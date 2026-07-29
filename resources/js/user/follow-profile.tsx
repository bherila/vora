import { BookOpen, Images, MessageSquare, Pencil, Plus, Star, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { Avatar } from '@/components/avatar';
import { FavoriteButton } from '@/components/favorite-button';
import { HelpHint } from '@/components/help-hint';
import { BROWSING_PAGE_WIDTH } from '@/components/page-width';
import { ProtectedImage } from '@/components/protected-image';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { fetchWrapper } from '@/fetchWrapper';
import {
  removeIdentityOption,
  switchActiveIdentity,
  useIdentityStore,
} from '@/identity';
import { readInitialData } from '@/initialData';
import type { CharacterOption } from '@/media/MediaUploadDialog';
import { OwnerMediaManager } from '@/media/OwnerMediaManager';
import { OwnerStoriesManager } from '@/stories/OwnerStoriesManager';
import type { CharacterRecord } from '@/user/persona-editor';
import { type PersonaProfileData, PersonaProfileView } from '@/user/persona-profile';
import { type ProfileEditable, ProfileIdentityEditor } from '@/user/profile-identity-editor';
import { GridSkeleton, MediaListTab, PostsListTab, StoriesListTab, TabEmpty, TabError, useProfileList } from '@/user/profile-tabs';
import { type ProfileViewAs, ViewAsBanner, ViewAsControl } from '@/user/view-as-control';

interface Interest { id: number; name: string; }
interface CharacterRef { id: number; display_name: string; avatar_url?: string | null; }
interface FollowRequestState { status: string; can_retry: boolean; }
interface ProfileData {
  id: number;
  is_self: boolean;
  display_name: string;
  avatar_url?: string | null;
  restricted: boolean;
  bio: string | null;
  pronouns: string | null;
  user_type: string | null;
  gender: string | null;
  mutual_interests: Interest[];
  follow_request: FollowRequestState | null;
  can_follow_back: boolean;
  characters: CharacterRef[];
  viewer_favorited?: boolean;
}
interface ProfileResponse { success: boolean; data: ProfileData; }

type TabKey = 'media' | 'stories' | 'posts' | 'favorites';
interface FavoriteCard { type: string; id: number; label: string; subtitle: string; href: string; thumbnail_url: string | null; }

/** One entry in the combined "Latest" strip (media, story, or post). */
interface RecentItem {
  type: 'media' | 'story' | 'post';
  id: number;
  title: string | null;
  thumbnail_url: string | null;
  href: string;
  created_at: string | null;
}

/** Per-identity content totals for the identity rail (owner's /me only). */
interface IdentityCounts { self: number; characters: Record<string, number>; }

/** Upload context for the owner's own profile: character privacy options + last interests. */
interface ProfileMediaData { characters: CharacterOption[]; last_interest_ids: number[]; }

function getInitialProfile(): ProfileData | null {
  return readInitialData<{ followProfile?: ProfileData }>().followProfile ?? null;
}

function getProfileMedia(): ProfileMediaData {
  return readInitialData<{ profileMedia?: ProfileMediaData }>().profileMedia ?? { characters: [], last_interest_ids: [] };
}

function getProfileCharacters(): CharacterRecord[] {
  return readInitialData<{ profileCharacters?: CharacterRecord[] }>().profileCharacters ?? [];
}

function getIdentityCounts(): IdentityCounts | null {
  return readInitialData<{ profileIdentityCounts?: IdentityCounts | null }>().profileIdentityCounts ?? null;
}

function getProfileViewAs(): ProfileViewAs | null {
  return readInitialData<{ profileViewAs?: ProfileViewAs | null }>().profileViewAs ?? null;
}

function getPersonaProfile(): PersonaProfileData | null {
  return readInitialData<{ personaProfile?: PersonaProfileData | null }>().personaProfile ?? null;
}

function getHydratedActiveIdentity(): number | null {
  return readInitialData<{ navbar?: { activeIdentityId?: number | null } }>().navbar?.activeIdentityId ?? null;
}

function profileQuery(identity: number | null, viewAs: ProfileViewAs | null = null): string {
  const params = new URLSearchParams();
  if (identity !== null) params.set('character_id', String(identity));
  if (viewAs) params.set('view_as', viewAs.mode);

  const query = params.toString();
  return query === '' ? '' : `?${query}`;
}

/**
 * The "Latest" showcase strip: the active identity's most recent media, stories,
 * and posts in one horizontally scrolling row. Quiet by design — it disappears
 * entirely (no header, no empty state) when there is nothing to show, and on
 * load errors the tabs below remain the authoritative surface.
 */
function LatestStrip({ userId, identity }: { userId: number; identity: number | null }) {
  const { items, loading, error } = useProfileList<RecentItem>(`/api/users/${userId}/recent-content${profileQuery(identity)}`);

  if (loading) {
    return (
      <div className="flex gap-3 overflow-hidden" role="status" aria-busy="true">
        <span className="sr-only">Loading latest content…</span>
        {Array.from({ length: 4 }).map((_, index) => (
          <Skeleton key={index} className="h-28 w-28 shrink-0 rounded-lg" />
        ))}
      </div>
    );
  }
  if (error || items.length === 0) return null;

  return (
    <section aria-label="Latest" className="space-y-2">
      <h2 className="text-sm font-semibold text-muted-foreground">Latest</h2>
      <div className="flex gap-3 overflow-x-auto pb-1">
        {items.map((item) => (
          <a
            key={`${item.type}-${item.id}`}
            href={item.href}
            className="group shrink-0 focus-visible:outline-2 focus-visible:outline-ring"
          >
            {item.type === 'media' ? (
              item.thumbnail_url ? (
                <ProtectedImage
                  src={item.thumbnail_url}
                  alt={item.title ?? 'Media'}
                  className="h-28 w-28 rounded-lg border border-border object-cover transition-opacity group-hover:opacity-90"
                />
              ) : (
                <span className="flex h-28 w-28 items-center justify-center rounded-lg border border-border bg-muted text-muted-foreground">
                  <Images className="h-6 w-6" aria-hidden="true" />
                  <span className="sr-only">{item.title ?? 'Media'}</span>
                </span>
              )
            ) : (
              <span className="flex h-28 w-44 flex-col justify-between rounded-lg border border-border bg-muted/40 p-3 transition-colors group-hover:bg-muted">
                <span className="line-clamp-3 text-xs text-foreground">{item.title || (item.type === 'story' ? 'Untitled story' : 'Post')}</span>
                <span className="flex items-center gap-1 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                  {item.type === 'story'
                    ? <><BookOpen className="h-3 w-3" aria-hidden="true" /> Story</>
                    : <><MessageSquare className="h-3 w-3" aria-hidden="true" /> Post</>}
                </span>
              </span>
            )}
          </a>
        ))}
      </div>
    </section>
  );
}

