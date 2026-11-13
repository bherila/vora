import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';
import { hydrateIdentityStore } from '@/identity';

import { FollowProfilePage } from './follow-profile';

// The real Edit profile dialog and location editor, unlike follow-profile.test.tsx.
jest.mock('@/initialData', () => ({
  readInitialData: () => {
    const el = document.getElementById('initial-data');
    return el?.textContent ? JSON.parse(el.textContent) : {};
  },
}));
jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: jest.fn((url: string) => Promise.resolve(
      url.includes('content-counts') ? { data: { media: 0, stories: 0, posts: 0, favorites: 0 } } : { data: [] },
    )),
    post: jest.fn(() => Promise.resolve({})),
    put: jest.fn(),
    patch: jest.fn(() => Promise.resolve({})),
    delete: jest.fn(() => Promise.resolve({})),
  },
}));
jest.mock('@/community/NotificationBell', () => ({ NotificationBell: () => null }));
jest.mock('sonner', () => ({ Toaster: () => null, toast: { success: jest.fn(), error: jest.fn() } }));
jest.mock('@/media/OwnerMediaManager', () => ({ OwnerMediaManager: () => null }));
jest.mock('@/stories/OwnerStoriesManager', () => ({ OwnerStoriesManager: () => null }));
jest.mock('@/user/profile-identity-editor', () => ({ ProfileIdentityEditor: () => null }));
jest.mock('@/community/PostCard', () => ({ PostCard: () => null }));
jest.mock('@/explore/StoryGrid', () => ({ StoryGrid: () => null }));
jest.mock('@/media/MediaGrid', () => ({ MediaGrid: () => null }));
jest.mock('@/components/favorite-button', () => ({ FavoriteButton: () => null }));

const mocked = fetchWrapper as jest.Mocked<typeof fetchWrapper>;
const portland = { id: 1, name: 'Portland', region: 'Oregon', country_code: 'US', country_name: 'United States', label: 'Portland, Oregon, United States' };

function setInitialData(): void {
  document.body.innerHTML = '<script id="initial-data" type="application/json"></script>';
  const el = document.getElementById('initial-data');
  if (el) {
    el.textContent = JSON.stringify({
      followProfile: {
        id: 7, public_id: '01PUBLICUSER00000000000000', is_self: true, display_name: 'Ben', avatar_url: null,
        restricted: false, bio: null, pronouns: null, user_type: null, gender: null, mutual_interests: [],
        follow_request: null, can_follow_back: false, can_message: false, characters: [],
        location: { level: 'city', label: portland.label, city: 'Portland', region: 'Oregon', country_code: 'US', country_name: 'United States' },
      },
      profileEditable: {
        name: 'Ben', email: 'ben@example.com', display_name: 'Ben', bio: null, pronouns: null, gender: null, gender_other: null,
        user_type: null, user_type_other: null, preferred_user_types: [], preferred_genders: [], profile_audience: 'everyone',
        audience_user_ids: [], can_manage_interests: false,
        location: { place: portland, country_audience: 'everyone', city_audience: 'everyone' },
      },
      profileMedia: { characters: [], last_interest_ids: [] },
      profileCharacters: [],
      profileIdentityCounts: null,
    });
  }
  hydrateIdentityStore([], null);
}

describe('Edit profile location saves', () => {
  beforeAll(() => {
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      value: jest.fn().mockReturnValue({ matches: false, addEventListener: jest.fn(), removeEventListener: jest.fn() }),
    });
  });

  it('keeps the dialog open during a save, so a reopened editor never resubmits older, broader audiences', async () => {
    setInitialData();
    let finish: (value: unknown) => void = () => undefined;
    mocked.put.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve; }));
    render(<FollowProfilePage />);

    fireEvent.click(await screen.findByRole('button', { name: 'Edit profile' }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Who can see your country'), { target: { value: 'nobody' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save location' }));
    expect(mocked.put).toHaveBeenLastCalledWith('/api/location', { place_id: 1, country_audience: 'nobody', city_audience: 'nobody' });

    // Trying to close while the save is pending leaves the editor in place.
    fireEvent.keyDown(document.activeElement ?? document.body, { key: 'Escape' });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Close' }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();

    await act(async () => { finish({ success: true, message: 'Location saved.', data: { place: portland, country_audience: 'nobody', city_audience: 'nobody' } }); });
    await screen.findByText('Location saved.');

    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    fireEvent.click(screen.getByRole('button', { name: 'Edit profile' }));
    const reopened = await screen.findByRole('dialog');
    expect(within(reopened).getByLabelText<HTMLSelectElement>('Who can see your country').value).toBe('nobody');
    expect(within(reopened).getByLabelText<HTMLSelectElement>('Who can see your city').value).toBe('nobody');
  });

  it('closes the city results with Escape without closing the dialog', async () => {
    setInitialData();
    mocked.get.mockImplementation((url: string) => Promise.resolve(
      url.startsWith('/api/location/places') ? { data: [portland] }
        : url.includes('content-counts') ? { data: { media: 0, stories: 0, posts: 0, favorites: 0 } } : { data: [] },
    ));
    render(<FollowProfilePage />);

    fireEvent.click(await screen.findByRole('button', { name: 'Edit profile' }));
    const dialog = await screen.findByRole('dialog');
    const search = within(dialog).getByRole('combobox', { name: 'City' });
    fireEvent.change(search, { target: { value: 'port' } });
    await within(dialog).findByRole('option', { name: portland.label }, { timeout: 2000 });

    fireEvent.keyDown(search, { key: 'Escape' });
    expect(within(dialog).queryByRole('listbox', { name: 'Matching cities' })).toBeNull();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });
});
