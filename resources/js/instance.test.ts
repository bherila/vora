import { readInitialData } from '@/initialData';
import { instanceProfile, moduleEnabled } from '@/instance';

jest.mock('@/initialData', () => ({ readInitialData: jest.fn() }));

describe('instance modules', () => {
  it('preserves existing modules and leaves Events opt-in without bootstrap', () => {
    jest.mocked(readInitialData).mockReturnValue({});
    expect(moduleEnabled('personas')).toBe(true);
    expect(moduleEnabled('profiles')).toBe(true);
    expect(moduleEnabled('library')).toBe(true);
    expect(moduleEnabled('events')).toBe(false);
    expect(instanceProfile()).toBe('vora');
  });

  it('honors explicit module overrides independently of the instance profile', () => {
    jest.mocked(readInitialData).mockReturnValue({ instance: { profile: 'event', modules: { personas: false, profiles: false, events: true, library: false } } });
    expect(moduleEnabled('events')).toBe(true);
    expect(instanceProfile()).toBe('event');
    expect(moduleEnabled('personas')).toBe(false);
    expect(moduleEnabled('profiles')).toBe(false);
    expect(moduleEnabled('library')).toBe(false);
    expect(moduleEnabled('favorites')).toBe(true);
  });
});
