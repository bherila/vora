/**
 * Time parsing and formatting for the video player: `#t=` deep links, clock
 * labels, and frame-accurate timecodes.
 */

/** Longest position a deep link may request (48 hours); anything larger is ignored. */
export const MAX_FRAGMENT_SECONDS = 48 * 60 * 60;

const SECONDS = /^\d+(?:\.\d+)?$/;
const UNITS = /^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+(?:\.\d+)?)s)?$/;
// m:ss[.fff] or h:mm:ss[.fff]; only the seconds may have a fraction.
const CLOCK = /^(\d+):([0-5]?\d(?:\.\d+)?)(?::([0-5]?\d(?:\.\d+)?))?$/;

/**
 * Parse a start time from a URL fragment such as `#t=1h02m30s`, `#t=3750`,
 * `#t=62:30`, or the Media Fragments form `#t=npt:3750,3800` (only the start is
 * used). Returns seconds, or null when the fragment has no valid time.
 */
export function parseTimeFragment(hash: string): number | null {
  const fragment = hash.startsWith('#') ? hash.slice(1) : hash;
  const pair = fragment.split('&').find((part) => part.startsWith('t='));
  if (pair === undefined) {
    return null;
  }

  let value = pair.slice(2);
  try {
    value = decodeURIComponent(value);
  } catch {
    return null;
  }
  value = value.trim().toLowerCase().replace(/^npt:/, '').split(',')[0] ?? '';

  const seconds = parseTimeValue(value);
  if (seconds === null || !Number.isFinite(seconds) || seconds < 0 || seconds > MAX_FRAGMENT_SECONDS) {
    return null;
  }
  return seconds;
}

/**
 * Parse a typed time such as `12:31`, `1:02:30.5`, `90`, or `1h2m`. Returns
 * seconds, or null when the text is not a time.
 */
export function parseTimeInput(value: string): number | null {
  const seconds = parseTimeValue(value.trim().toLowerCase());
  if (seconds === null || !Number.isFinite(seconds) || seconds < 0 || seconds > MAX_FRAGMENT_SECONDS) {
    return null;
  }
  return seconds;
}

/** Format milliseconds for editing: `0:00`, `12:31`, `1:02:30.500`. */
export function formatEditTime(ms: number): string {
  const clock = formatClock(ms / 1000);
  const fraction = Math.max(0, Math.floor(ms)) % 1000;
  return fraction === 0 ? clock : `${clock}.${String(fraction).padStart(3, '0')}`;
}

/** Parse a rational frame rate such as `30000/1001`; null when malformed. */
export function parseFrameRate(value: string | null | undefined): number | null {
  const match = /^(\d{1,7})\/(\d{1,7})$/.exec(value ?? '');
  if (match === null) {
    return null;
  }
  const rate = Number(match[1]) / Number(match[2]);
  return Number.isFinite(rate) && rate > 0 && rate <= 1000 ? rate : null;
}

function parseTimeValue(value: string): number | null {
  if (value === '') {
    return null;
  }
  if (SECONDS.test(value)) {
    return Number(value);
  }

  const units = UNITS.exec(value);
  if (units !== null) {
    const [, hours, minutes, seconds] = units;
    return Number(hours ?? 0) * 3600 + Number(minutes ?? 0) * 60 + Number(seconds ?? 0);
  }

  const clock = CLOCK.exec(value);
  if (clock !== null) {
    const [, first, second, third] = clock;
    if (third !== undefined && second?.includes('.')) {
      return null;
    }
    // h:mm:ss when there are three parts, otherwise m:ss.
    return third === undefined
      ? Number(first) * 60 + Number(second)
      : Number(first) * 3600 + Number(second) * 60 + Number(third);
  }

  return null;
}

/** Format whole seconds as a compact fragment value: `1h02m30s`, `2m05s`, `45s`. */
export function formatTimeFragment(seconds: number): string {
  const total = Math.max(0, Math.floor(seconds));
  const hours = Math.floor(total / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  const secs = total % 60;

  if (hours > 0) {
    return `${hours}h${pad(minutes)}m${pad(secs)}s`;
  }
  if (minutes > 0) {
    return `${minutes}m${pad(secs)}s`;
  }
  return `${secs}s`;
}

/** Format seconds as a clock label: `1:02:30`, `41:12`, `0:05`. */
export function formatClock(seconds: number): string {
  const total = Number.isFinite(seconds) ? Math.max(0, Math.floor(seconds)) : 0;
  const hours = Math.floor(total / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  const secs = total % 60;

  return hours > 0 ? `${hours}:${pad(minutes)}:${pad(secs)}` : `${minutes}:${pad(secs)}`;
}

/**
 * Format seconds as `HH:MM:SS.ff` (frame within the second) when the frame rate
 * is known, otherwise `HH:MM:SS.mmm`.
 */
export function formatTimecode(seconds: number, frameRate: number | null): string {
  const time = Number.isFinite(seconds) ? Math.max(0, seconds) : 0;
  const whole = Math.floor(time);
  const hours = Math.floor(whole / 3600);
  const minutes = Math.floor((whole % 3600) / 60);
  const secs = whole % 60;
  const base = `${pad(hours)}:${pad(minutes)}:${pad(secs)}`;

  if (frameRate !== null && frameRate > 0) {
    // The small epsilon keeps an exact frame timestamp from rounding down into
    // the previous frame.
    const frame = Math.min(Math.ceil(frameRate) - 1, Math.floor((time - whole) * frameRate + 1e-6));
    return `${base}.${pad(Math.max(0, frame))}`;
  }

  return `${base}.${String(Math.floor((time - whole) * 1000)).padStart(3, '0')}`;
}

function pad(value: number): string {
  return String(value).padStart(2, '0');
}
