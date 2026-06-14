import { createRoot } from 'react-dom/client';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { fetchWrapper } from '@/fetchWrapper';

const mountEl = document.getElementById('pending-approval');

function PendingApprovalPage() {
  const querySource = typeof window === 'undefined'
    ? ''
    : new URLSearchParams(window.location.search).get('source') || '';
  const dataSource = typeof mountEl === 'object' && mountEl !== null
    ? mountEl.getAttribute('data-source')
    : '';
  const source = querySource || dataSource || '';
  const sourceMessage = source === 'login'
    ? 'If you just logged in, your account is still waiting for admin approval.'
    : 'Your account is waiting for admin approval.';

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
          <CardTitle className="text-2xl font-bold">Account Pending Approval</CardTitle>
          <CardDescription>
            Your account is pending admin approval.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <p className="text-center text-sm text-muted-foreground">
            {sourceMessage} Our team will review your account and notify you once approved.
          </p>
          <p className="text-center text-sm text-muted-foreground">
            ID verification will be required to verify your age before age-restricted content is available.
          </p>
          <Button
            type="button"
            variant="outline"
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

if (mountEl) {
  createRoot(mountEl).render(<PendingApprovalPage />);
}
