import { type FormEvent, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { UserPicker } from '@/community/UserPicker';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';
import { type LibraryCollection, libraryError, type LibraryItem, type LibraryPersona } from '@/library/types';

export interface ShareTarget {
  kind: 'items' | 'collections';
  ulid: string;
  title: string;
  member_ids: number[];
}

interface ShareDialogProps {
  target: ShareTarget;
  onClose: () => void;
  onChanged: () => void;
}

export function LibraryShareDialog({ target, onClose, onChanged }: ShareDialogProps) {
  const [ids, setIds] = useState(target.member_ids);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const link = `${window.location.origin}/library?${target.kind === 'items' ? 'item' : 'collection'}=${encodeURIComponent(target.ulid)}`;

  const save = async (recipients: number[]): Promise<void> => {
    setBusy(true);
    setError('');
    try {
      await fetchWrapper.put(`/api/library/${target.kind}/${target.ulid}/sharing`, { user_ids: recipients });
      toast.success(recipients.length === 0 ? 'Direct sharing removed.' : 'Sharing updated.');
      onChanged();
      onClose();
    } catch (error) {
      setError(libraryError(error));
    } finally {
      setBusy(false);
    }
  };

  const copyLink = async (): Promise<void> => {
    try {
      await navigator.clipboard.writeText(link);
      toast.success('Link copied. Only people with access can open it.');
    } catch {
      setError('Could not copy the link. Select and copy it from the field below.');
    }
  };

  return <Dialog open onOpenChange={(open) => { if (!open && !busy) onClose(); }}>
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Share {target.title}</DialogTitle>
        <DialogDescription>
          Selected people can view and save this content. Saved items remain in their Libraries if you stop sharing or delete your account.
        </DialogDescription>
      </DialogHeader>
      {target.kind === 'collections' && <p className="text-sm text-muted-foreground">Sharing includes all current and future items added to this collection, including notes.</p>}
      {target.kind === 'items' && <p className="text-sm text-muted-foreground">These are direct recipients. People with access through a shared collection can also view this item.</p>}
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <UserPicker selectedIds={ids} onChange={setIds} disabled={busy} />
      <Label htmlFor="library-share-link">Link for recipients</Label>
      <Input id="library-share-link" value={link} readOnly />
      <Button variant="outline" onClick={() => void copyLink()}>Copy link</Button>
      <DialogFooter>
        <Button variant="outline" disabled={busy} onClick={() => void save([])}>Stop direct sharing</Button>
        <Button disabled={busy} onClick={() => void save(ids)}>{busy ? 'Saving…' : 'Save sharing'}</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>;
}

interface CollectionDialogProps {
  collection: LibraryCollection | null;
  onClose: () => void;
  onChanged: () => void;
}

export function LibraryCollectionDialog({ collection, onClose, onChanged }: CollectionDialogProps) {
  const [name, setName] = useState(collection?.name ?? '');
  const [description, setDescription] = useState(collection?.description ?? '');
  const [personaUlid, setPersonaUlid] = useState(collection?.related_persona?.ulid ?? '');
  const [personaChanged, setPersonaChanged] = useState(false);
  const [query, setQuery] = useState('');
  const [personas, setPersonas] = useState<LibraryPersona[]>(collection?.related_persona ? [collection.related_persona] : []);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;
    const timer = window.setTimeout(() => {
      fetchWrapper.get(`/api/library/personas?q=${encodeURIComponent(query)}`).then((response) => {
        if (!active) return;
        const options = (response as { data: LibraryPersona[] }).data;
        setPersonas((current) => {
          const selected = current.find((entry) => entry.ulid === personaUlid);
          return selected && !options.some((entry) => entry.ulid === selected.ulid) ? [selected, ...options] : options;
        });
      }).catch(() => { if (active) setError('Could not load personas. You can create a collection without a persona link.'); });
    }, 250);
    return () => { active = false; window.clearTimeout(timer); };
  }, [query, personaUlid]);

  const save = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    setBusy(true);
    setError('');
    const payload = {
      name: name.trim(), description: description.trim() || null,
      ...(!collection || personaChanged ? { related_persona_ulid: personaUlid || null } : {}),
    };
    try {
      if (collection) await fetchWrapper.patch(`/api/library/collections/${collection.ulid}`, payload);
      else await fetchWrapper.post('/api/library/collections', payload);
      toast.success(collection ? 'Collection updated.' : 'Private collection created.');
      onChanged();
      onClose();
    } catch (error) {
      setError(libraryError(error));
    } finally {
      setBusy(false);
    }
  };

  return <Dialog open onOpenChange={(open) => { if (!open && !busy) onClose(); }}>
    <DialogContent>
      <DialogHeader>
        <DialogTitle>{collection ? 'Edit collection' : 'New private collection'}</DialogTitle>
        <DialogDescription>A related persona is a label for organizing your content. It grants no access and sends no notification.</DialogDescription>
      </DialogHeader>
      <form onSubmit={(event) => void save(event)} className="grid gap-3">
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <Label htmlFor="collection-name">Collection name</Label>
        <Input id="collection-name" value={name} onChange={(event) => setName(event.target.value)} required maxLength={255} disabled={busy} />
        <Label htmlFor="collection-description">Description (optional)</Label>
        <Textarea id="collection-description" value={description} onChange={(event) => setDescription(event.target.value)} maxLength={2000} disabled={busy} />
        <Label htmlFor="persona-search">Search existing personas</Label>
        <Input id="persona-search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search public personas or paste a persona link" disabled={busy} />
        <Label htmlFor="related-persona">Related persona (optional)</Label>
        <select id="related-persona" value={personaUlid} onChange={(event) => { setPersonaChanged(true); setPersonaUlid(event.target.value); }} disabled={busy} className="h-10 rounded-md border border-input bg-background px-3">
          <option value="">{collection?.related_persona_unavailable && !personaChanged ? 'Keep unavailable persona link' : 'No related persona'}</option>
          {personas.map((persona) => <option key={persona.ulid} value={persona.ulid}>{persona.display_name}</option>)}
        </select>
        {collection?.related_persona_unavailable && <Button type="button" variant="outline" disabled={busy} onClick={() => { setPersonaUlid(''); setPersonaChanged(true); }}>Clear unavailable persona link</Button>}
        <DialogFooter>
          <Button type="button" variant="outline" disabled={busy} onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy}>{busy ? 'Saving…' : collection ? 'Save collection' : 'Create collection'}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>;
}

