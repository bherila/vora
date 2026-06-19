import { fetchWrapper } from '@/fetchWrapper';

import type { CommunityPost, Envelope, FeedResponse, PostComment } from './types';

export interface PostInput {
  body: string;
  audience: string;
  discoverable: boolean;
  audience_user_ids: number[];
  character_id: number | null;
  attachments: Array<{ type: string; id: number }>;
}

export const communityApi = {
  feed: (cursor?: string | null): Promise<FeedResponse> => {
    const suffix = cursor ? `?cursor=${encodeURIComponent(cursor)}` : '';
    return fetchWrapper.get(`/api/feed${suffix}`) as Promise<FeedResponse>;
  },
  postByUlid: (ulid: string): Promise<CommunityPost> =>
    fetchWrapper.get(`/api/posts/by-ulid/${encodeURIComponent(ulid)}`).then((r) => (r as Envelope<CommunityPost>).data),
  createPost: (input: PostInput): Promise<CommunityPost> =>
    fetchWrapper.post('/api/posts', input).then((r) => (r as Envelope<CommunityPost>).data),
  react: (postId: number): Promise<{ reaction_count: number; viewer_reacted: boolean }> =>
    fetchWrapper.post(`/api/posts/${postId}/reactions`, { type: 'like' }).then((r) => (r as Envelope<{ reaction_count: number; viewer_reacted: boolean }>).data),
  unreact: (postId: number): Promise<{ reaction_count: number; viewer_reacted: boolean }> =>
    fetchWrapper.delete(`/api/posts/${postId}/reactions`, { type: 'like' }).then((r) => (r as Envelope<{ reaction_count: number; viewer_reacted: boolean }>).data),
  comments: (postId: number): Promise<PostComment[]> =>
    fetchWrapper.get(`/api/posts/${postId}/comments`).then((r) => (r as Envelope<PostComment[]>).data),
  comment: (postId: number, body: string, parentId: number | null = null): Promise<PostComment> =>
    fetchWrapper.post(`/api/posts/${postId}/comments`, { body, parent_id: parentId }).then((r) => (r as Envelope<PostComment>).data),
  deleteComment: (postId: number, commentId: number): Promise<void> =>
    fetchWrapper.delete(`/api/posts/${postId}/comments/${commentId}`).then(() => undefined),
};
