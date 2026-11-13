import { MapPin } from 'lucide-react';

import { LocationEditor } from './LocationEditor';
import { type LocationSettings, normalizeLocationSettings, type VisibleLocation } from './types';

/** The owner's settings from the profile bootstrap payload, if editable. */
export function initialLocationSettings(value: unknown): LocationSettings | null {
  return value ? normalizeLocationSettings(value) : null;
}

/** What the owner sees of their own location after saving: always the city. */
export function visibleLocationFromSettings(settings: LocationSettings): VisibleLocation | null {
  const place = settings.place;
  return place ? {
    level: 'city',
    label: place.label,
    city: place.name,
    region: place.region,
    country_code: place.country_code,
    country_name: place.country_name,
  } : null;
}

interface ProfileLocationLineProps {
  location?: VisibleLocation | null | undefined;
}

/** The location line under a profile's name, at the level the viewer may see. */
export function ProfileLocationLine({ location }: ProfileLocationLineProps) {
  if (!location) return null;
  return (
    <p className="mt-1 flex items-center gap-1 text-sm text-muted-foreground" data-testid="profile-location">
      <MapPin className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
      <span className="sr-only">Location: </span>{location.label}
    </p>
  );
}

interface ProfileLocationSectionProps {
  settings: LocationSettings;
  onSaved: (settings: LocationSettings) => void;
  onSavingChange?: (saving: boolean) => void;
}

/** The location section of the Edit profile dialog. */
export function ProfileLocationSection({ settings, onSaved, onSavingChange }: ProfileLocationSectionProps) {
  return (
    <div className="border-t border-border pt-4">
      <LocationEditor initial={settings} onSaved={onSaved} onSavingChange={onSavingChange} />
    </div>
  );
}
