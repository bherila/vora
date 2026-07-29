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

export interface MediaCharacter {
  id: number;
  display_name: string;
}

export interface EditableMedia {
  title: string | null;
  character_id: number | null;
  audience: Audience;
  audience_user_ids: number[];
  discoverable: boolean;
  characters: MediaCharacter[];
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
  character_id: number | null;
  type: MediaTypeValue;
  purpose: MediaPurposeValue;
  title: string | null;
  /** Owner/admin responses only; visitor payloads deliberately omit it. */
  original_filename?: string;
  mime_type: string;
  size_bytes: number | null;
  audience: Audience;
  discoverable: boolean;
  upload_status: string;
  /** Owner-only signal that the item isn't visible to others yet (awaiting review). */
  under_review?: boolean;
  url: string | null;
  /** Signed URL of the small JPEG thumbnail/poster, when one was generated. */
  thumbnail_url: string | null;
  video: VideoStatus | null;
  interests: MediaInterest[];
  character: MediaCharacter | null;
  created_at: string | null;
  /** Present only on the single-media view: whether the current viewer saved it. */
  favorited?: boolean;
  /** Present on the single-media view: how many users have saved this item. */
  favorite_count?: number;
  /** Present on the single-media view: the uploader, for the profile frame. */
  owner?: MediaOwner | null;
  /** Present on the single-media view: whether the viewer may report this item. */
  can_report?: boolean;
  /** Owner-management shape; its absence is the visitor authorization boundary. */
  editable?: EditableMedia;
}

/** The uploader of a single media item, used to frame the detail page. */
export interface MediaOwner {
  /** Null when a Separate persona is the deliberate public attribution. */
  id: number | null;
  display_name: string;
  avatar_url: string | null;
  /** Profile URL (/me for the owner, /users/{id} otherwise). */
  href: string;
  is_self: boolean;
}

/** Selected media-type filter for a listing. 'all' applies no type constraint. */
export type MediaTypeFilter = 'all' | MediaTypeValue;

/** Admin list responses additionally carry the internal review state. */
export interface AdminMediaItem extends MediaItem {
  original_filename: string;
  moderation_status: ModerationStatusValue;
  moderation_notes: string | null;
  moderated_at: string | null;
  moderated_by_user_id: number | null;
  download_url: string | null;
  /** Admin-only dedup signals. */
  file_hash: string | null;
  duplicate_of_media_id: number | null;
  cross_account_duplicates?: {
    other_account_count: number;
    match_count: number;
    matches: {
      media_id: number;
      media_href: string;
      account_id: number;
      account_name: string | null;
      account_email: string | null;
      account_href: string;
      distance: number;
    }[];
  };
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