interface IdentityRailProps {
  profile: ProfileData;
  identity: number | null;
  counts: IdentityCounts | null;
  onSelect: (identity: number | null) => void;
  createHref?: string | undefined;
}

/**
 * The identity rail: an avatar tab per identity (the main profile plus each
 * persona), with per-identity content totals on the owner's own page. Rendered
 * only when at least one persona exists — a lone "You" tab explains nothing.
 */
function IdentityRail({ profile, identity, counts, onSelect, createHref }: IdentityRailProps) {
  const railItems: { key: string; id: number | null; name: string; avatar_url: string | null | undefined; count: number | undefined }[] = [
    { key: 'self', id: null, name: profile.display_name, avatar_url: profile.avatar_url, count: counts?.self },
    ...profile.characters.map((character) => ({
      key: `c-${character.id}`,
      id: character.id as number | null,
      name: character.display_name,
      avatar_url: character.avatar_url,
      count: counts?.characters[String(character.id)],
    })),
  ];

  return (
    <nav aria-label="Identities" className="flex items-start gap-1 overflow-x-auto pb-1">
      {railItems.map((item) => {
        const active = identity === item.id;
        return (
          <button
            key={item.key}
            type="button"
            onClick={() => onSelect(item.id)}
            aria-pressed={active}
            className={`flex w-20 shrink-0 flex-col items-center gap-1.5 rounded-lg px-2 py-2 text-center ${active ? 'bg-muted' : 'hover:bg-muted/60'}`}
          >
            <Avatar
              name={item.name}
              src={item.avatar_url}
              sizeClassName="h-12 w-12"
              className={active ? 'ring-2 ring-foreground ring-offset-2 ring-offset-background' : ''}
            />
            <span className="w-full truncate text-xs font-medium">{item.name}</span>
            {typeof item.count === 'number' && (
              <span className="text-[11px] tabular-nums leading-none text-muted-foreground">{item.count}</span>
            )}
          </button>
        );
      })}
      {createHref && (
        <a
          href={createHref}
          className="flex w-20 shrink-0 flex-col items-center gap-1.5 rounded-lg px-2 py-2 text-center text-muted-foreground hover:bg-muted/60 hover:text-foreground"
        >
          <span className="flex h-12 w-12 items-center justify-center rounded-full border border-dashed border-border">
            <Plus className="h-5 w-5" aria-hidden="true" />
          </span>
          <span className="w-full truncate text-xs font-medium">New persona</span>
        </a>
      )}
    </nav>
  );
}

