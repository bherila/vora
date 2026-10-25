import { Search } from 'lucide-react';
import { type ReactNode, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { formatStorageLimit, StorageLimitDialog } from '@/admin/StorageLimitDialog';
import { todayDate } from '@/auth/age-policy';
import { Avatar } from '@/components/avatar';
import { DatePicker } from '@/components/date-picker';
import { BROWSING_PAGE_WIDTH } from '@/components/page-width';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';
import { instanceProfile, moduleEnabled } from '@/instance';

interface AdminUser {
  id: number;
  name: string;
  display_name: string | null;
  birth_date: string | null;
  email: string;
  is_admin: boolean;
  is_disabled: boolean;
  is_deactivated: boolean;
  is_deleted: boolean;
  is_approved: boolean;
  id_verified: boolean;
  birth_date_verified: boolean;
  email_verified: boolean;
  name_locked: boolean;
  email_locked: boolean;
  id_verified_at: string | null;
  approved_at: string | null;
  last_login_at: string | null;
  created_at: string;
  invite_balance: number;
  can_receive_invites: boolean;
  trusted_inviter: boolean;
  library_enabled: boolean;
  event_uploads_auto_approved: boolean;
  storage_limit_bytes: number | null;
  storage_unlimited: boolean;
  effective_storage_limit_bytes: number | null;
  is_banned: boolean;
  ban_reason: string | null;
  ban_hides_content: boolean;
  ban_appeal_message: string | null;
  ban_appeal_at: string | null;
  is_on_legal_hold: boolean;
  legal_hold_note: string | null;
  referrer_user_id: number | null;
  referrer_display_name: string | null;
  restrictions: AdminRestriction[];
}

type RestrictionCapability = 'media.upload' | 'media.view' | 'comment.create';

interface AdminRestriction {
  id: number;
  capability: RestrictionCapability;
  label: string;
  reason: string | null;
  expires_at: string | null;
  lifted_at: string | null;
  created_at: string | null;
  active: boolean;
}

function getCsrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function patchUser(id: number, body: Record<string, unknown>) {
  const response = await fetch(`/api/admin/users/${id}`, {
    method: 'PATCH',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': getCsrfToken(),
    },
    credentials: 'include',
    body: JSON.stringify(body),
  });
  if (!response.ok) {
    const data = await response.json().catch(() => ({})) as { message?: string };
    throw new Error(data.message ?? 'Request failed');
  }
  return response.json() as Promise<{ success: boolean; data: AdminUser }>;
}

type UserFilter = 'all' | 'pending' | 'restricted' | 'banned' | 'admins' | 'deleted';

const FILTERS: Array<{ value: UserFilter; label: string }> = [
  { value: 'all', label: 'All' },
  { value: 'pending', label: 'Needs approval' },
  { value: 'restricted', label: 'Restricted' },
  { value: 'banned', label: 'Banned' },
  { value: 'admins', label: 'Admins' },
  { value: 'deleted', label: 'Deleted' },
];

const TONES = {
  neutral: 'border-transparent bg-muted text-foreground',
  good: 'border-transparent bg-emerald-500/15 text-emerald-800 dark:text-emerald-300',
  waiting: 'border-transparent bg-amber-500/15 text-amber-800 dark:text-amber-300',
  warning: 'border-transparent bg-orange-500/15 text-orange-800 dark:text-orange-300',
  danger: 'border-transparent bg-destructive/15 text-destructive dark:text-red-300',
  admin: 'border-transparent bg-primary text-primary-foreground',
};

interface StatusPill {
  label: string;
  className: string;
}

function activeRestrictions(user: AdminUser): AdminRestriction[] {
  return user.restrictions.filter((restriction) => restriction.active);
}

function matchesFilter(user: AdminUser, filter: UserFilter): boolean {
  switch (filter) {
    case 'pending':
      return !user.is_deleted && !user.is_approved;
    case 'restricted':
      return !user.is_deleted && activeRestrictions(user).length > 0;
    case 'banned':
      return !user.is_deleted && user.is_banned;
    case 'admins':
      return !user.is_deleted && user.is_admin;
    case 'deleted':
      return user.is_deleted;
    default:
      return true;
  }
}

function matchesQuery(user: AdminUser, query: string): boolean {
  const needle = query.trim().toLowerCase();
  if (needle === '') return true;

  return [user.name, user.display_name ?? '', user.email].some((value) => value.toLowerCase().includes(needle));
}

function displayName(user: AdminUser): string {
  return user.display_name ?? user.name;
}

/** "Real name · email", dropping the real name when it repeats the display name. */
function identityLine(user: AdminUser): string {
  return user.display_name && user.display_name !== user.name ? `${user.name} · ${user.email}` : user.email;
}

