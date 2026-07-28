import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import Navbar from '@/components/navbar';
import { fetchWrapper } from '@/fetchWrapper';
import { hydrateIdentityStore } from '@/identity';

import { FollowProfilePage } from './follow-profile';

// Read the hydration script fresh on every call so each test can swap payloads
// (the real module caches the first parse for the lifetime of the page).
jest.mock('@/initialData', () => ({
  readInitialData: () => {
    const el = document.getElementById('initial-data');
    return el?.textContent ? JSON.parse(el.textContent) : {};
  },
}));

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: jest.fn((url: string) => Promise.resolve(
      url.includes('content-counts')
        ? { data: { media: 0, stories: 0, posts: 0, favorites: 0 } }
        : { data: [] },
    )),
    post: jest.fn(() => Promise.resolve({})),
    patch: jest.fn(() => Promise.resolve({})),
    delete: jest.fn(() => Promise.resolve({})),
  },
}));

jest.mock('@/community/NotificationBell', () => ({ NotificationBell: () => null }));
jest.mock('sonner', () => ({ Toaster: () => null, toast: { success: jest.fn(), error: jest.fn() } }));

// The tab bodies and dialogs are heavy, separately-tested trees; this suite is
// about the page chrome (header, identity rail, persona affordances, tabs).
jest.mock('@/media/OwnerMediaManager', () => ({
  OwnerMediaManager: ({ identity }: { identity: number | null }) => (
    <div data-testid="owner-media-manager" data-identity={identity ?? 'human'} />
  ),
}));
jest.mock('@/stories/OwnerStoriesManager', () => ({ OwnerStoriesManager: () => null }));
jest.mock('@/user/CharacterEditorDialog', () => ({
  CharacterEditorDialog: ({
    open,
    onSaved,
  }: {
    open: boolean;
    onSaved: (record: Record<string, unknown>) => void;
  }) => open ? (
    <button
      type="button"
      onClick={() => onSaved({
        id: 23,
        display_name: 'Nova',
        profile_picture: null,
        audience: 'everyone',
        audience_user_ids: [],
      })}
    >
      Save Nova
    </button>
  ) : null,
}));
jest.mock('@/user/profile-identity-editor', () => ({ ProfileIdentityEditor: () => null }));
jest.mock('@/community/PostCard', () => ({ PostCard: () => null }));
jest.mock('@/explore/StoryGrid', () => ({ StoryGrid: () => null }));
jest.mock('@/media/MediaGrid', () => ({ MediaGrid: () => null }));
jest.mock('@/components/favorite-button', () => ({ FavoriteButton: () => null }));

interface ProfileOverrides { characters?: { id: number; display_name: string; avatar_url?: string | null }[] }

function ownerInitialData(overrides: ProfileOverrides = {}, extra: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    followProfile: {
      id: 7,
      is_self: true,
      display_name: 'Ben',
      avatar_url: null,
      restricted: false,
      bio: 'Hello there.',
      pronouns: 'he/him',
      user_type: 'human',
      gender: 'male',
      mutual_interests: [],
      follow_request: null,
      can_follow_back: false,
      characters: overrides.characters ?? [],
    },
    profileEditable: null,
    profileMedia: { characters: [], last_interest_ids: [] },
    profileCharacters: (overrides.characters ?? []).map((c) => ({ id: c.id, display_name: c.display_name, profile_picture: null })),
    profileIdentityCounts: null,
    ...extra,
  };
}

function setInitialData(data: Record<string, unknown>): void {
  document.body.innerHTML = '<script id="initial-data" type="application/json"></script>';
  const el = document.getElementById('initial-data');
  if (el) el.textContent = JSON.stringify(data);
  const navbar = data.navbar as { identities?: []; activeIdentityId?: number | null } | undefined;
  hydrateIdentityStore(navbar?.identities ?? [], navbar?.activeIdentityId ?? null);
}

