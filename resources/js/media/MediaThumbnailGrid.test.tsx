import { fireEvent, render, screen } from '@testing-library/react';

import { MediaThumbnailGrid } from '@/media/MediaThumbnailGrid';
import type { MediaItem } from '@/media/types';

jest.mock('@/components/protected-image', () => ({
  ProtectedImage: ({ alt }: { alt: string }) => <img alt={alt} />,
}));

function item(id: number, title: string, changes: Partial<MediaItem> = {}): MediaItem {
  return {
    id, ulid: `media-${id}`, character_id: null, type: 'photo', purpose: 'gallery', title,
    mime_type: 'image/jpeg', size_bytes: 100, audience: 'everyone', discoverable: true,
    upload_status: 'ready', url: `/photo-${id}`, thumbnail_url: `/thumb-${id}`, video: null,
    interests: [], character: null, created_at: null, ...changes,
  };
}

const items = [item(1, 'Toast'), item(2, 'First dance'), item(3, 'Still uploading', { upload_status: 'pending' })];

describe('MediaThumbnailGrid', () => {
  it('renders caption-free square tiles that open each item', () => {
    render(<MediaThumbnailGrid items={items} getHref={(media) => `/events?item=${media.ulid}`} />);

    expect(screen.getByRole('link', { name: 'Open Toast' })).toHaveAttribute('href', '/events?item=media-1');
    expect(screen.getByRole('img', { name: 'First dance' }).closest('li')).toHaveClass('aspect-square');
    expect(screen.queryByText('Toast')).toBeNull();
    expect(screen.queryByRole('checkbox')).toBeNull();
  });

  it('toggles selection instead of opening while selecting, skipping unselectable items', () => {
    const onSelectionChange = jest.fn();
    const { rerender } = render(<MediaThumbnailGrid items={items} getHref={(media) => `/m/${media.ulid}`} selecting selectedIds={[]} onSelectionChange={onSelectionChange} canSelect={(media) => media.upload_status === 'ready'} />);

    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.queryByRole('checkbox', { name: 'Select Still uploading' })).toBeNull();
    fireEvent.click(screen.getByRole('checkbox', { name: 'Select First dance' }));
    expect(onSelectionChange).toHaveBeenCalledWith([2]);

    rerender(<MediaThumbnailGrid items={items} getHref={(media) => `/m/${media.ulid}`} selecting selectedIds={[2]} onSelectionChange={onSelectionChange} canSelect={(media) => media.upload_status === 'ready'} />);
    expect(screen.getByRole('checkbox', { name: 'Select First dance' })).toBeChecked();
    fireEvent.click(screen.getByRole('checkbox', { name: 'Select First dance' }));
    expect(onSelectionChange).toHaveBeenLastCalledWith([]);
  });
});
