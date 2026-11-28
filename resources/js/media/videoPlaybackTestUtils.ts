/**
 * Test double for an HTMLVideoElement: jsdom does not implement media playback,
 * so position, duration, and frame presentation are driven by the test.
 */
export interface FakeVideo {
  video: HTMLVideoElement;
  /** Start or stop "playback" and dispatch the matching event. */
  setPaused: (paused: boolean) => void;
  /** Move the playhead without the element's seek behaviour. */
  setTime: (seconds: number) => void;
  /** Change how much of the media has loaded (HTMLMediaElement.HAVE_*). */
  setReadyState: (readyState: number) => void;
  /** Report a newly presented frame through requestVideoFrameCallback. */
  present: (mediaTime: number, presentedFrames: number) => void;
  seeks: number[];
}

export function createFakeVideo({ duration = 600, readyState = 4 }: { duration?: number; readyState?: number } = {}): FakeVideo {
  const video = document.createElement('video');
  let currentTime = 0;
  let paused = true;
  const seeks: number[] = [];
  let frameCallback: VideoFrameRequestCallback | null = null;

  Object.defineProperties(video, {
    currentTime: {
      configurable: true,
      get: () => currentTime,
      set: (value: number) => {
        currentTime = value;
        seeks.push(value);
      },
    },
    duration: { configurable: true, writable: true, value: duration },
    paused: { configurable: true, get: () => paused },
    readyState: { configurable: true, writable: true, value: readyState },
    seeking: { configurable: true, writable: true, value: false },
    playbackRate: { configurable: true, writable: true, value: 1 },
  });

  video.pause = jest.fn(() => {
    if (!paused) {
      paused = true;
      video.dispatchEvent(new Event('pause'));
    }
  });
  video.requestVideoFrameCallback = jest.fn((callback: VideoFrameRequestCallback) => {
    frameCallback = callback;
    return 1;
  });
  video.cancelVideoFrameCallback = jest.fn();

  return {
    video,
    seeks,
    setPaused: (next) => {
      paused = next;
      video.dispatchEvent(new Event(next ? 'pause' : 'play'));
    },
    setReadyState: (next) => {
      Object.defineProperty(video, 'readyState', { configurable: true, writable: true, value: next });
    },
    setTime: (seconds) => {
      currentTime = seconds;
      video.dispatchEvent(new Event('timeupdate'));
    },
    present: (mediaTime, presentedFrames) => {
      const callback = frameCallback;
      frameCallback = null;
      // Only the metadata fields the controller reads are meaningful here.
      callback?.(0, { mediaTime, presentedFrames } as VideoFrameCallbackMetadata);
    },
  };
}
