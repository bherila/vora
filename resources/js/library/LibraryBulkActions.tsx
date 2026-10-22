import { useState } from 'react';
import { toast } from 'sonner';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import { instanceProfile } from '@/instance';
import { type LibraryCollection, libraryError } from '@/library/types';

type BulkAction = 'add' | 'move' | 'remove' | 'delete';

interface LibraryBulkActionsProps {
  itemUlids: string[];
  collections: LibraryCollection[];
  currentCollection: LibraryCollection | undefined;
  enabled: boolean;
  onChanged: () => void;
  onClear: () => void;
}

const ACTION_LABELS: Record<BulkAction, string> = {
  add: 'Add to collection', move: 'Move to collection', remove: 'Remove from collection', delete: 'Remove from Library',
};

export function LibraryBulkActions({ itemUlids, collections, currentCollection, enabled, onChanged, onClear }: LibraryBulkActionsProps) {
  const eventInstance = instanceProfile() === 'event';
  const actionLabels = { ...ACTION_LABELS, delete: eventInstance ? 'Remove items' : ACTION_LABELS.delete };
  const homeName = eventInstance ? 'My collection' : 'your Library';
  const [action, setAction] = useState<BulkAction | null>(null);
  const [target, setTarget] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const targets = collections.filter((entry) => entry.owned && (action !== 'move' || entry.ulid !== currentCollection?.ulid));
  const needsTarget = action === 'add' || action === 'move';
  const targetCollection = collections.find((entry) => entry.ulid === target);

  const chooseAction = (next: BulkAction): void => { setAction(next); setTarget(''); setError(''); };
  const apply = async (): Promise<void> => {
    if (!action) return;
    setBusy(true);
    setError('');
    try {
      await fetchWrapper.post('/api/library/items/bulk', {
        action, item_ulids: itemUlids,
        ...(needsTarget ? { collection_ulid: target } : {}),
        ...((action === 'move' || action === 'remove') ? { source_collection_ulid: currentCollection?.ulid } : {}),
      });
      setAction(null);
      onClear();
      onChanged();
      toast.success(`${itemUlids.length} ${itemUlids.length === 1 ? 'item' : 'items'} updated.`);
    } catch (error) {
      setError(libraryError(error));
    } finally {
      setBusy(false);
    }
  };

  return <div className="space-y-3 rounded-lg border p-3" aria-label={eventInstance ? 'Selected items' : 'Selected Library items'}>
    <div className="flex flex-wrap items-center gap-2">
      <p className="mr-auto text-sm font-medium" role="status">{itemUlids.length} selected</p>
      {enabled && <Button variant="outline" onClick={() => chooseAction('add')}>Add to collection</Button>}
      {currentCollection?.owned && <>
        {enabled && <Button variant="outline" onClick={() => chooseAction('move')}>Move to collection</Button>}
        <Button variant="outline" onClick={() => chooseAction('remove')}>Remove from collection</Button>
      </>}
      <Button variant="outline" onClick={() => chooseAction('delete')}>{actionLabels.delete}</Button>
      <Button variant="ghost" onClick={onClear}>Clear selection</Button>
    </div>
    <Dialog open={action !== null} onOpenChange={(open) => { if (!open && !busy) setAction(null); }}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{action ? actionLabels[action] : 'Organize items'}</DialogTitle>
          <DialogDescription>
            {action === 'delete' ? `Remove ${itemUlids.length} selected items from ${homeName} and all your collections? People you shared them with lose access, but keep their own saved items. Files are retained while another saved reference or retention period still needs them.`
              : action === 'remove' ? `Remove ${itemUlids.length} selected items from ${currentCollection?.name}? They remain in ${homeName} and other collections. Recipients lose access through this collection, but keep their own saved items.`
                : action === 'move' ? `Move ${itemUlids.length} selected items out of ${currentCollection?.name} into another collection. Other collection memberships remain. Recipients lose access through the previous collection, but keep their own saved items.`
                  : `Add ${itemUlids.length} selected items to a collection. Their other collection memberships remain.`}
          </DialogDescription>
        </DialogHeader>
        {needsTarget && <div className="space-y-2">
          <Label htmlFor="bulk-target-collection">Destination collection</Label>
          <select id="bulk-target-collection" className="w-full rounded-md border bg-background px-3 py-2 text-sm" value={target} onChange={(event) => setTarget(event.target.value)} disabled={busy}>
            <option value="">Choose a collection</option>
            {targets.map((entry) => <option key={entry.ulid} value={entry.ulid}>{entry.name}</option>)}
          </select>
          {targets.length === 0 && <p className="text-sm text-muted-foreground">Create a collection first.</p>}
          {targetCollection && targetCollection.member_ids.length > 0 && <p className="text-sm text-muted-foreground">This collection is shared. Your uploads and notes become visible to its recipients and can be saved by them. Saved items stay private to you.</p>}
        </div>}
        {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
        <DialogFooter>
          <Button variant="outline" disabled={busy} onClick={() => setAction(null)}>Cancel</Button>
          <Button variant={action === 'delete' ? 'destructive' : 'default'} disabled={busy || (needsTarget && !target)} onClick={() => void apply()}>{busy ? 'Updating…' : action ? actionLabels[action] : 'Apply'}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>;
}
