import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { fetchWrapper } from '@/fetchWrapper';

interface FollowRequest { id: number; requester: { id: number; display_name: string; user_type: string | null; gender: string | null; }; created_at: string | null; }
interface InboxResponse { success: boolean; data: FollowRequest[]; }

function FollowRequestsPage() {
  const [requests, setRequests] = useState<FollowRequest[]>([]);
  const [message, setMessage] = useState('');

  const load = () => {
    fetchWrapper.get('/api/users/follow-requests').then((response) => setRequests((response as InboxResponse).data));
  };
  useEffect(load, []);

  const decide = async (id: number, action: 'accept' | 'decline') => {
    await fetchWrapper.post(`/api/users/follow-requests/${id}/${action}`, {});
    setMessage(action === 'accept' ? 'Follow request accepted. You can now follow back from their profile.' : 'Follow request declined.');
    load();
  };

  return (
    <div className="mx-auto max-w-4xl space-y-6 px-4 py-8">
      <h1 className="text-2xl font-bold">Follow request inbox</h1>
      {message && <p className="text-sm text-muted-foreground">{message}</p>}
      {requests.length === 0 && <p className="text-sm text-muted-foreground">No pending follow requests.</p>}
      <div className="space-y-4">
        {requests.map((request) => (
          <Card key={request.id}>
            <CardHeader>
              <CardTitle>{request.requester.display_name}</CardTitle>
              <CardDescription>Requested {request.created_at ? new Date(request.created_at).toLocaleString() : 'recently'}</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-wrap gap-3">
              <a className="text-sm font-medium underline underline-offset-4" href={`/users/${request.requester.id}`}>View profile</a>
              <Button size="sm" onClick={() => void decide(request.id, 'accept')}>Accept</Button>
              <Button size="sm" variant="outline" onClick={() => void decide(request.id, 'decline')}>Decline</Button>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}

const mountEl = document.getElementById('follow-requests');
if (mountEl) createRoot(mountEl).render(<FollowRequestsPage />);
