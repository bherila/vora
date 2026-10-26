import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { fetchWrapper } from '@/fetchWrapper';
import { instanceProfile } from '@/instance';
import { libraryError } from '@/library/types';
import { safeInternalUrl } from '@/security/dom-url';

interface BulkTransferActionsProps {
  source: 'events' | 'library';
  ulids: string[];
  saveUlids?: string[];
  downloadUlids: string[];
  canSave: boolean;
  showSummary?: boolean;
  /** Pinned action bar: keep only the notes that explain a limited action. */
  compact?: boolean;
  onSaved: () => void;
  onClear?: () => void;
}

export function BulkTransferActions({ source, ulids, saveUlids = ulids, downloadUlids, canSave, showSummary = true, compact = false, onSaved, onClear }: BulkTransferActionsProps) {
  const [busy, setBusy] = useState<'save' | 'download' | null>(null);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const mounted = useRef(true);
  const selectionKey = ulids.join('|');
  const currentSelection = useRef(selectionKey);
  useEffect(() => { currentSelection.current = selectionKey; }, [selectionKey]);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  const base = source === 'events' ? '/api/events/media' : '/api/library/items';
  const saveLabel = instanceProfile() === 'event' ? 'Save selected to my collection' : 'Save selected to Library';
  const transfer = async (action: 'save' | 'download'): Promise<void> => {
    const selection = currentSelection.current;
    const isCurrent = (): boolean => mounted.current && currentSelection.current === selection;
    setBusy(action); setError(''); setMessage('');
    try {
      if (action === 'save') {
        await fetchWrapper.post(`${base}/bulk-save`, { ulids: saveUlids });
        if (!isCurrent()) return;
        setMessage('Saved privately. The files remain available in your account.');
        toast.success('Saved privately. The files remain available in your account.');
        onSaved();
      } else {
        const result = await fetchWrapper.post(`${base}/bulk-downloads`, { ulids: downloadUlids }) as { data: { download_url: string } };
        if (!isCurrent()) return;
        const url = safeInternalUrl(result.data.download_url);
        if (!url?.startsWith('/api/bulk-downloads/')) throw new Error('The download could not be prepared. Try again.');
        // Let the browser stream directly to its download manager. Never fetch
        // a multi-gigabyte ZIP into a JavaScript Blob/array buffer.
        const link = document.createElement('a');
        link.href = url;
        link.download = 'selected-media.zip';
        document.body.append(link);
        link.click();
        link.remove();
        setMessage('Your browser is downloading the ZIP. If it is interrupted, try again.');
      }
    } catch (error) {
      if (isCurrent()) setError(libraryError(error));
    } finally {
      if (mounted.current) setBusy(null);
    }
  };

  return <div className="space-y-2 rounded border p-3" aria-label="Save or download selected items">
    <div className="flex flex-wrap items-center gap-2">
      {showSummary && <p className="mr-auto text-sm font-medium" role="status">{ulids.length} selected</p>}
      {/* Compact keeps the full action names for assistive tech, starting with the visible word. */}
      {canSave && <Button variant="outline" size={compact ? 'sm' : 'default'} aria-label={compact && busy !== 'save' ? saveLabel : undefined} disabled={busy !== null} onClick={() => void transfer('save')}>{busy === 'save' ? 'Saving selected…' : compact ? 'Save' : saveLabel}</Button>}
      <Button variant="outline" size={compact ? 'sm' : 'default'} aria-label={compact && busy !== 'download' ? 'Download selected (ZIP)' : undefined} disabled={busy !== null || downloadUlids.length === 0 || downloadUlids.length > 50} onClick={() => void transfer('download')}>{busy === 'download' ? 'Preparing ZIP…' : compact ? 'Download' : 'Download selected (ZIP)'}</Button>
      {onClear && !compact && <Button variant="ghost" disabled={busy !== null} onClick={onClear}>Clear selection</Button>}
    </div>
    {(!compact || downloadUlids.length > 50 || downloadUlids.length < ulids.length) && <p className="text-xs text-muted-foreground">ZIP downloads: up to 50 files and 2 GiB total. Download larger videos individually.{downloadUlids.length < ulids.length ? ' Notes and unavailable files are excluded from ZIP downloads.' : ''}</p>}
    {canSave && !compact && <p className="text-xs text-muted-foreground">Saving adds private references without copying files and counts toward your storage limit.</p>}
    {message && <p role="status" className="text-sm">{message}</p>}
    {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
  </div>;
}
