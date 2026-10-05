import { spawn } from 'node:child_process';
import { createReadStream, existsSync } from 'node:fs';
import { createServer } from 'node:http';
import { resolve } from 'node:path';
import { createInterface } from 'node:readline';

// PHP's built-in server resets :memory: between requests. This bridge keeps a
// PHPUnit worker alive, sending normal HTTP requests through Laravel's kernel.
const origin = 'http://127.0.0.1:4187';
const publicRoot = resolve('public/build');
if (!existsSync(resolve(publicRoot, 'manifest.json'))) throw new Error('Run pnpm run build before browser tests.');
if (existsSync('public/hot')) throw new Error('Stop Vite before browser tests so they use the built assets.');
const worker = spawn('php', ['vendor/bin/phpunit', '--no-progress', '--colors=never', 'tests/Browser/BrowserServerTest.php'], {
  env: {
    ...process.env,
    VORA_BROWSER_WORKER: '1',
    APP_ENV: 'testing',
    APP_URL: origin,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: ':memory:',
    SESSION_DRIVER: 'database',
    CACHE_STORE: 'array',
    MAIL_MAILER: 'array',
    QUEUE_CONNECTION: 'sync',
  },
  stdio: ['pipe', 'pipe', 'inherit'],
});
let nextId = 0;
let stopping = false;
const pending = new Map();
let ready;
const readiness = new Promise((resolveReady) => { ready = resolveReady; });
createInterface({ input: worker.stdout }).on('line', (line) => {
  if (!line.startsWith('VORA_BROWSER_JSON ')) {
    process.stderr.write(`${line}\n`);
    return;
  }
  const message = JSON.parse(line.slice('VORA_BROWSER_JSON '.length));
  if (message.ready) ready();
  else {
    const request = pending.get(message.id);
    if (request) {
      clearTimeout(request.timer);
      pending.delete(message.id);
      request.resolve(message);
    }
  }
});
worker.on('error', (error) => { console.error(error); process.exit(1); });
worker.on('exit', (code) => {
  if (!stopping) { console.error(`Browser worker exited unexpectedly (${code}).`); process.exit(1); }
});

function requestWorker(input) {
  const id = ++nextId;
  return new Promise((resolveRequest, reject) => {
    const timer = setTimeout(() => {
      pending.delete(id);
      reject(new Error('Laravel browser request timed out.'));
    }, 30_000);
    pending.set(id, { resolve: resolveRequest, timer });
    worker.stdin.write(`${JSON.stringify({ ...input, id })}\n`);
  });
}

const server = createServer(async (request, response) => {
  try {
    const url = new URL(request.url, origin);
    if (request.headers.host !== '127.0.0.1:4187') { response.writeHead(400).end(); return; }
    if (url.pathname === '/__browser/ready') { response.writeHead(200).end('ready'); return; }
    if (url.pathname.startsWith('/build/')) {
      const path = resolve('public', `.${decodeURIComponent(url.pathname)}`);
      if (!path.startsWith(`${publicRoot}/`) || !existsSync(path)) { response.writeHead(404).end(); return; }
      const mime = path.endsWith('.js') ? 'text/javascript' : path.endsWith('.css') ? 'text/css' : 'application/octet-stream';
      response.writeHead(200, { 'Content-Type': mime });
      createReadStream(path).on('error', (error) => response.destroy(error)).pipe(response);
      return;
    }
    const chunks = [];
    for await (const chunk of request) chunks.push(chunk);
    const result = await requestWorker({
      method: request.method,
      path: request.url,
      headers: request.headers,
      body: Buffer.concat(chunks).toString('base64'),
    });
    const headers = { ...result.headers };
    if (result.cookies?.length) headers['Set-Cookie'] = result.cookies;
    response.writeHead(result.status, headers);
    response.end(Buffer.from(result.body, 'base64'));
  } catch (error) {
    console.error(error);
    response.writeHead(500).end('Browser test bridge failed.');
  }
});
await readiness;
server.listen(4187, '127.0.0.1');
function stop() {
  stopping = true;
  server.closeAllConnections();
  server.close();
  worker.stdin.end();
  setTimeout(() => worker.kill('SIGKILL'), 5_000).unref();
}
process.on('SIGTERM', stop);
process.on('SIGINT', stop);
