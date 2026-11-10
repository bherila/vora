import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { NearbyPeople } from './NearbyPeople';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn() } }));

const mocked = fetchWrapper as jest.Mocked<typeof fetchWrapper>;

describe('NearbyPeople', () => {
  beforeEach(() => jest.clearAllMocks());

  it('asks for a city when the viewer has none', async () => {
    mocked.get.mockResolvedValue({ data: { has_location: false, radius_km: 50, people: [] } });
    render(<NearbyPeople />);

    expect(await screen.findByText('Set your city to see people near you')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Add your city on your profile' })).toHaveAttribute('href', '/me');
  });

  it('lists nearby people with coarse distances and reloads for a new radius', async () => {
    mocked.get.mockResolvedValue({ data: { has_location: true, radius_km: 50, people: [
      { id: 2, display_name: 'Sam', user_type: null, gender: null, same_city: true, within_km: null,
        location: { level: 'city', label: 'Portland, Oregon, United States', city: 'Portland', region: 'Oregon', country_code: 'US', country_name: 'United States' } },
      { id: 3, display_name: 'Bea', user_type: null, gender: null, same_city: false, within_km: 25,
        location: { level: 'city', label: 'Beaverton, Oregon, United States', city: 'Beaverton', region: 'Oregon', country_code: 'US', country_name: 'United States' } },
    ] } });
    render(<NearbyPeople />);

    expect(await screen.findByText('In your city')).toBeInTheDocument();
    expect(screen.getByText('Within 25 km', { selector: ':not(option)' })).toBeInTheDocument();
    expect(screen.getAllByRole('link', { name: 'View profile' })[1]).toHaveAttribute('href', '/users/3');

    fireEvent.change(screen.getByLabelText('Distance'), { target: { value: '100' } });
    await waitFor(() => expect(mocked.get).toHaveBeenLastCalledWith('/api/users/nearby?radius=100'));
  });
});
