import { ChevronDown, Globe, Lock, Paperclip, X } from 'lucide-react';
import { type FormEvent, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Avatar } from '@/components/avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';
import { useIdentityStore } from '@/identity';
import { moduleEnabled } from '@/instance';
import { type Audience } from '@/lib/audience';
import type { MediaItem, PagedResponse } from '@/media/types';
import type { StorySummary } from '@/stories/types';

import { communityApi } from './api';
import { AudienceField } from './AudienceField';
import type { AttachmentType, CharacterRef, CommunityPost, InterestRef } from './types';

interface InterestsResponse {
  success: boolean;
  data: InterestRef[];
}

interface CharacterResponse {
  success: boolean;
  data: CharacterRef[];
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
  contextInterest?: InterestRef | null;
  lockContext?: boolean;
}

const AUDIENCE_LABELS: Record<Audience, string> = {
  everyone: 'Everyone',
  followers: 'Followers',
  mutuals: 'Mutuals',
  specific: 'Specific people',
};

/** Attachment kinds follow the instance's modules; a disabled module offers nothing to attach. */
function attachmentTypes(): Array<{ value: AttachmentType; label: string }> {
  return [
    ...(moduleEnabled('personas') ? [{ value: 'character' as const, label: 'Character' }] : []),
    ...(moduleEnabled('profiles') ? [{ value: 'media' as const, label: 'Media' }] : []),
    ...(moduleEnabled('stories') ? [{ value: 'story' as const, label: 'Story' }] : []),
  ];
}

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : 'Request failed.';
}

function identityValue(id: number | null | ''): string {
  return id === null || id === '' ? 'user' : String(id);
}

/**
 * One-line composer that grows while writing. Who you post as lives on the
 * avatar, who can see it on the audience chip, and attachments behind the
 * paperclip, so the resting state is a single row.
 */
