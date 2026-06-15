import { BookOpen, GitBranch, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

import { storiesApi } from './api';
import { StoryEditorPanel } from './StoryEditorPanel';
import type { StorySummary, StoryType } from './types';

function readCurrentUserId(): number {
  const script = document.getElementById('stories-initial-data');
  try {
    const data = JSON.parse(script?.textContent?.trim() ?? '{}') as { currentUserId?: number };
    return typeof data.currentUserId === 'number' ? data.currentUserId : 0;
  } catch {
    return 0;
  }
}

function StoryCard({ story, currentUserId, onEdit }: { story: StorySummary; currentUserId: number; onEdit: (id: number) => void }) {
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
          {story.visibility === 'unlisted' && <span className="rounded bg-muted px-2 py-0.5 text-muted-foreground">unlisted</span>}
          {!story.authors.some((a) => a.is_owner && a.user_id === currentUserId) && (
            <span className="rounded bg-muted px-2 py-0.5 text-muted-foreground">co-author</span>
          )}
        </div>
        {story.involves.length > 0 && (
          <p className="text-xs text-muted-foreground">Involves: {story.involves.map((v) => v.name).join(', ')}</p>
        )}
        {story.interests.length > 0 && (
          <p className="text-xs text-muted-foreground">Interests: {story.interests.map((i) => i.name).join(', ')}</p>
        )}
        <div className="flex items-center gap-3 pt-1">
          <Button type="button" size="sm" onClick={() => onEdit(story.id)}>Edit</Button>
          <a className="text-sm underline underline-offset-4" href={`/s/${story.ulid}`}>Read</a>
        </div>
      </CardContent>
    </Card>
  );
}

function StoriesPage() {
  const currentUserId = readCurrentUserId();
  const [stories, setStories] = useState<StorySummary[]>([]);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);
  const [newTitle, setNewTitle] = useState('');
  const [newType, setNewType] = useState<StoryType>('long_form');
  const [error, setError] = useState('');

  const load = (): void => {
    storiesApi.list().then(setStories).catch((e) => setError(typeof e === 'string' ? e : 'Could not load stories.'));
  };
  useEffect(load, []);

  const create = async (): Promise<void> => {
    if (newTitle.trim() === '') return;
    setError('');
    try {
      const created = await storiesApi.create({ title: newTitle.trim(), type: newType });
      setNewTitle('');
      setCreating(false);
      load();
      setEditingId(created.id);
    } catch (e) {
      setError(typeof e === 'string' ? e : 'Could not create story.');
    }
  };

  if (editingId !== null) {
    return (
      <div className="mx-auto max-w-5xl px-4 py-8">
        <StoryEditorPanel
          storyId={editingId}
          currentUserId={currentUserId}
          onBack={() => {
            setEditingId(null);
            load();
          }}
          onChanged={load}
          onDeleted={() => {
            setEditingId(null);
            load();
          }}
        />
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6 px-4 py-8">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Stories</h1>
        <Button type="button" onClick={() => setCreating((v) => !v)}>
          <Plus className="mr-1 h-4 w-4" /> New story
        </Button>
      </div>
      {error && <p className="text-sm text-destructive">{error}</p>}

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

      {stories.length === 0 ? (
        <p className="text-sm text-muted-foreground">You have no stories yet. Create one to get started.</p>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2">
          {stories.map((story) => (
            <StoryCard key={story.id} story={story} currentUserId={currentUserId} onEdit={setEditingId} />
          ))}
        </div>
      )}
    </div>
  );
}

const mount = document.getElementById('stories-app');
if (mount) createRoot(mount).render(<StoriesPage />);
