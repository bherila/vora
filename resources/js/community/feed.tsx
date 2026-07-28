import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { FeedView } from '@/community/FeedView';
import type { OnboardingSteps } from '@/community/OnboardingChecklist';
import { readInitialData } from '@/initialData';

function FeedPage() {
  const onboarding = readInitialData<{ feedOnboarding?: OnboardingSteps | null }>().feedOnboarding ?? null;

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Feed</h1>
        <p className="text-muted-foreground">Posts from you and people you follow.</p>
      </div>
      <FeedView onboarding={onboarding} />
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('feed');
if (mountEl) {
  createRoot(mountEl).render(<FeedPage />);
}
