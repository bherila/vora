import {
  BROWSING_PAGE_WIDTH,
  FULL_BROWSING_PAGE_WIDTH,
  READING_PAGE_WIDTH,
} from '@/components/page-width';

describe('page-width conventions', () => {
  it('keeps one documented token for each page class', () => {
    expect(READING_PAGE_WIDTH).toContain('max-w-3xl');
    expect(BROWSING_PAGE_WIDTH).toContain('max-w-7xl');
    expect(FULL_BROWSING_PAGE_WIDTH).toBe('w-full');
  });
});
