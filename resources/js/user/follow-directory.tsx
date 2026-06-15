import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { fetchWrapper } from '@/fetchWrapper';

interface DirectoryUser { id: number; display_name: string; user_type: string | null; gender: string | null; }
interface DirectoryResponse { success: boolean; data: DirectoryUser[]; }

function FollowDirectoryPage() {
  const [users, setUsers] = useState<DirectoryUser[]>([]);
  const [error, setError] = useState('');

  useEffect(() => {
    fetchWrapper.get('/api/users')
      .then((response) => setUsers((response as DirectoryResponse).data))
      .catch(() => setError('Unable to load users.'));
  }, []);

  return (
    <div className="mx-auto max-w-4xl space-y-6 px-4 py-8">
      <div>
        <h1 className="text-2xl font-bold">Browse users</h1>
        <p className="text-sm text-muted-foreground">View profiles and request to follow users.</p>
      </div>
      {error && <p className="text-sm text-destructive">{error}</p>}
      <div className="grid gap-4 md:grid-cols-2">
        {users.map((user) => (
          <Card key={user.id}>
            <CardHeader>
              <CardTitle>{user.display_name}</CardTitle>
              <CardDescription className="flex gap-2">
                {user.user_type && <Badge variant="outline">{user.user_type}</Badge>}
                {user.gender && <Badge variant="outline">{user.gender}</Badge>}
              </CardDescription>
            </CardHeader>
            <CardContent>
              <a className="text-sm font-medium underline underline-offset-4" href={`/users/${user.id}`}>View profile</a>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}

const mountEl = document.getElementById('follow-directory');
if (mountEl) createRoot(mountEl).render(<FollowDirectoryPage />);
