import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import { type LibraryCollection, libraryError, type LibraryItem } from '@/library/types';

interface SaveToLibraryButtonProps {
  mediaUlid?: string;
  itemUlid?: string;
  savedUlid?: string | null;
  onSaved?: () => void;
}

export function SaveToLibraryButton({ mediaUlid, itemUlid, savedUlid = null, onSaved }: SaveToLibraryButtonProps) {
  const [open, setOpen] = useState(false);
  const [collections, setCollections] = useState<LibraryCollection[]>([]);
  const [collection, setCollection] = useState('');
  const [saved, setSaved] = useState(savedUlid);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const openDialog = async (): Promise<void> => {
    setOpen(true);
    setError('');
    try {
      const response = await fetchWrapper.get('/api/library/collections') as { data: LibraryCollection[] };
      setCollections(response.data.filter((entry) => entry.owned));
    } catch (error) {
      setError(libraryError(error));
    }
  };

  const save = async (): Promise<void> => {
    setBusy(true);
    setError('');
    try {
      const response = await fetchWrapper.post('/api/library/save', {
        ...(mediaUlid ? { media_ulid: mediaUlid } : { item_ulid: itemUlid }),
        collection_ulid: collection || null,
      }) as { data: LibraryItem };
      setSaved(response.data.ulid);
      setOpen(false);
      toast.success('Saved to your Library.');
      onSaved?.();
    } catch (error) {
      setError(libraryError(error));
    } finally {
      setBusy(false);
    }
  };

  if (saved) {
    return <a href={`/library?item=${encodeURIComponent(saved)}`} className="text-sm underline underline-offset-4">Open in Library</a>;
  }

  return <>
    <Button type="button" size="sm" onClick={() => void openDialog()}>Save to Library</Button>
    <Dialog open={open} onOpenChange={(value) => { if (!busy) setOpen(value); }}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Save to your Library</DialogTitle>
          <DialogDescription>
            Keep access here even if the original uploader removes the item, leaves Vora, or stops sharing it with you.
          </DialogDescription>
        </DialogHeader>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <Label htmlFor="save-library-collection">Collection (optional)</Label>
        <select id="save-library-collection" className="h-10 rounded-md border border-input bg-background px-3" value={collection} onChange={(event) => setCollection(event.target.value)} disabled={busy}>
          <option value="">Just save to Library</option>
          {collections.map((entry) => <option key={entry.ulid} value={entry.ulid}>{entry.name}{entry.member_ids.length > 0 ? ' (shared)' : ''}</option>)}
        </select>
        <p className="text-sm text-muted-foreground">Items saved from someone else stay private to you, including in shared collections. Your own uploads and original notes can be shared.</p>
        <DialogFooter>
          <Button variant="outline" disabled={busy} onClick={() => setOpen(false)}>Cancel</Button>
          <Button disabled={busy} onClick={() => void save()}>{busy ? 'Saving…' : 'Save to Library'}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </>;
}
