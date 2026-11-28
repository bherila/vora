import { readResumePosition, RESUME_MAX_AGE_MS, writeResumePosition } from '@/media/videoResume';

const KEY = 'vora:video-position:01VIDEO';

beforeEach(() => {
  window.localStorage.clear();
  jest.restoreAllMocks();
});

it('saves and reads a position worth resuming', () => {
  writeResumePosition('01VIDEO', 2472.8, 3600, 1_000);

  expect(JSON.parse(window.localStorage.getItem(KEY) ?? 'null')).toEqual({ t: 2472, at: 1_000 });
  expect(readResumePosition('01VIDEO', 2_000)).toBe(2472);
});

it('clears the position near the start and near the end', () => {
  writeResumePosition('01VIDEO', 600, 3600);
  writeResumePosition('01VIDEO', 4, 3600);
  expect(window.localStorage.getItem(KEY)).toBeNull();

  writeResumePosition('01VIDEO', 600, 3600);
  writeResumePosition('01VIDEO', 3590, 3600);
  expect(window.localStorage.getItem(KEY)).toBeNull();
});

it('forgets stale and malformed entries', () => {
  writeResumePosition('01VIDEO', 600, 3600, 0);
  expect(readResumePosition('01VIDEO', RESUME_MAX_AGE_MS + 1)).toBeNull();
  expect(window.localStorage.getItem(KEY)).toBeNull();

  window.localStorage.setItem(KEY, 'not json');
  expect(readResumePosition('01VIDEO')).toBeNull();
  window.localStorage.setItem(KEY, JSON.stringify({ t: 'soon', at: 0 }));
  expect(readResumePosition('01VIDEO')).toBeNull();
});

it('tolerates unavailable storage', () => {
  jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
    throw new Error('blocked');
  });
  jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
    throw new Error('quota');
  });

  expect(readResumePosition('01VIDEO')).toBeNull();
  expect(() => writeResumePosition('01VIDEO', 600, 3600)).not.toThrow();
});
