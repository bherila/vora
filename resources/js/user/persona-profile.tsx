import { BookOpen, Images, MessageSquare, Pencil, UserPlus, Users } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import { Avatar } from '@/components/avatar';
import { FavoriteButton } from '@/components/favorite-button';
import { MuteButton } from '@/components/mute-button';
import { BROWSING_PAGE_WIDTH } from '@/components/page-width';
import { ReportButton } from '@/components/report-button';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';
import { MediaListTab, PostsListTab, StoriesListTab } from '@/user/profile-tabs';
import { type ProfileViewAs, ViewAsBanner } from '@/user/view-as-control';

interface PersonaInterest { id: number; name: string | null; }

/** Present only when the persona is Linked — a Separate persona's payload
 *  carries nothing that resolves to the human behind it. */
interface PersonaOwner { display_name: string; href: string; }

export interface PersonaProfileData {
  id: number;
  ulid: string;
  display_name: string;
  description: string | null;
  avatar_url: string | null;
  user_type: string | null;
  gender: string | null;
  is_owner: boolean;
  is_linked: boolean;
  owner: PersonaOwner | null;
  interests: PersonaInterest[];
  viewer_favorited: boolean;
  can_report: boolean;
  viewer_muted: boolean;
}

interface PersonaProfileViewProps {
  persona: PersonaProfileData;
  viewAs?: ProfileViewAs | null;
}

interface FollowerIdentity {
  id: number;
  display_name: string;
  avatar_url: string | null;
  restricted: boolean;
}

interface PersonaFollower {
  follower: FollowerIdentity;
  followed_at: string | null;
}

interface PersonaFollowersData {
  count: number;
  viewer_is_following: boolean;
  followers: PersonaFollower[];
}

type TabKey = 'media' | 'stories' | 'posts';

interface TabDef { key: TabKey; label: string; icon: typeof Images }

const TABS: TabDef[] = [
  { key: 'media', label: 'Media', icon: Images },
  { key: 'stories', label: 'Stories', icon: BookOpen },
  { key: 'posts', label: 'Posts', icon: MessageSquare },
];

/**
 * A persona's public page: the persona is the star — its name, avatar, and
 * work lead; the human behind it appears only as quiet page meta, and only
 * when the persona is Linked. This is the product's primary public surface
 * for a persona, so the layout mirrors the visitor profile: header card,
 * then content tabs with media first.
 */
