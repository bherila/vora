import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { BROWSING_PAGE_WIDTH } from '@/components/page-width';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { eventError } from '@/events/types';
import { fetchWrapper } from '@/fetchWrapper';
import type { PageMeta } from '@/media/types';

interface OrganizerEvent {
  ulid: string;
  name: string;
  visibility: 'everyone' | 'specific';
}

interface OrganizerAttendee {
  id: number;
  display_name: string;
  email: string;
  is_admin: boolean;
  approved: boolean;
  blocked_reason: string | null;
  can_approve: boolean;
  can_manage_access: boolean;
  event_ulids: string[];
  event_uploads_auto_approved: boolean;
}

interface OrganizerResponse {
  data: OrganizerAttendee[];
  events: OrganizerEvent[];
  meta: PageMeta;
}

function blockedMessage(reason: string | null): string | null {
  switch (reason) {
    case 'email_verification': return 'Waiting for email verification.';
    case 'minimum_age': return 'Does not meet the current age policy. Check account details in Admin → Users.';
    case 'verification_required': return 'Age confirmation is required in Admin → Users.';
    case 'account_unavailable': return 'This account is disabled, banned or deactivated. Manage it in Admin → Users.';
    default: return null;
  }
}

interface AttendeeAccessDialogProps {
  attendee: OrganizerAttendee;
  events: OrganizerEvent[];
  onClose: () => void;
  onSaved: () => void;
}

function AttendeeAccessDialog({ attendee, events, onClose, onSaved }: AttendeeAccessDialogProps) {
  const [selected, setSelected] = useState(attendee.event_ulids);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const specific = events.filter((event) => event.visibility === 'specific');
  const everyone = events.filter((event) => event.visibility === 'everyone');
  async function save(event: FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      await fetchWrapper.put(`/api/admin/events/attendees/${attendee.id}/access`, { event_ulids: selected });
      onSaved();
      onClose();
    } catch (failure) {
      setError(eventError(failure));
    } finally {
      setBusy(false);
    }
  }

  return <Dialog open onOpenChange={(open) => { if (!open && !busy) onClose(); }}>
    <DialogContent className="max-h-[85vh] overflow-y-auto">
      <DialogHeader><DialogTitle className="break-words">Event access for {attendee.display_name}</DialogTitle></DialogHeader>
      <form onSubmit={(event) => void save(event)} className="space-y-4">
        <p className="text-sm text-muted-foreground">Changes take effect immediately. Previously Saved items stay in the attendee’s private collection.</p>
        {attendee.is_admin && <p className="text-sm">Administrators can access every Event regardless of these selections.</p>}
        {everyone.length > 0 && <p className="text-sm">All approved attendees can access: {everyone.map((event) => event.name).join(', ')}.</p>}
        <fieldset disabled={busy} className="space-y-3">
          <legend className="mb-2 text-sm font-medium">Selected-attendee Events</legend>
          {specific.length === 0 && <p className="text-sm text-muted-foreground">There are no selected-attendee Events yet.</p>}
          {specific.map((event) => <label key={event.ulid} className="flex items-center gap-3 text-sm">
            <input type="checkbox" checked={selected.includes(event.ulid)} onChange={(change) => setSelected((ids) => change.target.checked ? [...ids, event.ulid] : ids.filter((id) => id !== event.ulid))} />
            {event.name}
          </label>)}
        </fieldset>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <div className="flex gap-2"><Button type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save Event access'}</Button><Button type="button" variant="outline" disabled={busy} onClick={onClose}>Cancel</Button></div>
      </form>
    </DialogContent>
  </Dialog>;
}

