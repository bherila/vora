import { Clock3, UserRoundPlus } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

import { Avatar } from '@/components/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { fetchWrapper } from '@/fetchWrapper';
import { safeInternalUrl } from '@/security/dom-url';

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
  const safeHref = safeInternalUrl(href);
  const content = (
    <>
      <Avatar name={name} src={avatarUrl} sizeClassName="h-8 w-8" />
      <span className="min-w-0">
        <span className="block truncate text-sm font-medium">{name}</span>
        {detail && <span className="block text-xs text-muted-foreground">{detail}</span>}
      </span>
    </>
  );

  if (safeHref === null) {
    return <div className="flex min-w-0 items-center gap-2 rounded-md p-1.5">{content}</div>;
  }

  return (
    <a href={safeHref} className="flex min-w-0 items-center gap-2 rounded-md p-1.5 hover:bg-muted">
      {content}
    </a>
  );
}

function FollowSuggestionButton({ person }: { person: SuggestedPerson }) {
  const [state, setState] = useState<'idle' | 'busy' | 'requested'>('idle');
  const [error, setError] = useState('');

  const follow = async (): Promise<void> => {
    setState('busy');
    setError('');
    try {
      await fetchWrapper.post(`/api/users/${person.id}/follow-requests`, {});
      setState('requested');
    } catch (err) {
      setState('idle');
      setError(typeof err === 'string' ? err : 'Could not send the follow request.');
    }
  };

  return (
    <div className="flex shrink-0 flex-col items-end">
      <Button
        type="button"
        size="sm"
        variant="outline"
        disabled={state !== 'idle'}
        aria-label={state === 'requested' ? `Follow request sent to ${person.display_name}` : `Follow ${person.display_name}`}
        onClick={() => void follow()}
      >
        {state === 'requested' ? 'Requested' : 'Follow'}
      </Button>
      {error && <span role="alert" className="mt-1 max-w-32 text-right text-xs text-destructive">{error}</span>}
    </div>
  );
}

/**
 * Context for the page beside it. A card renders only when it has something to
 * say: pending actions with nothing waiting are left out instead of listed at
 * zero. Choosing who you create as lives in the navbar and the post composer.
 */
export function AppSideRail() {
  const [payload, setPayload] = useState<SideRailPayload | null>(null);

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

  const waiting = (payload?.pending_actions ?? []).filter((action) => action.count > 0);
  const waitingTotal = waiting.reduce((sum, action) => sum + action.count, 0);

  return (
    <aside aria-label="Account overview" className="space-y-4">
      {waiting.length > 0 && (
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="flex items-center gap-2 text-sm">
              Needs you
              <Badge>{waitingTotal}</Badge>
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-1">
            {waiting.map((action) => {
              const safeHref = safeInternalUrl(action.href);
              const content = (
                <>
                  <span>{action.label}</span>
                  <span className="font-semibold tabular-nums">{action.count}</span>
                </>
              );
              const className = 'flex items-center justify-between rounded-md px-1 py-1.5 text-sm';

              return safeHref === null ? (
                <div key={action.label} className={className}>{content}</div>
              ) : (
                <a key={action.label} href={safeHref} className={`${className} hover:bg-muted`}>
                  {content}
                </a>
              );
            })}
          </CardContent>
        </Card>
      )}

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
              <div key={person.id} className="flex items-center justify-between gap-2">
                <PersonLink
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
                <FollowSuggestionButton person={person} />
              </div>
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
