import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import { BROWSING_PAGE_WIDTH } from '@/components/page-width';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';
import { LibraryCollectionDialog, LibraryNoteDialog, LibraryShareDialog, type ShareTarget } from '@/library/LibraryDialogs';
import { LibraryItemDialog, LibraryItemPreview } from '@/library/LibraryItemDialog';
import { type AccountStorageUsage, EMPTY_STORAGE_USAGE, type LibraryCollection, libraryError, type LibraryItem } from '@/library/types';
import { MediaUploadDialog } from '@/media/MediaUploadDialog';
import { formatBytes, type PagedResponse } from '@/media/types';

interface LibraryBootstrap {
  library?: { enabled: boolean; storage_usage: AccountStorageUsage };
}

const SECTIONS = [
  { key: 'all', label: 'All my items' },
  { key: 'uploaded', label: 'Uploaded' },
  { key: 'saved', label: 'Saved' },
  { key: 'notes', label: 'Notes' },
  { key: 'shared', label: 'Shared with me' },
];

export function LibraryPage() {
  const [initial] = useState(() => readInitialData<LibraryBootstrap>().library);
  const enabled = initial?.enabled ?? false;
  const [usage, setUsage] = useState(initial?.storage_usage ?? EMPTY_STORAGE_USAGE);
  const [collections, setCollections] = useState<LibraryCollection[]>([]);
  const [items, setItems] = useState<LibraryItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [revision, setRevision] = useState(0);
  const [activeItem, setActiveItem] = useState<LibraryItem | null>(null);
  const [collectionEditor, setCollectionEditor] = useState<LibraryCollection | null>();
  const [noteOpen, setNoteOpen] = useState(false);
  const [shareTarget, setShareTarget] = useState<ShareTarget | null>(null);
  const [removeCollection, setRemoveCollection] = useState<LibraryCollection | null>(null);
  const [removingCollection, setRemovingCollection] = useState(false);
  const params = new URLSearchParams(window.location.search);
  const section = SECTIONS.some((entry) => entry.key === params.get('section')) ? params.get('section') ?? 'all' : 'all';
  const collectionUlid = params.get('collection') ?? '';
  const selectedCollection = collections.find((entry) => entry.ulid === collectionUlid);
  const [initialItemUlid] = useState(params.get('item'));
  const canAddHere = enabled && (!selectedCollection || selectedCollection.owned) && section !== 'shared';
  const refresh = (): void => setRevision((value) => value + 1);

  useEffect(() => {
    let active = true;
    Promise.all([
      fetchWrapper.get('/api/library/collections') as Promise<{ data: LibraryCollection[] }>,
      fetchWrapper.get('/api/account/storage') as Promise<{ data: AccountStorageUsage }>,
    ]).then(([collectionResponse, usageResponse]) => {
      if (!active) return;
      setCollections(collectionResponse.data);
      setUsage(usageResponse.data);
    }).catch((error: unknown) => { if (active) setError(libraryError(error)); });
    return () => { active = false; };
  }, [revision]);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError('');
    const timer = window.setTimeout(() => {
      const search = new URLSearchParams({ section: collectionUlid ? 'all' : section, page: String(page), q: query });
      if (collectionUlid) search.set('collection_ulid', collectionUlid);
      fetchWrapper.get(`/api/library/items?${search.toString()}`).then((response) => {
        if (!active) return;
        const data = response as PagedResponse<LibraryItem>;
        setItems(data.data);
        setHasMore(data.meta?.has_more ?? false);
      }).catch((error: unknown) => { if (active) { setItems([]); setError(libraryError(error)); } })
        .finally(() => { if (active) setLoading(false); });
    }, query ? 250 : 0);
    return () => { active = false; window.clearTimeout(timer); };
  }, [section, collectionUlid, query, page, revision]);

  useEffect(() => {
    if (!initialItemUlid) return;
    let active = true;
    fetchWrapper.get(`/api/library/items/${encodeURIComponent(initialItemUlid)}`).then((response) => {
      if (active) setActiveItem((response as { data: LibraryItem }).data);
    }).catch((error: unknown) => { if (active) setError(libraryError(error)); });
    return () => { active = false; };
  }, [initialItemUlid]);

  const openItem = async (ulid: string): Promise<void> => {
    try {
      const response = await fetchWrapper.get(`/api/library/items/${ulid}`) as { data: LibraryItem };
      setActiveItem(response.data);
      const url = new URL(window.location.href);
      url.searchParams.set('item', ulid);
      window.history.replaceState({}, '', url);
    } catch (error) {
      toast.error(libraryError(error));
    }
  };

  const closeItem = (): void => {
    setActiveItem(null);
    const url = new URL(window.location.href);
    url.searchParams.delete('item');
    window.history.replaceState({}, '', url);
  };

  const sharingChanged = (): void => {
    refresh();
    if (activeItem && shareTarget?.kind === 'items') {
      fetchWrapper.get(`/api/library/items/${activeItem.ulid}`).then((response) => {
        setActiveItem((response as { data: LibraryItem }).data);
      }).catch((error: unknown) => toast.error(libraryError(error)));
    }
  };

  const deleteCollection = async (): Promise<void> => {
    if (!removeCollection) return;
    setRemovingCollection(true);
    try {
      await fetchWrapper.delete(`/api/library/collections/${removeCollection.ulid}`);
      const url = new URL(window.location.href);
      url.searchParams.delete('collection');
      window.history.replaceState({}, '', url);
      setRemoveCollection(null);
      refresh();
      toast.success('Collection removed. Its items remain in your Library.');
    } catch (error) {
      toast.error(libraryError(error));
    } finally {
      setRemovingCollection(false);
    }
  };

  return <main className={`${BROWSING_PAGE_WIDTH} space-y-5 px-4 py-6`}>
    <header className="flex flex-wrap items-start justify-between gap-4">
      <div><h1 className="text-2xl font-semibold">Library</h1><p className="mt-1 max-w-2xl text-sm text-muted-foreground">A private place for notes, photos, and videos you upload or save. Organize them around existing personas and choose who you share with.</p></div>
      {enabled && <Button onClick={() => setCollectionEditor(null)}>New collection</Button>}
    </header>
    {!enabled && <Alert><AlertDescription>Library storage is enabled selectively by an administrator. You can still view shared content and manage items already in your Library.</AlertDescription></Alert>}
    <div className="rounded-lg border px-4 py-3 text-sm">
      <p className="font-medium">{formatBytes(usage.total_bytes)} in your account</p>
      <p className="text-muted-foreground">Uploaded originals: {formatBytes(usage.uploaded_bytes)} · Saved originals: {formatBytes(usage.saved_bytes)} · Retained deleted uploads: {formatBytes(usage.retained_bytes)}</p>
      <p className="mt-1 text-xs text-muted-foreground">Each original counts once in your account, even in several collections. Processed copies are excluded.</p>
      {usage.unknown_size_file_count > 0 && <p className="mt-1 text-xs text-muted-foreground">Sizes are unavailable for {usage.unknown_size_file_count} files and excluded from these totals.</p>}
    </div>
    <div className="grid gap-6 md:grid-cols-[220px_minmax(0,1fr)]">
      <aside className="space-y-5">
        <nav aria-label="Library sections" className="flex flex-wrap gap-1 md:flex-col">
          {SECTIONS.map((entry) => <a key={entry.key} href={`/library?section=${entry.key}`} aria-current={!collectionUlid && section === entry.key ? 'page' : undefined} className={`rounded-md px-3 py-2 text-sm ${!collectionUlid && section === entry.key ? 'bg-muted font-medium' : 'hover:bg-muted'}`}>{entry.label}</a>)}
        </nav>
        <nav aria-label="Collections" className="space-y-1">
          <h2 className="px-3 text-sm font-medium">Collections</h2>
          {collections.map((entry) => <a key={entry.ulid} href={`/library?collection=${encodeURIComponent(entry.ulid)}`} aria-current={collectionUlid === entry.ulid ? 'page' : undefined} className={`block break-words rounded-md px-3 py-2 text-sm ${collectionUlid === entry.ulid ? 'bg-muted font-medium' : 'hover:bg-muted'}`}>{entry.name}<span className="block text-xs font-normal text-muted-foreground">{entry.owned ? entry.member_ids.length > 0 ? 'Shared collection' : 'Private collection' : 'Shared with you'}</span></a>)}
          {collections.length === 0 && <p className="px-3 text-sm text-muted-foreground">No collections yet.</p>}
        </nav>
      </aside>
      <section className="min-w-0 space-y-4" aria-label="Library items">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-lg font-semibold">{selectedCollection?.name ?? SECTIONS.find((entry) => entry.key === section)?.label}</h2>
          <div className="flex flex-wrap gap-2">
            {canAddHere && <><MediaUploadDialog key={collectionUlid || 'all'} characters={[]} defaultCharacterId={null} lastInterestIds={[]} libraryCollectionUlid={collectionUlid || null} onUploaded={refresh} /><Button variant="outline" onClick={() => setNoteOpen(true)}>Add note</Button></>}
            {selectedCollection?.owned && <>
              {enabled && <Button variant="outline" onClick={() => setCollectionEditor(selectedCollection)}>Edit collection</Button>}
              <Button variant="outline" onClick={() => setShareTarget({ kind: 'collections', ulid: selectedCollection.ulid, title: selectedCollection.name, member_ids: selectedCollection.member_ids })}>Share collection</Button>
              <Button variant="ghost" onClick={() => setRemoveCollection(selectedCollection)}>Remove collection</Button>
            </>}
          </div>
        </div>
        {selectedCollection?.description && <p className="whitespace-pre-wrap break-words text-sm text-muted-foreground">{selectedCollection.description}</p>}
        {selectedCollection?.related_persona && <p className="text-sm">Related to <a className="underline underline-offset-4" href={selectedCollection.related_persona.href}>{selectedCollection.related_persona.display_name}</a></p>}
        {selectedCollection?.related_persona_unavailable && <p className="text-sm text-muted-foreground">The related persona is unavailable. Your collection and files remain accessible.</p>}
        {selectedCollection?.owned && selectedCollection.member_ids.length > 0 && <p className="text-sm text-muted-foreground">This collection is shared. Current and future items, including notes, are visible to its recipients and can be saved by them.</p>}
        <Label htmlFor="library-search" className="sr-only">Search Library items</Label>
        <Input id="library-search" placeholder="Search titles and notes" value={query} onChange={(event) => { setQuery(event.target.value); setPage(1); }} />
        {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
        {loading ? <p role="status" className="py-8 text-sm text-muted-foreground">Loading Library…</p> : items.length === 0 ? <div className="rounded-lg border border-dashed p-8 text-center"><p className="font-medium">No items here yet.</p><p className="mt-2 text-sm text-muted-foreground">Upload media, add a note, or choose Save to Library on a media page.</p></div> : <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {items.map((item) => <Card key={item.ulid} className="overflow-hidden py-0">
            <button type="button" aria-label={`Open ${item.title || 'untitled item'}`} className="flex aspect-[4/3] w-full items-center justify-center overflow-hidden bg-muted" onClick={() => void openItem(item.ulid)}><LibraryItemPreview item={item} /></button>
            <CardContent className="space-y-2 p-4"><h3 className="truncate font-medium">{item.title || (item.body !== null ? 'Untitled note' : 'Untitled media')}</h3><div className="flex flex-wrap gap-1"><Badge variant="secondary">{item.body !== null ? 'Note' : item.media?.type ?? 'Media'}</Badge><Badge variant="outline">{item.owned ? item.source === 'saved' ? 'Saved' : 'Yours' : 'Shared with you'}</Badge></div></CardContent>
          </Card>)}
        </div>}
        {(page > 1 || hasMore) && <div className="flex items-center justify-between"><Button variant="outline" disabled={loading || page === 1} onClick={() => setPage((value) => value - 1)}>Previous</Button><span className="text-sm">Page {page}</span><Button variant="outline" disabled={loading || !hasMore} onClick={() => setPage((value) => value + 1)}>Next</Button></div>}
      </section>
    </div>
    {activeItem && <LibraryItemDialog key={activeItem.ulid} item={activeItem} collections={collections} enabled={enabled} onClose={closeItem} onChanged={refresh} onShare={setShareTarget} />}
    {collectionEditor !== undefined && <LibraryCollectionDialog collection={collectionEditor} onClose={() => setCollectionEditor(undefined)} onChanged={refresh} />}
    {noteOpen && <LibraryNoteDialog collections={collections} selectedCollection={collectionUlid} onClose={() => setNoteOpen(false)} onChanged={refresh} />}
    {shareTarget && <LibraryShareDialog key={`${shareTarget.kind}:${shareTarget.ulid}`} target={shareTarget} onClose={() => setShareTarget(null)} onChanged={sharingChanged} />}
    <AlertDialog open={removeCollection !== null} onOpenChange={(open) => { if (!open && !removingCollection) setRemoveCollection(null); }}>
      <AlertDialogContent><AlertDialogHeader><AlertDialogTitle>Remove this collection?</AlertDialogTitle><AlertDialogDescription>Its items remain in your Library. Collection recipients lose access through this collection, but keep anything they saved.</AlertDialogDescription></AlertDialogHeader><AlertDialogFooter><AlertDialogCancel disabled={removingCollection}>Cancel</AlertDialogCancel><AlertDialogAction disabled={removingCollection} onClick={() => void deleteCollection()}>Remove collection</AlertDialogAction></AlertDialogFooter></AlertDialogContent>
    </AlertDialog>
    <Toaster position="top-right" richColors closeButton />
  </main>;
}

const mount = document.getElementById('library');
if (mount) createRoot(mount).render(<LibraryPage />);
