import '@testing-library/jest-dom';

import { render, screen } from '@testing-library/react';

import type { ProfileEditable } from '@/user/profile-identity-editor';
import { ProfileIdentityEditor } from '@/user/profile-identity-editor';

jest.mock('@/initialData', () => ({
  readInitialData: () => ({
    restrictions: [{
      capability: 'media.upload',
      label: 'Media uploads',
      reason: 'Safety review',
      expires_at: null,
    }],
  }),
}));
jest.mock('@/community/AudienceField', () => ({ AudienceField: () => null }));
jest.mock('@/interests/api', () => ({
  loadInterests: jest.fn(() => Promise.resolve({ interests: [] })),
  persistRatings: jest.fn(),
}));

const editable: ProfileEditable = {
  name: 'Alex Example',
  email: 'alex@example.test',
  display_name: 'Alex',
  bio: null,
  pronouns: null,
  gender: null,
  gender_other: null,
  user_type: null,
  user_type_other: null,
  preferred_user_types: [],
  preferred_genders: [],
  profile_audience: 'everyone',
  audience_user_ids: [],
  can_manage_interests: false,
};

describe('ProfileIdentityEditor restrictions', () => {
  it('replaces the account picture picker while keeping picture removal available', () => {
    render(<ProfileIdentityEditor editable={editable} onSaved={jest.fn()} />);

    expect(screen.queryByText('Drop a profile image here')).not.toBeInTheDocument();
    expect(screen.getByText(/Media uploads restricted/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Remove current picture' })).toBeInTheDocument();
  });
});