export function PersonaProfileView({ persona, viewAs = null }: PersonaProfileViewProps) {
  const [tab, setTab] = useState<TabKey>('media');
  const [counts, setCounts] = useState<Record<TabKey, number> | null>(null);
  const [followData, setFollowData] = useState<PersonaFollowersData | null>(null);
  const [followPending, setFollowPending] = useState(false);
  const [followersOpen, setFollowersOpen] = useState(false);
  const ulid = persona.ulid;
  const personaId = persona.id;
  const query = viewAs ? `?view_as=${viewAs.mode}` : '';
  const readOnly = viewAs !== null;

  const fetchFollowers = useCallback(async (): Promise<PersonaFollowersData | null> => {
    const response = await fetchWrapper.get(`/api/characters/${personaId}/followers${query}`) as { data: PersonaFollowersData };
    return response.data;
  }, [personaId, query]);

  useEffect(() => {
    let active = true;
    fetchWrapper.get(`/api/c/${ulid}/counts${query}`)
      .then((response) => { if (active) setCounts((response as { data: Record<TabKey, number> }).data); })
      .catch(() => { if (active) setCounts(null); });
    return () => { active = false; };
  }, [query, ulid]);

  useEffect(() => {
    let active = true;
    fetchFollowers()
      .then((data) => { if (active) setFollowData(data); })
      .catch(() => { if (active) setFollowData(null); });
    return () => { active = false; };
  }, [fetchFollowers]);

  const followPersona = async (): Promise<void> => {
    setFollowPending(true);
    try {
      await fetchWrapper.post(`/api/characters/${persona.id}/follow`, {});
      setFollowData(await fetchFollowers());
      toast.success(`You are now following ${persona.display_name}.`);
    } catch (error) {
      toast.error(typeof error === 'string' ? error : `Could not follow ${persona.display_name}.`);
      setFollowData(await fetchFollowers().catch(() => null));
    } finally {
      setFollowPending(false);
    }
  };

  const followerCount = followData?.count ?? 0;
  const followerLabel = `${followerCount} ${followerCount === 1 ? 'follower' : 'followers'}`;

  return (
    <div className={`${BROWSING_PAGE_WIDTH} space-y-6 px-4 py-8`}>
      {viewAs && <ViewAsBanner viewAs={viewAs} />}
      <a className="text-sm underline underline-offset-4" href="/explore">← Explore</a>

      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="flex min-w-0 items-center gap-4">
              <Avatar name={persona.display_name} src={persona.avatar_url} sizeClassName="h-20 w-20" />
              <div className="min-w-0">
                <h1 className="truncate text-2xl font-semibold leading-none">{persona.display_name}</h1>
                <CardDescription className="mt-1 flex flex-wrap items-center gap-2">
                  <Badge variant="secondary">Persona</Badge>
                  {persona.user_type && <Badge variant="outline">{persona.user_type}</Badge>}
                  {persona.gender && <Badge variant="outline">{persona.gender}</Badge>}
                </CardDescription>
                {/* Owner meta — rendered for Linked personas only. A Separate
                    persona's page never names the human behind it. */}
                {persona.owner && (
                  <p className="mt-2 text-sm text-muted-foreground">
                    A persona of{' '}
                    <a className="font-medium text-foreground underline underline-offset-4" href={persona.owner.href}>
                      {persona.owner.display_name}
                    </a>
                  </p>
                )}
                {followData && (
                  <Button
                    type="button"
                    variant="link"
                    size="sm"
                    className="mt-1 h-auto px-0 text-muted-foreground"
                    aria-label={followerLabel}
                    onClick={() => setFollowersOpen(true)}
                  >
                    <Users className="h-4 w-4" aria-hidden="true" />
                    {followerLabel}
                  </Button>
                )}
              </div>
            </div>
            <div className="flex shrink-0 items-center gap-2">
              {readOnly ? null : persona.is_owner ? (
                <Button variant="outline" asChild>
                  <a href={`/c/${persona.ulid}/edit`}>
                    <Pencil className="h-4 w-4" aria-hidden="true" /> Edit persona
                  </a>
                </Button>
              ) : (
                <>
                  <Button
                    type="button"
                    size="sm"
                    disabled={followData === null || followData.viewer_is_following || followPending}
                    aria-label={followData?.viewer_is_following ? `Following ${persona.display_name}` : `Follow ${persona.display_name}`}
                    onClick={() => void followPersona()}
                  >
                    <UserPlus className="h-4 w-4" aria-hidden="true" />
                    {followData?.viewer_is_following ? 'Following' : followPending ? 'Following…' : 'Follow'}
                  </Button>
                  <FavoriteButton type="character" id={persona.id} initialFavorited={persona.viewer_favorited} />
                  <MuteButton
                    type="character"
                    id={persona.id}
                    displayName={persona.display_name}
                    initialMuted={persona.viewer_muted}
                  />
                  {persona.can_report && <ReportButton type="character" id={persona.id} variant="ghost" />}
                </>
              )}
            </div>
          </div>
        </CardHeader>
        {(persona.description || persona.interests.length > 0) && (
          <CardContent className="space-y-4">
            {persona.description && <p className="whitespace-pre-line text-sm">{persona.description}</p>}
            {persona.interests.length > 0 && (
              <section aria-label="Interests" className="flex flex-wrap gap-2">
                {persona.interests.map((interest) => interest.name && <Badge key={interest.id}>{interest.name}</Badge>)}
              </section>
            )}
          </CardContent>
        )}
      </Card>

      <div className="space-y-4">
        <div className="flex flex-wrap gap-2" role="tablist" aria-label="Persona content">
          {TABS.map(({ key, label, icon: Icon }) => (
            <Button key={key} type="button" size="sm" variant={tab === key ? 'default' : 'outline'} aria-pressed={tab === key} onClick={() => setTab(key)}>
              <Icon className="h-4 w-4" /> {label}
              {counts && typeof counts[key] === 'number' && <span className="ml-1 text-xs tabular-nums opacity-70">{counts[key]}</span>}
            </Button>
          ))}
        </div>
        {tab === 'media' && (
          <MediaListTab endpoint={`/api/c/${persona.ulid}/media${query}`} emptyTitle="No media to show." readOnly={readOnly} />
        )}
        {tab === 'stories' && (
          <StoriesListTab endpoint={`/api/c/${persona.ulid}/stories${query}`} emptyTitle="No stories to show." readOnly={readOnly} />
        )}
        {tab === 'posts' && <PostsListTab endpoint={`/api/c/${persona.ulid}/posts${query}`} emptyTitle="No posts to show." readOnly={readOnly} />}
      </div>

      <Dialog open={followersOpen} onOpenChange={setFollowersOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{persona.display_name}&apos;s followers</DialogTitle>
            <DialogDescription>People who follow {persona.display_name}.</DialogDescription>
          </DialogHeader>
          {followData?.followers?.length ? (
            <div className="space-y-3">
              {followData.followers.map((entry) => (
                <div key={entry.follower.id} className="flex items-center gap-3 rounded-lg border p-3">
                  <Avatar name={entry.follower.display_name} src={entry.follower.avatar_url} sizeClassName="h-10 w-10" />
                  <div className="min-w-0">
                    <a className="block truncate text-sm font-medium underline underline-offset-4" href={`/users/${entry.follower.id}`}>
                      {entry.follower.display_name}
                    </a>
                    <p className="text-xs text-muted-foreground">
                      Follower
                      {entry.follower.restricted ? ' · Private profile' : ''}
                    </p>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-muted-foreground">No followers yet.</p>
          )}
        </DialogContent>
      </Dialog>
      {!readOnly && <Toaster position="top-right" richColors closeButton />}
    </div>
  );
}

export function PersonaProfilePage() {
  const initial = readInitialData<{
    personaProfile?: PersonaProfileData;
    profileViewAs?: ProfileViewAs | null;
  }>();
  const [persona] = useState<PersonaProfileData | null>(() => initial.personaProfile ?? null);

  if (!persona) return <div className={`${BROWSING_PAGE_WIDTH} px-4 py-8`}>Loading persona...</div>;

  return <PersonaProfileView persona={persona} viewAs={initial.profileViewAs ?? null} />;
}

const mountEl = document.getElementById('persona-profile');
if (mountEl) createRoot(mountEl).render(<PersonaProfilePage />);
