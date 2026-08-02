import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { FeedView } from '@/community/FeedView';
import type { OnboardingData } from '@/community/OnboardingChecklist';
import type { InterestRef } from '@/community/types';
import { SideRailLayout } from '@/components/app-side-rail';
import { BROWSING_PAGE_WIDTH } from '@/components/page-width';
import { readInitialData } from '@/initialData';

export function FeedPage() {
  const initialData = readInitialData<{
    feedHasFollowing?: boolean;
    feedOnboarding?: OnboardingData | null;
    feedInterests?: InterestRef[];
  }>();
  const onboarding = initialData.feedOnboarding ?? null;

  return (
    <div className={`${BROWSING_PAGE_WIDTH} px-4`}>
      <SideRailLayout>
        <div className="mx-auto max-w-3xl space-y-6">
          <div>
            <h1 className="text-2xl font-bold">Feed</h1>
            <p className="text-muted-foreground">Posts shared with you.</p>
          </div>
          <FeedView
            hasFollowing={initialData.feedHasFollowing ?? false}
            onboarding={onboarding}
            interests={initialData.feedInterests ?? []}
          />
        </div>
      </SideRailLayout>
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('feed');
if (mountEl) {
  createRoot(mountEl).render(<FeedPage />);
}
