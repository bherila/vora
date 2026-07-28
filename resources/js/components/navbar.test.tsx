import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';
import { hydrateIdentityStore } from '@/identity';

import Navbar from './navbar';

jest.mock('@/community/NotificationBell', () => ({ NotificationBell: () => null }));
jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    post: jest.fn(() => Promise.resolve({ data: { active_identity_id: 17 } })),
  },
}));

const accountMenu = {
  label: 'Human Name',
  avatarUrl: null,
  profileHref: '/me',
  items: [],
};

beforeAll(() => {
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: jest.fn().mockReturnValue({
      matches: false,
      addEventListener: jest.fn(),
      removeEventListener: jest.fn(),
    }),
  });
});

beforeEach(() => {
  localStorage.clear();
  jest.clearAllMocks();
  hydrateIdentityStore([], null);
});

describe('Navbar identity switcher', () => {
  it('keeps the existing account avatar unchanged when the user has no personas', () => {
    render(
      <Navbar
        authenticated
        navItems={[]}
        adminMenu={null}
        accountMenu={accountMenu}
        guestMenuItems={[]}
      />,
    );

    expect(screen.getByRole('navigation').className).not.toContain('flex-wrap');
    expect(screen.queryByRole('button', { name: /switch identity/i })).toBeNull();
    expect(screen.queryByText(/Creating as/)).toBeNull();
    expect(screen.getByRole('link', { name: /Human Name/i })).toHaveAttribute('href', '/me');
  });

  it('switches authorship through the session endpoint and shows the persistent H1 copy', async () => {
    hydrateIdentityStore([
      { id: null, displayName: 'Human Name', avatarUrl: null },
      { id: 17, displayName: 'Kira', avatarUrl: null },
    ], null);

    render(
      <Navbar
        authenticated
        navItems={[]}
        adminMenu={null}
        accountMenu={accountMenu}
        guestMenuItems={[]}
      />,
    );

    const trigger = screen.getByRole('button', { name: 'Switch identity (currently Human Name)' });
    expect(trigger.querySelector('[data-identity-label]')).toHaveClass('hidden', 'sm:inline');
    fireEvent.click(trigger);
    expect(screen.getByText('Switching changes who you create as — never what you can see.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('menuitemradio', { name: 'Kira' }));

    await waitFor(() => expect(fetchWrapper.post).toHaveBeenCalledWith('/api/identity', { character_id: 17 }));
    expect(await screen.findByRole('heading', { name: /Creating as Kira\./ })).toBeInTheDocument();
    expect(screen.getByText("New posts, uploads, and stories will be from Kira. What you can see doesn't change.")).toBeInTheDocument();
  });

  it('keeps direct Profile access in the account menu for persona users', () => {
    hydrateIdentityStore([
      { id: null, displayName: 'Human Name', avatarUrl: null },
      { id: 17, displayName: 'Kira', avatarUrl: null },
    ], 17);

    render(
      <Navbar
        authenticated
        navItems={[]}
        adminMenu={null}
        accountMenu={{
          ...accountMenu,
          items: [{ type: 'link', label: 'Profile', href: '/me' }],
        }}
        guestMenuItems={[]}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Account menu' }));
    expect(screen.getByRole('link', { name: 'Profile' })).toHaveAttribute('href', '/me');
    expect(screen.getByRole('button', { name: 'Switch identity (currently Kira)' })).toBeInTheDocument();
  });
});