interface NoteDialogProps {
  collections: LibraryCollection[];
  selectedCollection: string;
  onClose: () => void;
  onChanged: () => void;
}

export function LibraryNoteDialog({ collections, selectedCollection, onClose, onChanged }: NoteDialogProps) {
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [collection, setCollection] = useState(selectedCollection);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const save = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      await fetchWrapper.post('/api/library/notes', { title: title.trim() || null, body, collection_ulid: collection || null });
      toast.success('Note added to your Library.');
      onChanged();
      onClose();
    } catch (error) {
      setError(libraryError(error));
    } finally {
      setBusy(false);
    }
  };

  return <Dialog open onOpenChange={(open) => { if (!open && !busy) onClose(); }}>
    <DialogContent>
      <DialogHeader><DialogTitle>Add a note</DialogTitle><DialogDescription>Keep text received elsewhere, or write a note for your collection.</DialogDescription></DialogHeader>
      <form onSubmit={(event) => void save(event)} className="grid gap-3">
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <Label htmlFor="note-title">Title (optional)</Label>
        <Input id="note-title" value={title} onChange={(event) => setTitle(event.target.value)} maxLength={255} disabled={busy} />
        <Label htmlFor="note-body">Note</Label>
        <Textarea id="note-body" value={body} onChange={(event) => setBody(event.target.value)} required maxLength={100000} rows={8} disabled={busy} />
        <Label htmlFor="note-collection">Collection (optional)</Label>
        <select id="note-collection" value={collection} onChange={(event) => setCollection(event.target.value)} disabled={busy} className="h-10 rounded-md border border-input bg-background px-3">
          <option value="">Just add to Library</option>
          {collections.filter((entry) => entry.owned).map((entry) => <option key={entry.ulid} value={entry.ulid}>{entry.name}{entry.member_ids.length > 0 ? ' (shared)' : ''}</option>)}
        </select>
        {collections.some((entry) => entry.ulid === collection && entry.member_ids.length > 0) && <p className="text-sm text-muted-foreground">This note will be visible to this collection&apos;s recipients, who can save their own copy.</p>}
        <DialogFooter><Button type="button" variant="outline" disabled={busy} onClick={onClose}>Cancel</Button><Button disabled={busy} type="submit">{busy ? 'Saving…' : 'Add note'}</Button></DialogFooter>
      </form>
    </DialogContent>
  </Dialog>;
}

export function itemShareTarget(item: LibraryItem): ShareTarget {
  return { kind: 'items', ulid: item.ulid, title: item.title || 'item', member_ids: item.member_ids };
}
