import { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { DayOne, isDayOne } from '@/community/DayOne';
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
  const [onboarding, setOnboarding] = useState(initialData.feedOnboarding ?? null);
  const hasFollowing = initialData.feedHasFollowing ?? false;
  const [dayOne, setDayOne] = useState(() => isDayOne(onboarding, hasFollowing));
  const markPosted = (): void => {
    setOnboarding((current) => current && { ...current, steps: { ...current.steps, has_posted: true } });
    setDayOne(false);
  };

  // The first-run page carries its own follow suggestions, so the rail would
  // only repeat them; it returns with the ordinary feed.
  return (
    <div className={`${BROWSING_PAGE_WIDTH} px-4`}>
      <SideRailLayout enabled={!dayOne}>
        <div className="mx-auto max-w-3xl">
          {dayOne && onboarding ? (
            <div className="space-y-10">
              <DayOne onboarding={onboarding} onContinue={() => setDayOne(false)} onPosted={markPosted} />
              <section aria-labelledby="day-one-public" className="space-y-4">
                <h2 id="day-one-public" className="border-b pb-2 text-lg font-semibold">Public posts from everyone</h2>
                <FeedView hasFollowing={false} interests={initialData.feedInterests ?? []} showComposer={false} />
              </section>
            </div>
          ) : (
            <>
              <h1 className="sr-only">Feed</h1>
              <FeedView
                hasFollowing={hasFollowing}
                onboarding={onboarding}
                interests={initialData.feedInterests ?? []}
              />
            </>
          )}
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
