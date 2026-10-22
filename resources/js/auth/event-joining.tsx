import { Alert, AlertDescription } from '@/components/ui/alert';
import { readInitialData } from '@/initialData';

interface EventJoinInitialData {
  eventJoin?: { continue_url?: string | null };
}

interface EventJoiningNoticeProps {
  stage: 'sign-in' | 'verify' | 'approval' | 'two-factor';
}

export function getEventJoiningUrl(): string | null {
  const url = readInitialData<EventJoinInitialData>().eventJoin?.continue_url;
  return typeof url === 'string' && /^\/events\/join\/[0-7][0-9A-HJKMNP-TV-Z]{25}$/.test(url) ? url : null;
}

export function EventJoiningNotice({ stage }: EventJoiningNoticeProps) {
  if (!getEventJoiningUrl()) return null;

  const message = stage === 'sign-in'
    ? 'You’re joining an Event. Your place will be kept while you sign in or create an account. If public sign-ups are closed, ask the organizer for a separate personal signup invitation; this Event link does not grant signup or restricted Event access.'
    : stage === 'verify'
      ? 'Your Event link is saved in this browser. Verify your email, then complete account approval if required to continue.'
      : stage === 'approval'
        ? 'Your Event link is saved in this browser. After approval, continue below. A joining link does not grant access to a restricted Event; its organizer must add you.'
        : 'Complete the security check to continue joining. Your Event link is saved in this browser.';

  return <Alert className="mb-4"><AlertDescription>{message}</AlertDescription></Alert>;
}
