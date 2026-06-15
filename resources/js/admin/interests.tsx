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

interface InterestTreeNode extends AdminInterest {
  children: InterestTreeNode[];
  depth: number;
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
  status: string;
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

function sortByName<T extends { name: string }>(items: T[]): T[] {
  return [...items].sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
}

function buildInterestTree(interests: AdminInterest[]): InterestTreeNode[] {
  const nodes = new Map<number, InterestTreeNode>();
  const roots: InterestTreeNode[] = [];

  for (const interest of interests) {
    nodes.set(interest.id, { ...interest, children: [], depth: 0 });
  }

  for (const node of nodes.values()) {
    if (node.parent_interest_id !== null) {
      const parent = nodes.get(node.parent_interest_id);
      if (parent) {
        parent.children.push(node);
        continue;
      }
    }

    roots.push(node);
  }

  const sortBranch = (branch: InterestTreeNode[], depth: number): InterestTreeNode[] => {
    return sortByName(branch).map((node) => ({
      ...node,
      depth,
      children: sortBranch(node.children, depth + 1),
    }));
  };

  return sortBranch(roots, 0);
}

function flattenInterestTree(nodes: InterestTreeNode[]): InterestTreeNode[] {
  return nodes.flatMap((node) => [node, ...flattenInterestTree(node.children)]);
}

function getDepthPaddingClass(depth: number): string {
  const classes = ['pl-0', 'pl-4', 'pl-8', 'pl-12', 'pl-16', 'pl-20', 'pl-24'];

  return classes[Math.min(depth, classes.length - 1)] ?? 'pl-0';
}

function collectDescendantIds(node: InterestTreeNode): Set<number> {
  const ids = new Set<number>();

  for (const child of node.children) {
    ids.add(child.id);
    for (const descendantId of collectDescendantIds(child)) {
      ids.add(descendantId);
    }
  }

  return ids;
}

function AdminInterestsPage() {
  const [interests, setInterests] = useState<AdminInterest[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [savingId, setSavingId] = useState<number | null>(null);
  const [requestLoading, setRequestLoading] = useState(true);
  const [requestActionId, setRequestActionId] = useState<number | null>(null);
  const [pendingRequests, setPendingRequests] = useState<AdminInterestRequest[]>([]);
  const [requestEditingId, setRequestEditingId] = useState<number | null>(null);
  const [requestEditForm, setRequestEditForm] = useState<InterestFormState>(createEmptyForm());
  const [deleteTarget, setDeleteTarget] = useState<AdminInterest | null>(null);
  const [deleteRequestTarget, setDeleteRequestTarget] = useState<AdminInterestRequest | null>(null);
  const [createForm, setCreateForm] = useState<InterestFormState>(createEmptyForm());
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editForm, setEditForm] = useState<InterestFormState>(createEmptyForm());

  const treeInterests = useMemo(() => buildInterestTree(interests), [interests]);
  const flatTreeInterests = useMemo(() => flattenInterestTree(treeInterests), [treeInterests]);

  const parentOptions = useMemo(() => {
    return flatTreeInterests.map((interest) => ({
      id: String(interest.id),
      label: `${'— '.repeat(interest.depth)}${interest.name}`,
      value: interest.id,
    }));
  }, [flatTreeInterests]);

  const editParentOptions = useMemo(() => {
    if (editingId === null) {
      return parentOptions;
    }

    const editingNode = flatTreeInterests.find((interest) => interest.id === editingId);
    const excludedIds = new Set<number>([editingId]);

    if (editingNode) {
      for (const descendantId of collectDescendantIds(editingNode)) {
        excludedIds.add(descendantId);
      }
    }

    return parentOptions.filter((option) => !excludedIds.has(option.value));
  }, [editingId, flatTreeInterests, parentOptions]);

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

  const beginRequestEdit = (request: AdminInterestRequest): void => {
    setRequestEditingId(request.id);
    setRequestEditForm({
      name: request.name,
      description: request.description ?? '',
      parent_interest_id: request.parent_interest_id ? String(request.parent_interest_id) : '',
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

  const saveRequestEdit = async (requestId: number): Promise<void> => {
    setError('');
    setRequestActionId(requestId);
    try {
      await fetchWrapper.put(`/api/admin/interest-requests/${requestId}`, {
        name: requestEditForm.name.trim(),
        description: requestEditForm.description.trim() || null,
        parent_interest_id: requestEditForm.parent_interest_id ? Number(requestEditForm.parent_interest_id) : null,
      });
      setRequestEditingId(null);
      await loadRequests();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setRequestActionId(null);
    }
  };

  const deleteRequest = async (requestId: number): Promise<void> => {
    setError('');
    setRequestActionId(requestId);
    try {
      await fetchWrapper.delete(`/api/admin/interest-requests/${requestId}`);
      setDeleteRequestTarget(null);
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
                  {requestEditingId === request.id ? (
                    <>
                      <TableCell>
                        <Input
                          value={requestEditForm.name}
                          onChange={(event) => setRequestEditForm((current) => ({ ...current, name: event.target.value }))}
                        />
                      </TableCell>
                      <TableCell>
                        <select
                          value={requestEditForm.parent_interest_id}
                          onChange={(event) => setRequestEditForm((current) => ({ ...current, parent_interest_id: event.target.value }))}
                          className="w-full rounded-md border border-input bg-background px-3 py-2"
                        >
                          <option value="">No parent</option>
                          {parentOptions.map((option) => (
                            <option key={option.id} value={option.id}>
                              {option.label}
                            </option>
                          ))}
                        </select>
                      </TableCell>
                      <TableCell>{request.requested_by_name ?? request.requested_by ?? 'Unknown'}</TableCell>
                      <TableCell>
                        <Textarea
                          value={requestEditForm.description}
                          onChange={(event) => setRequestEditForm((current) => ({ ...current, description: event.target.value }))}
                          rows={2}
                        />
                      </TableCell>
                      <TableCell>{request.requested_at}</TableCell>
                      <TableCell className="space-x-2">
                        <Button
                          size="sm"
                          disabled={requestActionId === request.id}
                          onClick={() => void saveRequestEdit(request.id)}
                        >
                          Save
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => setRequestEditingId(null)}
                        >
                          Cancel
                        </Button>
                      </TableCell>
                    </>
                  ) : (
                    <>
                      <TableCell className="font-medium">{request.name}</TableCell>
                      <TableCell>{request.parent_name ?? '—'}</TableCell>
                      <TableCell>{request.requested_by_name ?? request.requested_by ?? 'Unknown'}</TableCell>
                      <TableCell>{request.description ?? '—'}</TableCell>
                      <TableCell>{request.requested_at}</TableCell>
                      <TableCell className="space-x-2">
                        <Button size="sm" variant="outline" onClick={() => beginRequestEdit(request)} disabled={requestActionId === request.id}>
                          Edit
                        </Button>
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
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => setDeleteRequestTarget(request)}
                          disabled={requestActionId === request.id}
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
      ) : flatTreeInterests.length === 0 ? (
        <p className="rounded border border-border p-4 text-sm text-muted-foreground">No interests have been created yet.</p>
      ) : (
        <section className="rounded border border-border p-4" aria-label="Interest hierarchy">
          <div className="mb-3 grid grid-cols-[minmax(0,1fr)_8rem_12rem] gap-3 px-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            <span>Name</span>
            <span>Updated</span>
            <span>Actions</span>
          </div>
          <div className="space-y-2">
            {flatTreeInterests.map((interest) => (
              <div key={interest.id} className="rounded-md border border-border bg-background p-3">
                {editingId === interest.id ? (
                  <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(12rem,18rem)_minmax(0,1fr)_auto]">
                    <Input
                      value={editForm.name}
                      onChange={(event) => setEditForm((current) => ({ ...current, name: event.target.value }))}
                      aria-label="Interest name"
                    />
                    <select
                      value={editForm.parent_interest_id}
                      onChange={(event) => setEditForm((current) => ({ ...current, parent_interest_id: event.target.value }))}
                      className="w-full rounded-md border border-input bg-background px-3 py-2"
                      aria-label="Parent interest"
                    >
                      <option value="">No parent</option>
                      {editParentOptions.map((option) => (
                        <option key={option.id} value={option.id}>
                          {option.label}
                        </option>
                      ))}
                    </select>
                    <Textarea
                      value={editForm.description}
                      onChange={(event) => setEditForm((current) => ({ ...current, description: event.target.value }))}
                      rows={2}
                      aria-label="Interest description"
                    />
                    <div className="flex gap-2">
                      <Button size="sm" disabled={savingId === interest.id} onClick={() => void saveEdit(interest.id)}>
                        Save
                      </Button>
                      <Button size="sm" variant="outline" onClick={() => setEditingId(null)}>
                        Cancel
                      </Button>
                    </div>
                  </div>
                ) : (
                  <div className="grid grid-cols-[minmax(0,1fr)_8rem_12rem] items-start gap-3">
                    <div className={getDepthPaddingClass(interest.depth)}>
                      <div className="flex items-center gap-2">
                        {interest.depth > 0 && <span className="text-muted-foreground">↳</span>}
                        <span className="font-medium">{interest.name}</span>
                      </div>
                      {interest.description && (
                        <p className="mt-1 text-sm text-muted-foreground">{interest.description}</p>
                      )}
                    </div>
                    <span className="text-sm text-muted-foreground">{formatDate(interest.updated_at)}</span>
                    <div className="flex gap-2">
                      <Button size="sm" variant="outline" onClick={() => beginEdit(interest)}>
                        Edit
                      </Button>
                      <Button size="sm" variant="destructive" onClick={() => setDeleteTarget(interest)} disabled={savingId === interest.id}>
                        Delete
                      </Button>
                    </div>
                  </div>
                )}
              </div>
            ))}
          </div>
        </section>
      )}

      <Dialog open={deleteTarget !== null} onOpenChange={(open) => {
        if (!open) {
          setDeleteTarget(null);
        }
      }}
      >
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

      <Dialog
        open={deleteRequestTarget !== null}
        onOpenChange={(open) => {
          if (!open) {
            setDeleteRequestTarget(null);
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete Interest Request</DialogTitle>
            <DialogDescription>
              Delete request <strong>{deleteRequestTarget?.name}</strong>? This cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleteRequestTarget(null)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => {
                if (deleteRequestTarget) {
                  void deleteRequest(deleteRequestTarget.id);
                }
              }}
              disabled={requestActionId === deleteRequestTarget?.id}
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
