import { readInitialData } from '@/initialData';

export interface AgePolicy {
  minimum_age: number | null;
  age_verification_required: boolean;
  birth_date_required: boolean;
  latest_birth_date: string;
}

export function todayDate(): string {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function getAgePolicy(): AgePolicy {
  const policy = readInitialData<{ agePolicy?: AgePolicy }>().agePolicy;
  if (policy) return policy;
  const date = new Date();
  date.setFullYear(date.getFullYear() - 18);
  return {
    minimum_age: 18,
    age_verification_required: false,
    birth_date_required: true,
    latest_birth_date: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`,
  };
}

export function ageRequirementMessage(policy: AgePolicy): string {
  return policy.minimum_age === null
    ? 'Enter a birth date that is not in the future.'
    : `You must be at least ${policy.minimum_age} years old to join.`;
}

export function ageVerificationMessage(policy: AgePolicy): string {
  return policy.age_verification_required
    ? 'An administrator must confirm your age before you can access shared content. Contact the organizer to arrange confirmation; do not upload identity documents here.'
    : 'Manual age verification is not required by this site.';
}
