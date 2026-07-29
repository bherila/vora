import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { communityApi } from './api';
import { OnboardingChecklist, type OnboardingData } from './OnboardingChecklist';

jest.mock('./api', () => ({
  communityApi: {
    dismissOnboarding: jest.fn(),
  },
}));

const dismissOnboarding = jest.mocked(communityApi.dismissOnboarding);

function onboarding(overrides: Partial<OnboardingData> = {}): OnboardingData {
  return {
    display_name: 'Avery',
    has_personas: false,
    steps: {
      has_avatar: false,
      has_interests: true,
      is_following: false,
      has_posted: false,
    },
    ...overrides,
  };
}

describe('OnboardingChecklist', () => {
  beforeEach(() => {
    dismissOnboarding.mockReset();
    dismissOnboarding.mockResolvedValue({ success: true });
  });

  it('welcomes the user and gives every incomplete required step an action', () => {
    render(<OnboardingChecklist onboarding={onboarding({
      steps: {
        has_avatar: false,
        has_interests: false,
        is_following: false,
        has_posted: false,
      },
    })} />);

    expect(screen.getByRole('heading', { name: 'Welcome to Vora, Avery!' })).toBeInTheDocument();

    const list = screen.getByRole('list', { name: 'Getting started steps' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(4);
    expect(screen.getByRole('link', { name: 'Edit your profile photo' })).toHaveAttribute('href', '/me');
    expect(screen.getByRole('link', { name: 'Choose your interests' })).toHaveAttribute('href', '/me');
    expect(screen.getByRole('link', { name: 'Browse people' })).toHaveAttribute('href', '/users');
    expect(screen.getByRole('button', { name: 'Write your first post' })).toBeInTheDocument();
  });

  it('announces explicit completion state for every required step', () => {
    render(<OnboardingChecklist onboarding={onboarding()} />);

    const list = screen.getByRole('list', { name: 'Getting started steps' });
    expect(within(list).getAllByText('Completed')).toHaveLength(1);
    expect(within(list).getAllByText('Not completed')).toHaveLength(3);
  });

  it('focuses the composer from the first-post action', () => {
    const composer = document.createElement('textarea');
    composer.id = 'post-composer-body';
    document.body.appendChild(composer);

    render(<OnboardingChecklist onboarding={onboarding()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Write your first post' }));

    expect(composer).toHaveFocus();
  });

  it('presents persona creation as an optional invitation, never an incomplete step', () => {
    render(<OnboardingChecklist onboarding={onboarding()} />);

    const invitation = screen.getByTestId('persona-invitation');
    expect(within(invitation).getByText('Optional')).toBeInTheDocument();
    expect(within(invitation).getByText(/Most people never need one/)).toBeInTheDocument();
    expect(within(invitation).getByRole('link', { name: 'Create a persona' })).toHaveAttribute(
      'href',
      '/personas/new',
    );
    expect(within(invitation).queryByText('Not completed')).not.toBeInTheDocument();
    expect(screen.getByRole('list', { name: 'Getting started steps' })).not.toContainElement(invitation);
  });

  it('omits the optional invitation once the user already has a persona', () => {
    render(<OnboardingChecklist onboarding={onboarding({ has_personas: true })} />);

    expect(screen.queryByTestId('persona-invitation')).not.toBeInTheDocument();
  });

  it('persists dismissal before hiding the checklist', async () => {
    render(<OnboardingChecklist onboarding={onboarding()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Dismiss checklist' }));

    await waitFor(() => expect(dismissOnboarding).toHaveBeenCalledTimes(1));
    expect(screen.queryByRole('heading', { name: 'Welcome to Vora, Avery!' })).not.toBeInTheDocument();
  });
});
