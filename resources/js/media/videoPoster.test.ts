import { generateVideoPoster, VIDEO_POSTER_STEP_TIMEOUT_MS } from '@/media/imageProcessing';

describe('generateVideoPoster', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    URL.createObjectURL = jest.fn(() => 'blob:video');
    URL.revokeObjectURL = jest.fn();
    jest.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => undefined);
  });
  afterEach(() => { jest.useRealTimers(); jest.restoreAllMocks(); });

  it('gives up instead of hanging when the browser never loads the video', async () => {
    const poster = generateVideoPoster(new File(['x'], 'IMG_3987.mov', { type: 'video/quicktime' }));
    await jest.advanceTimersByTimeAsync(VIDEO_POSTER_STEP_TIMEOUT_MS);
    await expect(poster).resolves.toBeNull();
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:video');
  });

  it('gives up when seeking never completes', async () => {
    const created = jest.spyOn(document, 'createElement');
    const poster = generateVideoPoster(new File(['x'], 'clip.mp4', { type: 'video/mp4' }));
    const video = created.mock.results.find((result) => result.value instanceof HTMLVideoElement)?.value as HTMLVideoElement;
    video.dispatchEvent(new Event('loadedmetadata'));
    await jest.advanceTimersByTimeAsync(VIDEO_POSTER_STEP_TIMEOUT_MS);
    await expect(poster).resolves.toBeNull();
  });

  it('stops immediately when the upload is cancelled', async () => {
    const controller = new AbortController();
    const poster = generateVideoPoster(new File(['x'], 'clip.mov', { type: 'video/quicktime' }), controller.signal);
    controller.abort();
    await expect(poster).resolves.toBeNull();
    expect(jest.getTimerCount()).toBe(0);
  });
});
