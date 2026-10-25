import { BookOpen, Heart, MessageCircle, Play } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Avatar } from '@/components/avatar';
import { FavoriteButton } from '@/components/favorite-button';
import { ProtectedImage } from '@/components/protected-image';
import { ReportButton } from '@/components/report-button';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { moduleEnabled } from '@/instance';

import { communityApi } from './api';
import { CommentThread } from './CommentThread';
import type { CommunityPost, PostAttachment } from './types';

interface PostCardProps { post: CommunityPost; expanded?: boolean; readOnly?: boolean }

function formatDate(value: string | null): string {
  if (!value) return '';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

/** Compact age for the byline ("5m", "3h", "2d"); older posts show their date. */
export function relativeTime(value: string | null, now: number = Date.now()): string {
  if (!value) return '';

  const minutes = Math.floor((now - new Date(value).getTime()) / 60_000);
  if (minutes < 1) return 'now';
  if (minutes < 60) return `${minutes}m`;
  if (minutes < 60 * 24) return `${Math.floor(minutes / 60)}h`;
  if (minutes < 60 * 24 * 7) return `${Math.floor(minutes / (60 * 24))}d`;

  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value));
}

function attachmentHref(attachment: PostAttachment): string | null {
  if (attachment.type === 'media' && attachment.ulid) return `/m/${attachment.ulid}`;
  if (attachment.type === 'story' && attachment.ulid) return `/s/${attachment.ulid}`;
  return null;
}

interface MaybeLinkProps {
  href: string | null;
  className: string;
  children: ReactNode;
  label?: string;
}

function MaybeLink({ href, className, children, label }: MaybeLinkProps) {
  return href
    ? <a href={href} className={className} aria-label={label}>{children}</a>
    : <div className={className}>{children}</div>;
}

/** Media with a preview renders inline; anything else (no preview yet) falls back to a labelled chip. */
interface MediaGridProps {
  items: PostAttachment[];
  readOnly: boolean;
}

function MediaGrid({ items, readOnly }: MediaGridProps) {
  return (
    <div className={`grid gap-0.5 ${items.length > 1 ? 'grid-cols-2' : 'grid-cols-1'}`}>
      {items.map((attachment) => {
        const video = attachment.media_type === 'video';
        const label = attachment.label || (video ? 'Video' : 'Photo');

        return (
          <MaybeLink
            key={attachment.id}
            href={readOnly ? null : attachmentHref(attachment)}
            label={`Open ${video ? 'video' : 'photo'}: ${label}`}
            className={`relative block overflow-hidden bg-muted ${items.length > 1 ? 'aspect-square' : 'aspect-[4/3]'}`}
          >
            <ProtectedImage src={attachment.thumbnail_url ?? ''} alt={label} className="h-full w-full object-cover" loading="lazy" />
            {video && (
              <span className="absolute inset-0 flex items-center justify-center" aria-hidden="true">
                <span className="flex h-12 w-12 items-center justify-center rounded-full bg-black/60 text-white">
                  <Play className="h-5 w-5 fill-current" />
                </span>
              </span>
            )}
          </MaybeLink>
        );
      })}
    </div>
  );
}

