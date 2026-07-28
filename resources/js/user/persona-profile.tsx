import { BookOpen, Images, MessageSquare, Pencil } from 'lucide-react';
import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { Avatar } from '@/components/avatar';
import { FavoriteButton } from '@/components/favorite-button';
import { ReportButton } from '@/components/report-button';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';
import { MediaListTab, PostsListTab, StoriesListTab } from '@/user/profile-tabs';

interface PersonaInterest { id: number; name: string | null; }

/** Present only when the persona is Linked — a Separate persona's payload
 *  carries nothing that resolves to the human behind it. */
interface PersonaOwner { display_name: string; href: string; }

interface PersonaProfileData {
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
export function PersonaProfilePage() {
  const [persona] = useState<PersonaProfileData | null>(
    () => readInitialData<{ personaProfile?: PersonaProfileData }>().personaProfile ?? null,
  );
  const [tab, setTab] = useState<TabKey>('media');
  const [counts, setCounts] = useState<Record<TabKey, number> | null>(null);
  const ulid = persona?.ulid ?? null;

  useEffect(() => {
    if (!ulid) return;
    let active = true;
    fetchWrapper.get(`/api/c/${ulid}/counts`)
      .then((response) => { if (active) setCounts((response as { data: Record<TabKey, number> }).data); })
      .catch(() => { if (active) setCounts(null); });
    return () => { active = false; };
  }, [ulid]);

  if (!persona) return <div className="mx-auto max-w-4xl px-4 py-8">Loading persona...</div>;

  return (
    <div className="mx-auto max-w-4xl space-y-6 px-4 py-8">
      <a className="text-sm underline underline-offset-4" href="/explore">← Explore</a>

      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="flex min-w-0 items-center gap-4">
              <Avatar name={persona.display_name} src={persona.avatar_url} sizeClassName="h-20 w-20" />
              <div className="min-w-0">
                <CardTitle className="truncate text-2xl">{persona.display_name}</CardTitle>
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
              </div>
            </div>
            <div className="flex shrink-0 items-center gap-2">
              {persona.is_owner ? (
                <Button variant="outline" asChild>
                  <a href="/me"><Pencil className="h-4 w-4" aria-hidden="true" /> Manage on your profile</a>
                </Button>
              ) : (
                <>
                  <FavoriteButton type="character" id={persona.id} initialFavorited={persona.viewer_favorited} />
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
        {tab === 'media' && <MediaListTab endpoint={`/api/c/${persona.ulid}/media`} emptyTitle="No media to show." />}
        {tab === 'stories' && <StoriesListTab endpoint={`/api/c/${persona.ulid}/stories`} emptyTitle="No stories to show." />}
        {tab === 'posts' && <PostsListTab endpoint={`/api/c/${persona.ulid}/posts`} emptyTitle="No posts to show." />}
      </div>
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('persona-profile');
if (mountEl) createRoot(mountEl).render(<PersonaProfilePage />);
