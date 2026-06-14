import { useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { fetchWrapper } from '@/fetchWrapper';
import { MediaPlayer } from '@/media/MediaPlayer';
import { formatBytes, type MediaItem } from '@/media/types';

function getUlid(): string | null {
  const el = document.getElementById('media-view-initial-data');
  if (!el?.textContent) {
    return null;
  }
  try {
    return (JSON.parse(el.textContent) as { ulid?: string }).ulid ?? null;
  } catch {
    return null;
  }
}

function MediaViewPage() {
  const ulid = useMemo(() => getUlid(), []);
  const [item, setItem] = useState<MediaItem | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!ulid) {
      setError('Media not found.');
      setLoading(false);
      return;
    }
    void (async () => {
      try {
        const response = (await fetchWrapper.get(`/api/media/by-ulid/${ulid}`)) as { data: MediaItem };
        setItem(response.data);
      } catch {
        setError('This media is unavailable.');
      } finally {
        setLoading(false);
      }
    })();
  }, [ulid]);

  return (
    <div className="mx-auto max-w-3xl px-4 py-8">
      {loading ? (
        <p className="text-muted-foreground">Loading…</p>
      ) : error || !item ? (
        <p className="text-muted-foreground">{error || 'This media is unavailable.'}</p>
      ) : (
        <Card>
          <CardHeader>
            <CardTitle>{item.title || item.original_filename}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3">
            <div className="overflow-hidden rounded-md bg-muted">
              <MediaPlayer item={item} className="max-h-[70vh] w-full object-contain" />
            </div>
            <p className="text-xs text-muted-foreground">
              {item.type} · {formatBytes(item.size_bytes)}
            </p>
            {item.interests.length > 0 && (
              <p className="text-sm text-muted-foreground">{item.interests.map((i) => i.name).join(', ')}</p>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

const mountEl = document.getElementById('media-view');
if (mountEl) {
  createRoot(mountEl).render(<MediaViewPage />);
}
