import { useSyncExternalStore } from 'react';

/**
 * The subset of an hls.js instance the controller reads: its quality levels,
 * which carry the `FRAME-RATE` attribute of the multivariant playlist.
 */
export interface PlaybackLevelSource {
  readonly levels?: ReadonlyArray<{ readonly frameRate?: number; readonly height?: number }> | undefined;
  /** hls.js: the playing level index; -1 lets it choose automatically. */
  currentLevel?: number;
}

export interface PlaybackState {
  /** Position shown to the viewer, in seconds: the presented frame when paused. */
  time: number;
  /** Duration in seconds, or NaN until metadata loads. */
  duration: number;
  paused: boolean;
  /** Frames per second, when known from the stream or measured during playback. */
  frameRate: number | null;
}

/** Frame rate assumed for stepping before the real one is known. */
export const FALLBACK_FRAME_RATE = 30;

// NTSC rates are exact rationals (30000/1001, …): a rounded 29.97 drifts a
// whole frame from real timestamps within seconds.
const COMMON_FRAME_RATES = [24000 / 1001, 24, 25, 30000 / 1001, 30, 48, 50, 60000 / 1001, 60];
const MEDIA_EVENTS = ['timeupdate', 'seeked', 'seeking', 'play', 'pause', 'loadedmetadata', 'durationchange', 'ratechange', 'emptied'] as const;
const MEASURED_SAMPLES = 15;
const MIN_MEASURED_SAMPLES = 5;

/** Snap a measured or advertised rate to the nearest common rate within 0.5%. */
export function snapFrameRate(fps: number): number {
  let best: number | null = null;
  for (const common of COMMON_FRAME_RATES) {
    const error = Math.abs(fps - common) / common;
    if (error < 0.005 && (best === null || error < Math.abs(fps - best) / best)) {
      best = common;
    }
  }
  return best ?? Math.round(fps * 1000) / 1000;
}

/** A frame rate for display: `29.97`, `25`. */
export function formatFrameRate(fps: number): string {
  return String(Math.round(fps * 100) / 100);
}

/**
 * Where to seek to show the frame `frames` away from the one presented at
 * `presentedTime`. Browsers display the frame whose timestamp is at or before
 * the current time, so landing a quarter frame past the target frame's start
 * selects it even when the stream's first frame is not at exactly 0.
 */
export function frameStepTarget(presentedTime: number, frames: number, fps: number): number {
  return Math.max(0, presentedTime + (frames + 0.25) / fps);
}

/**
 * Observes one `<video>` element and offers seeking and frame stepping. Owned
 * by `HlsVideoPlayer`, which creates it once playback is attached and disposes
 * it when the source changes or the player unmounts.
 */
export class VideoPlayback {
  private readonly listeners = new Set<() => void>();
  private state: PlaybackState;
  private presented: { mediaTime: number; frames: number } | null = null;
  private readonly frameDeltas: number[] = [];
  private measuredFrameRate: number | null = null;
  private frameCallback: number | null = null;
  private pendingSeek: (() => void) | null = null;
  private disposed = false;