/** The one state an admin should read first; {@link userFlags} carries the rest. */
function primaryStatus(user: AdminUser): StatusPill {
  if (user.is_deleted) return { label: 'Deleted', className: TONES.danger };
  if (user.is_banned) return { label: user.ban_hides_content ? 'Banned (hidden)' : 'Banned', className: TONES.danger };
  if (user.is_disabled) return { label: 'Disabled', className: TONES.danger };
  if (!user.is_approved) return { label: 'Pending', className: TONES.waiting };
  if (user.is_deactivated) return { label: 'Deactivated', className: TONES.neutral };
  if (activeRestrictions(user).length > 0) return { label: 'Restricted', className: TONES.warning };
  if (user.is_admin) return { label: 'Admin', className: TONES.admin };
  return { label: 'Active', className: TONES.good };
}

function storageLabel(user: AdminUser): string {
  if (user.storage_unlimited) return 'Unlimited override';
  if (user.storage_limit_bytes === null) return `Site default (${formatStorageLimit(user.effective_storage_limit_bytes)})`;
  return `Custom (${formatStorageLimit(user.storage_limit_bytes)})`;
}

/**
 * Departures from the defaults only. Site-default storage, a zero invite
 * balance and a verified email are what most accounts look like, so they stay
 * in the detail panel instead of earning a badge on every row.
 */
function userFlags(user: AdminUser, eventProfile: boolean): string[] {
  const flags: string[] = [];
  if (user.is_admin && primaryStatus(user).label !== 'Admin') flags.push('Admin');
  if (!user.email_verified) flags.push('Email unverified');
  for (const restriction of activeRestrictions(user)) flags.push(`Restricted: ${restriction.label}`);
  if (user.is_banned && user.ban_appeal_at) flags.push('Appeal pending');
  if (user.is_on_legal_hold) flags.push('Legal hold');
  if (user.id_verified) flags.push('ID/Age verified');
  if (moduleEnabled('events') && user.event_uploads_auto_approved) flags.push('Event uploads auto-approved');
  if (user.name_locked) flags.push('Real name locked');
  if (user.email_locked) flags.push('Email locked');
  if (user.trusted_inviter) flags.push('Trusted inviter');
  if (!user.can_receive_invites) flags.push('No invites');
  if (user.storage_unlimited || user.storage_limit_bytes !== null) flags.push(`Storage: ${storageLabel(user)}`);
  if (!eventProfile && moduleEnabled('library') && user.library_enabled) flags.push('Library enabled');

  return flags;
}

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleDateString() : '—';
}

const ROW_FLAG_LIMIT = 2;

function ActionGroup({ title, children, danger = false }: { title: string; children: ReactNode; danger?: boolean }) {
  return (
    <section className={danger ? 'space-y-2 rounded-lg border border-destructive/40 p-3' : 'space-y-2'}>
      <h3 className={`text-xs font-semibold uppercase tracking-wide ${danger ? 'text-destructive' : 'text-muted-foreground'}`}>{title}</h3>
      <div className="flex flex-wrap gap-2">{children}</div>
    </section>
  );
}

function Callout({ children }: { children: ReactNode }) {
  return <div className="space-y-2 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm">{children}</div>;
}

