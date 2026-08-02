import { Trash2 } from 'lucide-react';
import { type FormEvent, useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Avatar } from '@/components/avatar';
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

import { communityApi } from './api';
import type { PostComment } from './types';

interface CommentThreadProps {
  postId: number;
  initialCount: number;
}

function formatDate(value: string | null): string {
  if (!value) return '';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

export function CommentThread({ postId, initialCount }: CommentThreadProps) {
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

  useEffect(() => { void load(); }, [load]);

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
      await load();
      toast.success('Comment deleted.');
    } catch (err) {
      toast.error(typeof err === 'string' ? err : 'Could not delete comment.');
    }
  };

  const roots = comments.filter((comment) => comment.parent_id === null);
  const repliesFor = (parentId: number): PostComment[] => comments.filter((comment) => comment.parent_id === parentId);

  const row = (comment: PostComment, reply = false) => (
    <div key={comment.id} className={reply ? 'ml-4 rounded-md border border-border p-3' : 'space-y-2 rounded-md border border-border p-3'}>
      <div className="flex items-start justify-between gap-2">
        <div className="flex min-w-0 items-start gap-2">
          <Avatar name={comment.author?.display_name ?? 'Deleted user'} src={comment.author?.avatar_url} sizeClassName="h-7 w-7" />
          <div className="min-w-0">
            <p className="text-sm font-medium">{comment.author?.display_name ?? 'Deleted user'}</p>
            <p className="whitespace-pre-wrap text-sm">{comment.body}</p>
            <p className="text-xs text-muted-foreground">{formatDate(comment.created_at)}</p>
          </div>
        </div>
        {comment.can_delete && <Button type="button" size="sm" variant="ghost" onClick={() => setPendingDelete(comment)} title="Delete comment"><Trash2 className="h-4 w-4" /></Button>}
      </div>
      {!reply && <Button type="button" size="sm" variant="ghost" onClick={() => setReplyTo(comment)}>Reply</Button>}
      {!reply && repliesFor(comment.id).map((child) => row(child, true))}
    </div>
  );

  return (
    <div className="space-y-4 border-t border-border pt-4">
      {loading ? <p className="text-sm text-muted-foreground">Loading comments...</p> : comments.length === 0
        ? <p className="text-sm text-muted-foreground">{initialCount === 0 ? 'No comments yet.' : 'No visible comments.'}</p>
        : <div className="space-y-3">{roots.map((comment) => row(comment))}</div>}
      <form className="space-y-2" onSubmit={(event) => void submit(event)}>
        {replyTo && <div className="flex items-center justify-between rounded-md bg-muted px-3 py-2 text-sm"><span>Replying to {replyTo.author?.display_name ?? 'comment'}</span><Button type="button" size="sm" variant="ghost" onClick={() => setReplyTo(null)}>Cancel</Button></div>}
        <Textarea value={body} onChange={(event) => setBody(event.target.value)} placeholder="Write a comment" rows={3} />
        <Button type="submit" disabled={saving || body.trim().length === 0}>{saving ? 'Posting...' : 'Post comment'}</Button>
      </form>
      <AlertDialog open={pendingDelete !== null} onOpenChange={(open) => { if (!open) setPendingDelete(null); }}>
        <AlertDialogContent><AlertDialogHeader><AlertDialogTitle>Delete this comment?</AlertDialogTitle><AlertDialogDescription>Your contribution will be removed. Replies by other people will remain.</AlertDialogDescription></AlertDialogHeader><AlertDialogFooter><AlertDialogCancel>Cancel</AlertDialogCancel><AlertDialogAction onClick={() => void confirmDelete()}>Delete</AlertDialogAction></AlertDialogFooter></AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
