import { execFileSync,spawn } from 'node:child_process';
import { createReadStream, existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { createServer } from 'node:https';
import { tmpdir } from 'node:os';
import { join,resolve } from 'node:path';
import { createInterface } from 'node:readline';

// Both replicas boot the real embedded application, with separate in-memory
// databases. Routing /auth to its own worker allows the host callback to make
// real HTTP token/identity requests without blocking its own request loop.
const origin = 'https://127.0.0.1:4188';
const publicRoot = resolve('public/build');
if (!existsSync(join(publicRoot, 'manifest.json'))) throw new Error('Run pnpm run build before browser tests.');
if (existsSync('public/hot')) throw new Error('Stop Vite before browser tests.');
const certificateDirectory = mkdtempSync(join(tmpdir(), 'vora-embedded-tls-'));
const certificate = join(certificateDirectory, 'certificate.pem');
const privateKey = join(certificateDirectory, 'private.pem');
execFileSync('openssl', [
  'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
  '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1',
  '-keyout', privateKey, '-out', certificate,
], { stdio: 'ignore' });

let stopping = false;
let server = null;
const workers = [];
function startWorker(role, extra = {}) {
  const process = spawn('php', ['vendor/bin/phpunit', '--no-progress', '--colors=never', 'tests/Browser/EmbeddedBrowserServerTest.php'], {
    env: {
      ...globalThis.process.env,
      VORA_EMBEDDED_BROWSER_WORKER: '1', VORA_EMBEDDED_BROWSER_ROLE: role,
      VORA_EMBEDDED_BROWSER_CA: certificate,
      AUTH_SIGN_IN: 'embedded', APP_ENV: 'testing', APP_URL: origin,
      DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:',
      SESSION_DRIVER: 'database', CACHE_STORE: 'array', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync',
      ...extra,
    },
    stdio: ['pipe', 'pipe', 'inherit'],
  });
  let nextId = 0;
  const pending = new Map();
  let ready;
  let failed;
  const readiness = new Promise((resolveReady, rejectReady) => { ready = resolveReady; failed = rejectReady; });
  createInterface({ input: process.stdout }).on('line', (line) => {
    if (!line.startsWith('VORA_EMBEDDED_JSON ')) {
      globalThis.process.stderr.write(`[${role}] ${line}\n`);
      return;
    }
    const message = JSON.parse(line.slice('VORA_EMBEDDED_JSON '.length));
    if (message.ready) ready(message);
    else {
      const request = pending.get(message.id);
      if (request) {
        clearTimeout(request.timer);
        pending.delete(message.id);
        request.resolve(message);
      }
    }
  });
  process.on('error', failed);
  process.on('exit', (code) => {
    if (!stopping) {
      failed(new Error(`Embedded ${role} worker exited (${code}).`));
      globalThis.process.stderr.write(`Embedded ${role} worker exited (${code}).\n`);
      globalThis.process.exitCode = 1;
      stop();
    }
  });
  function request(input) {
    const id = ++nextId;
    return new Promise((resolveRequest, reject) => {
      const timer = setTimeout(() => { pending.delete(id); reject(new Error(`Embedded ${role} request timed out.`)); }, 30_000);
      pending.set(id, { resolve: resolveRequest, timer });
      process.stdin.write(`${JSON.stringify({ ...input, id })}\n`);
    });
  }
  const worker = { process, readiness, request };
  workers.push(worker);
  return worker;
}

const provider = startWorker('provider');
const credentials = await provider.readiness;
const host = startWorker('host', {
  OAUTH_PROVIDER: 'embedded-test', OAUTH_CLIENT_ID: credentials.client, OAUTH_CLIENT_SECRET: credentials.secret,
});
await host.readiness;

server = createServer({ key: readFileSync(privateKey), cert: readFileSync(certificate) }, async (request, response) => {
  try {
    if (request.headers.host !== '127.0.0.1:4188') { response.writeHead(400).end(); return; }
    const url = new URL(request.url, origin);
    if (url.pathname === '/__embedded/ready') { response.writeHead(200).end('ready'); return; }
    if (url.pathname.startsWith('/build/')) {
      const path = resolve('public', `.${decodeURIComponent(url.pathname)}`);
      if (!path.startsWith(`${publicRoot}/`) || !existsSync(path)) { response.writeHead(404).end(); return; }
      response.writeHead(200, { 'Content-Type': path.endsWith('.js') ? 'text/javascript' : path.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
      createReadStream(path).on('error', (error) => response.destroy(error)).pipe(response);
      return;
    }
    const chunks = [];
    for await (const chunk of request) chunks.push(chunk);
    const worker = url.pathname === '/auth' || url.pathname.startsWith('/auth/') ? provider : host;
    const result = await worker.request({
      method: request.method, path: request.url, headers: request.headers,
      body: Buffer.concat(chunks).toString('base64'),
    });
    const headers = { ...result.headers };
    if (result.cookies?.length) headers['Set-Cookie'] = result.cookies;
    response.writeHead(result.status, headers);
    response.end(Buffer.from(result.body, 'base64'));
  } catch (error) {
    console.error(error);
    response.writeHead(500).end('Embedded browser bridge failed.');
  }
});
server.listen(4188, '127.0.0.1');

function stop() {
  if (stopping) return;
  stopping = true;
  if (server !== null) { server.closeAllConnections(); server.close(); }
  for (const worker of workers) {
    worker.process.stdin.end();
    setTimeout(() => worker.process.kill('SIGKILL'), 5_000).unref();
  }
  rmSync(certificateDirectory, { recursive: true, force: true });
}
process.on('SIGTERM', stop);
process.on('SIGINT', stop);
