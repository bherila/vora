import { PasswordResetRequestForm } from 'bwh-auth';
import { useState } from 'react';
import { createRoot } from 'react-dom/client';

import { READING_PAGE_WIDTH } from '@/components/page-width';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

import { getAuthComponents, getCsrfToken } from './shared-components';

export function ForgotPasswordPage() {
  const [sent, setSent] = useState(false);
  const acknowledgeRequest = () => setSent(true);

  return (
    <div className={`${READING_PAGE_WIDTH} flex min-h-screen flex-col items-center justify-center p-4`}>
      <Card className="w-full max-w-md">
        <CardHeader className="space-y-1 text-center">
          <CardTitle className="text-2xl font-bold">Reset Password</CardTitle>
          <CardDescription>
            Enter your email and we&apos;ll send you a link to reset your password.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {sent && (
            <Alert className="mb-4">
              <AlertDescription>If an account exists with this email, a password reset link has been sent.</AlertDescription>
            </Alert>
          )}

          {!sent ? (
            <PasswordResetRequestForm
              components={getAuthComponents()}
              endpoints={{ forgotPassword: '/api/auth/forgot-password', csrfToken: getCsrfToken() }}
              submitClassName="w-full"
              onSuccess={acknowledgeRequest}
              onError={acknowledgeRequest}
            />
          ) : (
            <div className="space-y-4 text-center">
              <p className="text-sm text-muted-foreground">Check your email for the reset link.</p>
              <Button
                variant="outline"
                className="w-full"
                onClick={() => {
                  setSent(false);
                }}
              >
                Try a different email
              </Button>
            </div>
          )}

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

const mountEl = document.getElementById('forgot-password');
if (mountEl) {
  createRoot(mountEl).render(<ForgotPasswordPage />);
}
