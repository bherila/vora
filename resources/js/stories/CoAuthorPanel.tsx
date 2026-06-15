import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { fetchWrapper } from '@/fetchWrapper';

import { storiesApi } from './api';
import type { StoryAuthorRef } from './types';

interface UserOption {
  id: number;
  display_name: string;
}

interface CoAuthorPanelProps {
  storyId: number;
  authors: StoryAuthorRef[];
  canManage: boolean;
  currentUserId: number;
  onChange: (authors: StoryAuthorRef[]) => void;
}

/**
 * Co-author management: the owner invites/removes collaborators; collaborators
 * can leave. Invitations are accepted through the shared requests inbox.
 */
export function CoAuthorPanel({ storyId, authors, canManage, currentUserId, onChange }: CoAuthorPanelProps) {
  const [users, setUsers] = useState<UserOption[]>([]);
  const [selected, setSelected] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!canManage) return;
    fetchWrapper
      .get('/api/users')
      .then((r) => setUsers(((r as { data: UserOption[] }).data ?? []).map((u) => ({ id: u.id, display_name: u.display_name }))))
      .catch(() => setUsers([]));
  }, [canManage]);

  const invite = async (): Promise<void> => {
    if (!selected) return;
    setBusy(true);
    setError('');
    try {
      const updated = await storiesApi.invite(storyId, Number(selected));
      onChange(updated);
      setSelected('');
    } catch (e) {
      setError(typeof e === 'string' ? e : 'Could not send invite.');
    } finally {
      setBusy(false);
    }
  };

  const remove = async (userId: number): Promise<void> => {
    setBusy(true);
    setError('');
    try {
      const updated = await storiesApi.removeAuthor(storyId, userId);
      onChange(updated);
    } catch (e) {
      setError(typeof e === 'string' ? e : 'Could not update authors.');
    } finally {
      setBusy(false);
    }
  };

  const invitableUsers = users.filter((u) => !authors.some((a) => a.user_id === u.id));

  return (
    <div className="space-y-3 rounded-md border border-border p-3">
      <h4 className="text-sm font-medium">Authors</h4>
      <ul className="space-y-1 text-sm">
        {authors.map((a) => {
          const canRemove = (canManage && !a.is_owner) || (a.user_id === currentUserId && !a.is_owner);
          return (
            <li key={a.id} className="flex items-center justify-between">
              <span>
                {a.display_name}
                <span className="ml-2 text-xs text-muted-foreground">
                  {a.is_owner ? 'owner' : a.status === 'pending' ? 'invited' : 'co-author'}
                </span>
              </span>
              {canRemove && (
                <Button type="button" variant="ghost" size="sm" onClick={() => void remove(a.user_id)} disabled={busy}>
                  {a.user_id === currentUserId ? 'Leave' : 'Remove'}
                </Button>
              )}
            </li>
          );
        })}
      </ul>

      {canManage && (
        <div className="flex items-center gap-2">
          <select
            className="h-9 flex-1 rounded-md border border-input bg-background px-2 text-sm"
            value={selected}
            onChange={(e) => setSelected(e.target.value)}
          >
            <option value="">Invite a co-author…</option>
            {invitableUsers.map((u) => (
              <option key={u.id} value={u.id}>
                {u.display_name}
              </option>
            ))}
          </select>
          <Button type="button" size="sm" onClick={() => void invite()} disabled={busy || !selected}>
            Invite
          </Button>
        </div>
      )}
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  );
}
