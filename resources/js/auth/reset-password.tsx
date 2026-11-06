import { ResetPasswordForm } from 'bwh-auth';
import { useState } from 'react';
import { createRoot } from 'react-dom/client';

import { READING_PAGE_WIDTH } from '@/components/page-width';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

import { getAuthComponents, getCsrfToken } from './shared-components';

interface ResetPasswordPageProps {
  token: string;
  email: string;
}

export function ResetPasswordPage({ token, email }: ResetPasswordPageProps) {
  const [error, setError] = useState('');

  return (
    <div className={`${READING_PAGE_WIDTH} flex min-h-screen flex-col items-center justify-center p-4`}>
      <Card className="w-full max-w-md">
        <CardHeader className="space-y-1 text-center">
          <CardTitle className="text-2xl font-bold">Reset Password</CardTitle>
          <CardDescription>Enter your new password below.</CardDescription>
        </CardHeader>
        <CardContent>
          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          <ResetPasswordForm
            components={getAuthComponents()}
            endpoints={{ resetPassword: '/api/auth/reset-password', csrfToken: getCsrfToken() }}
            token={token}
            email={email}
            minPasswordLength={8}
            validatePasswordConfirmation
            passwordLabel="New Password"
            passwordConfirmationLabel="Confirm New Password"
            passwordHelpText="Must be at least 8 characters long"
            submitClassName="w-full"
            onSubmitStart={() => setError('')}
            onError={setError}
            onSuccess={(result) => {
              if (result.success) window.location.href = result.redirect || '/login';
              else setError(result.message || 'Password reset failed. Please try again.');
            }}
          />

          <div className="mt-6 text-center text-sm">
            <a href="/login" className="text-primary hover:underline">
              Back to Sign In
            </a>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

const mountEl = document.getElementById('reset-password');
if (mountEl) {
  const token = mountEl.dataset.token ?? '';
  const email = mountEl.dataset.email ?? '';
  createRoot(mountEl).render(<ResetPasswordPage token={token} email={email} />);
}
