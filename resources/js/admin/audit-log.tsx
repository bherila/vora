import { type FormEvent, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { BROWSING_PAGE_WIDTH } from '@/components/page-width';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { fetchWrapper } from '@/fetchWrapper';

interface AuditLogEntry {
  id: number;
  event: string;
  email: string | null;
  ip_address: string | null;
  created_at: string;
  user_id: number | null;
  acting_user_id: number | null;
  auth_method: string | null;
  succeeded: boolean;
  is_suspicious: boolean;
  reason: string | null;
  metadata: Record<string, unknown> | null;
}

interface PaginatedResponse {
  data: AuditLogEntry[];
  total: number;
  current_page: number;
  last_page: number;
}

interface Filters {
  userId: string;
  event: string;
  email: string;
  perPage: string;
}

const EMPTY_FILTERS: Filters = { userId: '', event: '', email: '', perPage: '50' };
const REASONS = new Map([
  ['invalid_credentials', 'Invalid credentials'],
  ['account_disabled', 'Account disabled'],
  ['rate_limited', 'Rate limited'],
]);

function initialFilters(): Filters {
  const userId = new URLSearchParams(window.location.search).get('user_id') ?? '';
  return {
    ...EMPTY_FILTERS,
    userId: /^[1-9]\d*$/.test(userId) && Number.isSafeInteger(Number(userId)) ? userId : '',
  };
}

interface LocalUserProps {
  id: number | null;
}

interface AuditDetailsProps {
  entry: AuditLogEntry;
}

function LocalUser({ id }: LocalUserProps) {
  // Only host audit identity columns identify local users. Provider subjects and
  // other metadata must never be interpreted as local user IDs.
  return typeof id === 'number' && Number.isSafeInteger(id) && id > 0
    ? <a className="text-primary underline" href={`/admin/users#user-${id}`}>User #{id}</a>
    : <span className="text-muted-foreground">Not recorded</span>;
}

function adminAccess(value: unknown): boolean | null {
  if (typeof value !== 'object' || value === null) return null;
  if ('is_admin' in value && typeof value.is_admin === 'boolean') return value.is_admin;
  if ('application_admin' in value && typeof value.application_admin === 'boolean') return value.application_admin;
  return null;
}

function AuditDetails({ entry }: AuditDetailsProps) {
  // Explicit scalar allowlist: never stringify arbitrary metadata, credential
  // identifiers, tokens, session IDs, provider subjects or exception reasons.
  const before = adminAccess(entry.metadata?.before);
  const after = adminAccess(entry.metadata?.after);
  const reason = entry.reason ? REASONS.get(entry.reason) ?? 'A reason was recorded; raw diagnostic text is omitted.' : null;
  return <details className="max-w-sm text-sm">
    <summary className="cursor-pointer whitespace-nowrap">Details <span className="sr-only">for event #{entry.id}</span></summary>
    <dl className="mt-2 space-y-2 break-words">
      <div><dt className="font-medium">Authentication method</dt><dd>{entry.auth_method ?? 'Not recorded'}</dd></div>
      <div><dt className="font-medium">IP address</dt><dd>{entry.ip_address ?? 'Not recorded'}</dd></div>
      {reason && <div><dt className="font-medium">Reason</dt><dd>{reason}</dd></div>}
      {typeof entry.metadata?.attempt_suspicious === 'boolean' && <div><dt className="font-medium">Suspicious challenge</dt><dd>{entry.metadata.attempt_suspicious ? 'Yes' : 'No'}</dd></div>}
      {before !== null && after !== null && <div><dt className="font-medium">Application administrator</dt><dd>{before ? 'Yes' : 'No'} → {after ? 'Yes' : 'No'}</dd></div>}
    </dl>
  </details>;
}

export function AuditLogPage() {
  const [draft, setDraft] = useState<Filters>(initialFilters);
  const [query, setQuery] = useState(() => ({ ...initialFilters(), page: 1 }));
  const [response, setResponse] = useState<PaginatedResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError(false);
    setResponse(null);
    const params = new URLSearchParams({ page: String(query.page), per_page: query.perPage });
    if (query.userId) params.set('user_id', query.userId);
    if (query.event) params.set('event', query.event);
    if (query.email) params.set('email', query.email);
    (fetchWrapper.get(`/api/auth/audit-log/all?${params}`) as Promise<PaginatedResponse>)
      .then((result) => { if (active) setResponse(result); })
      .catch(() => { if (active) setError(true); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [query]);

  function applyFilters(event: FormEvent): void {
    event.preventDefault();
    setQuery({ userId: draft.userId.trim(), event: draft.event.trim(), email: draft.email.trim(), perPage: draft.perPage, page: 1 });
  }

  return <div className={`${BROWSING_PAGE_WIDTH} space-y-6 px-4 py-8`}>
    <div>
      <h1 className="text-2xl font-bold">Admin — Audit Log</h1>
      <p className="mt-1 text-sm text-muted-foreground">Authentication and access events recorded by this application. Provider directory events are separate. Details show selected fields; credential and session data are omitted.</p>
    </div>
    <form onSubmit={applyFilters} className="flex flex-wrap items-end gap-3">
      <div className="grid gap-1"><Label htmlFor="audit-user">Local user ID</Label><Input id="audit-user" type="number" min="1" max={Number.MAX_SAFE_INTEGER} step="1" value={draft.userId} onChange={(event) => setDraft({ ...draft, userId: event.target.value })} /></div>
      <div className="grid min-w-48 flex-1 gap-1"><Label htmlFor="audit-email">Email (exact)</Label><Input id="audit-email" type="search" maxLength={255} value={draft.email} onChange={(event) => setDraft({ ...draft, email: event.target.value })} /></div>
      <div className="grid min-w-48 flex-1 gap-1"><Label htmlFor="audit-event">Event (exact)</Label><Input id="audit-event" type="search" maxLength={255} placeholder="e.g. login_failed" value={draft.event} onChange={(event) => setDraft({ ...draft, event: event.target.value })} /></div>
      <div className="grid gap-1"><Label htmlFor="audit-page-size">Per page</Label><select id="audit-page-size" className="h-9 rounded-md border bg-background px-2 text-sm" value={draft.perPage} onChange={(event) => setDraft({ ...draft, perPage: event.target.value })}>{[25, 50, 100, 200].map((size) => <option key={size} value={size}>{size}</option>)}</select></div>
      <Button type="submit">Apply filters</Button>
      <Button type="button" variant="outline" onClick={() => { setDraft(EMPTY_FILTERS); setQuery({ ...EMPTY_FILTERS, page: 1 }); }}>Clear filters</Button>
    </form>
    {error && <div role="alert" className="flex flex-wrap items-center gap-3 rounded border border-destructive p-3 text-sm"><p>Could not load the audit log. Check your connection and administrator access, then try again.</p><Button variant="outline" onClick={() => setQuery({ ...query })}>Retry</Button></div>}
    {loading && <p role="status" className="text-muted-foreground">Loading audit log…</p>}
    {!loading && response && <>
      <p role="status" className="text-sm text-muted-foreground">{response.total} {response.total === 1 ? 'event' : 'events'} match.</p>
      <Table>
        <TableHeader><TableRow><TableHead>Date</TableHead><TableHead>Event</TableHead><TableHead>Target</TableHead><TableHead>Actor</TableHead><TableHead>Result</TableHead><TableHead>Details</TableHead></TableRow></TableHeader>
        <TableBody>
          {response.data.map((entry) => <TableRow key={entry.id}>
            <TableCell className="whitespace-nowrap text-sm text-muted-foreground">{new Date(entry.created_at).toLocaleString()}</TableCell>
            <TableCell className="font-medium">{entry.event}</TableCell>
            <TableCell><LocalUser id={entry.user_id} />{entry.email && <span className="block break-all text-xs text-muted-foreground">{entry.email}</span>}</TableCell>
            <TableCell><LocalUser id={entry.acting_user_id} /></TableCell>
            <TableCell><Badge variant={entry.succeeded ? 'secondary' : 'destructive'}>{entry.succeeded ? 'Succeeded' : 'Failed'}</Badge>{entry.is_suspicious && <span className="mt-1 block text-xs text-destructive">Flagged suspicious</span>}</TableCell>
            <TableCell><AuditDetails entry={entry} /></TableCell>
          </TableRow>)}
          {response.data.length === 0 && <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground">No audit log entries match these filters.</TableCell></TableRow>}
        </TableBody>
      </Table>
      <nav aria-label="Audit log pages" className="flex items-center justify-between gap-3">
        <Button variant="outline" disabled={response.current_page <= 1} onClick={() => setQuery({ ...query, page: response.current_page - 1 })}>Previous</Button>
        <span className="text-sm">Page {response.current_page} of {response.last_page}</span>
        <Button variant="outline" disabled={response.current_page >= response.last_page} onClick={() => setQuery({ ...query, page: response.current_page + 1 })}>Next</Button>
      </nav>
    </>}
  </div>;
}

const mountEl = document.getElementById('admin-audit-log');
if (mountEl) createRoot(mountEl).render(<AuditLogPage />);
