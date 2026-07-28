import { Heart, MessageCircle, Trash2 } from 'lucide-react';
import { type FormEvent, useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Avatar } from '@/components/avatar';
import { ReportButton } from '@/components/report-button';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';

import { communityApi } from './api';
import type { CommunityPost, PostAttachment, PostComment } from './types';

interface PostCardProps {
  post: CommunityPost;
  expanded?: boolean;
  readOnly?: boolean;
}

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
  switch (attachment.type) {
    case 'character':
      return `Character: ${attachment.label}`;
    case 'interest':
      return `Interest: ${attachment.label}`;
    case 'media':
      return `Media: ${attachment.label}`;
    case 'story':
      return `Story: ${attachment.label}`;
    default:
      return attachment.label;
  }
}

function CommentThread({ postId, initialCount }: { postId: number; initialCount: number }) {
  const [comments, setComments] = useState<PostComment[]>([]);
  const [body, setBody] = useState('');
  const [replyTo, setReplyTo] = useState<PostComment | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [pendingDelete, setPendingDelete] = useState<PostComment | null>(null);

  const load = useCallback(async (): Promise<void> => {
    setLoading(true);
    try {
      setComments(await communityApi.comments(postId));
    } catch (err) {
      toast.error(typeof err === 'string' ? err : 'Could not load comments.');
    } finally {
      setLoading(false);
    }
  }, [postId]);

  useEffect(() => {
    void load();
  }, [load]);

  const submit = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();
    const trimmed = body.trim();
    if (!trimmed) return;

    setSaving(true);
    try {
      const created = await communityApi.comment(postId, trimmed, replyTo?.id ?? null);
      setComments((current) => [...current, created]);
      setBody('');
      setReplyTo(null);
    } catch (err) {
      toast.error(typeof err === 'string' ? err : 'Could not add comment.');
    } finally {
      setSaving(false);
    }
  };

  const confirmDelete = async (): Promise<void> => {
    const comment = pendingDelete;
    if (!comment) return;
    setPendingDelete(null);

    try {
      await communityApi.deleteComment(postId, comment.id);
      setComments((current) => current.filter((item) => item.id !== comment.id && item.parent_id !== comment.id));
      toast.success('Comment deleted.');
    } catch (err) {
      toast.error(typeof err === 'string' ? err : 'Could not delete comment.');
    }
  };

  const roots = comments.filter((comment) => comment.parent_id === null);
  const repliesFor = (parentId: number): PostComment[] => comments.filter((comment) => comment.parent_id === parentId);

  return (
    <div className="space-y-4 border-t border-border pt-4">
      {loading ? (
        <p className="text-sm text-muted-foreground">Loading comments...</p>
      ) : comments.length === 0 ? (
        <p className="text-sm text-muted-foreground">{initialCount === 0 ? 'No comments yet.' : 'No visible comments.'}</p>
      ) : (
        <div className="space-y-3">
          {roots.map((comment) => (
            <div key={comment.id} className="space-y-2 rounded-md border border-border p-3">
              <div className="flex items-start justify-between gap-2">
                <div className="flex min-w-0 items-start gap-2">
                  <Avatar name={comment.author?.display_name ?? 'Deleted user'} src={comment.author?.avatar_url} sizeClassName="h-7 w-7" />
                  <div className="min-w-0">
                    <p className="text-sm font-medium">{comment.author?.display_name ?? 'Deleted user'}</p>
                    <p className="whitespace-pre-wrap text-sm">{comment.body}</p>
                    <p className="text-xs text-muted-foreground">{formatDate(comment.created_at)}</p>
                  </div>
                </div>
                {comment.can_delete && (
                  <Button type="button" size="sm" variant="ghost" onClick={() => setPendingDelete(comment)} title="Delete comment">
                    <Trash2 className="h-4 w-4" />
                  </Button>
                )}
              </div>
              <Button type="button" size="sm" variant="ghost" onClick={() => setReplyTo(comment)}>
                Reply
              </Button>
              {repliesFor(comment.id).map((reply) => (
                <div key={reply.id} className="ml-4 rounded-md border border-border p-3">
                  <div className="flex items-start justify-between gap-2">
                    <div className="flex min-w-0 items-start gap-2">
                      <Avatar name={reply.author?.display_name ?? 'Deleted user'} src={reply.author?.avatar_url} sizeClassName="h-7 w-7" />
                      <div className="min-w-0">
                        <p className="text-sm font-medium">{reply.author?.display_name ?? 'Deleted user'}</p>
                        <p className="whitespace-pre-wrap text-sm">{reply.body}</p>
                        <p className="text-xs text-muted-foreground">{formatDate(reply.created_at)}</p>
                      </div>
                    </div>
                    {reply.can_delete && (
                      <Button type="button" size="sm" variant="ghost" onClick={() => setPendingDelete(reply)} title="Delete comment">
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          ))}
        </div>
      )}
      <form className="space-y-2" onSubmit={(event) => void submit(event)}>
        {replyTo && (
          <div className="flex items-center justify-between rounded-md bg-muted px-3 py-2 text-sm">
            <span>Replying to {replyTo.author?.display_name ?? 'comment'}</span>
            <Button type="button" size="sm" variant="ghost" onClick={() => setReplyTo(null)}>Cancel</Button>
          </div>
        )}
        <Textarea value={body} onChange={(event) => setBody(event.target.value)} placeholder="Write a comment" rows={3} />
        <Button type="submit" disabled={saving || body.trim().length === 0}>{saving ? 'Posting...' : 'Post comment'}</Button>
      </form>
      <AlertDialog open={pendingDelete !== null} onOpenChange={(open) => { if (!open) setPendingDelete(null); }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete this comment?</AlertDialogTitle>
            <AlertDialogDescription>This can’t be undone. The comment and its replies will be removed.</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={() => void confirmDelete()}>Delete</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

export function PostCard({ post: initialPost, expanded = false, readOnly = false }: PostCardProps) {
  const [post, setPost] = useState(initialPost);
  const [showComments, setShowComments] = useState(expanded);
  // A persona post is bylined by the persona alone — the human never appears.
  // When the viewer fails the persona's audience the server sends the persona
  // name with no ulid, so the byline renders the name unlinked rather than
  // falling back to the human author.
  const authorLabel = post.as_character?.display_name ?? post.author?.display_name ?? 'Unknown';
  const personaHref = !readOnly && post.as_character?.ulid ? `/c/${post.as_character.ulid}` : null;
  const avatarName = post.as_character?.display_name ?? post.author?.display_name ?? 'Unknown';
  const avatarSrc = post.as_character
    ? post.as_character.avatar?.thumbnail_url ?? post.as_character.avatar?.url ?? null
    : post.author?.avatar_url ?? null;

  useEffect(() => {
    setPost(initialPost);
  }, [initialPost]);

  const toggleReaction = async (): Promise<void> => {
    try {
      const summary = post.viewer_reacted
        ? await communityApi.unreact(post.id)
        : await communityApi.react(post.id);
      setPost((current) => ({ ...current, ...summary }));
    } catch (err) {
      toast.error(typeof err === 'string' ? err : 'Could not update reaction.');
    }
  };

  return (
    <Card>
      <CardHeader className="space-y-2">
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <Avatar name={avatarName} src={avatarSrc} sizeClassName="h-9 w-9" />
            <div className="min-w-0">
              <p className="font-medium">
                {personaHref
                  ? <a href={personaHref} className="hover:underline">{authorLabel}</a>
                  : authorLabel}
              </p>
              <p className="text-xs text-muted-foreground">{formatDate(post.created_at)}</p>
            </div>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            {!readOnly && post.can_report && <ReportButton type="post" id={post.id} variant="ghost" />}
            {!readOnly && <a className="text-sm underline underline-offset-4" href={`/p/${post.ulid}`}>Open</a>}
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-3">
        <p className="whitespace-pre-wrap text-sm leading-6">{post.body}</p>
        {post.attachments.length > 0 && (
          <div className="flex flex-wrap gap-2">
            {post.attachments.map((attachment) => {
              const href = readOnly ? null : attachmentHref(attachment);
              const label = attachmentLabel(attachment);
              return href ? (
                <a key={`${attachment.type}-${attachment.id}`} href={href}>
                  <Badge variant="outline">{label}</Badge>
                </a>
              ) : (
                <Badge key={`${attachment.type}-${attachment.id}`} variant="outline">{label}</Badge>
              );
            })}
          </div>
        )}
      </CardContent>
      {!readOnly && <CardFooter className="flex-col items-stretch gap-4">
        <div className="flex items-center gap-2">
          <Button type="button" size="sm" variant={post.viewer_reacted ? 'default' : 'outline'} onClick={() => void toggleReaction()}>
            <Heart className="mr-2 h-4 w-4" />
            {post.reaction_count}
          </Button>
          <Button type="button" size="sm" variant="outline" onClick={() => setShowComments((value) => !value)}>
            <MessageCircle className="mr-2 h-4 w-4" />
            {post.comment_count}
          </Button>
        </div>
        {showComments && <CommentThread postId={post.id} initialCount={post.comment_count} />}
      </CardFooter>}
    </Card>
  );
}
