import { useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Avatar } from '@/components/avatar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';

interface Interest { id: number; name: string; }
interface FollowRequestState { status: string; can_retry: boolean; }
interface ProfileData { id: number; display_name: string; avatar_url?: string | null; restricted: boolean; user_type: string | null; gender: string | null; mutual_interests: Interest[]; follow_request: FollowRequestState | null; can_follow_back: boolean; }
interface ProfileResponse { success: boolean; data: ProfileData; }

function getInitialProfile(): ProfileData | null {
  return readInitialData<{ followProfile?: ProfileData }>().followProfile ?? null;
}

function FollowProfilePage() {
  // The page is server-hydrated (see FollowController::profilePage), so the
  // initial render needs no AJAX. The endpoint is only re-hit to refresh state
  // after the viewer acts.
  const [profile, setProfile] = useState<ProfileData | null>(getInitialProfile);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const userId = profile?.id ?? null;

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

  if (!profile) return <div className="mx-auto max-w-3xl px-4 py-8">Loading profile...</div>;
  const hasActiveRequest = profile.follow_request !== null && !profile.follow_request.can_retry;

  return (
    <div className="mx-auto max-w-3xl space-y-6 px-4 py-8">
      <a className="text-sm underline underline-offset-4" href="/users">← Browse users</a>
      {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
      {message && <Alert><AlertDescription>{message}</AlertDescription></Alert>}
      <Card>
        <CardHeader>
          <div className="flex items-center gap-4">
            <Avatar name={profile.display_name} src={profile.avatar_url} sizeClassName="h-14 w-14" />
            <div className="min-w-0">
              <CardTitle className="truncate">{profile.display_name}</CardTitle>
              <CardDescription className="mt-1 flex flex-wrap gap-2">
                {profile.user_type && <Badge variant="outline">{profile.user_type}</Badge>}
                {profile.gender && <Badge variant="outline">{profile.gender}</Badge>}
              </CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent className="space-y-5">
          {profile.restricted ? (
            <section>
              <h2 className="font-semibold">This profile is private</h2>
              <p className="text-sm text-muted-foreground">Send a follow request to see more.</p>
            </section>
          ) : (
            <section>
              <h2 className="font-semibold">Mutual interests</h2>
              <p className="text-sm text-muted-foreground">Only interests you both have are shown by default.</p>
              <div className="mt-3 flex flex-wrap gap-2">
                {profile.mutual_interests.length === 0 ? <span className="text-sm text-muted-foreground">No mutual interests yet.</span> : profile.mutual_interests.map((interest) => <Badge key={interest.id}>{interest.name}</Badge>)}
              </div>
            </section>
          )}
          {hasActiveRequest ? (
            <p className="text-sm">Follow request status: <strong>{profile.follow_request?.status}</strong></p>
          ) : (
            <Button onClick={() => void sendRequest()}>{profile.can_follow_back ? 'Follow back' : 'Send follow request'}</Button>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

const mountEl = document.getElementById('follow-profile');
if (mountEl) createRoot(mountEl).render(<FollowProfilePage />);
