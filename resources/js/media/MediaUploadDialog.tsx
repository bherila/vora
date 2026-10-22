import { type FormEvent, type ReactNode, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { AudienceField } from '@/community/AudienceField';
import { InterestPicker } from '@/components/interest-picker';
import { FileDropzone } from '@/components/media/FileDropzone';
import { UploadProgress } from '@/components/media/UploadProgress';
import { RestrictionNotice } from '@/components/restriction-notice';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useActiveIdentityId } from '@/identity';
import { computeFileHash, generatePhotoDerivatives, generateVideoPoster, supportsClientDerivatives } from '@/media/imageProcessing';
import { type Audience, type MediaItem, mediaTypeForFile } from '@/media/types';
import {
  type CompletedMultipartPart,
  type MultipartPartToSign,
  type MultipartUploadSession,
  putToSignedUrl,
  readMultipartSession,
  removeMultipartSession,
  saveMultipartSession,
  uploadMultipartFile,
  withRetries,
} from '@/media/upload';
import { postUpload, UploadHttpError } from '@/media/uploadRequest';
import { activeRestriction } from '@/restrictions';

/** A character the upload can be associated with (privacy is inherited from it). */
export interface CharacterOption {
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
  data: { upload_id: string; part_size_bytes: number; expires_in_minutes: number };
}