export function EventOrganizerPage() {
  const [draftSearch, setDraftSearch] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('all');
  const [page, setPage] = useState(1);
  const [data, setData] = useState<OrganizerResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busyIds, setBusyIds] = useState<number[]>([]);
  const [editing, setEditing] = useState<OrganizerAttendee | null>(null);
  const requestVersion = useRef(0);
  const mutating = busyIds.length > 0;

  const load = useCallback(async (): Promise<void> => {
    const version = ++requestVersion.current;
    setLoading(true);
    setError('');
    const params = new URLSearchParams({ page: String(page), status });
    if (search) params.set('search', search);
    try {
      const response = await fetchWrapper.get(`/api/admin/events/attendees?${params}`) as OrganizerResponse;
      if (version === requestVersion.current) {
        if (page > response.meta.last_page) {
          setData(null);
          setPage(Math.max(1, response.meta.last_page));
        } else {
          setData(response);
        }
      }
    } catch (failure) {
      if (version === requestVersion.current) setError(eventError(failure));
    } finally {
      if (version === requestVersion.current) setLoading(false);
    }
  }, [page, search, status]);

  useEffect(() => {
    void load();
    return () => { requestVersion.current += 1; };
  }, [load]);

  async function changeAttendee(attendee: OrganizerAttendee, approve: boolean): Promise<void> {
    setBusyIds((ids) => [...ids, attendee.id]);
    setError('');
    setNotice('');
    try {
      if (approve) {
        await fetchWrapper.post(`/api/admin/users/${attendee.id}/approve`, {});
        setNotice(`${attendee.display_name} is approved. You can now choose their Event access.`);
      } else {
        await fetchWrapper.patch(`/api/admin/users/${attendee.id}`, { event_uploads_auto_approved: !attendee.event_uploads_auto_approved });
        setNotice(`Upload review settings updated for ${attendee.display_name}.`);
      }
      await load();
    } catch (failure) {
      setError(eventError(failure));
    } finally {
      setBusyIds((ids) => ids.filter((id) => id !== attendee.id));
    }
  }

  function submitSearch(event: FormEvent): void {
    event.preventDefault();
    setPage(1);
    setSearch(draftSearch.trim());
  }

  return <div className={`mx-auto space-y-6 px-4 py-6 ${BROWSING_PAGE_WIDTH}`}>
    <div className="flex flex-wrap items-center justify-between gap-3"><h1 className="text-2xl font-semibold">Manage attendees</h1><a href="/events" className="text-sm underline">Back to Events</a></div>
    <p className="text-sm text-muted-foreground">Approve accounts and manage Event access here. Signup invitations create accounts; they do not grant access to selected-attendee Events.</p>
    <form onSubmit={submitSearch} className="flex flex-wrap items-end gap-3">
      <div className="grid min-w-48 flex-1 gap-1"><Label htmlFor="attendee-search">Search attendees</Label><Input id="attendee-search" type="search" maxLength={100} disabled={mutating} placeholder="Name or email" value={draftSearch} onChange={(event) => setDraftSearch(event.target.value)} /></div>
      <div className="grid gap-1"><Label htmlFor="attendee-status">Account status</Label><select id="attendee-status" className="rounded-md border bg-background p-2" disabled={mutating} value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}><option value="all">All accounts</option><option value="pending">Awaiting approval</option><option value="approved">Approved accounts</option></select></div>
      <Button type="submit" disabled={mutating}>Search</Button>
    </form>
    {notice && <p role="status" className="text-sm">{notice}</p>}
    {error && <div role="alert" className="flex flex-wrap items-center gap-3 text-sm text-destructive"><p>{error}</p><Button variant="outline" disabled={mutating} onClick={() => void load()}>Refresh attendees</Button></div>}
    {loading && <p role="status" className="text-sm text-muted-foreground">Loading attendees…</p>}
    {!loading && data?.data.length === 0 && <p>No attendees match this search.</p>}
    {data && <div className="grid gap-4 md:grid-cols-2">
      {data.data.map((attendee) => {
        const busy = busyIds.includes(attendee.id) || loading;
        const blocked = blockedMessage(attendee.blocked_reason);
        const memberships = data.events.filter((event) => attendee.event_ulids.includes(event.ulid));
        return <Card key={attendee.id} className="min-w-0"><CardContent className="space-y-3 p-4">
          <div className="flex flex-wrap items-center gap-2"><h2 className="break-words font-semibold">{attendee.display_name}</h2><Badge variant="outline">{attendee.approved ? 'Approved' : 'Awaiting approval'}</Badge>{attendee.is_admin && <Badge variant="outline">Administrator</Badge>}</div>
          <p className="break-all text-sm text-muted-foreground">{attendee.email}</p>
          {blocked && <p className="text-sm">{blocked} <a href="/admin/users" className="underline">Manage account</a></p>}
          <p className="text-sm">{attendee.is_admin ? 'Administrator access to every Event.' : `Selected Events: ${memberships.length ? memberships.map((event) => event.name).join(', ') : 'None'}. All-attendee Events become available after account approval and eligibility checks.`}</p>
          <div className="flex flex-wrap gap-2">{!attendee.approved && <Button size="sm" aria-label={`Approve ${attendee.display_name}`} disabled={busy || !attendee.can_approve} onClick={() => void changeAttendee(attendee, true)}>Approve</Button>}<Button size="sm" variant="outline" aria-label={`Edit Event access for ${attendee.display_name}`} disabled={busy || !attendee.can_manage_access} onClick={() => setEditing(attendee)}>Edit Event access</Button></div>
          <label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" aria-label={`Skip upload review for ${attendee.display_name}`} checked={attendee.event_uploads_auto_approved} disabled={busy} onChange={() => void changeAttendee(attendee, false)} /><span>Publish this attendee’s Event uploads without review.</span></label>
          <p className="text-xs text-muted-foreground">Applies to future completed Event uploads. Account approval and Event access are still required.</p>
        </CardContent></Card>;
      })}
    </div>}
    {data && data.meta.last_page > 1 && <nav aria-label="Attendee pages" className="flex items-center justify-between gap-3"><Button variant="outline" disabled={loading || mutating || page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</Button><span className="text-sm">Page {data.meta.current_page} of {data.meta.last_page}</span><Button variant="outline" disabled={loading || mutating || !data.meta.has_more} onClick={() => setPage((value) => value + 1)}>Next</Button></nav>}
    {editing && data && <AttendeeAccessDialog key={editing.id} attendee={editing} events={data.events} onClose={() => setEditing(null)} onSaved={() => { setNotice(`Event access updated for ${editing.display_name}.`); void load(); }} />}
  </div>;
}

const organizerRoot = document.getElementById('event-organizer');
if (organizerRoot) createRoot(organizerRoot).render(<EventOrganizerPage />);
