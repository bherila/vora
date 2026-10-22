import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { toast } from 'sonner';

import { computeFileHash } from '@/media/imageProcessing';
import {
  type MultipartUploadSession,
  putToSignedUrl,
  readMultipartSession,
  removeMultipartSession,
  saveMultipartSession,
  uploadMultipartFile,
} from '@/media/upload';
import { postUpload, UploadHttpError } from '@/media/uploadRequest';

import { MediaUploadDialog } from './MediaUploadDialog';

interface MockFileDropzoneProps {
  onFilesChange: (files: File[]) => void;
  disabled: boolean;
}

jest.mock('sonner', () => ({ toast: { success: jest.fn(), error: jest.fn(), info: jest.fn() } }));
jest.mock('@/identity', () => ({ useActiveIdentityId: () => null }));
jest.mock('@/restrictions', () => ({ activeRestriction: () => null }));
jest.mock('@/community/AudienceField', () => ({ AudienceField: () => null }));
jest.mock('@/components/interest-picker', () => ({ InterestPicker: () => null }));
jest.mock('@/media/imageProcessing', () => ({
  supportsClientDerivatives: () => false,
  computeFileHash: jest.fn(),
}));
jest.mock('@/media/uploadRequest', () => ({
  ...jest.requireActual<typeof import('@/media/uploadRequest')>('@/media/uploadRequest'),
  postUpload: jest.fn(),
}));
jest.mock('@/media/upload', () => ({
  ...jest.requireActual<typeof import('@/media/upload')>('@/media/upload'),
  putToSignedUrl: jest.fn(),
  readMultipartSession: jest.fn(),
  removeMultipartSession: jest.fn(),
  saveMultipartSession: jest.fn(),
  uploadMultipartFile: jest.fn(),
}));
jest.mock('@/components/media/FileDropzone', () => ({
  FileDropzone: function MockFileDropzone({ onFilesChange, disabled }: MockFileDropzoneProps) {
    return <input aria-label="Choose uploads" type="file" multiple disabled={disabled}
      onChange={(event) => onFilesChange(Array.from(event.currentTarget.files ?? []))} />;
  },
}));

const post = jest.mocked(postUpload);
const multipart = jest.mocked(uploadMultipartFile);
const hash = jest.mocked(computeFileHash);
const readSession = jest.mocked(readMultipartSession);
const storage = jest.requireActual<typeof import('@/media/upload')>('@/media/upload');

const originalHash = 'a'.repeat(64);
const differentHash = 'b'.repeat(64);
const resumeKey = `vora:media:multipart:v2:gallery:account:${originalHash}`;
const existingSession: MultipartUploadSession = {
  mediaId: 17,
  uploadId: 'existing-upload',
  partSizeBytes: 16,
  completedParts: [],
  createdAt: '2026-10-22T00:00:00.000Z',
};

function createdUpload() {
  return {
    data: { id: 41 },
    upload_url: 'https://storage.example.test/upload',
    upload_headers: {},
    thumbnail_upload_url: null,
    thumbnail_upload_headers: null,
    multipart: { enabled: true, threshold_bytes: 1, part_size_bytes: 16 },
  };
}

function startUpload(file = new File(['first'], 'video.mp4', { type: 'video/mp4', lastModified: 1234 })) {
  const refresh = jest.fn();
  render(<MediaUploadDialog characters={[]} lastInterestIds={[]} onUploaded={refresh} />);
  fireEvent.click(screen.getByRole('button', { name: 'Upload media' }));
  fireEvent.change(screen.getByLabelText('Choose uploads'), { target: { files: [file] } });
  fireEvent.click(screen.getByRole('button', { name: 'Upload' }));

  return refresh;
}

