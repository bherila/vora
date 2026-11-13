import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { LocationEditor } from './LocationEditor';
import { type PlaceOption } from './types';

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: { get: jest.fn(), post: jest.fn(), put: jest.fn() },
}));

const mocked = fetchWrapper as jest.Mocked<typeof fetchWrapper>;

const portland: PlaceOption = {
  id: 5746545,
  name: 'Portland',
  region: 'Oregon',
  country_code: 'US',
  country_name: 'United States',
  label: 'Portland, Oregon, United States',
};

function renderEditor() {
  render(<LocationEditor initial={{ place: null, country_audience: 'nobody', city_audience: 'nobody' }} />);
}

describe('LocationEditor', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
  });
  afterEach(() => {
    jest.useRealTimers();
  });

  it('searches the local city list and saves the chosen city with both audiences', async () => {
    mocked.get.mockResolvedValue({ data: [portland] });
    mocked.put.mockResolvedValue({ success: true, message: 'Location saved.', data: { place: portland, country_audience: 'everyone', city_audience: 'mutuals' } });
    renderEditor();

    fireEvent.change(screen.getByRole('combobox', { name: 'City' }), { target: { value: 'portl' } });
    await act(async () => { jest.advanceTimersByTime(300); });
    expect(mocked.get).toHaveBeenCalledWith('/api/location/places?q=portl');

    const option = await screen.findByRole('option', { name: 'Portland, Oregon, United States' });
    // Plain click activation (no mouse-down), as touch and assistive technology send.
    fireEvent.click(option);
    expect(screen.getByTestId('selected-place')).toHaveTextContent('Portland, Oregon, United States');

    fireEvent.change(screen.getByLabelText('Who can see your country'), { target: { value: 'everyone' } });
    fireEvent.change(screen.getByLabelText('Who can see your city'), { target: { value: 'mutuals' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save location' }));

    await waitFor(() => expect(mocked.put).toHaveBeenCalledWith('/api/location', {
      place_id: 5746545,
      country_audience: 'everyone',
      city_audience: 'mutuals',
    }));
    expect(await screen.findByText('Location saved.')).toBeInTheDocument();
  });

  it('never lets the city reach more people than the country', () => {
    renderEditor();
    const country = screen.getByLabelText('Who can see your country');
    const city = screen.getByLabelText<HTMLSelectElement>('Who can see your city');

    fireEvent.change(country, { target: { value: 'everyone' } });
    fireEvent.change(city, { target: { value: 'everyone' } });
    fireEvent.change(country, { target: { value: 'mutuals' } });

    expect(city.value).toBe('mutuals');
    const cityOptions = Array.from(city.options);
    expect(cityOptions.filter((option) => option.disabled).map((option) => option.value)).toEqual(['followers', 'everyone']);
  });

  it('snaps the browser position, rounded, to the nearest city', async () => {
    mocked.post.mockResolvedValue({ data: portland });
    const getCurrentPosition = jest.fn((success: PositionCallback) => success({
      coords: { latitude: 45.523456, longitude: -122.676789 },
    } as GeolocationPosition));
    Object.defineProperty(navigator, 'geolocation', { configurable: true, value: { getCurrentPosition } });
    renderEditor();

    fireEvent.click(screen.getByRole('button', { name: /Use my current location/ }));

    await waitFor(() => expect(mocked.post).toHaveBeenCalledWith('/api/location/places/nearest', { latitude: 45.52, longitude: -122.68 }));
    expect(await screen.findByTestId('selected-place')).toHaveTextContent('Portland, Oregon, United States');
  });

  it('explains a denied location permission', () => {
    const getCurrentPosition = jest.fn((_success: PositionCallback, failure: PositionErrorCallback) => failure({
      code: 1, PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3, message: 'denied',
    } as GeolocationPositionError));
    Object.defineProperty(navigator, 'geolocation', { configurable: true, value: { getCurrentPosition } });
    renderEditor();

    fireEvent.click(screen.getByRole('button', { name: /Use my current location/ }));

    expect(screen.getByRole('alert')).toHaveTextContent('Location permission was denied');
    expect(mocked.post).not.toHaveBeenCalled();
  });

  it('ignores a slow location lookup once a city was chosen by hand', async () => {
    let locate: (position: GeolocationPosition) => void = () => undefined;
    Object.defineProperty(navigator, 'geolocation', {
      configurable: true,
      value: { getCurrentPosition: jest.fn((success: PositionCallback) => { locate = success; }) },
    });
    const salem: PlaceOption = { ...portland, id: 2, name: 'Salem', label: 'Salem, Oregon, United States' };
    mocked.get.mockResolvedValue({ data: [salem] });
    mocked.post.mockResolvedValue({ data: portland });
    renderEditor();

    fireEvent.click(screen.getByRole('button', { name: /Use my current location/ }));
    fireEvent.change(screen.getByRole('combobox', { name: 'City' }), { target: { value: 'sal' } });
    await act(async () => { jest.advanceTimersByTime(300); });
    fireEvent.click(await screen.findByRole('option', { name: salem.label }));

    await act(async () => { locate({ coords: { latitude: 45.52, longitude: -122.68 } } as GeolocationPosition); });

    expect(mocked.post).not.toHaveBeenCalled();
    expect(screen.getByTestId('selected-place')).toHaveTextContent('Salem, Oregon, United States');
    expect(screen.getByRole('button', { name: /Use my current location/ })).toBeEnabled();
  });
});
