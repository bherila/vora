import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { type InvitationRow, type InvitationsData, ProviderInvitationsPage } from './provider-invitations';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), post: jest.fn() } }));

const sent: InvitationRow = { id: 3, email: 'guest@example.test', status: 'pending', email_sent: true, expires_at: '2026-11-03T12:00:00Z', created_at: '2026-10-27T12:00:00Z', invited_by: 'Avery' };
const admin: InvitationsData = { is_admin: true, balance: null, invitations: [] };

describe('ProviderInvitationsPage', () => {
  beforeEach(() => {
    jest.mocked(fetchWrapper.get).mockResolvedValue({ success: true, data: { ...admin, invitations: [sent] } });
  });

  it('sends an invitation, shows its link once, and lists it', async () => {
    jest.mocked(fetchWrapper.post).mockResolvedValue({ success: true, data: { invitation: sent, link: 'https://id.example.test/invitations/secret' } });
    render(<ProviderInvitationsPage initial={admin} />);
    expect(screen.getByText('Administrators can invite anyone.')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Email address'), { target: { value: 'guest@example.test' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send invitation' }));

    expect(await screen.findByLabelText('Invitation link')).toHaveValue('https://id.example.test/invitations/secret');
    expect(fetchWrapper.post).toHaveBeenCalledWith('/api/invitations', { email: 'guest@example.test' });
    expect(screen.getByText(/Invitation emailed to guest@example.test/)).toBeInTheDocument();
    expect(await screen.findByRole('cell', { name: 'guest@example.test' })).toBeInTheDocument();
    expect(screen.getByRole('cell', { name: 'Avery' })).toBeInTheDocument();
    expect(screen.getByLabelText('Email address')).toHaveValue('');
  });

  it('disables sending for someone without invites', async () => {
    render(<ProviderInvitationsPage initial={{ is_admin: false, balance: 0, invitations: [] }} />);
    expect(screen.getByText(/You have no invites available right now/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Send invitation' })).toBeDisabled();
  });

  it('keeps the provider message when a revoke is refused', async () => {
    jest.mocked(fetchWrapper.post).mockRejectedValue('This invitation was already accepted.');
    render(<ProviderInvitationsPage initial={{ ...admin, invitations: [sent] }} />);

    fireEvent.click(screen.getByRole('button', { name: 'Revoke' }));
    expect(await screen.findByText('This invitation was already accepted.')).toBeInTheDocument();
    expect(fetchWrapper.post).toHaveBeenCalledWith('/api/invitations/3/revoke', {});
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenCalledWith('/api/invitations'));
  });
});
