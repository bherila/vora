import { computeFileHash } from '@/media/imageProcessing';
import { putToSignedUrl } from '@/media/upload';
import { postUpload } from '@/media/uploadRequest';

import { prepareHeifPreview } from './heifPhoto';
import { uploadMediaFile } from './uploadMediaFile';

jest.mock('@/media/heifPhoto', () => ({
  ...jest.requireActual<typeof import('@/media/heifPhoto')>('@/media/heifPhoto'), prepareHeifPreview: jest.fn(),
}));
jest.mock('@/media/imageProcessing', () => ({ supportsClientDerivatives: () => false, computeFileHash: jest.fn().mockResolvedValue('original-hash') }));
jest.mock('@/media/uploadRequest', () => ({ ...jest.requireActual<typeof import('@/media/uploadRequest')>('@/media/uploadRequest'), postUpload: jest.fn() }));
jest.mock('@/media/upload', () => ({
  ...jest.requireActual<typeof import('@/media/upload')>('@/media/upload'), putToSignedUrl: jest.fn(),
  withRetries: (operation: () => Promise<unknown>) => operation(),
}));

const post = jest.mocked(postUpload);
const put = jest.mocked(putToSignedUrl);
const preview = jest.mocked(prepareHeifPreview);
const original = new File(['untouched-heic-original'], 'photo.HEIC', { type: 'image/heic' });

function upload(file = original): Promise<void> {
  return uploadMediaFile({ file, title: '', destination: { kind: 'event', ulid: 'event-one' }, signal: new AbortController().signal, onProgress: jest.fn() });
}

beforeEach(() => {
  jest.clearAllMocks(); window.localStorage.clear();
  preview.mockResolvedValue(new Blob(['jpeg-preview'], { type: 'image/jpeg' }));
  put.mockResolvedValue();
  post.mockImplementation(async (url) => url.endsWith('/uploads') ? {
    data: { id: 41 }, upload_url: 'https://storage.test/original', upload_headers: { 'Content-Type': 'image/heic' },
    thumbnail_upload_url: 'https://storage.test/preview', thumbnail_upload_headers: { 'Content-Type': 'image/jpeg' },
    multipart: { enabled: false, threshold_bytes: 100, part_size_bytes: 100 },
  } : { success: true });
});

test('uploads the unchanged original and a separate required JPEG, charging/resuming by original bytes', async () => {
  await upload();
  expect(post).toHaveBeenCalledWith('/api/events/event-one/uploads', expect.objectContaining({ filename: 'photo.HEIC', content_type: 'image/heic', size: original.size, has_thumbnail: true, thumbnail_size: 12, file_hash: 'original-hash' }), expect.any(AbortSignal));
  expect(put.mock.calls[0]?.[1]).toBe(original);
  expect(put.mock.calls[1]?.[1]).toBeInstanceOf(Blob);
  expect(jest.mocked(computeFileHash)).toHaveBeenCalledWith(original, expect.any(Function), expect.any(AbortSignal));
  expect(post).toHaveBeenCalledWith('/api/media/41/complete', {}, expect.any(AbortSignal));
});

test('normalizes missing camera MIME metadata without altering filename or original size', async () => {
  const file = new File(['same-original'], 'camera.HEIF', { lastModified: 123 });
  await upload(file);
  expect(post).toHaveBeenCalledWith('/api/events/event-one/uploads', expect.objectContaining({ filename: 'camera.HEIF', content_type: 'image/heif', size: file.size }), expect.any(AbortSignal));
  const body = put.mock.calls[0]?.[1] as File;
  expect([body.name, body.size, body.lastModified]).toEqual([file.name, file.size, 123]);
});

test('a conversion failure creates no upload or quota reservation', async () => {
  preview.mockRejectedValue(new Error('Could not decode this HEIC/HEIF photo.'));
  await expect(upload()).rejects.toThrow('Could not decode');
  expect(post).not.toHaveBeenCalled(); expect(put).not.toHaveBeenCalled();
});

test('a missing JPEG upload fails this file and removes its unfinished original reservation', async () => {
  put.mockImplementation(async (url) => { if (url.endsWith('/preview')) throw new Error('Offline'); });
  await expect(upload()).rejects.toThrow('JPEG preview could not be uploaded');
  expect(post).not.toHaveBeenCalledWith('/api/media/41/complete', {}, expect.any(AbortSignal));
  expect(post).toHaveBeenCalledWith('/api/media/41/cancel-upload', {});
});
