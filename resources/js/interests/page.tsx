import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { FeedView } from '@/community/FeedView';
import type { InterestRef } from '@/community/types';
import { BROWSING_PAGE_WIDTH } from '@/components/page-width';
import { readInitialData } from '@/initialData';

export function InterestPage() {
  const data = readInitialData<{ interest: InterestRef; feedHasFollowing?: boolean }>();

  return (
    <div className={`${BROWSING_PAGE_WIDTH} space-y-6 px-4`}>
      <header>
        <h1 className="text-2xl font-bold">{data.interest.name}</h1>
        {data.interest.description && <p className="text-muted-foreground">{data.interest.description}</p>}
      </header>
      <div className="mx-auto max-w-3xl">
        <FeedView interest={data.interest} hasFollowing={data.feedHasFollowing ?? false} />
      </div>
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mount = document.getElementById('interest-page');
if (mount) createRoot(mount).render(<InterestPage />);
