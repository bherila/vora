export type StoryType = 'long_form' | 'cyoa';
export type StoryStatus = 'draft' | 'published';
export type Visibility = 'users' | 'unlisted';
export type AuthorStatus = 'pending' | 'accepted';

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
  visibility: Visibility;
  owner: { id: number; display_name: string } | null;
  interests: InterestTag[];
  involves: InvolvementTag[];
  authors: StoryAuthorRef[];
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
}
