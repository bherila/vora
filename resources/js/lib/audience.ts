// Single source of truth for the content privacy "audience" vocabulary, shared
// by media and stories so the selector options cannot drift between surfaces.
// Mirrors App\Enums\Audience on the backend.

export type Audience = 'everyone' | 'followers' | 'mutuals' | 'specific';

export interface AudienceOption {
  value: Audience;
  label: string;
}

export const AUDIENCE_SELECT_OPTIONS: AudienceOption[] = [
  { value: 'everyone', label: 'Everyone' },
  { value: 'followers', label: 'Followers' },
  { value: 'mutuals', label: 'Mutuals (people you follow back)' },
];

export const AUDIENCE_WITH_SPECIFIC_OPTIONS: AudienceOption[] = [
  ...AUDIENCE_SELECT_OPTIONS,
  { value: 'specific', label: 'Specific people' },
];
