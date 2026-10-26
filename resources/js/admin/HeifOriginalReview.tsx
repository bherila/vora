import { useEffect, useRef, useState } from 'react';

import { ProtectedImage } from '@/components/protected-image';
import { Button } from '@/components/ui/button';
import { prepareHeifPreview } from '@/media/heifPhoto';
import { type AdminMediaItem } from '@/media/types';
import { safeHttpsUrl, safeLocalBlobUrl } from '@/security/dom-url';

interface HeifOriginalReviewProps {
  /** The admin queue and the Event viewer both supply the signed original and its stored size. */
  item: Pick<AdminMediaItem, 'download_url' | 'url' | 'size_bytes' | 'original_filename' | 'mime_type'>;
  onVerified: () => void;
}

function originalUrl(value: string | null): string | null {
  const https = safeHttpsUrl(value);
  if (https !== null) return https;
  // Disposable staging uses HTTP loopback object storage, never public HTTP.
  if (value === null || window.location.protocol !== 'http:') return null;
  try {
    const url = new URL(value);
    return url.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname) ? url.href : null;
  } catch { return null; }
}

async function originalBlob(response: Response, limit: number): Promise<Blob> {
  if (!response.ok) throw new Error('Could not fetch the original. Refresh the review queue and try again.');
  const length = Number(response.headers.get('Content-Length'));
  if (length > limit) throw new Error('The original exceeds the photo review size limit.');
  if (response.body === null) {
    const blob = await response.blob();
    if (blob.size > limit) throw new Error('The original exceeds the photo review size limit.');
    return blob;
  }
  const reader = response.body.getReader();
  const chunks: Uint8Array<ArrayBuffer>[] = [];
  let size = 0;
  try {
    for (;;) {
      const result = await reader.read();
      if (result.done) break;
      size += result.value.byteLength;
      if (size > limit) throw new Error('The original exceeds the photo review size limit.');
      chunks.push(new Uint8Array(result.value));
    }
  } finally { await reader.cancel(); reader.releaseLock(); }
  return new Blob(chunks);
}

let previousOriginalCheck: Promise<void> = Promise.resolve();

/** Decode stored original bytes independently of the uploader-supplied preview. */
export function HeifOriginalReview({ item, onVerified }: HeifOriginalReviewProps) {
  const [busy, setBusy] = useState(false);
  const [preview, setPreview] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const abortRef = useRef<AbortController | null>(null);
  const urlRef = useRef<string | null>(null);
  useEffect(() => () => {
    abortRef.current?.abort();
    if (urlRef.current !== null) URL.revokeObjectURL(urlRef.current);
  }, []);

  const check = async (): Promise<void> => {
    const url = originalUrl(item.download_url);
    if (url === null) { setError('The original is unavailable. Refresh the review queue and try again.'); return; }
    const controller = new AbortController(); abortRef.current = controller;
    setBusy(true); setError(null);
    let release = (): void => {};
    const previous = previousOriginalCheck;
    previousOriginalCheck = new Promise<void>((resolve) => { release = resolve; });
    try {
      await previous;
      controller.signal.throwIfAborted();
      const response = await fetch(url, { signal: controller.signal, credentials: 'omit', cache: 'no-store' });
      const source = await originalBlob(response, Math.min(item.size_bytes ?? 25 * 1024 * 1024, 25 * 1024 * 1024));
      if (item.size_bytes !== null && source.size !== item.size_bytes) throw new Error('The original changed. Refresh the review queue and try again.');
      const jpeg = await prepareHeifPreview(new File([source], item.original_filename, { type: item.mime_type }), controller.signal);
      controller.signal.throwIfAborted();
      const raw = URL.createObjectURL(jpeg);
      const safe = safeLocalBlobUrl(raw);
      if (safe === null) { URL.revokeObjectURL(raw); throw new Error('Could not display the original photo.'); }
      if (urlRef.current !== null) URL.revokeObjectURL(urlRef.current);
      urlRef.current = raw; setPreview(safe);
    } catch (failure) {
      if (!controller.signal.aborted) setError(failure instanceof Error ? failure.message : 'Could not check the original.');
    } finally { release(); if (!controller.signal.aborted) setBusy(false); }
  };

  return <div className="grid gap-2 p-3">
    <p className="text-xs font-medium">Uploader preview — check the original file before approving.</p>
    {item.url && <ProtectedImage src={item.url} alt="Uploader-supplied HEIC/HEIF preview" className="max-h-40 w-full object-contain" />}
    <Button type="button" size="sm" variant="outline" onClick={() => void check()} disabled={busy || preview !== null}>
      {busy ? 'Checking original…' : preview === null ? 'Check original' : 'Original checked'}
    </Button>
    {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
    {preview && <div className="grid gap-1"><p className="text-xs font-medium">Original file decoded here — review this image before approving.</p>
      <ProtectedImage src={preview} alt="Verified preview decoded from the stored original" className="max-h-64 w-full object-contain" onLoad={onVerified} onError={() => setError('Could not display the original photo. Refresh and try again.')} />
    </div>}
  </div>;
}
