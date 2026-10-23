import { decodeHeifInWorker } from './heifDecoderClient';
import { createHeifDecoderWorker } from './heifDecoderFactory';

jest.mock('@/media/heifDecoderFactory', () => ({ createHeifDecoderWorker: jest.fn() }));

interface TestWorker {
  onmessage: ((event: MessageEvent<{ bitmap: ImageBitmap }>) => void) | null;
  onerror: (() => void) | null;
  postMessage: jest.Mock;
  terminate: jest.Mock;
}

let worker: TestWorker;
beforeEach(() => {
  jest.useFakeTimers();
  worker = { onmessage: null, onerror: null, postMessage: jest.fn(), terminate: jest.fn() };
  jest.mocked(createHeifDecoderWorker).mockReturnValue(worker as unknown as Worker);
});
afterEach(() => { jest.useRealTimers(); jest.clearAllMocks(); });

test('releases a successful decoder worker and transfers its bitmap to the caller', async () => {
  const result = decodeHeifInWorker(new Blob(['source']), new AbortController().signal);
  const bitmap = { width: 64, height: 48, close: jest.fn() } as unknown as ImageBitmap;
  worker.onmessage?.(new MessageEvent('message', { data: { bitmap } }));
  await expect(result).resolves.toBe(bitmap);
  expect(worker.terminate).toHaveBeenCalledTimes(1);
  expect(jest.getTimerCount()).toBe(0);
});

test('terminates decoding immediately on cancellation without retaining a timeout', async () => {
  const controller = new AbortController();
  const result = decodeHeifInWorker(new Blob(['source']), controller.signal);
  controller.abort();
  await expect(result).rejects.toMatchObject({ name: 'AbortError' });
  expect(worker.terminate).toHaveBeenCalledTimes(1);
  expect(jest.getTimerCount()).toBe(0);
});

test('bounds a stalled conversion and releases its worker', async () => {
  const result = decodeHeifInWorker(new Blob(['source']), new AbortController().signal);
  jest.advanceTimersByTime(20000);
  await expect(result).rejects.toThrow('took too long');
  expect(worker.terminate).toHaveBeenCalledTimes(1);
  expect(jest.getTimerCount()).toBe(0);
});

test('cleans up when a browser cannot clone the file to the worker', async () => {
  worker.postMessage.mockImplementation(() => { throw new Error('DataCloneError'); });
  await expect(decodeHeifInWorker(new Blob(['source']), new AbortController().signal)).rejects.toThrow('Export it as JPEG');
  expect(worker.terminate).toHaveBeenCalledTimes(1);
  expect(jest.getTimerCount()).toBe(0);
});
