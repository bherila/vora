import { render, screen } from '@testing-library/react';

import { MediaPlayer } from '@/media/MediaPlayer';
import type { MediaItem } from '@/media/types';

jest.mock('@/media/HlsVideoPlayer', () => ({ HlsVideoPlayer: ({ src }: { src: string }) => <div data-testid="hls">{src}</div> }));
const video: MediaItem = { id: 1, ulid: 'video', character_id: null, type: 'video', purpose: 'event', title: null, mime_type: 'video/mp4', size_bytes: 5, audience: 'specific', discoverable: false, upload_status: 'ready', url: '/original.mp4', thumbnail_url: null, video: { status: 'processing', master_url: null }, interests: [], character: null, created_at: null };

it('distinguishes review from video preparation without promising a completion time', () => {
  const { rerender } = render(<MediaPlayer item={{ ...video, under_review: true }} />);
  expect(screen.getByText(/awaiting review/)).toBeInTheDocument();
  rerender(<MediaPlayer item={video} />);
  expect(screen.getByText('Video is still processing')).toBeInTheDocument();
  expect(screen.queryByText(/15–30/)).toBeNull();
  expect(screen.queryByTestId('hls')).toBeNull();
  rerender(<MediaPlayer item={{ ...video, video: { status: 'ready', master_url: '/ready.m3u8' } }} />);
  expect(screen.getByTestId('hls')).toHaveTextContent('/ready.m3u8');
  expect(screen.queryByText('/original.mp4')).toBeNull();
});
