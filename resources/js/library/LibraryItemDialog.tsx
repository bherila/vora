import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { ProtectedImage } from '@/components/protected-image';
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';
import { instanceProfile } from '@/instance';
import { itemShareTarget, type ShareTarget } from '@/library/LibraryDialogs';
import { SaveToLibraryButton } from '@/library/SaveToLibraryButton';
import { type LibraryCollection, libraryError, type LibraryItem } from '@/library/types';
import { MediaPlayer } from '@/media/MediaPlayer';
import { formatBytes } from '@/media/types';

interface LibraryItemDialogProps {
  item: LibraryItem;
  collections: LibraryCollection[];
  enabled: boolean;
  onClose: () => void;
  onChanged: () => void;
  onShare: (target: ShareTarget) => void;
}

export function LibraryItemDialog({ item: initialItem, collections, enabled, onClose, onChanged, onShare }: LibraryItemDialogProps) {
  const eventInstance = instanceProfile() === 'event';
  const removeLabel = eventInstance ? 'Remove item' : 'Remove from Library';
  const [item, setItem] = useState(initialItem);
  const [editing, setEditing] = useState(false);
  const [title, setTitle] = useState(item.title ?? '');
  const [body, setBody] = useState(item.body ?? '');
  const [collection, setCollection] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [removeOpen, setRemoveOpen] = useState(false);
  const ownCollections = collections.filter((entry) => entry.owned);

  useEffect(() => { setItem(initialItem); }, [initialItem]);

  const refresh = async (): Promise<void> => {
    const response = await fetchWrapper.get(`/api/library/items/${item.ulid}`) as { data: LibraryItem };
    setItem(response.data);
    onChanged();
  };

  const edit = async (): Promise<void> => {
    setBusy(true);
    setError('');
    try {
      await fetchWrapper.patch(`/api/library/items/${item.ulid}`, { title: title.trim() || null, ...(item.body !== null ? { body } : {}) });
      await refresh();
      setEditing(false);
      toast.success('Item updated.');
    } catch (error) {
      setError(libraryError(error));
    } finally {
      setBusy(false);
    }
  };

  const membership = async (ulid: string, remove: boolean): Promise<void> => {
    setBusy(true);
    setError('');
    try {
      if (remove) await fetchWrapper.delete(`/api/library/items/${item.ulid}/collections/${ulid}`);
      else await fetchWrapper.post(`/api/library/items/${item.ulid}/collections`, { collection_ulid: ulid });
      await refresh();
      setCollection('');
    } catch (error) {
      setError(libraryError(error));
    } finally {
      setBusy(false);
    }
  };

  const remove = async (): Promise<void> => {
    setBusy(true);
    setError('');
    try {
      await fetchWrapper.delete(`/api/library/items/${item.ulid}`);
      toast.success(eventInstance ? 'Removed from My collection.' : 'Removed from your Library.');
      onChanged();
      onClose();
    } catch (error) {
      setError(libraryError(error));
    } finally {
      setBusy(false);
      setRemoveOpen(false);
    }
  };

  return <>
    <Dialog open onOpenChange={(open) => { if (!open && !busy) onClose(); }}>
      <DialogContent className="max-h-[90svh] overflow-auto sm:max-w-4xl">
        <DialogHeader>
          <DialogTitle>{item.title || (item.body !== null ? 'Untitled note' : 'Untitled media')}</DialogTitle>
          <DialogDescription>{item.owned ? item.source === 'saved' ? 'Saved items are visible only to you, including in shared collections.' : `In your private ${eventInstance ? 'collection' : 'Library'}. Only people you share with can view it.` : 'Shared with you.'}</DialogDescription>
        </DialogHeader>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        {!item.available ? <p className="rounded-md border p-6 text-sm text-muted-foreground">This media is currently unavailable.</p> : item.media ? <>
          <div className="flex max-h-[65svh] min-h-48 items-center justify-center overflow-hidden rounded-md bg-muted">
            <MediaPlayer key={item.ulid} item={item.media} className="max-h-[65svh] w-full object-contain" />
          </div>
          <p className="text-xs text-muted-foreground">{item.media.type} · {formatBytes(item.media.size_bytes)}</p>
        </> : <p className="whitespace-pre-wrap break-words rounded-md border p-4">{item.body}</p>}
        <div className="flex flex-wrap gap-2">
          {item.available && item.media?.upload_status === 'ready' && <a href={`/api/library/items/${encodeURIComponent(item.ulid)}/download`} download className="text-sm underline underline-offset-4">Download</a>}
          {!item.owned && enabled && item.available && <SaveToLibraryButton itemUlid={item.ulid} onSaved={onChanged} />}
          {item.owned && <>
            {enabled && <Button variant="outline" size="sm" disabled={busy} onClick={() => setEditing(!editing)}>Edit</Button>}
            {item.source !== 'saved' && <Button variant="outline" size="sm" disabled={busy} onClick={() => onShare(itemShareTarget(item))}>Share</Button>}
            <Button variant="destructive" size="sm" disabled={busy} onClick={() => setRemoveOpen(true)}>{removeLabel}</Button>
          </>}
        </div>
        {editing && <div className="grid gap-3 rounded-md border p-4">
          <Label htmlFor="library-edit-title">Title</Label>
          <Input id="library-edit-title" value={title} onChange={(event) => setTitle(event.target.value)} maxLength={255} disabled={busy} />
          {item.body !== null && <><Label htmlFor="library-edit-body">Note</Label><Textarea id="library-edit-body" value={body} onChange={(event) => setBody(event.target.value)} rows={8} maxLength={100000} disabled={busy} /></>}
          <Button disabled={busy || (item.body !== null && !body.trim())} onClick={() => void edit()}>{busy ? 'Saving…' : 'Save changes'}</Button>
        </div>}
        {item.owned && <section className="grid gap-3 rounded-md border p-4" aria-label="Item collections">
          <h2 className="text-sm font-medium">Collections</h2>
          {ownCollections.filter((entry) => item.collection_ulids.includes(entry.ulid)).map((entry) => <div key={entry.ulid} className="flex items-center justify-between gap-3 text-sm">
            <span>{entry.name}{entry.member_ids.length > 0 ? ' · Shared' : ''}</span>
            <Button variant="ghost" size="sm" disabled={busy} aria-label={`Remove from ${entry.name}`} onClick={() => void membership(entry.ulid, true)}>Remove from collection</Button>
          </div>)}
          {enabled && <>
            <Label htmlFor="library-add-collection">Add to a collection</Label>
            <div className="flex flex-wrap gap-2">
              <select id="library-add-collection" value={collection} onChange={(event) => setCollection(event.target.value)} disabled={busy} className="h-10 min-w-0 flex-1 rounded-md border border-input bg-background px-3">
                <option value="">Choose a collection</option>
                {ownCollections.filter((entry) => !item.collection_ulids.includes(entry.ulid)).map((entry) => <option key={entry.ulid} value={entry.ulid}>{entry.name}{entry.member_ids.length > 0 ? ' (shared)' : ''}</option>)}
              </select>
              <Button variant="outline" disabled={busy || !collection} onClick={() => void membership(collection, false)}>Add</Button>
            </div>
            {item.source !== 'saved' && ownCollections.some((entry) => entry.ulid === collection && entry.member_ids.length > 0) && <p className="text-sm text-muted-foreground">Adding this item shares it with this collection&apos;s recipients. They can save it to their Libraries.</p>}
          </>}
        </section>}
      </DialogContent>
    </Dialog>
    <AlertDialog open={removeOpen} onOpenChange={(open) => { if (!busy) setRemoveOpen(open); }}>
      <AlertDialogContent>
        <AlertDialogHeader><AlertDialogTitle>Remove this item from {eventInstance ? 'My collection' : 'your Library'}?</AlertDialogTitle><AlertDialogDescription>This removes it from all your collections. Other people keep their saved items. Deleted uploads may remain in admin retention.</AlertDialogDescription></AlertDialogHeader>
        <AlertDialogFooter><AlertDialogCancel disabled={busy}>Cancel</AlertDialogCancel><AlertDialogAction disabled={busy} onClick={() => void remove()}>{removeLabel}</AlertDialogAction></AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  </>;
}

interface LibraryItemPreviewProps {
  item: LibraryItem;
}

export function LibraryItemPreview({ item }: LibraryItemPreviewProps) {
  if (!item.available) return <span className="text-sm text-muted-foreground">Unavailable</span>;
  const image = item.media?.thumbnail_url || (item.media?.type === 'photo' ? item.media.url : null);
  if (image) return <ProtectedImage src={image} alt="" className="h-full w-full object-cover" />;
  if (item.body !== null) return <span className="line-clamp-6 whitespace-pre-wrap break-words p-4 text-left text-sm text-muted-foreground">{item.body}</span>;
  return <span className="text-sm text-muted-foreground">{item.media?.type === 'video' ? 'Video' : 'Media'}</span>;
}
