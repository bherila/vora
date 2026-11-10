import { type FormEvent, useId, useState } from 'react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';

import { PlacePicker } from './PlacePicker';
import {
  audienceRank,
  LOCATION_AUDIENCE_OPTIONS,
  type LocationAudience,
  type LocationSettings,
  normalizeLocationSettings,
} from './types';

interface LocationEditorProps {
  initial: LocationSettings;
  onSaved?: (settings: LocationSettings) => void;
}

interface LocationUpdateResponse { success: boolean; message?: string; data?: LocationSettings }

/** The account's optional city and who may see it at each level. */
export function LocationEditor({ initial, onSaved }: LocationEditorProps) {
  const countryId = useId();
  const cityId = useId();
  const [place, setPlace] = useState(initial.place);
  const [countryAudience, setCountryAudience] = useState<LocationAudience>(initial.country_audience);
  const [cityAudience, setCityAudience] = useState<LocationAudience>(initial.city_audience);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  function changeCountryAudience(next: LocationAudience) {
    setCountryAudience(next);
    // Narrowing the country narrows the city with it.
    if (audienceRank(cityAudience) > audienceRank(next)) setCityAudience(next);
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setMessage('');
    setError('');
    try {
      const response = await fetchWrapper.put('/api/account/location', {
        place_id: place?.id ?? null,
        country_audience: countryAudience,
        city_audience: cityAudience,
      }) as LocationUpdateResponse;
      const saved = normalizeLocationSettings(response.data);
      setPlace(saved.place);
      setCountryAudience(saved.country_audience);
      setCityAudience(saved.city_audience);
      setMessage(response.message ?? 'Location saved.');
      onSaved?.(saved);
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Your location could not be saved.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <form className="space-y-4" aria-label="Location" onSubmit={(event) => void submit(event)}>
      <div>
        <h3 className="font-medium">Location</h3>
        <p className="text-sm text-muted-foreground">Optional. Helps people near you find you. Applies to your account only — personas never show a location.</p>
      </div>
      {error && <Alert variant="destructive"><AlertDescription>{error}</AlertDescription></Alert>}
      {message && <Alert><AlertDescription>{message}</AlertDescription></Alert>}
      <PlacePicker value={place} onChange={setPlace} disabled={saving} />
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="grid gap-2">
          <Label htmlFor={countryId}>Who can see your country</Label>
          <select
            id={countryId}
            className="h-9 rounded-md border border-input bg-background px-2 text-sm"
            value={countryAudience}
            disabled={saving}
            onChange={(event) => changeCountryAudience(event.target.value as LocationAudience)}
          >
            {LOCATION_AUDIENCE_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
        </div>
        <div className="grid gap-2">
          <Label htmlFor={cityId}>Who can see your city</Label>
          <select
            id={cityId}
            className="h-9 rounded-md border border-input bg-background px-2 text-sm"
            value={cityAudience}
            disabled={saving}
            onChange={(event) => setCityAudience(event.target.value as LocationAudience)}
          >
            {LOCATION_AUDIENCE_OPTIONS.map((option) => (
              <option key={option.value} value={option.value} disabled={audienceRank(option.value) > audienceRank(countryAudience)}>
                {option.label}
              </option>
            ))}
          </select>
        </div>
      </div>
      <p className="text-sm text-muted-foreground">
        Your city can’t be shown to more people than your country. People who can see your city can find you in Near me, which shows distance only roughly.
      </p>
      <Button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save location'}</Button>
    </form>
  );
}
