import { fireEvent, render, screen } from '@testing-library/react';

import { FeedPage } from './feed';
import type { OnboardingData } from './OnboardingChecklist';

let mockOnboarding: OnboardingData | null = null;
jest.mock('@/initialData', () => ({
  readInitialData: () => ({ feedHasFollowing: false, feedOnboarding: mockOnboarding, feedInterests: [] }),
}));
jest.mock('@/components/app-side-rail', () => ({
  SideRailLayout: ({ enabled = true, children }: { enabled?: boolean; children: React.ReactNode }) => (
    <div data-testid={enabled ? 'with-rail' : 'without-rail'}>{children}</div>
  ),
}));
jest.mock('./FeedView', () => ({
  FeedView: ({ showComposer = true }: { showComposer?: boolean }) => <div>{showComposer ? 'Feed with composer' : 'Public feed only'}</div>,
}));
jest.mock('./DayOne', () => ({
  ...jest.requireActual('./DayOne'),
  DayOne: ({ onContinue }: { onContinue: () => void }) => <button type="button" onClick={onContinue}>Day one skip</button>,
}));

describe('FeedPage', () => {
  it('opens a brand-new account on the first-run page without the rail', () => {
    mockOnboarding = { display_name: 'Avery', has_personas: false, steps: { has_avatar: false, is_following: false, has_posted: false } };
    render(<FeedPage />);

    expect(screen.getByTestId('without-rail')).toBeInTheDocument();
    expect(screen.getByText('Public feed only')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Public posts from everyone' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Day one skip' }));
    expect(screen.getByTestId('with-rail')).toBeInTheDocument();
    expect(screen.getByText('Feed with composer')).toBeInTheDocument();
  });

  it('shows the ordinary feed once the account follows someone', () => {
    mockOnboarding = { display_name: 'Avery', has_personas: false, steps: { has_avatar: false, is_following: true, has_posted: false } };
    render(<FeedPage />);

    expect(screen.getByTestId('with-rail')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Day one skip' })).toBeNull();
    expect(screen.getByRole('heading', { name: 'Feed' })).toBeInTheDocument();
  });
});
