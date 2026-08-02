import { Heart, MessageCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Avatar } from '@/components/avatar';
import { ReportButton } from '@/components/report-button';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader } from '@/components/ui/card';

import { communityApi } from './api';
import { CommentThread } from './CommentThread';
import type { CommunityPost, PostAttachment } from './types';

interface PostCardProps { post: CommunityPost; expanded?: boolean; readOnly?: boolean }

function formatDate(value: string | null): string {
  if (!value) return '';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

function attachmentHref(attachment: PostAttachment): string | null {
  if (attachment.type === 'media' && attachment.ulid) return `/m/${attachment.ulid}`;
  if (attachment.type === 'story' && attachment.ulid) return `/s/${attachment.ulid}`;
  return null;
}

function attachmentLabel(attachment: PostAttachment): string {
  if (attachment.type === 'character') return `Character: ${attachment.label}`;
  if (attachment.type === 'media') return `Media: ${attachment.label}`;
  return `Story: ${attachment.label}`;
}

export function PostCard({ post: initialPost, expanded = false, readOnly = false }: PostCardProps) {
  const [post, setPost] = useState(initialPost);
  const [showComments, setShowComments] = useState(expanded);
  const authorLabel = post.as_character?.display_name ?? post.author?.display_name ?? 'Unknown';
  const personaHref = !readOnly && post.as_character?.ulid ? `/c/${post.as_character.ulid}` : null;
  const avatarSrc = post.as_character ? post.as_character.avatar?.thumbnail_url ?? post.as_character.avatar?.url ?? null : post.author?.avatar_url ?? null;

  useEffect(() => setPost(initialPost), [initialPost]);

  const toggleReaction = async (): Promise<void> => {
    try {
      const summary = post.viewer_reacted ? await communityApi.unreact(post.id) : await communityApi.react(post.id);
      setPost((current) => ({ ...current, ...summary }));
    } catch (err) { toast.error(typeof err === 'string' ? err : 'Could not update reaction.'); }
  };

  return (
    <Card>
      <CardHeader className="space-y-2"><div className="flex items-start justify-between gap-3"><div className="flex min-w-0 items-center gap-3"><Avatar name={authorLabel} src={avatarSrc} sizeClassName="h-9 w-9" /><div className="min-w-0"><p className="font-medium">{personaHref ? <a href={personaHref} className="hover:underline">{authorLabel}</a> : authorLabel}</p><p className="text-xs text-muted-foreground">{formatDate(post.created_at)}</p></div></div><div className="flex shrink-0 items-center gap-2">{!readOnly && post.can_report && <ReportButton type="post" id={post.id} variant="ghost" />}{!readOnly && <a className="text-sm underline underline-offset-4" href={`/p/${encodeURIComponent(post.ulid)}`}>Open</a>}</div></div></CardHeader>
      <CardContent className="space-y-3">
        {post.context_interest && (
          <a href={`/interests/${post.context_interest.slug}`}>
            <Badge variant="secondary">{post.context_interest.name}</Badge>
          </a>
        )}
        <p className="whitespace-pre-wrap text-sm leading-6">{post.body}</p>
        {post.attachments.length > 0 && <div className="flex flex-wrap gap-2">{post.attachments.map((attachment) => { const href = readOnly ? null : attachmentHref(attachment); const label = attachmentLabel(attachment); return href ? <a key={`${attachment.type}-${attachment.id}`} href={href}><Badge variant="outline">{label}</Badge></a> : <Badge key={`${attachment.type}-${attachment.id}`} variant="outline">{label}</Badge>; })}</div>}
      </CardContent>
      {!readOnly && <CardFooter className="flex-col items-stretch gap-4"><div className="flex items-center gap-2"><Button type="button" size="sm" variant={post.viewer_reacted ? 'default' : 'outline'} onClick={() => void toggleReaction()}><Heart className="mr-2 h-4 w-4" />{post.reaction_count}</Button><Button type="button" size="sm" variant="outline" onClick={() => setShowComments((value) => !value)}><MessageCircle className="mr-2 h-4 w-4" />{post.comment_count}</Button></div>{showComments && <CommentThread postId={post.id} initialCount={post.comment_count} />}</CardFooter>}
    </Card>
  );
}
