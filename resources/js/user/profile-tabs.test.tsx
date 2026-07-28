import { render, screen } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';
import { MediaListTab, StoriesListTab } from '@/user/profile-tabs';

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: { get: jest.fn() },
}));
jest.mock('@/components/protected-image', () => ({
  ProtectedImage: ({ alt }: { alt: string }) => <img alt={alt} />,
}));

describe('profile preview list tabs', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('renders media cards without detail links in read-only mode', async () => {
    (fetchWrapper.get as jest.Mock).mockResolvedValue({
      data: [{
        id: 21,
        ulid: '01MEDIA',
        character_id: null,
        type: 'photo',
        purpose: 'gallery',
        title: 'Portrait',
        original_filename: 'portrait.jpg',
        mime_type: 'image/jpeg',
        size_bytes: 100,
        audience: 'everyone',
        discoverable: true,
        upload_status: 'ready',
        under_review: false,
        url: '/media.jpg',
        thumbnail_url: '/thumb.jpg',
        video: null,
        interests: [],
        character: null,
        created_at: null,
      }],
    });

    render(<MediaListTab endpoint="/api/media" emptyTitle="Empty" readOnly />);

    expect(await screen.findByText('Portrait')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Open Portrait' })).toBeNull();
  });

  it('renders story cards without reader links in read-only mode', async () => {
    (fetchWrapper.get as jest.Mock).mockResolvedValue({
      data: [{
        id: 22,
        ulid: '01STORY',
        title: 'A tale',
        type: 'long_form',
        owner: { id: 7, display_name: 'Author' },
        authors: [],
        interests: [],
        node_count: null,
        published_at: null,
      }],
    });

    render(<StoriesListTab endpoint="/api/stories" emptyTitle="Empty" readOnly />);

    expect(await screen.findByText('A tale')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Read' })).toBeNull();
  });
});
