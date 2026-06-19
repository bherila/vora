import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { fetchWrapper } from '@/fetchWrapper';

type InviteStatus = 'active' | 'used' | 'expired' | 'revoked';

interface InviteRow {
  uuid: string;
  url: string;
  status: InviteStatus;
  invited_user: string | null;
  expires_at: string | null;
  used_at: string | null;
  created_at: string | null;
}

interface InvitesData {
  balance: number;
  next_grant_expires_at: string | null;
  invites: InviteRow[];
}

function formatDate(value: string | null): string {
  if (!value) {
    return '—';
  }
  return new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function statusBadge(invite: InviteRow) {
  switch (invite.status) {
    case 'used':
      return <Badge variant="secondary">Used{invite.invited_user ? ` by ${invite.invited_user}` : ''}</Badge>;
    case 'expired':
      return <Badge variant="outline">Expired</Badge>;
    case 'revoked':
      return <Badge variant="outline">Revoked</Badge>;
    default:
      return <Badge>Active</Badge>;
  }
}

function InvitesPage() {
  const [data, setData] = useState<InvitesData | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [copied, setCopied] = useState<string | null>(null);

  const load = async () => {
    try {
      const response = await fetchWrapper.get('/api/invites');
      if (response.success) {
        setData(response.data as InvitesData);
      }
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Could not load your invites.');
    }
  };

  useEffect(() => {
    void load();
  }, []);

  const generate = async () => {
    setError('');
    setBusy(true);
    try {
      const response = await fetchWrapper.post('/api/invites', {});
      if (response.success) {
        await load();
      } else {
        setError(response.message || 'Could not generate an invite.');
      }
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Could not generate an invite.');
    } finally {
      setBusy(false);
    }
  };

  const revoke = async (uuid: string) => {
    setError('');
    setBusy(true);
    try {
      const response = await fetchWrapper.delete(`/api/invites/${uuid}`);
      if (response.success) {
        await load();
      } else {
        setError(response.message || 'Could not revoke the invite.');
      }
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Could not revoke the invite.');
    } finally {
      setBusy(false);
    }
  };

  const copy = async (invite: InviteRow) => {
    try {
      await navigator.clipboard.writeText(invite.url);
      setCopied(invite.uuid);
      window.setTimeout(() => setCopied(null), 2000);
    } catch {
      setError('Could not copy the link. Copy it manually instead.');
    }
  };

  const balance = data?.balance ?? 0;

  return (
    <div className="mx-auto max-w-3xl px-4 py-8">
      <Card>
        <CardHeader>
          <CardTitle>Invites</CardTitle>
          <CardDescription>Share invite links with people you want to bring to the community.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          <div className="flex items-center justify-between rounded-md border p-4">
            <div>
              <p className="text-sm text-muted-foreground">Invites available</p>
              <p className="text-2xl font-bold">{balance}</p>
              {data?.next_grant_expires_at && (
                <p className="text-xs text-muted-foreground">
                  Soonest to expire: {formatDate(data.next_grant_expires_at)}
                </p>
              )}
            </div>
            <Button type="button" onClick={() => void generate()} disabled={busy || balance < 1}>
              Generate invite link
            </Button>
          </div>

          {balance < 1 && (
            <p className="text-sm text-muted-foreground">
              You have no invites available right now. An administrator can issue you more.
            </p>
          )}

          {data && data.invites.length > 0 && (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Link</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Expires</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.invites.map((invite) => (
                  <TableRow key={invite.uuid}>
                    <TableCell className="max-w-[18rem] truncate font-mono text-xs">{invite.url}</TableCell>
                    <TableCell>{statusBadge(invite)}</TableCell>
                    <TableCell>{formatDate(invite.expires_at)}</TableCell>
                    <TableCell className="text-right">
                      {invite.status === 'active' && (
                        <div className="flex justify-end gap-2">
                          <Button type="button" size="sm" variant="outline" onClick={() => void copy(invite)}>
                            {copied === invite.uuid ? 'Copied' : 'Copy'}
                          </Button>
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => void revoke(invite.uuid)}
                            disabled={busy}
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

const mountEl = document.getElementById('user-invites');
if (mountEl) {
  createRoot(mountEl).render(<InvitesPage />);
}
