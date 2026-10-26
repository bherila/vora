import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { ageVerificationMessage, getAgePolicy } from '@/auth/age-policy';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';

import { EventJoiningNotice, getEventJoiningUrl } from './event-joining';

interface VerificationStatus {
  email: string;
  email_locked: boolean;
  verified: boolean;
  delivery: { status: 'sent' | 'deferred' | 'delivered' | 'bounced' | 'blocked' | 'failed' | 'complained'; sent_at: string } | null;
}

/** Provider reports usually land within seconds; stop asking after about three minutes. */
export const STATUS_POLL_MS = 10_000;
const STATUS_POLL_LIMIT = 18;
const UNDELIVERABLE = ['bounced', 'blocked', 'failed'];

export function VerifyEmailPage() {
  const joiningUrl = getEventJoiningUrl();
  const initialStatus = typeof window === 'undefined'
    ? ''
    : window.location.search.includes('signup_status=pending-approval')
      ? `Thank you for signing up. After you verify your email, your account will be reviewed by an admin. ${ageVerificationMessage(getAgePolicy())}`
      : '';
  const [status, setStatus] = useState(initialStatus);
  const [loading, setLoading] = useState(false);
  const [verification, setVerification] = useState<VerificationStatus | null>(null);
  const [pollRound, setPollRound] = useState(0);
  const [newEmail, setNewEmail] = useState('');
  const [changing, setChanging] = useState(false);
  const [changeError, setChangeError] = useState('');
  const attempts = useRef(0);

  const loadStatus = useCallback(async (): Promise<VerificationStatus | null> => {
    try {
      const response = await fetchWrapper.get('/email/verification-status') as { data: VerificationStatus };
      setVerification(response.data);
      return response.data;
    } catch {
      return null;
    }
  }, []);

  // Watch for a delivery report until it settles, restarting after each resend or address change.
  useEffect(() => {
    let active = true;
    let timer: number | undefined;
    attempts.current = 0;
    const tick = async (): Promise<void> => {
      const current = await loadStatus();
      if (!active) return;
      const settled = current !== null && (current.verified || (current.delivery !== null && !['sent', 'deferred'].includes(current.delivery.status)));
      if (!settled && attempts.current < STATUS_POLL_LIMIT) {
        attempts.current += 1;
        timer = window.setTimeout(() => void tick(), STATUS_POLL_MS);
      }
    };
    void tick();
    return () => { active = false; window.clearTimeout(timer); };
  }, [loadStatus, pollRound]);

  const handleChangeEmail = async (e: FormEvent) => {
    e.preventDefault();
    setChanging(true);
    setChangeError('');
    try {
      await fetchWrapper.patch('/api/account', { email: newEmail.trim() });
      setNewEmail('');
      setStatus(`Verification link sent to ${newEmail.trim()}.`);
      setPollRound((round) => round + 1);
    } catch (failure) {
      setChangeError(typeof failure === 'string' ? failure : 'That email address could not be saved.');
    } finally {
      setChanging(false);
    }
  };
  const undeliverable = verification?.delivery !== null && verification?.delivery !== undefined && UNDELIVERABLE.includes(verification.delivery.status);
  const deferred = verification?.delivery?.status === 'deferred';

  const handleResend = async (e: FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setStatus('');

    try {
      await fetchWrapper.post('/email/verification-notification', {});
      setStatus('Verification link sent! Please check your inbox.');
    } catch {
      setStatus('Verification link sent! Please check your inbox.');
    } finally {
      setLoading(false);
      setPollRound((round) => round + 1);
    }
  };

  const handleLogout = async () => {
    try {
      await fetchWrapper.post('/logout', {});
    } finally {
      window.location.href = joiningUrl ?? '/login';
    }
  };

  return (
    <div className="flex min-h-screen flex-col items-center justify-center p-4">
      <Card className="w-full max-w-md">
        <CardHeader className="space-y-1 text-center">
          <CardTitle className="text-2xl font-bold">Verify Your Email</CardTitle>
          <CardDescription>
            We&apos;ve sent a verification link to your email address. Please click the link to
            activate your account.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <EventJoiningNotice stage="verify" />
          {verification && <p className="text-center text-sm">Sent to <strong className="break-all">{verification.email}</strong></p>}
          {undeliverable && verification && (
            <Alert variant="destructive">
              <AlertDescription>
                We couldn&apos;t deliver the verification email to <strong className="break-all">{verification.email}</strong>. The address may be mistyped, or that mailbox is rejecting mail.
                {verification.email_locked && ' This address is locked to your invitation; contact the organizer.'}
              </AlertDescription>
            </Alert>
          )}
          {deferred && verification && (
            <p role="status" className="text-sm text-muted-foreground">The receiving mail server is delaying delivery to {verification.email}. It may still arrive; check spam too.</p>
          )}
          {undeliverable && verification && !verification.email_locked && (
            <form onSubmit={(e) => void handleChangeEmail(e)} className="space-y-2">
              <Label htmlFor="replacement-email">Use a different email address</Label>
              <Input id="replacement-email" type="email" required autoComplete="email" maxLength={255} value={newEmail} onChange={(event) => setNewEmail(event.target.value)} />
              {changeError && <p role="alert" className="text-sm text-destructive">{changeError}</p>}
              <Button type="submit" className="w-full" disabled={changing || newEmail.trim() === ''}>{changing ? 'Saving…' : 'Save and resend'}</Button>
            </form>
          )}
          {status && (
            <Alert>
              <AlertDescription>{status}</AlertDescription>
            </Alert>
          )}

          <p className="text-center text-sm text-muted-foreground">
            Check your inbox and spam folder for the verification email.
          </p>

          <Button asChild className="w-full"><a href="/email/verify">I&apos;ve verified my email — continue</a></Button>

          <form onSubmit={(e) => void handleResend(e)}>
            <Button type="submit" variant="outline" className="w-full" disabled={loading}>
              {loading ? 'Sending...' : 'Resend Verification Email'}
            </Button>
          </form>

          <Button
            type="button"
            variant="ghost"
            className="w-full"
            onClick={() => void handleLogout()}
          >
            Log out
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}

const mountEl = document.getElementById('verify-email');
if (mountEl) {
  createRoot(mountEl).render(<VerifyEmailPage />);
}
