import { MapPin } from 'lucide-react';
import { useEffect, useId, useState } from 'react';

import { Avatar } from '@/components/avatar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import { safeInternalUrl } from '@/security/dom-url';

import { type VisibleLocation } from './types';

interface NearbyPerson {
  id: number;
  display_name: string;
  avatar_url?: string | null;
  user_type: string | null;
  gender: string | null;
  location: VisibleLocation;
  same_city: boolean;
  /** Coarse distance band between city centres; null for the same city. */
  within_km: number | null;
}

interface NearbyResponse {
  data?: { has_location: boolean; radius_km: number; people: NearbyPerson[] };
}

type LoadState = 'loading' | 'ready' | 'error';

export const NEARBY_RADII_KM = [25, 50, 100, 250] as const;

function distanceLabel(person: NearbyPerson): string {
  return person.same_city ? 'In your city' : `Within ${person.within_km} km`;
}

/** People near the viewer's own city, as far as each person's city audience allows. */
export function NearbyPeople() {
  const radiusId = useId();
  const [radius, setRadius] = useState<number>(50);
  const [state, setState] = useState<LoadState>('loading');
  const [hasLocation, setHasLocation] = useState(true);
  const [people, setPeople] = useState<NearbyPerson[]>([]);

  useEffect(() => {
    let active = true;
    setState('loading');
    fetchWrapper.get(`/api/users/nearby?radius=${radius}`)
      .then((response: NearbyResponse) => {
        if (!active) return;
        setHasLocation(response.data?.has_location ?? false);
        setPeople(response.data?.people ?? []);
        setState('ready');
      })
      .catch(() => { if (active) setState('error'); });
    return () => { active = false; };
  }, [radius]);

  return (
    <section aria-labelledby={`${radiusId}-heading`} className="space-y-3">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 id={`${radiusId}-heading`} className="flex items-center gap-2 text-lg font-semibold">
            <MapPin className="h-4 w-4" aria-hidden="true" /> Near me
          </h2>
          <p className="text-sm text-muted-foreground">People who share their city with you, nearest first. Distances are between city centres.</p>
        </div>
        {hasLocation && (
          <div className="grid gap-1">
            <Label htmlFor={radiusId}>Distance</Label>
            <select
              id={radiusId}
              className="h-9 rounded-md border border-input bg-background px-2 text-sm"
              value={radius}
              onChange={(event) => setRadius(Number(event.target.value))}
            >
              {NEARBY_RADII_KM.map((km) => <option key={km} value={km}>Within {km} km</option>)}
            </select>
          </div>
        )}
      </div>

      {state === 'loading' && <p className="text-sm text-muted-foreground" role="status">Looking for people near you…</p>}
      {state === 'error' && <p className="text-sm text-destructive" role="alert">People near you could not be loaded.</p>}
      {state === 'ready' && !hasLocation && (
        <Card>
          <CardHeader>
            <CardTitle>Set your city to see people near you</CardTitle>
            <CardDescription>Your city is optional and only the people you choose can see it.</CardDescription>
          </CardHeader>
          <CardContent>
            <a className="text-sm font-medium underline underline-offset-4" href="/me">Add your city on your profile</a>
          </CardContent>
        </Card>
      )}
      {state === 'ready' && hasLocation && people.length === 0 && (
        <p className="text-sm text-muted-foreground">No one within {radius} km shares their city with you yet.</p>
      )}
      {state === 'ready' && people.length > 0 && (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {people.map((person) => (
            <Card key={person.id}>
              <CardHeader>
                <div className="flex items-center gap-3">
                  <Avatar name={person.display_name} src={person.avatar_url} sizeClassName="h-10 w-10" />
                  <div className="min-w-0">
                    <CardTitle className="truncate">{person.display_name}</CardTitle>
                    <CardDescription className="mt-1 flex flex-wrap gap-2">
                      <Badge variant="outline">{distanceLabel(person)}</Badge>
                      {person.user_type && <Badge variant="outline">{person.user_type}</Badge>}
                      {person.gender && <Badge variant="outline">{person.gender}</Badge>}
                    </CardDescription>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="space-y-2">
                <p className="text-sm text-muted-foreground">{person.location.label}</p>
                <a className="text-sm font-medium underline underline-offset-4" href={safeInternalUrl(`/users/${person.id}`) ?? '#'}>
                  View profile
                </a>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </section>
  );
}
