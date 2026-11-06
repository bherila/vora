import { LoginForm } from 'bwh-auth';
import { useState } from 'react';
import { createRoot } from 'react-dom/client';

import { READING_PAGE_WIDTH } from '@/components/page-width';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

import { EventJoiningNotice } from './event-joining';
import { getAuthComponents, getCsrfToken } from './shared-components';

export function LoginPage() {
  const [error, setError] = useState('');
  const supportsPasskeys = typeof window !== 'undefined' && !!window.PublicKeyCredential;

  return (
    <div className={`${READING_PAGE_WIDTH} flex min-h-screen flex-col items-center justify-center p-4`}>
      <Card className="w-full max-w-md">
        <CardHeader className="space-y-1 text-center">
          <CardTitle className="text-2xl font-bold">Sign In</CardTitle>
          <CardDescription>Enter your credentials to access your account</CardDescription>
        </CardHeader>
        <CardContent>
          <EventJoiningNotice stage="sign-in" />
          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          <LoginForm
            unwrapped
            components={getAuthComponents()}
            endpoints={{ login: '/api/auth/login', csrfToken: getCsrfToken() }}
            enablePasskeys={supportsPasskeys}
            enablePasskeyAutofill={false}
            rememberMeLabel="Remember me"
            onSubmitStart={() => setError('')}
            onError={setError}
            onTwoFactorRequired={(result) => {
              if (result.attempt_token) {
                window.location.replace(`/login/two-factor/${encodeURIComponent(result.attempt_token)}`);
              } else {
                setError(result.message || 'Login failed. Please try again.');
              }
            }}
            onSuccess={(result) => {
              if (result.success) window.location.replace(result.redirect || '/');
              else setError(result.message || 'Login failed. Please try again.');
            }}
            onPasskeySuccess={(redirectUrl) => window.location.replace(redirectUrl)}
            passkeySeparator={(
              <div className="relative mb-4">
                <div className="absolute inset-0 flex items-center"><span className="w-full border-t" /></div>
                <div className="relative flex justify-center text-xs uppercase">
                  <span className="bg-card px-2 text-muted-foreground">Or continue with</span>
                </div>
              </div>
            )}
          />

          <div className="mt-6 space-y-2 text-center text-sm">
            <div>
              <a href="/forgot-password" className="text-primary hover:underline">
                Forgot your password?
              </a>
            </div>
            <div>
              Don&apos;t have an account?{' '}
              <a href="/register" className="text-primary hover:underline">
                Sign up
              </a>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

const mountEl = document.getElementById('login');
if (mountEl) {
  createRoot(mountEl).render(<LoginPage />);
}
