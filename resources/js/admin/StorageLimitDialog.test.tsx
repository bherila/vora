import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { StorageLimitDialog } from './StorageLimitDialog';

describe('StorageLimitDialog', () => {
  beforeAll(() => {
    Object.defineProperty(window, 'PointerEvent', { configurable: true, value: MouseEvent });
  });

  it('sets a finite account limit in GiB', async () => {
    const save = jest.fn().mockResolvedValue(undefined);
    const close = jest.fn();
    render(<StorageLimitDialog userName="Test user" limitBytes={null} onSave={save} onClose={close} />);
    fireEvent.click(screen.getByRole('checkbox', { name: 'Unlimited storage' }));
    fireEvent.change(screen.getByLabelText('Limit (GiB)'), { target: { value: '1.5' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save storage limit' }));
    await waitFor(() => expect(save).toHaveBeenCalledWith(1610612736));
    expect(close).toHaveBeenCalledTimes(1);
  });

  it('allows an explicit zero limit and clearing an existing limit', async () => {
    const save = jest.fn().mockResolvedValue(undefined);
    const { unmount } = render(<StorageLimitDialog userName="Test user" limitBytes={1024 ** 3} onSave={save} onClose={jest.fn()} />);
    fireEvent.change(screen.getByLabelText('Limit (GiB)'), { target: { value: '0' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save storage limit' }));
    await waitFor(() => expect(save).toHaveBeenCalledWith(0));
    unmount();
    render(<StorageLimitDialog userName="Test user" limitBytes={0} onSave={save} onClose={jest.fn()} />);
    fireEvent.click(screen.getByRole('checkbox', { name: 'Unlimited storage' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save storage limit' }));
    await waitFor(() => expect(save).toHaveBeenCalledWith(null));
  });

  it('rejects an empty finite allowance and keeps failed changes open for retry', async () => {
    const save = jest.fn().mockRejectedValue(new Error('Could not save the limit.'));
    const close = jest.fn();
    render(<StorageLimitDialog userName="Test user" limitBytes={null} onSave={save} onClose={close} />);
    fireEvent.click(screen.getByRole('checkbox', { name: 'Unlimited storage' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save storage limit' }));
    expect(screen.getByRole('alert')).toHaveTextContent('Enter a non-negative storage limit');
    expect(save).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText('Limit (GiB)'), { target: { value: '2' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save storage limit' }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Could not save the limit.'));
    expect(close).not.toHaveBeenCalled();
    expect(screen.getByLabelText('Limit (GiB)')).toHaveValue(2);
  });
});
