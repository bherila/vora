import { type FormEvent, type ReactNode, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { AudienceField } from '@/community/AudienceField';
import { InterestPicker } from '@/components/interest-picker';
import { FileDropzone } from '@/components/media/FileDropzone';
import { UploadProgress } from '@/components/media/UploadProgress';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { fetchWrapper } from '@/fetchWrapper';
import { computeFileHash, generatePhotoDerivatives, generateVideoPoster, supportsClientDerivatives } from '@/media/imageProcessing';
import { type Audience, type MediaItem, mediaTypeForFile } from '@/media/types';
import {
  type CompletedMultipartPart,
  type MultipartPartToSign,
  type MultipartUploadSession,
  putToSignedUrl,
  readMultipartSession,
  saveMultipartSession,
  uploadMultipartFile,
} from '@/media/upload';

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

interface MediaUploadDialogProps {
  /** Characters the upload can be attached to (privacy is inherited from them). */
  characters: CharacterOption[];
  /** Identity the dialog opens scoped to: a character id, or null for the user. */
  defaultCharacterId?: number | null;
  /** Interests pre-selected from the user's last upload. */
  lastInterestIds: number[];
  /** Called after at least one upload completes, so the caller can refresh. */
  onUploaded: () => void;
  /** Label for the trigger button (defaults to "Upload media"). */
  triggerLabel?: ReactNode;
  triggerSize?: 'default' | 'sm';
  triggerVariant?: 'default' | 'outline';
}

/**
 * The media upload flow as a self-contained dialog. Media is uploaded *on* a
 * profile (the user's own or one of their characters), so this lives next to the
 * profile container and is reused wherever an owner can add media.
 */
export function MediaUploadDialog({
  characters,
  defaultCharacterId = null,
  lastInterestIds,
  onUploaded,
  triggerLabel = 'Upload media',
  triggerSize = 'default',
  triggerVariant = 'default',
}: MediaUploadDialogProps) {
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState<PendingUpload[]>([]);
  const [characterId, setCharacterId] = useState(defaultCharacterId === null ? '' : String(defaultCharacterId));
  const [audience, setAudience] = useState<Audience>('everyone');
  const [audienceUserIds, setAudienceUserIds] = useState<number[]>([]);
  const [discoverable, setDiscoverable] = useState(true);
  const [uploadInterestIds, setUploadInterestIds] = useState<number[]>(lastInterestIds);
  const [uploading, setUploading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [uploadLabel, setUploadLabel] = useState('Uploading…');
  const uploadAbortRef = useRef<AbortController | null>(null);

  // Re-scope to the active identity each time the dialog opens.
  useEffect(() => {
    if (open) {
      setCharacterId(defaultCharacterId === null ? '' : String(defaultCharacterId));
    }
  }, [open, defaultCharacterId]);

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
    setCharacterId(defaultCharacterId === null ? '' : String(defaultCharacterId));
    setAudience('everyone');
    setAudienceUserIds([]);
    setDiscoverable(true);
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
    try {
      for (const [index, item] of pending.entries()) {
        const selectedFile = item.file;
        const type = mediaTypeForFile(selectedFile);
        if (type === null) {
          continue;
        }
        setUploadLabel(`Uploading ${index + 1} of ${pending.length}: ${selectedFile.name}`);
        setProgress(0);
        const [{ thumbnail, perceptualHash }, fileHash] = await Promise.all([
          buildDerivatives(selectedFile, type),
          computeFileHash(selectedFile),
        ]);

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
          file_hash: fileHash,
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
            presignParts: async (parts: MultipartPartToSign[]) => {
              const response = (await fetchWrapper.post(`/api/media/${session.mediaId}/multipart/parts`, {
                upload_id: session.uploadId,
                part_numbers: parts.map((part) => part.partNumber),
                part_sizes: Object.fromEntries(parts.map((part) => [part.partNumber, part.sizeBytes])),
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
              });
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
      setOpen(false);
      resetForm();
      onUploaded();
    } catch (err) {
      if (completedCount > 0) {
        setPending(pending.slice(completedCount));
        onUploaded();
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
  );
}
