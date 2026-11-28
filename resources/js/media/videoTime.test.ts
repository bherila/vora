import { formatClock, formatEditTime, formatTimecode, formatTimeFragment, MAX_FRAGMENT_SECONDS, parseFrameRate, parseTimeFragment, parseTimeInput } from '@/media/videoTime';

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

  it.each([
    [12.346, '12.346s'],
    [12, '12s'],
    [125.001, '2m05.001s'],
    [3750.25, '1h02m30.250s'],
  ])('keeps milliseconds on request: %s as %s', (seconds, expected) => {
    expect(formatTimeFragment(seconds, { milliseconds: true })).toBe(expected);
    expect(parseTimeFragment(`#t=${expected}`)).toBeCloseTo(seconds, 6);
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

describe('editing helpers', () => {
  it('parses typed times', () => {
    expect(parseTimeInput(' 12:31 ')).toBe(751);
    expect(parseTimeInput('1:02:30.5')).toBe(3750.5);
    expect(parseTimeInput('1H2M')).toBe(3720);
    expect(parseTimeInput('0:42.500')).toBe(42.5);
    expect(parseTimeInput('1:02.5:30')).toBeNull();
    expect(parseTimeInput('later')).toBeNull();
    expect(parseTimeInput('')).toBeNull();
  });

  it('formats milliseconds for editing', () => {
    expect(formatEditTime(0)).toBe('0:00');
    expect(formatEditTime(751_000)).toBe('12:31');
    expect(formatEditTime(3_750_500)).toBe('1:02:30.500');
  });

  it('reads back every time it formats', () => {
    for (const ms of [0, 960, 42_500, 60_960, 751_000, 3_599_999, 3_750_500]) {
      expect(Math.round((parseTimeInput(formatEditTime(ms)) ?? -1) * 1000)).toBe(ms);
    }
  });

  it('parses rational frame rates', () => {
    expect(parseFrameRate('30000/1001')).toBeCloseTo(29.97, 2);
    expect(parseFrameRate('25/1')).toBe(25);
    expect(parseFrameRate('0/0')).toBeNull();
    expect(parseFrameRate('25')).toBeNull();
    expect(parseFrameRate(null)).toBeNull();
  });
});
