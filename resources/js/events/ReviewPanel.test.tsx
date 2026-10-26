import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { ReviewPanel } from '@/events/ReviewPanel';
import type { EventMediaItem } from '@/events/types';
import { fetchWrapper } from '@/fetchWrapper';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { post: jest.fn() } }));
jest.mock('@/admin/HeifOriginalReview', () => ({
  HeifOriginalReview: ({ item, onVerified }: { item: { download_url: string | null; size_bytes: number | null }; onVerified: () => void }) => <button type="button" data-url={item.download_url} data-size={item.size_bytes} onClick={onVerified}>Check original</button>,
}));

function pending(review: Partial<NonNullable<EventMediaItem['review']>> = {}): EventMediaItem & { review: NonNullable<EventMediaItem['review']> } {
  return {
    id: 41, ulid: 'pending', type: 'photo', mime_type: 'image/jpeg', url: '/preview', title: 'Toast',
    review: { pending: true, requires_original_check: false, original_url: null, original_size_bytes: 8, original_filename: 'toast.jpg', ...review },
  } as EventMediaItem & { review: NonNullable<EventMediaItem['review']> };
}

describe('ReviewPanel', () => {
  beforeEach(() => jest.resetAllMocks());

  it('approves, or rejects with a note, through the admin moderation endpoint', async () => {
    jest.mocked(fetchWrapper.post).mockResolvedValue({ success: true });
    const onModerated = jest.fn();
    render(<ReviewPanel item={pending()} onModerated={onModerated} />);
    fireEvent.click(screen.getByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(onModerated).toHaveBeenCalledWith('approve'));
    expect(fetchWrapper.post).toHaveBeenLastCalledWith('/api/admin/media/41/moderate', { action: 'approve', notes: null });

    fireEvent.click(screen.getByRole('button', { name: 'Add a note' }));
    fireEvent.change(screen.getByLabelText(/Note for the review record/), { target: { value: '  Blurry  ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Reject' }));
    await waitFor(() => expect(onModerated).toHaveBeenLastCalledWith('reject'));
    expect(fetchWrapper.post).toHaveBeenLastCalledWith('/api/admin/media/41/moderate', { action: 'reject', notes: 'Blurry' });
  });

  it('keeps Approve disabled until a HEIC original has been checked, and reports failures', async () => {
    jest.mocked(fetchWrapper.post).mockRejectedValue('Media could not be copied into reviewed storage.');
    const onModerated = jest.fn();
    render(<ReviewPanel item={pending({ requires_original_check: true, original_url: 'https://storage.example.test/original', original_size_bytes: 1234 })} onModerated={onModerated} />);
    expect(screen.getByRole('button', { name: 'Approve' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Check original' })).toHaveAttribute('data-size', '1234');
    fireEvent.click(screen.getByRole('button', { name: 'Check original' }));
    expect(screen.getByRole('button', { name: 'Approve' })).toBeEnabled();
    fireEvent.click(screen.getByRole('button', { name: 'Approve' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('could not be copied');
    expect(onModerated).not.toHaveBeenCalled();
  });
});
