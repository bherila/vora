import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { DatePicker } from '@/components/date-picker';
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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { fetchWrapper } from '@/fetchWrapper';

interface AdminUser {
  id: number;
  name: string;
  display_name: string | null;
  birth_date: string | null;
  email: string;
  is_admin: boolean;
  is_disabled: boolean;
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
                    {user.is_disabled && <Badge variant="destructive">Disabled</Badge>}
                    {!user.is_approved && <Badge variant="secondary">Pending</Badge>}
                    {user.email_verified && <Badge variant="outline">Verified</Badge>}
                    {!user.email_verified && <Badge variant="outline">Email Unverified</Badge>}
                    {user.id_verified && <Badge>ID/Age Verified</Badge>}
                    {user.name_locked && <Badge variant="outline">Real Name Locked</Badge>}
                    {user.email_locked && <Badge variant="outline">Email Locked</Badge>}
                  </div>
                </TableCell>
                <TableCell className="text-sm text-muted-foreground">
                  {new Date(user.created_at).toLocaleDateString()}
                </TableCell>
                <TableCell>
                  <div className="flex flex-wrap gap-2">
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
                      variant="destructive"
                      disabled={actionLoading === user.id}
                      onClick={() => setDeleteTarget(user)}
                      data-test="admin-users-delete"
                    >
                      Delete
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
            <DialogTitle>Delete User</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete <strong>{deleteTarget?.name}</strong> ({deleteTarget?.email})? This action
              cannot be undone.
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
    </div>
  );
}

const mountEl = document.getElementById('admin-users');
if (mountEl) {
  createRoot(mountEl).render(<AdminUsersPage />);
}
