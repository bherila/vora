import { act, fireEvent, render, waitFor } from '@testing-library/react';

import { HlsVideoPlayer } from '@/media/HlsVideoPlayer';

let mockModuleLoads = 0;
const mockSupported = jest.fn(() => true);
const mockAttach = jest.fn();
const mockLoad = jest.fn();
const mockDestroy = jest.fn();
const mockOn = jest.fn();
const mockConstruct = jest.fn();

jest.mock('hls.js', () => {
  mockModuleLoads += 1;
  return {
    __esModule: true,
    default: Object.assign(function MockHls() {
      mockConstruct();
      return { attachMedia: mockAttach, loadSource: mockLoad, destroy: mockDestroy, on: mockOn };
    }, { isSupported: mockSupported, Events: { ERROR: 'error' } }),
  };
});

afterEach(() => {
  jest.restoreAllMocks();
  jest.clearAllMocks();
  mockSupported.mockReturnValue(true);
  mockAttach.mockReset();
});

it('uses native HLS without loading hls.js', () => {
  jest.spyOn(HTMLMediaElement.prototype, 'canPlayType').mockReturnValue('probably');
  const onError = jest.fn();
  const { container } = render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" onError={onError} />);
  const video = container.querySelector('video')!;
  expect(video).toHaveAttribute('src', 'http://localhost/api/media/7/hls/master.m3u8');
  expect(mockModuleLoads).toBe(0);
  fireEvent.error(video);
  expect(onError).toHaveBeenCalledTimes(1);
});

it('rejects script URLs before loading a player or assigning a source', () => {
  const onError = jest.fn();
  const { container } = render(<HlsVideoPlayer src="javascript:alert(1)" onError={onError} />);
  expect(container.querySelector('video')).not.toHaveAttribute('src');
  expect(onError).toHaveBeenCalledTimes(1);
  expect(mockModuleLoads).toBe(0);
});

it('loads hls.js for MSE, forwards fatal errors, and destroys on unmount', async () => {
  jest.spyOn(HTMLMediaElement.prototype, 'canPlayType').mockReturnValue('');
  const onError = jest.fn();
  const { unmount } = render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" onError={onError} />);
  await waitFor(() => expect(mockAttach).toHaveBeenCalledTimes(1));
  expect(mockLoad).toHaveBeenCalledWith('http://localhost/api/media/7/hls/master.m3u8');
  const [, handleError] = mockOn.mock.calls[0] as unknown as [string, (event: string, data: { fatal: boolean }) => void];
  act(() => handleError('error', { fatal: false }));
  expect(onError).not.toHaveBeenCalled();
  act(() => handleError('error', { fatal: true }));
  expect(onError).toHaveBeenCalledTimes(1);
  unmount();
  expect(mockDestroy).toHaveBeenCalledTimes(1);
});

it('reports unsupported playback after the lazy import', async () => {
  jest.spyOn(HTMLMediaElement.prototype, 'canPlayType').mockReturnValue('');
  mockSupported.mockReturnValue(false);
  const onError = jest.fn();
  render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" onError={onError} />);
  await waitFor(() => expect(onError).toHaveBeenCalledTimes(1));
  expect(mockConstruct).not.toHaveBeenCalled();
});

it('does not attach a player if unmounted while the import is pending', async () => {
  jest.spyOn(HTMLMediaElement.prototype, 'canPlayType').mockReturnValue('');
  const { unmount } = render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" />);
  unmount();
  await act(async () => { await Promise.resolve(); });
  expect(mockConstruct).not.toHaveBeenCalled();
});

it('cleans up and reports initialization failures', async () => {
  jest.spyOn(HTMLMediaElement.prototype, 'canPlayType').mockReturnValue('');
  mockAttach.mockImplementation(() => { throw new Error('MSE unavailable'); });
  const onError = jest.fn();
  const { unmount } = render(<HlsVideoPlayer src="/api/media/7/hls/master.m3u8" onError={onError} />);
  await waitFor(() => expect(onError).toHaveBeenCalledTimes(1));
  expect(mockDestroy).toHaveBeenCalledTimes(1);
  unmount();
  expect(mockDestroy).toHaveBeenCalledTimes(1);
});
