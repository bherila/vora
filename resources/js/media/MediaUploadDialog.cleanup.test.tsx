import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { toast } from 'sonner';

import { computeFileHash } from '@/media/imageProcessing';
import { putToSignedUrl, uploadMultipartFile } from '@/media/upload';
import { postUpload } from '@/media/uploadRequest';

import { MediaUploadDialog } from './MediaUploadDialog';

interface MockFileDropzoneProps {
  onFilesChange: (files: File[]) => void;
  disabled: boolean;
}

jest.mock('@/media/uploadRequest', () => ({
  ...jest.requireActual<typeof import('@/media/uploadRequest')>('@/media/uploadRequest'),
  postUpload: jest.fn(),
}));
jest.mock('sonner', () => ({ toast: { success: jest.fn(), error: jest.fn(), info: jest.fn() } }));
jest.mock('@/identity', () => ({ useActiveIdentityId: () => null }));
jest.mock('@/restrictions', () => ({ activeRestriction: () => null }));
jest.mock('@/community/AudienceField', () => ({ AudienceField: () => null }));
jest.mock('@/components/interest-picker', () => ({ InterestPicker: () => null }));
jest.mock('@/media/imageProcessing', () => ({
  supportsClientDerivatives: () => false,
  computeFileHash: jest.fn().mockResolvedValue('fixture-hash'),
}));
jest.mock('@/media/upload', () => ({
  ...jest.requireActual<typeof import('@/media/upload')>('@/media/upload'),
  putToSignedUrl: jest.fn(),
  uploadMultipartFile: jest.fn(),
  withRetries: (operation: () => Promise<unknown>) => operation(),
}));
jest.mock('@/components/media/FileDropzone', () => ({
  FileDropzone: function MockFileDropzone({ onFilesChange, disabled }: MockFileDropzoneProps) {
    return <input aria-label="Choose uploads" type="file" multiple disabled={disabled}
      onChange={(event) => onFilesChange(Array.from(event.currentTarget.files ?? []))} />;
  },
}));

const post = jest.mocked(postUpload);
const put = jest.mocked(putToSignedUrl);
const multipart = jest.mocked(uploadMultipartFile);

function createdUpload(id = 41, useMultipart = false) {
  return {
    data: { id },
    upload_url: 'https://storage.example.test/upload',
    upload_headers: {},
    thumbnail_upload_url: null,
    thumbnail_upload_headers: null,
    multipart: { enabled: useMultipart, threshold_bytes: 1, part_size_bytes: 16 },
  };
}

function startUpload(files: File[] = [new File(['photo'], 'photo.jpg', { type: 'image/jpeg' })]) {
  const refresh = jest.fn();
  render(<MediaUploadDialog characters={[]} lastInterestIds={[]} onUploaded={refresh} libraryCollectionUlid={null} />);
  fireEvent.click(screen.getByRole('button', { name: 'Upload media' }));
  fireEvent.change(screen.getByLabelText('Choose uploads'), { target: { files } });
  fireEvent.click(screen.getByRole('button', { name: 'Upload' }));

  return refresh;
}

