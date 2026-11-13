import { ArrowLeft, ExternalLink } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { InterestPicker } from '@/components/interest-picker';
import { Markdown } from '@/components/Markdown';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useIdentityStore } from '@/identity';
import { AUDIENCE_SELECT_OPTIONS } from '@/lib/audience';

import { storiesApi } from './api';
import { CoAuthorPanel } from './CoAuthorPanel';
import { CyoaGraphEditor } from './CyoaGraphEditor';
import type { Audience, StoryAuthorRef, StoryEditor } from './types';

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

function storyDetails(story: StoryEditor) {
  return {
    title: story.title, status: story.status, audience: story.audience, discoverable: story.discoverable,
    body: story.body ?? '', interestIds: story.interests.map((interest) => interest.id).sort((a, b) => a - b),
    involved: story.involves.map((value) => involveKey(value.type, value.id)).sort(),
  };
}

export function StoryEditorPanel({ storyId, currentUserId, onBack, onChanged, onDeleted }: StoryEditorPanelProps) {
  const [story, setStory] = useState<StoryEditor | null>(null);
  const [loading, setLoading] = useState(true);
  const [title, setTitle] = useState('');
  const [status, setStatus] = useState<'draft' | 'published'>('draft');
  const [audience, setAudience] = useState<Audience>('everyone');
  const [discoverable, setDiscoverable] = useState(true);
  const [body, setBody] = useState('');
  const [interestIds, setInterestIds] = useState<number[]>([]);
  const [involved, setInvolved] = useState<Set<string>>(new Set());
  const [showPreview, setShowPreview] = useState(false);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const { identities } = useIdentityStore();
  const [identitySaving, setIdentitySaving] = useState(false);
  const [savedDetails, setSavedDetails] = useState<ReturnType<typeof storyDetails> | null>(null);
  const savedDetailsRef = useRef(savedDetails);
  const details = { title, status, audience, discoverable, body, interestIds: [...interestIds].sort((a, b) => a - b), involved: [...involved].sort() };
  const detailsDirty = savedDetails !== null && JSON.stringify(details) !== JSON.stringify(savedDetails);

  const hydrate = (data: StoryEditor): void => {
    setStory(data);
    setTitle(data.title);
    setStatus(data.status);
    setAudience(data.audience);
    setDiscoverable(data.discoverable);
    setBody(data.body ?? '');
    setInterestIds(data.interests.map((i) => i.id));
    setInvolved(new Set(data.involves.map((v) => involveKey(v.type, v.id))));
    const baseline = storyDetails(data);
    savedDetailsRef.current = baseline;
    setSavedDetails(baseline);
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

  const reconcileDetails = (updated: StoryEditor, submitted: ReturnType<typeof storyDetails>): void => {
    const canonical = storyDetails(updated);
    setStory(updated);
    savedDetailsRef.current = canonical;
    setSavedDetails(canonical);
    setTitle((current) => current === submitted.title ? canonical.title : current);
    setStatus((current) => current === submitted.status ? canonical.status : current);
    setAudience((current) => current === submitted.audience ? canonical.audience : current);
    setDiscoverable((current) => current === submitted.discoverable ? canonical.discoverable : current);
    setBody((current) => current === submitted.body ? canonical.body : current);
    setInterestIds((current) => JSON.stringify([...current].sort((a, b) => a - b)) === JSON.stringify(submitted.interestIds) ? canonical.interestIds : current);
    setInvolved((current) => JSON.stringify([...current].sort()) === JSON.stringify(submitted.involved) ? new Set(canonical.involved) : current);
  };

  const saveDetails = async (): Promise<void> => {
    if (story === null) return;
    const submitted = details;
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
        audience,
        discoverable,
        body: story.type === 'long_form' ? body : story.body,
        interest_ids: interestIds,
        involvements,
      });
      // Adopt canonical values only where the author has not edited since submit.
      reconcileDetails(updated, submitted);
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

  const updateAuthorIdentity = async (value: string): Promise<void> => {
    setIdentitySaving(true);
    setError('');
    try {
      const authors = await storiesApi.updateAuthorIdentity(
        storyId,
        currentUserId,
        value === '' ? null : Number(value),
      );
      onAuthorsChanged(authors);
    } catch (e) {
      setError(typeof e === 'string' ? e : 'Could not update your authoring identity.');
    } finally {
      setIdentitySaving(false);
    }
  };

  const isOwner = useMemo(() => story?.authors.some((a) => a.is_owner && a.user_id === currentUserId) ?? false, [story, currentUserId]);
  const currentAuthor = story?.authors.find((author) => author.user_id === currentUserId) ?? null;

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
            <CyoaGraphEditor key={story.id} story={story} onSaved={(updated) => {
              // A later completed details save owns its baseline. A graph response
              // captured before that save must not replace its values or clean state.
              if (savedDetails !== null && savedDetailsRef.current === savedDetails) reconcileDetails(updated, savedDetails);
              else setStory((current) => current ? { ...current, nodes: updated.nodes, choices: updated.choices, node_count: updated.node_count } : current);
              onChanged();
            }} />
          )}
        </div>

        {/* Sidebar */}
        <div className="space-y-4">
          {identities.length > 0 && currentAuthor && (
            <div className="grid gap-2">
              <label className="text-sm font-medium" htmlFor="story-author-identity">Writing as</label>
              <select
                id="story-author-identity"
                className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                value={currentAuthor.character_id ?? ''}
                onChange={(event) => void updateAuthorIdentity(event.target.value)}
                disabled={identitySaving}
              >
                {identities.map((identity) => (
                  <option key={identity.id ?? 'user'} value={identity.id ?? ''}>{identity.displayName}</option>
                ))}
              </select>
            </div>
          )}
          <div className="grid gap-2">
            <label className="text-sm font-medium" htmlFor="story-status">Status</label>
            <select id="story-status" className="h-9 rounded-md border border-input bg-background px-2 text-sm" value={status} onChange={(e) => setStatus(e.target.value as 'draft' | 'published')}>
              <option value="draft">Draft</option>
              <option value="published">Published</option>
            </select>
          </div>
          {story.status === 'published' && (
            <div className="grid gap-1 rounded-md border border-border p-3 text-sm">
              <span className="font-medium">Review status</span>
              <span className={story.review.status === 'rejected' ? 'text-destructive' : 'text-muted-foreground'}>{story.review.label}</span>
              {story.review.note && <p className="text-xs text-muted-foreground">{story.review.note}</p>}
            </div>
          )}
          <div className="grid gap-2">
            <label className="text-sm font-medium" htmlFor="story-audience">Who can see this?</label>
            <select id="story-audience" className="h-9 rounded-md border border-input bg-background px-2 text-sm" value={audience} onChange={(e) => setAudience(e.target.value as Audience)}>
              {AUDIENCE_SELECT_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={discoverable} onChange={(e) => setDiscoverable(e.target.checked)} />
              List in discovery (otherwise link-only)
            </label>
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
          {!saving && (detailsDirty || message) && <span className="text-sm text-muted-foreground">{detailsDirty ? 'Unsaved details.' : message}</span>}
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
