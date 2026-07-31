import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { AudienceField } from '@/community/AudienceField';
import { PrivacyBadge } from '@/components/privacy-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { fetchWrapper } from '@/fetchWrapper';
import { MediaGrid } from '@/media/MediaGrid';
import type { CharacterOption } from '@/media/MediaUploadDialog';
import { MediaUploadDialog } from '@/media/MediaUploadDialog';
import type { Audience } from '@/media/types';
import { useMediaListing } from '@/media/useMediaListing';

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : err instanceof Error ? err.message : 'Request failed.';
}

interface OwnerMediaManagerProps {
  userId: number;
  /** null = the main-user identity; a number = one of the user's characters. */
  identity: number | null;
  characters: CharacterOption[];
  lastInterestIds: number[];
}

/**
 * The owner's media management surface, rendered inside their own profile's
 * Media tab. Media is uploaded *on* the active identity (the user or one of
 * their characters), and managed in place — there is no separate library page.
 */
export function OwnerMediaManager({ userId, identity, characters, lastInterestIds }: OwnerMediaManagerProps) {
  const listing = useMediaListing(`/api/users/${userId}/media`, {
    type: 'all',
    interestIds: [],
    extraParams: identity === null ? {} : { character_id: identity },
  });

  // Bulk selection + editing across the visible items.
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [bulkDialogOpen, setBulkDialogOpen] = useState(false);
  const [bulkCharacterId, setBulkCharacterId] = useState('');
  const [bulkAudience, setBulkAudience] = useState<Audience>('everyone');
  const [bulkAudienceUserIds, setBulkAudienceUserIds] = useState<number[]>([]);
  const [bulkDiscoverable, setBulkDiscoverable] = useState(true);
  const [bulkBusy, setBulkBusy] = useState(false);

  const selectedItems = listing.items.filter((item) => selectedIds.includes(item.id));
  const selectedHasCharacterMedia = selectedItems.some((item) => item.character_id !== null);
  const allVisibleSelected = listing.items.length > 0 && listing.items.every((item) => selectedIds.includes(item.id));

  // Drop selections for items that scroll out of the current listing.
  useEffect(() => {
    const visibleIds = new Set(listing.items.map((item) => item.id));
    setSelectedIds((current) => current.filter((id) => visibleIds.has(id)));
  }, [listing.items]);

  const toggleVisibleSelection = (): void => {
    if (allVisibleSelected) {
      const visibleIds = new Set(listing.items.map((item) => item.id));
      setSelectedIds((current) => current.filter((id) => !visibleIds.has(id)));
      return;
    }
    setSelectedIds((current) => Array.from(new Set([...current, ...listing.items.map((item) => item.id)])));
  };

  const applyBulkUpdate = async (payload: Record<string, unknown>, successMessage: string): Promise<void> => {
    if (selectedIds.length === 0) {
      toast.error('Select at least one media item.');
      return;
    }
    setBulkBusy(true);
    try {
      await fetchWrapper.patch('/api/media/bulk', { media_ids: selectedIds, ...payload });
      toast.success(successMessage);
      setSelectedIds([]);
      setBulkDialogOpen(false);
      listing.reload();
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setBulkBusy(false);
    }
  };

  const assignSelectedToCharacter = async (): Promise<void> => {
    if (bulkCharacterId === '') {
      toast.error('Choose a character first.');
      return;
    }
    await applyBulkUpdate({ action: 'assign_character', character_id: Number(bulkCharacterId) }, 'Selected media assigned to character.');
  };

  const clearSelectedCharacter = async (): Promise<void> => {
    await applyBulkUpdate({ action: 'clear_character' }, 'Selected media detached from character.');
  };

  const updateSelectedPrivacy = async (): Promise<void> => {
    if (selectedHasCharacterMedia) {
      toast.error('Character media inherits character privacy. Clear the character first.');
      return;
    }
    await applyBulkUpdate({
      action: 'set_privacy',
      audience: bulkAudience,
      audience_user_ids: bulkAudience === 'specific' ? bulkAudienceUserIds : [],
      discoverable: bulkDiscoverable,
    }, 'Selected media privacy updated.');
  };

  const deleteSelected = async (): Promise<void> => {
    if (selectedIds.length === 0) {
      toast.error('Select at least one media item.');
      return;
    }
    if (!window.confirm(`Delete ${selectedIds.length} selected item${selectedIds.length === 1 ? '' : 's'}? They will be hidden from your profile and retained for admin recovery.`)) {
      return;
    }
    setBulkBusy(true);
    try {
      await fetchWrapper.delete('/api/media/bulk', { media_ids: selectedIds });
      toast.success('Selected media deleted.');
      setSelectedIds([]);
      setBulkDialogOpen(false);
      listing.reload();
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setBulkBusy(false);
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        {listing.items.length > 0 ? (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm text-muted-foreground">{selectedIds.length} selected</span>
            <Button type="button" size="sm" variant="outline" onClick={toggleVisibleSelection} disabled={bulkBusy}>
              {allVisibleSelected ? 'Clear visible' : 'Select visible'}
            </Button>
            <Button type="button" size="sm" onClick={() => setBulkDialogOpen(true)} disabled={bulkBusy || selectedIds.length === 0}>
              Edit selected
            </Button>
          </div>
        ) : <span />}
        <MediaUploadDialog
          characters={characters}
          {...(identity === null ? {} : { defaultCharacterId: identity })}
          lastInterestIds={lastInterestIds}
          onUploaded={listing.reload}
          triggerSize="sm"
        />
      </div>

      {listing.loading ? (
        <p className="text-muted-foreground">Loading…</p>
      ) : listing.error ? (
        <p className="text-destructive">{listing.error}</p>
      ) : listing.items.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border px-6 py-12 text-center text-sm text-muted-foreground">
          You haven’t added media to this profile yet. Use “Upload media” to add a photo or video.
        </p>
      ) : (
        <MediaGrid
          items={listing.items}
          thumbnailSize="large"
          selectedIds={selectedIds}
          onSelectionChange={setSelectedIds}
          renderActions={(item) => (
            <div className="flex flex-wrap items-center gap-2">
              <PrivacyBadge audience={item.audience} discoverable={item.discoverable} />
              {item.under_review && <Badge variant="outline" className="text-amber-600">In review</Badge>}
              {item.video?.status === 'processing' && <Badge variant="outline">Processing…</Badge>}
            </div>
          )}
        />
      )}
      {listing.hasMore && (
        <div className="mt-2 flex justify-center">
          <Button type="button" variant="outline" disabled={listing.loadingMore} onClick={listing.loadMore}>
            {listing.loadingMore ? 'Loading…' : 'Load more'}
          </Button>
        </div>
      )}

      <Dialog open={bulkDialogOpen} onOpenChange={(open) => { if (!open) setBulkDialogOpen(false); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit {selectedIds.length} selected</DialogTitle>
            <DialogDescription>Assign a character, change privacy, or delete the selected items.</DialogDescription>
          </DialogHeader>
          <div className="grid gap-5">
            <div className="grid gap-2">
              <span className="text-sm font-medium">Character</span>
              <select
                value={bulkCharacterId}
                onChange={(event) => setBulkCharacterId(event.target.value)}
                disabled={bulkBusy || characters.length === 0}
                className="h-9 rounded-md border border-input bg-background px-2 text-sm"
              >
                <option value="">Choose character</option>
                {characters.map((character) => (
                  <option key={character.id} value={character.id}>{character.display_name}</option>
                ))}
              </select>
              <div className="flex flex-wrap gap-2">
                <Button type="button" size="sm" onClick={() => void assignSelectedToCharacter()} disabled={bulkBusy || bulkCharacterId === ''}>
                  Assign
                </Button>
                <Button type="button" size="sm" variant="outline" onClick={() => void clearSelectedCharacter()} disabled={bulkBusy}>
                  Clear character
                </Button>
              </div>
            </div>

            <div className="grid gap-2 border-t border-border pt-4">
              <AudienceField
                audience={bulkAudience}
                onAudienceChange={setBulkAudience}
                selectedUserIds={bulkAudienceUserIds}
                onSelectedUserIdsChange={setBulkAudienceUserIds}
                disabled={bulkBusy || selectedHasCharacterMedia}
                label="Change standalone media privacy"
                specificRelationship="mutuals"
              />
              <label className="flex items-start gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={bulkDiscoverable}
                  onChange={(event) => setBulkDiscoverable(event.target.checked)}
                  disabled={bulkBusy || selectedHasCharacterMedia}
                  className="mt-0.5"
                />
                <span>List in discovery</span>
              </label>
              {selectedHasCharacterMedia && (
                <p className="text-xs text-muted-foreground">Character media inherits character privacy. Clear the character before changing media privacy directly.</p>
              )}
              <div>
                <Button type="button" size="sm" onClick={() => void updateSelectedPrivacy()} disabled={bulkBusy || selectedHasCharacterMedia}>
                  Apply privacy
                </Button>
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button type="button" variant="destructive" onClick={() => void deleteSelected()} disabled={bulkBusy}>
              Delete selected
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
