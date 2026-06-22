import { type FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import { AudienceField } from '@/community/AudienceField';
import { InterestPicker } from '@/components/interest-picker';
import { FileDropzone } from '@/components/media/FileDropzone';
import { UploadProgress } from '@/components/media/UploadProgress';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';
import { generatePhotoDerivatives, generateVideoPoster, supportsClientDerivatives } from '@/media/imageProcessing';
import { MediaFilters } from '@/media/MediaFilters';
import { MediaGrid } from '@/media/MediaGrid';
import { type Audience, type MediaItem, type MediaTypeFilter, mediaTypeForFile, type PageMeta } from '@/media/types';
import {
  type CompletedMultipartPart,
  type MultipartUploadSession,
  putToSignedUrl,
  readMultipartSession,
  saveMultipartSession,
  uploadMultipartFile,
} from '@/media/upload';
import { useMediaListing } from '@/media/useMediaListing';

interface InitialData {
  last_interest_ids: number[];
  characters: CharacterOption[];
  data: MediaItem[];
  meta?: PageMeta;
}

interface CharacterOption {
  id: number;
  display_name: string;
  audience: Audience;
  audience_user_ids: number[];
}

interface StoreResponse {
  data: MediaItem;
  upload_url: string;
  upload_headers: Record<string, string>;
  thumbnail_upload_url: string | null;
  thumbnail_upload_headers: Record<string, string> | null;
  multipart: {
    enabled: boolean;
    threshold_bytes: number;
    part_size_bytes: number;
  };
}

interface InitMultipartResponse {
  data: {
    upload_id: string;
    part_size_bytes: number;
    expires_in_minutes: number;
  };
}

interface PresignMultipartPartsResponse {
  data: {
    part_number: number;
    url: string;
    headers: Record<string, string>;
  }[];
}

interface CompleteMultipartResponse {
  data: MediaItem;
}

function getInitialData(): InitialData {
  return readInitialData<{ userMedia?: InitialData }>().userMedia ?? { last_interest_ids: [], characters: [], data: [] };
}

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : err instanceof Error ? err.message : 'Request failed.';
}

/** A selected file paired with the (editable) title it will be uploaded under. */
interface PendingUpload {
  file: File;
  title: string;
}

/**
 * Default an item's title to its file name without the extension
 * ("Beach Day.jpg" -> "Beach Day"). The user can edit or clear it before upload.
 */
function defaultTitleForFile(file: File): string {
  const lastDot = file.name.lastIndexOf('.');
  return lastDot > 0 ? file.name.slice(0, lastDot) : file.name;
}

/** Stable identity for a selected file, so edited titles survive re-selection. */
function sameFile(a: File, b: File): boolean {
  return a.name === b.name && a.size === b.size && a.lastModified === b.lastModified;
}

interface Derivatives {
  thumbnail: Blob | null;
  perceptualHash: string | null;
}

/**
 * Best-effort client-side thumbnail/poster (and, for photos, a perceptual hash).
 * Never throws: a failure just means the item uploads without a thumbnail.
 */
async function buildDerivatives(file: File, type: 'photo' | 'video'): Promise<Derivatives> {
  if (!supportsClientDerivatives()) {
    return { thumbnail: null, perceptualHash: null };
  }
  try {
    if (type === 'photo') {
      const { thumbnail, perceptualHash } = await generatePhotoDerivatives(file);
      return { thumbnail, perceptualHash };
    }
    return { thumbnail: await generateVideoPoster(file), perceptualHash: null };
  } catch {
    return { thumbnail: null, perceptualHash: null };
  }
}

function multipartSessionKey(file: File): string {
  return `vora:media:multipart:${file.name}:${file.size}:${file.lastModified}:${file.type}`;
}

