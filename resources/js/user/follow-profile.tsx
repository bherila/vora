import { BookOpen, Images, MessageSquare, Star } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { PostCard } from '@/community/PostCard';
import type { CommunityPost } from '@/community/types';
import { Avatar } from '@/components/avatar';
import { FavoriteButton } from '@/components/favorite-button';
import { PrivacyBadge } from '@/components/privacy-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { StoryGrid } from '@/explore/StoryGrid';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';
import { MediaGrid } from '@/media/MediaGrid';
import type { MediaItem } from '@/media/types';
import type { StoryDiscoveryItem } from '@/stories/types';
import { type ProfileEditable,ProfileIdentityEditor } from '@/user/profile-identity-editor';

interface Interest { id: number; name: string; }
interface CharacterRef { id: number; display_name: string; avatar_url?: string | null; }
interface FollowRequestState { status: string; can_retry: boolean; }
interface ProfileData {
  id: number;
  is_self: boolean;
  display_name: string;
  avatar_url?: string | null;
  restricted: boolean;
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

function getInitialProfile(): ProfileData | null {
  return readInitialData<{ followProfile?: ProfileData }>().followProfile ?? null;
}

/** Fetch a profile content listing for the active identity/tab; refetch on change. */
function useProfileList<T>(endpoint: string | null): { items: T[]; loading: boolean; error: string } {
  const [items, setItems] = useState<T[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!endpoint) {
      setItems([]);
      setLoading(false);
      return;
    }
    let active = true;
    setLoading(true);
    setError('');
    fetchWrapper.get(endpoint)
      .then((response) => { if (active) setItems(((response as { data?: T[] }).data) ?? []); })
      .catch(() => { if (active) setError('Could not load this content.'); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [endpoint]);

  return { items, loading, error };
}

function characterQuery(identity: number | null): string {
  return identity === null ? '' : `?character_id=${identity}`;
}

/** A grid of pulsing placeholders shown while a tab's content loads. */
function GridSkeleton({ itemClassName = 'aspect-video' }: { itemClassName?: string }) {
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" role="status" aria-busy="true">
      <span className="sr-only">Loading…</span>
      {Array.from({ length: 6 }).map((_, index) => (
        <Skeleton key={index} className={`w-full ${itemClassName}`} />
      ))}
    </div>
  );
}

/** Stacked placeholders for list-shaped tabs (posts). */
function ListSkeleton() {
  return (
    <div className="space-y-3" role="status" aria-busy="true">
      <span className="sr-only">Loading…</span>
      {Array.from({ length: 3 }).map((_, index) => (
        <Skeleton key={index} className="h-24 w-full" />
      ))}
    </div>
  );
}

/** A friendly, centered empty state with an icon and optional call to action. */
function TabEmpty({ icon: Icon, title, action }: { icon: typeof Images; title: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border px-6 py-12 text-center">
      <span className="flex h-10 w-10 items-center justify-center rounded-full bg-muted text-muted-foreground">
        <Icon className="h-5 w-5" aria-hidden="true" />
      </span>
      <p className="max-w-sm text-sm text-muted-foreground">{title}</p>
      {action}
    </div>
  );
}

function TabError({ message }: { message: string }) {
  return <p role="alert" className="rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive">{message}</p>;
}

function MediaTab({ userId, identity, isSelf }: { userId: number; identity: number | null; isSelf: boolean }) {
  const { items, loading, error } = useProfileList<MediaItem>(`/api/users/${userId}/media${characterQuery(identity)}`);
  if (loading) return <GridSkeleton />;
  if (error) return <TabError message={error} />;
  if (items.length === 0) {
    return (
      <TabEmpty
        icon={Images}
        title={isSelf ? 'You haven’t added media to this profile yet.' : 'No media to show.'}
        action={isSelf ? <Button size="sm" variant="outline" asChild><a href="/media">Upload media</a></Button> : undefined}
      />
    );
  }
  // Only the owner sees the per-item privacy indicator, so an item's audience is
  // never disclosed to other viewers.
  if (isSelf) {
    return <MediaGrid items={items} renderActions={(item) => <PrivacyBadge audience={item.audience} discoverable={item.discoverable} />} />;
  }
  return <MediaGrid items={items} />;
}

function StoriesTab({ userId, identity, isSelf }: { userId: number; identity: number | null; isSelf: boolean }) {
  const { items, loading, error } = useProfileList<StoryDiscoveryItem>(`/api/users/${userId}/stories${characterQuery(identity)}`);
  if (loading) return <GridSkeleton />;
  if (error) return <TabError message={error} />;
  if (items.length === 0) {
    return (
      <TabEmpty
        icon={BookOpen}
        title={isSelf ? 'You haven’t published stories here yet.' : 'No stories to show.'}
        action={isSelf ? <Button size="sm" variant="outline" asChild><a href="/stories">Write a story</a></Button> : undefined}
      />
    );
  }
  return <StoryGrid items={items} />;
}

function PostsTab({ userId, identity, isSelf }: { userId: number; identity: number | null; isSelf: boolean }) {
  const { items, loading, error } = useProfileList<CommunityPost>(`/api/users/${userId}/posts${characterQuery(identity)}`);
  if (loading) return <ListSkeleton />;
  if (error) return <TabError message={error} />;
  if (items.length === 0) {
    return (
      <TabEmpty
        icon={MessageSquare}
        title={isSelf ? 'You haven’t posted anything here yet.' : 'No posts to show.'}
        action={isSelf ? <Button size="sm" variant="outline" asChild><a href="/feed">Go to your feed</a></Button> : undefined}
      />
    );
  }
  return <div className="space-y-4">{items.map((post) => <PostCard key={post.id} post={post} />)}</div>;
}

function FavoritesTab({ userId, isSelf }: { userId: number; isSelf: boolean }) {
  const { items, loading, error } = useProfileList<FavoriteCard>(`/api/users/${userId}/favorites`);
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
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {items.map((item) => (
        <a key={`${item.type}-${item.id}`} href={item.href} className="block">
          <Card className="h-full transition-colors hover:bg-muted">
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
        </a>
      ))}
    </div>
  );
}

