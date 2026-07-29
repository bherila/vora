import { safeHttpsUrl, safeInternalUrl, safeSameOriginUrl } from '@/security/dom-url';

describe('DOM URL boundaries', () => {
  it('accepts app-relative routes and encodes HTML metacharacters', () => {
    expect(safeInternalUrl('/users/7?name=<script>')).toBe('/users/7?name=%3Cscript%3E');
  });

  it.each([
    'javascript:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    '//evil.example/path',
    '/\\evil.example/path',
    'https://evil.example/path',
  ])('rejects a non-internal navigation target: %s', (value) => {
    expect(safeInternalUrl(value)).toBeNull();
  });

  it('allows only same-origin HTTP URLs for media playback', () => {
    expect(safeSameOriginUrl('/api/media/7/hls/master.m3u8')).toBe(
      'http://localhost/api/media/7/hls/master.m3u8',
    );
    expect(safeSameOriginUrl('https://evil.example/master.m3u8')).toBeNull();
    expect(safeSameOriginUrl('javascript:alert(1)')).toBeNull();
  });

  it('allows HTTPS downloads without changing existing signed escapes', () => {
    const signed = 'https://r2.example/file%2Fone?signature=a%20b';

    expect(safeHttpsUrl(signed)).toBe(signed);
    expect(safeHttpsUrl('http://r2.example/file')).toBeNull();
    expect(safeHttpsUrl('javascript:alert(1)')).toBeNull();
  });
});
