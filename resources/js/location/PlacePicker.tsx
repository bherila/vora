import { LocateFixed, X } from 'lucide-react';
import { type KeyboardEvent, useEffect, useId, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';

import { type PlaceOption } from './types';

interface PlacePickerProps {
  value: PlaceOption | null;
  onChange: (place: PlaceOption | null) => void;
  disabled?: boolean;
}

interface PlaceListResponse { data?: PlaceOption[] }
interface NearestPlaceResponse { data?: PlaceOption | null }

const SEARCH_DELAY_MS = 250;

/**
 * Coordinates are rounded to about 1 km before they leave the browser; the
 * server only uses them to find the nearest city and never stores them.
 */
function roundCoordinate(value: number): number {
  return Math.round(value * 100) / 100;
}

function geolocationMessage(error: GeolocationPositionError): string {
  if (error.code === error.PERMISSION_DENIED) return 'Location permission was denied. You can search for your city instead.';
  if (error.code === error.TIMEOUT) return 'Finding your location took too long. Try again or search for your city.';
  return 'Your location could not be found. Search for your city instead.';
}

/** City search over the server's local GeoNames copy, plus "use my location". */
export function PlacePicker({ value, onChange, disabled = false }: PlacePickerProps) {
  const inputId = useId();
  const listId = useId();
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<PlaceOption[]>([]);
  const [highlighted, setHighlighted] = useState(-1);
  const [searching, setSearching] = useState(false);
  const [locating, setLocating] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    const term = query.trim();
    if (term.length < 2) {
      setResults([]);
      setSearching(false);
      return;
    }
    let active = true;
    setSearching(true);
    const timer = window.setTimeout(() => {
      fetchWrapper.get(`/api/location/places?q=${encodeURIComponent(term)}`)
        .then((response: PlaceListResponse) => {
          if (!active) return;
          setResults(response.data ?? []);
          setHighlighted(-1);
        })
        .catch(() => { if (active) setResults([]); })
        .finally(() => { if (active) setSearching(false); });
    }, SEARCH_DELAY_MS);
    return () => { active = false; window.clearTimeout(timer); };
  }, [query]);

  function choose(place: PlaceOption) {
    onChange(place);
    setQuery('');
    setResults([]);
    setHighlighted(-1);
    setError('');
  }

  function onKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (results.length === 0) return;
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setHighlighted((index) => (index + 1) % results.length);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setHighlighted((index) => (index <= 0 ? results.length - 1 : index - 1));
    } else if (event.key === 'Enter' && results[highlighted]) {
      event.preventDefault();
      choose(results[highlighted]);
    } else if (event.key === 'Escape') {
      setResults([]);
      setHighlighted(-1);
    }
  }

  function useCurrentLocation() {
    if (!('geolocation' in navigator)) {
      setError('This browser cannot share your location. Search for your city instead.');
      return;
    }
    setError('');
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (position) => {
        fetchWrapper.post('/api/location/places/nearest', {
          latitude: roundCoordinate(position.coords.latitude),
          longitude: roundCoordinate(position.coords.longitude),
        })
          .then((response: NearestPlaceResponse) => {
            if (response.data) choose(response.data);
            else setError('No city was found near you. Search for your city instead.');
          })
          .catch(() => setError('Your city could not be looked up. Search for it instead.'))
          .finally(() => setLocating(false));
      },
      (positionError) => {
        setLocating(false);
        setError(geolocationMessage(positionError));
      },
      { enableHighAccuracy: false, timeout: 15000, maximumAge: 600000 },
    );
  }

  const showList = results.length > 0;
  const showNoResults = !searching && query.trim().length >= 2 && results.length === 0;

  return (
    <div className="space-y-2">
      <Label htmlFor={inputId}>City</Label>
      {value ? (
        <div className="flex items-center justify-between gap-2 rounded-md border border-input px-3 py-2 text-sm">
          <span data-testid="selected-place">{value.label}</span>
          <Button type="button" variant="ghost" size="sm" disabled={disabled} onClick={() => onChange(null)} aria-label="Remove city">
            <X className="h-4 w-4" aria-hidden="true" /> Remove
          </Button>
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">No city set.</p>
      )}
      <div className="relative">
        <Input
          id={inputId}
          type="search"
          role="combobox"
          aria-expanded={showList}
          aria-controls={listId}
          aria-autocomplete="list"
          aria-activedescendant={highlighted >= 0 ? `${listId}-${results[highlighted]?.id}` : undefined}
          placeholder={value ? 'Search to change your city' : 'Search for your city, e.g. Portland, Oregon'}
          value={query}
          disabled={disabled}
          autoComplete="off"
          onChange={(event) => setQuery(event.target.value)}
          onKeyDown={onKeyDown}
        />
        {showList && (
          <ul id={listId} role="listbox" aria-label="Matching cities" className="absolute z-50 mt-1 max-h-64 w-full overflow-auto rounded-md border border-border bg-popover p-1 text-sm shadow-md">
            {results.map((place, index) => (
              <li
                key={place.id}
                id={`${listId}-${place.id}`}
                role="option"
                aria-selected={index === highlighted}
                className={`cursor-pointer rounded px-2 py-1.5 ${index === highlighted ? 'bg-accent text-accent-foreground' : 'hover:bg-accent/60'}`}
                onMouseDown={(event) => { event.preventDefault(); choose(place); }}
              >
                {place.label}
              </li>
            ))}
          </ul>
        )}
      </div>
      {showNoResults && <p className="text-sm text-muted-foreground">No matching cities. Try adding a region or country, e.g. “Paris, France”.</p>}
      <Button type="button" variant="outline" size="sm" disabled={disabled || locating} onClick={useCurrentLocation}>
        <LocateFixed className="h-4 w-4" aria-hidden="true" /> {locating ? 'Finding your city…' : 'Use my current location'}
      </Button>
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <p className="text-xs text-muted-foreground">
        Only the nearest city is saved, never your exact position. City data from{' '}
        <a className="underline underline-offset-4" href="https://www.geonames.org/" target="_blank" rel="noreferrer">GeoNames</a>{' '}
        (<a className="underline underline-offset-4" href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noreferrer">CC BY 4.0</a>).
      </p>
    </div>
  );
}
