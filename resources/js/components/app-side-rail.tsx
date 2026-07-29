import { Clock3, UserRoundPlus } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

import { Avatar } from '@/components/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { fetchWrapper } from '@/fetchWrapper';
import { switchActiveIdentity, useIdentityStore } from '@/identity';

interface PendingAction {
  label: string;
  count: number;
  href: string;
}

interface SuggestedPerson {
  id: number;
  display_name: string;
  avatar_url: string | null;
  href: string;
  interest_match_score: number;
  matching_interests_count: number;
}

interface RecentProfile {
  type: 'user' | 'character';
  id: number;
  display_name: string;
  avatar_url: string | null;
  href: string;
}

interface SideRailPayload {
  pending_actions: PendingAction[];
  suggested_people: SuggestedPerson[];
  recently_visited: RecentProfile[];
}

interface SideRailResponse {
  data?: Partial<SideRailPayload>;
}

function PersonLink({
  name,
  avatarUrl,
  href,
  detail,
}: {
  name: string;
  avatarUrl: string | null;
  href: string;
  detail?: string | undefined;
}) {
  return (
    <a href={href} className="flex min-w-0 items-center gap-2 rounded-md p-1.5 hover:bg-muted">
      <Avatar name={name} src={avatarUrl} sizeClassName="h-8 w-8" />
      <span className="min-w-0">
        <span className="block truncate text-sm font-medium">{name}</span>
        {detail && <span className="block text-xs text-muted-foreground">{detail}</span>}
      </span>
    </a>
  );
}

export function AppSideRail() {
  const { identities, activeIdentityId } = useIdentityStore();
  const [payload, setPayload] = useState<SideRailPayload | null>(null);
  const [switchError, setSwitchError] = useState('');

  useEffect(() => {
    let active = true;
    fetchWrapper
      .get('/api/side-rail')
      .then((response) => {
        if (!active) return;
        const data = (response as SideRailResponse).data;
        setPayload({
          pending_actions: data?.pending_actions ?? [],
          suggested_people: data?.suggested_people ?? [],
          recently_visited: data?.recently_visited ?? [],
        });
      })
      .catch(() => {
        if (active) setPayload(null);
      });

    return () => {
      active = false;
    };
  }, []);

  const clearHistory = async (): Promise<void> => {
    await fetchWrapper.delete('/api/side-rail/history');
    setPayload((current) => (current ? { ...current, recently_visited: [] } : current));
  };

  const chooseIdentity = async (identityId: number | null): Promise<void> => {
    setSwitchError('');
    try {
      await switchActiveIdentity(identityId);
    } catch {
      setSwitchError('Unable to switch identity.');
    }
  };

  return (
    <aside aria-label="Account overview" className="space-y-4">
      <h2 className="text-lg font-semibold">Account overview</h2>

      {identities.length > 0 && (
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm">Creating as</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex flex-wrap gap-2">
              {identities.map((identity) => (
                <button
                  key={identity.id ?? 'human'}
                  type="button"
                  aria-pressed={identity.id === activeIdentityId}
                  onClick={() => void chooseIdentity(identity.id)}
                  className={`flex min-w-0 items-center gap-2 rounded-full border px-2 py-1 text-sm ${
                    identity.id === activeIdentityId
                      ? 'border-foreground bg-muted'
                      : 'border-border hover:bg-muted'
                  }`}
                >
                  <Avatar
                    name={identity.displayName}
                    src={identity.avatarUrl}
                    sizeClassName="h-7 w-7"
                  />
                  <span className="max-w-28 truncate">{identity.displayName}</span>
                </button>
              ))}
            </div>
            <p className="text-xs text-muted-foreground">
              Switching changes who you create as — never what you can see.
            </p>
            {switchError && <p className="text-xs text-destructive">{switchError}</p>}
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-sm">Pending actions</CardTitle>
        </CardHeader>
        <CardContent className="space-y-1">
          {(payload?.pending_actions ?? []).map((action) => (
            <a
              key={action.label}
              href={action.href}
              className="flex items-center justify-between rounded-md px-1 py-1.5 text-sm hover:bg-muted"
            >
              <span>{action.label}</span>
              <Badge variant={action.count > 0 ? 'default' : 'secondary'}>{action.count}</Badge>
            </a>
          ))}
        </CardContent>
      </Card>

      {(payload?.suggested_people.length ?? 0) > 0 && (
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="flex items-center gap-1.5 text-sm">
              <UserRoundPlus className="h-4 w-4" aria-hidden="true" />
              People to discover
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-1">
            {payload?.suggested_people.map((person) => (
              <PersonLink
                key={person.id}
                name={person.display_name}
                avatarUrl={person.avatar_url}
                href={person.href}
                detail={
                  person.matching_interests_count > 0
                    ? `${person.matching_interests_count} shared ${
                        person.matching_interests_count === 1 ? 'interest' : 'interests'
                      }`
                    : undefined
                }
              />
            ))}
          </CardContent>
        </Card>
      )}

      {(payload?.recently_visited.length ?? 0) > 0 && (
        <Card>
          <CardHeader className="flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="flex items-center gap-1.5 text-sm">
              <Clock3 className="h-4 w-4" aria-hidden="true" />
              Recently visited
            </CardTitle>
            <Button type="button" size="sm" variant="ghost" onClick={() => void clearHistory()}>
              Clear
            </Button>
          </CardHeader>
          <CardContent className="space-y-1">
            {payload?.recently_visited.map((profile) => (
              <PersonLink
                key={`${profile.type}-${profile.id}`}
                name={profile.display_name}
                avatarUrl={profile.avatar_url}
                href={profile.href}
              />
            ))}
          </CardContent>
        </Card>
      )}
    </aside>
  );
}

export function SideRailLayout({
  children,
  enabled = true,
}: {
  children: ReactNode;
  enabled?: boolean;
}) {
  if (!enabled) return <>{children}</>;

  return (
    <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start">
      <div className="min-w-0">{children}</div>
      <AppSideRail />
    </div>
  );
}
