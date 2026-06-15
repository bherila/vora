import { fetchWrapper } from '@/fetchWrapper';
import type { RatableInterest } from '@/interests/interest-rating-list';

/**
 * Shared client for the unified interest-rating endpoints. Ratings are scoped to
 * (logged-in user, character). Pass `characterId === null` to target the user's
 * own profile, or a character id to target that character's overrides.
 */

interface InterestsResponse {
  success: boolean;
  inherit_interests?: boolean;
  data: RatableInterest[];
}

export interface RatingInput {
  interest_id: number;
  /** A null level clears the rating; any other value upserts it. */
  level: number | null;
}

export async function loadInterests(characterId: number | null): Promise<{ interests: RatableInterest[]; inherit: boolean }> {
  const query = characterId === null ? '' : `?character_id=${characterId}`;
  const response = await fetchWrapper.get(`/api/interests${query}`) as InterestsResponse;

  return { interests: response.data ?? [], inherit: response.inherit_interests ?? false };
}

export async function persistRatings(characterId: number | null, ratings: RatingInput[]): Promise<void> {
  await fetchWrapper.post('/api/interests/ratings', { character_id: characterId, ratings });
}

export async function setInterestInheritance(characterId: number, inherit: boolean): Promise<void> {
  await fetchWrapper.post('/api/interests/inherit', { character_id: characterId, inherit });
}
