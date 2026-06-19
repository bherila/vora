import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { DatePicker } from '@/components/date-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';

interface AdminUser {
  id: number;
  name: string;
  display_name: string | null;
  birth_date: string | null;
  email: string;
  is_admin: boolean;
  is_disabled: boolean;
  is_deactivated: boolean;
  is_deleted: boolean;
  is_approved: boolean;
  id_verified: boolean;
  birth_date_verified: boolean;
  email_verified: boolean;
  name_locked: boolean;
  email_locked: boolean;
  id_verified_at: string | null;
  approved_at: string | null;
  last_login_at: string | null;
  created_at: string;
  invite_balance: number;
  can_receive_invites: boolean;
  trusted_inviter: boolean;
  is_banned: boolean;
  ban_reason: string | null;
  ban_hides_content: boolean;
  ban_appeal_message: string | null;
  ban_appeal_at: string | null;
  is_on_legal_hold: boolean;
  legal_hold_note: string | null;
  referrer_user_id: number | null;
  referrer_display_name: string | null;
}

function getCsrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function getAdultBirthDateLimit(): string {
  const date = new Date();
  date.setFullYear(date.getFullYear() - 18);

  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

async function patchUser(id: number, body: Record<string, unknown>) {
  const response = await fetch(`/api/admin/users/${id}`, {
    method: 'PATCH',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': getCsrfToken(),
    },
    credentials: 'include',
    body: JSON.stringify(body),
  });
  if (!response.ok) {
    const data = await response.json().catch(() => ({})) as { message?: string };
    throw new Error(data.message ?? 'Request failed');
  }
  return response.json() as Promise<{ success: boolean }>;
}

