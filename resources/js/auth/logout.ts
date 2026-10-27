/**
 * Under provider sign-in, POST /logout answers with the identity provider's
 * sign-out address, which the page must visit itself: a fetch cannot follow a
 * redirect to another origin. Only an absolute http(s) URL is accepted.
 */
export function logoutDestination(response: unknown): string | null {
  if (typeof response !== 'object' || response === null) return null;
  const redirect = (response as { redirect?: unknown }).redirect;

  return typeof redirect === 'string' && /^https?:\/\//i.test(redirect) ? redirect : null;
}
