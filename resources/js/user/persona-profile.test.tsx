import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { PersonaProfilePage } from './persona-profile';

const mockGet = jest.fn();
const mockPost = jest.fn();

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
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
}));

jest.mock('sonner', () => ({ Toaster: () => null, toast: { success: jest.fn(), error: jest.fn() } }));

// The tab bodies are heavy, separately-exercised trees; this suite is about the
// page chrome — and specifically about what the header does and does not reveal.
jest.mock('@/user/profile-tabs', () => ({
  MediaListTab: () => <div data-testid="media-tab" />,
  StoriesListTab: () => null,
  PostsListTab: () => null,
}));

function personaData(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    personaProfile: {
      id: 9,
      ulid: '01HZX5PERSONA',
      display_name: 'Kira',
      description: 'A wandering star-cartographer.',
      avatar_url: null,
      user_type: 'furry',
      gender: 'female',
      is_owner: false,
      is_linked: true,
      owner: { display_name: 'Ben', href: '/users/7' },
      interests: [],
      viewer_favorited: false,
      can_report: true,
      ...overrides,
    },
  };
}

function setInitialData(data: Record<string, unknown>): void {
  document.body.innerHTML = '<script id="initial-data" type="application/json"></script>';
  const el = document.getElementById('initial-data');
  if (el) el.textContent = JSON.stringify(data);
}

describe('PersonaProfilePage (/c/{ulid})', () => {
  beforeEach(() => {
    mockGet.mockImplementation((url: string) => Promise.resolve(
      url.endsWith('/followers')
        ? { data: { count: 0, viewer_is_following: false, followers: [] } }
        : { data: { media: 0, stories: 0, posts: 0 } },
    ));
    mockPost.mockResolvedValue({});
  });

  it('leads with the persona and names the owner only as quiet meta when Linked', async () => {
    setInitialData(personaData());
    render(<PersonaProfilePage />);

    expect(await screen.findByRole('button', { name: '0 followers' })).toBeInTheDocument();
    expect(screen.getByText('Kira')).toBeInTheDocument();
    expect(screen.getByText('A wandering star-cartographer.')).toBeInTheDocument();
    const ownerLink = screen.getByRole('link', { name: 'Ben' });
    expect(ownerLink).toHaveAttribute('href', '/users/7');
    // Visitors get Save + Report, not owner management.
    expect(screen.getByRole('button', { name: /save/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /report/i })).toBeInTheDocument();
  });

  it('shows no owner meta at all for a Separate persona', async () => {
    setInitialData(personaData({ is_linked: false, owner: null }));
    render(<PersonaProfilePage />);

    expect(await screen.findByRole('button', { name: '0 followers' })).toBeInTheDocument();
    expect(screen.getByText('Kira')).toBeInTheDocument();
    expect(screen.queryByText(/a persona of/i)).toBeNull();
    expect(screen.queryByRole('link', { name: 'Ben' })).toBeNull();
  });

  it('gives the owner a manage affordance instead of save/report', async () => {
    setInitialData(personaData({ is_owner: true, can_report: false, owner: { display_name: 'Ben', href: '/me' } }));
    render(<PersonaProfilePage />);

    expect(await screen.findByRole('button', { name: '0 followers' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /manage on your profile/i })).toHaveAttribute('href', '/me');
    expect(screen.queryByRole('button', { name: /report/i })).toBeNull();
    expect(screen.queryByRole('button', { name: /save/i })).toBeNull();
  });

  it('shows follower count and edge identity without exposing a persona owner', async () => {
    mockGet.mockImplementation((url: string) => Promise.resolve(
      url.endsWith('/followers')
        ? {
            data: {
              count: 2,
              viewer_is_following: false,
              followers: [
                {
                  follower: { id: 11, display_name: 'Alice', avatar_url: null, restricted: false },
                  target: { type: 'character', id: 9, ulid: '01HZX5PERSONA', display_name: 'Kira', avatar_url: null },
                  followed_at: '2026-07-28T12:00:00Z',
                },
                {
                  follower: { id: 12, display_name: 'Sam', avatar_url: null, restricted: true },
                  target: { type: 'user', id: 7, display_name: 'Private Human', avatar_url: null },
                  followed_at: '2026-07-28T13:00:00Z',
                },
              ],
            },
          }
        : { data: { media: 0, stories: 0, posts: 0 } },
    ));
    setInitialData(personaData({ is_linked: false, owner: null }));
    render(<PersonaProfilePage />);

    const followersButton = await screen.findByRole('button', { name: /2 followers/i });
    fireEvent.click(followersButton);

    expect(await screen.findByRole('dialog')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Alice' })).toHaveAttribute('href', '/users/11');
    expect(screen.getByText('Follows Kira directly')).toBeInTheDocument();
    expect(screen.getByText(/Follows the linked account/)).toBeInTheDocument();
    expect(screen.queryByText('Private Human')).toBeNull();
  });

  it('follows a persona through the auto-accepted edge and refreshes its count', async () => {
    let following = false;
    mockGet.mockImplementation((url: string) => Promise.resolve(
      url.endsWith('/followers')
        ? { data: { count: following ? 1 : 0, viewer_is_following: following, followers: [] } }
        : { data: { media: 0, stories: 0, posts: 0 } },
    ));
    mockPost.mockImplementation(() => {
      following = true;
      return Promise.resolve({ data: { status: 'accepted' } });
    });
    setInitialData(personaData({ is_linked: false, owner: null }));
    render(<PersonaProfilePage />);

    const followButton = await screen.findByRole('button', { name: 'Follow Kira' });
    fireEvent.click(followButton);

    await waitFor(() => expect(mockPost).toHaveBeenCalledWith('/api/characters/9/follow', {}));
    expect(await screen.findByRole('button', { name: 'Following Kira' })).toBeDisabled();
    expect(screen.getByRole('button', { name: /1 follower/i })).toBeInTheDocument();
  });
});
