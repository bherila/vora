import { formatClock, formatTimecode, formatTimeFragment, MAX_FRAGMENT_SECONDS, parseTimeFragment } from '@/media/videoTime';

describe('parseTimeFragment', () => {
  it.each([
    ['#t=3750', 3750],
    ['#t=12.5', 12.5],
    ['#t=1h02m30s', 3750],
    ['#t=2m05s', 125],
    ['#t=45s', 45],
    ['#t=1h', 3600],
    ['#t=62:30', 3750],
    ['#t=1:02:30', 3750],
    ['#t=npt:3750,3800', 3750],
    ['#comment=1&t=90', 90],
    ['t=90', 90],
    ['#t=1H02M30S', 3750],
    ['#t=1%3A30', 90],
  ])('parses %s', (hash, expected) => {
    expect(parseTimeFragment(hash)).toBe(expected);
  });

  it.each(['', '#', '#t=', '#time=90', '#t=-5', '#t=abc', '#t=1:75', '#t=1h2x', '#t=%E0%A4%A', '#t=Infinity'])('rejects %s', (hash) => {
    expect(parseTimeFragment(hash)).toBeNull();
  });

  it('rejects positions beyond the cap', () => {
    expect(parseTimeFragment(`#t=${MAX_FRAGMENT_SECONDS}`)).toBe(MAX_FRAGMENT_SECONDS);
    expect(parseTimeFragment(`#t=${MAX_FRAGMENT_SECONDS + 1}`)).toBeNull();
  });
});

describe('formatTimeFragment', () => {
  it.each([
    [0, '0s'],
    [45.9, '45s'],
    [125, '2m05s'],
    [3750.4, '1h02m30s'],
    [-3, '0s'],
  ])('formats %s as %s', (seconds, expected) => {
    expect(formatTimeFragment(seconds)).toBe(expected);
  });

  it('round-trips through parseTimeFragment', () => {
    for (const seconds of [0, 59, 61, 3599, 3600, 3750, 86_399]) {
      expect(parseTimeFragment(`#t=${formatTimeFragment(seconds)}`)).toBe(seconds);
    }
  });
});

describe('formatClock', () => {
  it.each([
    [5, '0:05'],
    [2472, '41:12'],
    [3750, '1:02:30'],
    [Number.NaN, '0:00'],
  ])('formats %s as %s', (seconds, expected) => {
    expect(formatClock(seconds)).toBe(expected);
  });
});

describe('formatTimecode', () => {
  it('shows the frame within the second when the frame rate is known', () => {
    expect(formatTimecode(3750 + 12 / 25, 25)).toBe('01:02:30.12');
    // An exact NTSC frame timestamp stays on its own frame.
    const ntsc = 30000 / 1001;
    expect(formatTimecode((30 * 1001) / 30000, ntsc)).toBe('00:00:01.00');
    expect(formatTimecode((29 * 1001) / 30000, ntsc)).toBe('00:00:00.29');
  });

  it('falls back to milliseconds without a frame rate', () => {
    expect(formatTimecode(61.25, null)).toBe('00:01:01.250');
    expect(formatTimecode(Number.NaN, null)).toBe('00:00:00.000');
  });
});
