import { withRetries } from '@/media/upload';
import { postUpload, UploadHttpError } from '@/media/uploadRequest';

describe('upload retries', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    Object.defineProperty(navigator, 'onLine', { value: true, configurable: true });
  });

  afterEach(() => {
    jest.useRealTimers();
    jest.restoreAllMocks();
    Object.defineProperty(navigator, 'onLine', { value: true, configurable: true });
  });

  it('backs off and waits for connectivity without spending attempts offline', async () => {
    const operation = jest.fn<Promise<string>, []>()
      .mockRejectedValueOnce(new TypeError('network error'))
      .mockResolvedValue('ok');
    const result = withRetries(operation);
    await jest.advanceTimersByTimeAsync(0);
    Object.defineProperty(navigator, 'onLine', { value: false, configurable: true });
    await jest.advanceTimersByTimeAsync(5 * 60_000);
    expect(operation).toHaveBeenCalledTimes(1);
    Object.defineProperty(navigator, 'onLine', { value: true, configurable: true });
    window.dispatchEvent(new Event('online'));
    await expect(result).resolves.toBe('ok');
    expect(operation).toHaveBeenCalledTimes(2);
  });

  it('does not start a request while already offline and cancels the wait', async () => {
    Object.defineProperty(navigator, 'onLine', { value: false, configurable: true });
    const controller = new AbortController();
    const operation = jest.fn<Promise<void>, []>();
    const removeListener = jest.spyOn(window, 'removeEventListener');
    const result = withRetries(operation, controller.signal);
    const settled = expect(result).rejects.toMatchObject({ name: 'AbortError' });
    await jest.advanceTimersByTimeAsync(60_000);
    expect(operation).not.toHaveBeenCalled();
    controller.abort();
    await settled;
    expect(removeListener).toHaveBeenCalledWith('online', expect.any(Function));
  });

  it('stops after six transient failures', async () => {
    const operation = jest.fn<Promise<void>, []>().mockRejectedValue(new UploadHttpError(503, 'Unavailable'));
    const settled = expect(withRetries(operation)).rejects.toThrow('Unavailable');
    await jest.advanceTimersByTimeAsync(60_000);
    await settled;
    expect(operation).toHaveBeenCalledTimes(6);
    expect(jest.getTimerCount()).toBe(0);
  });

  it.each([401, 403, 404, 409, 419, 422])('does not retry HTTP %i', async (status) => {
    const operation = jest.fn<Promise<void>, []>().mockRejectedValue(new UploadHttpError(status, 'Denied'));
    await expect(withRetries(operation)).rejects.toThrow('Denied');
    expect(operation).toHaveBeenCalledTimes(1);
    expect(jest.getTimerCount()).toBe(0);
  });

  it.each([0, 408, 429, 500, 503])('retries HTTP %i', async (status) => {
    const operation = jest.fn<Promise<string>, []>()
      .mockRejectedValueOnce(new UploadHttpError(status, 'Temporary'))
      .mockResolvedValue('ok');
    const result = withRetries(operation);
    await jest.advanceTimersByTimeAsync(1000);
    await expect(result).resolves.toBe('ok');
    expect(operation).toHaveBeenCalledTimes(2);
  });

  it('cancels backoff without another request', async () => {
    const controller = new AbortController();
    const operation = jest.fn<Promise<void>, []>().mockRejectedValue(new TypeError('network error'));
    const result = withRetries(operation, controller.signal);
    const settled = expect(result).rejects.toMatchObject({ name: 'AbortError' });
    await jest.advanceTimersByTimeAsync(0);
    controller.abort();
    await settled;
    expect(operation).toHaveBeenCalledTimes(1);
    expect(jest.getTimerCount()).toBe(0);
  });
});

describe('upload API errors', () => {
  it('retains JSON error status and message, forwarding CSRF and cancellation', async () => {
    document.head.innerHTML = '<meta name="csrf-token" content="token">';
    const controller = new AbortController();
    globalThis.fetch = jest.fn().mockResolvedValue({
      ok: false, status: 422, text: async () => '{"message":"Session expired"}',
    });
    await expect(postUpload('/api/media/7/multipart/parts', {}, controller.signal))
      .rejects.toMatchObject({ status: 422, message: 'Session expired' });
    expect(fetch).toHaveBeenCalledWith('/api/media/7/multipart/parts', expect.objectContaining({
      signal: controller.signal,
      credentials: 'same-origin',
      headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'token', Accept: 'application/json' }),
    }));
    document.head.innerHTML = '';
  });

  it('retains the status of a non-JSON gateway error', async () => {
    globalThis.fetch = jest.fn().mockResolvedValue({
      ok: false, status: 502, statusText: 'Bad Gateway', text: async () => '<html>Error</html>',
    });
    await expect(postUpload('/api/media/7/complete', {})).rejects.toMatchObject({ status: 502, message: 'Bad Gateway' });
  });
});
