import { type FormEvent, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import { READING_PAGE_WIDTH } from '@/components/page-width';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';

export type InvitationStatus = 'pending' | 'expired' | 'revoked' | 'superseded' | 'accepted' | 'closed';

export interface InvitationRow {
  id: number;
  email: string;
  status: InvitationStatus;
  email_sent: boolean;
  expires_at: string;
  created_at: string | null;
  invited_by: string | null;
}

export interface InvitationsData {
  is_admin: boolean;
  balance: number | null;
  invitations: InvitationRow[];
}

/** The link just issued: shown once, to the person who asked, and never stored. */
interface IssuedLink {
  email: string;
  link: string;
  emailSent: boolean;
}

function formatDate(value: string | null): string {
  if (!value) {
    return '—';
  }
  return new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function statusBadge(status: InvitationStatus) {
  switch (status) {
    case 'pending':
      return <Badge>Sent</Badge>;
    case 'accepted':
      return <Badge variant="secondary">Joined</Badge>;
    case 'expired':
      return <Badge variant="outline">Expired</Badge>;
    case 'revoked':
      return <Badge variant="outline">Revoked</Badge>;
    case 'superseded':
      return <Badge variant="outline">Replaced</Badge>;
    default:
      return <Badge variant="outline">Closed</Badge>;
  }
}

function errorMessage(err: unknown, fallback: string): string {
  return typeof err === 'string' && err !== '' ? err : fallback;
}

interface ProviderInvitationsPageProps {
  initial: InvitationsData | null;
}

export function ProviderInvitationsPage({ initial }: ProviderInvitationsPageProps) {
  const [data, setData] = useState<InvitationsData | null>(initial);
  const [email, setEmail] = useState('');
  const [issued, setIssued] = useState<IssuedLink | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const load = async () => {
    const response = await fetchWrapper.get('/api/invitations');
    if (response.success) {
      setData(response.data as InvitationsData);
    }
  };

  const run = async (request: () => Promise<{ success: boolean; message?: string; data?: { link?: string; invitation?: InvitationRow } }>, fallback: string) => {
    setError('');
    setIssued(null);
    setBusy(true);
    try {
      const response = await request();
      if (!response.success) {
        setError(response.message || fallback);
        return;
      }
      const invitation = response.data?.invitation;
      if (response.data?.link && invitation) {
        setIssued({ email: invitation.email, link: response.data.link, emailSent: invitation.email_sent });
      }
      return true;
    } catch (err) {
      setError(errorMessage(err, fallback));
    } finally {
      setBusy(false);
      // A refusal can still change an invitation's state (accepted elsewhere, say).
      await load().catch(() => undefined);
    }
  };

  const invite = async (event: FormEvent) => {
    event.preventDefault();
    if (await run(() => fetchWrapper.post('/api/invitations', { email }), 'Could not send the invitation.')) {
      setEmail('');
    }
  };

  const copy = async (link: string) => {
    try {
      await navigator.clipboard.writeText(link);
      toast.success('Copied');
    } catch {
      toast.error('Could not copy the link. Copy it manually instead.');
    }
  };

  const balance = data?.balance ?? null;
  const canInvite = data?.is_admin === true || (balance ?? 0) > 0;

  return (
    <div className={`${READING_PAGE_WIDTH} px-4 py-8`}>
      <Toaster position="top-right" richColors closeButton />
      <Card>
        <CardHeader>
          <CardTitle>Invites</CardTitle>
          <CardDescription>
            We email each person an invitation from the sign-in service. Their first sign-in creates their account here.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          <form className="space-y-3 rounded-md border p-4" onSubmit={(event) => void invite(event)}>
            <div className="space-y-1.5">
              <Label htmlFor="invite-email">Email address</Label>
              <Input
                id="invite-email"
                type="email"
                autoComplete="off"
                required
                maxLength={255}
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                disabled={busy || !canInvite}
              />
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3">
              <p className="text-sm text-muted-foreground">
                {balance === null ? 'Administrators can invite anyone.' : `Invites available: ${balance}`}
              </p>
              <Button type="submit" disabled={busy || !canInvite || email.trim() === ''}>
                Send invitation
              </Button>
            </div>
            {!canInvite && (
              <p className="text-sm text-muted-foreground">
                You have no invites available right now. An administrator can issue you more.
              </p>
            )}
          </form>

          {issued && (
            <Alert>
              <AlertDescription className="space-y-2">
                <p>
                  {issued.emailSent
                    ? `Invitation emailed to ${issued.email}. You can also share this link with them directly; it works once and is shown only now.`
                    : `The email to ${issued.email} could not be sent. Share this link with them directly; it works once and is shown only now.`}
                </p>
                <div className="flex items-center gap-2">
                  <Input readOnly value={issued.link} aria-label="Invitation link" className="font-mono text-xs" onFocus={(event) => event.target.select()} />
                  <Button type="button" variant="outline" size="sm" onClick={() => void copy(issued.link)}>
                    Copy
                  </Button>
                </div>
              </AlertDescription>
            </Alert>
          )}

          {data && data.invitations.length > 0 && (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Email</TableHead>
                  {data.is_admin && <TableHead>Invited by</TableHead>}
                  <TableHead>Status</TableHead>
                  <TableHead>Expires</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.invitations.map((invitation) => (
                  <TableRow key={invitation.id}>
                    <TableCell className="max-w-[16rem] truncate">{invitation.email}</TableCell>
                    {data.is_admin && <TableCell>{invitation.invited_by ?? '—'}</TableCell>}
                    <TableCell>{statusBadge(invitation.status)}</TableCell>
                    <TableCell>{formatDate(invitation.expires_at)}</TableCell>
                    <TableCell className="text-right">
                      {(invitation.status === 'pending' || invitation.status === 'expired') && (
                        <div className="flex justify-end gap-2">
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={busy}
                            onClick={() => void run(() => fetchWrapper.post(`/api/invitations/${invitation.id}/resend`, {}), 'Could not resend the invitation.')}
                          >
                            Resend
                          </Button>
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            disabled={busy}
                            onClick={() => void run(() => fetchWrapper.post(`/api/invitations/${invitation.id}/revoke`, {}), 'Could not revoke the invitation.')}
                          >
                            Revoke
                          </Button>
                        </div>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

const mountEl = document.getElementById('provider-invitations');
if (mountEl) {
  createRoot(mountEl).render(<ProviderInvitationsPage initial={readInitialData<{ invitations?: InvitationsData }>().invitations ?? null} />);
}
