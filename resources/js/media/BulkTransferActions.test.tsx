import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';
import { BulkTransferActions } from '@/media/BulkTransferActions';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { post: jest.fn() } }));
jest.mock('@/initialData', () => ({ readInitialData: jest.fn() }));

describe('BulkTransferActions', () => {
  beforeEach(() => {
    jest.resetAllMocks();
    jest.mocked(readInitialData).mockReturnValue({ instance: { profile: 'event' } });
  });

  it('keeps the compact bar to short actions and only explains a limited download', () => {
    const { rerender } = render(<BulkTransferActions compact source="events" ulids={['one', 'two']} downloadUlids={['one', 'two']} canSave onSaved={jest.fn()} onClear={jest.fn()} />);
    expect(screen.getByRole('button', { name: /^Save selected to / })).toHaveTextContent(/^Save$/);
    expect(screen.getByRole('button', { name: 'Download selected (ZIP)' })).toHaveTextContent(/^Download$/);
    expect(screen.queryByRole('button', { name: 'Clear selection' })).toBeNull();
    expect(screen.queryByText(/ZIP downloads/)).toBeNull();
    expect(screen.queryByText(/Saving adds private references/)).toBeNull();

    const many = Array.from({ length: 51 }, (_, index) => `item-${index}`);
    rerender(<BulkTransferActions compact source="events" ulids={many} downloadUlids={many} canSave onSaved={jest.fn()} />);
    expect(screen.getByRole('button', { name: 'Download selected (ZIP)' })).toBeDisabled();
    expect(screen.getByText(/ZIP downloads: up to 50 files/)).toBeInTheDocument();
  });

  it('saves the exact selection privately and leaves a failed selection retryable', async () => {
    const saved = jest.fn();
    jest.mocked(fetchWrapper.post).mockRejectedValueOnce('Storage limit reached.').mockResolvedValueOnce({ success: true });
    render(<BulkTransferActions source="events" ulids={['one', 'two']} downloadUlids={['one', 'two']} canSave onSaved={saved} />);
    fireEvent.click(screen.getByRole('button', { name: 'Save selected to my collection' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Storage limit reached');
    expect(saved).not.toHaveBeenCalled();
    expect(screen.getByText('2 selected')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save selected to my collection' }));
    await waitFor(() => expect(saved).toHaveBeenCalledTimes(1));
    expect(fetchWrapper.post).toHaveBeenLastCalledWith('/api/events/media/bulk-save', { ulids: ['one', 'two'] });
  });

  it('uses a native browser download without fetching the archive into a Blob', async () => {
    const click = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
    const previousFetch = window.fetch;
    const fetch = jest.fn();
    window.fetch = fetch;
    jest.mocked(fetchWrapper.post).mockResolvedValue({ data: { download_url: '/api/bulk-downloads/ticket' } });
    render(<BulkTransferActions source="library" ulids={['note', 'file']} downloadUlids={['file']} canSave={false} onSaved={jest.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Download selected (ZIP)' }));
    await waitFor(() => expect(click).toHaveBeenCalledTimes(1));
    expect(fetchWrapper.post).toHaveBeenCalledWith('/api/library/items/bulk-downloads', { ulids: ['file'] });
    expect(fetch).not.toHaveBeenCalled();
    expect(screen.getByText(/Notes and unavailable files are excluded/)).toBeInTheDocument();
    click.mockRestore(); window.fetch = previousFetch;
  });

  it('keeps working controls disabled while preparing and reports unsafe or rejected download URLs', async () => {
    let resolve: ((value: unknown) => void) | undefined;
    jest.mocked(fetchWrapper.post).mockImplementation(() => new Promise((finish) => { resolve = finish; }));
    render(<BulkTransferActions source="events" ulids={['one']} downloadUlids={['one']} canSave onSaved={jest.fn()} onClear={jest.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Download selected (ZIP)' }));
    expect(screen.getByRole('button', { name: 'Preparing ZIP…' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Clear selection' })).toBeDisabled();
    await act(async () => { resolve?.({ data: { download_url: 'https://evil.invalid/zip' } }); });
    expect(await screen.findByRole('alert')).toHaveTextContent('could not be prepared');
  });

  it('never starts a stale native download after leaving the selection', async () => {
    const click = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
    let resolve: ((value: unknown) => void) | undefined;
    jest.mocked(fetchWrapper.post).mockImplementation(() => new Promise((finish) => { resolve = finish; }));
    const { unmount } = render(<BulkTransferActions source="events" ulids={['one']} downloadUlids={['one']} canSave={false} onSaved={jest.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Download selected (ZIP)' }));
    unmount();
    await act(async () => { resolve?.({ data: { download_url: '/api/bulk-downloads/ticket' } }); });
    expect(click).not.toHaveBeenCalled();
    click.mockRestore();
  });
});
