import { fetchWrapper } from '@/fetchWrapper';

import type { InvolvementTag, StoryEditor, StoryNode, StoryReader, StorySummary, StoryType, Visibility } from './types';

interface Envelope<T> {
  success: boolean;
  data: T;
  message?: string;
}

export interface CreateStoryInput {
  title: string;
  type: StoryType;
  body?: string | null;
  visibility?: Visibility;
}

export interface UpdateStoryInput {
  title?: string;
  status?: 'draft' | 'published';
  visibility?: Visibility;
  body?: string | null;
  interest_ids?: number[];
  involvements?: Array<{ type: string; id: number }>;
}

export const storiesApi = {
  list: () => fetchWrapper.get('/api/stories').then((r) => (r as Envelope<StorySummary[]>).data),
  get: (id: number) => fetchWrapper.get(`/api/stories/${id}`).then((r) => (r as Envelope<StoryEditor>).data),
  reader: (ulid: string) => fetchWrapper.get(`/api/stories/by-ulid/${ulid}`).then((r) => (r as Envelope<StoryReader>).data),
  create: (input: CreateStoryInput) => fetchWrapper.post('/api/stories', input).then((r) => (r as Envelope<StoryEditor>).data),
  update: (id: number, input: UpdateStoryInput) => fetchWrapper.patch(`/api/stories/${id}`, input).then((r) => (r as Envelope<StoryEditor>).data),
  remove: (id: number) => fetchWrapper.delete(`/api/stories/${id}`),
  saveGraph: (id: number, nodes: StoryNode[], choices: Array<{ from: string; to: string | null; label: string; position: number }>) =>
    fetchWrapper.put(`/api/stories/${id}/graph`, { nodes, choices }).then((r) => (r as Envelope<StoryEditor>).data),
  authors: (id: number) => fetchWrapper.get(`/api/stories/${id}/authors`).then((r) => (r as Envelope<StoryEditor['authors']>).data),
  invite: (id: number, userId: number) => fetchWrapper.post(`/api/stories/${id}/authors`, { user_id: userId }).then((r) => (r as Envelope<StoryEditor['authors']>).data),
  removeAuthor: (id: number, userId: number) => fetchWrapper.delete(`/api/stories/${id}/authors/${userId}`).then((r) => (r as Envelope<StoryEditor['authors']>).data),
};

export type { InvolvementTag };
