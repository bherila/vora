import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { type ComponentProps } from 'react';

import { computeFileHash } from '@/media/imageProcessing';
import { putToSignedUrl, uploadMultipartFile } from '@/media/upload';
import { postUpload, UploadHttpError } from '@/media/uploadRequest';

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

function openUpload(props: Partial<ComponentProps<typeof MediaUploadDialog>> = {}) {
  const refresh = jest.fn();
  const rendered = render(<MediaUploadDialog characters={[]} lastInterestIds={[]} onUploaded={refresh} {...props} />);
  fireEvent.click(screen.getByRole('button', { name: 'Upload media' }));
  return { refresh, ...rendered };
}

const photos = [1, 2, 3].map((number) => new File([`photo-${number}`], `photo-${number}.jpg`, { type: 'image/jpeg', lastModified: number }));
const events = [{ ulid: 'private-event', name: 'Private shower', visibility: 'specific' as const }, { ulid: 'public-event', name: 'Reception', visibility: 'everyone' as const }];
function choose(files = photos): void {
  fireEvent.change(screen.getByLabelText('Choose uploads'), { target: { files } });
}
function submit(): void { fireEvent.click(screen.getByRole('button', { name: 'Upload' })); }

describe('MediaUploadDialog Event selection and recoverable batches', () => {
  beforeEach(() => {
    jest.resetAllMocks();
    window.localStorage.clear();
    jest.mocked(computeFileHash).mockResolvedValue('fixture-hash');
    put.mockResolvedValue();
    let id = 41;
    post.mockImplementation(async (url) => /uploads$/.test(url) ? createdUpload(id++) : { success: true });
    Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: jest.fn(() => `blob:preview-${Math.random()}`) });
    Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: jest.fn() });
  });

  it('chooses an Event inside the dialog, shows its audience and never falls back to Gallery', async () => {
    const { refresh } = openUpload({ eventOptions: events });
    choose([photos[0]!]);
    expect(screen.getByRole('button', { name: 'Upload' })).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Destination event'), { target: { value: 'private-event' } });
    expect(screen.getByText(/Private shower: Only selected attendees/)).toBeInTheDocument();
    submit();
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(post).toHaveBeenCalledWith('/api/events/private-event/uploads', expect.objectContaining({ filename: 'photo-1.jpg' }), expect.any(AbortSignal));
    const payload = post.mock.calls.find(([url]) => url.endsWith('/uploads'))![1];
    expect(payload).not.toHaveProperty('audience');
    expect(payload).not.toHaveProperty('character_id');
    expect(payload).not.toHaveProperty('collection_ulid');
    expect(post.mock.calls.map(([url]) => url)).not.toContain('/api/media');
  });

  it('handles empty and unavailable Event lists without allocating uploads', () => {
    const view = openUpload({ eventOptions: [], eventUlid: 'removed' });
    choose([photos[0]!]);
    expect(screen.getByText(/No events are available/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Upload' })).toBeDisabled();
    view.rerender(<MediaUploadDialog characters={[]} lastInterestIds={[]} onUploaded={view.refresh} eventOptions={events} eventUlid="removed" />);
    expect(screen.getByText(/selected event is no longer available/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Upload' })).toBeDisabled();
    expect(post).not.toHaveBeenCalled();
  });

  it('continues after an individual failure and retries only that file with the destination frozen', async () => {
    const { refresh } = openUpload({ eventOptions: events, eventUlid: 'public-event' });
    choose();
    put.mockResolvedValueOnce().mockRejectedValueOnce(new Error('Second photo failed.')).mockResolvedValueOnce();
    submit();
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(screen.getByText('2 of 3 uploaded')).toBeInTheDocument();
    expect(screen.getAllByText('Uploaded · awaiting review')).toHaveLength(2);
    expect(screen.getByText('Second photo failed.')).toBeInTheDocument();
    expect(screen.getByLabelText('Destination event')).toBeDisabled();
    expect(post).toHaveBeenCalledWith('/api/media/42/cancel-upload', {});
    fireEvent.click(screen.getByRole('button', { name: 'Retry failed' }));
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(2));
    const allocations = post.mock.calls.filter(([url]) => url.endsWith('/uploads'));
    expect(allocations).toHaveLength(4);
    expect(allocations.map(([, body]) => (body as { filename: string }).filename)).toEqual(['photo-1.jpg', 'photo-2.jpg', 'photo-3.jpg', 'photo-2.jpg']);
    expect(allocations.every(([url]) => url === '/api/events/public-event/uploads')).toBe(true);
    expect(post).not.toHaveBeenCalledWith('/api/media/41/cancel-upload', {});
    expect(post).not.toHaveBeenCalledWith('/api/media/43/cancel-upload', {});
  });

  it('marks an unsupported file as an individual failure and uploads the valid files', async () => {
    const { refresh } = openUpload({ libraryCollectionUlid: null });
    choose([photos[0]!, new File(['text'], 'notes.txt', { type: 'text/plain' }), photos[2]!]);
    submit();
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(screen.getByText('2 of 3 uploaded')).toBeInTheDocument();
    expect(screen.getByText('Only image and video files can be uploaded.')).toBeInTheDocument();
    expect(post.mock.calls.filter(([url]) => url.endsWith('/uploads'))).toHaveLength(2);
    fireEvent.click(screen.getByRole('button', { name: 'Remove notes.txt' }));
    await waitFor(() => expect(screen.queryByText('notes.txt')).not.toBeInTheDocument());
    expect(screen.getByText('2 of 2 uploaded')).toBeInTheDocument();
    expect(screen.getAllByText('Uploaded · awaiting review')).toHaveLength(2);
    expect(screen.getByRole('button', { name: 'Done' })).toBeEnabled();
  });

  it('shows current batch position and cancels only unfinished media before continuing the rest', async () => {
    const { refresh } = openUpload({ libraryCollectionUlid: null });
    choose();
    put.mockResolvedValueOnce().mockImplementationOnce((_url, _file, _headers, progress, options) => new Promise<void>((_resolve, reject) => {
      progress(0.5);
      options?.signal?.addEventListener('abort', () => reject(new DOMException('Canceled', 'AbortError')));
    }));
    submit();
    await waitFor(() => expect(put).toHaveBeenCalledTimes(2));
    expect(screen.getByText('Uploading 2 of 3: photo-2.jpg')).toBeInTheDocument();
    expect(within(screen.getByRole('listitem', { name: 'photo-2.jpg' })).getByRole('progressbar')).toHaveValue(50);
    fireEvent.click(screen.getByRole('button', { name: 'Cancel upload' }));
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(post.mock.calls.filter(([url]) => url.endsWith('/uploads'))).toHaveLength(2);
    expect(post).toHaveBeenCalledWith('/api/media/42/cancel-upload', {});
    expect(post).not.toHaveBeenCalledWith('/api/media/41/cancel-upload', {});
    fireEvent.click(screen.getByRole('button', { name: 'Continue unfinished' }));
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(2));
    expect(post.mock.calls.filter(([url]) => url.endsWith('/uploads'))).toHaveLength(4);
  });

  it('retries failed quota cleanup before allocating another direct upload', async () => {
    let id = 41;
    let cleanupFails = true;
    post.mockImplementation(async (url) => {
      if (url === '/api/library/uploads') return createdUpload(id++);
      if (url === '/api/media/41/cancel-upload' && cleanupFails) throw new UploadHttpError(503, 'Cleanup temporarily unavailable.');
      return { success: true };
    });
    put.mockRejectedValueOnce(new Error('Storage unavailable.'));
    const { refresh } = openUpload({ libraryCollectionUlid: null });
    choose([photos[0]!]); submit();
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    fireEvent.click(screen.getByRole('button', { name: 'Retry failed' }));
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(2));
    expect(post.mock.calls.filter(([url]) => url === '/api/library/uploads')).toHaveLength(1);
    cleanupFails = false;
    fireEvent.click(screen.getByRole('button', { name: 'Retry failed' }));
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(3));
    expect(post.mock.calls.filter(([url]) => url === '/api/library/uploads')).toHaveLength(2);
  });

  it('keeps multipart sessions scoped to the Event selected inside the dialog', async () => {
    const otherKey = 'vora:media:multipart:v2:event:private-event:fixture-hash';
    const otherSession = { mediaId: 17, uploadId: 'other-event', partSizeBytes: 16, completedParts: [], createdAt: '2026-10-22T00:00:00Z', context: 'other-context' };
    window.localStorage.setItem(otherKey, JSON.stringify(otherSession));
    post.mockImplementation(async (url) => {
      if (url === '/api/events/public-event/uploads') return createdUpload(41, true);
      if (url === '/api/media/41/multipart/init') return { data: { upload_id: 'new-event-session', part_size_bytes: 16, expires_in_minutes: 30 } };
      return { success: true };
    });
    multipart.mockImplementation(async (_file, options) => {
      expect(options.sessionKey).toBe('vora:media:multipart:v2:event:public-event:fixture-hash');
      expect(options.session.context).toContain('"event_ulid":"public-event"');
      await options.complete([{ part_number: 1, etag: 'part-etag' }]);
      window.localStorage.removeItem(options.sessionKey);
    });
    const { refresh } = openUpload({ eventOptions: events, eventUlid: 'private-event' });
    fireEvent.change(screen.getByLabelText('Destination event'), { target: { value: 'public-event' } });
    choose([new File(['video'], 'video.mp4', { type: 'video/mp4' })]); submit();
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(JSON.parse(window.localStorage.getItem(otherKey)!)).toEqual(otherSession);
    expect(post.mock.calls.some(([url]) => url.includes('/17/'))).toBe(false);
  });

  it('releases a failed resumable reservation before choosing different files and a new Event', async () => {
    post.mockImplementation(async (url) => {
      if (url === '/api/events/private-event/uploads') return createdUpload(41, true);
      if (url === '/api/media/41/multipart/init') return { data: { upload_id: 'retryable', part_size_bytes: 16, expires_in_minutes: 30 } };
      return { success: true };
    });
    multipart.mockRejectedValueOnce(new Error('Connection lost.'));
    const { refresh } = openUpload({ eventOptions: events, eventUlid: 'private-event' });
    choose([new File(['video'], 'video.mp4', { type: 'video/mp4' })]); submit();
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(window.localStorage.length).toBe(1);
    expect(screen.getByLabelText('Destination event')).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Choose different files' }));
    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(2));
    expect(post).toHaveBeenCalledWith('/api/media/41/cancel-upload', {});
    expect(window.localStorage.length).toBe(0);
    expect(screen.getByLabelText('Destination event')).toBeEnabled();
    expect(screen.queryByRole('list', { name: 'Selected uploads' })).not.toBeInTheDocument();
  });

  it('keeps previews bounded, optional titles collapsed for batches, and releases preview URLs', async () => {
    const { unmount } = openUpload({ eventOptions: events, eventUlid: 'private-event' });
    choose();
    expect(screen.getAllByRole('img', { name: /Preview of/ })).toHaveLength(3);
    expect(screen.getByRole('list', { name: 'Selected uploads' })).toHaveClass('max-h-64', 'overflow-y-auto');
    expect(screen.getAllByText('Edit title (optional)').every((summary) => !summary.parentElement?.hasAttribute('open'))).toBe(true);
    expect(screen.getByText(/Keep this page open and your device awake/)).toBeInTheDocument();
    const previews = jest.mocked(URL.createObjectURL).mock.results.map((result) => result.value as string);
    unmount();
    expect(URL.revokeObjectURL).toHaveBeenCalledTimes(3);
    for (const preview of previews) expect(URL.revokeObjectURL).toHaveBeenCalledWith(preview);
  });
});
