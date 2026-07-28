import { render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

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

jest.mock('sonner', () => ({ Toaster: () => null, toast: { success: jest.fn(), error: jest.fn() } }));

// The tab bodies and dialogs are heavy, separately-tested trees; this suite is
// about the page chrome (header, identity rail, persona affordances, tabs).
jest.mock('@/media/OwnerMediaManager', () => ({ OwnerMediaManager: () => <div data-testid="owner-media-manager" /> }));
jest.mock('@/stories/OwnerStoriesManager', () => ({ OwnerStoriesManager: () => null }));
jest.mock('@/user/CharacterEditorDialog', () => ({ CharacterEditorDialog: () => null }));
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
}

describe('FollowProfilePage (/me)', () => {
  beforeEach(() => {
    (fetchWrapper.get as jest.Mock).mockClear();
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
});