describe('MediaUploadDialog reservation cleanup', () => {
  beforeEach(() => {
    jest.resetAllMocks();
    window.localStorage.clear();
    jest.mocked(computeFileHash).mockResolvedValue('fixture-hash');
    put.mockResolvedValue();
    post.mockImplementation(async (url) => url === '/api/library/uploads' ? createdUpload() : { success: true });
  });

  it('cancels a failed direct PUT reservation and refreshes storage before allowing retry', async () => {
    put.mockRejectedValueOnce(new Error('Storage connection lost.'));
    const refresh = startUpload();

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Storage connection lost.'));
    expect(post).toHaveBeenCalledWith('/api/media/41/cancel-upload', {});
    expect(post.mock.calls.map(([url]) => url)).not.toContain('/api/media/41/complete');
    expect(refresh).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Retry failed' })).toBeEnabled();
  });

  it('releases a direct PUT reservation when the user cancels the in-flight upload', async () => {
    put.mockImplementation((_url, _file, _headers, _onProgress, options) => new Promise<void>((_resolve, reject) => {
      options?.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
    }));
    const refresh = startUpload();
    await waitFor(() => expect(put).toHaveBeenCalledTimes(1));
    fireEvent.click(screen.getByRole('button', { name: 'Cancel upload' }));

    await waitFor(() => expect(toast.info).toHaveBeenCalledWith('Upload canceled.'));
    expect(post).toHaveBeenCalledWith('/api/media/41/cancel-upload', {});
    expect(post.mock.calls.map(([url]) => url)).not.toContain('/api/media/41/complete');
    expect(refresh).toHaveBeenCalledTimes(1);
    expect(toast.error).not.toHaveBeenCalled();
  });

  it('preserves the completion error if the server refuses cancellation of an already completed upload', async () => {
    post.mockImplementation(async (url) => {
      if (url === '/api/library/uploads') return createdUpload();
      if (url === '/api/media/41/complete') throw new Error('Completion response was lost.');
      if (url === '/api/media/41/cancel-upload') throw new Error('A completed upload cannot be canceled.');
      throw new Error(`Unexpected request: ${url}`);
    });
    const refresh = startUpload();

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Completion response was lost.'));
    expect(post).toHaveBeenCalledWith('/api/media/41/cancel-upload', {});
    expect(refresh).toHaveBeenCalledTimes(1);
    expect(toast.error).toHaveBeenCalledTimes(1);
  });

  it('cancels the reservation if multipart initialization fails before a resumable session exists', async () => {
    post.mockImplementation(async (url) => {
      if (url === '/api/library/uploads') return createdUpload(41, true);
      if (url === '/api/media/41/multipart/init') throw new Error('Multipart initialization failed.');
      if (url === '/api/media/41/cancel-upload') return { success: true };
      throw new Error(`Unexpected request: ${url}`);
    });
    const refresh = startUpload([new File(['video'], 'video.mp4', { type: 'video/mp4' })]);

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Multipart initialization failed.'));
    expect(post).toHaveBeenCalledWith('/api/media/41/cancel-upload', {});
    expect(multipart).not.toHaveBeenCalled();
    expect(window.localStorage.length).toBe(0);
    expect(refresh).toHaveBeenCalledTimes(1);
  });

  it('cancels an initialized hashed multipart upload through the owner-only endpoint and preserves completed files', async () => {
    let nextId = 41;
    jest.mocked(computeFileHash).mockResolvedValueOnce('first-hash').mockResolvedValueOnce('second-hash');
    post.mockImplementation(async (url) => {
      if (url === '/api/library/uploads') return createdUpload(nextId++, true);
      if (/\/multipart\/init$/.test(url)) {
        return { data: { upload_id: 'active-upload', part_size_bytes: 16, expires_in_minutes: 30 } };
      }
      return { success: true };
    });
    multipart.mockImplementationOnce(async (_file, options) => {
      await options.complete([{ part_number: 1, etag: 'first-complete' }]);
      window.localStorage.removeItem(options.sessionKey);
    }).mockImplementationOnce((_file, options) => new Promise<void>((_resolve, reject) => {
      options.signal?.addEventListener('abort', () => {
        // Exercise the dialog's actual abort callback. The upload helper's own
        // tests cover invoking it and discarding local state on AbortError.
        void options.abort().then(() => {
          window.localStorage.removeItem(options.sessionKey);
          reject(new DOMException('Aborted', 'AbortError'));
        }).catch(reject);
      });
    }));
    const refresh = startUpload([
      new File(['first'], 'first.mp4', { type: 'video/mp4' }),
      new File(['second'], 'second.mp4', { type: 'video/mp4' }),
    ]);
    await waitFor(() => expect(multipart).toHaveBeenCalledTimes(2));
    expect(window.localStorage.length).toBe(1);
    fireEvent.click(screen.getByRole('button', { name: 'Cancel upload' }));

    await waitFor(() => expect(toast.info).toHaveBeenCalledWith('1 upload completed before cancellation.'));
    expect(post).toHaveBeenCalledWith('/api/media/42/cancel-upload', {});
    expect(post).not.toHaveBeenCalledWith('/api/media/41/cancel-upload', {});
    expect(post.mock.calls.map(([url]) => url)).not.toContain('/api/media/42/multipart/abort');
    expect(post).toHaveBeenCalledWith('/api/media/41/multipart/complete', {
      upload_id: 'active-upload', parts: [{ part_number: 1, etag: 'first-complete' }],
    }, expect.any(AbortSignal));
    expect(window.localStorage.length).toBe(0);
    expect(refresh).toHaveBeenCalledTimes(1);
    expect(toast.error).not.toHaveBeenCalled();
  });

  it('keeps an established multipart reservation and resumes it after a transient failure', async () => {
    post.mockImplementation(async (url) => {
      if (url === '/api/library/uploads') return createdUpload(41, true);
      if (url === '/api/media/41/multipart/init') {
        return { data: { upload_id: 'resumable-123', part_size_bytes: 16, expires_in_minutes: 30 } };
      }
      throw new Error(`Unexpected request: ${url}`);
    });
    multipart.mockRejectedValue(new Error('Temporary part upload failure.'));
    const refresh = startUpload([new File(['video'], 'video.mp4', { type: 'video/mp4', lastModified: 1234 })]);

    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(window.localStorage.length).toBe(1);
    expect(JSON.parse(window.localStorage.getItem(window.localStorage.key(0)!)!)).toMatchObject({
      mediaId: 41, uploadId: 'resumable-123',
    });
    fireEvent.click(screen.getByRole('button', { name: 'Retry failed' }));
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(2));

    expect(multipart).toHaveBeenCalledTimes(2);
    expect(post.mock.calls.map(([url]) => url)).toEqual(['/api/library/uploads', '/api/media/41/multipart/init']);
    expect(toast.error).toHaveBeenLastCalledWith('Temporary part upload failure.');
  });

  it('cancels only the unfinished file when an earlier file in the same batch completed', async () => {
    let nextId = 41;
    post.mockImplementation(async (url) => url === '/api/library/uploads' ? createdUpload(nextId++) : { success: true });
    put.mockResolvedValueOnce().mockRejectedValueOnce(new Error('Second upload failed.'));
    const refresh = startUpload([
      new File(['first'], 'first.jpg', { type: 'image/jpeg' }),
      new File(['second'], 'second.jpg', { type: 'image/jpeg' }),
    ]);

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('1 of 2 uploaded. Retry failed files below.'));
    expect(post).toHaveBeenCalledWith('/api/media/41/complete', {}, expect.any(AbortSignal));
    expect(post).toHaveBeenCalledWith('/api/media/42/cancel-upload', {});
    expect(post).not.toHaveBeenCalledWith('/api/media/41/cancel-upload', {});
    expect(refresh).toHaveBeenCalledTimes(1);
    expect(screen.getAllByPlaceholderText('Title (optional)')).toHaveLength(2);
    expect(screen.getByText('Uploaded')).toBeInTheDocument();
    expect(screen.getByText('Second upload failed.')).toBeInTheDocument();
  });
});
