import { useState } from 'react';

import { HeifOriginalReview } from '@/admin/HeifOriginalReview';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { eventError, type EventMediaItem } from '@/events/types';
import { fetchWrapper } from '@/fetchWrapper';

export type ReviewAction = 'approve' | 'reject';

interface ReviewPanelProps {
  item: EventMediaItem & { review: NonNullable<EventMediaItem['review']> };
  onModerated: (action: ReviewAction) => void;
}

/**
 * Approve or reject a pending Event upload from the viewer. Uses the admin
 * moderation endpoint, and keeps its rule that a HEIC/HEIF photo's stored
 * original is decoded and checked before approval.
 */
export function ReviewPanel({ item, onModerated }: ReviewPanelProps) {
  const [originalChecked, setOriginalChecked] = useState(!item.review.requires_original_check);
  const [noteOpen, setNoteOpen] = useState(false);
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState<ReviewAction | null>(null);
  const [error, setError] = useState('');

  const moderate = async (action: ReviewAction): Promise<void> => {
    if (action === 'approve' && !originalChecked) return;
    setBusy(action);
    setError('');
    try {
      await fetchWrapper.post(`/api/admin/media/${item.id}/moderate`, { action, notes: note.trim() || null });
      onModerated(action);
    } catch (failure) {
      setError(eventError(failure));
    } finally {
      setBusy(null);
    }
  };

  return <section aria-label="Review this upload" className="grid gap-3 rounded-md border border-amber-500/50 bg-amber-500/5 p-3">
    <p className="text-sm"><span className="font-medium">Awaiting review.</span> Only organizers and the uploader can see it until it is approved.</p>
    {item.review.requires_original_check && <>
      <HeifOriginalReview item={{ download_url: item.review.original_url, url: item.url, size_bytes: item.review.original_size_bytes, original_filename: item.review.original_filename, mime_type: item.mime_type }} onVerified={() => setOriginalChecked(true)} />
      {!originalChecked && <p className="text-xs text-muted-foreground">Check the stored HEIC original before approving.</p>}
    </>}
    {noteOpen
      ? <div className="grid gap-1"><Label htmlFor="review-note">Note for the review record (optional)</Label><Input id="review-note" maxLength={2000} value={note} onChange={(event) => setNote(event.target.value)} /></div>
      : <button type="button" className="w-fit text-xs underline underline-offset-4" onClick={() => setNoteOpen(true)}>Add a note</button>}
    {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
    <div className="grid grid-cols-2 gap-2">
      <Button variant="outline" disabled={busy !== null} onClick={() => void moderate('reject')}>{busy === 'reject' ? 'Rejecting…' : 'Reject'}</Button>
      <Button disabled={busy !== null || !originalChecked} onClick={() => void moderate('approve')}>{busy === 'approve' ? 'Approving…' : 'Approve'}</Button>
    </div>
  </section>;
}