describe('MediaUploadDialog content-verified resume', () => {
  beforeEach(() => {
    jest.resetAllMocks();
    window.localStorage.clear();
    hash.mockResolvedValue(originalHash);
    readSession.mockImplementation(storage.readMultipartSession);
    jest.mocked(saveMultipartSession).mockImplementation(storage.saveMultipartSession);
    jest.mocked(removeMultipartSession).mockImplementation(storage.removeMultipartSession);
    jest.mocked(putToSignedUrl).mockResolvedValue();
    post.mockImplementation(async (url) => {
      if (url === '/api/media') return createdUpload();
      if (url === '/api/media/41/multipart/init') {
        return { data: { upload_id: 'replacement-upload', part_size_bytes: 16, expires_in_minutes: 30 } };
      }
      if (/\/multipart\/parts$/.test(url)) {
        return { data: [{ part_number: 1, url: 'https://storage.example.test/part', headers: {} }] };
      }
      if (/\/multipart\/complete$/.test(url)) return { success: true };
      throw new Error(`Unexpected request: ${url}`);
    });
    multipart.mockImplementation(async (_file, options) => {
      await options.presignParts([{ partNumber: 1, sizeBytes: _file.size }]);
      await options.complete([{ part_number: 1, etag: 'uploaded-etag' }]);
      removeMultipartSession(options.sessionKey);
    });
  });

  it('resumes matching content without creating a duplicate reservation', async () => {
    storage.saveMultipartSession(resumeKey, existingSession);
    const refresh = startUpload();

    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));

    expect(readSession).toHaveBeenCalledWith(resumeKey);
    expect(multipart).toHaveBeenCalledWith(expect.any(File), expect.objectContaining({ session: existingSession }));
    expect(post.mock.calls.map(([url]) => url)).toEqual([
      '/api/media/17/multipart/parts',
      '/api/media/17/multipart/complete',
    ]);
    expect(toast.error).not.toHaveBeenCalled();
  });

  it('creates a fresh reservation for different bytes with identical file metadata', async () => {
    storage.saveMultipartSession(resumeKey, existingSession);
    hash.mockResolvedValue(differentHash);
    const refresh = startUpload(new File(['other'], 'video.mp4', { type: 'video/mp4', lastModified: 1234 }));

    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));

    expect(readSession).toHaveBeenCalledWith(`vora:media:multipart:v2:gallery:account:${differentHash}`);
    expect(post).toHaveBeenCalledWith('/api/media', expect.objectContaining({ file_hash: differentHash }), expect.any(AbortSignal));
    expect(multipart).toHaveBeenCalledWith(expect.any(File), expect.objectContaining({
      session: expect.objectContaining({ mediaId: 41, uploadId: 'replacement-upload' }),
    }));
    expect(storage.readMultipartSession(resumeKey)).toEqual(existingSession);
  });

  it('does not consult stored sessions when hashing fails', async () => {
    hash.mockResolvedValue(null);
    readSession.mockReturnValue(existingSession);
    const refresh = startUpload();

    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));

    expect(readSession).not.toHaveBeenCalled();
    expect(post).toHaveBeenCalledWith('/api/media', expect.objectContaining({ file_hash: null }), expect.any(AbortSignal));
    expect(multipart).toHaveBeenCalledWith(expect.any(File), expect.objectContaining({
      session: expect.objectContaining({ mediaId: 41 }),
    }));
    expect(window.localStorage.length).toBe(0);
  });

  it.each([404, 422])('replaces a stale session after HTTP %i without another user attempt', async (status) => {
    storage.saveMultipartSession(resumeKey, existingSession);
    multipart.mockRejectedValueOnce(new UploadHttpError(status, 'Session expired.'));
    const refresh = startUpload();

    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));

    expect(multipart).toHaveBeenCalledTimes(2);
    expect(jest.mocked(removeMultipartSession)).toHaveBeenCalledWith(resumeKey);
    expect(post.mock.calls.map(([url]) => url)).toEqual([
      '/api/media',
      '/api/media/41/multipart/init',
      '/api/media/41/multipart/parts',
      '/api/media/41/multipart/complete',
    ]);
    expect(toast.error).not.toHaveBeenCalled();
  });

  it('cancels during preparation before reserving an upload', async () => {
    let finishHash: ((value: string) => void) | undefined;
    hash.mockImplementation(() => new Promise((resolve) => { finishHash = resolve; }));
    const refresh = startUpload();
    await waitFor(() => expect(hash).toHaveBeenCalledTimes(1));
    fireEvent.click(screen.getByRole('button', { name: 'Cancel upload' }));

    await act(async () => finishHash?.(originalHash));
    await waitFor(() => expect(toast.info).toHaveBeenCalledWith('Upload canceled.'));

    expect(hash.mock.calls[0]?.[2]?.aborted).toBe(true);
    expect(post).not.toHaveBeenCalled();
    expect(multipart).not.toHaveBeenCalled();
    expect(refresh).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Upload' })).toBeEnabled();
  });
});
