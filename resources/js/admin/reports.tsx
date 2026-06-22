import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';

type StatusFilter = 'open' | 'resolved' | 'dismissed' | 'all';

const FILTERS: StatusFilter[] = ['open', 'resolved', 'dismissed', 'all'];

interface ReportOwner {
  id: number;
  display_name: string;
  email: string | null;
  is_banned: boolean;
  is_on_legal_hold: boolean;
}

interface Reportable {
  type: 'media' | 'story' | 'post';
  id: number;
  label: string;
  href: string | null;
  deleted: boolean;
  owner: ReportOwner | null;
}

interface AdminReport {
  id: number;
  reason: string;
  reason_label: string;
  details: string | null;
  status: 'open' | 'resolved' | 'dismissed';
  resolution: string | null;
  created_at: string | null;
  reviewed_at: string | null;
  reporter: { id: number; display_name: string; email: string | null } | null;
  reviewer: { id: number; display_name: string } | null;
  reportable: Reportable | null;
}

interface PagedReports {
  data: AdminReport[];
  meta?: { has_more: boolean };
}

type ReportAction = 'dismiss' | 'delete_item' | 'suspend_owner' | 'legal_hold_owner';

const ACTION_LABELS: Record<ReportAction, string> = {
  dismiss: 'Dismiss',
  delete_item: 'Remove content',
  suspend_owner: 'Suspend account',
  legal_hold_owner: 'Legal hold account',
};

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : 'Request failed.';
}

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleString() : '';
}

function statusClass(status: AdminReport['status']): string {
  switch (status) {
    case 'resolved':
      return 'text-green-700';
    case 'dismissed':
      return 'text-muted-foreground';
    default:
      return 'text-amber-600';
  }
}

