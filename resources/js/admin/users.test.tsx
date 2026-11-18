import '@testing-library/jest-dom';

import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { AdminUsersPage } from '@/admin/users';
import { fetchWrapper } from '@/fetchWrapper';

let mockProfile: 'vora' | 'event' = 'vora';
let mockEventsEnabled = true;
jest.mock('@/instance', () => ({ instanceProfile: () => mockProfile, moduleEnabled: (module: string) => module !== 'events' || mockEventsEnabled }));

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: jest.fn(),
    post: jest.fn(),
    put: jest.fn(),
    delete: jest.fn(),
  },
}));

const user = {
  id: 8,
  name: 'Alex Example',
  display_name: 'Alex',
  birth_date: '1990-01-01',
  email: 'alex@example.test',
  identity: {
    sign_in_mode: 'local' as const,
    configured_provider: null,
    binding: 'local' as const,
    provider: null,
    subject: null,
    provider_account_url: null,
    local_email_lock_available: true,
  },
  is_admin: false,
  is_disabled: false,
  is_deactivated: false,
  is_deleted: false,
  is_approved: true,
  id_verified: false,
  birth_date_verified: false,
  email_verified: true,
  name_locked: false,
  email_locked: false,
  id_verified_at: null,
  approved_at: '2026-01-01T00:00:00Z',
  last_login_at: null,
  created_at: '2026-01-01T00:00:00Z',
  invite_balance: 0,
  can_receive_invites: true,
  trusted_inviter: false,
  library_enabled: true,
  event_uploads_auto_approved: false,
  storage_limit_bytes: null,
  storage_unlimited: false,
  effective_storage_limit_bytes: null,
  is_banned: false,
  ban_reason: null,
  ban_hides_content: false,
  ban_appeal_message: null,
  ban_appeal_at: null,
  is_on_legal_hold: false,
  legal_hold_note: null,
  referrer_user_id: null,
  referrer_display_name: null,
  restrictions: [{
    id: 33,
    capability: 'media.view',
    label: 'Media viewing',
    reason: 'Safety review',
    expires_at: null,
    lifted_at: null,
    created_at: '2026-08-01T00:00:00Z',
    active: true,
  }],
};

interface DirectoryFixtureUser {
  id: number;
  name: string;
  display_name: string | null;
  email: string;
  is_deleted: boolean;
  is_approved: boolean;
  is_admin: boolean;
  is_banned: boolean;
  restrictions: Array<{ active: boolean }>;
}

function directoryResponse<T extends { data: DirectoryFixtureUser[] }>(response: T) {
  return {
    meta: { current_page: 1, last_page: 1, per_page: 30, total: response.data.length, has_more: false },
    counts: {
      all: response.data.length,
      pending: response.data.filter((account) => !account.is_deleted && !account.is_approved).length,
      restricted: response.data.filter((account) => !account.is_deleted && account.restrictions.some((restriction) => restriction.active)).length,
      banned: response.data.filter((account) => !account.is_deleted && account.is_banned).length,
      admins: response.data.filter((account) => !account.is_deleted && account.is_admin).length,
      deleted: response.data.filter((account) => account.is_deleted).length,
    },
    ...response,
  };
}

function filteredResponse<T extends { data: DirectoryFixtureUser[] }>(response: T, url: string) {
  const params = new URL(url, 'https://app.example.test').searchParams;
  const status = params.get('status');
  const search = (params.get('search') ?? '').toLowerCase();
  const complete = directoryResponse(response);
  const data = response.data.filter((account) => {
    const match = status === 'pending' ? !account.is_deleted && !account.is_approved
      : status === 'deleted' ? account.is_deleted
        : status === 'admins' ? !account.is_deleted && account.is_admin
          : status === 'banned' ? !account.is_deleted && account.is_banned : true;
    return match && [account.name, account.display_name ?? '', account.email].some((value) => value.toLowerCase().includes(search));
  });
  return { ...complete, data, meta: { ...complete.meta, total: data.length } };
}

beforeEach(() => { window.history.replaceState({}, '', '/admin/users'); });

describe('AdminUsersPage restrictions', () => {
  beforeEach(() => {
    mockProfile = 'vora';
    mockEventsEnabled = true;
    jest.clearAllMocks();
    jest.mocked(fetchWrapper.get).mockResolvedValue(directoryResponse({ success: true, data: [user], settings: { default_storage_limit_bytes: null } }));
    jest.mocked(fetchWrapper.delete).mockResolvedValue({ success: true });
  });

  it('shows active reasons and lifts the selected historical record', async () => {
    render(<AdminUsersPage />);

    const details = await screen.findByRole('complementary', { name: 'User details' });
    expect(within(details).getByText('Restricted: Media viewing')).toBeInTheDocument();
    expect(within(details).getByText(/Safety review/)).toBeInTheDocument();
    fireEvent.click(within(details).getByRole('button', { name: 'Lift Media viewing' }));

    await waitFor(() => expect(fetchWrapper.delete).toHaveBeenCalledWith('/api/admin/users/8/restrictions/33', {}));
  });
});