  /**
   * @param frameRateHint The source's exact frame rate when the server knows it
   *   (metadata.json's `source.frameRate`); preferred over the stream's levels.
   */
  constructor(
    readonly video: HTMLVideoElement,
    private readonly levelSource: PlaybackLevelSource | null = null,
    private readonly frameRateHint: number | null = null,
  ) {
    const { fromPresentedFrame: _fromPresentedFrame, ...initial } = this.read();
    this.state = initial;
    for (const event of MEDIA_EVENTS) {
      video.addEventListener(event, this.refresh);
    }
    this.watchFrames();
  }

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    return () => {
      this.listeners.delete(listener);
    };
  };

  getState = (): PlaybackState => this.state;

  /** Frames per second: server hint, else the stream's playlist, else measured, else null. */
  frameRate(): number | null {
    if (this.frameRateHint !== null && this.frameRateHint > 0) {
      return this.frameRateHint;
    }
    const levels = this.levelSource?.levels ?? [];
    let best: { frameRate: number; height: number } | null = null;
    for (const level of levels) {
      const frameRate = level.frameRate ?? 0;
      const height = level.height ?? 0;
      if (frameRate > 0 && (best === null || height > best.height)) {
        best = { frameRate, height };
      }
    }
    if (best !== null) {
      return snapFrameRate(best.frameRate);
    }
    return this.measuredFrameRate;
  }

  /**
   * Lock playback to the highest-resolution level while a frame is being
   * captured, so the frame tuned in the browser is the one the server will
   * extract (from that same rendition). Native HLS has no levels to pin.
   */
  pinTopQuality(): void {
    const source = this.levelSource;
    const levels = source?.levels ?? [];
    if (source === null || levels.length === 0 || !('currentLevel' in source)) {
      return;
    }
    let top = 0;
    levels.forEach((level, index) => {
      if ((level.height ?? 0) > (levels[top]?.height ?? 0)) {
        top = index;
      }
    });
    source.currentLevel = top;
  }

  /** Return quality selection to hls.js. */
  releaseQuality(): void {
    const source = this.levelSource;
    if (source !== null && 'currentLevel' in source) {
      source.currentLevel = -1;
    }
  }

  /** Seek to `seconds`, clamped to the video's duration. */
  seek(seconds: number): void {
    if (this.disposed || !Number.isFinite(seconds)) {
      return;
    }
    const duration = this.video.duration;
    const target = Math.max(0, Number.isFinite(duration) ? Math.min(seconds, duration) : seconds);
    this.video.currentTime = target;
    this.refresh();
  }

  /**
   * Seek once metadata is available. Setting the position earlier is ignored
   * by Media Source playback, so deep links wait for `loadedmetadata`.
   */
  seekWhenReady(seconds: number): void {
    if (this.disposed) {
      return;
    }
    this.cancelPendingSeek();
    if (this.video.readyState >= HTMLMediaElement.HAVE_METADATA) {
      this.seek(seconds);
      return;
    }
    const onReady = (): void => {
      this.pendingSeek = null;
      this.seek(seconds);
    };
    this.video.addEventListener('loadedmetadata', onReady, { once: true });
    this.pendingSeek = () => this.video.removeEventListener('loadedmetadata', onReady);
  }

  /** Pause and move by whole frames (negative steps backward). */
  stepFrames(frames: number): void {
    if (this.disposed) {
      return;
    }
    this.video.pause();
    const fps = this.frameRate() ?? FALLBACK_FRAME_RATE;
    // Read the position now: during playback the published state only updates
    // on timeupdate, a few times a second.
    const shown = this.read();
    // Without a presented-frame timestamp, currentTime still carries the
    // previous step's quarter-frame offset; snap it to the frame's start so
    // repeated steps move exactly one frame each.
    const base = shown.fromPresentedFrame ? shown.time : Math.floor(shown.time * fps + 1e-6) / fps;
    this.seek(frameStepTarget(base, frames, fps));
  }

  /** Move by `seconds` from the shown position, keeping play/pause state. */
  stepSeconds(seconds: number): void {
    this.seek(this.read().time + seconds);
  }

  dispose(): void {
    if (this.disposed) {
      return;
    }
    this.disposed = true;
    this.cancelPendingSeek();
    for (const event of MEDIA_EVENTS) {
      this.video.removeEventListener(event, this.refresh);
    }
    if (this.frameCallback !== null && typeof this.video.cancelVideoFrameCallback === 'function') {
      this.video.cancelVideoFrameCallback(this.frameCallback);
    }
    this.frameCallback = null;
    this.listeners.clear();
  }

  private cancelPendingSeek(): void {
    this.pendingSeek?.();
    this.pendingSeek = null;
  }

  private readonly refresh = (): void => {
    if (this.disposed) {
      return;
    }
    if (this.video.seeking || this.video.currentTime === 0) {
      // A seek or a new source makes the last presented frame stale.
      this.presented = null;
    }
    const { fromPresentedFrame: _fromPresentedFrame, ...next } = this.read();
    const current = this.state;
    if (
      next.time === current.time
      && next.paused === current.paused
      && next.frameRate === current.frameRate
      && Object.is(next.duration, current.duration)
    ) {
      return;
    }
    this.state = next;
    for (const listener of this.listeners) {
      listener();
    }
  };

  private read(): PlaybackState & { fromPresentedFrame: boolean } {
    const { video } = this;
    const frameRate = this.frameRate();
    let time = video.currentTime;
    let fromPresentedFrame = false;
    // While paused, report the frame actually on screen; currentTime can sit
    // part-way through it.
    if (video.paused && this.presented !== null) {
      const tolerance = 1 / (frameRate ?? FALLBACK_FRAME_RATE);
      if (Math.abs(this.presented.mediaTime - video.currentTime) < tolerance) {
        time = this.presented.mediaTime;
        fromPresentedFrame = true;
      }
    }
    return { time, duration: video.duration, paused: video.paused, frameRate, fromPresentedFrame };
  }

  private watchFrames(): void {
    const { video } = this;
    if (typeof video.requestVideoFrameCallback !== 'function') {
      return;
    }
    const onFrame = (_now: number, metadata: VideoFrameCallbackMetadata): void => {
      if (this.disposed) {
        return;
      }
      this.recordFrame(metadata);
      this.frameCallback = video.requestVideoFrameCallback(onFrame);
    };
    this.frameCallback = video.requestVideoFrameCallback(onFrame);
  }

  private recordFrame(metadata: VideoFrameCallbackMetadata): void {
    const previous = this.presented;
    this.presented = { mediaTime: metadata.mediaTime, frames: metadata.presentedFrames };

    // Consecutive frames during normal-speed playback give the frame duration.
    if (
      previous !== null
      && !this.video.paused
      && this.video.playbackRate === 1
      && metadata.presentedFrames === previous.frames + 1
    ) {
      const delta = metadata.mediaTime - previous.mediaTime;
      if (delta > 0.004 && delta < 0.2) {
        this.frameDeltas.push(delta);
        if (this.frameDeltas.length > MEASURED_SAMPLES) {
          this.frameDeltas.shift();
        }
        if (this.frameDeltas.length >= MIN_MEASURED_SAMPLES) {
          const sorted = [...this.frameDeltas].sort((a, b) => a - b);
          const median = sorted[Math.floor(sorted.length / 2)] ?? delta;
          this.measuredFrameRate = snapFrameRate(1 / median);
        }
      }
    }

    // Playback updates arrive through timeupdate; while paused, a newly
    // presented frame (after a seek or step) updates the readout.
    if (this.video.paused) {
      this.refresh();
    }
  }
}

/** Subscribe a component to a playback controller's state. */
export function usePlaybackState(playback: VideoPlayback): PlaybackState {
  return useSyncExternalStore(playback.subscribe, playback.getState, playback.getState);
}
