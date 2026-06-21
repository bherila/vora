import { ChevronDown, Paperclip, Send, X } from 'lucide-react';
import { type FormEvent, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';
import { type Audience } from '@/lib/audience';
import type { MediaItem, PagedResponse } from '@/media/types';
import type { StorySummary } from '@/stories/types';

import { communityApi } from './api';
import { AudienceField } from './AudienceField';
import type { AttachmentType, CharacterRef, CommunityPost } from './types';

interface CharacterResponse {
  success: boolean;
  data: CharacterRef[];
}

interface InterestItem {
  id: number;
  name: string;
}

interface InterestsResponse {
  success: boolean;
  data: InterestItem[];
}

interface StoriesResponse {
  success: boolean;
  data: StorySummary[];
}

interface SelectableAttachment {
  type: AttachmentType;
  id: number;
  label: string;
}

interface PostComposerProps {
  onCreated: (post: CommunityPost) => void;
}

const ATTACHMENT_TYPES: Array<{ value: AttachmentType; label: string }> = [
  { value: 'character', label: 'Character' },
  { value: 'interest', label: 'Interest' },
  { value: 'media', label: 'Media' },
  { value: 'story', label: 'Story' },
];

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : 'Request failed.';
}

export function PostComposer({ onCreated }: PostComposerProps) {
  const [body, setBody] = useState('');
  const [audience, setAudience] = useState<Audience>('everyone');
  const [audienceUserIds, setAudienceUserIds] = useState<number[]>([]);
  const [discoverable, setDiscoverable] = useState(true);
  const [characters, setCharacters] = useState<CharacterRef[]>([]);
  const [media, setMedia] = useState<MediaItem[]>([]);
  const [stories, setStories] = useState<StorySummary[]>([]);
  const [interests, setInterests] = useState<InterestItem[]>([]);
  const [characterId, setCharacterId] = useState<number | ''>('');
  const [attachmentType, setAttachmentType] = useState<AttachmentType>('character');
  const [attachmentId, setAttachmentId] = useState<number | ''>('');
  const [attachments, setAttachments] = useState<SelectableAttachment[]>([]);
  const [saving, setSaving] = useState(false);
  const [showOptions, setShowOptions] = useState(false);

  // Summarise any non-default options so the collapsed toggle still tells the
  // user what their post will do.
  const optionsSummary = useMemo((): string => {
    const parts: string[] = [];
    if (audience !== 'everyone') parts.push(audience === 'specific' ? 'Specific people' : audience === 'followers' ? 'Followers' : 'Mutuals');
    if (characterId !== '') {
      const persona = characters.find((character) => character.id === characterId);
      if (persona) parts.push(`As ${persona.display_name}`);
    }
    if (attachments.length > 0) parts.push(`${attachments.length} attachment${attachments.length === 1 ? '' : 's'}`);
    return parts.join(' · ');
  }, [audience, characterId, characters, attachments.length]);

  useEffect(() => {
    let active = true;

    Promise.allSettled([
      fetchWrapper.get('/api/characters') as Promise<CharacterResponse>,
      fetchWrapper.get('/api/media') as Promise<PagedResponse<MediaItem>>,
      fetchWrapper.get('/api/stories') as Promise<StoriesResponse>,
      fetchWrapper.get('/api/interests') as Promise<InterestsResponse>,
    ]).then((results) => {
      if (!active) return;

      const [characterResult, mediaResult, storyResult, interestResult] = results;
      if (characterResult.status === 'fulfilled') setCharacters(characterResult.value.data);
      if (mediaResult.status === 'fulfilled') setMedia(mediaResult.value.data);
      if (storyResult.status === 'fulfilled') setStories(storyResult.value.data);
      if (interestResult.status === 'fulfilled') setInterests(interestResult.value.data);
    });

    return () => {
      active = false;
    };
  }, []);

  const attachmentOptions = useMemo((): SelectableAttachment[] => {
    switch (attachmentType) {
      case 'character':
        return characters.map((character) => ({ type: 'character', id: character.id, label: character.display_name }));
      case 'interest':
        return interests.map((interest) => ({ type: 'interest', id: interest.id, label: interest.name }));
      case 'media':
        return media.map((item) => ({ type: 'media', id: item.id, label: item.title ?? item.original_filename }));
      case 'story':
        return stories.map((story) => ({ type: 'story', id: story.id, label: story.title }));
      default:
        return [];
    }
  }, [attachmentType, characters, interests, media, stories]);

  const addAttachment = (): void => {
    if (attachmentId === '') return;
    const selected = attachmentOptions.find((option) => option.id === attachmentId);
    if (!selected) return;
    if (attachments.some((item) => item.type === selected.type && item.id === selected.id)) return;
    if (attachments.length >= 4) {
      toast.error('Posts can have up to four attachments.');
      return;
    }
    setAttachments((current) => [...current, selected]);
    setAttachmentId('');
  };

  const submit = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();
    const trimmed = body.trim();
    if (!trimmed) {
      toast.error('Write something before posting.');
      return;
    }
    if (audience === 'specific' && audienceUserIds.length === 0) {
      toast.error('Choose at least one person for a specific-people post.');
      return;
    }

    setSaving(true);
    try {
      const created = await communityApi.createPost({
        body: trimmed,
        audience,
        discoverable,
        audience_user_ids: audience === 'specific' ? audienceUserIds : [],
        character_id: characterId === '' ? null : characterId,
        attachments: attachments.map((attachment) => ({ type: attachment.type, id: attachment.id })),
      });
      onCreated(created);
      setBody('');
      setAudience('everyone');
      setAudienceUserIds([]);
      setDiscoverable(true);
      setCharacterId('');
      setAttachments([]);
      setShowOptions(false);
      toast.success('Post published.');
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>New post</CardTitle>
      </CardHeader>
      <CardContent>
        <form className="space-y-4" onSubmit={(event) => void submit(event)}>
          <Textarea value={body} onChange={(event) => setBody(event.target.value)} rows={5} placeholder="Share an update" disabled={saving} />

          <button
            type="button"
            className="flex w-full items-center justify-between gap-2 rounded-md border border-border px-3 py-2 text-sm hover:bg-muted"
            onClick={() => setShowOptions((value) => !value)}
            aria-expanded={showOptions}
            disabled={saving}
          >
            <span className="flex items-center gap-2 text-muted-foreground">
              <Paperclip className="h-4 w-4" />
              {optionsSummary || 'Audience, persona & attachments'}
            </span>
            <ChevronDown className={`h-4 w-4 shrink-0 transition-transform ${showOptions ? 'rotate-180' : ''}`} />
          </button>

          {showOptions && (
          <div className="space-y-4">
          <div className="grid gap-4 md:grid-cols-2">
            <AudienceField
              audience={audience}
              onAudienceChange={setAudience}
              selectedUserIds={audienceUserIds}
              onSelectedUserIdsChange={setAudienceUserIds}
              disabled={saving}
            />
            <div className="space-y-2">
              <Label htmlFor="post-character">Post as</Label>
              <select
                id="post-character"
                className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                value={characterId}
                onChange={(event) => setCharacterId(event.target.value === '' ? '' : Number(event.target.value))}
                disabled={saving}
              >
                <option value="">My user profile</option>
                {characters.map((character) => (
                  <option key={character.id} value={character.id}>{character.display_name}</option>
                ))}
              </select>
              <label className="flex items-center gap-2 text-sm">
                <Checkbox checked={discoverable} onCheckedChange={(checked) => setDiscoverable(checked === true)} disabled={saving} />
                <span>Discoverable when the audience allows it</span>
              </label>
            </div>
          </div>
          <div className="space-y-2 rounded-md border border-border p-3">
            <div className="flex items-center gap-2 text-sm font-medium">
              <Paperclip className="h-4 w-4" />
              Attachments
            </div>
            <div className="grid gap-2 md:grid-cols-[160px_minmax(0,1fr)_auto]">
              <select
                className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                value={attachmentType}
                onChange={(event) => {
                  setAttachmentType(event.target.value as AttachmentType);
                  setAttachmentId('');
                }}
                disabled={saving}
              >
                {ATTACHMENT_TYPES.map((type) => (
                  <option key={type.value} value={type.value}>{type.label}</option>
                ))}
              </select>
              <select
                className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                value={attachmentId}
                onChange={(event) => setAttachmentId(event.target.value === '' ? '' : Number(event.target.value))}
                disabled={saving}
              >
                <option value="">Choose item</option>
                {attachmentOptions.map((option) => (
                  <option key={`${option.type}-${option.id}`} value={option.id}>{option.label}</option>
                ))}
              </select>
              <Button type="button" variant="outline" onClick={addAttachment} disabled={saving || attachmentId === ''}>Add</Button>
            </div>
            {attachments.length > 0 && (
              <div className="flex flex-wrap gap-2">
                {attachments.map((attachment) => (
                  <span key={`${attachment.type}-${attachment.id}`} className="inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 text-sm">
                    {attachment.label}
                    <button
                      type="button"
                      onClick={() => setAttachments((current) => current.filter((item) => item !== attachment))}
                      className="rounded-sm p-0.5 hover:bg-muted"
                      aria-label={`Remove ${attachment.label}`}
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </span>
                ))}
              </div>
            )}
          </div>
          </div>
          )}
          <Button type="submit" disabled={saving || body.trim().length === 0}>
            <Send className="mr-2 h-4 w-4" />
            {saving ? 'Posting...' : 'Post'}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
