import type { MediaItem } from '@/media/types';

export interface EventAttendee {
  id: number;
  display_name: string;
  avatar_url?: string | null;
}

export interface EventProfile {
  ulid: string;
  name: string;
  description: string | null;
  visibility: 'everyone' | 'specific';
  timezone: string;
  starts_at: string | null;
  ends_at: string | null;
  can_manage: boolean;
  member_ids?: number[];
  member_count?: number;
}

export interface EventSettings {
  featured_curators: EventAttendee[];
  is_admin: boolean;
  viewer_id: number;
  uploads_require_review?: boolean;
}

export interface EventMediaItem extends MediaItem {
  event: { ulid: string; name: string } | null;
  uploader: { id: number; display_name: string };
  owned: boolean;
  download_url: string | null;
  favorited: boolean;
}

export function eventError(error: unknown): string {
  return error instanceof Error ? error.message : typeof error === 'string' ? error : 'The request could not be completed.';
}