export function AdminUsersPage() {
  const eventProfile = instanceProfile() === 'event';
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [deleteTarget, setDeleteTarget] = useState<AdminUser | null>(null);
  const [storageTarget, setStorageTarget] = useState<AdminUser | null>(null);
  const [defaultStorageLimit, setDefaultStorageLimit] = useState<number | null>(null);
  const [storageSettingsLoaded, setStorageSettingsLoaded] = useState(false);
  const [defaultStorageOpen, setDefaultStorageOpen] = useState(false);
  const [birthDateTarget, setBirthDateTarget] = useState<AdminUser | null>(null);
  const [birthDateValue, setBirthDateValue] = useState('');
  const [actionLoading, setActionLoading] = useState<number | null>(null);
  const latestBirthDate = todayDate();

  // Ban dialog.
  const [banTarget, setBanTarget] = useState<AdminUser | null>(null);
  const [banReason, setBanReason] = useState('');
  const [banHidesContent, setBanHidesContent] = useState(false);
  // Legal hold dialog.
  const [legalHoldTarget, setLegalHoldTarget] = useState<AdminUser | null>(null);
  const [legalHoldNote, setLegalHoldNote] = useState('');
  // Issue invites dialog.
  const [issueTarget, setIssueTarget] = useState<AdminUser | null>(null);
  const [issueQuantity, setIssueQuantity] = useState('1');
  const [issueExpiryDays, setIssueExpiryDays] = useState('');
  // Capability restriction dialog.
  const [restrictionTarget, setRestrictionTarget] = useState<AdminUser | null>(null);
  const [restrictionCapability, setRestrictionCapability] = useState<RestrictionCapability>('media.upload');
  const [restrictionReason, setRestrictionReason] = useState('');
  const [restrictionExpiry, setRestrictionExpiry] = useState('');
  // List filtering and the detail panel's selection.
  const [filter, setFilter] = useState<UserFilter>('all');
  const [query, setQuery] = useState('');
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const detailRef = useRef<HTMLElement | null>(null);

  const loadUsers = async (): Promise<boolean> => {
    setLoading(true);
    setError('');
    try {
      const response = await fetchWrapper.get('/api/admin/users') as {
        success: boolean; data: AdminUser[]; settings: { default_storage_limit_bytes: number | null };
      };
      if (!response.success || !response.settings) throw new Error('Failed to load users and storage settings.');
      setUsers(response.data);
      setDefaultStorageLimit(response.settings.default_storage_limit_bytes);
      setStorageSettingsLoaded(true);
      return true;
    } catch (err) {
      setError(err instanceof Error ? err.message : typeof err === 'string' ? err : 'Failed to load users.');
      return false;
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadUsers();
  }, []);

  const approveUser = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await fetchWrapper.post(`/api/admin/users/${user.id}/approve`, {});
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to approve user.');
    } finally {
      setActionLoading(null);
    }
  };

  const toggleAdmin = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await patchUser(user.id, { is_admin: !user.is_admin });
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update user.');
    } finally {
      setActionLoading(null);
    }
  };

  const toggleDisabled = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await patchUser(user.id, { is_disabled: !user.is_disabled });
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update user.');
    } finally {
      setActionLoading(null);
    }
  };

  const toggleNameLock = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await patchUser(user.id, { name_locked: !user.name_locked });
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update user.');
    } finally {
      setActionLoading(null);
    }
  };

  const toggleLibrary = async (user: AdminUser): Promise<void> => {
    setActionLoading(user.id);
    try {
      await patchUser(user.id, { library_enabled: !user.library_enabled });
      await loadUsers();
    } catch (error) {
      setError(typeof error === 'string' ? error : 'Failed to update Library access.');
    } finally {
      setActionLoading(null);
    }
  };

  const toggleEventUploadApproval = async (user: AdminUser): Promise<void> => {
    setActionLoading(user.id);
    setError('');
    try {
      await patchUser(user.id, { event_uploads_auto_approved: !user.event_uploads_auto_approved });
      await loadUsers();
    } catch (error) {
      setError(error instanceof Error ? error.message : typeof error === 'string' ? error : 'Failed to update Event upload review.');
    } finally {
      setActionLoading(null);
    }
  };

  const toggleEmailLock = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await patchUser(user.id, { email_locked: !user.email_locked });
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update user.');
    } finally {
      setActionLoading(null);
    }
  };

  const toggleIdVerification = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await patchUser(user.id, { id_verified: !user.id_verified });
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update user.');
    } finally {
      setActionLoading(null);
    }
  };

  const beginBirthDateEdit = (user: AdminUser) => {
    setBirthDateTarget(user);
    setBirthDateValue(user.birth_date ?? '');
  };

  const saveBirthDate = async () => {
    if (!birthDateTarget || !birthDateValue) {
      return;
    }

    setActionLoading(birthDateTarget.id);
    try {
      await patchUser(birthDateTarget.id, { birth_date: birthDateValue });
      setBirthDateTarget(null);
      setBirthDateValue('');
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update birth date.');
    } finally {
      setActionLoading(null);
    }
  };

  const deleteUser = async (user: AdminUser) => {
    setActionLoading(user.id);
    setDeleteTarget(null);
    try {
      await fetchWrapper.delete(`/api/admin/users/${user.id}`, {});
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to delete user.');
    } finally {
      setActionLoading(null);
    }
  };

  const restoreUser = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await fetchWrapper.post(`/api/admin/users/${user.id}/restore`, {});
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to restore user.');
    } finally {
      setActionLoading(null);
    }
  };

  const toggleTrustedInviter = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await patchUser(user.id, { trusted_inviter: !user.trusted_inviter });
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update user.');
    } finally {
      setActionLoading(null);
    }
  };

  const toggleCanReceiveInvites = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await patchUser(user.id, { can_receive_invites: !user.can_receive_invites });
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update user.');
    } finally {
      setActionLoading(null);
    }
  };

  const beginBan = (user: AdminUser) => {
    setBanTarget(user);
    setBanReason(user.ban_reason ?? '');
    setBanHidesContent(user.ban_hides_content);
  };

  const submitBan = async () => {
    if (!banTarget) {
      return;
    }
    setActionLoading(banTarget.id);
    try {
      await fetchWrapper.post(`/api/admin/users/${banTarget.id}/ban`, {
        reason: banReason.trim() || null,
        hide_content: banHidesContent,
      });
      setBanTarget(null);
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to ban user.');
    } finally {
      setActionLoading(null);
    }
  };

  const unbanUser = async (user: AdminUser) => {
    setActionLoading(user.id);
    try {
      await fetchWrapper.post(`/api/admin/users/${user.id}/unban`, {});
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to unban user.');
    } finally {
      setActionLoading(null);
    }
  };

  const beginLegalHold = (user: AdminUser) => {
    setLegalHoldTarget(user);
    setLegalHoldNote(user.legal_hold_note ?? '');
  };

  const setLegalHold = async (user: AdminUser, onHold: boolean) => {
    setActionLoading(user.id);
    try {
      await fetchWrapper.post(`/api/admin/users/${user.id}/legal-hold`, {
        on_hold: onHold,
        note: onHold ? (legalHoldNote.trim() || null) : null,
      });
      setLegalHoldTarget(null);
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to update legal hold.');
    } finally {
      setActionLoading(null);
    }
  };

  const beginIssue = (user: AdminUser) => {
    setIssueTarget(user);
    setIssueQuantity('1');
    setIssueExpiryDays('');
  };

  const submitIssue = async () => {
    if (!issueTarget) {
      return;
    }
    setActionLoading(issueTarget.id);
    try {
      await fetchWrapper.post(`/api/admin/users/${issueTarget.id}/invites`, {
        quantity: Number(issueQuantity),
        expires_in_days: issueExpiryDays.trim() === '' ? null : Number(issueExpiryDays),
      });
      setIssueTarget(null);
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to issue invites.');
    } finally {
      setActionLoading(null);
    }
  };

  const beginRestriction = (user: AdminUser) => {
    setRestrictionTarget(user);
    setRestrictionCapability('media.upload');
    setRestrictionReason('');
    setRestrictionExpiry('');
  };

  const submitRestriction = async () => {
    if (!restrictionTarget) return;

    setActionLoading(restrictionTarget.id);
    try {
      await fetchWrapper.post(`/api/admin/users/${restrictionTarget.id}/restrictions`, {
        capability: restrictionCapability,
        reason: restrictionReason.trim() || null,
        expires_at: restrictionExpiry || null,
      });
      setRestrictionTarget(null);
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to restrict user.');
    } finally {
      setActionLoading(null);
    }
  };

  const liftRestriction = async (user: AdminUser, restriction: AdminRestriction) => {
    setActionLoading(user.id);
    try {
      await fetchWrapper.delete(`/api/admin/users/${user.id}/restrictions/${restriction.id}`, {});
      await loadUsers();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to lift restriction.');
    } finally {
      setActionLoading(null);
    }
  };

  const counts = Object.fromEntries(
    FILTERS.map((item) => [item.value, users.filter((user) => matchesFilter(user, item.value)).length]),
  ) as Record<UserFilter, number>;
  const visibleUsers = users.filter((user) => matchesFilter(user, filter) && matchesQuery(user, query));
  const selected = visibleUsers.find((user) => user.id === selectedId) ?? visibleUsers[0] ?? null;

  const selectUser = (id: number): void => {
    setSelectedId(id);
    // On narrow screens the panel stacks under the list; bring it into view.
    if (window.matchMedia?.('(max-width: 1023px)').matches) {
      detailRef.current?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
    }
  };

  const renderDetail = (user: AdminUser) => {
    const status = primaryStatus(user);
    const restrictions = activeRestrictions(user);
    const busy = actionLoading === user.id;

    return (
      <>
        <div className="space-y-3 border-b p-4">
          <div className="flex items-center gap-3">
            <Avatar name={displayName(user)} src={null} sizeClassName="h-12 w-12" />
            <div className="min-w-0 flex-1">
              <h2 className="truncate text-lg font-semibold">{displayName(user)}</h2>
              <p className="truncate text-sm text-muted-foreground">{identityLine(user)}</p>
            </div>
            {!user.is_deleted && moduleEnabled('profiles') && (
              <a className="shrink-0 text-sm text-muted-foreground underline-offset-4 hover:underline" href={`/users/${user.id}`}>Profile</a>
            )}
          </div>
          <div className="flex flex-wrap gap-1.5">
            <Badge className={status.className}>{status.label}</Badge>
            {userFlags(user, eventProfile).map((flag) => <Badge key={flag} variant="outline">{flag}</Badge>)}
          </div>

          {user.is_deleted && (
            <Callout>
              <p>This account is deleted. Restore it, or purge it permanently below.</p>
              <Button size="sm" disabled={busy} onClick={() => void restoreUser(user)} data-test="admin-users-restore">Restore</Button>
            </Callout>
          )}
          {!user.is_deleted && !user.is_approved && (
            <Callout>
              <p>
                {user.email_verified
                  ? `Waiting for approval since ${formatDate(user.created_at)}.`
                  : 'Waiting for approval. They have to verify their email before you can approve them.'}
                {user.referrer_display_name && ` Invited by ${user.referrer_display_name}.`}
              </p>
              <Button size="sm" disabled={busy || !user.email_verified} onClick={() => void approveUser(user)}>Approve</Button>
            </Callout>
          )}
          {!user.is_deleted && user.is_banned && (
            <Callout>
              <p><span className="font-medium">Ban reason:</span> {user.ban_reason ?? 'No reason given.'}</p>
              {user.ban_appeal_at && <p><span className="font-medium">Appeal:</span> {user.ban_appeal_message}</p>}
            </Callout>
          )}
          {!user.is_deleted && restrictions.map((restriction) => (
            <Callout key={restriction.id}>
              <p>
                <span className="font-medium">{restriction.label}:</span>{' '}
                {restriction.reason ?? 'No reason provided.'}
                {restriction.expires_at ? ` Expires ${new Date(restriction.expires_at).toLocaleString()}.` : ' No expiry.'}
              </p>
              <Button size="sm" variant="outline" disabled={busy} onClick={() => void liftRestriction(user, restriction)}>
                Lift {restriction.label}
              </Button>
            </Callout>
          ))}
        </div>

        <dl className="grid grid-cols-[7.5rem_minmax(0,1fr)] gap-x-3 gap-y-2 border-b p-4 text-sm">
          <dt className="text-muted-foreground">Birth date</dt>
          <dd title={user.id_verified_at ? `Verified ${new Date(user.id_verified_at).toLocaleString()}` : undefined}>
            {user.birth_date ? `${user.birth_date} · ${user.birth_date_verified ? 'verified' : 'unverified'}` : '—'}
          </dd>
          <dt className="text-muted-foreground">Joined</dt>
          <dd>{formatDate(user.created_at)}</dd>
          <dt className="text-muted-foreground">Last login</dt>
          <dd>{user.last_login_at ? formatDate(user.last_login_at) : 'Never'}</dd>
          <dt className="text-muted-foreground">Invited by</dt>
          <dd>{user.referrer_display_name ?? '—'}</dd>
          <dt className="text-muted-foreground">Storage</dt>
          <dd>{storageLabel(user)}</dd>
          {moduleEnabled('library') && (
            <>
              <dt className="text-muted-foreground">Library</dt>
              <dd>{eventProfile ? 'Library available to all attendees' : user.library_enabled ? 'Enabled' : 'Off'}</dd>
            </>
          )}
          <dt className="text-muted-foreground">Invites left</dt>
          <dd>{user.invite_balance}</dd>
        </dl>

        <div className="space-y-4 p-4">
          {/* Normal controls 404 for soft-deleted users (routes aren't trashed-aware); only Restore/Purge apply. */}
          {!user.is_deleted && (
            <>
              <ActionGroup title="Account">
                <Button size="sm" variant="outline" disabled={busy} onClick={() => beginRestriction(user)} data-test="admin-users-restrict">Restrict…</Button>
                <Button size="sm" variant="outline" disabled={busy} onClick={() => void toggleDisabled(user)} data-test="admin-users-toggle-disabled">
                  {user.is_disabled ? 'Enable' : 'Disable'}
                </Button>
                <Button size="sm" variant="outline" disabled={busy} onClick={() => void toggleAdmin(user)} data-test="admin-users-toggle-admin">
                  {user.is_admin ? 'Remove admin' : 'Make admin'}
                </Button>
              </ActionGroup>

              <ActionGroup title="Identity & age">
                <Button size="sm" variant="outline" disabled={busy} onClick={() => void toggleIdVerification(user)} data-test="admin-users-toggle-id-verified">
                  {user.id_verified ? 'Clear ID/age verification' : 'Verify ID/age'}
                </Button>
                <Button size="sm" variant="outline" disabled={busy} onClick={() => beginBirthDateEdit(user)} data-test="admin-users-edit-birth-date">Edit birth date</Button>
                <Button size="sm" variant="outline" disabled={busy} onClick={() => void toggleNameLock(user)} data-test="admin-users-toggle-name-lock">
                  {user.name_locked ? 'Unlock real name' : 'Lock real name'}
                </Button>
                <Button size="sm" variant="outline" disabled={busy} onClick={() => void toggleEmailLock(user)} data-test="admin-users-toggle-email-lock">
                  {user.email_locked ? 'Unlock email' : 'Lock email'}
                </Button>
              </ActionGroup>

              <ActionGroup title="Storage">
                <Button size="sm" variant="outline" disabled={busy} onClick={() => setStorageTarget(user)}>Set storage limit</Button>
                {!eventProfile && moduleEnabled('library') && (
                  <Button size="sm" variant="outline" disabled={busy} onClick={() => void toggleLibrary(user)}>
                    {user.library_enabled ? 'Disable Library storage' : 'Enable Library storage'}
                  </Button>
                )}
              </ActionGroup>

              {moduleEnabled('events') && (
                <ActionGroup title="Event uploads">
                  <Button size="sm" variant="outline" disabled={busy} onClick={() => void toggleEventUploadApproval(user)}>
                    {user.event_uploads_auto_approved ? 'Require Event upload review' : 'Skip Event upload review'}
                  </Button>
                  <p className="basis-full text-xs text-muted-foreground">
                    Exemptions apply when future uploads finish. Other media still needs review. Removing an exemption keeps already approved items approved.
                  </p>
                </ActionGroup>
              )}

              <ActionGroup title="Invites">
                <Button size="sm" variant="outline" disabled={busy} onClick={() => beginIssue(user)} data-test="admin-users-issue-invites">Issue invites</Button>
                <Button size="sm" variant="outline" disabled={busy} onClick={() => void toggleTrustedInviter(user)} data-test="admin-users-toggle-trusted">
                  {user.trusted_inviter ? 'Untrust inviter' : 'Trust inviter'}
                </Button>
                <Button size="sm" variant="outline" disabled={busy} onClick={() => void toggleCanReceiveInvites(user)} data-test="admin-users-toggle-can-receive">
                  {user.can_receive_invites ? 'Block invites' : 'Allow invites'}
                </Button>
              </ActionGroup>
            </>
          )}

          <ActionGroup title="Danger zone" danger>
            {!user.is_deleted && (
              <>
                <Button
                  size="sm"
                  variant={user.is_banned ? 'outline' : 'destructive'}
                  disabled={busy}
                  onClick={() => (user.is_banned ? void unbanUser(user) : beginBan(user))}
                  data-test="admin-users-ban"
                >
                  {user.is_banned ? 'Unban' : 'Ban…'}
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={busy}
                  onClick={() => (user.is_on_legal_hold ? void setLegalHold(user, false) : beginLegalHold(user))}
                  data-test="admin-users-legal-hold"
                >
                  {user.is_on_legal_hold ? 'Lift legal hold' : 'Legal hold…'}
                </Button>
              </>
            )}
            <Button size="sm" variant="destructive" disabled={busy} onClick={() => setDeleteTarget(user)} data-test="admin-users-delete">
              {user.is_deleted ? 'Purge…' : 'Delete…'}
            </Button>
          </ActionGroup>
        </div>
      </>
    );
  };

  return (
    <div className={`${BROWSING_PAGE_WIDTH} px-4 py-8`}>
      <header className="mb-6 flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <h1 className="text-2xl font-bold">Users</h1>
        <section aria-labelledby="default-storage-heading" className="space-y-1 text-sm sm:text-right">
          <h2 id="default-storage-heading" className="sr-only">Default account storage</h2>
          <p className="flex flex-wrap items-center gap-x-2 sm:justify-end">
            <span>{storageSettingsLoaded ? `Site default storage: ${formatStorageLimit(defaultStorageLimit)}` : 'Site default storage unavailable until settings are loaded.'}</span>
            <Button variant="link" size="sm" className="h-auto px-0" disabled={loading || !storageSettingsLoaded} onClick={() => setDefaultStorageOpen(true)}>Change site default</Button>
          </p>
          <p className="max-w-md text-xs text-muted-foreground">
            Applies to accounts without their own limit, including new ones. {eventProfile ? 'Library access is available to all attendees.' : 'Library access is enabled separately.'}
          </p>
        </section>
      </header>

      {error && (
        <div role="alert" className="mb-4 rounded border border-destructive bg-destructive/10 p-3 text-sm text-destructive">
          {error}
        </div>
      )}

      {loading && users.length === 0 ? (
        <p className="text-muted-foreground">Loading users...</p>
      ) : (
        <>
          <div className="mb-4 flex flex-wrap items-center gap-3">
            <div role="group" aria-label="Filter users" className="flex flex-wrap gap-1 rounded-lg border bg-muted/40 p-1">
              {FILTERS.filter((item) => item.value !== 'deleted' || counts.deleted > 0).map((item) => {
                const active = filter === item.value;
                const highlight = item.value === 'pending' && counts.pending > 0;

                return (
                  <button
                    key={item.value}
                    type="button"
                    aria-pressed={active}
                    onClick={() => setFilter(item.value)}
                    className={`inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-sm ${active ? 'bg-background font-semibold shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}
                  >
                    {item.label}
                    <span className={highlight ? 'rounded-full bg-amber-500/20 px-1.5 text-xs font-semibold text-amber-800 dark:text-amber-300' : 'text-xs text-muted-foreground'}>
                      {counts[item.value]}
                    </span>
                  </button>
                );
              })}
            </div>
            <div className="relative min-w-56 flex-1">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
              <Input
                type="search"
                aria-label="Search users"
                placeholder="Search name, display name or email"
                className="pl-9"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
              />
            </div>
          </div>

          <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_26rem]">
            <div className="min-w-0 overflow-x-auto rounded-xl border">
              <table className="w-full text-sm">
                <caption className="sr-only">Users</caption>
                <thead className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                  <tr>
                    <th scope="col" className="px-4 py-2.5 font-medium">User</th>
                    <th scope="col" className="px-4 py-2.5 font-medium">Status</th>
                    <th scope="col" className="hidden px-4 py-2.5 font-medium sm:table-cell">Joined</th>
                    <th scope="col" className="px-4 py-2.5"><span className="sr-only">Quick actions</span></th>
                  </tr>
                </thead>
                <tbody>
                  {visibleUsers.length === 0 && (
                    <tr>
                      <td colSpan={4} className="px-4 py-8 text-center text-muted-foreground">No users match.</td>
                    </tr>
                  )}
                  {visibleUsers.map((user) => {
                    const status = primaryStatus(user);
                    const flags = userFlags(user, eventProfile);
                    const isSelected = selected?.id === user.id;

                    return (
                      <tr
                        key={user.id}
                        id={`user-${user.id}`}
                        data-selected={isSelected || undefined}
                        onClick={() => selectUser(user.id)}
                        className={`cursor-pointer border-b last:border-b-0 ${isSelected ? 'bg-muted/60 shadow-[inset_3px_0_0_hsl(var(--primary))]' : 'hover:bg-muted/30'}`}
                      >
                        <td className="max-w-0 px-4 py-3">
                          <div className="flex min-w-0 items-center gap-3">
                            <Avatar name={displayName(user)} src={null} sizeClassName="h-9 w-9" />
                            <div className="min-w-0">
                              <button
                                type="button"
                                aria-pressed={isSelected}
                                className="block max-w-full truncate text-left font-medium hover:underline"
                                onClick={(event) => {
                                  event.stopPropagation();
                                  selectUser(user.id);
                                }}
                              >
                                {displayName(user)}
                              </button>
                              <p className="truncate text-xs text-muted-foreground">{identityLine(user)}</p>
                            </div>
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="flex flex-wrap items-center gap-1">
                            <Badge className={status.className}>{status.label}</Badge>
                            {flags.slice(0, ROW_FLAG_LIMIT).map((flag) => <Badge key={flag} variant="outline">{flag}</Badge>)}
                            {flags.length > ROW_FLAG_LIMIT && (
                              <span className="text-xs text-muted-foreground">+{flags.length - ROW_FLAG_LIMIT} more</span>
                            )}
                          </div>
                        </td>
                        <td className="hidden whitespace-nowrap px-4 py-3 text-muted-foreground sm:table-cell">{formatDate(user.created_at)}</td>
                        <td className="px-4 py-3 text-right">
                          {!user.is_deleted && !user.is_approved && (
                            <Button
                              size="sm"
                              disabled={actionLoading === user.id || !user.email_verified}
                              title={!user.email_verified ? 'User must verify their email before approval.' : 'Approve user'}
                              onClick={(event) => {
                                event.stopPropagation();
                                void approveUser(user);
                              }}
                              data-test="admin-users-approve"
                            >
                              Approve
                            </Button>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            <aside ref={detailRef} aria-label="User details" className="min-w-0 rounded-xl border lg:sticky lg:top-4">
              {selected ? renderDetail(selected) : <p className="p-4 text-sm text-muted-foreground">Select a user to see details and actions.</p>}
            </aside>
          </div>
        </>
      )}

      {storageTarget && <StorageLimitDialog
        key={storageTarget.id}
        userName={storageTarget.display_name ?? storageTarget.name}
        limitBytes={storageTarget.storage_limit_bytes}
        unlimitedOverride={storageTarget.storage_unlimited}
        defaultLimitBytes={defaultStorageLimit}
        onClose={() => setStorageTarget(null)}
        onSave={async (value) => {
          const response = await patchUser(storageTarget.id, { ...value });
          setUsers((current) => current.map((user) => user.id === storageTarget.id ? response.data : user));
          if (!await loadUsers()) throw new Error('The account limit was saved, but users could not be refreshed. Try again to refresh the list.');
        }}
      />}
      {defaultStorageOpen && <StorageLimitDialog
        userName="Site default"
        siteDefault
        limitBytes={defaultStorageLimit}
        unlimitedOverride={defaultStorageLimit === null}
        defaultLimitBytes={defaultStorageLimit}
        onClose={() => setDefaultStorageOpen(false)}
        onSave={async (value) => {
          const response = await fetchWrapper.put('/api/admin/storage-settings', {
            default_storage_limit_bytes: value.storage_limit_bytes,
          }) as { success: boolean; data: { default_storage_limit_bytes: number | null } };
          const updated = response.data.default_storage_limit_bytes;
          setDefaultStorageLimit(updated);
          setUsers((current) => current.map((user) => !user.storage_unlimited && user.storage_limit_bytes === null
            ? { ...user, effective_storage_limit_bytes: updated } : user));
          if (!await loadUsers()) throw new Error('The site default was saved, but users could not be refreshed. Try again to refresh the list.');
        }}
      />}
      <Dialog open={deleteTarget !== null} onOpenChange={(open) => { if (!open) setDeleteTarget(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Permanently delete user</DialogTitle>
            <DialogDescription>
              Permanently delete <strong>{deleteTarget?.name}</strong> ({deleteTarget?.email}), including all of their
              media, characters, and uploaded files? This cannot be undone. To temporarily remove an account instead,
              ask the user to deactivate it, or use Disable.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleteTarget(null)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => { if (deleteTarget) void deleteUser(deleteTarget); }}
              data-test="admin-users-delete-confirm"
            >
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={birthDateTarget !== null} onOpenChange={(open) => {
        if (!open) {
          setBirthDateTarget(null);
          setBirthDateValue('');
        }
      }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit Birth Date</DialogTitle>
            <DialogDescription>
              Update the date of birth for <strong>{birthDateTarget?.name}</strong>. Record the correct calendar date. Changing it clears prior age verification and may restrict access under the current age policy.
            </DialogDescription>
          </DialogHeader>
          <DatePicker
            id="admin-user-birth-date"
            value={birthDateValue}
            max={latestBirthDate}
            onChange={setBirthDateValue}
            placeholder="Select birth date"
          />
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                setBirthDateTarget(null);
                setBirthDateValue('');
              }}
            >
              Cancel
            </Button>
            <Button
              onClick={() => void saveBirthDate()}
              disabled={!birthDateValue || actionLoading === birthDateTarget?.id}
              data-test="admin-users-birth-date-save"
            >
              Save
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={banTarget !== null} onOpenChange={(open) => { if (!open) setBanTarget(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Ban user</DialogTitle>
            <DialogDescription>
              Ban <strong>{banTarget?.display_name ?? banTarget?.name}</strong>. They can still log in but can only
              appeal, deactivate, or delete their account.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="ban-reason">Reason (shown to the user)</Label>
              <Textarea id="ban-reason" rows={4} value={banReason} onChange={(e) => setBanReason(e.target.value)} />
            </div>
            <label className="flex items-center gap-2 text-sm">
              <Checkbox checked={banHidesContent} onCheckedChange={(checked) => setBanHidesContent(checked === true)} />
              Also hide this user's content from others (uncheck to memorialize)
            </label>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setBanTarget(null)}>Cancel</Button>
            <Button
              variant="destructive"
              onClick={() => void submitBan()}
              disabled={actionLoading === banTarget?.id}
              data-test="admin-users-ban-confirm"
            >
              Ban
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={legalHoldTarget !== null} onOpenChange={(open) => { if (!open) setLegalHoldTarget(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Place legal hold</DialogTitle>
            <DialogDescription>
              Prevent <strong>{legalHoldTarget?.display_name ?? legalHoldTarget?.name}</strong> from deleting their
              account. This is admin-only and not shown to the user unless they try to delete.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="legal-hold-note">Internal note (optional)</Label>
            <Textarea
              id="legal-hold-note"
              rows={3}
              value={legalHoldNote}
              onChange={(e) => setLegalHoldNote(e.target.value)}
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setLegalHoldTarget(null)}>Cancel</Button>
            <Button
              onClick={() => { if (legalHoldTarget) void setLegalHold(legalHoldTarget, true); }}
              disabled={actionLoading === legalHoldTarget?.id}
              data-test="admin-users-legal-hold-confirm"
            >
              Place hold
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={issueTarget !== null} onOpenChange={(open) => { if (!open) setIssueTarget(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Issue invites</DialogTitle>
            <DialogDescription>
              Add invites to <strong>{issueTarget?.display_name ?? issueTarget?.name}</strong>'s balance. Unused invites
              expire after the number of days you set (leave blank for no expiry).
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="issue-quantity">Quantity</Label>
              <Input
                id="issue-quantity"
                type="number"
                min={1}
                value={issueQuantity}
                onChange={(e) => setIssueQuantity(e.target.value)}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="issue-expiry">Expires in (days)</Label>
              <Input
                id="issue-expiry"
                type="number"
                min={1}
                placeholder="No expiry"
                value={issueExpiryDays}
                onChange={(e) => setIssueExpiryDays(e.target.value)}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIssueTarget(null)}>Cancel</Button>
            <Button
              onClick={() => void submitIssue()}
              disabled={actionLoading === issueTarget?.id || Number(issueQuantity) < 1}
              data-test="admin-users-issue-confirm"
            >
              Issue
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={restrictionTarget !== null} onOpenChange={(open) => { if (!open) setRestrictionTarget(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Restrict capability</DialogTitle>
            <DialogDescription>
              Restrict a single capability for <strong>{restrictionTarget?.display_name ?? restrictionTarget?.name}</strong>. The reason and expiry are shown to the user.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="restriction-capability">Capability</Label>
              <select
                id="restriction-capability"
                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                value={restrictionCapability}
                onChange={(event) => setRestrictionCapability(event.target.value as RestrictionCapability)}
              >
                <option value="media.upload">Media uploads</option>
                <option value="media.view">Media viewing</option>
                <option value="comment.create">Commenting</option>
              </select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="restriction-reason">Reason (shown to the user)</Label>
              <Textarea id="restriction-reason" rows={4} value={restrictionReason} onChange={(event) => setRestrictionReason(event.target.value)} />
            </div>
            <div className="space-y-2">
              <Label htmlFor="restriction-expiry">Expires at (optional)</Label>
              <Input id="restriction-expiry" type="datetime-local" value={restrictionExpiry} onChange={(event) => setRestrictionExpiry(event.target.value)} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setRestrictionTarget(null)}>Cancel</Button>
            <Button
              variant="destructive"
              onClick={() => void submitRestriction()}
              disabled={actionLoading === restrictionTarget?.id}
              data-test="admin-users-restrict-confirm"
            >
              Apply restriction
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

const mountEl = document.getElementById('admin-users');
if (mountEl) {
  createRoot(mountEl).render(<AdminUsersPage />);
}
