import { parse } from 'exifr';

import { exifCaptureTime, readPhotoMetadata } from './photoMetadata';

jest.mock('exifr', () => ({ parse: jest.fn() }));
const parser = jest.mocked(parse);

beforeEach(() => jest.clearAllMocks());

it('returns bounded summary flags and capture time but excludes actual coordinates and camera names', async () => {
  parser.mockResolvedValue({ latitude: 37.775, longitude: -122.4194, DateTimeOriginal: new Date(2026, 9, 22, 10, 0, 0), OffsetTimeOriginal: '-07:00', Make: 'Private camera name' });
  const summary = await readPhotoMetadata(new File(['photo'], 'camera.jpg'), new AbortController().signal);
  expect(summary).toEqual({ has_gps: true, has_capture_time: true, has_camera: true, captured_at: '2026-10-22T10:00:00-07:00' });
  expect(JSON.stringify(summary)).not.toMatch(/37\.775|122\.4194|Private camera/);
  expect(parser).toHaveBeenCalledWith(expect.any(File), expect.objectContaining({ chunked: true, chunkSize: 65536, firstChunkSize: 65536, chunkLimit: 5, multiSegment: false, makerNote: false, userComment: false }));
});

it('rejects whole-file and oversized reads even if a metadata segment requests them', async () => {
  const file = new File([new Uint8Array(1024 * 1024)], 'large.heic');
  parser.mockImplementationOnce(async (input: unknown) => { (input as File).slice(0, 65537); return {}; });
  expect(await readPhotoMetadata(file, new AbortController().signal)).toBeNull();
  parser.mockImplementationOnce(async (input: unknown) => { await (input as File).arrayBuffer(); return {}; });
  expect(await readPhotoMetadata(file, new AbortController().signal)).toBeNull();
});

it('caps total metadata reads to512KiB and treats even swallowed parser-limit errors as unknown', async () => {
  const file = new File([new Uint8Array(1024 * 1024)], 'large.png');
  parser.mockImplementationOnce(async (input: unknown) => {
    for (let i = 0; i < 8; i++) expect((input as File).slice(i * 65536, (i + 1) * 65536).size).toBe(65536);
    try { (input as File).slice(0, 1); } catch { /* Exifr may tolerate malformed segments. */ }
    return { Make: 'Partial data' };
  });
  expect(await readPhotoMetadata(file, new AbortController().signal)).toBeNull();
});

it('preserves cancellation while malformed or missing metadata stays best-effort', async () => {
  const controller = new AbortController(); controller.abort();
  await expect(readPhotoMetadata(new File(['photo'], 'x.jpg'), controller.signal)).rejects.toMatchObject({ name: 'AbortError' });
  expect(parser).not.toHaveBeenCalled();
  parser.mockRejectedValueOnce(new Error('Malformed EXIF'));
  expect(await readPhotoMetadata(new File(['x'], 'x.jpg'), new AbortController().signal)).toBeNull();
  parser.mockResolvedValueOnce(undefined);
  expect(await readPhotoMetadata(new File(['x'], 'x.png'), new AbortController().signal)).toBeNull();
});

it('keeps the camera wall-clock digits and only a well-formed offset', () => {
  expect(exifCaptureTime(new Date(2026, 9, 24, 17, 32, 5), undefined)).toBe('2026-10-24T17:32:05');
  expect(exifCaptureTime('2026:10:24 17:32:05', ' +01:00 ')).toBe('2026-10-24T17:32:05+01:00');
  expect(exifCaptureTime('2026:10:24 17:32:05', 'local')).toBe('2026-10-24T17:32:05');
  expect(exifCaptureTime('0000:00:00 00:00:00', undefined)).toBe('0000-00-00T00:00:00');
  expect(exifCaptureTime('not a date', '+01:00')).toBeNull();
  expect(exifCaptureTime(new Date(Number.NaN), undefined)).toBeNull();
});

it('pairs CreateDate with its digitized offset when there is no original time', async () => {
  parser.mockResolvedValueOnce({ CreateDate: '2026:10:24 09:00:00', OffsetTimeOriginal: '+09:00', OffsetTimeDigitized: '-05:00' });
  expect((await readPhotoMetadata(new File(['x'], 'x.jpg'), new AbortController().signal))?.captured_at).toBe('2026-10-24T09:00:00-05:00');
});
