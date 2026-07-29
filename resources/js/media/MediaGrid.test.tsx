import { render, screen } from '@testing-library/react';

import { MediaGrid } from '@/media/MediaGrid';
import type { MediaItem } from '@/media/types';

jest.mock('@/components/protected-image', () => ({
  ProtectedImage: ({ alt }: { alt: string }) => <img alt={alt} />,
}));

function visitorItem(): MediaItem {
  return {
    id: 7,
    ulid: '01HZX5MEDIA',
    character_id: null,
    type: 'photo',
    purpose: 'gallery',
    title: null,
    mime_type: 'image/jpeg',
    size_bytes: 100,
    audience: 'everyone',
    discoverable: true,
    upload_status: 'ready',
    url: '/api/media/by-ulid/01HZX5MEDIA/asset/original',
    thumbnail_url: null,
    video: null,
    interests: [],
    character: null,
    created_at: null,
  };
}

describe('MediaGrid visitor fallbacks', () => {
  it('uses neutral copy when the visitor payload has no title or filename', () => {
    render(<MediaGrid items={[visitorItem()]} />);

    expect(screen.getByText('Untitled media')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Untitled media' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open Untitled media' })).toBeInTheDocument();
  });
});
