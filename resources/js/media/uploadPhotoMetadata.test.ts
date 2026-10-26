import { generatePhotoDerivatives } from '@/media/imageProcessing';
import { readPhotoMetadata } from '@/media/photoMetadata';
import { putToSignedUrl } from '@/media/upload';
import { postUpload } from '@/media/uploadRequest';

import { uploadMediaFile } from './uploadMediaFile';

jest.mock('@/media/imageProcessing', () => ({ supportsClientDerivatives: () => true, generatePhotoDerivatives: jest.fn(), computeFileHash: jest.fn().mockResolvedValue('original-hash') }));
jest.mock('@/media/photoMetadata', () => ({ readPhotoMetadata: jest.fn() }));
jest.mock('@/media/videoMetadata', () => ({ readVideoCaptureTime: jest.fn().mockResolvedValue('2026-10-24T23:00:00Z') }));
jest.mock('@/media/uploadRequest', () => ({ ...jest.requireActual<typeof import('@/media/uploadRequest')>('@/media/uploadRequest'), postUpload: jest.fn() }));
jest.mock('@/media/upload', () => ({ ...jest.requireActual<typeof import('@/media/upload')>('@/media/upload'), putToSignedUrl: jest.fn(), withRetries: (operation: () => Promise<unknown>) => operation() }));

const original = new File(['original-with-exif'], 'camera.jpg', { type: 'image/jpeg' });
const post = jest.mocked(postUpload);

beforeEach(() => {
  jest.clearAllMocks(); window.localStorage.clear();
  jest.mocked(generatePhotoDerivatives).mockResolvedValue({ thumbnail: new Blob(['jpeg-preview'], { type: 'image/jpeg' }), perceptualHash: 'visual-hash', width: 6000, height: 4000 });
  // The upload boundary projects permitted summary fields even if a future
  // parser implementation accidentally returns an extra sensitive value.
  const metadata = { has_gps: true, has_capture_time: true, has_camera: true, captured_at: '2026-10-24T17:32:05-07:00', latitude: 37.775, Make: 'Private camera' };
  jest.mocked(readPhotoMetadata).mockResolvedValue(metadata);
  jest.mocked(putToSignedUrl).mockResolvedValue();
  post.mockImplementation(async (url) => url.endsWith('/uploads') ? {
    data: { id: 41 }, upload_url: 'https://storage.test/original', upload_headers: { 'Content-Type': 'image/jpeg' },
    thumbnail_upload_url: 'https://storage.test/preview', thumbnail_upload_headers: { 'Content-Type': 'image/jpeg' },
    multipart: { enabled: false, threshold_bytes: 100, part_size_bytes: 100 },
  } : { success: true });
});

it('sends original resolution, summary flags and capture time only for Event photos, preserving the original file', async () => {
  const signal = new AbortController().signal;
  await uploadMediaFile({ file: original, title: '', destination: { kind: 'event', ulid: 'event-one' }, signal, onProgress: jest.fn() });
  expect(readPhotoMetadata).toHaveBeenCalledWith(original, signal);
  const payload = post.mock.calls.find(([url]) => url.endsWith('/uploads'))?.[1];
  expect(payload).toEqual(expect.objectContaining({ photo_metadata: { width: 6000, height: 4000, has_gps: true, has_capture_time: true, has_camera: true } }));
  expect(payload).toEqual(expect.objectContaining({ captured_at: '2026-10-24T17:32:05-07:00' }));
  expect(JSON.stringify(payload)).not.toMatch(/37\.775|Private camera/);
  expect(jest.mocked(putToSignedUrl).mock.calls[0]?.[1]).toBe(original);
});

it('does not extract or send Event grouping metadata for private Library media', async () => {
  await uploadMediaFile({ file: original, title: '', destination: { kind: 'library', collectionUlid: null }, signal: new AbortController().signal, onProgress: jest.fn() });
  expect(readPhotoMetadata).not.toHaveBeenCalled();
  const payload = post.mock.calls.find(([url]) => url.endsWith('/uploads'))?.[1];
  expect(payload).not.toHaveProperty('photo_metadata');
  expect(payload).not.toHaveProperty('captured_at');
});
