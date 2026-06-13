import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { fetchWrapper } from '@/fetchWrapper';

interface AuditLogEntry {
  id: number;
  event: string;
  email: string | null;
  ip_address: string | null;
  created_at: string;
  user_id: number | null;
}

interface PaginatedResponse {
  data: AuditLogEntry[];
  total: number;
}

function AuditLogPage() {
  const [entries, setEntries] = useState<AuditLogEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    void (async () => {
      try {
        const response = await fetchWrapper.get('/api/auth/audit-log/all') as PaginatedResponse;
        setEntries(response.data);
      } catch (err) {
        setError(typeof err === 'string' ? err : 'Failed to load audit log.');
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  return (
    <div className="mx-auto max-w-6xl px-4 py-8">
      <h1 className="mb-6 text-2xl font-bold">Admin — Audit Log</h1>

      {error && (
        <div className="mb-4 rounded border border-destructive bg-destructive/10 p-3 text-sm text-destructive">
          {error}
        </div>
      )}

      {loading ? (
        <p className="text-muted-foreground">Loading audit log...</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Event</TableHead>
              <TableHead>Email</TableHead>
              <TableHead>IP Address</TableHead>
              <TableHead>Date</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {entries.map((entry) => (
              <TableRow key={entry.id}>
                <TableCell className="font-medium">{entry.event}</TableCell>
                <TableCell>{entry.email ?? '—'}</TableCell>
                <TableCell>{entry.ip_address ?? '—'}</TableCell>
                <TableCell className="text-sm text-muted-foreground">
                  {new Date(entry.created_at).toLocaleString()}
                </TableCell>
              </TableRow>
            ))}
            {entries.length === 0 && (
              <TableRow>
                <TableCell colSpan={4} className="text-center text-muted-foreground">
                  No audit log entries found.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      )}
    </div>
  );
}

const mountEl = document.getElementById('admin-audit-log');
if (mountEl) {
  createRoot(mountEl).render(<AuditLogPage />);
}
