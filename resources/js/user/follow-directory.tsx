import { useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Avatar } from '@/components/avatar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { readInitialData } from '@/initialData';

interface DirectoryUser { id: number; display_name: string; avatar_url?: string | null; restricted: boolean; user_type: string | null; gender: string | null; }

function FollowDirectoryPage() {
  const [users] = useState<DirectoryUser[]>(() => readInitialData<{ followDirectory?: DirectoryUser[] }>().followDirectory ?? []);
  const [query, setQuery] = useState('');

  const filtered = useMemo(() => {
    const term = query.trim().toLowerCase();
    if (!term) return users;
    return users.filter((user) => user.display_name.toLowerCase().includes(term));
  }, [users, query]);

  return (
    <div className="mx-auto max-w-4xl space-y-6 px-4 py-8">
      <div>
        <h1 className="text-2xl font-bold">Browse users</h1>
        <p className="text-sm text-muted-foreground">View profiles and request to follow users.</p>
      </div>
      <Input
        type="search"
        value={query}
        onChange={(event) => setQuery(event.target.value)}
        placeholder="Search by name"
        aria-label="Search users by name"
      />
      <div className="grid gap-4 md:grid-cols-2">
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
                    {user.restricted && <Badge variant="outline">Private</Badge>}
                  </CardDescription>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              <a className="text-sm font-medium underline underline-offset-4" href={`/users/${user.id}`}>View profile</a>
            </CardContent>
          </Card>
        ))}
        {filtered.length === 0 && (
          <p className="text-sm text-muted-foreground">No users match “{query}”.</p>
        )}
      </div>
    </div>
  );
}

const mountEl = document.getElementById('follow-directory');
if (mountEl) createRoot(mountEl).render(<FollowDirectoryPage />);