async function beginMultipartSession(
  created: StoreResponse | null,
  sessionKey: string,
): Promise<MultipartUploadSession> {
  if (created === null) {
    throw new Error('Upload session is missing.');
  }

  const init = (await fetchWrapper.post(`/api/media/${created.data.id}/multipart/init`, {})) as InitMultipartResponse;
  const session = {
    mediaId: created.data.id,
    uploadId: init.data.upload_id,
    partSizeBytes: init.data.part_size_bytes,
    completedParts: [],
    createdAt: new Date().toISOString(),
  };

  saveMultipartSession(sessionKey, session);

  return session;
}

async function uploadThumbnailBestEffort(
  created: StoreResponse,
  thumbnail: Blob | null,
  signal: AbortSignal,
): Promise<void> {
  if (!thumbnail || !created.thumbnail_upload_url || !created.thumbnail_upload_headers) {
    return;
  }

  try {
    await putToSignedUrl(created.thumbnail_upload_url, thumbnail, created.thumbnail_upload_headers, () => {}, { signal });
  } catch (err) {
    if (err instanceof DOMException && err.name === 'AbortError') {
      throw err;
    }
    /* ignore — thumbnail is optional */
  }
}

function UserMediaPage() {
  const initial = useMemo(() => getInitialData(), []);
  const characters = initial.characters ?? [];

  const [typeFilter, setTypeFilter] = useState<MediaTypeFilter>('all');
  const [filterInterestIds, setFilterInterestIds] = useState<number[]>([]);
  const listing = useMediaListing('/api/media', { type: typeFilter, interestIds: filterInterestIds }, initial);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [bulkCharacterId, setBulkCharacterId] = useState('');
  const [bulkAudience, setBulkAudience] = useState<Audience>('everyone');
  const [bulkAudienceUserIds, setBulkAudienceUserIds] = useState<number[]>([]);
  const [bulkDiscoverable, setBulkDiscoverable] = useState(true);
  const [bulkBusy, setBulkBusy] = useState(false);
  const [bulkDialogOpen, setBulkDialogOpen] = useState(false);

  // Single-item edit dialog state.
  const [editItem, setEditItem] = useState<MediaItem | null>(null);
  const [editTitle, setEditTitle] = useState('');
  const [editCharacterId, setEditCharacterId] = useState('');
  const [editAudience, setEditAudience] = useState<Audience>('everyone');
  const [editAudienceUserIds, setEditAudienceUserIds] = useState<number[]>([]);
  const [editDiscoverable, setEditDiscoverable] = useState(true);
  const [editBusy, setEditBusy] = useState(false);

  const [dialogOpen, setDialogOpen] = useState(false);
  const [pending, setPending] = useState<PendingUpload[]>([]);
  const [characterId, setCharacterId] = useState('');
  const [audience, setAudience] = useState<Audience>('everyone');
  const [audienceUserIds, setAudienceUserIds] = useState<number[]>([]);
  const [discoverable, setDiscoverable] = useState(true);
  const [uploadInterestIds, setUploadInterestIds] = useState<number[]>(initial.last_interest_ids);
  const [uploading, setUploading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [uploadLabel, setUploadLabel] = useState('Uploading…');
  const uploadAbortRef = useRef<AbortController | null>(null);
  const selectedItems = listing.items.filter((item) => selectedIds.includes(item.id));
  const selectedHasCharacterMedia = selectedItems.some((item) => item.character_id !== null);
  const allVisibleSelected = listing.items.length > 0 && listing.items.every((item) => selectedIds.includes(item.id));

  useEffect(() => {
    const visibleIds = new Set(listing.items.map((item) => item.id));
    setSelectedIds((current) => current.filter((id) => visibleIds.has(id)));
  }, [listing.items]);

  // Keep the title list in step with the selected files: existing files keep any
  // title the user already edited; newly added files default to their file name.
  const handleFilesChange = (newFiles: File[]): void => {
    setPending((prev) => newFiles.map((file) => {
      // Keep an already-edited title, but always pair it with the current File —
      // returning the matched item wholesale would drop the new File (and, when two
      // selected files share a name/size/lastModified, list the first one twice).
      const existing = prev.find((item) => sameFile(item.file, file));
      return { file, title: existing ? existing.title : defaultTitleForFile(file) };
    }));
  };

  const setTitleAt = (index: number, value: string): void => {
    setPending((prev) => prev.map((item, i) => (i === index ? { ...item, title: value } : item)));
  };

  const resetForm = (): void => {
    setPending([]);
    setCharacterId('');
    setAudience('everyone');
    setAudienceUserIds([]);
    setDiscoverable(true);
    setUploadInterestIds(initial.last_interest_ids);
    setProgress(0);
  };

  const upload = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();
    if (pending.length === 0) {
      toast.error('Choose at least one file to upload.');
      return;
    }

    const unsupported = pending.find((item) => mediaTypeForFile(item.file) === null);
    if (unsupported) {
      toast.error(`${unsupported.file.name} is not supported. Only image and video files can be uploaded.`);
      return;
    }

    const selectedCharacterId = characterId === '' ? null : Number(characterId);

    const abortController = new AbortController();
    uploadAbortRef.current = abortController;
    setUploading(true);
    setProgress(0);
    let completedCount = 0;
    try {
      for (const [index, item] of pending.entries()) {
        const selectedFile = item.file;
        const type = mediaTypeForFile(selectedFile);
        if (type === null) {
          continue;
        }
        setUploadLabel(`Uploading ${index + 1} of ${pending.length}: ${selectedFile.name}`);
        setProgress(0);
        const { thumbnail, perceptualHash } = await buildDerivatives(selectedFile, type);

        const sessionKey = multipartSessionKey(selectedFile);
        const existingSession = readMultipartSession(sessionKey);
        const uploadPayload = {
          type,
          filename: selectedFile.name,
          content_type: selectedFile.type,
          size: selectedFile.size,
          title: item.title.trim() || null,
          interest_ids: uploadInterestIds,
          has_thumbnail: thumbnail !== null,
          perceptual_hash: perceptualHash,
          ...(selectedCharacterId === null ? {
            audience,
            audience_user_ids: audience === 'specific' ? audienceUserIds : [],
            discoverable,
          } : {
            character_id: selectedCharacterId,
          }),
        };
        const created = existingSession === null
          ? (await fetchWrapper.post('/api/media', uploadPayload)) as StoreResponse
          : null;

        const shouldMultipart = existingSession !== null
          || (created?.multipart.enabled === true && selectedFile.size >= created.multipart.threshold_bytes);

        if (shouldMultipart) {
          const session = existingSession ?? await beginMultipartSession(created, sessionKey);
          await uploadMultipartFile(selectedFile, {
            sessionKey,
            session,
            signal: abortController.signal,
            onProgress: (fraction) => setProgress(fraction * 100),
            presignParts: async (partNumbers) => {
              const response = (await fetchWrapper.post(`/api/media/${session.mediaId}/multipart/parts`, {
                upload_id: session.uploadId,
                part_numbers: partNumbers,
              })) as PresignMultipartPartsResponse;

              return response.data;
            },
            complete: async (parts: CompletedMultipartPart[]) => {
              if (created !== null) {
                await uploadThumbnailBestEffort(created, thumbnail, abortController.signal);
              }

              await fetchWrapper.post(`/api/media/${session.mediaId}/multipart/complete`, {
                upload_id: session.uploadId,
                parts,
              }) as CompleteMultipartResponse;
            },
            abort: async () => {
              await fetchWrapper.post(`/api/media/${session.mediaId}/multipart/abort`, {
                upload_id: session.uploadId,
              });
            },
          });
        } else if (created !== null) {
          await putToSignedUrl(created.upload_url, selectedFile, created.upload_headers, (fraction) => {
            setProgress(fraction * 100);
          }, { signal: abortController.signal });

          await uploadThumbnailBestEffort(created, thumbnail, abortController.signal);
          await fetchWrapper.post(`/api/media/${created.data.id}/complete`, {});
        }
        completedCount += 1;
      }

      toast.success(pending.length === 1 ? 'Upload complete. It will be reviewed before others can see it.' : 'Uploads complete. They will be reviewed before others can see them.');
      setDialogOpen(false);
      resetForm();
      listing.reload();
    } catch (err) {
      if (completedCount > 0) {
        setPending(pending.slice(completedCount));
        listing.reload();
      }

      if (err instanceof DOMException && err.name === 'AbortError') {
        toast.info(completedCount > 0 ? `${completedCount} upload${completedCount === 1 ? '' : 's'} completed before cancellation.` : 'Upload canceled.');
      } else {
        const message = getErrorMessage(err);
        toast.error(completedCount > 0 ? `${message} ${completedCount} upload${completedCount === 1 ? '' : 's'} completed before the failure.` : message);
      }
    } finally {
      setUploading(false);
      uploadAbortRef.current = null;
    }
  };

  const remove = async (item: MediaItem): Promise<void> => {
    if (!window.confirm('Delete this item? It will be hidden from your library and retained for admin recovery.')) {
      return;
    }
    try {
      await fetchWrapper.delete(`/api/media/${item.id}`);
      listing.removeLocal(item.id);
      toast.success('Media deleted.');
    } catch (err) {
      toast.error(getErrorMessage(err));
    }
  };

  const openEdit = (item: MediaItem): void => {
    setEditItem(item);
    setEditTitle(item.title ?? '');
    setEditCharacterId(item.character_id !== null ? String(item.character_id) : '');
    setEditAudience(item.audience);
    setEditAudienceUserIds([]);
    setEditDiscoverable(item.discoverable);
  };

  const saveEdit = async (): Promise<void> => {
    if (!editItem) return;
    const payload: Record<string, unknown> = { title: editTitle.trim() === '' ? null : editTitle.trim() };

    if (editCharacterId !== '') {
      // Assigning (or keeping) a character: privacy is inherited from it.
      payload.character_id = Number(editCharacterId);
    } else if (editItem.character_id !== null) {
      // Detaching the character. Privacy is edited in a follow-up save, since the
      // server applies a character change OR a privacy change, not both at once.
      payload.character_id = null;
    } else {
      payload.audience = editAudience;
      payload.audience_user_ids = editAudience === 'specific' ? editAudienceUserIds : [];
      payload.discoverable = editDiscoverable;
    }

    setEditBusy(true);
    try {
      await fetchWrapper.patch(`/api/media/${editItem.id}`, payload);
      listing.reload();
      setEditItem(null);
      toast.success('Media updated.');
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setEditBusy(false);
    }
  };

  const toggleVisibleSelection = (): void => {
    if (allVisibleSelected) {
      const visibleIds = new Set(listing.items.map((item) => item.id));
      setSelectedIds((current) => current.filter((id) => !visibleIds.has(id)));
      return;
    }

    setSelectedIds((current) => Array.from(new Set([...current, ...listing.items.map((item) => item.id)])));
  };

  const applyBulkUpdate = async (payload: Record<string, unknown>, successMessage: string): Promise<void> => {
    if (selectedIds.length === 0) {
      toast.error('Select at least one media item.');
      return;
    }

    setBulkBusy(true);
    try {
      await fetchWrapper.patch('/api/media/bulk', {
        media_ids: selectedIds,
        ...payload,
      });
      toast.success(successMessage);
      setSelectedIds([]);
      setBulkDialogOpen(false);
      listing.reload();
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setBulkBusy(false);
    }
  };

  const assignSelectedToCharacter = async (): Promise<void> => {
    if (bulkCharacterId === '') {
      toast.error('Choose a character first.');
      return;
    }

    await applyBulkUpdate({
      action: 'assign_character',
      character_id: Number(bulkCharacterId),
    }, 'Selected media assigned to character.');
  };

  const clearSelectedCharacter = async (): Promise<void> => {
    await applyBulkUpdate({ action: 'clear_character' }, 'Selected media detached from character.');
  };

  const updateSelectedPrivacy = async (): Promise<void> => {
    if (selectedHasCharacterMedia) {
      toast.error('Character media inherits character privacy. Clear the character first.');
      return;
    }

    await applyBulkUpdate({
      action: 'set_privacy',
      audience: bulkAudience,
      audience_user_ids: bulkAudience === 'specific' ? bulkAudienceUserIds : [],
      discoverable: bulkDiscoverable,
    }, 'Selected media privacy updated.');
  };

  const deleteSelected = async (): Promise<void> => {
    if (selectedIds.length === 0) {
      toast.error('Select at least one media item.');
      return;
    }

    if (!window.confirm(`Delete ${selectedIds.length} selected item${selectedIds.length === 1 ? '' : 's'}? They will be hidden from your library and retained for admin recovery.`)) {
      return;
    }

    setBulkBusy(true);
    try {
      await fetchWrapper.delete('/api/media/bulk', { media_ids: selectedIds });
      toast.success('Selected media deleted.');
      setSelectedIds([]);
      setBulkDialogOpen(false);
      listing.reload();
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setBulkBusy(false);
    }
  };

  return (
    <div className="mx-auto max-w-5xl px-4 py-8">
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">My media</h1>
          <p className="text-muted-foreground">Upload photos and videos. Only you can see an upload until it is approved.</p>
        </div>
        <Dialog open={dialogOpen} onOpenChange={(open) => {
          if (!uploading) {
            setDialogOpen(open);
          }
        }}>
          <DialogTrigger asChild>
            <Button>Upload</Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Upload media</DialogTitle>
            </DialogHeader>
            <form onSubmit={(event) => void upload(event)} className="grid gap-4">
              <FileDropzone
                accept="image/*,video/*"
                files={pending.map((item) => item.file)}
                label="Drop photos or videos here"
                multiple
                onFilesChange={handleFilesChange}
                disabled={uploading}
                helperText="Select one or more files. Each file uploads in its own request."
              />
              {pending.length > 0 && (
                <div className="grid gap-3">
                  {pending.map((item, index) => (
                    <div key={`${item.file.name}:${item.file.size}:${item.file.lastModified}`} className="grid gap-1">
                      {pending.length > 1 && (
                        <span className="truncate text-xs text-muted-foreground" title={item.file.name}>
                          {item.file.name}
                        </span>
                      )}
                      <Input
                        value={item.title}
                        onChange={(event) => setTitleAt(index, event.target.value)}
                        placeholder="Title (optional)"
                        disabled={uploading}
                      />
                    </div>
                  ))}
                </div>
              )}
              {characters.length > 0 && (
                <label className="grid gap-1">
                  <span className="text-sm">Character</span>
                  <select
                    value={characterId}
                    onChange={(event) => setCharacterId(event.target.value)}
                    disabled={uploading}
                    className="w-full rounded-md border border-input bg-background px-3 py-2"
                  >
                    <option value="">No character</option>
                    {characters.map((character) => (
                      <option key={character.id} value={character.id}>{character.display_name}</option>
                    ))}
                  </select>
                </label>
              )}
              {characterId === '' ? (
                <>
                  <AudienceField
                    audience={audience}
                    onAudienceChange={setAudience}
                    selectedUserIds={audienceUserIds}
                    onSelectedUserIdsChange={setAudienceUserIds}
                    disabled={uploading}
                    specificRelationship="mutuals"
                  />
                  <label className="flex items-start gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={discoverable}
                      onChange={(event) => setDiscoverable(event.target.checked)}
                      disabled={uploading}
                      className="mt-0.5"
                    />
                    <span>List in discovery — otherwise only people with the link can find it.</span>
                  </label>
                </>
              ) : (
                <p className="text-sm text-muted-foreground">This upload uses the selected character's privacy setting.</p>
              )}
              <div className="grid gap-1">
                <span className="text-sm">Interests</span>
                <InterestPicker value={uploadInterestIds} onChange={setUploadInterestIds} disabled={uploading} />
              </div>
              <div className="flex items-center gap-3">
                <Button type="submit" disabled={uploading}>
                  {uploading ? 'Uploading…' : 'Upload'}
                </Button>
              </div>
              {uploading && (
                <UploadProgress
                  label={uploadLabel}
                  progress={progress}
                  onCancel={() => uploadAbortRef.current?.abort()}
                />
              )}
            </form>
          </DialogContent>
        </Dialog>
      </div>

      <MediaFilters
        type={typeFilter}
        onTypeChange={setTypeFilter}
        interestIds={filterInterestIds}
        onInterestIdsChange={setFilterInterestIds}
        disabled={listing.loading}
      />

      {listing.items.length > 0 && (
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-md border border-border p-4">
          <div className="text-sm text-muted-foreground">
            {selectedIds.length} selected
          </div>
          <div className="flex flex-wrap gap-2">
            <Button type="button" size="sm" variant="outline" onClick={toggleVisibleSelection} disabled={bulkBusy}>
              {allVisibleSelected ? 'Clear visible' : 'Select visible'}
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => setSelectedIds([])} disabled={bulkBusy || selectedIds.length === 0}>
              Clear selection
            </Button>
            <Button type="button" size="sm" onClick={() => setBulkDialogOpen(true)} disabled={bulkBusy || selectedIds.length === 0}>
              Edit selected
            </Button>
          </div>

          <Dialog open={bulkDialogOpen} onOpenChange={(open) => { if (!open) setBulkDialogOpen(false); }}>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Edit {selectedIds.length} selected</DialogTitle>
                <DialogDescription>Assign a character, change privacy, or delete the selected items.</DialogDescription>
              </DialogHeader>
              <div className="grid gap-5">
                <div className="grid gap-2">
                  <span className="text-sm font-medium">Character</span>
                  <select
                    value={bulkCharacterId}
                    onChange={(event) => setBulkCharacterId(event.target.value)}
                    disabled={bulkBusy || characters.length === 0}
                    className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                  >
                    <option value="">Choose character</option>
                    {characters.map((character) => (
                      <option key={character.id} value={character.id}>{character.display_name}</option>
                    ))}
                  </select>
                  <div className="flex flex-wrap gap-2">
                    <Button type="button" size="sm" onClick={() => void assignSelectedToCharacter()} disabled={bulkBusy || bulkCharacterId === ''}>
                      Assign
                    </Button>
                    <Button type="button" size="sm" variant="outline" onClick={() => void clearSelectedCharacter()} disabled={bulkBusy}>
                      Clear character
                    </Button>
                  </div>
                </div>

                <div className="grid gap-2 border-t border-border pt-4">
                  <AudienceField
                    audience={bulkAudience}
                    onAudienceChange={setBulkAudience}
                    selectedUserIds={bulkAudienceUserIds}
                    onSelectedUserIdsChange={setBulkAudienceUserIds}
                    disabled={bulkBusy || selectedHasCharacterMedia}
                    label="Change standalone media privacy"
                    specificRelationship="mutuals"
                  />
                  <label className="flex items-start gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={bulkDiscoverable}
                      onChange={(event) => setBulkDiscoverable(event.target.checked)}
                      disabled={bulkBusy || selectedHasCharacterMedia}
                      className="mt-0.5"
                    />
                    <span>List in discovery</span>
                  </label>
                  {selectedHasCharacterMedia && (
                    <p className="text-xs text-muted-foreground">Character media inherits character privacy. Clear the character before changing media privacy directly.</p>
                  )}
                  <div>
                    <Button type="button" size="sm" onClick={() => void updateSelectedPrivacy()} disabled={bulkBusy || selectedHasCharacterMedia}>
                      Apply privacy
                    </Button>
                  </div>
                </div>
              </div>
              <DialogFooter>
                <Button type="button" variant="destructive" onClick={() => void deleteSelected()} disabled={bulkBusy}>
                  Delete selected
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </div>
      )}

      {listing.loading ? (
        <p className="text-muted-foreground">Loading…</p>
      ) : listing.error ? (
        <p className="text-destructive">{listing.error}</p>
      ) : listing.items.length === 0 ? (
        <p className="text-muted-foreground">Nothing here yet.</p>
      ) : (
        <MediaGrid
          items={listing.items}
          selectedIds={selectedIds}
          onSelectionChange={setSelectedIds}
          renderActions={(item) => (
            <div className="flex gap-2">
              <Button type="button" size="sm" variant="outline" onClick={() => openEdit(item)}>
                Edit
              </Button>
              <Button type="button" size="sm" variant="destructive" onClick={() => void remove(item)}>
                Delete
              </Button>
            </div>
          )}
        />
      )}
      {listing.hasMore && (
        <div className="mt-6 flex justify-center">
          <Button type="button" variant="outline" disabled={listing.loadingMore} onClick={listing.loadMore}>
            {listing.loadingMore ? 'Loading…' : 'Load more'}
          </Button>
        </div>
      )}
      <Dialog open={editItem !== null} onOpenChange={(open) => { if (!open) setEditItem(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit media</DialogTitle>
            <DialogDescription>Fix the title, change its privacy, or attach a character.</DialogDescription>
          </DialogHeader>
          {editItem && (
            <div className="space-y-4">
              <div className="space-y-1">
                <Label htmlFor="edit-media-title">Title</Label>
                <Input id="edit-media-title" value={editTitle} onChange={(event) => setEditTitle(event.target.value)} disabled={editBusy} />
              </div>
              {characters.length > 0 && (
                <label className="grid gap-1 text-sm">
                  <span>Character</span>
                  <select
                    value={editCharacterId}
                    onChange={(event) => setEditCharacterId(event.target.value)}
                    disabled={editBusy}
                    className="w-full rounded-md border border-input bg-background px-3 py-2"
                  >
                    <option value="">No character</option>
                    {characters.map((character) => (
                      <option key={character.id} value={character.id}>{character.display_name}</option>
                    ))}
                  </select>
                </label>
              )}
              {editCharacterId !== '' ? (
                <p className="text-sm text-muted-foreground">Privacy follows the selected character.</p>
              ) : editItem.character_id !== null ? (
                <p className="text-sm text-muted-foreground">Detaching the character. Save, then reopen to set this item’s own privacy.</p>
              ) : (
                <>
                  <AudienceField
                    audience={editAudience}
                    onAudienceChange={setEditAudience}
                    selectedUserIds={editAudienceUserIds}
                    onSelectedUserIdsChange={setEditAudienceUserIds}
                    disabled={editBusy}
                    specificRelationship="mutuals"
                  />
                  <label className="flex items-start gap-2 text-sm">
                    <input type="checkbox" checked={editDiscoverable} onChange={(event) => setEditDiscoverable(event.target.checked)} disabled={editBusy} className="mt-0.5" />
                    <span>List in discovery — otherwise only people with the link can find it.</span>
                  </label>
                </>
              )}
            </div>
          )}
          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => setEditItem(null)} disabled={editBusy}>Cancel</Button>
            <Button type="button" onClick={() => void saveEdit()} disabled={editBusy}>{editBusy ? 'Saving…' : 'Save changes'}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('user-media');
if (mountEl) {
  createRoot(mountEl).render(<UserMediaPage />);
}
