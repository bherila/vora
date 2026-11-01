import '@testing-library/jest-dom';

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

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

describe('AdminUsersPage restrictions', () => {
  beforeEach(() => {
    mockProfile = 'vora';
    mockEventsEnabled = true;
    jest.clearAllMocks();
    jest.mocked(fetchWrapper.get).mockResolvedValue({ success: true, data: [user], settings: { default_storage_limit_bytes: null } });
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
    jest.mocked(fetchWrapper.get).mockResolvedValue({ success: true, data: [user], settings: { default_storage_limit_bytes: null } });
    globalThis.fetch = jest.fn().mockResolvedValue({ ok: true, json: async () => ({ success: true, data: user }) });
  });

  afterEach(() => { globalThis.fetch = originalFetch; });

  it('distinguishes inherited, custom zero and explicit unlimited allowances', async () => {
    jest.mocked(fetchWrapper.get).mockResolvedValue({
      success: true, settings: { default_storage_limit_bytes: 2 * GIB },
      data: [
        { ...user, effective_storage_limit_bytes: 2 * GIB },
        { ...user, id: 9, email: 'custom@example.test', storage_limit_bytes: 0, effective_storage_limit_bytes: 0 },
        { ...user, id: 10, email: 'unlimited@example.test', storage_unlimited: true },
      ],
    });
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
    jest.mocked(fetchWrapper.get).mockImplementation(async () => ({
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
    jest.mocked(fetchWrapper.get).mockResolvedValue({ success: true, data: [user], settings: { default_storage_limit_bytes: GIB } });
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
    jest.mocked(fetchWrapper.get).mockResolvedValue({ success: true, data: [{ ...user, library_enabled: false }], settings: { default_storage_limit_bytes: null } });
    render(<AdminUsersPage />);
    expect(within(await details()).getByText('Library available to all attendees')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^(Enable|Disable) Library storage$/ })).toBeNull();
    expect(screen.getByRole('button', { name: 'Set storage limit' })).toBeInTheDocument();
    expect(screen.getByText(/Library access is available to all attendees/)).toBeInTheDocument();
  });

  it('grants and removes Event-only upload review exemptions', async () => {
    mockProfile = 'event';
    let exempt = false;
    jest.mocked(fetchWrapper.get).mockImplementation(async () => ({ success: true, data: [{ ...user, event_uploads_auto_approved: exempt }], settings: { default_storage_limit_bytes: null } }));
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
    jest.mocked(fetchWrapper.get).mockResolvedValue({ success: true, data: [{ ...user, event_uploads_auto_approved: true }], settings: { default_storage_limit_bytes: null } });
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
    jest.mocked(fetchWrapper.get).mockResolvedValue({ success: true, data: [active, pending, unverified, admin, banned, deleted], settings: { default_storage_limit_bytes: null } });
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
    expect(rowFor('ada@example.test')).toBeUndefined();

    fireEvent.click(screen.getByRole('button', { name: /^All/ }));
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search users' }), { target: { value: 'ADA ad' } });
    expect(rowFor('ada@example.test')).toBeDefined();
    expect(rowFor('daybright@example.test')).toBeUndefined();

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search users' }), { target: { value: 'nobody' } });
    expect(screen.getByText('No users match.')).toBeInTheDocument();
    expect(screen.getByText('Select a user to see details and actions.')).toBeInTheDocument();
  });

  it('only offers the Deleted filter when deleted accounts exist', async () => {
    jest.mocked(fetchWrapper.get).mockResolvedValue({ success: true, data: [active], settings: { default_storage_limit_bytes: null } });
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
    for (const group of ['Account', 'Identity & age', 'Storage', 'Event uploads', 'Invites', 'Danger zone']) {
      expect(within(panel).getByRole('heading', { name: group })).toBeInTheDocument();
    }

    fireEvent.click(screen.getByRole('button', { name: /^Deleted/ }));
    expect(within(panel).getByRole('heading', { name: 'Gone' })).toBeInTheDocument();
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
