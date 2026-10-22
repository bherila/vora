import { type FormEvent, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface StorageLimitDialogProps {
  userName: string;
  limitBytes: number | null;
  onSave: (limitBytes: number | null) => Promise<void>;
  onClose: () => void;
}

const GIB = 1024 ** 3;

export function StorageLimitDialog({ userName, limitBytes, onSave, onClose }: StorageLimitDialogProps) {
  const [unlimited, setUnlimited] = useState(limitBytes === null);
  const [amount, setAmount] = useState(limitBytes === null ? '' : String(limitBytes / GIB));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const submit = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    const bytes = unlimited ? null : Math.round(Number(amount) * GIB);
    if (!unlimited && (amount.trim() === '' || Number(amount) < 0 || bytes === null || !Number.isSafeInteger(bytes) || bytes < 0)) {
      setError('Enter a non-negative storage limit, or choose unlimited storage.');
      return;
    }
    setBusy(true);
    setError('');
    try {
      await onSave(bytes);
      onClose();
    } catch (error) {
      setError(error instanceof Error ? error.message : typeof error === 'string' ? error : 'Could not update the storage limit.');
    } finally {
      setBusy(false);
    }
  };

  return <Dialog open onOpenChange={(open) => { if (!open && !busy) onClose(); }}>
    <DialogContent>
      <DialogHeader><DialogTitle>Storage limit for {userName}</DialogTitle><DialogDescription>Set an account allowance for active uploads and saved originals. Pending uploads reserve space. Deleted files held for retention are excluded.</DialogDescription></DialogHeader>
      <form onSubmit={(event) => void submit(event)} className="grid gap-4">
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <label className="flex items-center gap-2 text-sm"><Checkbox checked={unlimited} onCheckedChange={(checked) => setUnlimited(checked === true)} disabled={busy} />Unlimited storage</label>
        <div className="grid gap-2">
          <Label htmlFor="admin-storage-limit">Limit (GiB)</Label>
          <Input id="admin-storage-limit" type="number" min="0" step="any" value={amount} onChange={(event) => setAmount(event.target.value)} disabled={busy || unlimited} />
          <p className="text-xs text-muted-foreground">1 GiB = 1,073,741,824 bytes. A limit of 0 prevents new media storage. Reducing a limit keeps existing content available. Library access is enabled separately.</p>
        </div>
        <DialogFooter><Button type="button" variant="outline" disabled={busy} onClick={onClose}>Cancel</Button><Button type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save storage limit'}</Button></DialogFooter>
      </form>
    </DialogContent>
  </Dialog>;
}
