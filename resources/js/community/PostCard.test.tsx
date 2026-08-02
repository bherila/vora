import { render, screen } from '@testing-library/react';

import { PostCard } from '@/community/PostCard';
import type { CommunityPost } from '@/community/types';

jest.mock('@/components/report-button', () => ({
  ReportButton: () => <button type="button">Report post</button>,
}));
jest.mock('sonner', () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

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
  it('suppresses every mutation affordance in a profile preview', () => {
    render(<PostCard post={post} readOnly />);

    expect(screen.queryByRole('link', { name: 'Open' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Vex' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Media: Portrait' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Story: A tale' })).toBeNull();
    expect(screen.getByText('Vex')).toBeInTheDocument();
    expect(screen.getByText('Media: Portrait')).toBeInTheDocument();
    expect(screen.getByText('Story: A tale')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Report post' })).toBeNull();
    expect(screen.queryByRole('button', { name: '2' })).toBeNull();
    expect(screen.queryByRole('button', { name: '3' })).toBeNull();
  });
});
