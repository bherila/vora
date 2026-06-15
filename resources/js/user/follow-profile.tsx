import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { fetchWrapper } from '@/fetchWrapper';

interface Interest { id: number; name: string; }
interface ProfileData { id: number; display_name: string; user_type: string | null; gender: string | null; mutual_interests: Interest[]; follow_request: { status: string } | null; can_follow_back: boolean; }
interface ProfileResponse { success: boolean; data: ProfileData; }

function getUserId(): number | null {
  const script = document.getElementById('follow-profile-data');
  if (!script?.textContent) return null;
  try {
    const data = JSON.parse(script.textContent) as { userId?: unknown };
    return typeof data.userId === 'number' ? data.userId : null;
  } catch { return null; }
}

function FollowProfilePage() {
  const userId = getUserId();
  const [profile, setProfile] = useState<ProfileData | null>(null);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const loadProfile = () => {
    if (!userId) return;
    fetchWrapper.get(`/api/users/${userId}`)
      .then((response) => setProfile((response as ProfileResponse).data))
      .catch(() => setError('Unable to load profile.'));
  };

  useEffect(loadProfile, [userId]);

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
  const hasRequest = profile.follow_request !== null;

  return (
    <div className="mx-auto max-w-3xl space-y-6 px-4 py-8">
      <a className="text-sm underline underline-offset-4" href="/users">← Browse users</a>
      {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
      {message && <Alert><AlertDescription>{message}</AlertDescription></Alert>}
      <Card>
        <CardHeader>
          <CardTitle>{profile.display_name}</CardTitle>
          <CardDescription className="flex gap-2">
            {profile.user_type && <Badge variant="outline">{profile.user_type}</Badge>}
            {profile.gender && <Badge variant="outline">{profile.gender}</Badge>}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-5">
          <section>
            <h2 className="font-semibold">Mutual interests</h2>
            <p className="text-sm text-muted-foreground">Only interests you both have are shown by default.</p>
            <div className="mt-3 flex flex-wrap gap-2">
              {profile.mutual_interests.length === 0 ? <span className="text-sm text-muted-foreground">No mutual interests yet.</span> : profile.mutual_interests.map((interest) => <Badge key={interest.id}>{interest.name}</Badge>)}
            </div>
          </section>
          {hasRequest ? (
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
