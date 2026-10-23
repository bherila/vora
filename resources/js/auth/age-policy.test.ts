import { readInitialData } from '@/initialData';

import { ageRequirementMessage, ageVerificationMessage, getAgePolicy } from './age-policy';

jest.mock('@/initialData', () => ({ readInitialData: jest.fn() }));

it('preserves default18 and does not promise disabled manual verification', () => {
  jest.mocked(readInitialData).mockReturnValue({});
  const policy = getAgePolicy();
  expect(policy.minimum_age).toBe(18);
  expect(ageRequirementMessage(policy)).toContain('18 years old');
  expect(ageVerificationMessage(policy)).toBe('Manual age verification is not required by this site.');
});

it('uses the server birthday boundary and policy for younger accounts', () => {
  const policy = { minimum_age: 13, age_verification_required: true, birth_date_required: true, latest_birth_date: '2013-10-22' };
  jest.mocked(readInitialData).mockReturnValue({ agePolicy: policy });
  expect(getAgePolicy()).toEqual(policy);
  expect(ageRequirementMessage(getAgePolicy())).toContain('13 years old');
  expect(ageVerificationMessage(getAgePolicy())).toContain('administrator must confirm your age');
  expect(ageVerificationMessage(getAgePolicy())).toContain('do not upload identity documents');
});

it('does not state an adult minimum when minimum age is disabled', () => {
  jest.mocked(readInitialData).mockReturnValue({ agePolicy: { minimum_age: null, age_verification_required: false, birth_date_required: true, latest_birth_date: '2026-10-22' } });
  expect(ageRequirementMessage(getAgePolicy())).toBe('Enter a birth date that is not in the future.');
});
