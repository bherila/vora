import type { Audience } from '@/lib/audience';

export type { Audience };
export type StoryType = 'long_form' | 'cyoa';
export type StoryStatus = 'draft' | 'published';
export type AuthorStatus = 'pending' | 'accepted';
export type StoryReviewStatus = 'pending' | 'approved' | 'rejected';

export interface InterestTag {
  id: number;
  name: string;
}

export interface InvolvementTag {
  type: 'user' | 'character';
  id: number;
  name: string;
}

export interface InvolvableOption {
  type: 'user' | 'character';
  id: number;
  name: string;
}

export interface StoryAuthorRef {
  id: number;
  user_id: number;
  display_name: string;
  role: 'owner' | 'co_author';
  status: AuthorStatus;
  is_owner: boolean;
}

export interface StoryReview {
  status: StoryReviewStatus;
  label: string;
  note: string | null;
}

export interface StoryNode {
  id?: number;
  key: string;
  title: string | null;
  body: string | null;
  is_start: boolean;
  position_x: number;
  position_y: number;
}

export interface StoryChoice {
  id?: number;
  from_node_id?: number | null;
  to_node_id?: number | null;
  // Editor-side key references used when saving the graph.
  from?: string;
  to?: string | null;
  label: string;
  position: number;
}

export interface StorySummary {
  id: number;
  ulid: string;
  title: string;
  type: StoryType;
  status: StoryStatus;
  audience: Audience;
  discoverable: boolean;
  owner: { id: number; display_name: string } | null;
  interests: InterestTag[];
  involves: InvolvementTag[];
  authors: StoryAuthorRef[];
  review: StoryReview;
  node_count: number | null;
  published_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface StoryEditor extends StorySummary {
  body: string | null;
  nodes: StoryNode[];
  choices: StoryChoice[];
  can_manage_authors: boolean;
  involvable_options: InvolvableOption[];
}

export interface StoryReader {
  id: number;
  ulid: string;
  title: string;
  type: StoryType;
  status: StoryStatus;
  body: string | null;
  owner: { id: number; display_name: string } | null;
  authors: StoryAuthorRef[];
  interests: InterestTag[];
  involves: InvolvementTag[];
  nodes: Array<StoryNode & { id: number }>;
  choices: Array<StoryChoice & { from_node_id: number; to_node_id: number | null }>;
  published_at: string | null;
  /** Whether the current viewer has saved this story. */
  favorited?: boolean;
  /** How many users have saved this story. */
  favorite_count?: number;
}

export interface StoryDiscoveryItem {
  id: number;
  ulid: string;
  title: string;
  type: StoryType;
  owner: { id: number; display_name: string } | null;
  authors: StoryAuthorRef[];
  interests: InterestTag[];
  node_count: number | null;
  published_at: string | null;
  /** Whether the current viewer has saved this story (present on listings). */
  favorited?: boolean;
}
