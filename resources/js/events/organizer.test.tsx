import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { EventOrganizerPage } from './organizer';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), post: jest.fn(), patch: jest.fn(), put: jest.fn() } }));

const ready = {
  id: 7, display_name: 'Ready guest', email: 'ready@example.test', is_admin: false,
  approved: true, blocked_reason: null, can_approve: false, can_manage_access: true,
  event_ulids: ['shower'], event_uploads_auto_approved: false,
};
const waiting = {
  ...ready, id: 8, display_name: 'Waiting guest', email: 'waiting@example.test', approved: false,
  can_approve: true, can_manage_access: false, event_ulids: [],
};
const events = [
  { ulid: 'shower', name: 'Shower', visibility: 'specific' },
  { ulid: 'rehearsal', name: 'Rehearsal', visibility: 'specific' },
  { ulid: 'reception', name: 'Reception', visibility: 'everyone' },
];
const meta = { current_page: 1, last_page: 1, total: 2, per_page: 30, has_more: false };

describe('Event organizer', () => {
  beforeEach(() => {
    jest.resetAllMocks();
    jest.mocked(fetchWrapper.get).mockResolvedValue({ data: [ready, waiting], events, meta });
    jest.mocked(fetchWrapper.post).mockResolvedValue({ success: true });
    jest.mocked(fetchWrapper.put).mockResolvedValue({ success: true });
    jest.mocked(fetchWrapper.patch).mockResolvedValue({ success: true });
  });

  it('searches and filters attendee accounts without loading the general admin user list', async () => {
    render(<EventOrganizerPage />);
    await screen.findByText('ready@example.test');
    fireEvent.change(screen.getByLabelText('Search attendees'), { target: { value: 'guest@example.test' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenLastCalledWith('/api/admin/events/attendees?page=1&status=all&search=guest%40example.test'));
    fireEvent.change(screen.getByLabelText('Account status'), { target: { value: 'pending' } });
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenLastCalledWith('/api/admin/events/attendees?page=1&status=pending&search=guest%40example.test'));
  });

  it('approves an account separately from Event membership', async () => {
    render(<EventOrganizerPage />);
    fireEvent.click(await screen.findByRole('button', { name: 'Approve Waiting guest' }));
    await waitFor(() => expect(fetchWrapper.post).toHaveBeenCalledWith('/api/admin/users/8/approve', {}));
    expect(fetchWrapper.put).not.toHaveBeenCalled();
    expect(screen.getByText(/Signup invitations create accounts/)).toBeInTheDocument();
  });

  it('disables approval for unverified accounts and explains the next step', async () => {
    jest.mocked(fetchWrapper.get).mockResolvedValue({ data: [{ ...waiting, blocked_reason: 'email_verification', can_approve: false }], events, meta });
    render(<EventOrganizerPage />);
    expect(await screen.findByRole('button', { name: 'Approve Waiting guest' })).toBeDisabled();
    expect(screen.getByText(/Waiting for email verification/)).toBeInTheDocument();
  });

  it('updates selected Event access only, without affecting all-attendee Events or other account flags', async () => {
    render(<EventOrganizerPage />);
    fireEvent.click(await screen.findByRole('button', { name: 'Edit Event access for Ready guest' }));
    expect(screen.getByText(/All approved attendees can access: Reception/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('checkbox', { name: 'Shower' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Rehearsal' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save Event access' }));
    await waitFor(() => expect(fetchWrapper.put).toHaveBeenCalledWith('/api/admin/events/attendees/7/access', { event_ulids: ['rehearsal'] }));
    expect(fetchWrapper.patch).not.toHaveBeenCalled();
  });

  it('changes the per-attendee Event upload review exemption through the existing admin API', async () => {
    render(<EventOrganizerPage />);
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Skip upload review for Ready guest' }));
    await waitFor(() => expect(fetchWrapper.patch).toHaveBeenCalledWith('/api/admin/users/7', { event_uploads_auto_approved: true }));
    expect(fetchWrapper.put).not.toHaveBeenCalled();
  });

  it('retains Event selections and reports a failed membership save', async () => {
    jest.mocked(fetchWrapper.put).mockRejectedValueOnce(new Error('Access changed. Refresh first.'));
    render(<EventOrganizerPage />);
    fireEvent.click(await screen.findByRole('button', { name: 'Edit Event access for Ready guest' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Rehearsal' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save Event access' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Access changed. Refresh first.');
    expect(screen.getByRole('checkbox', { name: 'Rehearsal' })).toBeChecked();
  });

  it('returns to the last available page when approval empties a trailing page', async () => {
    let approved = false;
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => {
      const secondPage = url.includes('page=2');
      return { data: secondPage ? approved ? [] : [waiting] : [ready], events,
        meta: { ...meta, current_page: secondPage ? 2 : 1, last_page: approved ? 1 : 2, has_more: !secondPage && !approved } };
    });
    jest.mocked(fetchWrapper.post).mockImplementation(async () => { approved = true; return { success: true }; });
    render(<EventOrganizerPage />);
    fireEvent.click(await screen.findByRole('button', { name: 'Next' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Approve Waiting guest' }));
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenLastCalledWith('/api/admin/events/attendees?page=1&status=all'));
    expect(await screen.findByText('ready@example.test')).toBeInTheDocument();
  });

  it('keeps query controls stable while an attendee mutation is in flight', async () => {
    let finish: (() => void) | undefined;
    jest.mocked(fetchWrapper.post).mockImplementation(() => new Promise((resolve) => { finish = () => resolve({ success: true }); }));
    render(<EventOrganizerPage />);
    fireEvent.click(await screen.findByRole('button', { name: 'Approve Waiting guest' }));
    expect(screen.getByLabelText('Search attendees')).toBeDisabled();
    expect(screen.getByLabelText('Account status')).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Search' })).toBeDisabled();
    await act(async () => { finish?.(); });
    await waitFor(() => expect(screen.getByLabelText('Account status')).toBeEnabled());
  });
});
