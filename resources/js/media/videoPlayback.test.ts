import { FALLBACK_FRAME_RATE, formatFrameRate, frameStepTarget, snapFrameRate, VideoPlayback } from '@/media/videoPlayback';
import { createFakeVideo } from '@/media/videoPlaybackTestUtils';

describe('frame math', () => {
  it('lands a quarter frame into the target frame', () => {
    expect(frameStepTarget(10, 1, 25)).toBeCloseTo(10.05);
    expect(frameStepTarget(10, -1, 25)).toBeCloseTo(9.97);
    expect(frameStepTarget(0, -1, 25)).toBe(0);
  });

  it('snaps to common frame rates and keeps unusual ones', () => {
    expect(snapFrameRate(29.97)).toBe(30000 / 1001);
    expect(snapFrameRate(1 / 0.04)).toBe(25);
    expect(snapFrameRate(59.9)).toBe(60000 / 1001);
    expect(snapFrameRate(15)).toBe(15);
    expect(formatFrameRate(30000 / 1001)).toBe('29.97');
    expect(formatFrameRate(25)).toBe('25');
  });
});

describe('VideoPlayback', () => {
  it('prefers the frame rate of the highest stream level', () => {
    const { video } = createFakeVideo();
    const playback = new VideoPlayback(video, {
      levels: [{ height: 360, frameRate: 25 }, { height: 1080, frameRate: 30000 / 1001 }, { height: 720, frameRate: 0 }],
    });

    expect(playback.frameRate()).toBe(30000 / 1001);
  });

  it('measures the frame rate from presented frames during normal playback', () => {
    const fake = createFakeVideo();
    const playback = new VideoPlayback(fake.video, { levels: [{ height: 480, frameRate: 0 }] });
    expect(playback.frameRate()).toBeNull();

    fake.setPaused(false);
    for (let frame = 0; frame < 8; frame += 1) {
      fake.present(frame / 25, frame + 1);
    }

    expect(playback.frameRate()).toBe(25);
  });

  it('ignores frame gaps and other playback rates when measuring', () => {
    const fake = createFakeVideo();
    const playback = new VideoPlayback(fake.video);
    fake.setPaused(false);
    fake.video.playbackRate = 2;
    for (let frame = 0; frame < 8; frame += 1) {
      fake.present(frame / 25, frame + 1);
    }
    fake.video.playbackRate = 1;
    for (let frame = 0; frame < 8; frame += 1) {
      fake.present(frame / 25, frame * 2);
    }

    expect(playback.frameRate()).toBeNull();
  });

  it('steps frames from the presented frame and pauses first', () => {
    const fake = createFakeVideo();
    const playback = new VideoPlayback(fake.video, { levels: [{ height: 480, frameRate: 25 }] });
    fake.setPaused(false);
    fake.setTime(10.01);
    fake.present(10, 250);

    playback.stepFrames(1);

    expect(fake.video.pause).toHaveBeenCalled();
    expect(fake.seeks.at(-1)).toBeCloseTo(10.05);

    fake.present(10.04, 251);
    expect(playback.getState().time).toBeCloseTo(10.04);
    playback.stepFrames(-2);
    expect(fake.seeks.at(-1)).toBeCloseTo(9.97);
  });

  it('assumes a fallback frame rate before one is known', () => {
    const fake = createFakeVideo();
    const playback = new VideoPlayback(fake.video);
    fake.setTime(5);

    playback.stepFrames(1);

    expect(fake.seeks.at(-1)).toBeCloseTo(5 + 1.25 / FALLBACK_FRAME_RATE);
  });

  it('steps seconds and clamps seeks to the duration', () => {
    const fake = createFakeVideo({ duration: 20 });
    const playback = new VideoPlayback(fake.video);
    fake.setTime(19.5);

    playback.stepSeconds(1);
    expect(fake.seeks.at(-1)).toBe(20);
    playback.seek(-4);
    expect(fake.seeks.at(-1)).toBe(0);
    playback.seek(Number.NaN);
    expect(fake.seeks).toHaveLength(2);
  });

  it('defers a seek until metadata loads, and replaces an earlier pending seek', () => {
    const fake = createFakeVideo({ readyState: 0 });
    const playback = new VideoPlayback(fake.video);

    playback.seekWhenReady(30);
    playback.seekWhenReady(90);
    expect(fake.seeks).toEqual([]);

    fake.video.dispatchEvent(new Event('loadedmetadata'));
    expect(fake.seeks).toEqual([90]);
  });

  it('notifies subscribers of changes and stops after dispose', () => {
    const fake = createFakeVideo();
    const playback = new VideoPlayback(fake.video);
    const listener = jest.fn();
    playback.subscribe(listener);

    fake.setTime(3);
    expect(listener).toHaveBeenCalledTimes(1);
    expect(playback.getState()).toMatchObject({ time: 3, paused: true, duration: 600 });
    fake.setTime(3);
    expect(listener).toHaveBeenCalledTimes(1);

    playback.dispose();
    fake.setTime(4);
    playback.seek(8);
    expect(listener).toHaveBeenCalledTimes(1);
    expect(fake.video.cancelVideoFrameCallback).toHaveBeenCalledWith(1);
  });

  it('works without requestVideoFrameCallback', () => {
    const fake = createFakeVideo();
    Object.defineProperty(fake.video, 'requestVideoFrameCallback', { configurable: true, value: undefined });
    const playback = new VideoPlayback(fake.video);
    fake.setTime(7);

    playback.stepFrames(1);

    expect(fake.seeks.at(-1)).toBeCloseTo(7 + 1.25 / FALLBACK_FRAME_RATE);
    playback.dispose();
  });
});
