import '@testing-library/jest-dom';

import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { AdminUsersPage } from '@/admin/users';
import { fetchWrapper } from '@/fetchWrapper';

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: jest.fn(),
    post: jest.fn(),
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
    jest.mocked(fetchWrapper.get).mockResolvedValue({ success: true, data: [user] });
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
