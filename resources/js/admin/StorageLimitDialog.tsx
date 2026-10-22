import { type FormEvent, useId, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatBytes } from '@/media/types';

export interface StorageLimitUpdate {
  storage_limit_bytes: number | null;
  storage_unlimited: boolean;
}

interface StorageLimitDialogProps {
  userName: string;
  limitBytes: number | null;
  unlimitedOverride: boolean;
  defaultLimitBytes: number | null;
  siteDefault?: boolean;
  onSave: (value: StorageLimitUpdate) => Promise<void>;
  onClose: () => void;
}

type StorageLimitMode = 'default' | 'custom' | 'unlimited';
const GIB = 1024 ** 3;

export function formatStorageLimit(bytes: number | null): string {
  return bytes === null ? 'Unlimited' : bytes >= GIB ? `${bytes / GIB} GiB` : formatBytes(bytes);
}

export function StorageLimitDialog({ userName, limitBytes, unlimitedOverride, defaultLimitBytes, siteDefault = false, onSave, onClose }: StorageLimitDialogProps) {
  const [mode, setMode] = useState<StorageLimitMode>(() => siteDefault
    ? limitBytes === null ? 'unlimited' : 'custom'
    : unlimitedOverride ? 'unlimited' : limitBytes === null ? 'default' : 'custom');
  const [amount, setAmount] = useState(limitBytes === null ? '' : String(limitBytes / GIB));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const allowanceId = useId();
  const amountId = useId();

  const submit = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    const bytes = mode === 'custom' ? Math.round(Number(amount) * GIB) : null;
    if (mode === 'custom' && (amount.trim() === '' || Number(amount) < 0 || bytes === null || !Number.isSafeInteger(bytes) || bytes < 0)) {
      setError('Enter a non-negative storage limit, or choose another allowance.');
      return;
    }
    setBusy(true);
    setError('');
    try {
      await onSave({ storage_limit_bytes: bytes, storage_unlimited: mode === 'unlimited' });
      onClose();
    } catch (error) {
      setError(error instanceof Error ? error.message : typeof error === 'string' ? error : 'Could not update the storage limit.');
    } finally {
      setBusy(false);
    }
  };

  return <Dialog open onOpenChange={(open) => { if (!open && !busy) onClose(); }}>
    <DialogContent>
      <DialogHeader>
        <DialogTitle>{siteDefault ? 'Site default storage limit' : `Storage limit for ${userName}`}</DialogTitle>
        <DialogDescription>{siteDefault
          ? 'Set the allowance for accounts using the site default, including new accounts. Custom limits and Unlimited overrides keep their settings.'
          : 'Choose the site default, a custom account limit, or an explicit Unlimited override.'} Active uploads and saved originals count toward the allowance. Pending uploads reserve space. Deleted files held for retention are excluded.</DialogDescription>
      </DialogHeader>
      <form onSubmit={(event) => void submit(event)} className="grid gap-4">
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <div className="grid gap-2">
          <Label htmlFor={allowanceId}>Storage allowance</Label>
          <select id={allowanceId} className="w-full rounded-md border bg-background px-3 py-2 text-sm" value={mode} onChange={(event) => setMode(event.target.value as StorageLimitMode)} disabled={busy}>
            {!siteDefault && <option value="default">Use site default ({formatStorageLimit(defaultLimitBytes)})</option>}
            <option value="custom">Custom limit</option>
            <option value="unlimited">{siteDefault ? 'Unlimited' : 'Unlimited override'}</option>
          </select>
          {!siteDefault && mode === 'default' && <p className="text-xs text-muted-foreground">This account follows future changes to the site default.</p>}
        </div>
        <div className="grid gap-2">
          <Label htmlFor={amountId}>Limit (GiB)</Label>
          <Input id={amountId} type="number" min="0" step="any" value={amount} onChange={(event) => setAmount(event.target.value)} disabled={busy || mode !== 'custom'} />
          <p className="text-xs text-muted-foreground">1 GiB = 1,073,741,824 bytes. A limit of 0 prevents new media storage. Reducing a limit keeps existing content available. Library access is enabled separately.</p>
        </div>
        <DialogFooter><Button type="button" variant="outline" disabled={busy} onClick={onClose}>Cancel</Button><Button type="submit" disabled={busy}>{busy ? 'Saving…' : siteDefault ? 'Save site default' : 'Save storage limit'}</Button></DialogFooter>
      </form>
    </DialogContent>
  </Dialog>;
}
