import { useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { fetchWrapper } from '@/fetchWrapper';

interface SignupSettings {
  public_signups_enabled: boolean;
  default_new_user_invites: number;
  default_new_user_invite_expiry_days: number | null;
}

interface TreeUser {
  id: number;
  display_name: string | null;
  referrer_user_id: number | null;
  trusted_inviter: boolean;
  is_banned: boolean;
  balance: number;
}

interface RecentInvite {
  uuid: string;
  inviter: string | null;
  invited_user: string | null;
  status: string;
  expires_at: string | null;
  created_at: string | null;
}

interface InvitesData {
  settings: SignupSettings;
  users: TreeUser[];
  recent_invites: RecentInvite[];
}

function formatDate(value: string | null): string {
  if (!value) {
    return '—';
  }
  return new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

interface TreeNodeProps {
  user: TreeUser;
  childrenByParent: Map<number, TreeUser[]>;
}

function TreeNode({ user, childrenByParent }: TreeNodeProps) {
  const children = childrenByParent.get(user.id) ?? [];
  return (
    <li>
      <div className="flex items-center gap-2 py-1">
        <span className="font-medium">{user.display_name ?? `User #${user.id}`}</span>
        {user.trusted_inviter && <Badge>Trusted</Badge>}
        {user.is_banned && <Badge variant="destructive">Banned</Badge>}
        <span className="text-xs text-muted-foreground">{user.balance} invite(s)</span>
        {children.length > 0 && (
          <span className="text-xs text-muted-foreground">· {children.length} invited</span>
        )}
      </div>
      {children.length > 0 && (
        // Static class-based indentation per nesting level keeps the tree readable
        // without inline styles, which the app's CSP (style-src 'self') would block.
        <ul className="ml-5 border-l border-border pl-2">
          {children.map((child) => (
            <TreeNode key={child.id} user={child} childrenByParent={childrenByParent} />
          ))}
        </ul>
      )}
    </li>
  );
}

function AdminInvitesPage() {
  const [data, setData] = useState<InvitesData | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);

  // Settings form state.
  const [publicSignups, setPublicSignups] = useState(true);
  const [defaultInvites, setDefaultInvites] = useState('0');
  const [defaultExpiryDays, setDefaultExpiryDays] = useState('');

  // Issue-to-all form state.
  const [issueQuantity, setIssueQuantity] = useState('1');
  const [issueExpiryDays, setIssueExpiryDays] = useState('');

  const load = async () => {
    try {
      const response = await fetchWrapper.get('/api/admin/invites');
      if (response.success) {
        const payload = response.data as InvitesData;
        setData(payload);
        setPublicSignups(payload.settings.public_signups_enabled);
        setDefaultInvites(String(payload.settings.default_new_user_invites));
        setDefaultExpiryDays(
          payload.settings.default_new_user_invite_expiry_days === null
            ? ''
            : String(payload.settings.default_new_user_invite_expiry_days),
        );
      }
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Could not load invites.');
    }
  };

  useEffect(() => {
    void load();
  }, []);

  const saveSettings = async (nextPublicSignups: boolean) => {
    setError('');
    setNotice('');
    setBusy(true);
    try {
      await fetchWrapper.put('/api/admin/invites/settings', {
        public_signups_enabled: nextPublicSignups,
        default_new_user_invites: Number(defaultInvites) || 0,
        default_new_user_invite_expiry_days: defaultExpiryDays.trim() === '' ? null : Number(defaultExpiryDays),
      });
      setPublicSignups(nextPublicSignups);
      setNotice('Settings saved.');
      await load();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Could not save settings.');
    } finally {
      setBusy(false);
    }
  };

  const issueToAll = async () => {
    setError('');
    setNotice('');
    setBusy(true);
    try {
      const response = await fetchWrapper.post('/api/admin/invites/issue', {
        quantity: Number(issueQuantity),
        expires_in_days: issueExpiryDays.trim() === '' ? null : Number(issueExpiryDays),
      });
      setNotice(response.message || 'Invites issued.');
      await load();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Could not issue invites.');
    } finally {
      setBusy(false);
    }
  };

  const { roots, childrenByParent } = useMemo(() => {
    const users = data?.users ?? [];
    const byParent = new Map<number, TreeUser[]>();
    const ids = new Set(users.map((u) => u.id));
    const rootNodes: TreeUser[] = [];

    for (const user of users) {
      if (user.referrer_user_id !== null && ids.has(user.referrer_user_id)) {
        const list = byParent.get(user.referrer_user_id) ?? [];
        list.push(user);
        byParent.set(user.referrer_user_id, list);
      } else {
        rootNodes.push(user);
      }
    }

    return { roots: rootNodes, childrenByParent: byParent };
  }, [data]);

  return (
    <div className="mx-auto max-w-5xl space-y-6 px-4 py-8">
      <h1 className="text-2xl font-bold">Admin — Invites &amp; Signups</h1>

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
          <CardTitle>Public sign-ups</CardTitle>
          <CardDescription>
            When closed, new accounts can only be created through a valid invite link.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <label className="flex items-center gap-2 text-sm">
            <Checkbox
              checked={publicSignups}
              onCheckedChange={(checked) => void saveSettings(checked === true)}
              disabled={busy}
            />
            Allow public sign-ups
          </label>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="default-invites">Invites granted to each new user</Label>
              <Input
                id="default-invites"
                type="number"
                min={0}
                value={defaultInvites}
                onChange={(e) => setDefaultInvites(e.target.value)}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="default-expiry">New-user invite expiry (days)</Label>
              <Input
                id="default-expiry"
                type="number"
                min={1}
                placeholder="No expiry"
                value={defaultExpiryDays}
                onChange={(e) => setDefaultExpiryDays(e.target.value)}
              />
            </div>
          </div>
          <Button type="button" onClick={() => void saveSettings(publicSignups)} disabled={busy}>
            Save defaults
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Issue invites to everyone</CardTitle>
          <CardDescription>
            Top up every user permitted to receive invites. Unused invites expire after the configured number of days.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="issue-all-quantity">Quantity per user</Label>
              <Input
                id="issue-all-quantity"
                type="number"
                min={1}
                value={issueQuantity}
                onChange={(e) => setIssueQuantity(e.target.value)}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="issue-all-expiry">Expires in (days)</Label>
              <Input
                id="issue-all-expiry"
                type="number"
                min={1}
                placeholder="No expiry"
                value={issueExpiryDays}
                onChange={(e) => setIssueExpiryDays(e.target.value)}
              />
            </div>
          </div>
          <Button type="button" onClick={() => void issueToAll()} disabled={busy || Number(issueQuantity) < 1}>
            Issue to all users
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Invite tree</CardTitle>
          <CardDescription>Who invited whom, rooted at users who joined without an invite.</CardDescription>
        </CardHeader>
        <CardContent>
          {roots.length === 0 ? (
            <p className="text-sm text-muted-foreground">No users yet.</p>
          ) : (
            <ul className="text-sm">
              {roots.map((user) => (
                <TreeNode key={user.id} user={user} childrenByParent={childrenByParent} />
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Recent invites</CardTitle>
          <CardDescription>The 100 most recently generated invite links.</CardDescription>
        </CardHeader>
        <CardContent>
          {data && data.recent_invites.length > 0 ? (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Inviter</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Used by</TableHead>
                  <TableHead>Expires</TableHead>
                  <TableHead>Created</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.recent_invites.map((invite) => (
                  <TableRow key={invite.uuid}>
                    <TableCell>{invite.inviter ?? '—'}</TableCell>
                    <TableCell>
                      <Badge variant={invite.status === 'active' ? 'default' : 'outline'}>{invite.status}</Badge>
                    </TableCell>
                    <TableCell>{invite.invited_user ?? '—'}</TableCell>
                    <TableCell>{formatDate(invite.expires_at)}</TableCell>
                    <TableCell>{formatDate(invite.created_at)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          ) : (
            <p className="text-sm text-muted-foreground">No invites generated yet.</p>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

const mountEl = document.getElementById('admin-invites');
if (mountEl) {
  createRoot(mountEl).render(<AdminInvitesPage />);
}
