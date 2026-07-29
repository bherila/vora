import { useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';
import { safeInternalUrl } from '@/security/dom-url';

interface FollowRequest { id: number; requester: { id: number; display_name: string; restricted: boolean; user_type: string | null; gender: string | null; }; created_at: string | null; }
interface FollowInboxResponse { success: boolean; data: FollowRequest[]; }

interface AuthorshipInvite { id: number; story: { id: number; ulid: string; title: string; type: string }; invited_by: string | null; created_at: string | null; }
interface AuthorshipInboxResponse { success: boolean; data: AuthorshipInvite[]; }

function RequestsPage() {
  const initial = readInitialData<{ followRequests?: { requests?: FollowRequest[]; invites?: AuthorshipInvite[] } }>().followRequests;
  const [followRequests, setFollowRequests] = useState<FollowRequest[]>(initial?.requests ?? []);
  const [invites, setInvites] = useState<AuthorshipInvite[]>(initial?.invites ?? []);
  const [message, setMessage] = useState('');

  const load = (): void => {
    fetchWrapper.get('/api/users/follow-requests').then((r) => setFollowRequests((r as FollowInboxResponse).data));
    fetchWrapper.get('/api/authorship-invites').then((r) => setInvites((r as AuthorshipInboxResponse).data));
  };

  const decideFollow = async (id: number, action: 'accept' | 'decline'): Promise<void> => {
    await fetchWrapper.post(`/api/users/follow-requests/${id}/${action}`, {});
    setMessage(action === 'accept' ? 'Follow request accepted. You can now follow back from their profile.' : 'Follow request declined.');
    load();
  };

  const decideInvite = async (id: number, action: 'accept' | 'decline'): Promise<void> => {
    await fetchWrapper.post(`/api/authorship-invites/${id}/${action}`, {});
    setMessage(action === 'accept' ? 'Co-author invitation accepted. The story is now in your stories list.' : 'Co-author invitation declined.');
    load();
  };

  return (
    <div className="mx-auto max-w-4xl space-y-8 px-4 py-8">
      <h1 className="text-2xl font-bold">Requests</h1>
      {message && <p className="text-sm text-muted-foreground">{message}</p>}

      <section className="space-y-4">
        <h2 className="text-lg font-semibold">Follow requests</h2>
        {followRequests.length === 0 && <p className="text-sm text-muted-foreground">No pending follow requests.</p>}
        {followRequests.map((request) => (
          <Card key={request.id}>
            <CardHeader>
              <CardTitle>{request.requester.display_name}</CardTitle>
              <CardDescription>Requested {request.created_at ? new Date(request.created_at).toLocaleString() : 'recently'}</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-wrap gap-3">
              <a
                className="text-sm font-medium underline underline-offset-4"
                href={safeInternalUrl(`/users/${request.requester.id}`) ?? '#'}
              >
                View profile
              </a>
              <Button size="sm" onClick={() => void decideFollow(request.id, 'accept')}>Accept</Button>
              <Button size="sm" variant="outline" onClick={() => void decideFollow(request.id, 'decline')}>Decline</Button>
            </CardContent>
          </Card>
        ))}
      </section>

      <section className="space-y-4">
        <h2 className="text-lg font-semibold">Co-author invitations</h2>
        {invites.length === 0 && <p className="text-sm text-muted-foreground">No pending co-author invitations.</p>}
        {invites.map((invite) => (
          <Card key={invite.id}>
            <CardHeader>
              <CardTitle>{invite.story.title}</CardTitle>
              <CardDescription>
                {invite.invited_by ? `Invited by ${invite.invited_by}` : 'Invitation'} ·{' '}
                {invite.story.type === 'cyoa' ? 'Choose your own adventure' : 'Long form'}
              </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-wrap gap-3">
              <Button size="sm" onClick={() => void decideInvite(invite.id, 'accept')}>Accept</Button>
              <Button size="sm" variant="outline" onClick={() => void decideInvite(invite.id, 'decline')}>Decline</Button>
            </CardContent>
          </Card>
        ))}
      </section>
    </div>
  );
}

const mountEl = document.getElementById('follow-requests');
if (mountEl) createRoot(mountEl).render(<RequestsPage />);
