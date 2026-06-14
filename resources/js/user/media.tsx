import { type FormEvent, useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import { InterestPicker } from '@/components/interest-picker';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { MediaPlayer } from '@/media/MediaPlayer';
import { formatBytes, type MediaItem, mediaTypeForFile, type VisibilityValue } from '@/media/types';
import { putToSignedUrl } from '@/media/upload';

interface InitialData {
  last_interest_ids: number[];
}

interface StoreResponse {
  data: MediaItem;
  upload_url: string;
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

function UserMediaPage() {
  const initial = useMemo(() => getInitialData(), []);
  const [items, setItems] = useState<MediaItem[]>([]);
  const [loading, setLoading] = useState(true);

  const [dialogOpen, setDialogOpen] = useState(false);
  const [file, setFile] = useState<File | null>(null);
  const [title, setTitle] = useState('');
  const [visibility, setVisibility] = useState<VisibilityValue>('users');
  const [interestIds, setInterestIds] = useState<number[]>(initial.last_interest_ids);
  const [uploading, setUploading] = useState(false);
  const [progress, setProgress] = useState(0);

  const loadItems = async (): Promise<void> => {
    setLoading(true);
    try {
      const response = (await fetchWrapper.get('/api/media')) as { data: MediaItem[] };
      setItems(response.data ?? []);
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadItems();
  }, []);

  const resetForm = (): void => {
    setFile(null);
    setTitle('');
    setVisibility('users');
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
      const created = (await fetchWrapper.post('/api/media', {
        type,
        filename: file.name,
        content_type: file.type,
        size: file.size,
        title: title.trim() || null,
        visibility,
        interest_ids: interestIds,
      })) as StoreResponse;

      await putToSignedUrl(created.upload_url, file, file.type, (fraction) => {
        setProgress(Math.round(fraction * 100));
      });

      await fetchWrapper.post(`/api/media/${created.data.id}/complete`, {});

      toast.success('Upload complete. It will be reviewed before others can see it.');
      setDialogOpen(false);
      resetForm();
      await loadItems();
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
      setItems((current) => current.filter((m) => m.id !== item.id));
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
                <InterestPicker value={interestIds} onChange={setInterestIds} disabled={uploading} />
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

      {loading ? (
        <p className="text-muted-foreground">Loading…</p>
      ) : items.length === 0 ? (
        <p className="text-muted-foreground">You haven&apos;t uploaded anything yet.</p>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {items.map((item) => (
            <Card key={item.id}>
              <CardHeader>
                <CardTitle className="truncate text-base">{item.title || item.original_filename}</CardTitle>
              </CardHeader>
              <CardContent className="grid gap-2">
                <div className="overflow-hidden rounded-md bg-muted">
                  <MediaPlayer item={item} className="max-h-48 w-full object-contain" />
                </div>
                <dl className="text-xs text-muted-foreground">
                  <div className="flex justify-between">
                    <dt>Type</dt>
                    <dd>{item.type}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt>Size</dt>
                    <dd>{formatBytes(item.size_bytes)}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt>Visibility</dt>
                    <dd>{item.visibility === 'users' ? 'Any user' : 'Link only'}</dd>
                  </div>
                </dl>
                {item.interests.length > 0 && (
                  <p className="text-xs text-muted-foreground">{item.interests.map((i) => i.name).join(', ')}</p>
                )}
                <div className="flex gap-2">
                  <Button type="button" size="sm" variant="outline" onClick={() => { window.location.href = `/m/${item.ulid}`; }}>
                    Open
                  </Button>
                  <Button type="button" size="sm" variant="destructive" onClick={() => void remove(item)}>
                    Delete
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
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
