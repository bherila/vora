const INTERNAL_URL_BASE = 'https://vora.invalid';

/**
 * Encode the HTML metacharacters that must never cross from DOM-hydrated text
 * into a URL-bearing property. Existing percent escapes are deliberately left
 * alone so signed object-storage URLs keep their signatures.
 */
function escapeUrlMetacharacters(value: string): string {
  return value.replace(/[<>"']/g, (character) => encodeURIComponent(character));
}

/**
 * Accept only an app-relative path. Protocol-relative URLs and backslash
 * variants are rejected before the browser can normalize them to another host.
 */
export function safeInternalUrl(value: unknown): string | null {
  if (typeof value !== 'string') {
    return null;
  }

  const trimmed = value.trim();
  if (!trimmed.startsWith('/') || trimmed.startsWith('//') || trimmed.includes('\\')) {
    return null;
  }

  try {
    const parsed = new URL(trimmed, INTERNAL_URL_BASE);
    if (parsed.origin !== INTERNAL_URL_BASE) {
      return null;
    }

    return escapeUrlMetacharacters(`${parsed.pathname}${parsed.search}${parsed.hash}`);
  } catch {
    return null;
  }
}

/**
 * Accept an HLS manifest only when it resolves to this application. This keeps
 * authenticated playback from being redirected to a script or third-party URL.
 */
export function safeSameOriginUrl(value: unknown): string | null {
  if (typeof value !== 'string') {
    return null;
  }

  try {
    const parsed = new URL(value, window.location.origin);
    if (
      parsed.origin !== window.location.origin
      || (parsed.protocol !== 'http:' && parsed.protocol !== 'https:')
    ) {
      return null;
    }

    return escapeUrlMetacharacters(parsed.href);
  } catch {
    return null;
  }
}

/**
 * Admin download links are signed, absolute object-storage URLs. Require HTTPS
 * and preserve their existing percent-encoded signature bytes.
 */
export function safeHttpsUrl(value: unknown): string | null {
  if (typeof value !== 'string') {
    return null;
  }

  try {
    const parsed = new URL(value);
    if (parsed.protocol !== 'https:') {
      return null;
    }

    return escapeUrlMetacharacters(parsed.href);
  } catch {
    return null;
  }
}
