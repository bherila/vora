import type { Audience } from '@/lib/audience';
import type { MediaItem } from '@/media/types';

export type AttachmentType = 'character' | 'media' | 'story';
export type FeedScope = 'following' | 'mixed';

export interface UserOption {
  id: number;
  display_name: string;
  restricted?: boolean;
}

export interface PostAttachment {
  type: AttachmentType;
  id: number;
  ulid?: string;
  media_type?: string;
  label: string;
}

export interface PostAuthor {
  id: number;
  display_name: string;
  avatar_url?: string | null;
}

export interface CharacterRef {
  id: number;
  display_name: string;
  /** Null when the viewer fails the persona's audience — render unlinked. */
  ulid?: string | null;
  avatar?: MediaItem | null;
}

export interface CommunityPost {
  id: number;
  ulid: string;
  body: string;
  audience: Audience;
  discoverable: boolean;
  /** Null for persona posts: the human author never reaches the client. */
  author: PostAuthor | null;
  as_character: CharacterRef | null;
  attachments: PostAttachment[];
  reaction_count: number;
  viewer_reacted: boolean;
  comment_count: number;
  created_at: string | null;
  /** Whether the current viewer may report this post (not the author). */
  can_report?: boolean;
}

export interface PostComment {
  id: number;
  parent_id: number | null;
  body: string;
  author: PostAuthor | null;
  created_at: string | null;
  can_delete?: boolean;
}

export interface FeedResponse {
  success: boolean;
  data: CommunityPost[];
  next_cursor: string | null;
}

export interface Envelope<T> {
  success: boolean;
  data: T;
  message?: string;
}