describe('FollowProfilePage (/me)', () => {
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
    window.confirm = jest.fn(() => true);
  });

  it('renders a persona-free owner with no persona affordances beyond the single quiet entry point', async () => {
    setInitialData(ownerInitialData());
    render(<FollowProfilePage />);

    // The owner lands on their own work (media tab), not a feed.
    await waitFor(() => expect(screen.getByTestId('owner-media-manager')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /home/i })).toBeNull();
    expect(screen.queryByText(/get started/i)).toBeNull();

    // No identity rail at all — not an empty or one-tab rail.
    expect(screen.queryByRole('navigation', { name: 'Identities' })).toBeNull();
    expect(screen.queryByText('New persona')).toBeNull();

    // Exactly one entry point: the quiet "Create a persona" button and its
    // help hint. No other control on the page mentions personas.
    expect(screen.getByRole('button', { name: 'Create a persona' })).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: /persona/i })).toHaveLength(2);
    expect(screen.getByRole('button', { name: 'View as' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Personas' }));
    expect(await screen.findByText(
      'Personas are characters you create — for fiction, art, and role-play. Each gets its own page, its own followers, and its own media. Your real profile stays separate. Most people never need one.',
    )).toBeInTheDocument();
  });

  it('renders public preview through read-only visitor surfaces with the exact persistent copy', async () => {
    (fetchWrapper.get as jest.Mock).mockImplementation((url: string) => Promise.resolve(
      url.includes('content-counts')
        ? { data: { media: 0, stories: 0, posts: 0, favorites: 1 } }
        : url.includes('/favorites')
          ? {
              data: [{
                type: 'media',
                id: 21,
                label: 'Saved portrait',
                subtitle: 'Media',
                href: '/m/01MEDIA',
                thumbnail_url: null,
              }],
            }
          : { data: [] },
    ));
    setInitialData(ownerInitialData(
      { characters: [{ id: 5, display_name: 'Kira', avatar_url: null }] },
      {
        followProfile: {
          ...ownerInitialData().followProfile as Record<string, unknown>,
          is_self: false,
          characters: [{ id: 5, display_name: 'Kira', avatar_url: null }],
        },
        profileViewAs: { mode: 'public', audience: "someone who doesn't follow you" },
      },
    ));
    render(<FollowProfilePage />);

    expect(await screen.findByText((_, element) => (
      element?.textContent === "Viewing your profile as someone who doesn't follow you. This is exactly what they see."
    ))).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'View as' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Exit preview' })).toHaveAttribute('href', '/me');
    expect(screen.queryByRole('button', { name: 'Edit profile' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Account settings' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Create a persona' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Edit persona' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Delete' })).toBeNull();
    expect(screen.queryByTestId('owner-media-manager')).toBeNull();

    await waitFor(() => expect(fetchWrapper.get).toHaveBeenCalledWith(
      '/api/users/7/content-counts?view_as=public',
    ));
    expect(fetchWrapper.get).toHaveBeenCalledWith('/api/users/7/media?view_as=public');

    fireEvent.click(screen.getByRole('button', { name: 'Favorites 1' }));
    const favoriteLabel = await screen.findByText('Saved portrait');
    expect(favoriteLabel.closest('a')).toBeNull();
  });

  it('renders an active persona preview as the visitor persona page without mutation controls', async () => {
    setInitialData({
      personaProfile: {
        id: 5,
        ulid: '01PERSONA',
        display_name: 'Vex',
        description: 'A separate identity.',
        avatar_url: null,
        user_type: null,
        gender: null,
        is_owner: false,
        is_linked: false,
        owner: null,
        interests: [],
        viewer_favorited: false,
        can_report: false,
      },
      profileViewAs: { mode: 'follower', audience: 'someone who follows you' },
      navbar: {
        identities: [
          { id: null, displayName: 'Ben', avatarUrl: null },
          { id: 5, displayName: 'Vex', avatarUrl: null },
        ],
        activeIdentityId: 5,
      },
    });
    render(<FollowProfilePage />);

    expect(await screen.findByRole('heading', { name: 'Vex' })).toBeInTheDocument();
    expect(screen.getByText((_, element) => (
      element?.textContent === 'Viewing your profile as someone who follows you. This is exactly what they see.'
    ))).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /manage on your profile/i })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Follow Vex' })).toBeNull();
    expect(screen.queryByRole('button', { name: /save/i })).toBeNull();
    expect(screen.queryByRole('button', { name: /report/i })).toBeNull();

    await waitFor(() => expect(fetchWrapper.get).toHaveBeenCalledWith(
      '/api/c/01PERSONA/counts?view_as=follower',
    ));
    expect(fetchWrapper.get).toHaveBeenCalledWith(
      '/api/characters/5/followers?view_as=follower',
    );
  });

  it('shows bio and pronouns in the header', async () => {
    setInitialData(ownerInitialData());
    render(<FollowProfilePage />);

    await waitFor(() => expect(screen.getByText('Hello there.')).toBeInTheDocument());
    expect(screen.getByText('he/him')).toBeInTheDocument();
  });

  it('renders the identity rail with per-identity counts once a persona exists', async () => {
    setInitialData(ownerInitialData(
      { characters: [{ id: 5, display_name: 'Kira', avatar_url: null }] },
      { profileIdentityCounts: { self: 3, characters: { '5': 2 } } },
    ));
    render(<FollowProfilePage />);

    const rail = await screen.findByRole('navigation', { name: 'Identities' });
    expect(rail).toHaveTextContent('Ben');
    expect(rail).toHaveTextContent('Kira');
    expect(rail).toHaveTextContent('3');
    expect(rail).toHaveTextContent('2');
    // The entry point moves into the rail; the quiet one disappears.
    expect(screen.getByRole('button', { name: /new persona/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Create a persona' })).toBeNull();
  });

  it('keeps the navbar, profile rail, and upload listing on one live identity in both directions', async () => {
    const characters = [
      { id: 5, display_name: 'Kira', avatar_url: null },
      { id: 6, display_name: 'Vex', avatar_url: null },
    ];
    setInitialData(ownerInitialData(
      { characters },
      {
        navbar: {
          identities: [
            { id: null, displayName: 'Ben', avatarUrl: null },
            { id: 5, displayName: 'Kira', avatarUrl: null },
            { id: 6, displayName: 'Vex', avatarUrl: null },
          ],
          activeIdentityId: 5,
        },
      },
    ));

    render(<Navbar authenticated navItems={[]} adminMenu={null} accountMenu={null} guestMenuItems={[]} />);
    render(<FollowProfilePage />);

    const rail = await screen.findByRole('navigation', { name: 'Identities' });
    expect(within(rail).getByRole('button', { name: 'Kira' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByTestId('owner-media-manager')).toHaveAttribute('data-identity', '5');

    fireEvent.click(within(rail).getByRole('button', { name: 'Vex' }));
    await waitFor(() => expect(fetchWrapper.post).toHaveBeenCalledWith('/api/identity', { character_id: 6 }));
    expect(await screen.findByRole('heading', { name: /Creating as Vex\./ })).toBeInTheDocument();
    expect(screen.getByTestId('owner-media-manager')).toHaveAttribute('data-identity', '6');

    fireEvent.click(screen.getByRole('button', { name: 'Switch identity (currently Vex)' }));
    fireEvent.click(screen.getByRole('menuitemradio', { name: 'Kira' }));
    await waitFor(() => expect(fetchWrapper.post).toHaveBeenCalledWith('/api/identity', { character_id: 5 }));
    expect(within(rail).getByRole('button', { name: 'Kira' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByTestId('owner-media-manager')).toHaveAttribute('data-identity', '5');
  });

  it('adds a newly created persona to the live navbar options', async () => {
    setInitialData(ownerInitialData({}, {
      navbar: { identities: [], activeIdentityId: null },
    }));
    render(<Navbar authenticated navItems={[]} adminMenu={null} accountMenu={null} guestMenuItems={[]} />);
    render(<FollowProfilePage />);

    fireEvent.click(screen.getByRole('button', { name: 'Create a persona' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save Nova' }));

    expect(await screen.findByRole('button', { name: 'Switch identity (currently Ben)' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Switch identity (currently Ben)' }));
    expect(screen.getByRole('menuitemradio', { name: 'Nova' })).toBeInTheDocument();
  });

  it('deleting the active persona clears the server identity and removes its live option', async () => {
    const characters = [
      { id: 5, display_name: 'Kira', avatar_url: null },
      { id: 6, display_name: 'Vex', avatar_url: null },
    ];
    setInitialData(ownerInitialData(
      { characters },
      {
        navbar: {
          identities: [
            { id: null, displayName: 'Ben', avatarUrl: null },
            { id: 5, displayName: 'Kira', avatarUrl: null },
            { id: 6, displayName: 'Vex', avatarUrl: null },
          ],
          activeIdentityId: 5,
        },
      },
    ));
    render(<Navbar authenticated navItems={[]} adminMenu={null} accountMenu={null} guestMenuItems={[]} />);
    render(<FollowProfilePage />);

    fireEvent.click(await screen.findByRole('button', { name: 'Delete' }));

    await waitFor(() => expect(fetchWrapper.delete).toHaveBeenCalledWith('/api/characters/5'));
    expect(fetchWrapper.post).toHaveBeenCalledWith('/api/identity', { character_id: null });
    expect(screen.queryByRole('heading', { name: /Creating as Kira\./ })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Switch identity (currently Ben)' }));
    expect(screen.queryByRole('menuitemradio', { name: 'Kira' })).toBeNull();
    expect(screen.getByRole('menuitemradio', { name: 'Vex' })).toBeInTheDocument();
  });
});
