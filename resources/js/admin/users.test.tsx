import '@testing-library/jest-dom';

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { AdminUsersPage } from '@/admin/users';
import { fetchWrapper } from '@/fetchWrapper';

let mockProfile: 'vora' | 'event' = 'vora';
jest.mock('@/instance', () => ({ instanceProfile: () => mockProfile, moduleEnabled: () => true }));

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
    jest.clearAllMocks();
    jest.mocked(fetchWrapper.get).mockResolvedValue({ success: true, data: [user], settings: { default_storage_limit_bytes: null } });
    jest.mocked(fetchWrapper.delete).mockResolvedValue({ success: true });
  });

  it('shows active reasons and lifts the selected historical record', async () => {
    render(<AdminUsersPage />);

    expect(await screen.findByText('Restricted: Media viewing')).toBeInTheDocument();
    expect(screen.getByText(/Safety review/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Lift Media viewing' }));

    await waitFor(() => expect(fetchWrapper.delete).toHaveBeenCalledWith('/api/admin/users/8/restrictions/33', {}));
  });
});


const GIB = 1024 ** 3;

describe('AdminUsersPage storage defaults', () => {
  const originalFetch = globalThis.fetch;

  beforeEach(() => {
    mockProfile = 'vora';
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
    expect(await screen.findByText('Storage: Site default (2 GiB)')).toBeInTheDocument();
    expect(screen.getByText('Storage: Custom (0 B)')).toBeInTheDocument();
    expect(screen.getByText('Storage: Unlimited override')).toBeInTheDocument();
    expect(screen.getByText('Site default: 2 GiB')).toBeInTheDocument();
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
    await screen.findByText('Storage: Site default (Unlimited)');
    fireEvent.click(screen.getByRole('button', { name: 'Change site default' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Storage allowance'), { target: { value: 'custom' } });
    fireEvent.change(within(dialog).getByLabelText('Limit (GiB)'), { target: { value: '2' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save site default' }));
    await waitFor(() => expect(fetchWrapper.put).toHaveBeenCalledWith('/api/admin/storage-settings', { default_storage_limit_bytes: 2 * GIB }));
    expect(await screen.findByText('Storage: Site default (2 GiB)')).toBeInTheDocument();
    expect(screen.getByText('Storage: Custom (1 GiB)')).toBeInTheDocument();
    expect(screen.getByText('Storage: Unlimited override')).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(fetchWrapper.get).toHaveBeenCalledTimes(2);
    expect(globalThis.fetch).not.toHaveBeenCalled();
  });

  it('keeps failed global edits visible and preserves the chosen amount', async () => {
    jest.mocked(fetchWrapper.put).mockRejectedValue('Default storage could not be saved.');
    render(<AdminUsersPage />);
    await screen.findByText('Storage: Site default (Unlimited)');
    fireEvent.click(screen.getByRole('button', { name: 'Change site default' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Storage allowance'), { target: { value: 'custom' } });
    fireEvent.change(within(dialog).getByLabelText('Limit (GiB)'), { target: { value: '4' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save site default' }));
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('Default storage could not be saved.');
    expect(within(dialog).getByLabelText('Limit (GiB)')).toHaveValue(4);
    expect(within(dialog).getByLabelText('Storage allowance')).toHaveValue('custom');
    expect(screen.getByText('Site default: Unlimited')).toBeInTheDocument();
    expect(fetchWrapper.get).toHaveBeenCalledTimes(1);
    expect(globalThis.fetch).not.toHaveBeenCalled();
  });

  it('can explicitly remove the global cap without changing individual accounts', async () => {
    jest.mocked(fetchWrapper.get).mockResolvedValue({ success: true, data: [user], settings: { default_storage_limit_bytes: GIB } });
    jest.mocked(fetchWrapper.put).mockResolvedValue({ success: true, data: { default_storage_limit_bytes: null } });
    render(<AdminUsersPage />);
    await screen.findByText('Site default: 1 GiB');
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
    expect(await screen.findByText('Library available')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^(Enable|Disable) Library storage$/ })).toBeNull();
    expect(screen.getByRole('button', { name: 'Set storage limit' })).toBeInTheDocument();
    expect(screen.getByText(/Library access is available to all attendees/)).toBeInTheDocument();
  });

});
