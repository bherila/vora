import { render, waitFor } from '@testing-library/react';

import { HlsVideoPlayer } from '@/media/HlsVideoPlayer';

jest.mock('hls.js', () => ({
  __esModule: true,
  default: {
    isSupported: () => false,
  },
}));

describe('HlsVideoPlayer source validation', () => {
  beforeEach(() => {
    jest.spyOn(HTMLMediaElement.prototype, 'canPlayType').mockReturnValue('probably');
  });

  afterEach(() => {
    jest.restoreAllMocks();
  });

  it('sets a same-origin manifest on native HLS playback', () => {
    const { container } = render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" />);

    expect(container.querySelector('video')).toHaveAttribute(
      'src',
      'http://localhost/api/media/7/hls/master.m3u8',
    );
  });

  it('rejects script URLs before assigning the video source', async () => {
    const onError = jest.fn();
    const { container } = render(<HlsVideoPlayer src="javascript:alert(1)" onError={onError} />);

    expect(container.querySelector('video')).not.toHaveAttribute('src');
    await waitFor(() => expect(onError).toHaveBeenCalledTimes(1));
  });
});