function AdminReportsPage() {
  const [items, setItems] = useState<AdminReport[]>([]);
  const [filter, setFilter] = useState<StatusFilter>('open');
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [notes, setNotes] = useState<Record<number, string>>({});
  const [busy, setBusy] = useState<Record<number, boolean>>({});

  const load = async (next: StatusFilter, nextPage = 1): Promise<void> => {
    if (nextPage > 1) {
      setLoadingMore(true);
    } else {
      setLoading(true);
    }
    try {
      const params = new URLSearchParams({ page: String(nextPage) });
      if (next !== 'all') {
        params.set('status', next);
      }
      const response = (await fetchWrapper.get(`/api/admin/reports?${params.toString()}`)) as PagedReports;
      const rows = response.data ?? [];
      setItems((current) => (nextPage > 1 ? [...current, ...rows] : rows));
      setHasMore(response.meta?.has_more ?? false);
      setPage(nextPage);
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setLoading(false);
      setLoadingMore(false);
    }
  };

  useEffect(() => {
    void load(filter, 1);
     
  }, [filter]);

  const act = async (report: AdminReport, action: ReportAction): Promise<void> => {
    const confirmMessage = action === 'dismiss'
      ? null
      : `${ACTION_LABELS[action]}? This cannot be undone from here.`;
    if (confirmMessage && !window.confirm(confirmMessage)) {
      return;
    }

    setBusy((current) => ({ ...current, [report.id]: true }));
    try {
      const response = (await fetchWrapper.post(`/api/admin/reports/${report.id}/act`, {
        action,
        notes: notes[report.id]?.trim() || null,
      })) as { data: AdminReport };
      // Drop the row from the open queue, or replace it in place otherwise.
      setItems((current) => (filter === 'open'
        ? current.filter((r) => r.id !== report.id)
        : current.map((r) => (r.id === report.id ? response.data : r))));
      toast.success(`${ACTION_LABELS[action]} applied.`);
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setBusy((current) => ({ ...current, [report.id]: false }));
    }
  };

  return (
    <div className="mx-auto max-w-4xl px-4 py-8">
      <div className="mb-6">
        <h1 className="text-2xl font-bold">Abuse reports</h1>
        <p className="text-muted-foreground">Review reported media, stories, and posts, and act on the item or the account.</p>
      </div>

      <div className="mb-6 flex flex-wrap gap-2">
        {FILTERS.map((value) => (
          <Button key={value} type="button" size="sm" variant={filter === value ? 'default' : 'outline'} onClick={() => setFilter(value)}>
            {value.charAt(0).toUpperCase() + value.slice(1)}
          </Button>
        ))}
      </div>

      {loading ? (
        <p className="text-muted-foreground">Loading…</p>
      ) : items.length === 0 ? (
        <p className="text-muted-foreground">No reports in this view.</p>
      ) : (
        <div className="space-y-4">
          {items.map((report) => {
            const item = report.reportable;
            const owner = item?.owner ?? null;
            const isBusy = busy[report.id] ?? false;
            return (
              <Card key={report.id}>
                <CardHeader>
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="text-base">{report.reason_label}</CardTitle>
                    <span className={`text-sm font-medium ${statusClass(report.status)}`}>{report.status}</span>
                  </div>
                </CardHeader>
                <CardContent className="space-y-3 text-sm">
                  <div className="flex flex-wrap items-center gap-2">
                    {item ? (
                      <>
                        <Badge variant="outline">{item.type}</Badge>
                        {item.href ? (
                          <a className="underline underline-offset-4" href={item.href} target="_blank" rel="noreferrer">{item.label}</a>
                        ) : (
                          <span className="font-medium">{item.label}</span>
                        )}
                        {item.deleted && <Badge variant="outline" className="text-destructive">removed</Badge>}
                      </>
                    ) : (
                      <span className="text-muted-foreground">The reported item no longer exists.</span>
                    )}
                  </div>

                  {owner && (
                    <p className="text-muted-foreground">
                      Owner: <span className="font-medium text-foreground">{owner.display_name}</span> ({owner.email})
                      {owner.is_banned && <Badge variant="outline" className="ml-2 text-destructive">suspended</Badge>}
                      {owner.is_on_legal_hold && <Badge variant="outline" className="ml-2 text-amber-600">legal hold</Badge>}
                    </p>
                  )}

                  {report.details && <p className="rounded-md bg-muted px-3 py-2">{report.details}</p>}

                  <p className="text-xs text-muted-foreground">
                    Reported by {report.reporter?.display_name ?? 'unknown'} · {formatDate(report.created_at)}
                  </p>

                  {report.status !== 'open' ? (
                    <p className="text-xs text-muted-foreground">
                      {report.resolution} {report.reviewer && <>· by {report.reviewer.display_name}</>} {report.reviewed_at && <>· {formatDate(report.reviewed_at)}</>}
                    </p>
                  ) : (
                    <div className="space-y-2 border-t border-border pt-3">
                      <Textarea
                        value={notes[report.id] ?? ''}
                        onChange={(event) => setNotes((current) => ({ ...current, [report.id]: event.target.value }))}
                        disabled={isBusy}
                        placeholder="Internal notes (optional) — appended to the resolution / ban reason."
                      />
                      <div className="flex flex-wrap gap-2">
                        <Button type="button" size="sm" variant="outline" onClick={() => void act(report, 'dismiss')} disabled={isBusy}>
                          {ACTION_LABELS.dismiss}
                        </Button>
                        <Button type="button" size="sm" variant="outline" onClick={() => void act(report, 'delete_item')} disabled={isBusy || !item}>
                          {ACTION_LABELS.delete_item}
                        </Button>
                        <Button type="button" size="sm" variant="destructive" onClick={() => void act(report, 'suspend_owner')} disabled={isBusy || !owner}>
                          {ACTION_LABELS.suspend_owner}
                        </Button>
                        <Button type="button" size="sm" variant="destructive" onClick={() => void act(report, 'legal_hold_owner')} disabled={isBusy || !owner}>
                          {ACTION_LABELS.legal_hold_owner}
                        </Button>
                      </div>
                    </div>
                  )}
                </CardContent>
              </Card>
            );
          })}
        </div>
      )}

      {hasMore && (
        <div className="mt-6 flex justify-center">
          <Button type="button" variant="outline" disabled={loadingMore} onClick={() => void load(filter, page + 1)}>
            {loadingMore ? 'Loading…' : 'Load more'}
          </Button>
        </div>
      )}

      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('admin-reports');
if (mountEl) {
  createRoot(mountEl).render(<AdminReportsPage />);
}
