import { render, screen, waitFor } from '@testing-library/react';

import { PostCard, relativeTime } from '@/community/PostCard';
import type { CommunityPost } from '@/community/types';

jest.mock('@/components/report-button', () => ({
  ReportButton: () => <button type="button">Report post</button>,
}));
let mockFavoritesEnabled = true;
jest.mock('@/instance', () => ({ moduleEnabled: (module: string) => module !== 'favorites' || mockFavoritesEnabled }));
jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { post: jest.fn(() => Promise.resolve({ success: true })), delete: jest.fn() } }));
jest.mock('sonner', () => ({ toast: { success: jest.fn(), error: jest.fn() } }));
let mockRestrictions: Array<{ capability: string; label: string; reason: string | null; expires_at: string | null }> = [];
jest.mock('@/initialData', () => ({ readInitialData: () => ({ restrictions: mockRestrictions }) }));
jest.mock('@/community/api', () => ({
  communityApi: {
    comments: jest.fn(() => Promise.resolve({ changed: true, etag: '"post-card"', data: [] })),
    comment: jest.fn(),
    deleteComment: jest.fn(),
    react: jest.fn(),
    unreact: jest.fn(),
  },
}));

const post: CommunityPost = {
  id: 12,
  ulid: '01POST',
  body: 'A visible post',
  audience: 'everyone',
  discoverable: true,
  author: null,
  as_character: { id: 9, display_name: 'Vex', ulid: '01PERSONA' },
  attachments: [
    { type: 'media', id: 21, ulid: '01MEDIA', label: 'Portrait' },
    { type: 'story', id: 22, ulid: '01STORY', label: 'A tale' },
  ],
  context_interest: null,
  reaction_count: 2,
  viewer_reacted: false,
  comment_count: 3,
  created_at: null,
  can_report: true,
};

describe('PostCard', () => {
  beforeEach(() => {
    mockRestrictions = [];
    mockFavoritesEnabled = true;
  });

  it('suppresses every mutation affordance in a profile preview', () => {
    render(<PostCard post={post} readOnly />);

    expect(screen.queryByRole('link', { name: 'Open' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Vex' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Media: Portrait' })).toBeNull();
    expect(screen.queryByRole('link', { name: /A tale/ })).toBeNull();
    expect(screen.getByText('Vex')).toBeInTheDocument();
    expect(screen.getByText('Media: Portrait')).toBeInTheDocument();
    expect(screen.getByText('A tale')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Report post' })).toBeNull();
    expect(screen.queryByRole('button', { name: /Like/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /Comments/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /Save/ })).toBeNull();
  });

  it('keeps comments readable while replacing creation controls for a restricted user', async () => {
    mockRestrictions = [{
      capability: 'comment.create',
      label: 'Commenting',
      reason: 'Repeated abuse',
      expires_at: null,
    }];

    render(<PostCard post={{ ...post, comment_count: 0 }} expanded />);

    await waitFor(() => expect(screen.getByText('No comments yet.')).toBeInTheDocument());
    expect(screen.queryByPlaceholderText('Write a comment')).not.toBeInTheDocument();
    expect(screen.getByText(/Commenting restricted/)).toBeInTheDocument();
    expect(screen.getByText(/Repeated abuse/)).toBeInTheDocument();
  });

  it('shows media previews inline and stories as cards that open them', () => {
    render(<PostCard post={{
      ...post,
      attachments: [
        { type: 'media', id: 21, ulid: '01MEDIA', media_type: 'photo', label: 'Portrait', thumbnail_url: '/api/media/by-ulid/01MEDIA/asset/thumbnail' },
        { type: 'media', id: 23, ulid: '01VIDEO', media_type: 'video', label: 'Ridge', thumbnail_url: '/api/media/by-ulid/01VIDEO/asset/thumbnail' },
        { type: 'story', id: 22, ulid: '01STORY', label: 'A tale' },
      ],
    }} />);

    expect(screen.getByRole('img', { name: 'Portrait' })).toHaveAttribute('src', '/api/media/by-ulid/01MEDIA/asset/thumbnail');
    expect(screen.getByRole('link', { name: 'Open photo: Portrait' })).toHaveAttribute('href', '/m/01MEDIA');
    expect(screen.getByRole('link', { name: 'Open video: Ridge' })).toHaveAttribute('href', '/m/01VIDEO');
    expect(screen.queryByText('Media: Portrait')).toBeNull();
    expect(screen.getByRole('link', { name: /Story\s*A tale/ })).toHaveAttribute('href', '/s/01STORY');
  });

  it('falls back to a labelled chip for media without a preview yet', () => {
    render(<PostCard post={post} />);

    expect(screen.getByRole('link', { name: 'Media: Portrait' })).toHaveAttribute('href', '/m/01MEDIA');
    expect(screen.queryByRole('img', { name: 'Portrait' })).toBeNull();
  });

  it('offers Save only when the server reports saved state and Favorites is on', () => {
    const { rerender } = render(<PostCard post={post} />);
    expect(screen.queryByRole('button', { name: /Save/ })).toBeNull();

    rerender(<PostCard post={{ ...post, favorited: true }} />);
    expect(screen.getByRole('button', { name: 'Saved' })).toHaveAttribute('aria-pressed', 'true');

    mockFavoritesEnabled = false;
    rerender(<PostCard post={{ ...post, id: 13, favorited: false }} />);
    expect(screen.queryByRole('button', { name: /Save/ })).toBeNull();
  });

  it('puts the interest and a linked age in the byline', () => {
    render(<PostCard post={{ ...post, created_at: new Date(Date.now() - 3 * 3_600_000).toISOString(), context_interest: { name: 'Hiking', slug: 'hiking' } }} />);

    expect(screen.getByRole('link', { name: '3h' })).toHaveAttribute('href', '/p/01POST');
    expect(screen.getByRole('link', { name: 'Hiking' })).toHaveAttribute('href', '/interests/hiking');
    expect(screen.getByText('Persona')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Like (2)' })).toHaveAttribute('aria-pressed', 'false');
  });
});

describe('relativeTime', () => {
  const now = Date.parse('2026-10-25T12:00:00Z');

  it('compacts recent ages and dates older posts', () => {
    expect(relativeTime(null, now)).toBe('');
    expect(relativeTime('2026-10-25T11:59:40Z', now)).toBe('now');
    expect(relativeTime('2026-10-25T11:15:00Z', now)).toBe('45m');
    expect(relativeTime('2026-10-25T07:00:00Z', now)).toBe('5h');
    expect(relativeTime('2026-10-22T12:00:00Z', now)).toBe('3d');
    expect(relativeTime('2026-09-01T12:00:00Z', now)).not.toMatch(/^\d+[mhd]$/);
  });
});
