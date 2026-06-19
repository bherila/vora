import { type FormEvent, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

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
import { readInitialData } from '@/initialData';
import { AUDIENCE_SELECT_OPTIONS } from '@/lib/audience';
import { generatePhotoDerivatives, generateVideoPoster, supportsClientDerivatives } from '@/media/imageProcessing';
import { MediaFilters } from '@/media/MediaFilters';
import { MediaGrid } from '@/media/MediaGrid';
import { type Audience, type MediaItem, type MediaTypeFilter, mediaTypeForFile,type PageMeta } from '@/media/types';
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
  data: MediaItem[];
  meta?: PageMeta;
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
  return readInitialData<{ userMedia?: InitialData }>().userMedia ?? { last_interest_ids: [], data: [] };
}

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : err instanceof Error ? err.message : 'Request failed.';
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

  const [typeFilter, setTypeFilter] = useState<MediaTypeFilter>('all');
  const [filterInterestIds, setFilterInterestIds] = useState<number[]>([]);
  const listing = useMediaListing('/api/media', { type: typeFilter, interestIds: filterInterestIds }, initial);

  const [dialogOpen, setDialogOpen] = useState(false);
  const [files, setFiles] = useState<File[]>([]);
  const [title, setTitle] = useState('');
  const [audience, setAudience] = useState<Audience>('everyone');
  const [discoverable, setDiscoverable] = useState(true);
  const [uploadInterestIds, setUploadInterestIds] = useState<number[]>(initial.last_interest_ids);
  const [uploading, setUploading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [uploadLabel, setUploadLabel] = useState('Uploading…');
  const uploadAbortRef = useRef<AbortController | null>(null);

  const resetForm = (): void => {
    setFiles([]);
    setTitle('');
    setAudience('everyone');
    setDiscoverable(true);
    setUploadInterestIds(initial.last_interest_ids);
    setProgress(0);
  };

  const upload = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();
    if (files.length === 0) {
      toast.error('Choose at least one file to upload.');
      return;
    }

    const unsupported = files.find((selectedFile) => mediaTypeForFile(selectedFile) === null);
    if (unsupported) {
      toast.error(`${unsupported.name} is not supported. Only image and video files can be uploaded.`);
      return;
    }

    const abortController = new AbortController();
    uploadAbortRef.current = abortController;
    setUploading(true);
    setProgress(0);
    let completedCount = 0;
    try {
      for (const [index, selectedFile] of files.entries()) {
        const type = mediaTypeForFile(selectedFile);
        if (type === null) {
          continue;
        }
        setUploadLabel(`Uploading ${index + 1} of ${files.length}: ${selectedFile.name}`);
        setProgress(0);
        const { thumbnail, perceptualHash } = await buildDerivatives(selectedFile, type);

        const sessionKey = multipartSessionKey(selectedFile);
        const existingSession = readMultipartSession(sessionKey);
        const created = existingSession === null
          ? (await fetchWrapper.post('/api/media', {
              type,
              filename: selectedFile.name,
              content_type: selectedFile.type,
              size: selectedFile.size,
              title: files.length === 1 ? title.trim() || null : selectedFile.name,
              audience,
              discoverable,
              interest_ids: uploadInterestIds,
              has_thumbnail: thumbnail !== null,
              perceptual_hash: perceptualHash,
            })) as StoreResponse
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

      toast.success(files.length === 1 ? 'Upload complete. It will be reviewed before others can see it.' : 'Uploads complete. They will be reviewed before others can see them.');
      setDialogOpen(false);
      resetForm();
      listing.reload();
    } catch (err) {
      if (completedCount > 0) {
        setFiles(files.slice(completedCount));
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
    if (!window.confirm('Delete this item? This cannot be undone.')) {
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
                files={files}
                label="Drop photos or videos here"
                multiple
                onFilesChange={setFiles}
                disabled={uploading}
                helperText="Select one or more files. Each file uploads in its own request."
              />
              <Input
                value={title}
                onChange={(event) => setTitle(event.target.value)}
                placeholder="Title (optional)"
                disabled={uploading}
              />
              <label className="grid gap-1">
                <span className="text-sm">Who can see this?</span>
                <select
                  value={audience}
                  onChange={(event) => setAudience(event.target.value as Audience)}
                  disabled={uploading}
                  className="w-full rounded-md border border-input bg-background px-3 py-2"
                >
                  {AUDIENCE_SELECT_OPTIONS.map((option) => (
                    <option key={option.value} value={option.value}>{option.label}</option>
                  ))}
                </select>
              </label>
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

      {listing.loading ? (
        <p className="text-muted-foreground">Loading…</p>
      ) : listing.error ? (
        <p className="text-destructive">{listing.error}</p>
      ) : listing.items.length === 0 ? (
        <p className="text-muted-foreground">Nothing here yet.</p>
      ) : (
        <MediaGrid
          items={listing.items}
          renderActions={(item) => (
            <Button type="button" size="sm" variant="destructive" onClick={() => void remove(item)}>
              Delete
            </Button>
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
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('user-media');
if (mountEl) {
  createRoot(mountEl).render(<UserMediaPage />);
}