interface PresignMultipartPartsResponse {
  data: { part_number: number; url: string; headers: Record<string, string> }[];
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

function multipartSessionKey(fileHash: string | null, scope: string): string {
  // Metadata can collide for different files. Never resume without verified bytes.
  return `vora:media:multipart:v2:${scope}:${fileHash ?? crypto.randomUUID()}`;
}

async function beginMultipartSession(
  created: StoreResponse | null,
  sessionKey: string,
  context: string,
  signal: AbortSignal,
): Promise<MultipartUploadSession> {
  if (created === null) {
    throw new Error('Upload session is missing.');
  }

  const init = await postUpload<InitMultipartResponse>(`/api/media/${created.data.id}/multipart/init`, {}, signal);
  const session = {
    mediaId: created.data.id,
    uploadId: init.data.upload_id,
    partSizeBytes: init.data.part_size_bytes,
    completedParts: [],
    createdAt: new Date().toISOString(),
    context,
  };

  saveMultipartSession(sessionKey, session);

  return session;
}

/** Release only an owned pending reservation; a ready conflict never deletes it. */
async function releaseStoredSession(session: MultipartUploadSession, key: string): Promise<boolean> {
  let ready = false;
  try {
    await postUpload(`/api/media/${session.mediaId}/cancel-upload`, {});
  } catch (error) {
    if (!(error instanceof UploadHttpError)) throw error;
    if (error.status === 409) ready = true;
    else if (error.status !== 404) throw error;
  }
  // Keep recoverable state if cancellation failed for any other reason.
  removeMultipartSession(key);
  return ready;
}

async function uploadThumbnailBestEffort(
  created: StoreResponse,
  thumbnail: Blob | null,
  signal: AbortSignal,
): Promise<void> {
  if (!thumbnail || !created.thumbnail_upload_url || !created.thumbnail_upload_headers) {
    return;
  }

  const { thumbnail_upload_url: url, thumbnail_upload_headers: headers } = created;
  try {
    await withRetries(() => putToSignedUrl(url, thumbnail, headers, () => {}, { signal }), signal);
  } catch (err) {
    if (err instanceof DOMException && err.name === 'AbortError') {
      throw err;
    }
    /* ignore — thumbnail is optional */
  }
}

interface MediaUploadDialogProps {
  /** Characters the upload can be attached to (privacy is inherited from them). */
  characters: CharacterOption[];
  /** Explicit profile identity; omit to use the navbar's active authoring identity. */
  defaultCharacterId?: number | null;
  /** Interests pre-selected from the user's last upload. */
  lastInterestIds: number[];
  /** Called after at least one upload completes, so the caller can refresh. */
  onUploaded: () => void;
  /** Label for the trigger button (defaults to "Upload media"). */
  triggerLabel?: ReactNode;
  triggerSize?: 'default' | 'sm';
  triggerVariant?: 'default' | 'outline';
  /** Defined (including null) for private Library uploads. */
  libraryCollectionUlid?: string | null;
}

/**
 * The media upload flow as a self-contained dialog. Media is uploaded *on* a
 * profile (the user's own or one of their characters), so this lives next to the
 * profile container and is reused wherever an owner can add media.
 */
export function MediaUploadDialog({
  characters,
  defaultCharacterId,
  lastInterestIds,
  onUploaded,
  triggerLabel = 'Upload media',
  triggerSize = 'default',
  triggerVariant = 'default',
  libraryCollectionUlid,
}: MediaUploadDialogProps) {
  const uploadRestriction = activeRestriction('media.upload');
  const activeIdentityId = useActiveIdentityId();
  const isLibrary = libraryCollectionUlid !== undefined;
  const resolvedDefaultCharacterId = isLibrary ? null : defaultCharacterId === undefined ? activeIdentityId : defaultCharacterId;
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState<PendingUpload[]>([]);
  const [characterId, setCharacterId] = useState(resolvedDefaultCharacterId === null ? '' : String(resolvedDefaultCharacterId));
  const [audience, setAudience] = useState<Audience>('everyone');
  const [audienceUserIds, setAudienceUserIds] = useState<number[]>([]);
  const [discoverable, setDiscoverable] = useState(true);
  const [announce, setAnnounce] = useState(true);
  const [uploadInterestIds, setUploadInterestIds] = useState<number[]>(lastInterestIds);
  const [uploading, setUploading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [uploadLabel, setUploadLabel] = useState('Uploading…');
  const uploadAbortRef = useRef<AbortController | null>(null);

  // Re-scope to the active identity each time the dialog opens.
  useEffect(() => {
    if (open) {
      setCharacterId(resolvedDefaultCharacterId === null ? '' : String(resolvedDefaultCharacterId));
    }
  }, [open, resolvedDefaultCharacterId]);

  const handleFilesChange = (newFiles: File[]): void => {
    setPending((prev) => newFiles.map((file) => {
      const existing = prev.find((item) => sameFile(item.file, file));
      return { file, title: existing ? existing.title : defaultTitleForFile(file) };
    }));
  };

  const setTitleAt = (index: number, value: string): void => {
    setPending((prev) => prev.map((item, i) => (i === index ? { ...item, title: value } : item)));
  };

  const resetForm = (): void => {
    setPending([]);
    setCharacterId(resolvedDefaultCharacterId === null ? '' : String(resolvedDefaultCharacterId));
    setAudience('everyone');
    setAudienceUserIds([]);
    setDiscoverable(true);
    setAnnounce(true);
    setUploadInterestIds(lastInterestIds);
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
    let disposableUploadId: number | null = null;
    try {
      for (const [index, item] of pending.entries()) {
        const selectedFile = item.file;
        const type = mediaTypeForFile(selectedFile);
        if (type === null) {
          continue;
        }
        setUploadLabel(`Preparing ${index + 1} of ${pending.length}: ${selectedFile.name}`);
        setProgress(0);
        const [{ thumbnail, perceptualHash }, fileHash] = await Promise.all([
          buildDerivatives(selectedFile, type),
          computeFileHash(selectedFile, (fraction) => setProgress(fraction * 100), abortController.signal),
        ]);

        abortController.signal.throwIfAborted();
        setUploadLabel(`Uploading ${index + 1} of ${pending.length}: ${selectedFile.name}`);
        setProgress(0);
        const scope = isLibrary ? `library:${libraryCollectionUlid ?? 'all'}` : `gallery:${selectedCharacterId ?? 'account'}`;
        const sessionKey = multipartSessionKey(fileHash, scope);
        const uploadPayload = {
          type,
          filename: selectedFile.name,
          content_type: selectedFile.type,
          size: selectedFile.size,
          title: item.title.trim() || null,
          interest_ids: uploadInterestIds,
          has_thumbnail: thumbnail !== null,
          thumbnail_size: thumbnail?.size ?? null,
          perceptual_hash: perceptualHash,
          file_hash: fileHash,
          announce,
          ...(isLibrary ? {
            audience: 'specific', audience_user_ids: [], discoverable: false, announce: false,
            collection_ulid: libraryCollectionUlid,
          } : selectedCharacterId === null ? {
            audience,
            audience_user_ids: audience === 'specific' ? audienceUserIds : [],
            discoverable,
          } : {
            character_id: selectedCharacterId,
          }),
        };
        const context = JSON.stringify({
          audience: 'audience' in uploadPayload ? uploadPayload.audience : null,
          audience_user_ids: [...new Set('audience_user_ids' in uploadPayload ? uploadPayload.audience_user_ids : [])].sort((a, b) => a - b),
          discoverable: 'discoverable' in uploadPayload ? uploadPayload.discoverable : null,
          announce: uploadPayload.announce,
          character_id: 'character_id' in uploadPayload ? uploadPayload.character_id : null,
          title: uploadPayload.title,
          interest_ids: [...new Set(uploadPayload.interest_ids)].sort((a, b) => a - b),
          collection_ulid: isLibrary ? libraryCollectionUlid : null,
        });
        // Metadata-only keys cannot establish matching bytes, but their old
        // reservations must be released before a replacement can consume quota.
        const legacyKey = `${isLibrary ? `library:${libraryCollectionUlid ?? 'all'}:` : ''}vora:media:multipart:${selectedFile.name}:${selectedFile.size}:${selectedFile.lastModified}:${selectedFile.type}`;
        const legacySession = readMultipartSession(legacyKey);
        if (legacySession !== null) await releaseStoredSession(legacySession, legacyKey);

        let existingSession = fileHash === null ? null : readMultipartSession(sessionKey);
        if (existingSession !== null && existingSession.context !== context) {
          const ready = await releaseStoredSession(existingSession, sessionKey);
          existingSession = null;
          if (ready) {
            throw new Error('This upload already completed with different or unverified settings. Update the existing item’s settings before trying again.');
          }
        }
        const createUpload = async (): Promise<StoreResponse> => {
          const result = await postUpload<StoreResponse>(
            isLibrary ? '/api/library/uploads' : '/api/media', uploadPayload, abortController.signal,
          );
          disposableUploadId = result.data.id;
          return result;
        };
        const created = existingSession === null ? await createUpload() : null;

        const shouldMultipart = existingSession !== null
          || (created?.multipart.enabled === true && selectedFile.size >= created.multipart.threshold_bytes);

        if (shouldMultipart) {
          const runMultipart = (session: MultipartUploadSession, target: StoreResponse | null): Promise<void> => uploadMultipartFile(selectedFile, {
            sessionKey,
            session,
            signal: abortController.signal,
            onProgress: (fraction) => setProgress(fraction * 100),
            presignParts: async (parts: MultipartPartToSign[]) => {
              const response = await postUpload<PresignMultipartPartsResponse>(`/api/media/${session.mediaId}/multipart/parts`, {
                upload_id: session.uploadId,
                part_numbers: parts.map((part) => part.partNumber),
                part_sizes: Object.fromEntries(parts.map((part) => [part.partNumber, part.sizeBytes])),
              }, abortController.signal);

              return response.data;
            },
            complete: async (parts: CompletedMultipartPart[]) => {
              if (target !== null) {
                await uploadThumbnailBestEffort(target, thumbnail, abortController.signal);
              }

              await postUpload(`/api/media/${session.mediaId}/multipart/complete`, {
                upload_id: session.uploadId,
                parts,
              }, abortController.signal);
            },
            abort: async () => {
              await postUpload(`/api/media/${session.mediaId}/multipart/abort`, {
                upload_id: session.uploadId,
              });
            },
          });
          try {
            const session = existingSession ?? await beginMultipartSession(created, sessionKey, context, abortController.signal);
            // Retain failed sessions only when their content hash makes them resumable.
            if (fileHash !== null) disposableUploadId = null;
            await runMultipart(session, created);
          } catch (err) {
            if (existingSession === null || !(err instanceof UploadHttpError) || ![404, 422].includes(err.status)) throw err;
            // Matching bytes and settings plus an owner-only ready conflict
            // recover a lost completion response without allocating new media.
            const alreadyComplete = await releaseStoredSession(existingSession, sessionKey);
            if (!alreadyComplete) {
              abortController.signal.throwIfAborted();
              const replacement = await createUpload();
              const session = await beginMultipartSession(replacement, sessionKey, context, abortController.signal);
              disposableUploadId = null;
              await runMultipart(session, replacement);
            }
          } finally {
            if (fileHash === null) removeMultipartSession(sessionKey);
          }
        } else if (created !== null) {
          await withRetries(() => putToSignedUrl(created.upload_url, selectedFile, created.upload_headers, (fraction) => {
            setProgress(fraction * 100);
          }, { signal: abortController.signal }), abortController.signal);

          await uploadThumbnailBestEffort(created, thumbnail, abortController.signal);
          await withRetries(() => postUpload(`/api/media/${created.data.id}/complete`, {}, abortController.signal), abortController.signal);
        }
        disposableUploadId = null;
        completedCount += 1;
      }

      toast.success(pending.length === 1 ? 'Upload complete. It will be reviewed before others can see it.' : 'Uploads complete. They will be reviewed before others can see them.');
      setOpen(false);
      resetForm();
      onUploaded();
    } catch (err) {
      if (disposableUploadId !== null) {
        try {
          await postUpload(`/api/media/${disposableUploadId}/cancel-upload`, {});
        } catch {
          // A completed upload is protected by the server. Other abandoned
          // uploads remain visible to scheduled cleanup if cancellation fails.
        }
      }
      if (completedCount > 0) {
        setPending(pending.slice(completedCount));
      }
      onUploaded();

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

  if (uploadRestriction) {
    return <RestrictionNotice restriction={uploadRestriction} />;
  }

  return (
    <Dialog open={open} onOpenChange={(next) => { if (!uploading) setOpen(next); }}>
      <DialogTrigger asChild>
        <Button size={triggerSize} variant={triggerVariant}>{triggerLabel}</Button>
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
          {isLibrary ? (
            <p className="text-sm text-muted-foreground">
              Uploads stay in your private Library. If you selected a shared collection, its recipients can view and save new uploads after review.
            </p>
          ) : characterId === '' ? (
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
          {!isLibrary && <><div className="grid gap-1">
            <span className="text-sm">Interests</span>
            <InterestPicker value={uploadInterestIds} onChange={setUploadInterestIds} disabled={uploading} />
          </div>
          <label className="flex items-start gap-2 text-sm">
            <input
              type="checkbox"
              checked={announce}
              onChange={(event) => setAnnounce(event.target.checked)}
              disabled={uploading}
              className="mt-0.5"
            />
            <span>Share this to your followers&apos; feeds. Unchecking keeps the upload private to your profile.</span>
          </label></>}
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
  );
}
