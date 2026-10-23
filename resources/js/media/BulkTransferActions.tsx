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
  onSaved: () => void;
  onClear?: () => void;
}

export function BulkTransferActions({ source, ulids, saveUlids = ulids, downloadUlids, canSave, showSummary = true, onSaved, onClear }: BulkTransferActionsProps) {
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
      {canSave && <Button variant="outline" disabled={busy !== null} onClick={() => void transfer('save')}>{busy === 'save' ? 'Saving selected…' : saveLabel}</Button>}
      <Button variant="outline" disabled={busy !== null || downloadUlids.length === 0 || downloadUlids.length > 50} onClick={() => void transfer('download')}>{busy === 'download' ? 'Preparing ZIP…' : 'Download selected (ZIP)'}</Button>
      {onClear && <Button variant="ghost" disabled={busy !== null} onClick={onClear}>Clear selection</Button>}
    </div>
    <p className="text-xs text-muted-foreground">ZIP downloads: up to 50 files and 2 GiB total. Download larger videos individually.{downloadUlids.length < ulids.length ? ' Notes and unavailable files are excluded from ZIP downloads.' : ''}</p>
    {canSave && <p className="text-xs text-muted-foreground">Saving adds private references without copying files and counts toward your storage limit.</p>}
    {message && <p role="status" className="text-sm">{message}</p>}
    {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
  </div>;
}
