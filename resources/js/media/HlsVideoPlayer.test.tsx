import { fireEvent, render, waitFor } from '@testing-library/react';
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

function hlsInstance(): HlsDouble {
  return jest.mocked(Hls).mock.results[0]?.value as HlsDouble;
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

  it('uses hls.js when MSE and native HLS both claim support', () => {
    jest.mocked(Hls.isSupported).mockReturnValue(true);
    const { container } = render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" />);
    const video = container.querySelector('video');

    expect(Hls).toHaveBeenCalledWith({ enableWorker: true });
    expect(hlsInstance().loadSource).toHaveBeenCalledWith('http://localhost/api/media/7/hls/master.m3u8');
    expect(hlsInstance().attachMedia).toHaveBeenCalledWith(video);
    expect(video).not.toHaveAttribute('src');
  });

  it('uses native HLS when MSE is unavailable and removes its error listener on unmount', () => {
    const onError = jest.fn();
    const { container, unmount } = render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" onError={onError} />);
    const video = container.querySelector('video')!;

    expect(video).toHaveAttribute('src', 'http://localhost/api/media/7/hls/master.m3u8');
    expect(Hls).not.toHaveBeenCalled();
    fireEvent.error(video);
    expect(onError).toHaveBeenCalledTimes(1);
    unmount();
    fireEvent.error(video);
    expect(onError).toHaveBeenCalledTimes(1);
  });

  it('reports only fatal hls.js errors and destroys its instance on unmount', () => {
    jest.mocked(Hls.isSupported).mockReturnValue(true);
    const onError = jest.fn();
    const { unmount } = render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" onError={onError} />);
    const instance = hlsInstance();
    const [event, report] = instance.on.mock.calls[0] as [string, (event: string, data: { fatal: boolean }) => void];

    expect(event).toBe(Hls.Events.ERROR);
    report(event, { fatal: false });
    expect(onError).not.toHaveBeenCalled();
    report(event, { fatal: true });
    expect(onError).toHaveBeenCalledTimes(1);
    unmount();
    expect(instance.destroy).toHaveBeenCalledTimes(1);
  });

  it('reports unavailable playback when neither MSE nor native HLS is supported', () => {
    jest.mocked(HTMLMediaElement.prototype.canPlayType).mockReturnValue('');
    const onError = jest.fn();
    const { container } = render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" onError={onError} />);

    expect(container.querySelector('video')).not.toHaveAttribute('src');
    expect(Hls).not.toHaveBeenCalled();
    expect(onError).toHaveBeenCalledTimes(1);
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
