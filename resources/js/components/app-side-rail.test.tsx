import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';
import { hydrateIdentityStore } from '@/identity';

import { AppSideRail, SideRailLayout } from './app-side-rail';

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

const waitingPayload = {
  data: {
    ...emptyPayload.data,
    pending_actions: [
      { label: 'Follow requests', count: 2, href: '/users/follow-requests' },
      { label: 'Co-author invites', count: 1, href: '/users/follow-requests' },
      { label: 'Items under review', count: 0, href: '/me' },
    ],
  },
};

it('puts a named responsive rail after the primary content in DOM order', async () => {
  jest.mocked(fetchWrapper.get).mockResolvedValue(waitingPayload);
  render(
    <SideRailLayout>
      <main data-testid="primary-content">Primary content</main>
    </SideRailLayout>,
  );

  const primary = screen.getByTestId('primary-content');
  const aside = screen.getByRole('complementary', { name: 'Account overview' });
  expect(primary.compareDocumentPosition(aside) & Node.DOCUMENT_POSITION_FOLLOWING).not.toBe(0);
  expect(aside.parentElement).toHaveClass('grid', 'lg:grid-cols-[minmax(0,1fr)_18rem]');
  expect(await screen.findByText('Needs you')).toBeInTheDocument();
});

it('lists only the pending actions that have something waiting', async () => {
  jest.mocked(fetchWrapper.get).mockResolvedValue(waitingPayload);
  render(<AppSideRail />);

  const card = (await screen.findByText('Needs you')).closest('[data-slot="card"]') as HTMLElement;
  expect(within(card).getByText('3')).toBeInTheDocument();
  expect(within(card).getByRole('link', { name: /Follow requests\s*2/ })).toHaveAttribute('href', '/users/follow-requests');
  expect(within(card).getByRole('link', { name: /Co-author invites\s*1/ })).toBeInTheDocument();
  expect(within(card).queryByText('Items under review')).toBeNull();
});

it('leaves out the pending card entirely when nothing is waiting', async () => {
  render(<AppSideRail />);

  await waitFor(() => expect(fetchWrapper.get).toHaveBeenCalledWith('/api/side-rail'));
  expect(screen.queryByText('Needs you')).toBeNull();
  expect(screen.queryByText('Pending actions')).toBeNull();
  expect(screen.queryByText('Follow requests')).toBeNull();
});

it('does not offer identity switching; the navbar and composer own it', async () => {
  hydrateIdentityStore(
    [
      { id: null, displayName: 'Human Name', avatarUrl: null },
      { id: 17, displayName: 'Kira', avatarUrl: null },
    ],
    null,
  );
  jest.mocked(fetchWrapper.get).mockResolvedValue(waitingPayload);
  render(<AppSideRail />);

  expect(await screen.findByText('Needs you')).toBeInTheDocument();
  expect(screen.queryByText('Creating as')).toBeNull();
  expect(screen.queryByRole('button', { name: 'Kira' })).toBeNull();
  expect(screen.queryByRole('heading', { name: 'Account overview' })).toBeNull();
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

it('sends a follow request from a suggestion', async () => {
  jest.mocked(fetchWrapper.get).mockResolvedValue({
    data: {
      ...emptyPayload.data,
      suggested_people: [
        { id: 8, display_name: 'Priya', avatar_url: null, href: '/users/8', interest_match_score: 50, matching_interests_count: 2 },
      ],
    },
  });
  jest.mocked(fetchWrapper.post).mockResolvedValue({ success: true, data: { status: 'pending' } });
  render(<AppSideRail />);

  expect(await screen.findByText('2 shared interests')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Follow Priya' }));

  await waitFor(() => expect(fetchWrapper.post).toHaveBeenCalledWith('/api/users/8/follow-requests', {}));
  expect(await screen.findByRole('button', { name: 'Follow request sent to Priya' })).toBeDisabled();
});

it('keeps the suggestion followable and explains a failed request', async () => {
  jest.mocked(fetchWrapper.get).mockResolvedValue({
    data: {
      ...emptyPayload.data,
      suggested_people: [
        { id: 8, display_name: 'Priya', avatar_url: null, href: '/users/8', interest_match_score: 50, matching_interests_count: 0 },
      ],
    },
  });
  jest.mocked(fetchWrapper.post).mockRejectedValue('A follow request already exists.');
  render(<AppSideRail />);

  fireEvent.click(await screen.findByRole('button', { name: 'Follow Priya' }));

  expect(await screen.findByRole('alert')).toHaveTextContent('A follow request already exists.');
  expect(screen.getByRole('button', { name: 'Follow Priya' })).toBeEnabled();
});

it('clears recent history without removing the other rail sections', async () => {
  jest.mocked(fetchWrapper.get).mockResolvedValue({
    ...waitingPayload,
    data: {
      ...waitingPayload.data,
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
  expect(screen.getByText('Needs you')).toBeInTheDocument();
});

it('does not turn server-returned script URLs into links', async () => {
  jest.mocked(fetchWrapper.get).mockResolvedValue({
    data: {
      pending_actions: [
        { label: 'Unsafe action', count: 1, href: 'javascript:alert(1)' },
      ],
      suggested_people: [
        {
          id: 8,
          display_name: 'Unsafe suggestion',
          avatar_url: null,
          href: '//evil.example/suggestion',
          interest_match_score: 100,
          matching_interests_count: 1,
        },
      ],
      recently_visited: [
        {
          type: 'user',
          id: 9,
          display_name: 'Unsafe history',
          avatar_url: null,
          href: 'data:text/html,<script>alert(1)</script>',
        },
      ],
    },
  });

  render(<AppSideRail />);

  expect(await screen.findByText('Unsafe action')).toBeInTheDocument();
  expect(screen.getByText('Unsafe suggestion')).toBeInTheDocument();
  expect(screen.getByText('Unsafe history')).toBeInTheDocument();
  expect(screen.queryAllByRole('link')).toHaveLength(0);
});