export function PostCard({ post: initialPost, expanded = false, readOnly = false }: PostCardProps) {
  const [post, setPost] = useState(initialPost);
  const [showComments, setShowComments] = useState(expanded);
  const authorLabel = post.as_character?.display_name ?? post.author?.display_name ?? 'Unknown';
  const personaHref = !readOnly && post.as_character?.ulid ? `/c/${post.as_character.ulid}` : null;
  const avatarSrc = post.as_character ? post.as_character.avatar?.thumbnail_url ?? post.as_character.avatar?.url ?? null : post.author?.avatar_url ?? null;
  const previews = post.attachments.filter((attachment) => attachment.type === 'media' && attachment.thumbnail_url);
  const stories = post.attachments.filter((attachment) => attachment.type === 'story');
  const chips = post.attachments.filter((attachment) => !previews.includes(attachment) && !stories.includes(attachment));

  useEffect(() => setPost(initialPost), [initialPost]);

  const toggleReaction = async (): Promise<void> => {
    try {
      const summary = post.viewer_reacted ? await communityApi.unreact(post.id) : await communityApi.react(post.id);
      setPost((current) => ({ ...current, ...summary }));
    } catch (err) { toast.error(typeof err === 'string' ? err : 'Could not update reaction.'); }
  };

  const postHref = `/p/${encodeURIComponent(post.ulid)}`;
  const age = relativeTime(post.created_at);

  return (
    <article className="overflow-hidden rounded-xl border bg-card text-card-foreground">
      <header className="flex items-center gap-3 px-4 pt-4">
        <Avatar name={authorLabel} src={avatarSrc} sizeClassName="h-10 w-10" />
        <div className="min-w-0 flex-1">
          <p className="flex min-w-0 items-center gap-2 font-medium">
            <span className="truncate">{personaHref ? <a href={personaHref} className="hover:underline">{authorLabel}</a> : authorLabel}</span>
            {post.as_character && <Badge variant="outline" className="font-normal text-muted-foreground">Persona</Badge>}
          </p>
          <p className="truncate text-xs text-muted-foreground">
            {age && (readOnly
              ? <time dateTime={post.created_at ?? undefined} title={formatDate(post.created_at)}>{age}</time>
              : <a href={postHref} className="hover:underline"><time dateTime={post.created_at ?? undefined} title={formatDate(post.created_at)}>{age}</time></a>)}
            {age && post.context_interest && ' · '}
            {post.context_interest && (readOnly
              ? post.context_interest.name
              : <a href={`/interests/${post.context_interest.slug}`} className="hover:underline">{post.context_interest.name}</a>)}
          </p>
        </div>
        {!readOnly && (
          <div className="flex shrink-0 items-center gap-1">
            {post.can_report && <ReportButton type="post" id={post.id} variant="ghost" />}
            <a className="rounded-md px-2 py-1 text-sm text-muted-foreground hover:bg-muted hover:text-foreground" href={postHref}>Open</a>
          </div>
        )}
      </header>

      <p className="whitespace-pre-wrap px-4 pb-3 pt-2 text-[15px] leading-6">{post.body}</p>

      {previews.length > 0 && <MediaGrid items={previews} readOnly={readOnly} />}

      {stories.length > 0 && (
        <div className="space-y-2 px-4 pt-3">
          {stories.map((story) => (
            <MaybeLink
              key={story.id}
              href={readOnly ? null : attachmentHref(story)}
              className="flex items-center gap-3 rounded-lg border bg-muted/40 p-3 hover:bg-muted/70"
            >
              <span className="flex h-12 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground" aria-hidden="true">
                <BookOpen className="h-5 w-5" />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block text-xs uppercase tracking-wide text-muted-foreground">Story</span>
                <span className="block truncate font-medium">{story.label}</span>
              </span>
              {!readOnly && <span className="shrink-0 text-sm font-medium" aria-hidden="true">Read →</span>}
            </MaybeLink>
          ))}
        </div>
      )}

      {chips.length > 0 && (
        <div className="flex flex-wrap gap-2 px-4 pt-3">
          {chips.map((attachment) => {
            const href = readOnly ? null : attachmentHref(attachment);
            const label = attachment.type === 'character' ? `Character: ${attachment.label}` : `Media: ${attachment.label}`;

            return href
              ? <a key={`${attachment.type}-${attachment.id}`} href={href}><Badge variant="outline">{label}</Badge></a>
              : <Badge key={`${attachment.type}-${attachment.id}`} variant="outline">{label}</Badge>;
          })}
        </div>
      )}

      {readOnly ? <div className="pb-4" /> : (
        <footer className="space-y-3 px-2 pb-2 pt-2">
          <div className="flex items-center gap-1">
            <Button
              type="button"
              size="sm"
              variant="ghost"
              aria-pressed={post.viewer_reacted}
              aria-label={`${post.viewer_reacted ? 'Unlike' : 'Like'} (${post.reaction_count})`}
              className={post.viewer_reacted ? 'text-rose-600 dark:text-rose-400' : 'text-muted-foreground'}
              onClick={() => void toggleReaction()}
            >
              <Heart className={post.viewer_reacted ? 'fill-current' : ''} aria-hidden="true" />
              {post.reaction_count}
            </Button>
            <Button
              type="button"
              size="sm"
              variant="ghost"
              aria-expanded={showComments}
              aria-label={`Comments (${post.comment_count})`}
              className="text-muted-foreground"
              onClick={() => setShowComments((value) => !value)}
            >
              <MessageCircle aria-hidden="true" />
              {post.comment_count}
            </Button>
            <span className="flex-1" />
            {moduleEnabled('favorites') && post.favorited !== undefined && (
              <FavoriteButton type="post" id={post.id} initialFavorited={post.favorited} />
            )}
          </div>
          <div className="px-2">
            <CommentThread postId={post.id} initialCount={post.comment_count} enabled={showComments} />
          </div>
        </footer>
      )}
    </article>
  );
}
