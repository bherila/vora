import { useEffect, useRef } from 'react';

import { VideoPlayback } from '@/media/videoPlayback';
import { safeSameOriginUrl } from '@/security/dom-url';

interface HlsVideoPlayerProps {
  /** App-relative URL of the HLS master playlist proxy (`…/hls/master.m3u8`). */
  src: string;
  className?: string | undefined;
  /**
   * Called when HLS playback fails unrecoverably (manifest/segment 404, stale
   * mapping, transient failure, or no HLS/MSE support). Lets the parent mark
   * the stream unavailable without falling back to the original video file.
   */
  onError?: (() => void) | undefined;
  /**
   * Receives a playback controller once a stream is attached, and null when it
   * is torn down (source change, failure, or unmount).
   */
  onPlayback?: ((playback: VideoPlayback | null) => void) | undefined;
}

/**
 * Loads hls.js on demand and prefers its Media Source Extensions playback,
 * using native HLS when MSE is unavailable. `src` points at the authenticated
 * playback proxy, which serves manifests and redirects segments to signed URLs.
 */
export function HlsVideoPlayer({ src, className, onError, onPlayback }: HlsVideoPlayerProps) {
  const videoRef = useRef<HTMLVideoElement>(null);
  // Hold the latest callbacks so a fresh identity each render doesn't tear down
  // and re-attach the player.
  const onErrorRef = useRef(onError);
  const onPlaybackRef = useRef(onPlayback);
  useEffect(() => {
    onErrorRef.current = onError;
    onPlaybackRef.current = onPlayback;
  });

  useEffect(() => {
    const video = videoRef.current;
    if (!video) {
      return;
    }

    const safeSource = safeSameOriginUrl(src);
    if (safeSource === null) {
      onErrorRef.current?.();
      return;
    }

    let stopped = false;
    let cleanup: (() => void) | undefined;
    let playback: VideoPlayback | null = null;
    const publish = (next: VideoPlayback): void => {
      playback = next;
      onPlaybackRef.current?.(next);
    };
    const stop = (): void => {
      stopped = true;
      cleanup?.();
      cleanup = undefined;
      if (playback !== null) {
        playback.dispose();
        playback = null;
        onPlaybackRef.current?.(null);
      }
    };
    const fail = (): void => {
      if (!stopped) {
        stop();
        onErrorRef.current?.();
      }
    };

    void import('hls.js').then(({ default: Hls }) => {
      if (stopped) {
        return;
      }
      // Chromium can report native HLS support yet fail on valid streams.
      // Keep the hls.js-recommended MSE preference when loading it on demand.
      if (Hls.isSupported()) {
        const hls = new Hls({ enableWorker: true });
        cleanup = () => hls.destroy();
        hls.on(Hls.Events.ERROR, (_event, data) => {
          if (data.fatal) {
            fail();
          }
        });
        hls.loadSource(safeSource);
        if (!stopped) {
          hls.attachMedia(video);
          publish(new VideoPlayback(video, hls));
        }
        return;
      }

      if (video.canPlayType('application/vnd.apple.mpegurl')) {
        video.addEventListener('error', fail);
        cleanup = () => video.removeEventListener('error', fail);
        video.src = safeSource;
        publish(new VideoPlayback(video));
        return;
      }
      fail();
    }).catch(fail);

    return stop;
  }, [src]);

  return <video ref={videoRef} controls playsInline className={className} />;
}
