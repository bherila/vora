import { render, screen } from '@testing-library/react';

import { PersonaProfilePage } from './persona-profile';

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
    get: jest.fn(() => Promise.resolve({ data: { media: 0, stories: 0, posts: 0 } })),
    post: jest.fn(() => Promise.resolve({})),
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
  it('leads with the persona and names the owner only as quiet meta when Linked', () => {
    setInitialData(personaData());
    render(<PersonaProfilePage />);

    expect(screen.getByText('Kira')).toBeInTheDocument();
    expect(screen.getByText('A wandering star-cartographer.')).toBeInTheDocument();
    const ownerLink = screen.getByRole('link', { name: 'Ben' });
    expect(ownerLink).toHaveAttribute('href', '/users/7');
    // Visitors get Save + Report, not owner management.
    expect(screen.getByRole('button', { name: /save/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /report/i })).toBeInTheDocument();
  });

  it('shows no owner meta at all for a Separate persona', () => {
    setInitialData(personaData({ is_linked: false, owner: null }));
    render(<PersonaProfilePage />);

    expect(screen.getByText('Kira')).toBeInTheDocument();
    expect(screen.queryByText(/a persona of/i)).toBeNull();
    expect(screen.queryByRole('link', { name: 'Ben' })).toBeNull();
  });

  it('gives the owner a manage affordance instead of save/report', () => {
    setInitialData(personaData({ is_owner: true, can_report: false, owner: { display_name: 'Ben', href: '/me' } }));
    render(<PersonaProfilePage />);

    expect(screen.getByRole('link', { name: /manage on your profile/i })).toHaveAttribute('href', '/me');
    expect(screen.queryByRole('button', { name: /report/i })).toBeNull();
    expect(screen.queryByRole('button', { name: /save/i })).toBeNull();
  });
});
