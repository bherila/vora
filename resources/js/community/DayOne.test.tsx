import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { DayOne, isDayOne } from './DayOne';
import type { OnboardingData } from './OnboardingChecklist';
import type { CommunityPost } from './types';

let mockProfilesEnabled = true;
jest.mock('@/instance', () => ({ moduleEnabled: (module: string) => module !== 'profiles' || mockProfilesEnabled }));
jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), post: jest.fn() } }));
jest.mock('./PostComposer', () => ({
  PostComposer: ({ onCreated }: { onCreated: (post: CommunityPost) => void }) => (
    <button type="button" onClick={() => onCreated({ id: 1 } as CommunityPost)}>Publish hello</button>
  ),
}));

function onboarding(steps: OnboardingData['steps']): OnboardingData {
  return { display_name: 'Avery Example', has_personas: false, steps };
}

const fresh = onboarding({ has_avatar: false, has_interests: true, is_following: false, has_posted: false });

describe('isDayOne', () => {
  it('opens the first-run page only before the account has posted or followed', () => {
    expect(isDayOne(null)).toBe(false);
    expect(isDayOne(fresh)).toBe(true);
    expect(isDayOne({ ...fresh, steps: { ...fresh.steps, is_following: true } })).toBe(false);
    expect(isDayOne({ ...fresh, steps: { ...fresh.steps, has_posted: true } })).toBe(false);
    // Without profile pages there is no following step to wait for.
    expect(isDayOne(onboarding({ has_posted: false }))).toBe(true);
  });
});

describe('DayOne', () => {
  beforeEach(() => {
    mockProfilesEnabled = true;
    jest.clearAllMocks();
    jest.mocked(fetchWrapper.get).mockResolvedValue({
      data: {
        suggested_people: [
          { id: 8, display_name: 'Priya', avatar_url: null, href: '/users/8', interest_match_score: 50, matching_interests_count: 2 },
          { id: 9, display_name: 'Unsafe', avatar_url: null, href: 'javascript:alert(1)', interest_match_score: 10, matching_interests_count: 0 },
        ],
      },
    });
    jest.mocked(fetchWrapper.post).mockResolvedValue({ success: true, data: { status: 'pending' } });
  });

  it('turns the remaining steps into cards finished in place', async () => {
    render(<DayOne onboarding={fresh} onContinue={jest.fn()} onPosted={jest.fn()} />);

    expect(screen.getByRole('heading', { name: 'Welcome, Avery' })).toBeInTheDocument();
    expect(screen.getByText('Interests chosen.')).toBeInTheDocument();
    expect(within(screen.getByRole('region', { name: 'Add a face' })).getByRole('link', { name: 'Upload photo' })).toHaveAttribute('href', '/me');

    const follow = screen.getByRole('region', { name: 'Follow a few people' });
    expect(await within(follow).findByText('2 shared interests')).toBeInTheDocument();
    expect(within(follow).getByRole('link', { name: 'Priya' })).toHaveAttribute('href', '/users/8');
    expect(within(follow).queryByRole('link', { name: 'Unsafe' })).toBeNull();
    fireEvent.click(within(follow).getByRole('button', { name: 'Follow Priya' }));
    await waitFor(() => expect(fetchWrapper.post).toHaveBeenCalledWith('/api/users/8/follow-requests', {}));

    expect(within(screen.getByRole('region', { name: 'Say hello' })).getByRole('button', { name: 'Publish hello' })).toBeInTheDocument();
    expect(screen.getByText('Step 3 of 3')).toBeInTheDocument();
  });

  it('hands off to the ordinary feed on skip and after the first post', () => {
    const onContinue = jest.fn();
    const onPosted = jest.fn();
    render(<DayOne onboarding={fresh} onContinue={onContinue} onPosted={onPosted} />);

    fireEvent.click(screen.getByRole('button', { name: 'Skip for now' }));
    expect(onContinue).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole('button', { name: 'Publish hello' }));
    expect(onPosted).toHaveBeenCalledWith({ id: 1 });
  });

  it('offers only the steps this instance has and fetches no suggestions without them', () => {
    mockProfilesEnabled = false;
    render(<DayOne onboarding={onboarding({ has_posted: false })} onContinue={jest.fn()} onPosted={jest.fn()} />);

    expect(screen.getByText('One quick thing and your home fills up with people you care about.')).toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Add a face' })).toBeNull();
    expect(screen.queryByRole('region', { name: 'Follow a few people' })).toBeNull();
    expect(screen.queryByText('Interests chosen.')).toBeNull();
    expect(fetchWrapper.get).not.toHaveBeenCalled();
  });
});
