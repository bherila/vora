/**
 * Under provider sign-in, POST /logout answers with the identity provider's
 * sign-out address, which the page must visit itself: a fetch cannot follow a
 * redirect to another origin. Only an absolute https: URL is followed.
 */
export function logoutDestination(response: unknown): string | null {
  if (typeof response !== 'object' || response === null) return null;
  const redirect = (response as { redirect?: unknown }).redirect;
  if (typeof redirect !== 'string') return null;
  try {
    const url = new URL(redirect);
    return url.protocol === 'https:' ? url.href : null;
  } catch {
    return null;
  }
}
