import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';
import type { InstanceBootstrap } from '@/instance';
import { currentBrowserPushSubscription } from '@/push';

let mockInstance: InstanceBootstrap['instance'];
let mockPushSupported = false;
let mockProviderAccountUrl: string | null = null;

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
    instance: mockInstance,
    userSettings: {
      name: 'Account Name',
      display_name: 'Public Name',
      birth_date: '1990-01-01',
      email: 'account@example.com',
      provider_account_url: mockProviderAccountUrl,
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
      storage_usage: {
        active_bytes: 1048576,
        uploaded_bytes: 1048576,
        saved_bytes: 0,
        retained_bytes: 524288,
        total_bytes: 1572864,
        active_file_count: 2,
        retained_file_count: 1,
        unknown_size_file_count: 1,
      },
    },
  }),
}));

jest.mock('@/push', () => ({
  currentBrowserPushSubscription: jest.fn(),
  isWebPushSupported: () => mockPushSupported,
  subscribeBrowserToWebPush: jest.fn(),
  unsubscribeBrowserFromWebPush: jest.fn(),
}));

const patch = jest.mocked(fetchWrapper.patch);
const get = jest.mocked(fetchWrapper.get);
const remove = jest.mocked(fetchWrapper.delete);

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
      notify_message: true,
    },
  };
}

