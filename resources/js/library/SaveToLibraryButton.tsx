import { useId, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import { instanceProfile } from '@/instance';
import { type LibraryCollection, libraryError, type LibraryItem } from '@/library/types';

interface SaveToLibraryButtonProps {
  mediaUlid?: string;
  itemUlid?: string;
  savedUlid?: string | null;
  onSaved?: () => void;
}

export function SaveToLibraryButton({ mediaUlid, itemUlid, savedUlid = null, onSaved }: SaveToLibraryButtonProps) {
  const eventInstance = instanceProfile() === 'event';
  const destination = eventInstance ? 'my collection' : 'Library';
  const descriptionId = useId();
  const saving = useRef(false);
  const [open, setOpen] = useState(false);
  const [collections, setCollections] = useState<LibraryCollection[]>([]);
  const [collection, setCollection] = useState('');
  const [saved, setSaved] = useState(savedUlid);
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const loadCollections = async (): Promise<void> => {
    setLoading(true);
    setError('');
    try {
      const response = await fetchWrapper.get('/api/library/collections') as { data: LibraryCollection[] };
      setCollections(response.data.filter((entry) => entry.owned));
    } catch (error) {
      setError(libraryError(error));
    } finally {
      setLoading(false);
    }
  };

  const save = async (): Promise<void> => {
    if (saving.current || saved) return;
    saving.current = true;
    setBusy(true);
    setError('');
    try {
      const response = await fetchWrapper.post('/api/library/save', {
        ...(mediaUlid ? { media_ulid: mediaUlid } : { item_ulid: itemUlid }),
        collection_ulid: null,
      }) as { data: LibraryItem };
      setSaved(response.data.ulid);
      toast.success('Saved privately. You’ll keep access here.');
      onSaved?.();
    } catch (error) {
      setError(libraryError(error));
    } finally {
      saving.current = false;
      setBusy(false);
    }
  };

  const organize = async (): Promise<void> => {
    if (saving.current || !saved || !collection) return;
    saving.current = true;
    setBusy(true);
    setError('');
    try {
      await fetchWrapper.post(`/api/library/items/${encodeURIComponent(saved)}/collections`, { collection_ulid: collection });
      setOpen(false);
      toast.success('Added to collection. Your saved item stays private.');
    } catch (error) {
      setError(libraryError(error));
    } finally {
      saving.current = false;
      setBusy(false);
    }
  };

  return <div className="grid gap-1">
    <div className="flex flex-wrap items-center gap-2">
      {saved ? <>
        <a href={`/library?item=${encodeURIComponent(saved)}`} className="text-sm underline underline-offset-4">{eventInstance ? 'View in my collection' : 'Open in Library'}</a>
        <Button type="button" size="sm" variant="outline" onClick={() => { setOpen(true); void loadCollections(); }}>Organize</Button>
      </> : <Button type="button" size="sm" disabled={busy} aria-describedby={descriptionId} onClick={() => void save()}>{busy ? 'Saving…' : `Save to ${destination}`}</Button>}
    </div>
    <p id={descriptionId} className="sr-only">Keep private access here even if the uploader removes the item or stops sharing it. This does not download it to your device.</p>
    {error && !open && <p role="alert" className="text-sm text-destructive">{error}</p>}
    <Dialog open={open} onOpenChange={(value) => { if (!busy) setOpen(value); }}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Organize your saved item</DialogTitle>
          <DialogDescription>Already saved privately. Adding it to a collection is optional.</DialogDescription>
        </DialogHeader>
        {error && <div role="alert" className="grid gap-2 text-sm text-destructive"><p>{error}</p>{collections.length === 0 && <Button variant="outline" disabled={loading} onClick={() => void loadCollections()}>Retry loading collections</Button>}</div>}
        {loading ? <p role="status">Loading collections…</p> : collections.length > 0 ? <>
          <Label htmlFor={`${descriptionId}-collection`}>Collection</Label>
          <select id={`${descriptionId}-collection`} className="h-10 rounded-md border border-input bg-background px-3" value={collection} onChange={(event) => setCollection(event.target.value)} disabled={busy}>
            <option value="">Choose a collection</option>
            {collections.map((entry) => <option key={entry.ulid} value={entry.ulid}>{entry.name}{entry.member_ids.length > 0 ? ' (shared)' : ''}</option>)}
          </select>
        </> : !error && <p className="text-sm">You don’t have any collections yet. <a className="underline" href="/library">Create one in {eventInstance ? 'My collection' : 'your Library'}</a> whenever you like.</p>}
        <p className="text-sm text-muted-foreground">Items saved from someone else stay private to you, including in shared collections. You keep access if the uploader removes the item, deletes their account, or stops sharing it.</p>
        <DialogFooter>
          <Button variant="outline" disabled={busy} onClick={() => setOpen(false)}>Done</Button>
          {collections.length > 0 && <Button disabled={busy || loading || !collection} onClick={() => void organize()}>{busy ? 'Adding…' : 'Add to collection'}</Button>}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>;
}
