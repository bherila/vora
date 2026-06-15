import { ArrowLeft, ExternalLink } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { InterestPicker } from '@/components/interest-picker';
import { Markdown } from '@/components/Markdown';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

import { storiesApi } from './api';
import { CoAuthorPanel } from './CoAuthorPanel';
import { CyoaGraphEditor } from './CyoaGraphEditor';
import type { StoryAuthorRef, StoryEditor } from './types';

interface StoryEditorPanelProps {
  storyId: number;
  currentUserId: number;
  onBack: () => void;
  onChanged: () => void;
  onDeleted: () => void;
}

function involveKey(type: string, id: number): string {
  return `${type}:${id}`;
}

export function StoryEditorPanel({ storyId, currentUserId, onBack, onChanged, onDeleted }: StoryEditorPanelProps) {
  const [story, setStory] = useState<StoryEditor | null>(null);
  const [loading, setLoading] = useState(true);
  const [title, setTitle] = useState('');
  const [status, setStatus] = useState<'draft' | 'published'>('draft');
  const [visibility, setVisibility] = useState<'users' | 'unlisted'>('users');
  const [body, setBody] = useState('');
  const [interestIds, setInterestIds] = useState<number[]>([]);
  const [involved, setInvolved] = useState<Set<string>>(new Set());
  const [showPreview, setShowPreview] = useState(false);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const hydrate = (data: StoryEditor): void => {
    setStory(data);
    setTitle(data.title);
    setStatus(data.status);
    setVisibility(data.visibility);
    setBody(data.body ?? '');
    setInterestIds(data.interests.map((i) => i.id));
    setInvolved(new Set(data.involves.map((v) => involveKey(v.type, v.id))));
  };

  useEffect(() => {
    let active = true;
    setLoading(true);
    storiesApi
      .get(storyId)
      .then((data) => {
        if (active) hydrate(data);
      })
      .catch((e) => active && setError(typeof e === 'string' ? e : 'Could not load story.'))
      .finally(() => active && setLoading(false));
    return () => {
      active = false;
    };
  }, [storyId]);

  const toggleInvolve = (key: string): void => {
    setInvolved((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  };

  const saveDetails = async (): Promise<void> => {
    if (story === null) return;
    setSaving(true);
    setMessage('');
    setError('');
    try {
      const involvements = [...involved].map((k) => {
        const [type, id] = k.split(':');
        return { type: type ?? 'user', id: Number(id) };
      });
      const updated = await storiesApi.update(story.id, {
        title,
        status,
        visibility,
        body: story.type === 'long_form' ? body : story.body,
        interest_ids: interestIds,
        involvements,
      });
      hydrate(updated);
      setMessage('Saved.');
      onChanged();
    } catch (e) {
      setError(typeof e === 'string' ? e : 'Could not save.');
    } finally {
      setSaving(false);
    }
  };

  const remove = async (): Promise<void> => {
    if (story === null) return;
    if (!window.confirm('Delete this story permanently?')) return;
    try {
      await storiesApi.remove(story.id);
      onDeleted();
    } catch (e) {
      setError(typeof e === 'string' ? e : 'Could not delete.');
    }
  };

  const onAuthorsChanged = (authors: StoryAuthorRef[]): void => {
    setStory((prev) => (prev ? { ...prev, authors } : prev));
    onChanged();
    // Re-fetch so involvable options reflect new authors' characters.
    storiesApi.get(storyId).then((data) => setStory((prev) => (prev ? { ...prev, involvable_options: data.involvable_options } : prev))).catch(() => undefined);
  };

  const isOwner = useMemo(() => story?.authors.some((a) => a.is_owner && a.user_id === currentUserId) ?? false, [story, currentUserId]);

  if (loading) return <p className="text-sm text-muted-foreground">Loading story…</p>;
  if (story === null) return <p className="text-sm text-destructive">{error || 'Story not found.'}</p>;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <Button type="button" variant="ghost" size="sm" onClick={onBack}>
          <ArrowLeft className="mr-1 h-4 w-4" /> Back to stories
        </Button>
        <a className="inline-flex items-center text-sm underline underline-offset-4" href={`/s/${story.ulid}`} target="_blank" rel="noopener noreferrer">
          Open reader <ExternalLink className="ml-1 h-3 w-3" />
        </a>
      </div>

      <div className="grid gap-6 lg:grid-cols-[1fr_300px]">
        <div className="space-y-4">
          <div className="grid gap-2">
            <label className="text-sm font-medium" htmlFor="story-title">Title</label>
            <Input id="story-title" value={title} onChange={(e) => setTitle(e.target.value)} />
          </div>

          {story.type === 'long_form' ? (
            <div className="grid gap-2">
              <div className="flex items-center justify-between">
                <label className="text-sm font-medium" htmlFor="story-body">Story (markdown)</label>
                <Button type="button" variant="ghost" size="sm" onClick={() => setShowPreview((v) => !v)}>
                  {showPreview ? 'Edit' : 'Preview'}
                </Button>
              </div>
              {showPreview ? (
                <div className="rounded-md border border-border p-3">
                  <Markdown source={body} />
                </div>
              ) : (
                <Textarea id="story-body" value={body} rows={16} onChange={(e) => setBody(e.target.value)} placeholder="Write your story in markdown…" />
              )}
            </div>
          ) : (
            <CyoaGraphEditor story={story} onSaved={(updated) => hydrate(updated)} />
          )}
        </div>

        {/* Sidebar */}
        <div className="space-y-4">
          <div className="grid gap-2">
            <label className="text-sm font-medium" htmlFor="story-status">Status</label>
            <select id="story-status" className="h-9 rounded-md border border-input bg-background px-2 text-sm" value={status} onChange={(e) => setStatus(e.target.value as 'draft' | 'published')}>
              <option value="draft">Draft</option>
              <option value="published">Published</option>
            </select>
          </div>
          <div className="grid gap-2">
            <label className="text-sm font-medium" htmlFor="story-visibility">Visibility</label>
            <select id="story-visibility" className="h-9 rounded-md border border-input bg-background px-2 text-sm" value={visibility} onChange={(e) => setVisibility(e.target.value as 'users' | 'unlisted')}>
              <option value="users">Any user</option>
              <option value="unlisted">Only people with the link</option>
            </select>
          </div>

          <div className="grid gap-2">
            <span className="text-sm font-medium">Involves</span>
            {story.involvable_options.length === 0 ? (
              <p className="text-xs text-muted-foreground">No people or characters available.</p>
            ) : (
              <div className="max-h-40 overflow-y-auto rounded-md border border-input p-2">
                {story.involvable_options.map((opt) => {
                  const key = involveKey(opt.type, opt.id);
                  return (
                    <label key={key} className="flex items-center gap-2 py-1 text-sm">
                      <input type="checkbox" checked={involved.has(key)} onChange={() => toggleInvolve(key)} />
                      <span>
                        {opt.name}
                        {opt.type === 'character' && <span className="ml-1 text-xs text-muted-foreground">(character)</span>}
                      </span>
                    </label>
                  );
                })}
              </div>
            )}
          </div>

          <div className="grid gap-2">
            <span className="text-sm font-medium">Interests</span>
            <InterestPicker value={interestIds} onChange={setInterestIds} />
          </div>

          <CoAuthorPanel
            storyId={story.id}
            authors={story.authors}
            canManage={story.can_manage_authors}
            currentUserId={currentUserId}
            onChange={onAuthorsChanged}
          />
        </div>
      </div>

      <div className="flex items-center justify-between border-t border-border pt-4">
        <div className="flex items-center gap-3">
          <Button type="button" onClick={() => void saveDetails()} disabled={saving}>
            {saving ? 'Saving…' : 'Save details'}
          </Button>
          {message && <span className="text-sm text-muted-foreground">{message}</span>}
          {error && <span className="text-sm text-destructive">{error}</span>}
        </div>
        {isOwner && (
          <Button type="button" variant="destructive" onClick={() => void remove()}>
            Delete story
          </Button>
        )}
      </div>
    </div>
  );
}