function AdminUsersPage() {
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [deleteTarget, setDeleteTarget] = useState<AdminUser | null>(null);
  const [birthDateTarget, setBirthDateTarget] = useState<AdminUser | null>(null);
  const [birthDateValue, setBirthDateValue] = useState('');
  const [actionLoading, setActionLoading] = useState<number | null>(null);
  const adultBirthDateLimit = getAdultBirthDateLimit();

  // Ban dialog.
  const [banTarget, setBanTarget] = useState<AdminUser | null>(null);
  const [banReason, setBanReason] = useState('');
  const [banHidesContent, setBanHidesContent] = useState(false);
  // Legal hold dialog.
  const [legalHoldTarget, setLegalHoldTarget] = useState<AdminUser | null>(null);
  const [legalHoldNote, setLegalHoldNote] = useState('');
  // Issue invites dialog.
  const [issueTarget, setIssueTarget] = useState<AdminUser | null>(null);
  const [issueQuantity, setIssueQuantity] = useState('1');
  const [issueExpiryDays, setIssueExpiryDays] = useState('');

  const loadUsers = async () => {
    setLoading(true);
    setError('');
    try {
      const response = await fetchWrapper.get('/api/admin/users') as { success: boolean; data: AdminUser[] };
      if (response.success) {
        setUsers(response.data);
      }
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to load users.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadUsers();
  }, []);

  const approveUser = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await fetchWrapper.post(`/api/admin/users/${user.id}/approve`, {});
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to approve user.');
    } finally {
      setActionLoading(null);
    }
  };

  const toggleAdmin = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await patchUser(user.id, { is_admin: !user.is_admin });
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update user.');
    } finally {
      setActionLoading(null);
    }
  };

  const toggleDisabled = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await patchUser(user.id, { is_disabled: !user.is_disabled });
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update user.');
    } finally {
      setActionLoading(null);
    }
  };

  const toggleNameLock = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await patchUser(user.id, { name_locked: !user.name_locked });
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update user.');
    } finally {
      setActionLoading(null);
    }
  };

  const toggleEmailLock = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await patchUser(user.id, { email_locked: !user.email_locked });
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update user.');
    } finally {
      setActionLoading(null);
    }
  };

  const toggleIdVerification = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await patchUser(user.id, { id_verified: !user.id_verified });
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update user.');
    } finally {
      setActionLoading(null);
    }
  };

  const beginBirthDateEdit = (user: AdminUser) => {
    setBirthDateTarget(user);
    setBirthDateValue(user.birth_date ?? '');
  };

  const saveBirthDate = async () => {
    if (!birthDateTarget || !birthDateValue) {
      return;
    }

    setActionLoading(birthDateTarget.id);
    try {
      await patchUser(birthDateTarget.id, { birth_date: birthDateValue });
      setBirthDateTarget(null);
      setBirthDateValue('');
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update birth date.');
    } finally {
      setActionLoading(null);
    }
  };

  const deleteUser = async (user: AdminUser) => {
    setActionLoading(user.id);
    setDeleteTarget(null);
    try {
      await fetchWrapper.delete(`/api/admin/users/${user.id}`, {});
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to delete user.');
    } finally {
      setActionLoading(null);
    }
  };

  const restoreUser = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await fetchWrapper.post(`/api/admin/users/${user.id}/restore`, {});
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to restore user.');
    } finally {
      setActionLoading(null);
    }
  };

  const toggleTrustedInviter = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await patchUser(user.id, { trusted_inviter: !user.trusted_inviter });
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update user.');
    } finally {
      setActionLoading(null);
    }
  };

  const toggleCanReceiveInvites = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await patchUser(user.id, { can_receive_invites: !user.can_receive_invites });
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update user.');
    } finally {
      setActionLoading(null);
    }
  };

  const beginBan = (user: AdminUser) => {
    setBanTarget(user);
    setBanReason(user.ban_reason ?? '');
    setBanHidesContent(user.ban_hides_content);
  };

  const submitBan = async () => {
    if (!banTarget) {
      return;
    }
    setActionLoading(banTarget.id);
    try {
      await fetchWrapper.post(`/api/admin/users/${banTarget.id}/ban`, {
        reason: banReason.trim() || null,
        hide_content: banHidesContent,
      });
      setBanTarget(null);
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to ban user.');
    } finally {
      setActionLoading(null);
    }
  };

  const unbanUser = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await fetchWrapper.post(`/api/admin/users/${user.id}/unban`, {});
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to unban user.');
    } finally {
      setActionLoading(null);
    }
  };

  const beginLegalHold = (user: AdminUser) => {
    setLegalHoldTarget(user);
    setLegalHoldNote(user.legal_hold_note ?? '');
  };

  const setLegalHold = async (user: AdminUser, onHold: boolean) => {
    setActionLoading(user.id);
    try {
      await fetchWrapper.post(`/api/admin/users/${user.id}/legal-hold`, {
        on_hold: onHold,
        note: onHold ? (legalHoldNote.trim() || null) : null,
      });
      setLegalHoldTarget(null);
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update legal hold.');
    } finally {
      setActionLoading(null);
    }
  };

  const beginIssue = (user: AdminUser) => {
    setIssueTarget(user);
    setIssueQuantity('1');
    setIssueExpiryDays('');
  };

  const submitIssue = async () => {
    if (!issueTarget) {
      return;
    }
    setActionLoading(issueTarget.id);
    try {
      await fetchWrapper.post(`/api/admin/users/${issueTarget.id}/invites`, {
        quantity: Number(issueQuantity),
        expires_in_days: issueExpiryDays.trim() === '' ? null : Number(issueExpiryDays),
      });
      setIssueTarget(null);
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to issue invites.');
    } finally {
      setActionLoading(null);
    }
  };

  return (
    <div className="mx-auto max-w-6xl px-4 py-8">
      <h1 className="mb-6 text-2xl font-bold">Admin — Users</h1>

      {error && (
        <div className="mb-4 rounded border border-destructive bg-destructive/10 p-3 text-sm text-destructive">
          {error}
        </div>
      )}

      {loading ? (
        <p className="text-muted-foreground">Loading users...</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Real Name</TableHead>
              <TableHead>Display Name</TableHead>
              <TableHead>Birth Date</TableHead>
              <TableHead>Email</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Joined</TableHead>
              <TableHead>Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {users.map((user) => (
              <TableRow key={user.id}>
                <TableCell className="font-medium">{user.name}</TableCell>
                <TableCell>{user.display_name ?? user.name}</TableCell>
                <TableCell>
                  <div className="flex flex-col gap-1">
                    <span>{user.birth_date ?? '—'}</span>
                    {user.birth_date && (
                      <Badge
                        variant={user.birth_date_verified ? 'default' : 'outline'}
                        className="w-fit"
                        title={user.id_verified_at ? `Verified ${new Date(user.id_verified_at).toLocaleString()}` : undefined}
                      >
                        {user.birth_date_verified ? 'Birth Date Verified' : 'Birth Date Unverified'}
                      </Badge>
                    )}
                  </div>
                </TableCell>
                <TableCell>{user.email}</TableCell>
                <TableCell>
                  <div className="flex flex-wrap gap-1">
                    {user.is_admin && <Badge>Admin</Badge>}
                    {user.is_deleted && <Badge variant="destructive">Deleted</Badge>}
                    {user.is_deactivated && <Badge variant="secondary">Deactivated</Badge>}
                    {user.is_disabled && <Badge variant="destructive">Disabled</Badge>}
                    {!user.is_approved && <Badge variant="secondary">Pending</Badge>}
                    {user.email_verified && <Badge variant="outline">Verified</Badge>}
                    {!user.email_verified && <Badge variant="outline">Email Unverified</Badge>}
                    {user.id_verified && <Badge>ID/Age Verified</Badge>}
                    {user.name_locked && <Badge variant="outline">Real Name Locked</Badge>}
                    {user.email_locked && <Badge variant="outline">Email Locked</Badge>}
                    {user.is_banned && (
                      <Badge variant="destructive">{user.ban_hides_content ? 'Banned (hidden)' : 'Banned'}</Badge>
                    )}
                    {user.is_on_legal_hold && <Badge variant="destructive">Legal Hold</Badge>}
                    {user.trusted_inviter && <Badge>Trusted Inviter</Badge>}
                    {!user.can_receive_invites && <Badge variant="outline">No Invites</Badge>}
                    <Badge variant="outline">{user.invite_balance} invite(s)</Badge>
                    {user.referrer_display_name && (
                      <Badge variant="outline">Invited by {user.referrer_display_name}</Badge>
                    )}
                  </div>
                  {user.is_banned && user.ban_appeal_at && (
                    <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                      <span className="font-medium">Appeal:</span> {user.ban_appeal_message}
                    </p>
                  )}
                </TableCell>
                <TableCell className="text-sm text-muted-foreground">
                  {new Date(user.created_at).toLocaleDateString()}
                </TableCell>
                <TableCell>
                  <div className="flex flex-wrap gap-2">
                    {/* Normal controls 404 for soft-deleted users (routes aren't trashed-aware); only Restore/Purge apply. */}
                    {!user.is_deleted && (
                      <>
                        {!user.is_approved && (
                          <Button
                            size="sm"
                            disabled={actionLoading === user.id || !user.email_verified}
                            title={!user.email_verified ? 'User must verify their email before approval.' : 'Approve user'}
                            onClick={() => void approveUser(user)}
                            data-test="admin-users-approve"
                          >
                            Approve
                          </Button>
                        )}
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={actionLoading === user.id}
                          onClick={() => void toggleAdmin(user)}
                          data-test="admin-users-toggle-admin"
                        >
                          {user.is_admin ? 'Remove Admin' : 'Make Admin'}
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={actionLoading === user.id}
                          onClick={() => void toggleDisabled(user)}
                          data-test="admin-users-toggle-disabled"
                        >
                          {user.is_disabled ? 'Enable' : 'Disable'}
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={actionLoading === user.id}
                          onClick={() => void toggleNameLock(user)}
                          data-test="admin-users-toggle-name-lock"
                        >
                          {user.name_locked ? 'Unlock Real Name' : 'Lock Real Name'}
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={actionLoading === user.id}
                          onClick={() => void toggleEmailLock(user)}
                          data-test="admin-users-toggle-email-lock"
                        >
                          {user.email_locked ? 'Unlock Email' : 'Lock Email'}
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={actionLoading === user.id}
                          onClick={() => void toggleIdVerification(user)}
                          data-test="admin-users-toggle-id-verified"
                        >
                          {user.id_verified ? 'Clear ID/Age Verification' : 'Verify ID/Age'}
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={actionLoading === user.id}
                          onClick={() => beginBirthDateEdit(user)}
                          data-test="admin-users-edit-birth-date"
                        >
                          Edit Birth Date
                        </Button>
                        <Button
                          size="sm"
                          variant={user.is_banned ? 'outline' : 'destructive'}
                          disabled={actionLoading === user.id}
                          onClick={() => (user.is_banned ? void unbanUser(user) : beginBan(user))}
                          data-test="admin-users-ban"
                        >
                          {user.is_banned ? 'Unban' : 'Ban'}
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={actionLoading === user.id}
                          onClick={() => (user.is_on_legal_hold ? void setLegalHold(user, false) : beginLegalHold(user))}
                          data-test="admin-users-legal-hold"
                        >
                          {user.is_on_legal_hold ? 'Lift Legal Hold' : 'Legal Hold'}
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={actionLoading === user.id}
                          onClick={() => void toggleTrustedInviter(user)}
                          data-test="admin-users-toggle-trusted"
                        >
                          {user.trusted_inviter ? 'Untrust Inviter' : 'Trust Inviter'}
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={actionLoading === user.id}
                          onClick={() => void toggleCanReceiveInvites(user)}
                          data-test="admin-users-toggle-can-receive"
                        >
                          {user.can_receive_invites ? 'Block Invites' : 'Allow Invites'}
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={actionLoading === user.id}
                          onClick={() => beginIssue(user)}
                          data-test="admin-users-issue-invites"
                        >
                          Issue Invites
                        </Button>
                      </>
                    )}
                    {user.is_deleted && (
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={actionLoading === user.id}
                        onClick={() => void restoreUser(user)}
                        data-test="admin-users-restore"
                      >
                        Restore
                      </Button>
                    )}
                    <Button
                      size="sm"
                      variant="destructive"
                      disabled={actionLoading === user.id}
                      onClick={() => setDeleteTarget(user)}
                      data-test="admin-users-delete"
                    >
                      {user.is_deleted ? 'Purge' : 'Delete'}
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}

      <Dialog open={deleteTarget !== null} onOpenChange={(open) => { if (!open) setDeleteTarget(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Permanently delete user</DialogTitle>
            <DialogDescription>
              Permanently delete <strong>{deleteTarget?.name}</strong> ({deleteTarget?.email}), including all of their
              media, characters, and uploaded files? This cannot be undone. To temporarily remove an account instead,
              ask the user to deactivate it, or use Disable.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleteTarget(null)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => { if (deleteTarget) void deleteUser(deleteTarget); }}
              data-test="admin-users-delete-confirm"
            >
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={birthDateTarget !== null} onOpenChange={(open) => {
        if (!open) {
          setBirthDateTarget(null);
          setBirthDateValue('');
        }
      }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit Birth Date</DialogTitle>
            <DialogDescription>
              Update the date of birth for <strong>{birthDateTarget?.name}</strong>. Store this as the literal calendar date shown on the user's ID.
            </DialogDescription>
          </DialogHeader>
          <DatePicker
            id="admin-user-birth-date"
            value={birthDateValue}
            max={adultBirthDateLimit}
            onChange={setBirthDateValue}
            placeholder="Select birth date"
          />
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                setBirthDateTarget(null);
                setBirthDateValue('');
              }}
            >
              Cancel
            </Button>
            <Button
              onClick={() => void saveBirthDate()}
              disabled={!birthDateValue || actionLoading === birthDateTarget?.id}
              data-test="admin-users-birth-date-save"
            >
              Save
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={banTarget !== null} onOpenChange={(open) => { if (!open) setBanTarget(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Ban user</DialogTitle>
            <DialogDescription>
              Ban <strong>{banTarget?.display_name ?? banTarget?.name}</strong>. They can still log in but can only
              appeal, deactivate, or delete their account.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="ban-reason">Reason (shown to the user)</Label>
              <Textarea id="ban-reason" rows={4} value={banReason} onChange={(e) => setBanReason(e.target.value)} />
            </div>
            <label className="flex items-center gap-2 text-sm">
              <Checkbox checked={banHidesContent} onCheckedChange={(checked) => setBanHidesContent(checked === true)} />
              Also hide this user's content from others (uncheck to memorialize)
            </label>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setBanTarget(null)}>Cancel</Button>
            <Button
              variant="destructive"
              onClick={() => void submitBan()}
              disabled={actionLoading === banTarget?.id}
              data-test="admin-users-ban-confirm"
            >
              Ban
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={legalHoldTarget !== null} onOpenChange={(open) => { if (!open) setLegalHoldTarget(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Place legal hold</DialogTitle>
            <DialogDescription>
              Prevent <strong>{legalHoldTarget?.display_name ?? legalHoldTarget?.name}</strong> from deleting their
              account. This is admin-only and not shown to the user unless they try to delete.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="legal-hold-note">Internal note (optional)</Label>
            <Textarea
              id="legal-hold-note"
              rows={3}
              value={legalHoldNote}
              onChange={(e) => setLegalHoldNote(e.target.value)}
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setLegalHoldTarget(null)}>Cancel</Button>
            <Button
              onClick={() => { if (legalHoldTarget) void setLegalHold(legalHoldTarget, true); }}
              disabled={actionLoading === legalHoldTarget?.id}
              data-test="admin-users-legal-hold-confirm"
            >
              Place hold
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={issueTarget !== null} onOpenChange={(open) => { if (!open) setIssueTarget(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Issue invites</DialogTitle>
            <DialogDescription>
              Add invites to <strong>{issueTarget?.display_name ?? issueTarget?.name}</strong>'s balance. Unused invites
              expire after the number of days you set (leave blank for no expiry).
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="issue-quantity">Quantity</Label>
              <Input
                id="issue-quantity"
                type="number"
                min={1}
                value={issueQuantity}
                onChange={(e) => setIssueQuantity(e.target.value)}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="issue-expiry">Expires in (days)</Label>
              <Input
                id="issue-expiry"
                type="number"
                min={1}
                placeholder="No expiry"
                value={issueExpiryDays}
                onChange={(e) => setIssueExpiryDays(e.target.value)}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIssueTarget(null)}>Cancel</Button>
            <Button
              onClick={() => void submitIssue()}
              disabled={actionLoading === issueTarget?.id || Number(issueQuantity) < 1}
              data-test="admin-users-issue-confirm"
            >
              Issue
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

const mountEl = document.getElementById('admin-users');
if (mountEl) {
  createRoot(mountEl).render(<AdminUsersPage />);
}
