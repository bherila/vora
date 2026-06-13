import { createRoot } from 'react-dom/client';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { fetchWrapper } from '@/fetchWrapper';

function PendingApprovalPage() {
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
            Your account is pending admin approval. You&apos;ll be notified once approved.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <p className="text-center text-sm text-muted-foreground">
            Thank you for signing up! Our team will review your account shortly.
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

const mountEl = document.getElementById('pending-approval');
if (mountEl) {
  createRoot(mountEl).render(<PendingApprovalPage />);
}
