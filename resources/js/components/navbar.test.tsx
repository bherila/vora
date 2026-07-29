import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

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
  items: [
    { type: 'link' as const, label: 'Settings', href: '/settings' },
    { type: 'link' as const, label: 'Invites', href: '/invites' },
    { type: 'action' as const, label: 'Log out', action: 'logout' as const },
  ],
};

function renderNavbar() {
  return render(
    <Navbar
      authenticated
      navItems={[]}
      adminMenu={null}
      accountMenu={accountMenu}
      guestMenuItems={[]}
    />,
  );
}

function hydratePersonas(activeIdentityId: number | null = null): void {
  hydrateIdentityStore([
    { id: null, displayName: 'Human Name', avatarUrl: null },
    { id: 17, displayName: 'Kira', avatarUrl: null },
  ], activeIdentityId);
}

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

describe('Navbar account and identity menu', () => {
  it('uses one accessible menu in the persona-free state and keeps every account link available', () => {
    const { container } = renderNavbar();

    expect(screen.getByRole('navigation').className).not.toContain('flex-wrap');
    expect(screen.queryByText(/Creating as/)).toBeNull();

    const trigger = screen.getByRole('button', {
      name: 'Account and identity menu (currently Human Name)',
    });
    expect(trigger).toHaveAttribute('aria-haspopup', 'menu');
    expect(trigger).toHaveAttribute('aria-expanded', 'false');
    expect(trigger).toHaveClass('min-h-6', 'min-w-6');
    expect(container.querySelectorAll('.lucide-chevron-down')).toHaveLength(1);

    fireEvent.click(trigger);

    const menu = screen.getByRole('menu');
    expect(within(menu).queryByText('Acting as')).toBeNull();
    expect(within(menu).getByRole('menuitem', { name: 'Profile' })).toHaveAttribute('href', '/me');
    expect(within(menu).getByRole('menuitem', { name: 'Settings' })).toHaveAttribute('href', '/settings');
    expect(within(menu).getByRole('menuitem', { name: 'Invites' })).toHaveAttribute('href', '/invites');
    expect(within(menu).getByRole('menuitem', { name: 'Log out' })).toBeInTheDocument();
    expect(
      within(menu).getAllByRole('menuitem').every((item) => item.classList.contains('min-h-6')),
    ).toBe(true);
  });

  it('combines acting-as choices and account links without a second trigger', () => {
    hydratePersonas(17);
    const { container } = renderNavbar();

    const trigger = screen.getByRole('button', {
      name: 'Account and identity menu (currently Kira)',
    });
    expect(trigger.querySelector('[data-identity-label]')).toHaveClass('hidden', 'sm:inline');
    expect(container.querySelectorAll('.lucide-chevron-down')).toHaveLength(1);
    expect(screen.queryByRole('button', { name: 'Account menu' })).toBeNull();
    expect(screen.queryByRole('button', { name: /Switch identity/ })).toBeNull();

    fireEvent.click(trigger);

    const menu = screen.getByRole('menu');
    expect(within(menu).getByText('Acting as')).toBeInTheDocument();
    expect(within(menu).getByRole('menuitemradio', { name: 'Kira' })).toHaveAttribute('aria-checked', 'true');
    expect(within(menu).getByRole('menuitemradio', { name: 'Human Name' })).toHaveAttribute('aria-checked', 'false');
    expect(
      within(menu).getAllByRole('menuitemradio').every((item) => item.classList.contains('min-h-6')),
    ).toBe(true);
    expect(within(menu).getByRole('menuitem', { name: 'Profile' })).toHaveAttribute('href', '/me');
    expect(within(menu).getByText('Switching changes who you create as — never what you can see.')).toBeInTheDocument();
  });

  it('switches authorship through the session endpoint and keeps the Creating-as banner', async () => {
    hydratePersonas();
    renderNavbar();

    fireEvent.click(screen.getByRole('button', {
      name: 'Account and identity menu (currently Human Name)',
    }));
    fireEvent.click(screen.getByRole('menuitemradio', { name: 'Kira' }));

    await waitFor(() => expect(fetchWrapper.post).toHaveBeenCalledWith('/api/identity', { character_id: 17 }));
    expect(await screen.findByRole('heading', { name: /Creating as Kira\./ })).toBeInTheDocument();
    expect(screen.getByText("New posts, uploads, and stories will be from Kira. What you can see doesn't change.")).toBeInTheDocument();
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('supports arrow movement and Escape dismissal with focus returned to the trigger', async () => {
    hydratePersonas();
    renderNavbar();

    const trigger = screen.getByRole('button', {
      name: 'Account and identity menu (currently Human Name)',
    });
    trigger.focus();
    fireEvent.keyDown(trigger, { key: 'ArrowDown' });

    const accountIdentity = await screen.findByRole('menuitemradio', { name: 'Human Name' });
    await waitFor(() => expect(accountIdentity).toHaveFocus());

    fireEvent.keyDown(accountIdentity, { key: 'ArrowDown' });
    const personaIdentity = screen.getByRole('menuitemradio', { name: 'Kira' });
    await waitFor(() => expect(personaIdentity).toHaveFocus());

    fireEvent.keyDown(personaIdentity, { key: 'Escape' });
    await waitFor(() => expect(screen.queryByRole('menu')).toBeNull());
    expect(trigger).toHaveFocus();
  });

  it('traps Tab inside the open modal menu instead of exposing the page behind it', async () => {
    hydratePersonas();
    renderNavbar();

    const trigger = screen.getByRole('button', {
      name: 'Account and identity menu (currently Human Name)',
    });
    trigger.focus();
    fireEvent.keyDown(trigger, { key: 'ArrowDown' });

    const firstItem = await screen.findByRole('menuitemradio', { name: 'Human Name' });
    await waitFor(() => expect(firstItem).toHaveFocus());

    expect(fireEvent.keyDown(firstItem, { key: 'Tab' })).toBe(false);
    expect(screen.getByRole('menu')).toBeInTheDocument();
    expect(screen.getByRole('menuitemradio', { name: 'Kira' })).toHaveFocus();
    expect(screen.getByTitle('System')).not.toHaveFocus();
  });
});
