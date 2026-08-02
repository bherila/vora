import { fetchWrapper } from '@/fetchWrapper';

import type { CommunityPost, Envelope, FeedResponse, FeedScope, PostComment } from './types';

export class CommentApiError extends Error {
  constructor(message: string, public readonly status: number) {
    super(message);
    this.name = 'CommentApiError';
  }
}

export interface CommentThreadResponse {
  changed: boolean;
  etag: string | null;
  data?: PostComment[];
}

export interface PostInput {
  body: string;
  audience: string;
  discoverable: boolean;
  audience_user_ids: number[];
  character_id: number | null;
  context_interest_slug: string | null;
  attachments: Array<{ type: string; id: number }>;
}

export const communityApi = {
  dismissOnboarding: (): Promise<{ success: boolean }> =>
    fetchWrapper.post('/api/onboarding/dismiss', {}) as Promise<{ success: boolean }>,
  feed: (scope: FeedScope, cursor?: string | null, interest?: string | null): Promise<FeedResponse> => {
    const params = new URLSearchParams({ scope });
    if (cursor) params.set('cursor', cursor);
    if (interest) params.set('interest', interest);

    return fetchWrapper.get(`/api/feed?${params.toString()}`) as Promise<FeedResponse>;
  },
  postByUlid: (ulid: string): Promise<CommunityPost> =>
    fetchWrapper.get(`/api/posts/by-ulid/${encodeURIComponent(ulid)}`).then((r) => (r as Envelope<CommunityPost>).data),
  createPost: (input: PostInput): Promise<CommunityPost> =>
    fetchWrapper.post('/api/posts', input).then((r) => (r as Envelope<CommunityPost>).data),
  react: (postId: number): Promise<{ reaction_count: number; viewer_reacted: boolean }> =>
    fetchWrapper.post(`/api/posts/${postId}/reactions`, { type: 'like' }).then((r) => (r as Envelope<{ reaction_count: number; viewer_reacted: boolean }>).data),
  unreact: (postId: number): Promise<{ reaction_count: number; viewer_reacted: boolean }> =>
    fetchWrapper.delete(`/api/posts/${postId}/reactions`, { type: 'like' }).then((r) => (r as Envelope<{ reaction_count: number; viewer_reacted: boolean }>).data),
  comments: async (postId: number, etag: string | null = null): Promise<CommentThreadResponse> => {
    const response = await fetch(`/api/posts/${postId}/comments`, {
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(etag ? { 'If-None-Match': etag } : {}),
      },
    });

    if (response.status === 304) {
      return { changed: false, etag: response.headers.get('ETag') ?? etag };
    }
    if (!response.ok) {
      throw new CommentApiError(response.statusText || 'Could not refresh comments.', response.status);
    }

    const payload = await response.json() as Envelope<PostComment[]>;
    return { changed: true, etag: response.headers.get('ETag'), data: payload.data };
  },
  comment: (postId: number, body: string, parentId: number | null = null): Promise<PostComment> =>
    fetchWrapper.post(`/api/posts/${postId}/comments`, { body, parent_id: parentId }).then((r) => (r as Envelope<PostComment>).data),
  deleteComment: (postId: number, commentId: number): Promise<void> =>
    fetchWrapper.delete(`/api/posts/${postId}/comments/${commentId}`).then(() => undefined),
};
