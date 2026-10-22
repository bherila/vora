import { act, fireEvent, render, waitFor } from '@testing-library/react';
import Hls from 'hls.js';

import { HlsVideoPlayer } from '@/media/HlsVideoPlayer';

jest.mock('hls.js', () => ({
  __esModule: true,
  default: Object.assign(jest.fn().mockImplementation(() => ({
    on: jest.fn(), loadSource: jest.fn(), attachMedia: jest.fn(), destroy: jest.fn(),
  })), { isSupported: jest.fn(), Events: { ERROR: 'hlsError' } }),
}));

interface HlsDouble {
  on: jest.Mock;
  loadSource: jest.Mock;
  attachMedia: jest.Mock;
  destroy: jest.Mock;
}

function hlsInstance(index = 0): HlsDouble {
  return jest.mocked(Hls).mock.results[index]?.value as HlsDouble;
}

describe('HlsVideoPlayer', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    jest.mocked(Hls.isSupported).mockReturnValue(false);
    jest.spyOn(HTMLMediaElement.prototype, 'canPlayType').mockReturnValue('probably');
  });

  afterEach(() => {
    jest.restoreAllMocks();
  });

  it('loads hls.js on demand and prefers MSE when native HLS also claims support', async () => {
    jest.mocked(Hls.isSupported).mockReturnValue(true);
    const { container } = render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" />);
    const video = container.querySelector('video');

    expect(Hls).not.toHaveBeenCalled();
    await waitFor(() => expect(Hls).toHaveBeenCalledWith({ enableWorker: true }));
    expect(hlsInstance().loadSource).toHaveBeenCalledWith('http://localhost/api/media/7/hls/master.m3u8');
    expect(hlsInstance().attachMedia).toHaveBeenCalledWith(video);
    expect(video).not.toHaveAttribute('src');
  });

  it('uses native HLS when MSE is unavailable and removes its error listener on unmount', async () => {
    const onError = jest.fn();
    const { container, unmount } = render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" onError={onError} />);
    const video = container.querySelector('video')!;

    await waitFor(() => expect(video).toHaveAttribute('src', 'http://localhost/api/media/7/hls/master.m3u8'));
    expect(Hls).not.toHaveBeenCalled();
    fireEvent.error(video);
    expect(onError).toHaveBeenCalledTimes(1);
    unmount();
    fireEvent.error(video);
    expect(onError).toHaveBeenCalledTimes(1);
  });

  it('reports only fatal hls.js errors and destroys its instance exactly once', async () => {
    jest.mocked(Hls.isSupported).mockReturnValue(true);
    const onError = jest.fn();
    const { unmount } = render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" onError={onError} />);
    await waitFor(() => expect(Hls).toHaveBeenCalledTimes(1));
    const instance = hlsInstance();
    const [event, report] = instance.on.mock.calls[0] as [string, (event: string, data: { fatal: boolean }) => void];

    expect(event).toBe(Hls.Events.ERROR);
    report(event, { fatal: false });
    expect(onError).not.toHaveBeenCalled();
    report(event, { fatal: true });
    expect(onError).toHaveBeenCalledTimes(1);
    expect(instance.destroy).toHaveBeenCalledTimes(1);
    report(event, { fatal: true });
    expect(onError).toHaveBeenCalledTimes(1);
    unmount();
    expect(instance.destroy).toHaveBeenCalledTimes(1);
  });

  it('reports unavailable playback when neither MSE nor native HLS is supported', async () => {
    jest.mocked(HTMLMediaElement.prototype.canPlayType).mockReturnValue('');
    const onError = jest.fn();
    const { container } = render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" onError={onError} />);

    await waitFor(() => expect(onError).toHaveBeenCalledTimes(1));
    expect(container.querySelector('video')).not.toHaveAttribute('src');
    expect(Hls).not.toHaveBeenCalled();
  });

  it('does not initialize or report an error when unmounted before the import settles', async () => {
    jest.mocked(Hls.isSupported).mockReturnValue(true);
    const onError = jest.fn();
    const { unmount } = render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" onError={onError} />);
    unmount();
    await act(async () => { await Promise.resolve(); });

    expect(Hls).not.toHaveBeenCalled();
    expect(onError).not.toHaveBeenCalled();
  });

  it('destroys a partially initialized lazy player when loading its source throws', async () => {
    jest.mocked(Hls.isSupported).mockReturnValue(true);
    const broken: HlsDouble = {
      on: jest.fn(), attachMedia: jest.fn(), destroy: jest.fn(),
      loadSource: jest.fn(() => { throw new Error('Source initialization failed'); }),
    };
    // The test double implements only the Hls methods used by the component.
    jest.mocked(Hls).mockImplementationOnce(() => broken as unknown as Hls);
    const onError = jest.fn();
    const { unmount } = render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" onError={onError} />);
    await waitFor(() => expect(onError).toHaveBeenCalledTimes(1));

    expect(broken.attachMedia).not.toHaveBeenCalled();
    expect(broken.destroy).toHaveBeenCalledTimes(1);
    unmount();
    expect(broken.destroy).toHaveBeenCalledTimes(1);
  });

  it('ignores old instance errors after changing the source and cleans up the replacement', async () => {
    jest.mocked(Hls.isSupported).mockReturnValue(true);
    const onError = jest.fn();
    const { rerender, unmount } = render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" onError={onError} />);
    await waitFor(() => expect(Hls).toHaveBeenCalledTimes(1));
    const first = hlsInstance();
    const [event, report] = first.on.mock.calls[0] as [string, (event: string, data: { fatal: boolean }) => void];
    rerender(<HlsVideoPlayer src="/api/media/8/hls/master.m3u8" onError={onError} />);
    await waitFor(() => expect(Hls).toHaveBeenCalledTimes(2));

    expect(first.destroy).toHaveBeenCalledTimes(1);
    expect(hlsInstance(1).loadSource).toHaveBeenCalledWith('http://localhost/api/media/8/hls/master.m3u8');
    report(event, { fatal: true });
    expect(onError).not.toHaveBeenCalled();
    unmount();
    expect(hlsInstance(1).destroy).toHaveBeenCalledTimes(1);
  });

  it.each(['javascript:alert(1)', 'https://other.invalid/master.m3u8'])('rejects unsafe manifest %s before either playback path', async (source) => {
    jest.mocked(Hls.isSupported).mockReturnValue(true);
    const onError = jest.fn();
    const { container } = render(<HlsVideoPlayer src={source} onError={onError} />);

    expect(container.querySelector('video')).not.toHaveAttribute('src');
    expect(Hls).not.toHaveBeenCalled();
    await waitFor(() => expect(onError).toHaveBeenCalledTimes(1));
  });
});
