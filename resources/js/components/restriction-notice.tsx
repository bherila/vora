import { Alert, AlertDescription } from '@/components/ui/alert';
import type { ActiveRestriction } from '@/restrictions';
import { restrictionDescription } from '@/restrictions';

interface RestrictionNoticeProps {
  restriction: ActiveRestriction;
  showAppealLink?: boolean;
}

export function RestrictionNotice({ restriction, showAppealLink = true }: RestrictionNoticeProps) {
  const details = restrictionDescription(restriction);

  return (
    <Alert>
      <AlertDescription>
        <span className="font-medium">{restriction.label} restricted.</span>
        {details && <> {details}.</>}
        {showAppealLink && <> <a className="underline underline-offset-4" href="/account/restrictions">Review or appeal</a>.</>}
      </AlertDescription>
    </Alert>
  );
}
