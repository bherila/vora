// Single source of truth for the content privacy "audience" vocabulary, shared
// by media and stories so the selector options cannot drift between surfaces.
// Mirrors App\Enums\Audience on the backend.

export type Audience = 'everyone' | 'followers' | 'mutuals' | 'specific';

export interface AudienceOption {
  value: Audience;
  label: string;
}

// Audiences offered in the content selectors. "Specific people" is a valid
// backend audience but needs a user picker (a follow-up), so it is intentionally
// not yet offered here.
export const AUDIENCE_SELECT_OPTIONS: AudienceOption[] = [
  { value: 'everyone', label: 'Everyone' },
  { value: 'followers', label: 'Followers' },
  { value: 'mutuals', label: 'Mutuals (people you follow back)' },
];
