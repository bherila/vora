import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';
import { prepareHeifPreview } from '@/media/heifPhoto';
import { type AdminMediaItem } from '@/media/types';

import { AdminMediaPage } from './media';

jest.mock('@/media/heifPhoto', () => ({ ...jest.requireActual<typeof import('@/media/heifPhoto')>('@/media/heifPhoto'), prepareHeifPreview: jest.fn() }));
jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), post: jest.fn() } }));
jest.mock('sonner', () => ({ toast: { success: jest.fn(), error: jest.fn() }, Toaster: () => null }));

const item: AdminMediaItem = {
  id: 41, ulid: 'photo-one', type: 'photo', purpose: 'event', title: 'HEIC photo',
  mime_type: 'image/heic', original_filename: 'camera.HEIC', size_bytes: 15,
  upload_status: 'ready', audience: 'everyone', discoverable: false,
  url: 'https://storage.test/uploader-preview.jpg', thumbnail_url: 'https://storage.test/uploader-preview.jpg', video: null,
  created_at: '2026-10-23T00:00:00Z', character_id: null, character: null, interests: [],
  moderation_status: 'pending', moderation_notes: null, moderated_at: null, moderated_by_user_id: null,
  file_hash: null, duplicate_of_media_id: null, download_url: 'https://storage.test/stored-original.heic', review_version: 'v1',
  user: { id: 1, name: 'Uploader', email: 'uploader@example.test' },
};
const fetchOriginal = jest.fn();

beforeEach(() => {
  jest.clearAllMocks();
  jest.mocked(fetchWrapper.get).mockResolvedValue({ data: [item], meta: { has_more: false } });
  jest.mocked(fetchWrapper.post).mockResolvedValue({ success: true });
  jest.mocked(prepareHeifPreview).mockResolvedValue(new Blob(['verified-preview'], { type: 'image/jpeg' }));
  fetchOriginal.mockResolvedValue({ ok: true, headers: { get: () => '15' }, body: null, blob: async () => new Blob(['actual-original']) });
  Object.defineProperty(globalThis, 'fetch', { configurable: true, value: fetchOriginal });
  Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: jest.fn(() => `blob:${window.location.origin}/verified-photo`) });
  Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: jest.fn() });
});

test('approval requires independently fetching, decoding and displaying the stored original', async () => {
  const view = render(<AdminMediaPage />);
  const approve = await screen.findByRole('button', { name: 'Approve' });
  expect(approve).toBeDisabled();
  expect(screen.getByText(/Uploader preview — check the original/)).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Check original' }));
  const decoded = await screen.findByRole('img', { name: 'Verified preview decoded from the stored original' });
  expect(fetchOriginal).toHaveBeenCalledWith(item.download_url, expect.objectContaining({ credentials: 'omit', signal: expect.any(AbortSignal) }));
  const original = jest.mocked(prepareHeifPreview).mock.calls[0]?.[0];
  expect(original).toBeInstanceOf(File);
  expect([original?.type, original?.name, original?.size]).toEqual(['image/heic', 'camera.HEIC', 15]);
  expect(approve).toBeDisabled();
  fireEvent.load(decoded);
  await waitFor(() => expect(approve).toBeEnabled());
  fireEvent.click(approve);
  await waitFor(() => expect(fetchWrapper.post).toHaveBeenCalledWith('/api/admin/media/41/moderate', { action: 'approve', notes: null, review_version: 'v1' }));
  await waitFor(() => expect(screen.getByRole('button', { name: 'Approve' })).toBeDisabled());
  expect(await screen.findByRole('button', { name: 'Check original' })).toBeEnabled();
  view.unmount();
  expect(URL.revokeObjectURL).toHaveBeenCalledWith(`blob:${window.location.origin}/verified-photo`);
});

test('an oversized or unavailable original remains unapprovable while rejection stays available', async () => {
  fetchOriginal.mockResolvedValue({ ok: true, headers: { get: () => '1000' } });
  render(<AdminMediaPage />);
  fireEvent.click(await screen.findByRole('button', { name: 'Check original' }));
  expect(await screen.findByRole('alert')).toHaveTextContent('exceeds the photo review size limit');
  expect(screen.getByRole('button', { name: 'Approve' })).toBeDisabled();
  expect(screen.getByRole('button', { name: 'Reject' })).toBeEnabled();
  expect(prepareHeifPreview).not.toHaveBeenCalled();
});

test('leaving the review queue aborts an in-flight original fetch', async () => {
  fetchOriginal.mockImplementation((_url, options: RequestInit) => new Promise((_resolve, reject) => options.signal?.addEventListener('abort', () => reject(options.signal?.reason))));
  const view = render(<AdminMediaPage />);
  fireEvent.click(await screen.findByRole('button', { name: 'Check original' }));
  await waitFor(() => expect(fetchOriginal).toHaveBeenCalledTimes(1));
  const signal = fetchOriginal.mock.calls[0]?.[1].signal as AbortSignal;
  view.unmount();
  expect(signal.aborted).toBe(true);
});
