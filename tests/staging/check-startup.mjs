import assert from 'node:assert/strict';

const base = new URL(process.env.STAGING_APP_URL ?? 'http://127.0.0.1:18080');
assert(base.protocol === 'http:' && ['127.0.0.1', 'localhost', '[::1]'].includes(base.hostname), 'Startup checks require the loopback staging application.');
const response = await fetch(new URL('/login', base), { signal: AbortSignal.timeout(10_000) });
assert(response.ok, 'The login page must respond successfully.');
const html = await response.text();
assert(/\bid=["']login["']/.test(html), 'The login response must contain the application mount, not a PHP router error.');
const modules = [...html.matchAll(/<script\b[^>]*>/gi)]
  .filter(([tag]) => /\btype=["']module["']/.test(tag))
  .map(([tag]) => tag.match(/\bsrc=["']([^"']+)["']/)?.[1])
  .filter(Boolean);
assert(modules.length > 0, 'The login page must reference built JavaScript entrypoints.');
for (const source of modules) {
  const asset = new URL(source, base);
  assert(asset.origin === base.origin && asset.pathname.startsWith('/build/assets/'), 'Staging assets must come from the built application.');
  const result = await fetch(asset, { method: 'HEAD', signal: AbortSignal.timeout(10_000) });
  assert(result.ok && /javascript/.test(result.headers.get('content-type') ?? ''), 'Every login JavaScript entrypoint must be served with its correct content type.');
}
console.log(`Login HTML and ${modules.length} built JavaScript entrypoints are ready.`);