const GIB = 1024 ** 3;

const details = (): Promise<HTMLElement> => screen.findByRole('complementary', { name: 'User details' });

describe('AdminUsersPage storage defaults', () => {
  const originalFetch = globalThis.fetch;

  beforeEach(() => {
    mockProfile = 'vora';
    mockEventsEnabled = true;
    jest.clearAllMocks();
    jest.mocked(fetchWrapper.get).mockResolvedValue(directoryResponse({ success: true, data: [user], settings: { default_storage_limit_bytes: null } }));
    globalThis.fetch = jest.fn().mockResolvedValue({ ok: true, json: async () => ({ success: true, data: user }) });
  });

  afterEach(() => { globalThis.fetch = originalFetch; });

  it('distinguishes inherited, custom zero and explicit unlimited allowances', async () => {
    jest.mocked(fetchWrapper.get).mockResolvedValue(directoryResponse({
      success: true, settings: { default_storage_limit_bytes: 2 * GIB },
      data: [
        { ...user, effective_storage_limit_bytes: 2 * GIB },
        { ...user, id: 9, email: 'custom@example.test', storage_limit_bytes: 0, effective_storage_limit_bytes: 0 },
        { ...user, id: 10, email: 'unlimited@example.test', storage_unlimited: true },
      ],
    }));
    render(<AdminUsersPage />);
    // Inherited storage is the default and earns no row badge; overrides do.
    expect(within(await details()).getByText('Site default (2 GiB)')).toBeInTheDocument();
    expect(screen.queryByText('Storage: Site default (2 GiB)')).not.toBeInTheDocument();
    expect(screen.getByText('Storage: Custom (0 B)')).toBeInTheDocument();
    expect(screen.getByText('Storage: Unlimited override')).toBeInTheDocument();
    expect(screen.getByText('Site default storage: 2 GiB')).toBeInTheDocument();
  });

  it('updates only the global setting and reloads effective inherited limits', async () => {
    let siteDefault: number | null = null;
    jest.mocked(fetchWrapper.get).mockImplementation(async () => directoryResponse({
      success: true, settings: { default_storage_limit_bytes: siteDefault },
      data: [
        { ...user, effective_storage_limit_bytes: siteDefault },
        { ...user, id: 9, email: 'custom@example.test', storage_limit_bytes: GIB, effective_storage_limit_bytes: GIB },
        { ...user, id: 10, email: 'unlimited@example.test', storage_unlimited: true },
      ],
    }));
    jest.mocked(fetchWrapper.put).mockImplementation(async (_url, body) => {
      siteDefault = (body as { default_storage_limit_bytes: number | null }).default_storage_limit_bytes;
      return { success: true, data: { default_storage_limit_bytes: siteDefault } };
    });
    render(<AdminUsersPage />);
    await within(await details()).findByText('Site default (Unlimited)');
    fireEvent.click(screen.getByRole('button', { name: 'Change site default' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Storage allowance'), { target: { value: 'custom' } });
    fireEvent.change(within(dialog).getByLabelText('Limit (GiB)'), { target: { value: '2' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save site default' }));
    await waitFor(() => expect(fetchWrapper.put).toHaveBeenCalledWith('/api/admin/storage-settings', { default_storage_limit_bytes: 2 * GIB }));
    expect(await within(await details()).findByText('Site default (2 GiB)')).toBeInTheDocument();
    expect(screen.getByText('Site default storage: 2 GiB')).toBeInTheDocument();
    expect(screen.getByText('Storage: Custom (1 GiB)')).toBeInTheDocument();
    expect(screen.getByText('Storage: Unlimited override')).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(fetchWrapper.get).toHaveBeenCalledTimes(2);
    expect(globalThis.fetch).not.toHaveBeenCalled();
  });

  it('keeps failed global edits visible and preserves the chosen amount', async () => {
    jest.mocked(fetchWrapper.put).mockRejectedValue('Default storage could not be saved.');
    render(<AdminUsersPage />);
    await within(await details()).findByText('Site default (Unlimited)');
    fireEvent.click(screen.getByRole('button', { name: 'Change site default' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Storage allowance'), { target: { value: 'custom' } });
    fireEvent.change(within(dialog).getByLabelText('Limit (GiB)'), { target: { value: '4' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save site default' }));
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('Default storage could not be saved.');
    expect(within(dialog).getByLabelText('Limit (GiB)')).toHaveValue(4);
    expect(within(dialog).getByLabelText('Storage allowance')).toHaveValue('custom');
    expect(screen.getByText('Site default storage: Unlimited')).toBeInTheDocument();
    expect(fetchWrapper.get).toHaveBeenCalledTimes(1);
    expect(globalThis.fetch).not.toHaveBeenCalled();
  });

  it('can explicitly remove the global cap without changing individual accounts', async () => {
    jest.mocked(fetchWrapper.get).mockResolvedValue(directoryResponse({ success: true, data: [user], settings: { default_storage_limit_bytes: GIB } }));
    jest.mocked(fetchWrapper.put).mockResolvedValue({ success: true, data: { default_storage_limit_bytes: null } });
    render(<AdminUsersPage />);
    await screen.findByText('Site default storage: 1 GiB');
    fireEvent.click(screen.getByRole('button', { name: 'Change site default' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Storage allowance'), { target: { value: 'unlimited' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save site default' }));
    await waitFor(() => expect(fetchWrapper.put).toHaveBeenCalledWith('/api/admin/storage-settings', { default_storage_limit_bytes: null }));
    expect(globalThis.fetch).not.toHaveBeenCalled();
  });

  it('saves an account Unlimited override separately from global settings', async () => {
    render(<AdminUsersPage />);
    fireEvent.click(await screen.findByRole('button', { name: 'Set storage limit' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Storage allowance'), { target: { value: 'unlimited' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save storage limit' }));
    await waitFor(() => expect(globalThis.fetch).toHaveBeenCalledWith('/api/admin/users/8', expect.objectContaining({
      method: 'PATCH', body: JSON.stringify({ storage_limit_bytes: null, storage_unlimited: true }),
    })));
    expect(fetchWrapper.put).not.toHaveBeenCalled();
  });

  it('preserves per-account input and shows failed saves inside the dialog', async () => {
    jest.mocked(globalThis.fetch).mockResolvedValue({ ok: false, json: async () => ({ message: 'Account limit update failed.' }) } as Response);
    render(<AdminUsersPage />);
    fireEvent.click(await screen.findByRole('button', { name: 'Set storage limit' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Storage allowance'), { target: { value: 'custom' } });
    fireEvent.change(within(dialog).getByLabelText('Limit (GiB)'), { target: { value: '5' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save storage limit' }));
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('Account limit update failed.');
    expect(within(dialog).getByLabelText('Limit (GiB)')).toHaveValue(5);
    expect(fetchWrapper.put).not.toHaveBeenCalled();
  });

  it('does not allow editing an unknown default after settings fail to load', async () => {
    jest.mocked(fetchWrapper.get).mockRejectedValue('Settings unavailable.');
    render(<AdminUsersPage />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Settings unavailable.');
    expect(screen.getByRole('button', { name: 'Change site default' })).toBeDisabled();
    expect(fetchWrapper.put).not.toHaveBeenCalled();
  });
  it('shows automatic Event Library access without exposing the ineffective premium toggle', async () => {
    mockProfile = 'event';
    jest.mocked(fetchWrapper.get).mockResolvedValue(directoryResponse({ success: true, data: [{ ...user, library_enabled: false }], settings: { default_storage_limit_bytes: null } }));
    render(<AdminUsersPage />);
    expect(within(await details()).getByText('Library available to all attendees')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^(Enable|Disable) Library storage$/ })).toBeNull();
    expect(screen.getByRole('button', { name: 'Set storage limit' })).toBeInTheDocument();
    expect(screen.getByText(/Library access is available to all attendees/)).toBeInTheDocument();
  });

  it('grants and removes Event-only upload review exemptions', async () => {
    mockProfile = 'event';
    let exempt = false;
    jest.mocked(fetchWrapper.get).mockImplementation(async () => directoryResponse({ success: true, data: [{ ...user, event_uploads_auto_approved: exempt }], settings: { default_storage_limit_bytes: null } }));
    jest.mocked(globalThis.fetch).mockImplementation(async (_url, options) => {
      exempt = (JSON.parse(options?.body as string) as { event_uploads_auto_approved: boolean }).event_uploads_auto_approved;
      return { ok: true, json: async () => ({ success: true }) } as Response;
    });
    render(<AdminUsersPage />);
    fireEvent.click(await screen.findByRole('button', { name: 'Skip Event upload review' }));
    await waitFor(() => expect(globalThis.fetch).toHaveBeenCalledWith('/api/admin/users/8', expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ event_uploads_auto_approved: true }) })));
    expect(await within(await details()).findByText('Event uploads auto-approved')).toBeInTheDocument();
    expect(screen.getByText(/Other media still needs review/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Require Event upload review' }));
    await waitFor(() => expect(globalThis.fetch).toHaveBeenLastCalledWith('/api/admin/users/8', expect.objectContaining({ body: JSON.stringify({ event_uploads_auto_approved: false }) })));
    await waitFor(() => expect(screen.queryAllByText('Event uploads auto-approved')).toHaveLength(0));
  });

  it('preserves the current exemption and reports a failed admin update', async () => {
    jest.mocked(globalThis.fetch).mockResolvedValue({ ok: false, json: async () => ({ message: 'Review exemption update failed.' }) } as Response);
    render(<AdminUsersPage />);
    fireEvent.click(await screen.findByRole('button', { name: 'Skip Event upload review' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Review exemption update failed.');
    expect(screen.getByRole('button', { name: 'Skip Event upload review' })).toBeEnabled();
    expect(screen.queryByText('Event uploads auto-approved')).not.toBeInTheDocument();
  });

  it('hides Event approval controls when the Event module is disabled', async () => {
    mockEventsEnabled = false;
    jest.mocked(fetchWrapper.get).mockResolvedValue(directoryResponse({ success: true, data: [{ ...user, event_uploads_auto_approved: true }], settings: { default_storage_limit_bytes: null } }));
    render(<AdminUsersPage />);
    await screen.findByRole('button', { name: 'Set storage limit' });
    expect(screen.queryByRole('button', { name: /Event upload review/ })).not.toBeInTheDocument();
    expect(screen.queryByText('Event uploads auto-approved')).not.toBeInTheDocument();
  });

});

describe('AdminUsersPage list and detail panel', () => {
  const active = { ...user, restrictions: [], library_enabled: false };
  const pending = { ...active, id: 20, name: 'Daybright Pending', display_name: 'Daybright', email: 'daybright@example.test', is_approved: false, approved_at: null };
  const unverified = { ...pending, id: 21, name: 'Quinn Unverified', display_name: 'Quinn', email: 'quinn@example.test', email_verified: false };
  const admin = { ...active, id: 22, name: 'Ada Admin', display_name: 'Ada', email: 'ada@example.test', is_admin: true };
  const banned = { ...active, id: 23, name: 'Rook Banned', display_name: 'Rook', email: 'rook@example.test', is_banned: true, ban_reason: 'Spam', ban_appeal_at: '2026-09-01T00:00:00Z', ban_appeal_message: 'It was not me' };
  const deleted = { ...active, id: 24, name: 'Gone Deleted', display_name: 'Gone', email: 'gone@example.test', is_deleted: true };

  beforeEach(() => {
    mockProfile = 'vora';
    mockEventsEnabled = true;
    jest.clearAllMocks();
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => filteredResponse({ success: true, data: [active, pending, unverified, admin, banned, deleted], settings: { default_storage_limit_bytes: null } }, url));
    jest.mocked(fetchWrapper.post).mockResolvedValue({ success: true });
  });

  const rowFor = (email: string): HTMLElement => screen.getAllByRole('row').find((row) => row.textContent?.includes(email)) as HTMLElement;

  it('gives each row one primary status and hides default-only badges', async () => {
    render(<AdminUsersPage />);
    await screen.findByText('Daybright');
    const row = rowFor('alex@example.test');
    expect(within(row).getByText('Active')).toBeInTheDocument();
    expect(within(row).queryByText(/Storage:/)).not.toBeInTheDocument();
    expect(within(row).queryByText(/invite/)).not.toBeInTheDocument();
    expect(within(rowFor('daybright@example.test')).getByText('Pending')).toBeInTheDocument();
    expect(within(rowFor('ada@example.test')).getByText('Admin')).toBeInTheDocument();
    expect(within(rowFor('rook@example.test')).getByText('Banned')).toBeInTheDocument();
    expect(within(rowFor('rook@example.test')).getByText('Appeal pending')).toBeInTheDocument();
  });

  it('filters by status with counts and searches across names and email', async () => {
    render(<AdminUsersPage />);
    await screen.findByText('Daybright');
    const pendingFilter = screen.getByRole('button', { name: /^Needs approval/ });
    expect(pendingFilter).toHaveTextContent('2');

    fireEvent.click(pendingFilter);
    expect(pendingFilter).toHaveAttribute('aria-pressed', 'true');
    expect(rowFor('daybright@example.test')).toBeDefined();
    expect(rowFor('quinn@example.test')).toBeDefined();
    await waitFor(() => expect(rowFor('ada@example.test')).toBeUndefined());

    fireEvent.click(screen.getByRole('button', { name: /^All/ }));
    await waitFor(() => expect(rowFor('ada@example.test')).toBeDefined());
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search users' }), { target: { value: 'ADA ad' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search', exact: true }));
    await waitFor(() => expect(rowFor('daybright@example.test')).toBeUndefined());
    expect(rowFor('ada@example.test')).toBeDefined();

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search users' }), { target: { value: 'nobody' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search', exact: true }));
    await screen.findByText('No users match.');
    expect(screen.getByText('Select a user to see details and actions.')).toBeInTheDocument();
  });

  it('only offers the Deleted filter when deleted accounts exist', async () => {
    jest.mocked(fetchWrapper.get).mockResolvedValue(directoryResponse({ success: true, data: [active], settings: { default_storage_limit_bytes: null } }));
    render(<AdminUsersPage />);
    await screen.findByRole('button', { name: /^All/ });
    expect(screen.queryByRole('button', { name: /^Deleted/ })).not.toBeInTheDocument();
  });

  it('switches the detail panel to the selected user', async () => {
    render(<AdminUsersPage />);
    const panel = await screen.findByRole('complementary', { name: 'User details' });
    expect(within(panel).getByRole('heading', { name: 'Alex' })).toBeInTheDocument();

    fireEvent.click(within(rowFor('rook@example.test')).getByRole('button', { name: 'Rook' }));
    expect(within(panel).getByRole('heading', { name: 'Rook' })).toBeInTheDocument();
    expect(within(panel).getByText('Spam')).toBeInTheDocument();
    expect(within(panel).getByText('It was not me')).toBeInTheDocument();
    expect(within(panel).getByRole('button', { name: 'Unban' })).toBeInTheDocument();
    expect(within(rowFor('rook@example.test')).getByRole('button', { name: 'Rook' })).toHaveAttribute('aria-pressed', 'true');
  });

  it('approves pending accounts from the row and blocks unverified emails', async () => {
    render(<AdminUsersPage />);
    await screen.findByText('Daybright');
    expect(within(rowFor('quinn@example.test')).getByRole('button', { name: 'Approve' })).toBeDisabled();
    expect(within(rowFor('ada@example.test')).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();

    fireEvent.click(within(rowFor('daybright@example.test')).getByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(fetchWrapper.post).toHaveBeenCalledWith('/api/admin/users/20/approve', {}));
  });

  it('groups actions and limits deleted accounts to restore and purge', async () => {
    render(<AdminUsersPage />);
    const panel = await screen.findByRole('complementary', { name: 'User details' });
    for (const group of ['Vora application access', 'Vora profile & age', 'Storage', 'Event uploads', 'Invites', 'Danger zone']) {
      expect(within(panel).getByRole('heading', { name: group })).toBeInTheDocument();
    }

    fireEvent.click(screen.getByRole('button', { name: /^Deleted/ }));
    await within(panel).findByRole('heading', { name: 'Gone' });
    expect(within(panel).getByRole('button', { name: 'Restore' })).toBeInTheDocument();
    expect(within(panel).getByRole('button', { name: 'Purge…' })).toBeInTheDocument();
    expect(within(panel).queryByRole('heading', { name: 'Account' })).not.toBeInTheDocument();
    expect(within(panel).queryByRole('button', { name: 'Ban…' })).not.toBeInTheDocument();

    fireEvent.click(within(panel).getByRole('button', { name: 'Restore' }));
    await waitFor(() => expect(fetchWrapper.post).toHaveBeenCalledWith('/api/admin/users/24/restore', {}));
  });

  it('drops Event-only groups when the Event module is off', async () => {
    mockEventsEnabled = false;
    render(<AdminUsersPage />);
    const panel = await screen.findByRole('complementary', { name: 'User details' });
    expect(within(panel).queryByRole('heading', { name: 'Event uploads' })).not.toBeInTheDocument();
    expect(within(panel).getByRole('button', { name: 'Enable Library storage' })).toBeInTheDocument();
  });

  it('opens the account named by a #user link and keeps the link in sync', async () => {
    window.history.replaceState({}, '', '/admin/users#user-23');
    render(<AdminUsersPage />);

    const panel = await screen.findByRole('complementary', { name: 'User details' });
    expect(within(panel).getByRole('heading', { name: 'Rook' })).toBeInTheDocument();

    fireEvent.click(within(rowFor('ada@example.test')).getByRole('button', { name: 'Ada' }));
    expect(window.location.hash).toBe('#user-22');

    window.history.replaceState({}, '', '/admin/users#user-20');
    fireEvent(window, new HashChangeEvent('hashchange'));
    await waitFor(() => expect(within(panel).getByRole('heading', { name: 'Daybright' })).toBeInTheDocument());
    window.history.replaceState({}, '', '/admin/users');
  });
});

describe('AdminUsersPage sign-in ownership', () => {
  const originalFetch = globalThis.fetch;

  beforeEach(() => {
    mockProfile = 'vora';
    mockEventsEnabled = true;
    jest.clearAllMocks();
    window.history.replaceState(null, '', '/admin/users');
    globalThis.fetch = jest.fn().mockResolvedValue({ ok: true, json: async () => ({ success: true, data: user }) });
  });

  afterEach(() => { globalThis.fetch = originalFetch; });

  it('keeps local identity and email locking visibly separate from application role and access', async () => {
    jest.mocked(fetchWrapper.get).mockResolvedValue(directoryResponse({ success: true, data: [user], settings: { default_storage_limit_bytes: null } }));
    render(<AdminUsersPage />);
    const panel = await details();
    expect(within(panel).getByText('Local sign-in')).toBeInTheDocument();
    expect(within(panel).getByText('Local account — no provider binding')).toBeInTheDocument();
    expect(within(panel).getByRole('button', { name: 'Lock local email' })).toBeInTheDocument();
    expect(within(panel).queryByRole('link', { name: 'Provider self-service' })).not.toBeInTheDocument();
    expect(within(panel).getByRole('link', { name: 'View Vora audit history' })).toHaveAttribute('href', '/admin/audit-log?user_id=8');
    fireEvent.click(within(panel).getByRole('button', { name: 'Disable Vora account' }));
    await waitFor(() => expect(globalThis.fetch).toHaveBeenCalledWith('/api/admin/users/8', expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ is_disabled: true }) })));
  });

  it.each([
    ['provider', 'External provider sign-in', 'https://identity.example.test/settings'],
    ['embedded', 'Embedded provider sign-in', 'https://app.example.test/auth/settings'],
  ] as const)('explains %s ownership and only hands off self-service for the current browser account', async (mode, label, url) => {
    jest.mocked(fetchWrapper.get).mockResolvedValue(directoryResponse({ success: true, data: [{ ...user, email_locked: true, identity: {
      sign_in_mode: mode, configured_provider: 'example', binding: 'configured_provider', provider: 'example',
      subject: 'Opaque/<Subject>', provider_account_url: url, local_email_lock_available: false,
    } }], settings: { default_storage_limit_bytes: null } }));
    render(<AdminUsersPage />);
    const panel = await details();
    expect(within(panel).getByText(label, { exact: false })).toBeInTheDocument();
    expect(within(panel).getByText('Opaque/<Subject>')).toBeInTheDocument();
    expect(within(panel).getByRole('link', { name: 'Provider self-service' })).toHaveAttribute('href', url);
    expect(within(panel).getByText(/uses this browser’s provider session/)).toBeInTheDocument();
    expect(within(panel).queryByRole('button', { name: /local email/ })).not.toBeInTheDocument();
    expect(screen.queryByText('Email locked')).not.toBeInTheDocument();
    expect(within(panel).getByRole('button', { name: 'Make Vora admin' })).toBeInTheDocument();
    expect(within(panel).getByRole('button', { name: 'Edit birth date' })).toBeInTheDocument();
    expect(within(panel).getByText('Vora profile & age')).toBeInTheDocument();
    expect(within(panel).getByText(/Provider credentials and provider administrator roles are managed separately/)).toBeInTheDocument();
  });

  it.each([
    ['other_provider', 'Bound to a different provider'],
    ['incomplete', 'Incomplete provider binding'],
    ['configured_provider', 'Bound to the configured provider'],
  ] as const)('provides support context without a provider link for %s or unavailable configuration', async (binding, label) => {
    jest.mocked(fetchWrapper.get).mockResolvedValue(directoryResponse({ success: true, data: [{ ...user, identity: {
      sign_in_mode: 'provider', configured_provider: 'example', binding, provider: binding === 'incomplete' ? null : binding === 'configured_provider' ? 'example' : 'other',
      subject: 'subject', provider_account_url: null, local_email_lock_available: false,
    } }], settings: { default_storage_limit_bytes: null } }));
    render(<AdminUsersPage />);
    const panel = await details();
    expect(within(panel).getByText(label)).toBeInTheDocument();
    expect(within(panel).queryByRole('link', { name: 'Provider self-service' })).not.toBeInTheDocument();
    expect(within(panel).queryByRole('button', { name: /local email/ })).not.toBeInTheDocument();
  });
});

describe('AdminUsersPage server directory', () => {
  const newer = { ...user, id: 100, name: 'Newer Example', display_name: 'Newer', email: 'newer@example.test', restrictions: [] };
  const pending = { ...user, is_approved: false, approved_at: null, restrictions: [] };
  const originalFetch = globalThis.fetch;

  beforeEach(() => {
    jest.resetAllMocks();
    mockProfile = 'vora';
    mockEventsEnabled = true;
  });

  afterEach(() => { globalThis.fetch = originalFetch; });

  it('loads another page and submits search to the server while retaining global counts', async () => {
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => {
      const params = new URL(url, 'https://app.example.test').searchParams;
      return directoryResponse({ success: true, settings: { default_storage_limit_bytes: null },
        data: params.get('search') === 'outside' ? [] : params.get('page') === '2' ? [user] : [newer],
        counts: { all: 31, pending: 4, restricted: 1, banned: 0, admins: 1, deleted: 0 },
        meta: { current_page: Number(params.get('page')), last_page: params.has('search') ? 1 : 2, per_page: 30, total: params.has('search') ? 0 : 31, has_more: params.get('page') === '1' && !params.has('search') },
      });
    });
    render(<AdminUsersPage />);
    await screen.findByText('Page 1 of 2');
    fireEvent.click(screen.getByRole('button', { name: 'Next', exact: true }));
    await screen.findByText('Page 2 of 2');
    expect(fetchWrapper.get).toHaveBeenLastCalledWith('/api/admin/users?page=2&status=all');
    expect(screen.getByRole('button', { name: 'Previous', exact: true })).toBeEnabled();
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'outside' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search', exact: true }));
    await screen.findByText('No users match.');
    expect(fetchWrapper.get).toHaveBeenLastCalledWith('/api/admin/users?page=1&status=all&search=outside');
    expect(screen.getByRole('button', { name: /^Needs approval/ })).toHaveTextContent('4');
    expect(screen.queryByRole('navigation', { name: 'User pages' })).not.toBeInTheDocument();
  });

  it('opens and refreshes a linked off-page account after approving it', async () => {
    let approved = false;
    window.history.replaceState({}, '', '/admin/users#user-8');
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => url === '/api/admin/users/8'
      ? { success: true, data: { ...pending, is_approved: approved } }
      : directoryResponse({ success: true, data: [newer], settings: { default_storage_limit_bytes: null } }));
    jest.mocked(fetchWrapper.post).mockImplementation(async () => { approved = true; return { success: true }; });
    render(<AdminUsersPage />);
    const panel = await screen.findByRole('complementary', { name: 'User details' });
    await within(panel).findByRole('heading', { name: 'Alex' });
    fireEvent.click(within(panel).getByRole('button', { name: 'Approve', exact: true }));
    await waitFor(() => expect(within(panel).queryByRole('button', { name: 'Approve', exact: true })).not.toBeInTheDocument());
    expect(within(panel).getByRole('heading', { name: 'Alex' })).toBeInTheDocument();
    expect(window.location.hash).toBe('#user-8');
    expect(jest.mocked(fetchWrapper.get).mock.calls.filter(([url]) => url === '/api/admin/users/8')).toHaveLength(2);
  });

  it('clamps an emptied last page after an action without losing the acted-on account', async () => {
    let approved = false;
    const remaining = { ...newer, is_approved: false };
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => {
      if (url === '/api/admin/users/8') return { success: true, data: { ...pending, is_approved: approved } };
      const params = new URL(url, 'https://app.example.test').searchParams;
      const page = Number(params.get('page'));
      return directoryResponse({ success: true, settings: { default_storage_limit_bytes: null },
        data: page === 2 ? approved ? [] : [pending] : [remaining],
        meta: { current_page: page, last_page: approved ? 1 : 2, per_page: 30, total: approved ? 1 : 31, has_more: !approved && page === 1 },
      });
    });
    jest.mocked(fetchWrapper.post).mockImplementation(async () => { approved = true; return { success: true }; });
    render(<AdminUsersPage />);
    await screen.findByText('Page 1 of 2');
    fireEvent.click(screen.getByRole('button', { name: /^Needs approval/ }));
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenLastCalledWith('/api/admin/users?page=1&status=pending'));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Next', exact: true })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Next', exact: true }));
    await screen.findByText('Page 2 of 2');
    const panel = screen.getByRole('complementary', { name: 'User details' });
    fireEvent.click(within(panel).getByRole('button', { name: 'Approve', exact: true }));
    await waitFor(() => expect(screen.queryByRole('navigation', { name: 'User pages' })).not.toBeInTheDocument());
    await within(panel).findByRole('heading', { name: 'Alex' });
    expect(screen.getByRole('table')).toHaveTextContent('newer@example.test');
    expect(within(panel).queryByRole('button', { name: 'Approve', exact: true })).not.toBeInTheDocument();
    expect(fetchWrapper.get).toHaveBeenCalledWith('/api/admin/users?page=1&status=pending');
  });

  it('refreshes the current filter when an earlier action finishes after the filter changes', async () => {
    let finish: () => void = () => undefined;
    jest.mocked(fetchWrapper.post).mockImplementation(() => new Promise((resolve) => { finish = () => resolve({ success: true }); }));
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => directoryResponse({ success: true,
      data: url.includes('status=banned') ? [{ ...newer, is_banned: true }] : [pending], settings: { default_storage_limit_bytes: null },
    }));
    render(<AdminUsersPage />);
    const panel = await screen.findByRole('complementary', { name: 'User details' });
    fireEvent.click(within(panel).getByRole('button', { name: 'Approve', exact: true }));
    fireEvent.click(screen.getByRole('button', { name: /^Banned/ }));
    await screen.findByRole('heading', { name: 'Newer' });
    await act(async () => { finish(); });
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenCalledTimes(3));
    expect(fetchWrapper.get).toHaveBeenLastCalledWith('/api/admin/users?page=1&status=banned');
    expect(screen.getByRole('table')).toHaveTextContent('newer@example.test');
    expect(screen.getByRole('button', { name: /^Banned/ })).toHaveAttribute('aria-pressed', 'true');
  });

  it('retries directory and selected-account failures separately', async () => {
    let directoryAttempts = 0;
    let accountAttempts = 0;
    window.history.replaceState({}, '', '/admin/users#user-8');
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => {
      if (url === '/api/admin/users/8') {
        if (++accountAttempts === 1) throw new Error('Selected account unavailable.');
        return { success: true, data: user };
      }
      if (++directoryAttempts === 1) throw new Error('Directory unavailable.');
      return directoryResponse({ success: true, data: [newer], settings: { default_storage_limit_bytes: null } });
    });
    render(<AdminUsersPage />);
    await screen.findByText('Directory unavailable.');
    fireEvent.click(screen.getByRole('button', { name: 'Retry', exact: true }));
    await screen.findByRole('heading', { name: 'Alex' });
    // Loading the refreshed list also retried the previously failed selection.
    expect(directoryAttempts).toBe(2);
    expect(accountAttempts).toBe(2);
  });

  it('ignores an earlier search response that arrives after the newer search', async () => {
    let resolveFirst: (value: unknown) => void = () => undefined;
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => {
      if (url.includes('search=first')) return new Promise((resolve) => { resolveFirst = resolve; });
      return directoryResponse({ success: true, data: url.includes('search=second') ? [newer] : [user], settings: { default_storage_limit_bytes: null } });
    });
    render(<AdminUsersPage />);
    await screen.findByRole('heading', { name: 'Alex' });
    const search = screen.getByRole('searchbox');
    fireEvent.change(search, { target: { value: 'first' } });
    fireEvent.submit(search.closest('form')!);
    fireEvent.change(search, { target: { value: 'second' } });
    fireEvent.submit(search.closest('form')!);
    await screen.findByRole('heading', { name: 'Newer' });
    await act(async () => { resolveFirst(directoryResponse({ success: true, data: [user], settings: { default_storage_limit_bytes: null } })); });
    expect(screen.getByRole('table')).toHaveTextContent('newer@example.test');
    expect(screen.getByRole('table')).not.toHaveTextContent('alex@example.test');
  });

  it('retries an off-page lookup without replacing the directory or losing its link', async () => {
    let attempts = 0;
    window.history.replaceState({}, '', '/admin/users#user-8');
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => {
      if (url === '/api/admin/users/8') {
        if (++attempts === 1) throw new Error('Account lookup failed.');
        return { success: true, data: user };
      }
      return directoryResponse({ success: true, data: [newer], settings: { default_storage_limit_bytes: null } });
    });
    render(<AdminUsersPage />);
    await screen.findByText('Account lookup failed.');
    fireEvent.click(screen.getByRole('button', { name: 'Retry account', exact: true }));
    await screen.findByRole('heading', { name: 'Alex' });
    expect(screen.getByRole('table')).toHaveTextContent('newer@example.test');
    expect(window.location.hash).toBe('#user-8');
    expect(fetchWrapper.get).toHaveBeenCalledTimes(3);
  });

  it('ignores an old account lookup after a newer hash link is selected', async () => {
    let resolveOld: (value: unknown) => void = () => undefined;
    window.history.replaceState({}, '', '/admin/users#user-8');
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => {
      if (url === '/api/admin/users/8') return new Promise((resolve) => { resolveOld = resolve; });
      if (url === '/api/admin/users/9') return { success: true, data: { ...user, id: 9, display_name: 'Second link' } };
      return directoryResponse({ success: true, data: [newer], settings: { default_storage_limit_bytes: null } });
    });
    render(<AdminUsersPage />);
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenCalledWith('/api/admin/users/8'));
    window.history.replaceState({}, '', '/admin/users#user-9');
    fireEvent(window, new HashChangeEvent('hashchange'));
    await screen.findByRole('heading', { name: 'Second link' });
    await act(async () => { resolveOld({ success: true, data: user }); });
    expect(screen.getByRole('heading', { name: 'Second link' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Alex' })).not.toBeInTheDocument();
  });
});