const TABS: Array<{ key: TabKey; label: string; icon: typeof Images }> = [
  { key: 'media', label: 'Media', icon: Images },
  { key: 'stories', label: 'Stories', icon: BookOpen },
  { key: 'posts', label: 'Posts', icon: MessageSquare },
  { key: 'favorites', label: 'Favorites', icon: Star },
];

function FollowProfilePage() {
  const [profile, setProfile] = useState<ProfileData | null>(getInitialProfile);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  // null = the main user identity; a number = one of their characters.
  const [identity, setIdentity] = useState<number | null>(null);
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
    fetchWrapper.get(`/api/users/${userId}/content-counts${characterQuery(identity)}`)
      .then((response) => { if (active) setCounts((response as { data: Record<TabKey, number> }).data); })
      .catch(() => { if (active) setCounts(null); });
    return () => { active = false; };
  }, [userId, identity, restricted]);

  const loadProfile = () => {
    if (!userId) return;
    fetchWrapper.get(`/api/users/${userId}`)
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

  if (!profile) return <div className="mx-auto max-w-4xl px-4 py-8">Loading profile...</div>;
  const hasActiveRequest = profile.follow_request !== null && !profile.follow_request.can_retry;
  // Favorites belong to the user, not a character, so the tab only applies to the
  // main identity. Switching to a character while on Favorites falls back to Media.
  const tabs = identity === null ? TABS : TABS.filter((t) => t.key !== 'favorites');
  const activeTab: TabKey = tabs.some((t) => t.key === tab) ? tab : 'media';

  return (
    <div className="mx-auto max-w-4xl space-y-6 px-4 py-8">
      {!profile.is_self && <a className="text-sm underline underline-offset-4" href="/users">← Browse people</a>}
      {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
      {message && <Alert><AlertDescription>{message}</AlertDescription></Alert>}

      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div className="flex min-w-0 items-center gap-4">
              <Avatar name={profile.display_name} src={profile.avatar_url} sizeClassName="h-16 w-16" />
              <div className="min-w-0">
                <CardTitle className="truncate">{profile.display_name}</CardTitle>
                <CardDescription className="mt-1 flex flex-wrap gap-2">
                  {profile.user_type && <Badge variant="outline">{profile.user_type}</Badge>}
                  {profile.gender && <Badge variant="outline">{profile.gender}</Badge>}
                </CardDescription>
              </div>
            </div>
            {profile.is_self ? (
              <div className="flex gap-2">
                {editable && <Button onClick={() => setEditOpen(true)}>Edit profile</Button>}
                <Button variant="outline" asChild><a href="/user/settings">Account settings</a></Button>
              </div>
            ) : (
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
              <section>
                <h2 className="font-semibold">{profile.is_self ? 'Your interests' : 'Mutual interests'}</h2>
                <div className="mt-2 flex flex-wrap gap-2">
                  {profile.mutual_interests.length === 0
                    ? <span className="text-sm text-muted-foreground">{profile.is_self ? 'No interests set yet.' : 'No mutual interests yet.'}</span>
                    : profile.mutual_interests.map((interest) => <Badge key={interest.id}>{interest.name}</Badge>)}
                </div>
              </section>

              {/* Identity strip: the main user plus each character. */}
              <section className="flex flex-wrap gap-2 border-t border-border pt-4">
                <button
                  type="button"
                  onClick={() => setIdentity(null)}
                  aria-pressed={identity === null}
                  className={`flex items-center gap-2 rounded-full border px-2 py-1 text-sm ${identity === null ? 'border-foreground bg-muted' : 'border-border hover:bg-muted'}`}
                >
                  <Avatar name={profile.display_name} src={profile.avatar_url} sizeClassName="h-6 w-6" />
                  <span className="max-w-[8rem] truncate">{profile.display_name}</span>
                </button>
                {profile.characters.map((character) => (
                  <button
                    key={character.id}
                    type="button"
                    onClick={() => setIdentity(character.id)}
                    aria-pressed={identity === character.id}
                    className={`flex items-center gap-2 rounded-full border px-2 py-1 text-sm ${identity === character.id ? 'border-foreground bg-muted' : 'border-border hover:bg-muted'}`}
                  >
                    <Avatar name={character.display_name} src={character.avatar_url} sizeClassName="h-6 w-6" />
                    <span className="max-w-[8rem] truncate">{character.display_name}</span>
                  </button>
                ))}
              </section>
            </>
          )}
        </CardContent>
      </Card>

      {!profile.restricted && userId && (
        <div className="space-y-4">
          <div className="flex flex-wrap gap-2" role="tablist" aria-label="Profile content">
            {tabs.map(({ key, label, icon: Icon }) => (
              <Button key={key} type="button" size="sm" variant={activeTab === key ? 'default' : 'outline'} aria-pressed={activeTab === key} onClick={() => setTab(key)}>
                <Icon className="h-4 w-4" /> {label}
                {counts && <span className="ml-1 text-xs tabular-nums opacity-70">{counts[key]}</span>}
              </Button>
            ))}
          </div>
          {activeTab === 'media' && <MediaTab userId={userId} identity={identity} isSelf={profile.is_self} />}
          {activeTab === 'stories' && <StoriesTab userId={userId} identity={identity} isSelf={profile.is_self} />}
          {activeTab === 'posts' && <PostsTab userId={userId} identity={identity} isSelf={profile.is_self} />}
          {activeTab === 'favorites' && identity === null && <FavoritesTab userId={userId} isSelf={profile.is_self} />}
        </div>
      )}
      {editable && (
        <Dialog open={editOpen} onOpenChange={(open) => { if (!open) setEditOpen(false); }}>
          <DialogContent className="sm:max-w-2xl">
            <DialogHeader>
              <DialogTitle>Edit profile</DialogTitle>
              <DialogDescription>This is how others see you. Account and security settings live in Account settings.</DialogDescription>
            </DialogHeader>
            <ProfileIdentityEditor
              editable={editable}
              onSaved={(summary) => setProfile((current) => current ? { ...current, display_name: summary.display_name, user_type: summary.user_type, gender: summary.gender } : current)}
            />
          </DialogContent>
        </Dialog>
      )}
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('follow-profile');
if (mountEl) createRoot(mountEl).render(<FollowProfilePage />);
