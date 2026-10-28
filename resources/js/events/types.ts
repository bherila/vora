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
  /** Finished Event uploads awaiting review; admins only. */
  pending_review_count?: number | null;
}

export interface EventMediaItem extends MediaItem {
  event: { ulid: string; name: string; timezone?: string } | null;
  /** Device capture time, when the upload reported one. */
  taken_at?: string | null;
  /** The gallery's ordering time: capture time, else upload time. */
  gallery_at?: string | null;
  uploader: { id: number; display_name: string };
  owned: boolean;
  download_url: string | null;
  favorited: boolean;
  photo_versions?: { visible_count: number; preferred_ulid: string; is_preferred: boolean } | null;
  /** Present for admins on finished uploads awaiting review. */
  review?: { pending: true; review_version: string; requires_original_check: boolean; original_url: string | null; original_size_bytes: number | null; original_filename: string } | null;
  photo_details?: { width: number | null; height: number | null; metadata_known: boolean; has_gps: boolean; has_capture_time: boolean; has_camera: boolean } | null;
}

export function eventError(error: unknown): string {
  return error instanceof Error ? error.message : typeof error === 'string' ? error : 'The request could not be completed.';
}
