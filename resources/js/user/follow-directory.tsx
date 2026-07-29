import { useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Avatar } from '@/components/avatar';
import { BROWSING_PAGE_WIDTH } from '@/components/page-width';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { type PersonaDiscoveryItem,PersonaGrid } from '@/explore/PersonaGrid';
import { readInitialData } from '@/initialData';
import { safeInternalUrl } from '@/security/dom-url';

interface DirectoryUser {
  id: number;
  display_name: string;
  avatar_url?: string | null;
  restricted: boolean;
  user_type: string | null;
  gender: string | null;
  // Null for restricted profiles, whose interest overlap is hidden from the viewer.
  matching_interests_count: number | null;
  interest_match_score: number | null;
}

function FollowDirectoryPage() {
  const [users] = useState<DirectoryUser[]>(() => readInitialData<{ followDirectory?: DirectoryUser[] }>().followDirectory ?? []);
  const [personas] = useState<PersonaDiscoveryItem[]>(
    () => readInitialData<{ followDirectoryPersonas?: PersonaDiscoveryItem[] }>().followDirectoryPersonas ?? [],
  );
  const [query, setQuery] = useState('');

  const filtered = useMemo(() => {
    const term = query.trim().toLowerCase();
    if (!term) return users;
    return users.filter((user) => user.display_name.toLowerCase().includes(term));
  }, [users, query]);

  const filteredPersonas = useMemo(() => {
    const term = query.trim().toLowerCase();
    if (!term) return personas;
    return personas.filter((persona) => persona.display_name.toLowerCase().includes(term));
  }, [personas, query]);

  return (
    <div className={`${BROWSING_PAGE_WIDTH} space-y-6 px-4 py-8`}>
      <div>
        <h1 className="text-2xl font-bold">Browse people</h1>
        <p className="text-sm text-muted-foreground">View profiles, request to follow people, and discover their personas.</p>
      </div>
      <Input
        type="search"
        value={query}
        onChange={(event) => setQuery(event.target.value)}
        placeholder="Search by name"
        aria-label="Search people and personas by name"
      />
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {filtered.map((user) => (
          <Card key={user.id}>
            <CardHeader>
              <div className="flex items-center gap-3">
                <Avatar name={user.display_name} src={user.avatar_url} sizeClassName="h-10 w-10" />
                <div className="min-w-0">
                  <CardTitle className="truncate">{user.display_name}</CardTitle>
                  <CardDescription className="mt-1 flex flex-wrap gap-2">
                    {user.user_type && <Badge variant="outline">{user.user_type}</Badge>}
                    {user.gender && <Badge variant="outline">{user.gender}</Badge>}
                    {user.interest_match_score !== null && <Badge variant="outline">{user.interest_match_score}% interest match</Badge>}
                    {user.matching_interests_count !== null && user.matching_interests_count > 0 && (
                      <Badge variant="outline">{user.matching_interests_count} shared</Badge>
                    )}
                    {user.restricted && <Badge variant="outline">Private</Badge>}
                  </CardDescription>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              <a
                className="text-sm font-medium underline underline-offset-4"
                href={safeInternalUrl(`/users/${user.id}`) ?? '#'}
              >
                View profile
              </a>
            </CardContent>
          </Card>
        ))}
        {filtered.length === 0 && (
          <Card className="md:col-span-2 lg:col-span-3">
            <CardHeader>
              <CardTitle>{query.trim() ? 'No matching users yet' : 'No one to browse yet'}</CardTitle>
              <CardDescription>
                {query.trim()
                  ? `We could not find anyone named “${query.trim()}”. Try a different search or clear the filter.`
                  : 'When more approved members join, this page will sort them by how closely their interests match yours.'}
              </CardDescription>
            </CardHeader>
            <CardContent className="text-sm text-muted-foreground">
              Add interests on your profile to make future recommendations more relevant.
            </CardContent>
          </Card>
        )}
      </div>

      {/* Personas stand apart from people: following the work, not the person.
          Only personas opted into discovery are listed here — the cards never
          reference the human behind them. */}
      {filteredPersonas.length > 0 && (
        <section aria-label="Personas" className="space-y-3">
          <div>
            <h2 className="text-lg font-semibold">Personas</h2>
            <p className="text-sm text-muted-foreground">Characters made by members, with pages of their own.</p>
          </div>
          <PersonaGrid items={filteredPersonas} />
        </section>
      )}
    </div>
  );
}

const mountEl = document.getElementById('follow-directory');
if (mountEl) createRoot(mountEl).render(<FollowDirectoryPage />);
