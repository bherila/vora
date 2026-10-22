import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { toast } from 'sonner';

import { computeFileHash } from '@/media/imageProcessing';
import { type Audience } from '@/media/types';
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
interface MockAudienceFieldProps {
  audience: Audience;
  onAudienceChange: (value: Audience) => void;
}

jest.mock('@/community/AudienceField', () => ({
  AudienceField: function MockAudienceField({ audience, onAudienceChange }: MockAudienceFieldProps) {
    return <select aria-label="Upload audience" value={audience}
      onChange={(event) => onAudienceChange(event.target.value as Audience)}>
      <option value="everyone">Everyone</option><option value="specific">Specific people</option>
    </select>;
  },
}));
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
const matchingContext = JSON.stringify({
  audience: 'everyone', audience_user_ids: [], discoverable: true, announce: true,
  character_id: null, title: 'video', interest_ids: [], collection_ulid: null,
});
const existingSession: MultipartUploadSession = {
  mediaId: 17,
  uploadId: 'existing-upload',
  partSizeBytes: 16,
  completedParts: [],
  createdAt: '2026-10-22T00:00:00.000Z',
  context: matchingContext,
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

function startUpload(file = new File(['first'], 'video.mp4', { type: 'video/mp4', lastModified: 1234 }), configure?: () => void) {
  const refresh = jest.fn();
  render(<MediaUploadDialog characters={[]} lastInterestIds={[]} onUploaded={refresh} />);
  fireEvent.click(screen.getByRole('button', { name: 'Upload media' }));
  fireEvent.change(screen.getByLabelText('Choose uploads'), { target: { files: [file] } });
  configure?.();
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
      if (/\/multipart\/complete$/.test(url) || /\/cancel-upload$/.test(url)) return { success: true };
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

  it('does not resume content-keyed sessions when hashing fails', async () => {
    hash.mockResolvedValue(null);
    readSession.mockImplementation((key) => key === resumeKey ? existingSession : null);
    const refresh = startUpload();

    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));

    expect(readSession).not.toHaveBeenCalledWith(resumeKey);
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
      '/api/media/17/cancel-upload',
      '/api/media',
      '/api/media/41/multipart/init',
      '/api/media/41/multipart/parts',
      '/api/media/41/multipart/complete',
    ]);
    expect(toast.error).not.toHaveBeenCalled();
  });

  it('starts fresh for a stale session belonging to another account', async () => {
    storage.saveMultipartSession(resumeKey, existingSession);
    multipart.mockRejectedValueOnce(new UploadHttpError(404, 'Not found.'));
    post.mockRejectedValueOnce(new UploadHttpError(404, 'Not found.'));
    const refresh = startUpload();
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(post.mock.calls.map(([url]) => url).slice(0, 2)).toEqual(['/api/media/17/cancel-upload', '/api/media']);
    expect(multipart).toHaveBeenCalledTimes(2);
    expect(toast.error).not.toHaveBeenCalled();
  });

  it('recovers an already completed matching session without creating another upload', async () => {
    storage.saveMultipartSession(resumeKey, existingSession);
    multipart.mockRejectedValueOnce(new UploadHttpError(422, 'Already completed.'));
    post.mockRejectedValueOnce(new UploadHttpError(409, 'A completed upload cannot be canceled.'));
    const refresh = startUpload();
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(post.mock.calls.map(([url]) => url)).toEqual(['/api/media/17/cancel-upload']);
    expect(storage.readMultipartSession(resumeKey)).toBeNull();
    expect(toast.success).toHaveBeenCalled();
    expect(toast.error).not.toHaveBeenCalled();
  });

  it('preserves a stale session if its reservation cannot be safely released', async () => {
    storage.saveMultipartSession(resumeKey, existingSession);
    multipart.mockRejectedValueOnce(new UploadHttpError(422, 'Session expired.'));
    post.mockRejectedValueOnce(new UploadHttpError(503, 'Cancellation unavailable.'));
    const refresh = startUpload();
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(storage.readMultipartSession(resumeKey)).toEqual(existingSession);
    expect(post.mock.calls.map(([url]) => url)).toEqual(['/api/media/17/cancel-upload']);
    expect(toast.error).toHaveBeenCalledWith('Cancellation unavailable.');
  });

  it('cancels the new reservation if replacement initialization fails', async () => {
    storage.saveMultipartSession(resumeKey, existingSession);
    multipart.mockRejectedValueOnce(new UploadHttpError(422, 'Session expired.'));
    const implementation = post.getMockImplementation()!;
    post.mockImplementation(async (url, body, signal) => {
      if (url === '/api/media/41/multipart/init') throw new UploadHttpError(503, 'Initialization failed.');
      return implementation(url, body, signal);
    });
    const refresh = startUpload();
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(post.mock.calls.map(([url]) => url)).toEqual([
      '/api/media/17/cancel-upload', '/api/media', '/api/media/41/multipart/init', '/api/media/41/cancel-upload',
    ]);
    expect(toast.error).toHaveBeenCalledWith('Initialization failed.');
  });

  it('cancels an unresumable multipart failure when hashing was unavailable', async () => {
    hash.mockResolvedValue(null);
    multipart.mockRejectedValueOnce(new Error('Part upload failed.'));
    const refresh = startUpload();
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(post).toHaveBeenCalledWith('/api/media/41/cancel-upload', {});
    expect(window.localStorage.length).toBe(0);
    expect(toast.error).toHaveBeenCalledWith('Part upload failed.');
  });

  it.each([undefined, JSON.stringify({ ...JSON.parse(matchingContext), audience: 'specific' })])(
    'releases a session with missing or different publication context before allocating again', async (context) => {
      const session = { ...existingSession };
      if (context === undefined) delete session.context;
      else session.context = context;
      storage.saveMultipartSession(resumeKey, session);
      const refresh = startUpload();
      await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
      expect(post.mock.calls.map(([url]) => url).slice(0, 2)).toEqual(['/api/media/17/cancel-upload', '/api/media']);
      expect(multipart).toHaveBeenCalledTimes(1);
      expect(multipart).toHaveBeenCalledWith(expect.any(File), expect.objectContaining({ session: expect.objectContaining({
        mediaId: 41, context: matchingContext,
      }) }));
    },
  );

  it('recreates an Everyone upload when the user switches to Specific people before retrying', async () => {
    storage.saveMultipartSession(resumeKey, existingSession);
    const refresh = startUpload(undefined, () => {
      fireEvent.change(screen.getByLabelText('Upload audience'), { target: { value: 'specific' } });
    });
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(post.mock.calls.map(([url]) => url).slice(0, 2)).toEqual(['/api/media/17/cancel-upload', '/api/media']);
    expect(post).toHaveBeenCalledWith('/api/media', expect.objectContaining({ audience: 'specific', audience_user_ids: [] }), expect.any(AbortSignal));
    expect(multipart).toHaveBeenCalledWith(expect.any(File), expect.objectContaining({ session: expect.objectContaining({
      mediaId: 41, context: matchingContext.replace('"audience":"everyone"', '"audience":"specific"'),
    }) }));
  });

  it('never claims changed settings were applied when the previous upload is already ready', async () => {
    storage.saveMultipartSession(resumeKey, { ...existingSession, context: 'old-publication-settings' });
    post.mockRejectedValueOnce(new UploadHttpError(409, 'Already completed.'));
    const refresh = startUpload();
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(post.mock.calls.map(([url]) => url)).toEqual(['/api/media/17/cancel-upload']);
    expect(storage.readMultipartSession(resumeKey)).toBeNull();
    expect(toast.error).toHaveBeenCalledWith(expect.stringContaining('Update the existing item’s settings'));
    expect(toast.success).not.toHaveBeenCalled();
    expect(multipart).not.toHaveBeenCalled();
  });

  it('preserves a mismatched context if cancellation fails instead of allocating more storage', async () => {
    const session = { ...existingSession, context: 'old-publication-settings' };
    storage.saveMultipartSession(resumeKey, session);
    post.mockRejectedValueOnce(new UploadHttpError(503, 'Cannot cancel now.'));
    const refresh = startUpload();
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(storage.readMultipartSession(resumeKey)).toEqual(session);
    expect(post.mock.calls.map(([url]) => url)).toEqual(['/api/media/17/cancel-upload']);
    expect(multipart).not.toHaveBeenCalled();
  });

  it.each([200, 404, 409])('releases a legacy metadata-only key after status %i without trusting its bytes', async (status) => {
    const file = new File(['first'], 'video.mp4', { type: 'video/mp4', lastModified: 1234 });
    const legacyKey = `vora:media:multipart:${file.name}:${file.size}:${file.lastModified}:${file.type}`;
    storage.saveMultipartSession(legacyKey, existingSession);
    if (status !== 200) post.mockRejectedValueOnce(new UploadHttpError(status, 'Legacy reservation unavailable.'));
    const refresh = startUpload(file);
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(storage.readMultipartSession(legacyKey)).toBeNull();
    expect(post.mock.calls.map(([url]) => url).slice(0, 2)).toEqual(['/api/media/17/cancel-upload', '/api/media']);
    expect(multipart).toHaveBeenCalledWith(expect.any(File), expect.objectContaining({ session: expect.objectContaining({ mediaId: 41 }) }));
  });

  it('releases the previous Library metadata key before creating a private upload', async () => {
    const file = new File(['first'], 'video.mp4', { type: 'video/mp4', lastModified: 1234 });
    const legacyKey = `library:all:vora:media:multipart:${file.name}:${file.size}:${file.lastModified}:${file.type}`;
    storage.saveMultipartSession(legacyKey, existingSession);
    const implementation = post.getMockImplementation()!;
    post.mockImplementation(async (url, body, signal) => url === '/api/library/uploads'
      ? createdUpload() : implementation(url, body, signal));
    const refresh = jest.fn();
    render(<MediaUploadDialog characters={[]} lastInterestIds={[]} libraryCollectionUlid={null} onUploaded={refresh} />);
    fireEvent.click(screen.getByRole('button', { name: 'Upload media' }));
    fireEvent.change(screen.getByLabelText('Choose uploads'), { target: { files: [file] } });
    fireEvent.click(screen.getByRole('button', { name: 'Upload' }));
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(storage.readMultipartSession(legacyKey)).toBeNull();
    expect(post.mock.calls.map(([url]) => url).slice(0, 2)).toEqual(['/api/media/17/cancel-upload', '/api/library/uploads']);
    expect(toast.error).not.toHaveBeenCalled();
  });

  it('keeps a legacy reservation discoverable when cancellation fails', async () => {
    const file = new File(['first'], 'video.mp4', { type: 'video/mp4', lastModified: 1234 });
    const legacyKey = `vora:media:multipart:${file.name}:${file.size}:${file.lastModified}:${file.type}`;
    storage.saveMultipartSession(legacyKey, existingSession);
    post.mockRejectedValueOnce(new UploadHttpError(503, 'Cannot cancel legacy reservation.'));
    const refresh = startUpload(file);
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(storage.readMultipartSession(legacyKey)).toEqual(existingSession);
    expect(post.mock.calls.map(([url]) => url)).toEqual(['/api/media/17/cancel-upload']);
    expect(multipart).not.toHaveBeenCalled();
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
    expect(refresh).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Continue unfinished' })).toBeEnabled();
  });
  it('creates event uploads with a separate resume scope and no gallery publication fields', async () => {
    const implementation = post.getMockImplementation()!;
    post.mockImplementation(async (url, body, signal) => url === '/api/events/event-one/uploads'
      ? createdUpload() : implementation(url, body, signal));
    // An existing gallery session with identical bytes must remain untouched.
    storage.saveMultipartSession(resumeKey, existingSession);
    const refresh = jest.fn();
    render(<MediaUploadDialog characters={[]} lastInterestIds={[9]} eventUlid="event-one" libraryCollectionUlid={null} onUploaded={refresh} />);
    fireEvent.click(screen.getByRole('button', { name: 'Upload media' }));
    fireEvent.change(screen.getByLabelText('Choose uploads'), { target: { files: [new File(['first'], 'video.mp4', { type: 'video/mp4', lastModified: 1234 })] } });
    fireEvent.click(screen.getByRole('button', { name: 'Upload' }));
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(post).toHaveBeenCalledWith('/api/events/event-one/uploads', {
      type: 'video', filename: 'video.mp4', content_type: 'video/mp4', size: 5,
      title: 'video', has_thumbnail: false, thumbnail_size: null,
      perceptual_hash: null, file_hash: originalHash,
    }, expect.any(AbortSignal));
    expect(multipart).toHaveBeenCalledWith(expect.any(File), expect.objectContaining({
      sessionKey: `vora:media:multipart:v2:event:event-one:${originalHash}`,
      session: expect.objectContaining({ context: expect.stringContaining('"event_ulid":"event-one"') }),
    }));
    expect(storage.readMultipartSession(resumeKey)).toEqual(existingSession);
    expect(post).not.toHaveBeenCalledWith('/api/library/uploads', expect.anything(), expect.anything());
  });

});
