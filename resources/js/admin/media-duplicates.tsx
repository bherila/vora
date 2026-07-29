import { ExternalLink, ShieldAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { fetchWrapper } from '@/fetchWrapper';
import { MediaPlayer } from '@/media/MediaPlayer';
import type { AdminMediaItem } from '@/media/types';

type ClusterSort = 'size_desc' | 'newest_desc';

interface AdminDuplicateCluster {
  id: string;
  media_count: number;
  account_count: number;
  newest_at: string | null;
  media: AdminMediaItem[];
}

interface ClusterResponse {
  data: AdminDuplicateCluster[];
  meta?: {
    duplicate_scan?: DuplicateScanStatus;
  };
}

interface DuplicateScanStatus {
  truncated: boolean;
  scanned_media_count: number;
  scan_limit: number;
}

function getErrorMessage(error: unknown): string {
  return typeof error === 'string' ? error : 'Request failed.';
}

function accountName(item: AdminMediaItem): string {
  return item.user.name ?? item.user.email ?? `Account #${item.user.id}`;
}

export function AdminMediaDuplicatesPage() {
  const [clusters, setClusters] = useState<AdminDuplicateCluster[]>([]);
  const [sort, setSort] = useState<ClusterSort>('size_desc');
  const [scanStatus, setScanStatus] = useState<DuplicateScanStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [queued, setQueued] = useState<Set<number>>(() => new Set());
  const [busy, setBusy] = useState<Set<number>>(() => new Set());

  useEffect(() => {
    let active = true;
    setLoading(true);
    fetchWrapper
      .get(`/api/admin/media-duplicates?sort=${sort}`)
      .then((response) => {
        if (active) {
          const payload = response as ClusterResponse;
          setClusters(payload.data ?? []);
          setScanStatus(payload.meta?.duplicate_scan ?? null);
        }
      })
      .catch((error) => {
        if (active) toast.error(getErrorMessage(error));
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [sort]);

  const queueForReview = async (item: AdminMediaItem): Promise<void> => {
    setBusy((current) => new Set(current).add(item.id));
    try {
      await fetchWrapper.post(`/api/admin/media/${item.id}/duplicate-review`, {});
      setQueued((current) => new Set(current).add(item.id));
      toast.success('Media sent to abuse review.');
    } catch (error) {
      toast.error(getErrorMessage(error));
    } finally {
      setBusy((current) => {
        const next = new Set(current);
        next.delete(item.id);
        return next;
      });
    }
  };

  return (
    <div className="mx-auto max-w-7xl space-y-6 px-4 py-8">
      <div>
        <h1 className="text-2xl font-bold">Cross-account duplicate clusters</h1>
        <p className="text-muted-foreground">
          Admin-only PDQ matches across different accounts. A match is a review signal, not an automatic enforcement decision.
        </p>
      </div>

      {scanStatus?.truncated && (
        <p role="alert" className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-800">
          Only the newest {scanStatus.scanned_media_count} eligible photos were scanned.
          Older photos may contain additional matches.
        </p>
      )}

      <div className="flex flex-wrap gap-2" aria-label="Cluster sort order">
        <Button
          type="button"
          size="sm"
          variant={sort === 'size_desc' ? 'default' : 'outline'}
          onClick={() => setSort('size_desc')}
        >
          Largest clusters
        </Button>
        <Button
          type="button"
          size="sm"
          variant={sort === 'newest_desc' ? 'default' : 'outline'}
          onClick={() => setSort('newest_desc')}
        >
          Newest activity
        </Button>
      </div>

      {loading ? (
        <p className="text-muted-foreground">Loading…</p>
      ) : clusters.length === 0 ? (
        <p className="text-muted-foreground">No cross-account PDQ clusters found.</p>
      ) : (
        <div className="space-y-6">
          {clusters.map((cluster) => (
            <section key={cluster.id} aria-labelledby={`${cluster.id}-title`}>
              <Card>
                <CardHeader>
                  <CardTitle id={`${cluster.id}-title`} className="flex flex-wrap items-center gap-2 text-base">
                    <ShieldAlert className="h-4 w-4 text-amber-600" aria-hidden="true" />
                    {cluster.media_count} media across {cluster.account_count} accounts
                  </CardTitle>
                </CardHeader>
                <CardContent className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                  {cluster.media.map((item) => (
                    <article key={item.id} className="space-y-3 rounded-md border border-border p-3">
                      <div className="overflow-hidden rounded-md bg-muted">
                        <MediaPlayer item={item} className="max-h-52 w-full object-contain" />
                      </div>
                      <div className="space-y-1 text-sm">
                        <a
                          className="flex items-center gap-1 font-medium underline underline-offset-4"
                          href={`/m/${item.ulid}`}
                          target="_blank"
                          rel="noreferrer"
                        >
                          {item.title || item.original_filename}
                          <ExternalLink className="h-3 w-3" aria-hidden="true" />
                        </a>
                        <a
                          className="block text-muted-foreground underline underline-offset-4"
                          href={`/admin/users#user-${item.user.id}`}
                        >
                          {accountName(item)} ({item.user.email})
                        </a>
                        <Badge variant="outline">{item.moderation_status}</Badge>
                      </div>
                      {queued.has(item.id) ? (
                        <Button asChild type="button" size="sm" variant="outline">
                          <a href="/admin/reports">Open abuse reports</a>
                        </Button>
                      ) : (
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          disabled={busy.has(item.id)}
                          onClick={() => void queueForReview(item)}
                        >
                          Send to abuse review
                        </Button>
                      )}
                    </article>
                  ))}
                </CardContent>
              </Card>
            </section>
          ))}
        </div>
      )}
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('admin-media-duplicates');
if (mountEl) {
  createRoot(mountEl).render(<AdminMediaDuplicatesPage />);
}
