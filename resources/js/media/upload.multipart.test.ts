import { readMultipartSession, saveMultipartSession, uploadMultipartFile } from '@/media/upload';
import { UploadHttpError } from '@/media/uploadRequest';

class FakeXhr {
  static requests: FakeXhr[] = [];
  status = 200;
  upload = { onprogress: null };
  onload: (() => void) | null = null;
  onerror: (() => void) | null = null;
  onabort: (() => void) | null = null;
  onloadend: (() => void) | null = null;
  url = '';
  headers: Record<string, string> = {};
  constructor() { FakeXhr.requests.push(this); }
  open(_method: string, url: string): void { this.url = url; }
  setRequestHeader(name: string, value: string): void { this.headers[name] = value; }
  getResponseHeader(): string { return '"etag"'; }
  send(): void { /* Tests control when requests finish. */ }
  abort(): void { this.onabort?.(); this.onloadend?.(); }
  finish(status: number): void { this.status = status; this.onload?.(); this.onloadend?.(); }
}

function options() {
  return {
    sessionKey: 'resume',
    session: { mediaId: 7, uploadId: 'upload', partSizeBytes: 4, completedParts: [], createdAt: '2026-10-22' },
    presignParts: jest.fn(async () => [{ part_number: 1, url: '/signed', headers: { 'Content-Type': 'video/mp4' } as Record<string, string> }]),
    complete: jest.fn(async () => {}),
    abort: jest.fn(async () => {}),
    onProgress: jest.fn(),
  };
}

const file = new File(['abcdefgh'], 'clip.mp4', { type: 'video/mp4' });

beforeEach(() => {
  jest.useFakeTimers();
  FakeXhr.requests = [];
  jest.spyOn(globalThis, 'XMLHttpRequest').mockImplementation(() => new FakeXhr() as unknown as XMLHttpRequest);
  window.localStorage.clear();
  Object.defineProperty(navigator, 'onLine', { value: true, configurable: true });
});

afterEach(() => {
  jest.useRealTimers();
  jest.restoreAllMocks();
  Object.defineProperty(navigator, 'onLine', { value: true, configurable: true });
});

it('re-signs failed parts, persists completed parts, and resumes without reuploading them', async () => {
  const opts = options();
  opts.presignParts.mockResolvedValueOnce([{ part_number: 1, url: '/first', headers: {} }])
    .mockResolvedValueOnce([{ part_number: 1, url: '/fresh', headers: {} }]);
  const result = uploadMultipartFile(file, opts);
  await jest.advanceTimersByTimeAsync(0);
  FakeXhr.requests[0]!.finish(503);
  await jest.advanceTimersByTimeAsync(1000);
  expect(FakeXhr.requests[1]!.url).toBe('/fresh');
  FakeXhr.requests[1]!.finish(200);
  await jest.advanceTimersByTimeAsync(0);
  expect(readMultipartSession('resume')?.completedParts).toEqual([{ part_number: 1, etag: '"etag"' }]);
  FakeXhr.requests[2]!.finish(200);
  await result;
  expect(opts.complete).toHaveBeenCalledWith([
    { part_number: 1, etag: '"etag"' }, { part_number: 2, etag: '"etag"' },
  ]);
  expect(readMultipartSession('resume')).toBeNull();

  const resumed = options();
  const session = { ...resumed.session, completedParts: [{ part_number: 1, etag: '"earlier"' }] };
  const again = uploadMultipartFile(file, { ...resumed, session });
  await jest.advanceTimersByTimeAsync(0);
  expect(resumed.presignParts).toHaveBeenCalledWith([{ partNumber: 2, sizeBytes: 4 }]);
  FakeXhr.requests[3]!.finish(200);
  await again;
  expect(resumed.complete).toHaveBeenCalledWith([
    { part_number: 1, etag: '"earlier"' }, { part_number: 2, etag: '"etag"' },
  ]);
});

it('stops immediately on a permanent storage error', async () => {
  const opts = options();
  const result = uploadMultipartFile(file, opts);
  const settled = expect(result).rejects.toMatchObject({ status: 403 });
  await jest.advanceTimersByTimeAsync(0);
  FakeXhr.requests[0]!.finish(403);
  await settled;
  expect(opts.presignParts).toHaveBeenCalledTimes(1);
  expect(opts.complete).not.toHaveBeenCalled();
});

it('aborts the server session and clears saved state when canceled offline', async () => {
  const opts = options();
  const controller = new AbortController();
  saveMultipartSession('resume', opts.session);
  Object.defineProperty(navigator, 'onLine', { value: false, configurable: true });
  const result = uploadMultipartFile(file, { ...opts, signal: controller.signal });
  const settled = expect(result).rejects.toMatchObject({ name: 'AbortError' });
  await jest.advanceTimersByTimeAsync(0);
  controller.abort();
  await settled;
  expect(opts.abort).toHaveBeenCalledTimes(1);
  expect(opts.presignParts).not.toHaveBeenCalled();
  expect(readMultipartSession('resume')).toBeNull();
});

it('keeps resumable state on transient failure and does not retry multipart completion', async () => {
  const opts = options();
  const session = { ...opts.session, completedParts: [
    { part_number: 1, etag: '"1"' }, { part_number: 2, etag: '"2"' },
  ] };
  saveMultipartSession('resume', session);
  opts.complete.mockRejectedValue(new UploadHttpError(503, 'Unavailable'));
  await expect(uploadMultipartFile(file, { ...opts, session })).rejects.toThrow('Unavailable');
  expect(opts.complete).toHaveBeenCalledTimes(1);
  expect(readMultipartSession('resume')).toEqual(session);
});