function FavoritesTab({ userId, isSelf, viewAs }: { userId: number; isSelf: boolean; viewAs: ProfileViewAs | null }) {
  const { items, loading, error } = useProfileList<FavoriteCard>(`/api/users/${userId}/favorites${profileQuery(null, viewAs)}`);
  if (loading) return <GridSkeleton itemClassName="h-20" />;
  if (error) return <TabError message={error} />;
  if (items.length === 0) {
    return (
      <TabEmpty
        icon={Star}
        title={isSelf
          ? 'Tap Save on any media, story, post, or profile to keep it here.'
          : 'No favorites to show.'}
      />
    );
  }
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
      {items.map((item) => {
        const card = (
          <Card className={`h-full ${viewAs ? '' : 'transition-colors hover:bg-muted'}`}>
            <CardContent className="flex items-center gap-3 p-3">
              {item.thumbnail_url ? (
                <img src={item.thumbnail_url} alt="" className="h-12 w-12 shrink-0 rounded object-cover" />
              ) : (
                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground"><Star className="h-5 w-5" /></span>
              )}
              <span className="min-w-0">
                <span className="block truncate text-sm font-medium">{item.label}</span>
                <span className="block text-xs text-muted-foreground">{item.subtitle}</span>
              </span>
            </CardContent>
          </Card>
        );

        return viewAs
          ? <div key={`${item.type}-${item.id}`}>{card}</div>
          : <a key={`${item.type}-${item.id}`} href={item.href} className="block">{card}</a>;
      })}
    </div>
  );
}

interface TabDef { key: TabKey; label: string; icon: typeof Images }

const TABS: TabDef[] = [
  { key: 'media', label: 'Media', icon: Images },
  { key: 'stories', label: 'Stories', icon: BookOpen },
  { key: 'posts', label: 'Posts', icon: MessageSquare },
  { key: 'favorites', label: 'Favorites', icon: Star },
];

