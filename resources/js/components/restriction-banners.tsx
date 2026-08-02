import { BROWSING_PAGE_WIDTH } from '@/components/page-width';
import { RestrictionNotice } from '@/components/restriction-notice';
import type { ActiveRestriction } from '@/restrictions';

interface RestrictionBannersProps {
  restrictions: ActiveRestriction[];
}

export function RestrictionBanners({ restrictions }: RestrictionBannersProps) {
  if (restrictions.length === 0) return null;

  return (
    <div className={`${BROWSING_PAGE_WIDTH} space-y-2 px-4 pt-4 sm:px-6 lg:px-8`}>
      {restrictions.map((restriction) => (
        <RestrictionNotice key={restriction.capability} restriction={restriction} />
      ))}
    </div>
  );
}
