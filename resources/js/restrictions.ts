import { readInitialData } from '@/initialData';

export type RestrictionCapability = 'media.upload' | 'media.view' | 'comment.create';

export interface ActiveRestriction {
  capability: RestrictionCapability;
  label: string;
  reason: string | null;
  expires_at: string | null;
}

interface RestrictionsInitialData {
  restrictions?: ActiveRestriction[];
}

export function activeRestrictions(): ActiveRestriction[] {
  return readInitialData<RestrictionsInitialData>().restrictions ?? [];
}

export function activeRestriction(capability: RestrictionCapability): ActiveRestriction | null {
  return activeRestrictions().find((restriction) => restriction.capability === capability) ?? null;
}

export function restrictionDescription(restriction: ActiveRestriction): string {
  const details: string[] = [];
  if (restriction.reason) details.push(`Reason: ${restriction.reason}`);
  if (restriction.expires_at) {
    details.push(`Expires ${new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(restriction.expires_at))}`);
  }

  return details.join(' · ');
}
