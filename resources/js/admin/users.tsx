import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

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
  email: string;
  is_admin: boolean;
  is_disabled: boolean;
  is_approved: boolean;
  email_verified: boolean | null;
  approved_at: string | null;
  last_login_at: string | null;
  created_at: string;
}

function getCsrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
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
  const [actionLoading, setActionLoading] = useState<number | null>(null);

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
              <TableHead>Name</TableHead>
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
                <TableCell>{user.email}</TableCell>
                <TableCell>
                  <div className="flex flex-wrap gap-1">
                    {user.is_admin && <Badge>Admin</Badge>}
                    {user.is_disabled && <Badge variant="destructive">Disabled</Badge>}
                    {!user.is_approved && <Badge variant="secondary">Pending</Badge>}
                    {user.email_verified && <Badge variant="outline">Verified</Badge>}
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
                        disabled={actionLoading === user.id}
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
    </div>
  );
}

const mountEl = document.getElementById('admin-users');
if (mountEl) {
  createRoot(mountEl).render(<AdminUsersPage />);
}
