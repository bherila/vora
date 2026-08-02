import { type FormEvent, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { READING_PAGE_WIDTH } from '@/components/page-width';
import { RestrictionNotice } from '@/components/restriction-notice';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';
import type { ActiveRestriction } from '@/restrictions';

interface RestrictionAppealData {
  restrictions: ActiveRestriction[];
  appeal_message: string | null;
  appeal_at: string | null;
}

function getInitialData(): RestrictionAppealData {
  return readInitialData<{ restrictionAppeal?: RestrictionAppealData }>().restrictionAppeal ?? {
    restrictions: [],
    appeal_message: null,
    appeal_at: null,
  };
}

export function RestrictedAccountPage() {
  const initialData = getInitialData();
  const [message, setMessage] = useState(initialData.appeal_message ?? '');
  const [submitted, setSubmitted] = useState(initialData.appeal_at !== null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();
    if (!message.trim()) {
      setError('Please describe your appeal.');
      return;
    }

    setBusy(true);
    setError('');
    try {
      await fetchWrapper.post('/api/account/appeal', { message: message.trim() });
      setSubmitted(true);
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Could not submit your appeal.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className={`${READING_PAGE_WIDTH} space-y-4`}>
      <Card>
        <CardHeader>
          <CardTitle>Account restrictions</CardTitle>
          <CardDescription>These restrictions limit only the capabilities listed below. The rest of your account remains available.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {initialData.restrictions.map((restriction) => (
            <RestrictionNotice key={restriction.capability} restriction={restriction} showAppealLink={false} />
          ))}
          {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
          {submitted && <Alert><AlertDescription>Your appeal has been submitted. An administrator will review it.</AlertDescription></Alert>}
          <form className="space-y-2" onSubmit={(event) => void submit(event)}>
            <label className="text-sm font-medium" htmlFor="restriction-appeal">Appeal to the administrator</label>
            <Textarea id="restriction-appeal" rows={5} value={message} onChange={(event) => setMessage(event.target.value)} placeholder="Explain why the restriction should be lifted." />
            <div className="flex flex-wrap gap-2">
              <Button type="submit" disabled={busy}>{submitted ? 'Update appeal' : 'Submit appeal'}</Button>
              <Button type="button" variant="outline" asChild><a href="/">Back to the app</a></Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}

const mount = document.getElementById('restricted-account');
if (mount) createRoot(mount).render(<RestrictedAccountPage />);