export function PostComposer({ onCreated, contextInterest = null, lockContext = false }: PostComposerProps) {
  const { activeIdentityId, identities } = useIdentityStore();
  const types = useMemo(() => attachmentTypes(), []);
  const personasEnabled = moduleEnabled('personas');
  const interestsEnabled = moduleEnabled('interests');
  const [body, setBody] = useState('');
  const [focused, setFocused] = useState(false);
  const [audience, setAudience] = useState<Audience>('everyone');
  const [audienceUserIds, setAudienceUserIds] = useState<number[]>([]);
  const [discoverable, setDiscoverable] = useState(true);
  const [characters, setCharacters] = useState<CharacterRef[]>([]);
  const [media, setMedia] = useState<MediaItem[]>([]);
  const [stories, setStories] = useState<StorySummary[]>([]);
  const [interests, setInterests] = useState<InterestRef[]>(contextInterest ? [contextInterest] : []);
  const [contextInterestSlug, setContextInterestSlug] = useState(contextInterest?.slug ?? '');
  const [characterId, setCharacterId] = useState<number | ''>(() => activeIdentityId ?? '');
  const [attachmentType, setAttachmentType] = useState<AttachmentType>(() => types[0]?.value ?? 'media');
  const [attachmentId, setAttachmentId] = useState<number | ''>('');
  const [attachments, setAttachments] = useState<SelectableAttachment[]>([]);
  const [saving, setSaving] = useState(false);
  const [showAudience, setShowAudience] = useState(false);
  const [showAttachments, setShowAttachments] = useState(false);

  const postingAs = identities.find((identity) => identity.id === (characterId === '' ? null : characterId))
    ?? identities.find((identity) => identity.id === null)
    ?? null;
  const postingAsName = postingAs?.displayName ?? 'you';
  const firstName = identities.find((identity) => identity.id === null)?.displayName.split(' ')[0];
  const context = interests.find((interest) => interest.slug === contextInterestSlug) ?? null;

  useEffect(() => {
    let active = true;

    Promise.allSettled([
      personasEnabled ? fetchWrapper.get('/api/characters') as Promise<CharacterResponse> : Promise.reject(new Error('off')),
      moduleEnabled('profiles') ? fetchWrapper.get('/api/media') as Promise<PagedResponse<MediaItem>> : Promise.reject(new Error('off')),
      moduleEnabled('stories') ? fetchWrapper.get('/api/stories') as Promise<StoriesResponse> : Promise.reject(new Error('off')),
      interestsEnabled ? fetchWrapper.get('/api/interests') as Promise<InterestsResponse> : Promise.reject(new Error('off')),
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
  }, [personasEnabled, interestsEnabled]);

  useEffect(() => {
    setCharacterId(activeIdentityId ?? '');
  }, [activeIdentityId]);

  // On the general feed the selected filter seeds, but does not lock, the
  // composer context. Keep that seed current when the filter changes so a post
  // cannot silently retain the previously browsed Interest.
  useEffect(() => {
    setContextInterestSlug(contextInterest?.slug ?? '');
  }, [contextInterest?.slug]);

  const attachmentOptions = useMemo((): SelectableAttachment[] => {
    switch (attachmentType) {
      case 'character':
        return characters.map((character) => ({ type: 'character', id: character.id, label: character.display_name }));
      case 'media':
        return media.map((item) => ({
          type: 'media',
          id: item.id,
          label: item.title ?? item.original_filename ?? 'Untitled media',
        }));
      case 'story':
        return stories.map((story) => ({ type: 'story', id: story.id, label: story.title }));
      default:
        return [];
    }
  }, [attachmentType, characters, media, stories]);

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
        context_interest_slug: contextInterestSlug === '' ? null : contextInterestSlug,
        attachments: attachments.map((attachment) => ({ type: attachment.type, id: attachment.id })),
      });
      onCreated(created);
      setBody('');
      setAudience('everyone');
      setAudienceUserIds([]);
      setDiscoverable(true);
      setCharacterId(activeIdentityId ?? '');
      if (!lockContext) setContextInterestSlug('');
      setAttachments([]);
      setShowAudience(false);
      setShowAttachments(false);
      toast.success('Post published.');
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  const expanded = focused || body !== '' || showAudience || showAttachments || attachments.length > 0;
  const avatar = <Avatar name={postingAsName} src={postingAs?.avatarUrl ?? null} sizeClassName="h-10 w-10" />;

  return (
    <form
      aria-label="New post"
      className="space-y-3 rounded-xl border bg-card p-3 text-card-foreground sm:p-4"
      onSubmit={(event) => void submit(event)}
    >
      <div className="flex items-start gap-3">
        {personasEnabled && identities.length > 1 ? (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button
                type="button"
                className="relative shrink-0 rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                aria-label={`Posting as ${postingAsName}. Change who this post is from`}
                disabled={saving}
              >
                {avatar}
                <span className="absolute -bottom-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full border bg-background text-muted-foreground">
                  <ChevronDown className="h-3 w-3" aria-hidden="true" />
                </span>
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-60">
              <DropdownMenuRadioGroup value={identityValue(characterId)}>
                <DropdownMenuLabel className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Post as</DropdownMenuLabel>
                {identities.map((identity) => (
                  <DropdownMenuRadioItem
                    key={identity.id ?? 'user'}
                    value={identityValue(identity.id)}
                    onClick={() => setCharacterId(identity.id ?? '')}
                  >
                    <Avatar name={identity.displayName} src={identity.avatarUrl} sizeClassName="h-6 w-6" />
                    <span className="truncate">{identity.displayName}</span>
                  </DropdownMenuRadioItem>
                ))}
              </DropdownMenuRadioGroup>
            </DropdownMenuContent>
          </DropdownMenu>
        ) : avatar}
        <Textarea
          id="post-composer-body"
          aria-label="Share an update"
          value={body}
          onChange={(event) => setBody(event.target.value)}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
          rows={expanded ? 4 : 1}
          placeholder={firstName ? `What's on your mind, ${firstName}?` : "What's on your mind?"}
          className="min-h-10 resize-none border-0 bg-transparent px-0 py-2 text-base shadow-none focus-visible:ring-0 dark:bg-transparent"
          disabled={saving}
        />
      </div>

      <div className="flex flex-wrap items-center gap-1 sm:pl-[3.25rem]">
        {types.length > 0 && (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            aria-expanded={showAttachments}
            onClick={() => setShowAttachments((value) => !value)}
            disabled={saving}
          >
            <Paperclip aria-hidden="true" />
            {attachments.length > 0 ? `Attach (${attachments.length})` : 'Attach'}
          </Button>
        )}
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="rounded-full"
          aria-expanded={showAudience}
          aria-label={`Audience: ${AUDIENCE_LABELS[audience]}`}
          onClick={() => setShowAudience((value) => !value)}
          disabled={saving}
        >
          {audience === 'everyone' ? <Globe aria-hidden="true" /> : <Lock aria-hidden="true" />}
          {AUDIENCE_LABELS[audience]}
          <ChevronDown aria-hidden="true" />
        </Button>
        {context && (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="rounded-full text-muted-foreground"
            aria-expanded={showAudience}
            onClick={() => setShowAudience(true)}
            disabled={saving}
          >
            In {context.name}
          </Button>
        )}
        <span className="flex-1" />
        <Button type="submit" size="sm" disabled={saving || body.trim().length === 0}>
          {saving ? 'Posting...' : 'Post'}
        </Button>
      </div>

      {showAudience && (
        <div className="grid gap-4 rounded-lg border p-3 sm:grid-cols-2">
          <AudienceField
            audience={audience}
            onAudienceChange={setAudience}
            selectedUserIds={audienceUserIds}
            onSelectedUserIdsChange={setAudienceUserIds}
            disabled={saving}
          />
          {interestsEnabled && (
            <div className="grid gap-2">
              <Label htmlFor="post-interest-context">Interest context</Label>
              <select
                id="post-interest-context"
                className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                value={contextInterestSlug}
                onChange={(event) => setContextInterestSlug(event.target.value)}
                disabled={saving || lockContext}
              >
                <option value="">No Interest context</option>
                {interests.map((interest) => <option key={interest.slug} value={interest.slug}>{interest.name}</option>)}
              </select>
            </div>
          )}
          <label className="flex items-center gap-2 text-sm sm:col-span-2">
            <Checkbox checked={discoverable} onCheckedChange={(checked) => setDiscoverable(checked === true)} disabled={saving} />
            <span>Discoverable when the audience allows it</span>
          </label>
        </div>
      )}

      {showAttachments && types.length > 0 && (
        <div className="space-y-2 rounded-lg border p-3">
          <div className="grid gap-2 md:grid-cols-[160px_minmax(0,1fr)_auto]">
            <select
              aria-label="Attachment type"
              className="h-9 rounded-md border border-input bg-background px-2 text-sm"
              value={attachmentType}
              onChange={(event) => {
                setAttachmentType(event.target.value as AttachmentType);
                setAttachmentId('');
              }}
              disabled={saving}
            >
              {types.map((type) => (
                <option key={type.value} value={type.value}>{type.label}</option>
              ))}
            </select>
            <select
              aria-label="Item to attach"
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
        </div>
      )}

      {attachments.length > 0 && (
        <div className="flex flex-wrap gap-2 sm:pl-[3.25rem]">
          {attachments.map((attachment) => (
            <span key={`${attachment.type}-${attachment.id}`} className="inline-flex items-center gap-1 rounded-md border px-2 py-1 text-sm">
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
    </form>
  );
}
