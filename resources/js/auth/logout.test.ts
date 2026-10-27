import { logoutDestination } from './logout';

describe('logoutDestination', () => {
  it('follows only an absolute provider sign-out address', () => {
    expect(logoutDestination({ redirect: 'https://id.example.test/oauth/end-session?x=1' })).toBe('https://id.example.test/oauth/end-session?x=1');
    expect(logoutDestination({ redirect: 'javascript:alert(1)' })).toBeNull();
    expect(logoutDestination({ redirect: '//evil.example.test' })).toBeNull();
    expect(logoutDestination('<!doctype html>')).toBeNull();
    expect(logoutDestination(undefined)).toBeNull();
  });
});
