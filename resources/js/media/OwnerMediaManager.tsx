import { useState } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import { MediaGrid } from '@/media/MediaGrid';
import type { CharacterOption } from '@/media/MediaUploadDialog';
import { MediaUploadDialog } from '@/media/MediaUploadDialog';
import type { Audience, MediaItem } from '@/media/types';
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

  const [editItem, setEditItem] = useState<MediaItem | null>(null);
  const [editTitle, setEditTitle] = useState('');
  const [editCharacterId, setEditCharacterId] = useState('');
  const [editAudience, setEditAudience] = useState<Audience>('everyone');
  const [editAudienceUserIds, setEditAudienceUserIds] = useState<number[]>([]);
  const [editDiscoverable, setEditDiscoverable] = useState(true);
  const [editBusy, setEditBusy] = useState(false);

  const openEdit = (item: MediaItem): void => {
    setEditItem(item);
    setEditTitle(item.title ?? '');
    setEditCharacterId(item.character_id !== null ? String(item.character_id) : '');
    setEditAudience(item.audience);
    setEditAudienceUserIds([]);
    setEditDiscoverable(item.discoverable);
  };

  const saveEdit = async (): Promise<void> => {
    if (!editItem) return;
    const payload: Record<string, unknown> = { title: editTitle.trim() === '' ? null : editTitle.trim() };

    if (editCharacterId !== '') {
      // Assigning (or keeping) a character: privacy is inherited from it.
      payload.character_id = Number(editCharacterId);
    } else if (editItem.character_id !== null) {
      // Detaching the character. Privacy is edited in a follow-up save, since the
      // server applies a character change OR a privacy change, not both at once.
      payload.character_id = null;
    } else {
      payload.audience = editAudience;
      payload.audience_user_ids = editAudience === 'specific' ? editAudienceUserIds : [];
      payload.discoverable = editDiscoverable;
    }

    setEditBusy(true);
    try {
      await fetchWrapper.patch(`/api/media/${editItem.id}`, payload);
      listing.reload();
      setEditItem(null);
      toast.success('Media updated.');
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setEditBusy(false);
    }
  };

  const remove = async (item: MediaItem): Promise<void> => {
    if (!window.confirm('Delete this item? It will be hidden from your profile and retained for admin recovery.')) {
      return;
    }
    try {
      await fetchWrapper.delete(`/api/media/${item.id}`);
      listing.removeLocal(item.id);
      toast.success('Media deleted.');
    } catch (err) {
      toast.error(getErrorMessage(err));
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <MediaUploadDialog
          characters={characters}
          defaultCharacterId={identity}
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
          renderActions={(item) => (
            <div className="flex flex-wrap items-center gap-2">
              <PrivacyBadge audience={item.audience} discoverable={item.discoverable} />
              {item.under_review && <Badge variant="outline" className="text-amber-600">In review</Badge>}
              {item.video?.status === 'processing' && <Badge variant="outline">Processing…</Badge>}
              <Button type="button" size="sm" variant="outline" onClick={() => openEdit(item)}>
                Edit
              </Button>
              <Button type="button" size="sm" variant="destructive" onClick={() => void remove(item)}>
                Delete
              </Button>
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

      <Dialog open={editItem !== null} onOpenChange={(open) => { if (!open) setEditItem(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit media</DialogTitle>
            <DialogDescription>Fix the title, change its privacy, or attach a character.</DialogDescription>
          </DialogHeader>
          {editItem && (
            <div className="space-y-4">
              <div className="space-y-1">
                <Label htmlFor="edit-media-title">Title</Label>
                <Input id="edit-media-title" value={editTitle} onChange={(event) => setEditTitle(event.target.value)} disabled={editBusy} />
              </div>
              {characters.length > 0 && (
                <label className="grid gap-1 text-sm">
                  <span>Character</span>
                  <select
                    value={editCharacterId}
                    onChange={(event) => setEditCharacterId(event.target.value)}
                    disabled={editBusy}
                    className="w-full rounded-md border border-input bg-background px-3 py-2"
                  >
                    <option value="">No character</option>
                    {characters.map((character) => (
                      <option key={character.id} value={character.id}>{character.display_name}</option>
                    ))}
                  </select>
                </label>
              )}
              {editCharacterId !== '' ? (
                <p className="text-sm text-muted-foreground">Privacy follows the selected character.</p>
              ) : editItem.character_id !== null ? (
                <p className="text-sm text-muted-foreground">Detaching the character. Save, then reopen to set this item’s own privacy.</p>
              ) : (
                <>
                  <AudienceField
                    audience={editAudience}
                    onAudienceChange={setEditAudience}
                    selectedUserIds={editAudienceUserIds}
                    onSelectedUserIdsChange={setEditAudienceUserIds}
                    disabled={editBusy}
                    specificRelationship="mutuals"
                  />
                  <label className="flex items-start gap-2 text-sm">
                    <input type="checkbox" checked={editDiscoverable} onChange={(event) => setEditDiscoverable(event.target.checked)} disabled={editBusy} className="mt-0.5" />
                    <span>List in discovery — otherwise only people with the link can find it.</span>
                  </label>
                </>
              )}
            </div>
          )}
          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => setEditItem(null)} disabled={editBusy}>Cancel</Button>
            <Button type="button" onClick={() => void saveEdit()} disabled={editBusy}>{editBusy ? 'Saving…' : 'Save changes'}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
