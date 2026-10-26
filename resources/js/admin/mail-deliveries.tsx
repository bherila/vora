import { type FormEvent, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { BROWSING_PAGE_WIDTH } from '@/components/page-width';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { fetchWrapper } from '@/fetchWrapper';
import type { PageMeta } from '@/media/types';

type DeliveryStatus = 'sent' | 'deferred' | 'delivered' | 'bounced' | 'blocked' | 'failed' | 'complained';

interface MailDelivery {
  id: number;
  type: string | null;
  recipient: string;
  user: { id: number; name: string } | null;
  mailer: string;
  status: DeliveryStatus;
  status_reason: string | null;
  sent_at: string;
  status_at: string | null;
}

interface MailDeliveryResponse {
  data: MailDelivery[];
  meta: PageMeta;
  summary: Partial<Record<DeliveryStatus, number>>;
  reports: { brevo: boolean; ses: boolean };
}

const STATUS_LABELS: Record<DeliveryStatus, string> = {
  sent: 'Sent, awaiting report',
  deferred: 'Deferred',
  delivered: 'Delivered',
  bounced: 'Bounced',
  blocked: 'Blocked',
  failed: 'Failed',
  complained: 'Marked as spam',
};

const PROBLEM_STATUSES: DeliveryStatus[] = ['bounced', 'blocked', 'failed', 'complained'];

function statusVariant(status: DeliveryStatus): 'default' | 'secondary' | 'destructive' | 'outline' {
  if (PROBLEM_STATUSES.includes(status)) return 'destructive';
  if (status === 'delivered') return 'secondary';
  return 'outline';
}

export function MailDeliveriesPage() {
  const [status, setStatus] = useState('');
  const [draftSearch, setDraftSearch] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [response, setResponse] = useState<MailDeliveryResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError('');
    const params = new URLSearchParams({ page: String(page) });
    if (status) params.set('status', status);
    if (search) params.set('search', search);
    (fetchWrapper.get(`/api/admin/mail-deliveries?${params}`) as Promise<MailDeliveryResponse>)
      .then((result) => { if (active) setResponse(result); })
      .catch((failure: unknown) => { if (active) setError(typeof failure === 'string' ? failure : 'Failed to load email deliveries.'); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [page, status, search]);

  const submitSearch = (event: FormEvent): void => {
    event.preventDefault();
    setPage(1);
    setSearch(draftSearch.trim());
  };
  const summary = response?.summary ?? {};
  const problems = PROBLEM_STATUSES.reduce((total, key) => total + (summary[key] ?? 0), 0);
  const reportsConfigured = response ? response.reports.brevo || response.reports.ses : true;

  return <div className={`mx-auto space-y-6 px-4 py-6 ${BROWSING_PAGE_WIDTH}`}>
    <div>
      <h1 className="text-2xl font-semibold">Email delivery</h1>
      <p className="mt-1 text-sm text-muted-foreground">What the mail provider reported for each email. Opens and clicks are not tracked. Records are kept for 90 days.</p>
    </div>
    {!reportsConfigured && <p role="status" className="rounded border border-dashed p-3 text-sm">Delivery reports are not configured, so emails stay at “Sent, awaiting report”. Set <code>BREVO_WEBHOOK_SECRET</code> or <code>SES_NOTIFICATION_TOPIC_ARN</code> and register the webhook with the provider.</p>}
    {response && <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4" aria-label="Last 7 days">
      <div className="rounded border p-3"><dt className="text-xs text-muted-foreground">Delivered (7 days)</dt><dd className="text-xl font-semibold">{summary.delivered ?? 0}</dd></div>
      <div className="rounded border p-3"><dt className="text-xs text-muted-foreground">Awaiting report</dt><dd className="text-xl font-semibold">{summary.sent ?? 0}</dd></div>
      <div className="rounded border p-3"><dt className="text-xs text-muted-foreground">Deferred</dt><dd className="text-xl font-semibold">{summary.deferred ?? 0}</dd></div>
      <div className="rounded border p-3"><dt className="text-xs text-muted-foreground">Problems</dt><dd className={`text-xl font-semibold ${problems > 0 ? 'text-destructive' : ''}`}>{problems}</dd></div>
    </dl>}
    <form onSubmit={submitSearch} className="flex flex-wrap items-end gap-3">
      <div className="grid min-w-48 flex-1 gap-1"><Label htmlFor="mail-search">Recipient</Label><Input id="mail-search" type="search" maxLength={254} placeholder="Email address" value={draftSearch} onChange={(event) => setDraftSearch(event.target.value)} /></div>
      <div className="grid gap-1"><Label htmlFor="mail-status">Status</Label><select id="mail-status" className="h-9 rounded-md border bg-background px-2 text-sm" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}><option value="">All statuses</option>{(Object.keys(STATUS_LABELS) as DeliveryStatus[]).map((key) => <option key={key} value={key}>{STATUS_LABELS[key]}</option>)}</select></div>
      <Button type="submit">Search</Button>
    </form>
    {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
    {loading ? <p role="status" className="text-sm text-muted-foreground">Loading email deliveries…</p> : response && <div className="overflow-x-auto">
      <Table>
        <TableHeader><TableRow><TableHead>Sent</TableHead><TableHead>Recipient</TableHead><TableHead>Email</TableHead><TableHead>Status</TableHead><TableHead>Detail</TableHead></TableRow></TableHeader>
        <TableBody>
          {response.data.map((delivery) => <TableRow key={delivery.id}>
            <TableCell className="whitespace-nowrap text-sm text-muted-foreground">{new Date(delivery.sent_at).toLocaleString()}</TableCell>
            <TableCell><span className="break-all">{delivery.recipient}</span>{delivery.user && <span className="block text-xs text-muted-foreground">{delivery.user.name}</span>}</TableCell>
            <TableCell className="text-sm">{delivery.type ?? '—'}<span className="block text-xs text-muted-foreground">{delivery.mailer}</span></TableCell>
            <TableCell><Badge variant={statusVariant(delivery.status)}>{STATUS_LABELS[delivery.status]}</Badge>{delivery.status_at && delivery.status !== 'sent' && <span className="block text-xs text-muted-foreground">{new Date(delivery.status_at).toLocaleString()}</span>}</TableCell>
            <TableCell className="max-w-xs text-xs text-muted-foreground"><span className="break-words">{delivery.status_reason ?? '—'}</span></TableCell>
          </TableRow>)}
          {response.data.length === 0 && <TableRow><TableCell colSpan={5} className="text-center text-muted-foreground">No emails match.</TableCell></TableRow>}
        </TableBody>
      </Table>
    </div>}
    {response && response.meta.last_page > 1 && <nav aria-label="Email delivery pages" className="flex items-center justify-between gap-3"><Button variant="outline" disabled={loading || page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</Button><span className="text-sm">Page {response.meta.current_page} of {response.meta.last_page}</span><Button variant="outline" disabled={loading || !response.meta.has_more} onClick={() => setPage((value) => value + 1)}>Next</Button></nav>}
  </div>;
}

const mountEl = document.getElementById('admin-mail-deliveries');
if (mountEl) createRoot(mountEl).render(<MailDeliveriesPage />);
