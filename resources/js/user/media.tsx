import { type FormEvent, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import { InterestPicker } from '@/components/interest-picker';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { fetchWrapper } from '@/fetchWrapper';
import { generatePhotoDerivatives, generateVideoPoster, supportsClientDerivatives } from '@/media/imageProcessing';
import { MediaFilters } from '@/media/MediaFilters';
import { MediaGrid } from '@/media/MediaGrid';
import { type MediaItem, type MediaTypeFilter, mediaTypeForFile, type VisibilityValue } from '@/media/types';
import { putToSignedUrl } from '@/media/upload';
import { useMediaListing } from '@/media/useMediaListing';

interface InitialData {
  last_interest_ids: number[];
}

interface StoreResponse {
  data: MediaItem;
  upload_url: string;
  upload_headers: Record<string, string>;
  thumbnail_upload_url: string | null;
  thumbnail_upload_headers: Record<string, string> | null;
}

function getInitialData(): InitialData {
  const el = document.getElementById('user-media-initial-data');
  if (!el?.textContent) {
    return { last_interest_ids: [] };
  }
  try {
    const parsed = JSON.parse(el.textContent) as Partial<InitialData>;
    return { last_interest_ids: parsed.last_interest_ids ?? [] };
  } catch {
    return { last_interest_ids: [] };
  }
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

function UserMediaPage() {
  const initial = useMemo(() => getInitialData(), []);

  const [typeFilter, setTypeFilter] = useState<MediaTypeFilter>('all');
  const [filterInterestIds, setFilterInterestIds] = useState<number[]>([]);
  const listing = useMediaListing('/api/media', { type: typeFilter, interestIds: filterInterestIds });

  const [dialogOpen, setDialogOpen] = useState(false);
  const [file, setFile] = useState<File | null>(null);
  const [title, setTitle] = useState('');
  const [visibility, setVisibility] = useState<VisibilityValue>('users');
  const [uploadInterestIds, setUploadInterestIds] = useState<number[]>(initial.last_interest_ids);
  const [uploading, setUploading] = useState(false);
  const [progress, setProgress] = useState(0);

  const resetForm = (): void => {
    setFile(null);
    setTitle('');
    setVisibility('users');
    setUploadInterestIds(initial.last_interest_ids);
    setProgress(0);
  };

  const upload = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();
    if (!file) {
      toast.error('Choose a file to upload.');
      return;
    }
    const type = mediaTypeForFile(file);
    if (type === null) {
      toast.error('Only image and video files are supported.');
      return;
    }

    setUploading(true);
    setProgress(0);
    try {
      const { thumbnail, perceptualHash } = await buildDerivatives(file, type);

      const created = (await fetchWrapper.post('/api/media', {
        type,
        filename: file.name,
        content_type: file.type,
        size: file.size,
        title: title.trim() || null,
        visibility,
        interest_ids: uploadInterestIds,
        has_thumbnail: thumbnail !== null,
        perceptual_hash: perceptualHash,
      })) as StoreResponse;

      await putToSignedUrl(created.upload_url, file, created.upload_headers, (fraction) => {
        setProgress(Math.round(fraction * 100));
      });

      // Best-effort: if the thumbnail PUT fails, completion drops the key and the
      // item still works, just without a generated preview.
      if (thumbnail && created.thumbnail_upload_url && created.thumbnail_upload_headers) {
        try {
          await putToSignedUrl(created.thumbnail_upload_url, thumbnail, created.thumbnail_upload_headers, () => {});
        } catch {
          /* ignore — thumbnail is optional */
        }
      }

      await fetchWrapper.post(`/api/media/${created.data.id}/complete`, {});

      toast.success('Upload complete. It will be reviewed before others can see it.');
      setDialogOpen(false);
      resetForm();
      listing.reload();
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setUploading(false);
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
        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogTrigger asChild>
            <Button>Upload</Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Upload media</DialogTitle>
            </DialogHeader>
            <form onSubmit={(event) => void upload(event)} className="grid gap-4">
              <Input
                type="file"
                accept="image/*,video/*"
                onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                disabled={uploading}
                required
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
                  value={visibility}
                  onChange={(event) => setVisibility(event.target.value as VisibilityValue)}
                  disabled={uploading}
                  className="w-full rounded-md border border-input bg-background px-3 py-2"
                >
                  <option value="users">Any user</option>
                  <option value="unlisted">Only people with the link</option>
                </select>
              </label>
              <div className="grid gap-1">
                <span className="text-sm">Interests</span>
                <InterestPicker value={uploadInterestIds} onChange={setUploadInterestIds} disabled={uploading} />
              </div>
              <div className="flex items-center gap-3">
                <Button type="submit" disabled={uploading}>
                  {uploading ? 'Uploading…' : 'Upload'}
                </Button>
                {uploading && (
                  <span className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Spinner /> {progress}%
                  </span>
                )}
              </div>
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
