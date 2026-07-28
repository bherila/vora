import { BookOpen, Images, MessageSquare } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

import { PostCard } from '@/community/PostCard';
import type { CommunityPost } from '@/community/types';
import { Skeleton } from '@/components/ui/skeleton';
import { StoryGrid } from '@/explore/StoryGrid';
import { fetchWrapper } from '@/fetchWrapper';
import { MediaGrid } from '@/media/MediaGrid';
import type { MediaItem } from '@/media/types';
import type { StoryDiscoveryItem } from '@/stories/types';

/**
 * The read-only content tabs shared by the profile-as-container surfaces: a
 * user profile (/me and /users/{id}, per identity) and a persona's public page
 * (/c/{ulid}). Each tab takes the listing endpoint to fetch, so the same
 * component serves both the user-scoped and the persona-scoped APIs.
 */

/** Fetch a profile content listing; refetch when the endpoint changes. */
export function useProfileList<T>(endpoint: string | null): { items: T[]; loading: boolean; error: string } {
  const [items, setItems] = useState<T[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!endpoint) {
      setItems([]);
      setLoading(false);
      return;
    }
    let active = true;
    setLoading(true);
    setError('');
    fetchWrapper.get(endpoint)
      .then((response) => { if (active) setItems(((response as { data?: T[] }).data) ?? []); })
      .catch(() => { if (active) setError('Could not load this content.'); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [endpoint]);

  return { items, loading, error };
}

/** A grid of pulsing placeholders shown while a tab's content loads. */
export function GridSkeleton({ itemClassName = 'aspect-video' }: { itemClassName?: string }) {
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" role="status" aria-busy="true">
      <span className="sr-only">Loading…</span>
      {Array.from({ length: 6 }).map((_, index) => (
        <Skeleton key={index} className={`w-full ${itemClassName}`} />
      ))}
    </div>
  );
}

/** Stacked placeholders for list-shaped tabs (posts). */
export function ListSkeleton() {
  return (
    <div className="space-y-3" role="status" aria-busy="true">
      <span className="sr-only">Loading…</span>
      {Array.from({ length: 3 }).map((_, index) => (
        <Skeleton key={index} className="h-24 w-full" />
      ))}
    </div>
  );
}

/** A friendly, centered empty state with an icon and optional call to action. */
export function TabEmpty({ icon: Icon, title, action }: { icon: typeof Images; title: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border px-6 py-12 text-center">
      <span className="flex h-10 w-10 items-center justify-center rounded-full bg-muted text-muted-foreground">
        <Icon className="h-5 w-5" aria-hidden="true" />
      </span>
      <p className="max-w-sm text-sm text-muted-foreground">{title}</p>
      {action}
    </div>
  );
}

export function TabError({ message }: { message: string }) {
  return <p role="alert" className="rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive">{message}</p>;
}

interface ListTabProps {
  endpoint: string;
  emptyTitle: string;
  emptyAction?: ReactNode;
}

/** Read-only media grid for a profile or persona page. */
export function MediaListTab({ endpoint, emptyTitle }: ListTabProps) {
  const { items, loading, error } = useProfileList<MediaItem>(endpoint);
  if (loading) return <GridSkeleton />;
  if (error) return <TabError message={error} />;
  if (items.length === 0) return <TabEmpty icon={Images} title={emptyTitle} />;
  return <MediaGrid items={items} />;
}

export function StoriesListTab({ endpoint, emptyTitle }: ListTabProps) {
  const { items, loading, error } = useProfileList<StoryDiscoveryItem>(endpoint);
  if (loading) return <GridSkeleton />;
  if (error) return <TabError message={error} />;
  if (items.length === 0) return <TabEmpty icon={BookOpen} title={emptyTitle} />;
  return <StoryGrid items={items} />;
}

export function PostsListTab({ endpoint, emptyTitle, emptyAction }: ListTabProps) {
  const { items, loading, error } = useProfileList<CommunityPost>(endpoint);
  if (loading) return <ListSkeleton />;
  if (error) return <TabError message={error} />;
  if (items.length === 0) return <TabEmpty icon={MessageSquare} title={emptyTitle} action={emptyAction} />;
  return <div className="space-y-4">{items.map((post) => <PostCard key={post.id} post={post} />)}</div>;
}
