import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { fetchWrapper } from '@/fetchWrapper';

interface WaitlistGeo {
  country?: string;
  city?: string;
  region?: string;
  postal?: string;
  latitude?: string;
  longitude?: string;
}

interface WaitlistRow {
  uuid: string;
  email: string;
  birth_date: string | null;
  interests: string;
  ip_address: string | null;
  geo: WaitlistGeo;
  is_verified: boolean;
  verified_at: string | null;
  is_admitted: boolean;
  admitted_at: string | null;
  created_at: string | null;
}

function formatDate(value: string | null): string {
  if (!value) {
    return '—';
  }
  return new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatLocation(geo: WaitlistGeo): string {
  const parts = [geo.city, geo.region, geo.country].filter((part): part is string => Boolean(part));
  return parts.length > 0 ? parts.join(', ') : '—';
}

function statusLabel(row: WaitlistRow): { label: string; variant: 'default' | 'outline' | 'destructive' } {
  if (row.is_admitted) {
    return { label: 'admitted', variant: 'default' };
  }
  if (row.is_verified) {
    return { label: 'verified', variant: 'outline' };
  }
  return { label: 'unverified', variant: 'destructive' };
}

function AdminWaitlistPage() {
  const [rows, setRows] = useState<WaitlistRow[]>([]);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busyUuid, setBusyUuid] = useState<string | null>(null);

  const load = async () => {
    try {
      const response = await fetchWrapper.get('/api/admin/waitlist');
      if (response.success) {
        setRows(response.data as WaitlistRow[]);
      }
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Could not load waitlist requests.');
    }
  };

  useEffect(() => {
    void load();
  }, []);

  const admit = async (row: WaitlistRow) => {
    setError('');
    setNotice('');
    setBusyUuid(row.uuid);
    try {
      const response = await fetchWrapper.post(`/api/admin/waitlist/${row.uuid}/admit`, {});
      if (response.success) {
        setNotice(`Invitation emailed to ${row.email}.`);
        await load();
      } else {
        setError(response.message || 'Could not admit this request.');
      }
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Could not admit this request.');
    } finally {
      setBusyUuid(null);
    }
  };

  const remove = async (row: WaitlistRow) => {
    setError('');
    setNotice('');
    setBusyUuid(row.uuid);
    try {
      await fetchWrapper.delete(`/api/admin/waitlist/${row.uuid}`);
      setNotice('Request deleted.');
      await load();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Could not delete this request.');
    } finally {
      setBusyUuid(null);
    }
  };

  return (
    <div className="mx-auto max-w-6xl space-y-6 px-4 py-8">
      <h1 className="text-2xl font-bold">Admin — Invitation requests</h1>

      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}
      {notice && (
        <Alert>
          <AlertDescription>{notice}</AlertDescription>
        </Alert>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Waitlist</CardTitle>
          <CardDescription>
            People who requested an invitation. Admit a verified request to email them an invite that creates an
            auto-approved account.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {rows.length === 0 ? (
            <p className="text-sm text-muted-foreground">No requests yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Email</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>About</TableHead>
                  <TableHead>Birth date</TableHead>
                  <TableHead>IP / location</TableHead>
                  <TableHead>Requested</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((row) => {
                  const status = statusLabel(row);
                  return (
                    <TableRow key={row.uuid}>
                      <TableCell className="font-medium">{row.email}</TableCell>
                      <TableCell>
                        <Badge variant={status.variant}>{status.label}</Badge>
                      </TableCell>
                      <TableCell className="max-w-xs whitespace-pre-wrap text-xs text-muted-foreground">
                        {row.interests}
                      </TableCell>
                      <TableCell>{formatDate(row.birth_date)}</TableCell>
                      <TableCell className="text-xs">
                        <div>{row.ip_address ?? '—'}</div>
                        <div className="text-muted-foreground">{formatLocation(row.geo)}</div>
                      </TableCell>
                      <TableCell>{formatDate(row.created_at)}</TableCell>
                      <TableCell className="space-x-2 text-right">
                        <Button
                          type="button"
                          size="sm"
                          disabled={busyUuid === row.uuid || row.is_admitted || !row.is_verified}
                          onClick={() => void admit(row)}
                        >
                          {row.is_admitted ? 'Admitted' : 'Admit'}
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="destructive"
                          disabled={busyUuid === row.uuid}
                          onClick={() => void remove(row)}
                        >
                          Delete
                        </Button>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

const mountEl = document.getElementById('admin-waitlist');
if (mountEl) {
  createRoot(mountEl).render(<AdminWaitlistPage />);
}