describe('UserSettingsPage', () => {
  beforeEach(() => {
    mockInstance = undefined;
    mockPushSupported = false;
    mockProviderAccountUrl = null;
    window.history.replaceState({}, '', '/user/settings');
    patch.mockReset();
    patch.mockResolvedValue(notificationResponse());
    get.mockReset();
    remove.mockReset();
    get.mockResolvedValue({ data: [] });
    remove.mockResolvedValue({ data: { muted: false } });
  });

  it('opens a URL-addressed tab and keeps the profile card above it', () => {
    window.history.replaceState({}, '', '/user/settings?tab=notifications');

    render(<UserSettingsPage />);

    expect(screen.getByRole('tab', { name: 'Notifications' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByText('Your public profile')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Edit profile' })).toHaveAttribute('href', '/me');
  });

  it('shows active and retained original-file storage with an incomplete-size notice', () => {
    window.history.replaceState({}, '', '/user/settings?tab=data');

    render(<UserSettingsPage />);

    expect(screen.getByText('Storage usage')).toBeInTheDocument();
    expect(screen.getByText('Active files (2)')).toBeInTheDocument();
    expect(screen.getByText('1.00 MB')).toBeInTheDocument();
    expect(screen.getByText('Retained deleted files (1)')).toBeInTheDocument();
    expect(screen.getByText('512.0 KB')).toBeInTheDocument();
    expect(screen.getByText('Total stored originals')).toBeInTheDocument();
    expect(screen.getByText('1.50 MB')).toBeInTheDocument();
    expect(screen.getByText(/Size unavailable for 1 file;/)).toBeInTheDocument();
  });

  it('sends password, passkey and email changes to the sign-in provider when it owns them', () => {
    mockProviderAccountUrl = 'https://id.example.test/settings';
    window.history.replaceState({}, '', '/user/settings?tab=security');

    render(<UserSettingsPage />);

    expect(screen.getByRole('link', { name: 'Manage sign-in settings' })).toHaveAttribute('href', 'https://id.example.test/settings');
    expect(screen.queryByText('Change password form')).not.toBeInTheDocument();
    expect(screen.queryByText('Passkey settings')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('tab', { name: 'Account' }));
    expect(screen.getByLabelText('Email')).toBeDisabled();
    expect(screen.getByText('Your email is managed by your sign-in provider.')).toBeInTheDocument();
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

  it('lists exact muted identities in Privacy and uses target-specific unmute controls', async () => {
    window.history.replaceState({}, '', '/user/settings?tab=privacy');
    get.mockImplementation(async (url) => ({
      data: url === '/api/mutes' ? [{
        type: 'character',
        id: 9,
        display_name: 'Kira',
        avatar_url: null,
        profile_url: '/c/01KIRA',
      }] : [],
    }));

    render(<UserSettingsPage />);

    expect(await screen.findByRole('link', { name: /Kira/ })).toHaveAttribute('href', '/c/01KIRA');
    fireEvent.click(screen.getByRole('button', { name: 'Unmute Kira' }));

    await waitFor(() => expect(remove).toHaveBeenCalledWith('/api/mutes', {
      type: 'character',
      id: 9,
    }));
    expect(screen.queryByText('Kira')).toBeNull();
  });

  it('shows the exact blocked persona with its date and a target-specific unblock control', async () => {
    window.history.replaceState({}, '', '/user/settings?tab=privacy');
    get.mockImplementation(async (url) => ({
      data: url === '/api/blocks' ? [{
        block_id: 42,
        type: 'character',
        id: 9,
        display_name: 'Kira',
        avatar_url: null,
        blocked_at: '2026-07-29T12:00:00Z',
      }] : [],
    }));

    render(<UserSettingsPage />);

    expect(await screen.findByText('Kira')).toBeInTheDocument();
    expect(screen.getByText('Persona')).toBeInTheDocument();
    expect(screen.getByText(/^Blocked \d/)).toBeInTheDocument();
    expect(screen.queryByText('Owner Sentinel')).toBeNull();
    expect(screen.getByText(/follow relationships removed by blocking are not restored/i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Unblock Kira' }));

    await waitFor(() => expect(remove).toHaveBeenCalledWith('/api/blocks/42'));
    expect(screen.queryByText('Kira')).toBeNull();
  });

  it('keeps blocking discoverable with an empty state', async () => {
    window.history.replaceState({}, '', '/user/settings?tab=privacy');

    render(<UserSettingsPage />);

    expect(await screen.findByText('You have not blocked anyone.')).toBeInTheDocument();
    expect(screen.getByText('Blocked accounts and personas')).toBeInTheDocument();
  });

  it('uses a wrapping mobile-safe tab grid', () => {
    render(<UserSettingsPage />);

    expect(screen.getByRole('tablist', { name: 'Settings sections' })).toHaveClass('grid-cols-2', 'sm:grid-cols-5');
  });
  it.each(['notifications', 'privacy'])('falls back from disabled social %s settings without requesting disabled modules', (tab) => {
    mockInstance = { profile: 'event', modules: { profiles: false, personas: false, community: false, stories: false, chat: false, favorites: false } };
    window.history.replaceState({}, '', `/user/settings?tab=${tab}`);
    render(<UserSettingsPage />);
    expect(screen.getByRole('tab', { name: 'Account' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.queryByRole('tab', { name: 'Notifications' })).toBeNull();
    expect(screen.queryByRole('tab', { name: 'Privacy' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Edit profile' })).toBeNull();
    expect(screen.getByLabelText('Display name')).toHaveValue('Public Name');
    expect(screen.getByText('1990-01-01')).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Security' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Data & account' })).toBeInTheDocument();
    expect(get).not.toHaveBeenCalled();
  });

  it('keeps favorite-only notification preferences available to Event attendees without social controls', async () => {
    mockInstance = { profile: 'event', modules: { profiles: false, personas: false, community: false, stories: false, chat: false, favorites: true } };
    window.history.replaceState({}, '', '/user/settings?tab=notifications');
    patch.mockResolvedValue({ ...notificationResponse(), data: { ...notificationResponse().data, notify_favorite: false } });
    render(<UserSettingsPage />);
    expect(screen.getByRole('tab', { name: 'Notifications' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.queryByRole('tab', { name: 'Privacy' })).toBeNull();
    expect(screen.getAllByRole('checkbox')).toHaveLength(1);
    fireEvent.click(screen.getByRole('checkbox', { name: 'Notify me when someone favorites my content' }));
    expect(screen.getByText('Browser push notifications')).toBeInTheDocument();
    expect(screen.getByText(/Notifications stay in your inbox/)).toBeInTheDocument();
    expect(screen.getByRole('tablist', { name: 'Settings sections' })).toHaveClass('sm:grid-cols-4');
    fireEvent.click(screen.getByRole('button', { name: 'Save notification preferences' }));
    await waitFor(() => expect(patch).toHaveBeenCalledWith('/api/account', { notify_favorite: false }));
    expect(get).not.toHaveBeenCalledWith('/api/mutes');
    expect(get).not.toHaveBeenCalledWith('/api/blocks');
  });

  it('checks browser push ownership when Favorites is the only notification module', async () => {
    mockInstance = { profile: 'event', modules: { profiles: false, personas: false, community: false, stories: false, chat: false, favorites: true } };
    mockPushSupported = true;
    jest.mocked(currentBrowserPushSubscription).mockResolvedValue(null);
    const originalNotification = Object.getOwnPropertyDescriptor(globalThis, 'Notification');
    Object.defineProperty(globalThis, 'Notification', { configurable: true, value: { permission: 'granted' } });
    try {
      render(<UserSettingsPage />);
      await waitFor(() => expect(get).toHaveBeenCalledWith('/api/push-subscriptions'));
      expect(get).not.toHaveBeenCalledWith('/api/mutes');
      expect(get).not.toHaveBeenCalledWith('/api/blocks');
    } finally {
      if (originalNotification) Object.defineProperty(globalThis, 'Notification', originalNotification);
      else Reflect.deleteProperty(globalThis, 'Notification');
    }
  });

});
