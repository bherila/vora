import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { AdminInvitesPage } from '@/admin/invites';
import { fetchWrapper } from '@/fetchWrapper';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), put: jest.fn(), post: jest.fn() } }));

const get = jest.mocked(fetchWrapper.get);
const put = jest.mocked(fetchWrapper.put);

function response(minimum_age: number | null = 18, age_verification_required = false) {
  return { success: true, data: { settings: { public_signups_enabled: true, default_new_user_invites: 0, default_new_user_invite_expiry_days: null, minimum_age, age_verification_required }, users: [], recent_invites: [] } };
}

beforeEach(() => {
  // Base UI uses PointerEvent when forwarding a checkbox label click; jsdom
  // supplies MouseEvent but does not implement PointerEvent.
  Object.defineProperty(window, 'PointerEvent', { configurable: true, value: MouseEvent });
  jest.clearAllMocks();
  get.mockResolvedValue(response());
  put.mockResolvedValue({ success: true });
});

it('loads policy and saves a younger minimum with separate manual verification', async () => {
  render(<AdminInvitesPage />);
  const minimum = await screen.findByLabelText('Minimum account age (years)');
  await waitFor(() => expect(screen.getByRole('button', { name: 'Save defaults' })).toBeEnabled());
  expect(minimum).toHaveValue(18);
  expect(screen.getByRole('checkbox', { name: 'Require administrator age verification' })).not.toBeChecked();
  fireEvent.change(minimum, { target: { value: '13' } });
  fireEvent.click(screen.getByRole('checkbox', { name: 'Require administrator age verification' }));
  get.mockResolvedValue(response(13, true));
  fireEvent.click(screen.getByRole('button', { name: 'Save defaults' }));
  await waitFor(() => expect(put).toHaveBeenCalledWith('/api/admin/invites/settings', expect.objectContaining({ minimum_age: 13, age_verification_required: true })));
  expect(await screen.findByText('Settings saved.')).toBeInTheDocument();
});

it('sends null for a disabled minimum without implicitly disabling verification', async () => {
  get.mockResolvedValue(response(13, true));
  render(<AdminInvitesPage />);
  await waitFor(() => expect(screen.getByLabelText('Minimum account age (years)')).toHaveValue(13));
  fireEvent.change(screen.getByLabelText('Minimum account age (years)'), { target: { value: '' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save defaults' }));
  await waitFor(() => expect(put).toHaveBeenCalledWith('/api/admin/invites/settings', expect.objectContaining({ minimum_age: null, age_verification_required: true })));
});

it('does not overwrite an unknown policy after settings fail to load', async () => {
  get.mockRejectedValue('Unavailable');
  render(<AdminInvitesPage />);
  expect(await screen.findByText('Unavailable')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Save defaults' })).toBeDisabled();
  expect(put).not.toHaveBeenCalled();
});
