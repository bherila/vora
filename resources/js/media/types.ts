import type { Audience } from '@/lib/audience';

export type { Audience };
export type MediaTypeValue = 'photo' | 'video';
export type MediaPurposeValue = 'gallery' | 'profile_picture';
export type ModerationStatusValue = 'pending' | 'approved' | 'rejected';

export interface VideoStatus {
  status: 'processing' | 'ready' | 'not_applicable';
  /** App-relative URL of the HLS playback proxy master playlist when ready. */
  master_url: string | null;
}

export interface MediaInterest {
  id: number;
  name: string;
}

export interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  has_more: boolean;
}

export interface PagedResponse<T> {
  data: T[];
  meta?: PageMeta;
}

export interface MediaItem {
  id: number;
  ulid: string;
  type: MediaTypeValue;
  purpose: MediaPurposeValue;
  title: string | null;
  original_filename: string;
  mime_type: string;
  size_bytes: number | null;
  audience: Audience;
  discoverable: boolean;
  upload_status: string;
  url: string | null;
  /** Signed URL of the small JPEG thumbnail/poster, when one was generated. */
  thumbnail_url: string | null;
  video: VideoStatus | null;
  interests: MediaInterest[];
  created_at: string | null;
}

/** Selected media-type filter for a listing. 'all' applies no type constraint. */
export type MediaTypeFilter = 'all' | MediaTypeValue;

/** Admin list responses additionally carry the internal review state. */
export interface AdminMediaItem extends MediaItem {
  moderation_status: ModerationStatusValue;
  moderation_notes: string | null;
  moderated_at: string | null;
  moderated_by_user_id: number | null;
  user: { id: number; name: string | null; email: string | null };
}

export function mediaTypeForFile(file: File): MediaTypeValue | null {
  if (file.type.startsWith('image/')) {
    return 'photo';
  }
  if (file.type.startsWith('video/')) {
    return 'video';
  }
  return null;
}

export function formatBytes(bytes: number | null): string {
  if (bytes === null) {
    return '—';
  }
  if (bytes >= 1024 ** 3) {
    return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
  }
  if (bytes >= 1024 ** 2) {
    return `${(bytes / 1024 ** 2).toFixed(2)} MB`;
  }
  if (bytes >= 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`;
  }
  return `${bytes} B`;
}
