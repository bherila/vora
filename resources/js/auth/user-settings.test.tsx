import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { UserSettingsPage } from './user-settings';

jest.mock('bwh-auth', () => ({
  ChangePasswordForm: () => <div>Change password form</div>,
  PasskeySection: () => <div>Passkey settings</div>,
}));

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    delete: jest.fn(),
    get: jest.fn(),
    patch: jest.fn(),
    post: jest.fn(),
  },
}));

jest.mock('@/initialData', () => ({
  readInitialData: () => ({
    userSettings: {
      name: 'Account Name',
      display_name: 'Public Name',
      birth_date: '1990-01-01',
      email: 'account@example.com',
      notify_new_post: true,
      notify_post_reaction: true,
      notify_post_comment: true,
      notify_follow_request: true,
      notify_follow_accepted: true,
      notify_co_author_invite: true,
      notify_co_author_invite_accepted: true,
      notify_favorite: true,
      web_push_public_key: '',
      web_push_subscription_count: 0,
    },
  }),
}));

jest.mock('@/push', () => ({
  currentBrowserPushSubscription: jest.fn(),
  isWebPushSupported: () => false,
  subscribeBrowserToWebPush: jest.fn(),
  unsubscribeBrowserFromWebPush: jest.fn(),
}));

const patch = jest.mocked(fetchWrapper.patch);

function notificationResponse() {
  return {
    success: true,
    data: {
      name: 'Account Name',
      display_name: 'Public Name',
      email: 'account@example.com',
      notify_new_post: true,
      notify_post_reaction: true,
      notify_post_comment: false,
      notify_follow_request: true,
      notify_follow_accepted: true,
      notify_co_author_invite: true,
      notify_co_author_invite_accepted: true,
      notify_favorite: true,
    },
  };
}

describe('UserSettingsPage', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/user/settings');
    patch.mockReset();
    patch.mockResolvedValue(notificationResponse());
  });

  it('opens a URL-addressed tab and keeps the profile card above it', () => {
    window.history.replaceState({}, '', '/user/settings?tab=notifications');

    render(<UserSettingsPage />);

    expect(screen.getByRole('tab', { name: 'Notifications' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByText('Your public profile')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Edit profile' })).toHaveAttribute('href', '/me');
  });

  it('preserves unsaved notification changes while switching tabs', () => {
    window.history.replaceState({}, '', '/user/settings?tab=notifications');
    render(<UserSettingsPage />);

    const commentToggle = screen.getByRole('checkbox', { name: 'Notify me when someone comments on my post' });
    fireEvent.click(commentToggle);
    fireEvent.click(screen.getByRole('tab', { name: 'Security' }));

    expect(window.location.search).toBe('?tab=security');

    fireEvent.click(screen.getByRole('tab', { name: 'Notifications' }));
    expect(screen.getByRole('checkbox', { name: 'Notify me when someone comments on my post' })).not.toBeChecked();
  });

  it('saves notification preferences without submitting account fields', async () => {
    window.history.replaceState({}, '', '/user/settings?tab=notifications');
    render(<UserSettingsPage />);

    fireEvent.click(screen.getByRole('checkbox', { name: 'Notify me when someone comments on my post' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save notification preferences' }));

    await waitFor(() => expect(patch).toHaveBeenCalledTimes(1));
    expect(patch).toHaveBeenCalledWith('/api/account', expect.objectContaining({
      notify_post_comment: false,
    }));
    expect(patch.mock.calls[0]?.[1]).not.toHaveProperty('name');
    expect(patch.mock.calls[0]?.[1]).not.toHaveProperty('email');
  });

  it('responds to browser history and supports arrow-key tab navigation', async () => {
    render(<UserSettingsPage />);

    const accountTab = screen.getByRole('tab', { name: 'Account' });
    fireEvent.focus(accountTab);
    fireEvent.keyDown(accountTab, { key: 'ArrowRight' });
    await waitFor(() => expect(screen.getByRole('tab', { name: 'Notifications' })).toHaveFocus());

    window.history.pushState({}, '', '/user/settings?tab=data');
    fireEvent(window, new PopStateEvent('popstate'));
    expect(screen.getByRole('tab', { name: 'Data & account' })).toHaveAttribute('aria-selected', 'true');
  });

  it('uses a wrapping mobile-safe tab grid', () => {
    render(<UserSettingsPage />);

    expect(screen.getByRole('tablist', { name: 'Settings sections' })).toHaveClass('grid-cols-2', 'sm:grid-cols-4');
  });
});
