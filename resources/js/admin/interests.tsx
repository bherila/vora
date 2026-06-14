import { type FormEvent, useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';

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

interface AdminInterest {
  id: number;
  name: string;
  description: string | null;
  parent_interest_id: number | null;
  parent_name: string | null;
  created_at: string;
  updated_at: string;
}

interface AdminInterestRequest {
  id: number;
  name: string;
  description: string | null;
  parent_interest_id: number | null;
  parent_name: string | null;
  requested_by: string | null;
  requested_by_id: number | null;
  requested_by_name: string | null;
  requested_at: string;
}

type InterestFormState = {
  name: string;
  description: string;
  parent_interest_id: string;
};

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : 'Request failed.';
}

function formatDate(value: string): string {
  try {
    return new Date(value).toLocaleDateString();
  } catch {
    return value;
  }
}

function createEmptyForm(): InterestFormState {
  return { name: '', description: '', parent_interest_id: '' };
}

function AdminInterestsPage() {
  const [interests, setInterests] = useState<AdminInterest[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [savingId, setSavingId] = useState<number | null>(null);
  const [requestLoading, setRequestLoading] = useState(true);
  const [requestActionId, setRequestActionId] = useState<number | null>(null);
  const [pendingRequests, setPendingRequests] = useState<AdminInterestRequest[]>([]);
  const [deleteTarget, setDeleteTarget] = useState<AdminInterest | null>(null);
  const [createForm, setCreateForm] = useState<InterestFormState>(createEmptyForm());
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editForm, setEditForm] = useState<InterestFormState>(createEmptyForm());

  const parentOptions = useMemo(() => {
    return interests.map((interest) => ({
      id: String(interest.id),
      label: interest.name,
      value: interest.id,
    }));
  }, [interests]);

  const loadInterests = async (): Promise<void> => {
    setLoading(true);
    setError('');
    try {
      const response = await fetchWrapper.get('/api/admin/interests') as { success: boolean; data: AdminInterest[] };
      setInterests(response.data ?? []);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  const loadRequests = async (): Promise<void> => {
    setRequestLoading(true);
    try {
      const response = await fetchWrapper.get('/api/admin/interest-requests') as {
        success: boolean;
        data: AdminInterestRequest[];
      };
      setPendingRequests(response.data ?? []);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setRequestLoading(false);
    }
  };

  useEffect(() => {
    void Promise.all([loadInterests(), loadRequests()]);
  }, []);

  const saveCreate = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();
    setError('');
    setSavingId(0);
    try {
      await fetchWrapper.post('/api/admin/interests', {
        name: createForm.name.trim(),
        description: createForm.description.trim() || null,
        parent_interest_id: createForm.parent_interest_id ? Number(createForm.parent_interest_id) : null,
      });
      setCreateForm(createEmptyForm());
      await loadInterests();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSavingId(null);
    }
  };

  const saveEdit = async (id: number): Promise<void> => {
    setError('');
    setSavingId(id);
    try {
      await fetchWrapper.put(`/api/admin/interests/${id}`, {
        name: editForm.name.trim(),
        description: editForm.description.trim() || null,
        parent_interest_id: editForm.parent_interest_id ? Number(editForm.parent_interest_id) : null,
      });
      setEditingId(null);
      await loadInterests();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSavingId(null);
    }
  };

  const deleteInterest = async (id: number): Promise<void> => {
    setError('');
    setSavingId(id);
    try {
      await fetchWrapper.delete(`/api/admin/interests/${id}`);
      setDeleteTarget(null);
      await loadInterests();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSavingId(null);
    }
  };

  const beginEdit = (interest: AdminInterest): void => {
    setEditingId(interest.id);
    setEditForm({
      name: interest.name,
      description: interest.description ?? '',
      parent_interest_id: interest.parent_interest_id ? String(interest.parent_interest_id) : '',
    });
  };

  const approveRequest = async (requestId: number): Promise<void> => {
    setError('');
    setRequestActionId(requestId);
    try {
      await fetchWrapper.post(`/api/admin/interest-requests/${requestId}/approve`, {});
      await Promise.all([loadInterests(), loadRequests()]);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setRequestActionId(null);
    }
  };

  const rejectRequest = async (requestId: number): Promise<void> => {
    setError('');
    setRequestActionId(requestId);
    try {
      await fetchWrapper.post(`/api/admin/interest-requests/${requestId}/reject`, {});
      await loadRequests();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setRequestActionId(null);
    }
  };

  return (
    <div className="mx-auto max-w-6xl px-4 py-8">
      <h1 className="mb-6 text-2xl font-bold">Admin — Interests</h1>

      {error && (
        <div className="mb-4 rounded border border-destructive bg-destructive/10 p-3 text-sm text-destructive">
          {error}
        </div>
      )}

      <section className="mb-8 rounded border border-border p-4">
        <h2 className="mb-4 text-lg font-semibold">Pending Interest Requests</h2>
        {requestLoading ? (
          <p className="text-muted-foreground">Loading pending requests...</p>
        ) : pendingRequests.length === 0 ? (
          <p className="text-sm text-muted-foreground">No pending requests.</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Item</TableHead>
                <TableHead>Parent</TableHead>
                <TableHead>Requested By</TableHead>
                <TableHead>Description</TableHead>
                <TableHead>Requested</TableHead>
                <TableHead>Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {pendingRequests.map((request) => (
                <TableRow key={request.id}>
                  <TableCell className="font-medium">{request.name}</TableCell>
                  <TableCell>{request.parent_name ?? '—'}</TableCell>
                  <TableCell>{request.requested_by_name ?? request.requested_by ?? 'Unknown'}</TableCell>
                  <TableCell>{request.description ?? '—'}</TableCell>
                  <TableCell>{request.requested_at}</TableCell>
                  <TableCell className="space-x-2">
                    <Button
                      size="sm"
                      onClick={() => void approveRequest(request.id)}
                      disabled={requestActionId === request.id}
                    >
                      {requestActionId === request.id ? 'Approving…' : 'Approve'}
                    </Button>
                    <Button
                      variant="destructive"
                      size="sm"
                      onClick={() => void rejectRequest(request.id)}
                      disabled={requestActionId === request.id}
                    >
                      {requestActionId === request.id ? 'Rejecting…' : 'Reject'}
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </section>

      <form onSubmit={(event) => void saveCreate(event)} className="mb-8 grid gap-4 rounded border border-border p-4">
        <h2 className="text-lg font-semibold">Add Interest</h2>
        <Input
          value={createForm.name}
          onChange={(event) => setCreateForm((current) => ({ ...current, name: event.target.value }))}
          placeholder="Interest name"
          required
        />
        <Textarea
          value={createForm.description}
          onChange={(event) => setCreateForm((current) => ({ ...current, description: event.target.value }))}
          placeholder="Description (optional)"
          rows={3}
        />
        <label className="grid gap-1">
          <span className="text-sm">Parent interest</span>
          <select
            value={createForm.parent_interest_id}
            onChange={(event) => setCreateForm((current) => ({ ...current, parent_interest_id: event.target.value }))}
            className="w-full rounded-md border border-input bg-background px-3 py-2"
          >
            <option value="">No parent</option>
            {parentOptions.map((option) => (
              <option key={option.id} value={option.id}>
                {option.label}
              </option>
            ))}
          </select>
        </label>
        <div>
          <Button type="submit" disabled={savingId === 0}>
            {savingId === 0 ? 'Saving…' : 'Create'}
          </Button>
        </div>
      </form>

      {loading ? (
        <p className="text-muted-foreground">Loading interests...</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Name</TableHead>
              <TableHead>Parent</TableHead>
              <TableHead>Description</TableHead>
              <TableHead>Updated</TableHead>
              <TableHead>Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {interests.map((interest) => (
              <TableRow key={interest.id}>
                {editingId === interest.id ? (
                  <>
                    <TableCell>
                      <Input
                        value={editForm.name}
                        onChange={(event) => setEditForm((current) => ({ ...current, name: event.target.value }))}
                      />
                    </TableCell>
                    <TableCell>
                      <select
                        value={editForm.parent_interest_id}
                        onChange={(event) => setEditForm((current) => ({ ...current, parent_interest_id: event.target.value }))}
                        className="w-full rounded-md border border-input bg-background px-3 py-2"
                      >
                        <option value="">No parent</option>
                        {parentOptions
                          .filter((option) => option.value !== interest.id)
                          .map((option) => (
                            <option key={option.id} value={option.id}>
                              {option.label}
                            </option>
                          ))}
                      </select>
                    </TableCell>
                    <TableCell>
                      <Textarea
                        value={editForm.description}
                        onChange={(event) => setEditForm((current) => ({ ...current, description: event.target.value }))}
                        rows={2}
                      />
                    </TableCell>
                    <TableCell>{formatDate(interest.updated_at)}</TableCell>
                    <TableCell className="space-x-2">
                      <Button
                        size="sm"
                        disabled={savingId === interest.id}
                        onClick={() => void saveEdit(interest.id)}
                      >
                        Save
                      </Button>
                      <Button size="sm" variant="outline" onClick={() => setEditingId(null)}>
                        Cancel
                      </Button>
                    </TableCell>
                  </>
                ) : (
                  <>
                    <TableCell>{interest.name}</TableCell>
                    <TableCell>{interest.parent_name ?? '—'}</TableCell>
                    <TableCell>{interest.description ?? '—'}</TableCell>
                    <TableCell>{formatDate(interest.updated_at)}</TableCell>
                    <TableCell className="space-x-2">
                      <Button size="sm" variant="outline" onClick={() => beginEdit(interest)}>
                        Edit
                      </Button>
                      <Button
                        size="sm"
                        variant="destructive"
                        onClick={() => setDeleteTarget(interest)}
                        disabled={savingId === interest.id}
                      >
                        Delete
                      </Button>
                    </TableCell>
                  </>
                )}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}

      <Dialog open={deleteTarget !== null} onOpenChange={(open) => { if (!open) setDeleteTarget(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete Interest</DialogTitle>
            <DialogDescription>
              Delete <strong>{deleteTarget?.name}</strong>? This cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleteTarget(null)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => { if (deleteTarget) void deleteInterest(deleteTarget.id); }}
            >
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

const mountEl = document.getElementById('admin-interests');
if (mountEl) {
  createRoot(mountEl).render(<AdminInterestsPage />);
}
