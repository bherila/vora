export type LocationAudience = 'nobody' | 'mutuals' | 'followers' | 'everyone';

/** A city from the server's local GeoNames copy. */
export interface PlaceOption {
  id: number;
  name: string;
  region: string | null;
  country_code: string;
  country_name: string;
  label: string;
}

/** The owner's own location settings. Accounts only — personas have none. */
export interface LocationSettings {
  place: PlaceOption | null;
  country_audience: LocationAudience;
  city_audience: LocationAudience;
}

/** What a viewer is allowed to see of someone's location. */
export interface VisibleLocation {
  level: 'city' | 'country';
  label: string;
  city: string | null;
  region: string | null;
  country_code: string;
  country_name: string;
}

/** Narrowest first; a city may never reach more people than its country. */
export const LOCATION_AUDIENCE_OPTIONS: ReadonlyArray<{ value: LocationAudience; label: string }> = [
  { value: 'nobody', label: 'Nobody' },
  { value: 'mutuals', label: 'Mutuals (people you follow back)' },
  { value: 'followers', label: 'People who follow you' },
  { value: 'everyone', label: 'Everyone' },
];

export function audienceRank(value: LocationAudience): number {
  return LOCATION_AUDIENCE_OPTIONS.findIndex((option) => option.value === value);
}

export function normalizeLocationAudience(value: unknown): LocationAudience {
  return value === 'mutuals' || value === 'followers' || value === 'everyone' ? value : 'nobody';
}

export function normalizeLocationSettings(value: unknown): LocationSettings {
  const raw = (value ?? {}) as Partial<LocationSettings>;
  return {
    place: raw.place ?? null,
    country_audience: normalizeLocationAudience(raw.country_audience),
    city_audience: normalizeLocationAudience(raw.city_audience),
  };
}
