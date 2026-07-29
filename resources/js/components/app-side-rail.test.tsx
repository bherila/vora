import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';
import { hydrateIdentityStore } from '@/identity';

import { AppSideRail, SideRailLayout } from './app-side-rail';
import Navbar from './navbar';

jest.mock('@/community/NotificationBell', () => ({ NotificationBell: () => null }));
jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: jest.fn(),
    post: jest.fn(),
    delete: jest.fn(),
  },
}));

const emptyPayload = {
  data: {
    pending_actions: [
      { label: 'Follow requests', count: 0, href: '/users/follow-requests' },
      { label: 'Co-author invites', count: 0, href: '/users/follow-requests' },
      { label: 'Items under review', count: 0, href: '/me' },
    ],
    suggested_people: [],
    recently_visited: [],
  },
};

const accountMenu = {
  label: 'Human Name',
  avatarUrl: null,
  profileHref: '/me',
  items: [
    { type: 'link' as const, label: 'Settings', href: '/settings' },
    { type: 'action' as const, label: 'Log out', action: 'logout' as const },
  ],
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
  jest.clearAllMocks();
  jest.mocked(fetchWrapper.get).mockResolvedValue(emptyPayload);
  jest.mocked(fetchWrapper.post).mockResolvedValue({ data: { active_identity_id: 17 } });
  jest.mocked(fetchWrapper.delete).mockResolvedValue({ success: true });
  hydrateIdentityStore([], null);
});

it('puts a named responsive rail after the primary content in DOM order', async () => {
  render(
    <SideRailLayout>
      <main data-testid="primary-content">Primary content</main>
    </SideRailLayout>,
  );

  const primary = screen.getByTestId('primary-content');
  const aside = screen.getByRole('complementary', { name: 'Account overview' });
  expect(primary.compareDocumentPosition(aside) & Node.DOCUMENT_POSITION_FOLLOWING).not.toBe(0);
  expect(aside.parentElement).toHaveClass('grid', 'lg:grid-cols-[minmax(0,1fr)_18rem]');
  expect(await screen.findByText('Pending actions')).toBeInTheDocument();
});

it('does not render an identity section for a persona-free account', async () => {
  render(<AppSideRail />);

  expect(await screen.findByText('Pending actions')).toBeInTheDocument();
  expect(screen.queryByText('Creating as')).toBeNull();
  expect(screen.queryByText(/Switching changes who you create as/)).toBeNull();
});

it('can leave pages outside Feed, Explore, and me as a single column without an aside', () => {
  render(
    <SideRailLayout enabled={false}>
      <div>Other profile</div>
    </SideRailLayout>,
  );

  expect(screen.getByText('Other profile')).toBeInTheDocument();
  expect(screen.queryByRole('complementary', { name: 'Account overview' })).toBeNull();
  expect(fetchWrapper.get).not.toHaveBeenCalled();
});

it('keeps rail and navbar identity controls synchronized through the shared store', async () => {
  hydrateIdentityStore(
    [
      { id: null, displayName: 'Human Name', avatarUrl: null },
      { id: 17, displayName: 'Kira', avatarUrl: null },
    ],
    null,
  );

  render(
    <>
      <Navbar
        authenticated
        navItems={[]}
        adminMenu={null}
        accountMenu={accountMenu}
        guestMenuItems={[]}
      />
      <AppSideRail />
    </>,
  );

  fireEvent.click(await screen.findByRole('button', { name: 'Kira' }));
  await waitFor(() =>
    expect(fetchWrapper.post).toHaveBeenCalledWith('/api/identity', { character_id: 17 }),
  );
  expect(
    screen.getByRole('button', { name: 'Account and identity menu (currently Kira)' }),
  ).toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Account and identity menu (currently Kira)' }));
  const menu = screen.getByRole('menu');
  fireEvent.click(within(menu).getByRole('menuitemradio', { name: 'Human Name' }));

  await waitFor(() =>
    expect(screen.getByRole('button', { name: 'Human Name' })).toHaveAttribute('aria-pressed', 'true'),
  );
  expect(screen.getByText('Switching changes who you create as — never what you can see.')).toBeInTheDocument();
});

it('clears recent history without removing the other rail sections', async () => {
  jest.mocked(fetchWrapper.get).mockResolvedValue({
    ...emptyPayload,
    data: {
      ...emptyPayload.data,
      recently_visited: [
        {
          type: 'character',
          id: 7,
          display_name: 'Independent Persona',
          avatar_url: null,
          href: '/c/persona-ulid',
        },
      ],
    },
  });
  render(<AppSideRail />);

  expect(await screen.findByText('Independent Persona')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Clear' }));

  await waitFor(() => expect(fetchWrapper.delete).toHaveBeenCalledWith('/api/side-rail/history'));
  await waitFor(() => expect(screen.queryByText('Recently visited')).toBeNull());
  expect(screen.getByText('Pending actions')).toBeInTheDocument();
});
