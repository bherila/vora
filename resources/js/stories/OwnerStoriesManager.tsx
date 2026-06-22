import { BookOpen, GitBranch, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

import { storiesApi } from './api';
import type { StorySummary, StoryType } from './types';

/** Open the dedicated full-page editor (handles long-form prose and CYOA graphs). */
function editorHref(id: number): string {
  return `/stories?edit=${id}`;
}

function ReviewBadge({ story }: { story: StorySummary }) {
  if (story.status !== 'published') return null;
  const className = story.review.status === 'approved'
    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
    : story.review.status === 'rejected'
      ? 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200'
      : 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200';
  return <span className={`rounded px-2 py-0.5 ${className}`}>{story.review.label}</span>;
}

function StoryCard({ story, currentUserId, onTogglePublish, onDelete, busy }: {
  story: StorySummary;
  currentUserId: number;
  onTogglePublish: (story: StorySummary) => void;
  onDelete: (story: StorySummary) => void;
  busy: boolean;
}) {
  const isOwner = story.authors.some((a) => a.is_owner && a.user_id === currentUserId);
  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-2">
        <CardTitle className="text-base">{story.title}</CardTitle>
        <span className="inline-flex items-center gap-1 rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground">
          {story.type === 'cyoa' ? <GitBranch className="h-3 w-3" /> : <BookOpen className="h-3 w-3" />}
          {story.type === 'cyoa' ? 'Adventure' : 'Long form'}
        </span>
      </CardHeader>
      <CardContent className="space-y-2 text-sm">
        <div className="flex flex-wrap items-center gap-2 text-xs">
          <span className={`rounded px-2 py-0.5 ${story.status === 'published' ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' : 'bg-muted text-muted-foreground'}`}>
            {story.status}
          </span>
          {story.audience !== 'everyone' && <span className="rounded bg-muted px-2 py-0.5 text-muted-foreground">{story.audience}</span>}
          {!story.discoverable && <span className="rounded bg-muted px-2 py-0.5 text-muted-foreground">link-only</span>}
          <ReviewBadge story={story} />
          {!isOwner && <span className="rounded bg-muted px-2 py-0.5 text-muted-foreground">co-author</span>}
        </div>
        {story.involves.length > 0 && (
          <p className="text-xs text-muted-foreground">Involves: {story.involves.map((v) => v.name).join(', ')}</p>
        )}
        {story.interests.length > 0 && (
          <p className="text-xs text-muted-foreground">Interests: {story.interests.map((i) => i.name).join(', ')}</p>
        )}
        <div className="flex flex-wrap items-center gap-2 pt-1">
          <Button type="button" size="sm" asChild><a href={editorHref(story.id)}>Edit</a></Button>
          <a className="text-sm underline underline-offset-4" href={`/s/${story.ulid}`}>Read</a>
          {/* Only the owner can publish/unpublish or delete. */}
          {isOwner && (
            <>
              <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => onTogglePublish(story)}>
                {story.status === 'published' ? 'Unpublish' : 'Publish'}
              </Button>
              <Button type="button" size="sm" variant="ghost" disabled={busy} onClick={() => onDelete(story)}>Delete</Button>
            </>
          )}
        </div>
      </CardContent>
    </Card>
  );
}

interface OwnerStoriesManagerProps {
  /** The profile owner (and current viewer, since this only renders for self). */
  currentUserId: number;
}

/**
 * The owner's story library + create flow, embedded in their profile's Stories
 * tab. Editing (long-form prose and the CYOA graph) happens on the dedicated
 * /stories?edit=<id> editor page, which this links to. Stories are user-level
 * (not character-scoped), so this shows all of the owner's stories.
 */
export function OwnerStoriesManager({ currentUserId }: OwnerStoriesManagerProps) {
  const [stories, setStories] = useState<StorySummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);
  const [newTitle, setNewTitle] = useState('');
  const [newType, setNewType] = useState<StoryType>('long_form');
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = (): void => {
    storiesApi.list()
      .then(setStories)
      .catch((e) => toast.error(typeof e === 'string' ? e : 'Could not load stories.'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  const create = async (): Promise<void> => {
    if (newTitle.trim() === '') return;
    try {
      const created = await storiesApi.create({ title: newTitle.trim(), type: newType });
      // Go straight to the editor for the new story.
      window.location.href = editorHref(created.id);
    } catch (e) {
      toast.error(typeof e === 'string' ? e : 'Could not create story.');
    }
  };

  const togglePublish = async (story: StorySummary): Promise<void> => {
    setBusyId(story.id);
    try {
      await storiesApi.update(story.id, { status: story.status === 'published' ? 'draft' : 'published' });
      toast.success(story.status === 'published' ? 'Story unpublished.' : 'Story published — it will be reviewed before others can read it.');
      load();
    } catch (e) {
      toast.error(typeof e === 'string' ? e : 'Could not update the story.');
    } finally {
      setBusyId(null);
    }
  };

  const remove = async (story: StorySummary): Promise<void> => {
    if (!window.confirm(`Delete “${story.title}”? It will be hidden and retained for admin recovery.`)) return;
    setBusyId(story.id);
    try {
      await storiesApi.remove(story.id);
      setStories((current) => current.filter((s) => s.id !== story.id));
      toast.success('Story deleted.');
    } catch (e) {
      toast.error(typeof e === 'string' ? e : 'Could not delete the story.');
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Button type="button" size="sm" onClick={() => setCreating((v) => !v)}>
          <Plus className="mr-1 h-4 w-4" /> New story
        </Button>
      </div>

      {creating && (
        <Card>
          <CardContent className="space-y-3 pt-6">
            <Input value={newTitle} placeholder="Story title" onChange={(e) => setNewTitle(e.target.value)} />
            <div className="flex flex-wrap items-center gap-4">
              <label className="flex items-center gap-2 text-sm">
                <input type="radio" name="story-type" checked={newType === 'long_form'} onChange={() => setNewType('long_form')} />
                Long form
              </label>
              <label className="flex items-center gap-2 text-sm">
                <input type="radio" name="story-type" checked={newType === 'cyoa'} onChange={() => setNewType('cyoa')} />
                Choose your own adventure
              </label>
            </div>
            <div className="flex items-center gap-2">
              <Button type="button" onClick={() => void create()} disabled={newTitle.trim() === ''}>Create &amp; edit</Button>
              <Button type="button" variant="outline" onClick={() => setCreating(false)}>Cancel</Button>
            </div>
          </CardContent>
        </Card>
      )}

      {loading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : stories.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border px-6 py-12 text-center text-sm text-muted-foreground">
          You have no stories yet. Use “New story” to write one.
        </p>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2">
          {stories.map((story) => (
            <StoryCard
              key={story.id}
              story={story}
              currentUserId={currentUserId}
              onTogglePublish={(s) => void togglePublish(s)}
              onDelete={(s) => void remove(s)}
              busy={busyId === story.id}
            />
          ))}
        </div>
      )}
    </div>
  );
}