export function FollowProfilePage() {
  const [profile, setProfile] = useState<ProfileData | null>(getInitialProfile);
  const [personaProfile] = useState<PersonaProfileData | null>(getPersonaProfile);
  const [viewAs] = useState<ProfileViewAs | null>(getProfileViewAs);
  const [profileMedia, setProfileMedia] = useState<ProfileMediaData>(getProfileMedia);
  const [profileCharacters, setProfileCharacters] = useState<CharacterRecord[]>(getProfileCharacters);
  const [identityCounts] = useState<IdentityCounts | null>(getIdentityCounts);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const { activeIdentityId } = useIdentityStore();
  const previewIdentity = useRef(getHydratedActiveIdentity());
  // Other people's profile rails are browsing state. On /me, the rail is the
  // same persisted authorship state as the navbar and every create surface.
  const [viewedIdentity, setViewedIdentity] = useState<number | null>(null);
  const identity = profile?.is_self ? activeIdentityId : viewedIdentity;
  // Everyone lands on the showcase: the profile's own work, media first.
  const [tab, setTab] = useState<TabKey>('media');
  const [editOpen, setEditOpen] = useState(false);
  const [editable] = useState<ProfileEditable | null>(() => readInitialData<{ profileEditable?: ProfileEditable }>().profileEditable ?? null);
  const [counts, setCounts] = useState<Record<TabKey, number> | null>(null);
  const userId = profile?.id ?? null;
  const restricted = profile?.restricted ?? true;

  // Per-identity tab counts, refreshed whenever the active identity changes so
  // every badge is populated up front rather than only after a tab is opened.
  useEffect(() => {
    if (!userId || restricted) { setCounts(null); return; }
    let active = true;
    setCounts(null);
    fetchWrapper.get(`/api/users/${userId}/content-counts${profileQuery(identity, viewAs)}`)
      .then((response) => { if (active) setCounts((response as { data: Record<TabKey, number> }).data); })
      .catch(() => { if (active) setCounts(null); });
    return () => { active = false; };
  }, [userId, identity, restricted, viewAs]);

  useEffect(() => {
    if (viewAs && previewIdentity.current !== activeIdentityId) {
      window.location.assign(`/me${profileQuery(null, viewAs)}`);
    }
  }, [activeIdentityId, viewAs]);

  const loadProfile = () => {
    if (!userId) return;
    fetchWrapper.get(`/api/users/${userId}${profileQuery(null, viewAs)}`)
      .then((response) => setProfile((response as ProfileResponse).data))
      .catch(() => setError('Unable to load profile.'));
  };

  const sendRequest = async () => {
    if (!userId) return;
    setError('');
    setMessage('');
    try {
      await fetchWrapper.post(`/api/users/${userId}/follow-requests`, {});
      setMessage('Follow request sent.');
      loadProfile();
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Unable to send follow request.');
    }
  };

  const handleDeleteCharacter = async (record: CharacterRecord): Promise<void> => {
    if (!window.confirm(`Delete ${record.display_name}? Art ownership stays with your user account.`)) return;
    try {
      await fetchWrapper.delete(`/api/characters/${record.id}`);
      if (activeIdentityId === record.id) {
        await switchActiveIdentity(null);
      }
      removeIdentityOption(record.id);
      setProfileCharacters((current) => current.filter((c) => c.id !== record.id));
      setProfile((current) => current ? { ...current, characters: current.characters.filter((c) => c.id !== record.id) } : current);
      setProfileMedia((current) => ({
        ...current,
        characters: current.characters.filter((character) => character.id !== record.id),
      }));
      if (viewedIdentity === record.id) setViewedIdentity(null);
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Failed to delete character.');
    }
  };

  if (personaProfile && viewAs) return <PersonaProfileView persona={personaProfile} viewAs={viewAs} />;
  if (!profile) return <div className={`${BROWSING_PAGE_WIDTH} px-4 py-8`}>Loading profile...</div>;
  const isPreview = viewAs !== null;
  const hasActiveRequest = profile.follow_request !== null && !profile.follow_request.can_retry;
  const hasPersonas = profile.characters.length > 0;
  // Favorites belong to the user, not a character, so they only apply to the
  // main identity. Switching to a character while on Favorites falls back to
  // the first available tab.
  const tabs: TabDef[] = identity !== null ? TABS.filter((t) => t.key !== 'favorites') : TABS;
  const activeTab: TabKey = tabs.some((t) => t.key === tab) ? tab : (tabs[0]?.key ?? 'media');
  const activeCharacter = identity !== null ? profileCharacters.find((c) => c.id === identity) ?? null : null;
  const handleIdentitySelect = (nextIdentity: number | null): void => {
    if (!profile.is_self) {
      setViewedIdentity(nextIdentity);
      return;
    }

    switchActiveIdentity(nextIdentity).catch(() => setError('Unable to switch identity.'));
  };

  return (
    <div className={`${BROWSING_PAGE_WIDTH} space-y-6 px-4 py-8`} data-page-width="browsing">
      {viewAs && <ViewAsBanner viewAs={viewAs} />}
      {!profile.is_self && <a className="text-sm underline underline-offset-4" href="/users">← Browse people</a>}
      {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
      {message && <Alert><AlertDescription>{message}</AlertDescription></Alert>}

      <div data-profile-layout="summary-and-content" className={!profile.restricted && userId
        ? 'grid gap-6 lg:grid-cols-[minmax(16rem,22rem)_minmax(0,1fr)] lg:items-start'
        : undefined}
      >
        <div className="min-w-0 space-y-4">
          <Card>
        <CardHeader>
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div className="flex min-w-0 items-center gap-4">
              <Avatar name={profile.display_name} src={profile.avatar_url} sizeClassName="h-16 w-16" />
              <div className="min-w-0">
                <CardTitle className="truncate">
                  {profile.display_name}
                  {profile.pronouns && <span className="ml-2 text-sm font-normal text-muted-foreground">{profile.pronouns}</span>}
                </CardTitle>
                {(profile.user_type || profile.gender) && (
                  <CardDescription className="mt-1 flex flex-wrap gap-2">
                    {profile.user_type && <Badge variant="outline">{profile.user_type}</Badge>}
                    {profile.gender && <Badge variant="outline">{profile.gender}</Badge>}
                  </CardDescription>
                )}
              </div>
            </div>
            {profile.is_self ? (
              <div className="flex gap-2">
                {editable && <Button onClick={() => setEditOpen(true)}>Edit profile</Button>}
                <Button variant="outline" asChild><a href="/user/settings">Account settings</a></Button>
                <ViewAsControl />
              </div>
            ) : isPreview ? null : (
              <div className="flex items-center gap-2">
                {!profile.restricted && <FavoriteButton type="user" id={profile.id} initialFavorited={profile.viewer_favorited ?? false} />}
                {!hasActiveRequest ? (
                  <Button onClick={() => void sendRequest()}>{profile.can_follow_back ? 'Follow back' : 'Send follow request'}</Button>
                ) : (
                  <span className="text-sm text-muted-foreground">Request: <strong>{profile.follow_request?.status}</strong></span>
                )}
              </div>
            )}
          </div>
        </CardHeader>
        <CardContent className="space-y-5">
          {profile.restricted ? (
            <section>
              <h2 className="font-semibold">This profile is private</h2>
              <p className="text-sm text-muted-foreground">Send a follow request to see more.</p>
            </section>
          ) : (
            <>
              {profile.bio && <p className="whitespace-pre-line text-sm">{profile.bio}</p>}
              <section>
                <h2 className="font-semibold">{profile.is_self ? 'Your interests' : 'Mutual interests'}</h2>
                <div className="mt-2 flex flex-wrap gap-2">
                  {profile.mutual_interests.length === 0
                    ? <span className="text-sm text-muted-foreground">{profile.is_self ? 'No interests set yet.' : 'No mutual interests yet.'}</span>
                    : profile.mutual_interests.map((interest) => <Badge key={interest.id}>{interest.name}</Badge>)}
                </div>
              </section>

              {/* The single quiet entry point into personas for an owner who has
                  none. Everything else persona-shaped is absent until then. */}
              {profile.is_self && !hasPersonas && (
                <div className="flex items-center gap-1">
                  <Button size="sm" variant="ghost" className="text-muted-foreground" asChild>
                    <a href="/personas/new">
                      <Plus className="h-4 w-4" aria-hidden="true" /> Create a persona
                    </a>
                  </Button>
                  <HelpHint label="Personas">
                    <p>
                      Personas are characters you create — for fiction, art, and role-play.
                      Each gets its own page, its own followers, and its own media. Your real
                      profile stays separate. Most people never need one.
                    </p>
                  </HelpHint>
                </div>
              )}
            </>
          )}
        </CardContent>
          </Card>

          {/* Identity rail — absent entirely until the first persona exists. */}
          {!profile.restricted && userId && hasPersonas && (
            <IdentityRail
              profile={profile}
              identity={identity}
              counts={profile.is_self ? identityCounts : null}
              onSelect={handleIdentitySelect}
              createHref={profile.is_self && !isPreview ? '/personas/new' : undefined}
            />
          )}
          {/* Owner controls for the active persona. */}
          {!profile.restricted && userId && profile.is_self && activeCharacter && (
            <div className="flex flex-wrap gap-2">
              <Button size="sm" variant="outline" asChild>
                <a href={`/c/${activeCharacter.ulid}/edit`}>
                  <Pencil className="h-4 w-4" /> Edit persona
                </a>
              </Button>
              <Button type="button" size="sm" variant="ghost" onClick={() => void handleDeleteCharacter(activeCharacter)}>
                <Trash2 className="h-4 w-4" /> Delete
              </Button>
            </div>
          )}
        </div>

        {!profile.restricted && userId && (
          <div className="min-w-0 space-y-4">
          {profile.is_self && <LatestStrip userId={userId} identity={identity} />}

          <div className="flex flex-wrap gap-2" role="tablist" aria-label="Profile content">
            {tabs.map(({ key, label, icon: Icon }) => (
              <Button key={key} type="button" size="sm" variant={activeTab === key ? 'default' : 'outline'} aria-pressed={activeTab === key} onClick={() => setTab(key)}>
                <Icon className="h-4 w-4" /> {label}
                {counts && typeof counts[key] === 'number' && <span className="ml-1 text-xs tabular-nums opacity-70">{counts[key]}</span>}
              </Button>
            ))}
          </div>
          {activeTab === 'media' && (profile.is_self && !isPreview
            ? <OwnerMediaManager userId={userId} identity={identity} characters={profileMedia.characters} lastInterestIds={profileMedia.last_interest_ids} />
            : (
              <MediaListTab
                endpoint={`/api/users/${userId}/media${profileQuery(identity, viewAs)}`}
                emptyTitle="No media to show."
                readOnly={isPreview}
              />
            ))}
          {activeTab === 'stories' && (profile.is_self && !isPreview && identity === null
            ? <OwnerStoriesManager currentUserId={userId} />
            : (
              <StoriesListTab
                endpoint={`/api/users/${userId}/stories${profileQuery(identity, viewAs)}`}
                // For the owner this tab only renders on a character identity (the
                // main identity uses the manager), so the empty state is
                // persona-scoped.
                emptyTitle={profile.is_self ? 'No stories written as this persona yet.' : 'No stories to show.'}
                readOnly={isPreview}
              />
            ))}
          {activeTab === 'posts' && (
            <PostsListTab
              endpoint={`/api/users/${userId}/posts${profileQuery(identity, viewAs)}`}
              emptyTitle={profile.is_self ? 'You haven’t posted anything here yet.' : 'No posts to show.'}
              emptyAction={profile.is_self ? <Button size="sm" variant="outline" asChild><a href="/feed">Go to your feed</a></Button> : undefined}
              readOnly={isPreview}
            />
          )}
          {activeTab === 'favorites' && identity === null && (
            <FavoritesTab userId={userId} isSelf={profile.is_self} viewAs={viewAs} />
          )}
          </div>
        )}
      </div>
      {editable && (
        <Dialog open={editOpen} onOpenChange={(open) => { if (!open) setEditOpen(false); }}>
          <DialogContent className="sm:max-w-2xl">
            <DialogHeader>
              <DialogTitle>Edit profile</DialogTitle>
              <DialogDescription>This is how others see you. Account and security settings live in Account settings.</DialogDescription>
            </DialogHeader>
            <ProfileIdentityEditor
              editable={editable}
              onSaved={(summary) => setProfile((current) => current ? {
                ...current,
                display_name: summary.display_name,
                bio: summary.bio,
                pronouns: summary.pronouns,
                user_type: summary.user_type,
                gender: summary.gender,
              } : current)}
            />
          </DialogContent>
        </Dialog>
      )}
      {!isPreview && <Toaster position="top-right" richColors closeButton />}
    </div>
  );
}

const mountEl = document.getElementById('follow-profile');
if (mountEl) createRoot(mountEl).render(<FollowProfilePage />);
