import type { MediaItem } from '@/media/types';

export interface AccountStorageUsage {
  active_bytes: number;
  uploaded_bytes: number;
  saved_bytes: number;
  retained_bytes: number;
  total_bytes: number;
  active_file_count: number;
  retained_file_count: number;
  unknown_size_file_count: number;
}

export const EMPTY_STORAGE_USAGE: AccountStorageUsage = {
  active_bytes: 0, uploaded_bytes: 0, saved_bytes: 0, retained_bytes: 0,
  total_bytes: 0, active_file_count: 0, retained_file_count: 0, unknown_size_file_count: 0,
};

export interface LibraryPersona {
  ulid: string;
  display_name: string;
  href?: string;
}

export interface LibraryCollection {
  ulid: string;
  name: string;
  description: string | null;
  owned: boolean;
  member_ids: number[];
  related_persona: LibraryPersona | null;
  related_persona_unavailable: boolean;
}

export interface LibraryItem {
  ulid: string;
  title: string | null;
  body: string | null;
  source: 'upload' | 'saved' | 'note';
  owned: boolean;
  available: boolean;
  member_ids: number[];
  collection_ulids: string[];
  media: MediaItem | null;
  created_at: string | null;
}

export function libraryError(error: unknown): string {
  return typeof error === 'string' ? error : error instanceof Error ? error.message : 'Request failed. Please try again.';
}
