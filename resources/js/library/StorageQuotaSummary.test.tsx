import { render, screen } from '@testing-library/react';

import { StorageQuotaSummary } from './StorageQuotaSummary';
import { EMPTY_STORAGE_USAGE } from './types';

describe('StorageQuotaSummary', () => {
  it('shows unlimited allowance and pending reservations separately from retained files', () => {
    render(<StorageQuotaSummary usage={{ ...EMPTY_STORAGE_USAGE, active_bytes: 1024, used_bytes: 2048, reserved_bytes: 1024, retained_bytes: 4096 }} />);
    expect(screen.getByText('2.0 KB used · No storage limit')).toBeInTheDocument();
    expect(screen.getByText(/1.0 KB reserved for unfinished uploads/)).toBeInTheDocument();
    expect(screen.getByText(/Deleted files kept for admin retention, notes, and viewing shared files do not use your allowance/)).toBeInTheDocument();
    expect(screen.queryByRole('progressbar')).toBeNull();
  });

  it('warns near the limit and displays the allowance as an accessible progress bar', () => {
    render(<StorageQuotaSummary usage={{ ...EMPTY_STORAGE_USAGE, limit_bytes: 10240, used_bytes: 9216, remaining_bytes: 1024, warning: 'near_limit' }} />);
    expect(screen.getByText('Storage is nearly full')).toBeInTheDocument();
    expect(screen.getByText('1.0 KB available')).toBeInTheDocument();
    expect(screen.getByRole('progressbar', { name: 'Storage allowance used' })).toHaveAttribute('value', '90');
  });

  it('explains that reaching a zero allowance preserves existing items and notes', () => {
    render(<StorageQuotaSummary usage={{ ...EMPTY_STORAGE_USAGE, limit_bytes: 0, remaining_bytes: 0, warning: 'limit_reached' }} />);
    expect(screen.getByText('Storage allowance reached')).toBeInTheDocument();
    expect(screen.getByText(/Existing items and notes remain available/)).toBeInTheDocument();
    expect(screen.getByRole('progressbar')).toHaveAttribute('value', '100');
  });

  it('explains the metadata check needed when a finite allowance has unknown file sizes', () => {
    render(<StorageQuotaSummary usage={{ ...EMPTY_STORAGE_USAGE, limit_bytes: 10000, over_limit: true, warning: 'unknown_size', active_unknown_size_file_count: 1 }} />);
    expect(screen.getByRole('alert')).toHaveTextContent('Storage sizes need checking');
    expect(screen.getByText(/until an administrator checks their storage metadata/)).toBeInTheDocument();
  });
});
