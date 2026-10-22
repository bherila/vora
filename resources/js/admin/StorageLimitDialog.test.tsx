import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { StorageLimitDialog } from './StorageLimitDialog';

const GIB = 1024 ** 3;
const account = { userName: 'Test user', limitBytes: null, unlimitedOverride: false, defaultLimitBytes: 2 * GIB };

describe('StorageLimitDialog', () => {
  it('uses the resolved site default without creating an Unlimited override', async () => {
    const save = jest.fn().mockResolvedValue(undefined);
    render(<StorageLimitDialog {...account} onSave={save} onClose={jest.fn()} />);
    expect(screen.getByLabelText('Storage allowance')).toHaveValue('default');
    expect(screen.getByRole('option', { name: 'Use site default (2 GiB)' })).toBeInTheDocument();
    expect(screen.getByText(/follows future changes to the site default/)).toBeInTheDocument();
    expect(screen.getByLabelText('Limit (GiB)')).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Save storage limit' }));
    await waitFor(() => expect(save).toHaveBeenCalledWith({ storage_limit_bytes: null, storage_unlimited: false }));
  });

  it('sets a custom account limit in GiB', async () => {
    const save = jest.fn().mockResolvedValue(undefined);
    const close = jest.fn();
    render(<StorageLimitDialog {...account} onSave={save} onClose={close} />);
    fireEvent.change(screen.getByLabelText('Storage allowance'), { target: { value: 'custom' } });
    fireEvent.change(screen.getByLabelText('Limit (GiB)'), { target: { value: '1.5' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save storage limit' }));
    await waitFor(() => expect(save).toHaveBeenCalledWith({ storage_limit_bytes: 1610612736, storage_unlimited: false }));
    expect(close).toHaveBeenCalledTimes(1);
  });

  it('allows an explicit zero limit and an Unlimited override', async () => {
    const save = jest.fn().mockResolvedValue(undefined);
    const { unmount } = render(<StorageLimitDialog {...account} limitBytes={GIB} onSave={save} onClose={jest.fn()} />);
    fireEvent.change(screen.getByLabelText('Limit (GiB)'), { target: { value: '0' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save storage limit' }));
    await waitFor(() => expect(save).toHaveBeenCalledWith({ storage_limit_bytes: 0, storage_unlimited: false }));
    unmount();
    render(<StorageLimitDialog {...account} limitBytes={0} onSave={save} onClose={jest.fn()} />);
    fireEvent.change(screen.getByLabelText('Storage allowance'), { target: { value: 'unlimited' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save storage limit' }));
    await waitFor(() => expect(save).toHaveBeenCalledWith({ storage_limit_bytes: null, storage_unlimited: true }));
  });

  it('returns an Unlimited override to inheritance even when the site default is also unlimited', async () => {
    const save = jest.fn().mockResolvedValue(undefined);
    render(<StorageLimitDialog {...account} unlimitedOverride defaultLimitBytes={null} onSave={save} onClose={jest.fn()} />);
    expect(screen.getByLabelText('Storage allowance')).toHaveValue('unlimited');
    fireEvent.change(screen.getByLabelText('Storage allowance'), { target: { value: 'default' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save storage limit' }));
    await waitFor(() => expect(save).toHaveBeenCalledWith({ storage_limit_bytes: null, storage_unlimited: false }));
  });

  it('rejects an empty custom allowance and keeps failed changes open for retry', async () => {
    const save = jest.fn().mockRejectedValue(new Error('Could not save the limit.'));
    const close = jest.fn();
    render(<StorageLimitDialog {...account} onSave={save} onClose={close} />);
    fireEvent.change(screen.getByLabelText('Storage allowance'), { target: { value: 'custom' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save storage limit' }));
    expect(screen.getByRole('alert')).toHaveTextContent('Enter a non-negative storage limit');
    expect(save).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText('Limit (GiB)'), { target: { value: '2' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save storage limit' }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Could not save the limit.'));
    expect(close).not.toHaveBeenCalled();
    expect(screen.getByLabelText('Storage allowance')).toHaveValue('custom');
    expect(screen.getByLabelText('Limit (GiB)')).toHaveValue(2);
  });

  it('edits the site default without offering inheritance and preserves a draft when switching choices', async () => {
    const save = jest.fn().mockResolvedValue(undefined);
    render(<StorageLimitDialog {...account} siteDefault onSave={save} onClose={jest.fn()} />);
    expect(screen.getByRole('heading', { name: 'Site default storage limit' })).toBeInTheDocument();
    expect(screen.getByLabelText('Storage allowance')).toHaveValue('unlimited');
    expect(screen.queryByRole('option', { name: /Use site default/ })).toBeNull();
    fireEvent.change(screen.getByLabelText('Storage allowance'), { target: { value: 'custom' } });
    fireEvent.change(screen.getByLabelText('Limit (GiB)'), { target: { value: '3' } });
    fireEvent.change(screen.getByLabelText('Storage allowance'), { target: { value: 'unlimited' } });
    fireEvent.change(screen.getByLabelText('Storage allowance'), { target: { value: 'custom' } });
    expect(screen.getByLabelText('Limit (GiB)')).toHaveValue(3);
    fireEvent.click(screen.getByRole('button', { name: 'Save site default' }));
    await waitFor(() => expect(save).toHaveBeenCalledWith({ storage_limit_bytes: 3 * GIB, storage_unlimited: false }));
  });

  it('does not save when the administrator cancels a changed allowance', () => {
    const save = jest.fn();
    const close = jest.fn();
    render(<StorageLimitDialog {...account} limitBytes={GIB} onSave={save} onClose={close} />);
    fireEvent.change(screen.getByLabelText('Storage allowance'), { target: { value: 'unlimited' } });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(close).toHaveBeenCalledTimes(1);
    expect(save).not.toHaveBeenCalled();
  });
});
