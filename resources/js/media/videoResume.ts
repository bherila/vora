/**
 * Remembers where a viewer stopped watching each video, in this browser only.
 * A convenience: every read and write tolerates storage being unavailable.
 */

const KEY_PREFIX = 'vora:video-position:';
/** Positions this close to the start are not worth offering. */
export const MIN_RESUME_SECONDS = 10;
/** Positions this close to the end count as finished. */
export const END_MARGIN_SECONDS = 15;
/** Forget positions older than this. */
export const RESUME_MAX_AGE_MS = 180 * 24 * 60 * 60 * 1000;

interface StoredPosition {
  t: number;
  at: number;
}

function key(mediaUlid: string): string {
  return `${KEY_PREFIX}${mediaUlid}`;
}

/** The saved position in seconds, or null when there is none worth offering. */
export function readResumePosition(mediaUlid: string, now: number = Date.now()): number | null {
  try {
    const raw = window.localStorage.getItem(key(mediaUlid));
    if (raw === null) {
      return null;
    }
    const parsed = JSON.parse(raw) as Partial<StoredPosition> | null;
    const t = parsed?.t;
    const at = parsed?.at;
    if (typeof t !== 'number' || typeof at !== 'number' || !Number.isFinite(t) || t < MIN_RESUME_SECONDS) {
      return null;
    }
    if (now - at > RESUME_MAX_AGE_MS) {
      window.localStorage.removeItem(key(mediaUlid));
      return null;
    }
    return t;
  } catch {
    return null;
  }
}

/** Save the position, or clear it near the start or end of the video. */
export function writeResumePosition(mediaUlid: string, time: number, duration: number, now: number = Date.now()): void {
  try {
    const finished = Number.isFinite(duration) && duration > 0 && time >= duration - END_MARGIN_SECONDS;
    if (!Number.isFinite(time) || time < MIN_RESUME_SECONDS || finished) {
      window.localStorage.removeItem(key(mediaUlid));
      return;
    }
    const value: StoredPosition = { t: Math.floor(time), at: now };
    window.localStorage.setItem(key(mediaUlid), JSON.stringify(value));
  } catch {
    // Storage can be full, disabled, or blocked; resuming is optional.
  }
}
