import { type FormEvent, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { fetchWrapper } from '@/fetchWrapper';

function VerifyEmailPage() {
  const initialStatus = typeof window === 'undefined'
    ? ''
    : window.location.search.includes('signup_status=pending-approval')
      ? 'Thank you for signing up. Your account is pending admin approval while we review it.'
      : '';
  const [status, setStatus] = useState(initialStatus);
  const [loading, setLoading] = useState(false);

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
    }
  };

  const handleLogout = async () => {
    try {
      await fetchWrapper.post('/logout', {});
    } finally {
      window.location.href = '/login';
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
          {status && (
            <Alert>
              <AlertDescription>{status}</AlertDescription>
            </Alert>
          )}

          <p className="text-center text-sm text-muted-foreground">
            Check your inbox and spam folder for the verification email.
          </p>

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
