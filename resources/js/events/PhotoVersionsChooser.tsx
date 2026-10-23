import { useEffect, useId, useState } from 'react';

import { ProtectedImage } from '@/components/protected-image';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { eventError, type EventMediaItem } from '@/events/types';
import { fetchWrapper } from '@/fetchWrapper';
import { formatBytes, type PageMeta } from '@/media/types';

interface PhotoVersionsChooserProps {
  item: EventMediaItem;
  onChoose: (ulid: string) => void;
  disabled?: boolean;
}

interface VariantsResponse {
  data: EventMediaItem[];
  meta: PageMeta;
  preferred_ulid: string;
}

interface PhotoDetailsProps {
  item: EventMediaItem;
}

function photoFormat(item: EventMediaItem): string {
  if (item.mime_type === 'image/jpeg') return 'JPEG';
  return item.mime_type.replace(/^image\//, '').toUpperCase();
}

/** Describe presence only. Original metadata, including coordinates, stays in the file. */
export function PhotoDetails({ item }: PhotoDetailsProps) {
  const details = item.photo_details;
  const metadata = !details?.metadata_known ? 'Metadata not inspected' : [
    details.has_capture_time && 'Capture time',
    details.has_camera && 'Camera details',
    details.has_gps && 'Location metadata',
  ].filter(Boolean).join(' · ') || 'No capture, camera or location metadata detected';

  return <span className="grid gap-1 text-xs text-muted-foreground">
    <span>{photoFormat(item)} · {details?.width && details.height ? `${details.width} × ${details.height} pixels · ` : ''}{formatBytes(item.size_bytes)}</span>
    <span>{metadata}</span>
  </span>;
}

export function PhotoVersionsChooser({ item, onChoose, disabled = false }: PhotoVersionsChooserProps) {
  const [open, setOpen] = useState(false);
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [result, setResult] = useState<VariantsResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const panelId = useId();

  useEffect(() => {
    if (!open) return;
    let active = true;
    setLoading(true);
    setError('');
    setResult(null);
    fetchWrapper.get(`/api/events/media/${encodeURIComponent(item.ulid)}/variants?page=${page}`)
      .then((response: VariantsResponse) => {
        if (!active) return;
        const lastPage = Math.max(1, response.meta.last_page);
        if (page > lastPage) {
          setPage(lastPage);
          return;
        }
        setResult(response);
        setLoading(false);
      })
      .catch((error: unknown) => { if (active) { setError(eventError(error)); setLoading(false); } });
    return () => { active = false; };
  }, [item.ulid, open, page, revision]);

  if (!item.photo_versions || item.photo_versions.visible_count < 2) return null;

  return <section className="grid gap-3 rounded border p-3" aria-label="Photo versions">
    <div className="flex flex-wrap items-center gap-2">
      <Badge variant="secondary">{item.photo_versions.visible_count} versions</Badge>
      {item.photo_versions.is_preferred && <Badge variant="outline">Preferred version</Badge>}
      <Button type="button" variant="outline" size="sm" aria-expanded={open} aria-controls={panelId} disabled={disabled} onClick={() => setOpen((value) => !value)}>{open ? 'Hide versions' : 'Show versions'}</Button>
    </div>
    {open && <div id={panelId} className="grid gap-3">
      <p className="text-xs text-muted-foreground">Similar photos are grouped together. We prefer higher resolution, then richer embedded metadata. All originals remain available. Saving, favoriting and downloading apply to the version you are viewing.</p>
      {loading && <p role="status" className="text-sm">Loading versions…</p>}
      {error && <div role="alert" className="grid gap-2 text-sm text-destructive"><p>{error}</p><Button type="button" variant="outline" size="sm" onClick={() => setRevision((value) => value + 1)}>Try versions again</Button></div>}
      {result && <>
        {result.data.length === 0 ? <p className="text-sm">No other versions are available.</p> : <ul className="grid gap-2 sm:grid-cols-2">
          {result.data.map((variant, index) => <li key={variant.ulid}>
            <button type="button" className="flex h-full w-full items-start gap-3 rounded border p-3 text-left hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-60" aria-label={`View version ${(page - 1) * result.meta.per_page + index + 1} · ${photoFormat(variant)} · ${variant.uploader.display_name}`} aria-current={variant.ulid === item.ulid ? 'true' : undefined} disabled={disabled || variant.ulid === item.ulid} onClick={() => onChoose(variant.ulid)}>
              {variant.thumbnail_url && <ProtectedImage src={variant.thumbnail_url} alt="" loading="lazy" className="h-16 w-16 shrink-0 rounded object-cover" />}
              <span className="grid min-w-0 gap-2">
                <span className="flex flex-wrap gap-1">{variant.ulid === result.preferred_ulid && <Badge variant="outline">Preferred version</Badge>}{variant.ulid === item.ulid && <Badge variant="secondary">Viewing</Badge>}</span>
                <span className="break-words text-sm font-medium">{variant.uploader.display_name}</span>
                <PhotoDetails item={variant} />
              </span>
            </button>
          </li>)}
        </ul>}
        {result.meta.last_page > 1 && <nav aria-label="Version pages" className="flex flex-wrap items-center justify-between gap-2">
          <Button type="button" variant="outline" size="sm" disabled={disabled || page <= 1} onClick={() => setPage((value) => value - 1)}>Previous versions</Button>
          <span className="text-xs text-muted-foreground">Page {result.meta.current_page} of {result.meta.last_page}</span>
          <Button type="button" variant="outline" size="sm" disabled={disabled || !result.meta.has_more} onClick={() => setPage((value) => value + 1)}>More versions</Button>
        </nav>}
      </>}
    </div>}
  </section>;
}
