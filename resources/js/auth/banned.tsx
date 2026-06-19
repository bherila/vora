import { type FormEvent, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';

interface BannedInitialData {
  reason: string | null;
  appeal_message: string | null;
  appeal_at: string | null;
}

function getInitialData(): BannedInitialData {
  return readInitialData<{ banned?: BannedInitialData }>().banned ?? { reason: null, appeal_message: null, appeal_at: null };
}

function BannedPage() {
  const initialData = getInitialData();
  const [message, setMessage] = useState(initialData.appeal_message ?? '');
  const [appealSubmitted, setAppealSubmitted] = useState(initialData.appeal_at !== null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);

  const submitAppeal = async (e: FormEvent) => {
    e.preventDefault();
    setError('');
    setNotice('');

    if (!message.trim()) {
      setError('Please describe your appeal.');
      return;
    }

    setBusy(true);
    try {
      const response = await fetchWrapper.post('/api/account/appeal', { message: message.trim() });
      if (response.success) {
        setAppealSubmitted(true);
        setNotice('Your appeal has been submitted. An administrator will review it.');
      } else {
        setError(response.message || 'Could not submit your appeal.');
      }
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Could not submit your appeal.');
    } finally {
      setBusy(false);
    }
  };

  const deactivate = async () => {
    setError('');
    setBusy(true);
    try {
      const response = await fetchWrapper.post('/api/account/deactivate', {});
      if (response.success) {
        window.location.href = '/account/deactivated';
      } else {
        setError(response.message || 'Could not deactivate your account.');
      }
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Could not deactivate your account.');
    } finally {
      setBusy(false);
    }
  };

  const deleteAccount = async () => {
    setError('');
    if (!window.confirm('Permanently delete your account? This cannot be undone by you.')) {
      return;
    }

    setBusy(true);
    try {
      const response = await fetchWrapper.post('/api/account/delete', {});
      if (response.success) {
        window.location.href = '/';
      } else {
        setError(response.message || 'Could not delete your account.');
      }
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Could not delete your account.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex min-h-screen flex-col items-center justify-center p-4">
      <Card className="w-full max-w-md">
        <CardHeader className="space-y-1 text-center">
          <CardTitle className="text-2xl font-bold">Your account is banned</CardTitle>
          <CardDescription>
            You can still sign in, but you cannot use the app while banned. You may appeal, deactivate, or delete
            your account.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {initialData.reason && (
            <Alert>
              <AlertDescription>
                <span className="font-medium">Reason:</span> {initialData.reason}
              </AlertDescription>
            </Alert>
          )}

          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {notice && (
            <Alert>
              <AlertDescription>{notice}</AlertDescription>
            </Alert>
          )}

          <form onSubmit={(e) => void submitAppeal(e)} className="space-y-2">
            <label htmlFor="appeal" className="text-sm font-medium">
              Appeal to the administrator
            </label>
            <Textarea
              id="appeal"
              value={message}
              onChange={(e) => setMessage(e.target.value)}
              rows={5}
              placeholder="Explain why your account should be reinstated."
            />
            <Button type="submit" className="w-full" disabled={busy}>
              {appealSubmitted ? 'Update appeal' : 'Submit appeal'}
            </Button>
          </form>

          <div className="border-t pt-4">
            <p className="mb-3 text-sm text-muted-foreground">
              You can also remove your account from public view or delete it entirely.
            </p>
            <div className="flex flex-col gap-2">
              <Button type="button" variant="outline" onClick={() => void deactivate()} disabled={busy}>
                Deactivate account
              </Button>
              <Button type="button" variant="destructive" onClick={() => void deleteAccount()} disabled={busy}>
                Delete account
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

const mountEl = document.getElementById('banned');
if (mountEl) {
  createRoot(mountEl).render(<BannedPage />);
}
