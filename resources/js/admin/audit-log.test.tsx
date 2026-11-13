import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { AuditLogPage } from './audit-log';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn() } }));

const entry = {
  id: 5, event: 'login_failed', email: 'guest@example.test', ip_address: '192.0.2.1',
  created_at: '2026-11-13T12:00:00Z', user_id: 9, acting_user_id: 3,
  auth_method: 'password', succeeded: false, is_suspicious: true,
  reason: 'invalid_credentials', metadata: { attempt_suspicious: true },
};
const page = { data: [entry], current_page: 1, last_page: 3, total: 101 };

describe('AuditLogPage', () => {
  beforeEach(() => {
    jest.mocked(fetchWrapper.get).mockReset().mockResolvedValue(page);
  });

  it('pages through the host audit API and applies exact filters from the first page', async () => {
    render(<AuditLogPage />);
    expect(await screen.findByText('101 events match.')).toBeInTheDocument();
    expect(fetchWrapper.get).toHaveBeenLastCalledWith('/api/auth/audit-log/all?page=1&per_page=50');
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();

    jest.mocked(fetchWrapper.get).mockResolvedValue({ ...page, current_page: 2 });
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(await screen.findByText('Page 2 of 3')).toBeInTheDocument();
    expect(fetchWrapper.get).toHaveBeenLastCalledWith('/api/auth/audit-log/all?page=2&per_page=50');
    jest.mocked(fetchWrapper.get).mockResolvedValue(page);
    fireEvent.click(screen.getByRole('button', { name: 'Previous' }));
    expect(await screen.findByText('Page 1 of 3')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Local user ID'), { target: { value: '9' } });
    fireEvent.change(screen.getByLabelText('Email (exact)'), { target: { value: ' guest+tag@example.test ' } });
    fireEvent.change(screen.getByLabelText('Event (exact)'), { target: { value: ' login_failed ' } });
    fireEvent.change(screen.getByLabelText('Per page'), { target: { value: '200' } });
    expect(fetchWrapper.get).toHaveBeenCalledTimes(3);
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenLastCalledWith('/api/auth/audit-log/all?page=1&per_page=200&user_id=9&event=login_failed&email=guest%2Btag%40example.test'));
    expect(await screen.findByText('Page 1 of 3')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenLastCalledWith('/api/auth/audit-log/all?page=1&per_page=50'));
    expect(screen.getByLabelText('Email (exact)')).toHaveValue('');
    expect(screen.getByLabelText('Local user ID')).toHaveValue(null);
  });

  it('shows local actor/target links, outcomes and only explicitly allowed event details', async () => {
    jest.mocked(fetchWrapper.get).mockResolvedValue({ ...page, data: [
      { ...entry, metadata: { ...entry.metadata, credential_id: 'secret-credential', password: 'secret-password', token: 'secret-token', subject: '88', actor_subject: '77', before: { application_admin: false }, after: { application_admin: true } }, session_id: 'secret-session' },
      { ...entry, id: 6, user_id: null, acting_user_id: null, email: null, succeeded: true, reason: 'secret-diagnostic', metadata: { before: { is_admin: true }, after: { is_admin: false } } },
    ] });
    render(<AuditLogPage />);
    expect(await screen.findByRole('link', { name: 'User #9' })).toHaveAttribute('href', '/admin/users#user-9');
    expect(screen.getByRole('link', { name: 'User #3' })).toHaveAttribute('href', '/admin/users#user-3');
    expect(screen.getAllByRole('link')).toHaveLength(2);
    expect(screen.getByText('Failed')).toBeInTheDocument();
    expect(screen.getByText('Succeeded')).toBeInTheDocument();
    expect(screen.getAllByText('Flagged suspicious')).toHaveLength(2);
    fireEvent.click(screen.getByText('for event #5').parentElement!);
    expect(screen.getByText('Invalid credentials')).toBeVisible();
    expect(screen.getAllByText('192.0.2.1')[0]).toBeVisible();
    expect(screen.getByText('No → Yes')).toBeVisible();
    fireEvent.click(screen.getByText('for event #6').parentElement!);
    expect(screen.getByText('Yes → No')).toBeVisible();
    expect(screen.getByText(/raw diagnostic text is omitted/)).toBeVisible();
    expect(document.body.textContent).not.toMatch(/secret-|credential_id|actor_subject/);
  });

  it('never makes user links from invalid IDs or provider subjects', async () => {
    jest.mocked(fetchWrapper.get).mockResolvedValue({ ...page, data: [
      { ...entry, user_id: -1, acting_user_id: 1.5, reason: '__proto__', metadata: { subject: '9' } },
      { ...entry, id: 6, user_id: Number.MAX_SAFE_INTEGER + 1, acting_user_id: '7' },
    ] });
    render(<AuditLogPage />);
    await screen.findByText('101 events match.');
    expect(screen.queryAllByRole('link')).toHaveLength(0);
  });

  it('keeps loading separate from empty results and disables paging at the last page', async () => {
    let finish!: (result: unknown) => void;
    jest.mocked(fetchWrapper.get).mockReturnValue(new Promise((resolve) => { finish = resolve; }));
    render(<AuditLogPage />);
    expect(screen.getByRole('status')).toHaveTextContent('Loading audit log');
    expect(screen.queryByText(/No audit log entries/)).not.toBeInTheDocument();
    await act(async () => finish({ data: [], current_page: 1, last_page: 1, total: 0 }));
    expect(screen.getByText('No audit log entries match these filters.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();
  });

  it('offers retry without exposing raw server error messages or showing failed requests as empty', async () => {
    jest.mocked(fetchWrapper.get).mockRejectedValueOnce('secret-server-diagnostic');
    render(<AuditLogPage />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load the audit log');
    expect(screen.queryByText(/No audit log entries/)).not.toBeInTheDocument();
    expect(document.body.textContent).not.toContain('secret-server-diagnostic');
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    expect(await screen.findByText('101 events match.')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('ignores an older request that finishes after a new filter response', async () => {
    let finishOld!: (result: unknown) => void;
    jest.mocked(fetchWrapper.get).mockReturnValueOnce(new Promise((resolve) => { finishOld = resolve; }));
    render(<AuditLogPage />);
    fireEvent.change(screen.getByLabelText('Event (exact)'), { target: { value: 'password_changed' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
    expect(await screen.findByText('101 events match.')).toBeInTheDocument();
    await act(async () => finishOld({ data: [], current_page: 1, last_page: 1, total: 0 }));
    expect(screen.getByText('101 events match.')).toBeInTheDocument();
    expect(screen.queryByText(/No audit log entries/)).not.toBeInTheDocument();
  });
});
