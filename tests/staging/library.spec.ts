import { type APIRequestContext, type APIResponse, type BrowserContext, type Download, expect, type Page, type Request, type Response, test } from '@playwright/test';

let mailUrl = 'http://127.0.0.1:18025';
let expectMultipartVideo = false;
let eventProfile = false;

test.beforeAll(async ({ request }, testInfo) => {
  eventProfile = testInfo.config.metadata.profile === 'event';
  mailUrl = String(testInfo.config.metadata.mailUrl);
  expectMultipartVideo = testInfo.config.metadata.expectMultipartVideo === true;
  expect((await request.get(`${mailUrl}/api/v1/messages?limit=1`)).ok()).toBeTruthy();
});
const PASSWORD = 'Local-Staging-Only-2026!';
const HEIC_SHA256 = '8892ba2bdbb14b22dc4b54927d55688a697dc558c51e7b1b03cfe30e822b2c9f';
const EMAILS = ['admin', 'recipient', 'outsider'].map((role) => `${role}@staging.invalid`);

interface MailSummary { ID: string; To: { Address: string }[]; }
interface Account { id: number; display_name: string; email: string; is_admin: boolean; is_deleted: boolean; storage_limit_bytes: number | null; storage_unlimited: boolean; }
interface Item { ulid: string; title: string; source: string; media: { video?: { status: string; master_url: string | null } } | null; }
interface Upload {
  data: { id: number; ulid: string };
  upload_url: string;
  upload_headers: Record<string, string>;
  multipart: { enabled: boolean; threshold_bytes: number; part_size_bytes: number };
}
interface Result<T> { status: number; body: T; }

async function expectOriginalHeic(page: Page, response: APIResponse): Promise<void> {
  expect(response.status()).toBe(200);
  const bytes = Array.from(new Uint8Array(await response.body()));
  const hash = await page.evaluate(async (original) => {
    const digest = await crypto.subtle.digest('SHA-256', new Uint8Array(original));
    return Array.from(new Uint8Array(digest)).map((byte) => byte.toString(16).padStart(2, '0')).join('');
  }, bytes);
  expect(hash, 'Original HEIC bytes survive preview conversion, review and durable Save').toBe(HEIC_SHA256);
}

async function zipEntryNames(download: Download): Promise<string[]> {
  const stream = await download.createReadStream();
  expect(stream).not.toBeNull();
  const chunks: Uint8Array[] = [];
  let size = 0;
  // CI inspects only tiny synthetic fixtures, never buffers user archives.
  for await (const chunk of stream! as AsyncIterable<Uint8Array>) {
    size += chunk.byteLength;
    expect(size).toBeLessThan(1024 * 1024);
    chunks.push(new Uint8Array(chunk));
  }
  const bytes = new Uint8Array(size);
  let offset = 0;
  for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
  const view = new DataView(bytes.buffer);
  // No ZIP comment: the end record supplies the central directory's exact
  // offset, so compressed/source bytes cannot masquerade as filename records.
  const end = size - 22;
  expect(view.getUint32(end, true)).toBe(0x06054b50);
  const count = view.getUint16(end + 10, true);
  let cursor = view.getUint32(end + 16, true);
  const names: string[] = [];
  for (let index = 0; index < count; index++) {
    expect(view.getUint32(cursor, true)).toBe(0x02014b50);
    const nameLength = view.getUint16(cursor + 28, true);
    const extraLength = view.getUint16(cursor + 30, true);
    const commentLength = view.getUint16(cursor + 32, true);
    names.push(new TextDecoder().decode(bytes.slice(cursor + 46, cursor + 46 + nameLength)));
    cursor += 46 + nameLength + extraLength + commentLength;
  }
  return names;
}

async function api<T>(page: Page, method: string, path: string, body?: unknown): Promise<Result<T>> {
  if (!path.startsWith('/api/')) throw new Error('Acceptance API helper only accepts normal application API paths.');
  return page.evaluate(async ({ method, path, body }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    const response = await fetch(path, {
      method, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    return { status: response.status, body: await response.json() };
  }, { method, path, body }) as Promise<Result<T>>;
}

async function success<T>(page: Page, method: string, path: string, body?: unknown): Promise<T> {
  const response = await api<T>(page, method, path, body);
  expect(response.status, `${method} ${path}`).toBeGreaterThanOrEqual(200);
  expect(response.status, `${method} ${path}`).toBeLessThan(300);
  return response.body;
}

async function mailIds(request: APIRequestContext): Promise<MailSummary[]> {
  const response = await request.get(`${mailUrl}/api/v1/messages?limit=100`);
  expect(response.ok(), 'Mailpit must be reachable').toBeTruthy();
  return ((await response.json()) as { messages: MailSummary[] }).messages;
}

async function login(page: Page, request: APIRequestContext, email: string): Promise<void> {
  if (!EMAILS.includes(email) && !/^library-run-[0-9]+@staging\.invalid$/.test(email)) throw new Error('Acceptance only logs in disposable staging accounts.');
  const before = new Set((await mailIds(request)).map((mail) => mail.ID));
  await page.goto('/login');
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign In', exact: true }).click();
  await expect(page).toHaveURL(/\/login\/two-factor\//);
  let code = '';
  await expect.poll(async () => {
    const message = (await mailIds(request)).find((mail) => !before.has(mail.ID) && mail.To.some((to) => to.Address.toLowerCase() === email));
    if (!message) return false;
    const response = await request.get(`${mailUrl}/api/v1/message/${encodeURIComponent(message.ID)}`);
    const detail = await response.json() as { Text: string; HTML: string };
    code = (detail.Text || detail.HTML).match(/verification code is[\s\S]{0,400}?\b(\d{6})\b/i)?.[1] ?? '';
    return code.length === 6;
  }, { timeout: 30_000, message: `Real SMTP two-factor email for ${email}` }).toBeTruthy();
  await page.getByLabel('Verification Code').fill(code);
  await page.getByRole('button', { name: 'Verify Code', exact: true }).click();
  await expect(page).toHaveURL(eventProfile ? /\/events$/ : /\/feed$/);
  await page.goto('/library');
}

async function registerUploader(page: Page, admin: Page, request: APIRequestContext, adminId: number, suffix: string, organizerApproval = false): Promise<Account> {
  const email = `library-run-${suffix}@staging.invalid`;
  await success(admin, 'POST', `/api/admin/users/${adminId}/invites`, { quantity: 1, expires_in_days: 1 });
  const invite = (await success<{ data: { uuid: string } }>(admin, 'POST', '/api/invites', {})).data.uuid;
  const before = new Set((await mailIds(request)).map((mail) => mail.ID));
  await page.goto(`/register?invite=${invite}`);
  await success(page, 'POST', '/api/auth/register', {
    name: `Disposable uploader ${suffix}`, display_name: `Disposable uploader ${suffix}`, birth_date: '1990-01-01',
    email, password: PASSWORD, password_confirmation: PASSWORD, invite,
  });
  let verification = '';
  await expect.poll(async () => {
    const message = (await mailIds(request)).find((mail) => !before.has(mail.ID) && mail.To.some((to) => to.Address.toLowerCase() === email));
    if (!message) return false;
    const response = await request.get(`${mailUrl}/api/v1/message/${encodeURIComponent(message.ID)}`);
    const detail = await response.json() as { HTML: string };
    verification = detail.HTML.match(/href=["']([^"']*\/email\/verify\/[^"']+)["']/i)?.[1]?.replaceAll('&amp;', '&') ?? '';
    return verification !== '';
  }, { timeout: 30_000, message: 'Real SMTP registration verification link' }).toBeTruthy();
  expect(new URL(verification).origin).toBe(new URL(page.url()).origin);
  await page.goto(verification);
  const accounts = (await success<{ data: Account[] }>(admin, 'GET', '/api/admin/users')).data;
  const uploader = accounts.find((entry) => entry.email === email);
  expect(uploader).toBeTruthy();
  if (organizerApproval) {
    await organizerSearch(admin, email);
    await admin.getByRole('button', { name: `Approve ${uploader!.display_name}`, exact: true }).click();
    await expect(admin.getByRole('button', { name: `Edit Event access for ${uploader!.display_name}`, exact: true })).toBeEnabled();
  } else {
    await success(admin, 'POST', `/api/admin/users/${uploader!.id}/approve`, {});
  }
  await success(admin, 'PATCH', `/api/admin/users/${uploader!.id}`, { library_enabled: true });
  await page.goto('/library');
  await page.evaluate(async () => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    const response = await fetch('/logout', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: '{}' });
    if (!response.ok) throw new Error('Normal logout failed before uploader two-factor login.');
  });
  await login(page, request, email);
  return uploader!;
}

async function organizerSearch(admin: Page, email: string): Promise<void> {
  await admin.goto('/events/organizer');
  await admin.getByLabel('Search attendees', { exact: true }).fill(email);
  const loaded = admin.waitForResponse((response) => new URL(response.url()).pathname === '/api/admin/events/attendees'
    && new URL(response.url()).searchParams.get('search') === email);
  await admin.getByRole('button', { name: 'Search', exact: true }).click();
  expect((await loaded).ok()).toBeTruthy();
  await expect(admin.getByText(email, { exact: true })).toBeVisible();
}

async function setEventAccess(admin: Page, attendee: Account, eventName: string, checked: boolean): Promise<void> {
  await organizerSearch(admin, attendee.email);
  await admin.getByRole('button', { name: `Edit Event access for ${attendee.display_name}`, exact: true }).click();
  const dialog = admin.getByRole('dialog');
  await dialog.getByRole('checkbox', { name: eventName, exact: true }).setChecked(checked);
  await dialog.getByRole('button', { name: 'Save Event access', exact: true }).click();
  await expect(dialog).toHaveCount(0);
}

async function approveHeicOriginal(admin: Page, mediaId: number): Promise<void> {
  await admin.goto('/admin/media');
  const card = admin.locator(`#media-${mediaId}`);
  await expect(card).toBeVisible();
  await expect(card.getByRole('button', { name: 'Approve', exact: true })).toBeDisabled();
  await card.getByRole('button', { name: 'Check original', exact: true }).click();
  const original = card.getByRole('img', { name: 'Verified preview decoded from the stored original', exact: true });
  await expect.poll(() => original.evaluate((image: HTMLImageElement) => image.naturalWidth)).toBeGreaterThan(0);
  await expect(card.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
  const reviewed = admin.waitForResponse((response) => response.url().endsWith(`/api/admin/media/${mediaId}/moderate`) && response.request().method() === 'POST');
  await card.getByRole('button', { name: 'Approve', exact: true }).click();
  expect((await reviewed).ok()).toBeTruthy();
  await expect(card).toHaveCount(0);
}

async function upload(page: Page, type: 'photo' | 'video', title: string, collection: string, eventUlid?: string, photoFilename = 'photo.png'): Promise<Upload> {
  const filename = type === 'photo' ? photoFilename : 'video.mp4';
  await page.goto(eventUlid ? `/events?event_ulid=${eventUlid}` : `/library?collection=${collection}`);
  await page.getByRole('button', { name: eventUlid ? 'Add photos & videos' : 'Upload media', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await dialog.locator('input[type="file"]').setInputFiles(new URL(`./.fixtures/${filename}`, import.meta.url).pathname);
  await dialog.getByPlaceholder('Title (optional)', { exact: true }).fill(title);
  const pendingResponse = page.waitForResponse((response) => response.url().endsWith(eventUlid ? `/api/events/${eventUlid}/uploads` : '/api/library/uploads') && response.request().method() === 'POST');
  const completeResponse = page.waitForResponse((response) => /\/api\/media\/[0-9]+\/(?:multipart\/)?complete$/.test(response.url()) && response.request().method() === 'POST');
  const objectPuts: Response[] = [];
  const capturePut = (response: Response): void => {
    if (response.request().method() === 'PUT' && new URL(response.url()).port === '19000') objectPuts.push(response);
  };
  page.on('response', capturePut);
  try {
    await dialog.getByRole('button', { name: 'Upload', exact: true }).click();
    const pending = await pendingResponse;
    expect(pending.ok()).toBeTruthy();
    const result = await pending.json() as Upload;
    // FileDropzone clears its native input after retaining the File in React.
    // Read the selected size from the actual form request instead of that input.
    const fixtureBytes = (pending.request().postDataJSON() as { size: number }).size;
    expect(fixtureBytes, 'The real upload fixture must contain bytes').toBeGreaterThan(0);
    if (expectMultipartVideo) {
      if (type === 'photo') expect(fixtureBytes, 'Small direct-upload photo fixture').toBeLessThan(64 * 1024);
      else expect(fixtureBytes, 'Video fixture exceeds the opt-in multipart threshold').toBeGreaterThan(1024);
    }
    const source = new URL(result.upload_url);
    expect(['127.0.0.1', 'localhost', '[::1]']).toContain(source.hostname);
    const completed = await completeResponse;
    expect(completed.ok()).toBeTruthy();
    const multipart = new URL(completed.url()).pathname.endsWith('/multipart/complete');
    if (type === 'photo') expect(multipart, 'Photos use direct completion').toBe(false);
    if (expectMultipartVideo) {
      expect(result.multipart.threshold_bytes, 'Opt-in staging multipart configuration reaches the app').toBe(1024);
      expect(multipart, 'The real video upload must exercise multipart completion').toBe(type === 'video');
    }
    const sourcePuts = objectPuts.filter((response) => response.ok() && new URL(response.url()).pathname === source.pathname);
    expect(sourcePuts.length, 'One successful source PUT for the small fixture, excluding thumbnails and failed attempts').toBe(1);
    const sourcePut = sourcePuts[0];
    expect(sourcePut.ok(), 'Real source PUT including browser CORS succeeds').toBeTruthy();
    const partUrl = new URL(sourcePut.url());
    expect(partUrl.searchParams.has('uploadId'), 'Only multipart PUTs carry an upload ID').toBe(multipart);
    if (multipart) {
      expect(fixtureBytes, 'The multipart video fixture fits in one real S3 part').toBeLessThan(result.multipart.part_size_bytes);
      expect(partUrl.searchParams.get('partNumber'), 'Real S3 multipart part number').toBe('1');
      const etag = await sourcePut.headerValue('etag');
      expect(Boolean(etag), 'The real multipart part response contains an ETag').toBe(true);
      const payload = completed.request().postDataJSON() as { parts: { part_number: number; etag: string }[] };
      expect(payload.parts.length, 'Multipart completion submits one uploaded part').toBe(1);
      expect(payload.parts[0].part_number).toBe(1);
      expect(payload.parts[0].etag === etag, 'The browser can read the CORS-exposed part ETag and submit it for completion').toBe(true);
    }
    await expect(dialog).toHaveCount(0, { timeout: 30_000 });
    return result;
  } finally {
    page.off('response', capturePut);
  }
}

async function findItem(page: Page, collection: string, title: string): Promise<Item> {
  const response = await success<{ data: Item[] }>(page, 'GET', `/api/library/items?collection_ulid=${collection}`);
  const item = response.data.find((entry) => entry.title === title);
  expect(item, `Library item ${title}`).toBeTruthy();
  return item!;
}

async function playable(page: Page, ulid: string): Promise<void> {
  await expect.poll(async () => {
    const response = await success<{ data: Item }>(page, 'GET', `/api/library/items/${ulid}`);
    return response.data.media?.video?.status;
  }, { timeout: 160_000, intervals: [2_000, 5_000, 10_000], message: 'Real local FFmpeg HLS output resolves through normal cached playback API' }).toBe('ready');
  await page.goto(`/library?item=${ulid}`);
  const video = page.getByRole('dialog').locator('video');
  await expect(video).toBeVisible();
  await expect.poll(() => video.evaluate((element: HTMLVideoElement) => element.readyState), { timeout: 30_000 }).toBeGreaterThanOrEqual(2);
  await video.evaluate(async (element: HTMLVideoElement) => { element.muted = true; await element.play(); });
  await expect.poll(() => video.evaluate((element: HTMLVideoElement) => element.currentTime), { timeout: 15_000 }).toBeGreaterThan(0.5);
  expect(await video.evaluate((element: HTMLVideoElement) => element.videoWidth)).toBeGreaterThan(0);
  await video.evaluate((element: HTMLVideoElement) => element.pause());
}

async function retainedSegmentUrl(page: Page, ulid: string): Promise<string> {
  const master = await page.request.get(`/api/library/items/${ulid}/hls/master.m3u8`);
  expect(master.ok()).toBeTruthy();
  const playlist = (await master.text()).split(/\r?\n/).find((line) => line.trim() && !line.startsWith('#'));
  expect(playlist).toBeTruthy();
  const rendition = await page.request.get(new URL(playlist!, page.url()).href);
  expect(rendition.ok()).toBeTruthy();
  const segment = (await rendition.text()).split(/\r?\n/).find((line) => line.trim() && !line.startsWith('#'));
  expect(segment).toBeTruthy();
  const redirect = await page.request.get(new URL(segment!, page.url()).href, { maxRedirects: 0 });
  expect(redirect.status()).toBe(302);
  const signed = redirect.headers().location;
  expect(['127.0.0.1', 'localhost', '[::1]']).toContain(new URL(signed).hostname);
  expect((await page.request.get(signed)).ok()).toBeTruthy();
  return signed;
}

test('real private upload, reviewed HLS, quotas, bulk organization and durable Save survive disposable uploader purge', async ({ browser, request }, testInfo) => {
  const contexts: BrowserContext[] = [];
  let adminPage: Page | undefined;
  let recipientAccountId: number | undefined;
  let originalLimit: number | null = null;
  let originalUnlimited = false;
  let originalDefaultLimit: number | null | undefined;
  try {
    const pages: Page[] = [];
    for (const email of EMAILS) {
      const context = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
      contexts.push(context);
      const page = await context.newPage();
      await login(page, request, email);
      pages.push(page);
    }
    const [admin, recipient, outsider] = pages;
    adminPage = admin;
    const adminUsers = await success<{ data: Account[]; settings: { default_storage_limit_bytes: number | null } }>(admin, 'GET', '/api/admin/users');
    const accounts = adminUsers.data;
    const account = (email: string): Account => {
      const result = accounts.find((entry) => entry.email === email);
      if (!result || result.is_deleted || !EMAILS.includes(result.email)) throw new Error('Expected active seeded staging accounts; inspect the disposable Docker staging setup.');
      return result;
    };
    const recipientAccount = account('recipient@staging.invalid');
    recipientAccountId = recipientAccount.id;
    originalLimit = recipientAccount.storage_limit_bytes;
    originalUnlimited = recipientAccount.storage_unlimited;
    originalDefaultLimit = adminUsers.settings.default_storage_limit_bytes;
    const defaultLimit = 2 * 1024 ** 2;
    await success(admin, 'PUT', '/api/admin/storage-settings', { default_storage_limit_bytes: defaultLimit });
    await success(admin, 'PATCH', `/api/admin/users/${recipientAccount.id}`, { storage_limit_bytes: null, storage_unlimited: true });
    const baselineUsage = (await success<{ data: { used_bytes: number; saved_bytes: number } }>(recipient, 'GET', '/api/account/storage')).data;
    const suffix = String(Date.now());
    const uploaderContext = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
    contexts.push(uploaderContext);
    const uploader = await uploaderContext.newPage();
    const uploaderAccount = await registerUploader(uploader, admin, request, account('admin@staging.invalid').id, suffix);
    expect(uploaderAccount.is_admin).toBe(false);
    expect(uploaderAccount.id).not.toBe(1);
    expect(uploaderAccount.storage_limit_bytes).toBeNull();
    expect(uploaderAccount.storage_unlimited).toBe(false);
    expect((await success<{ data: { limit_bytes: number | null } }>(uploader, 'GET', '/api/account/storage')).data.limit_bytes).toBe(defaultLimit);
    const collection = (await success<{ data: { ulid: string } }>(uploader, 'POST', '/api/library/collections', { name: `Received ${suffix}` })).data;
    await success(uploader, 'PUT', `/api/library/collections/${collection.ulid}/sharing`, { user_ids: [recipientAccount.id] });
    const photoTitle = `Staging photo ${suffix}`;
    const videoTitle = `Staging video ${suffix}`;
    const photo = await upload(uploader, 'photo', photoTitle, collection.ulid, undefined, 'photo.heic');
    const video = await upload(uploader, 'video', videoTitle, collection.ulid);
    const photoItem = await findItem(uploader, collection.ulid, photoTitle);
    const videoItem = await findItem(uploader, collection.ulid, videoTitle);
    expect((await api(recipient, 'GET', `/api/library/items/${photoItem.ulid}`)).status).toBe(404);
    expect((await api(outsider, 'GET', `/api/library/items/${videoItem.ulid}`)).status).toBe(404);
    await approveHeicOriginal(admin, photo.data.id);
    await success(admin, 'POST', `/api/admin/media/${video.data.id}/moderate`, { action: 'approve' });
    await recipient.goto(`/library?item=${photoItem.ulid}`);
    const image = recipient.getByRole('dialog').getByRole('img', { name: photoTitle, exact: true });
    await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.naturalWidth)).toBeGreaterThan(0);
    const originalPhoto = await recipient.request.get(`/api/library/items/${photoItem.ulid}/assets/original`);
    await expectOriginalHeic(recipient, originalPhoto);
    await playable(recipient, videoItem.ulid);
    // A changed default affects existing inheriting accounts. The recipient's
    // explicit unlimited override still permits durable Saves under a zero default.
    await success(admin, 'PUT', '/api/admin/storage-settings', { default_storage_limit_bytes: 0 });
    expect((await success<{ data: { limit_bytes: number | null } }>(uploader, 'GET', '/api/account/storage')).data.limit_bytes).toBe(0);
    expect((await api(uploader, 'POST', '/api/library/uploads', { type: 'photo', filename: 'blocked.png', content_type: 'image/png', size: 1 })).status).toBe(422);
    expect((await success<{ data: { limit_bytes: number | null } }>(recipient, 'GET', '/api/account/storage')).data.limit_bytes).toBeNull();
    const savedPhoto = (await success<{ data: Item }>(recipient, 'POST', '/api/library/save', { item_ulid: photoItem.ulid })).data;
    const savedVideo = (await success<{ data: Item }>(recipient, 'POST', '/api/library/save', { item_ulid: videoItem.ulid })).data;
    const usage = (await success<{ data: { used_bytes: number; saved_bytes: number } }>(recipient, 'GET', '/api/account/storage')).data;
    expect(usage.saved_bytes).toBeGreaterThan(baselineUsage.saved_bytes);
    expect(usage.used_bytes - baselineUsage.used_bytes).toBe(usage.saved_bytes - baselineUsage.saved_bytes);
    await success(admin, 'PATCH', `/api/admin/users/${recipientAccount.id}`, { storage_limit_bytes: usage.used_bytes });
    const repeatedSave = await success<{ data: Item }>(recipient, 'POST', '/api/library/save', { item_ulid: videoItem.ulid });
    expect(repeatedSave.data.ulid).toBe(savedVideo.ulid); // Idempotent Save at the limit.
    const quotaFailure = await api<{ errors?: { storage?: string[] } }>(recipient, 'POST', '/api/library/uploads', { type: 'photo', filename: 'photo.png', content_type: 'image/png', size: 1 });
    expect(quotaFailure.status).toBe(422);
    expect(quotaFailure.body.errors?.storage).toBeDefined();
    const organized = (await success<{ data: { ulid: string } }>(recipient, 'POST', '/api/library/collections', { name: `Organized ${suffix}` })).data;
    const moved = (await success<{ data: { ulid: string } }>(recipient, 'POST', '/api/library/collections', { name: `Moved ${suffix}` })).data;
    const ownItems = [savedPhoto.ulid, savedVideo.ulid];
    await success(recipient, 'POST', '/api/library/items/bulk', { action: 'add', item_ulids: ownItems, collection_ulid: organized.ulid });
    await success(recipient, 'POST', '/api/library/items/bulk', { action: 'move', item_ulids: ownItems, collection_ulid: moved.ulid, source_collection_ulid: organized.ulid });
    expect((await success<{ data: Item[] }>(recipient, 'GET', `/api/library/items?collection_ulid=${organized.ulid}`)).data).toHaveLength(0);
    expect((await success<{ data: Item[] }>(recipient, 'GET', `/api/library/items?collection_ulid=${moved.ulid}`)).data).toHaveLength(2);
    await success(recipient, 'PUT', `/api/library/collections/${moved.ulid}/sharing`, { user_ids: [account('outsider@staging.invalid').id] });
    expect((await api(outsider, 'GET', `/api/library/items/${savedPhoto.ulid}`)).status).toBe(404);
    expect((await api(outsider, 'GET', `/api/library/items/${savedVideo.ulid}`)).status).toBe(404);
    await success(recipient, 'PUT', `/api/library/collections/${moved.ulid}/sharing`, { user_ids: [] });
    await success(uploader, 'PUT', `/api/library/collections/${collection.ulid}/sharing`, { user_ids: [] });
    expect((await api(recipient, 'GET', `/api/library/items/${photoItem.ulid}`)).status).toBe(404);
    // Destructive lifecycle step is bound to the exact disposable seeded identity.
    const freshAccounts = (await success<{ data: Account[] }>(admin, 'GET', '/api/admin/users')).data;
    const disposable = freshAccounts.find((entry) => entry.id === uploaderAccount.id);
    expect(disposable?.email).toBe(`library-run-${suffix}@staging.invalid`);
    expect(disposable?.email).toMatch(/^library-run-[0-9]+@staging\.invalid$/);
    expect(disposable?.is_admin).toBe(false);
    await success(admin, 'DELETE', `/api/admin/users/${uploaderAccount.id}`);
    await recipient.goto(`/library?item=${savedPhoto.ulid}`);
    await expect.poll(() => recipient.getByRole('dialog').getByRole('img', { name: photoTitle, exact: true }).evaluate((element: HTMLImageElement) => element.naturalWidth)).toBeGreaterThan(0);
    await expectOriginalHeic(recipient, await recipient.request.get(`/api/library/items/${savedPhoto.ulid}/assets/original`));
    await playable(recipient, savedVideo.ulid);
    const segmentUrl = await retainedSegmentUrl(recipient, savedVideo.ulid);
    expect((await outsider.request.get(`/api/library/items/${savedVideo.ulid}/hls/master.m3u8`)).status()).toBe(404);
    await recipient.screenshot({ path: testInfo.outputPath('retained-real-hls.png'), fullPage: true });
    await testInfo.attach('disposable-media-identifiers', { body: JSON.stringify({ photo_ulid: photo.data.ulid, video_ulid: video.data.ulid, saved_photo_ulid: savedPhoto.ulid, saved_video_ulid: savedVideo.ulid }), contentType: 'application/json' });
    await success(recipient, 'POST', '/api/library/items/bulk', { action: 'delete', item_ulids: ownItems });
    expect((await api(recipient, 'GET', `/api/library/items/${savedPhoto.ulid}`)).status).toBe(404);
    expect((await api(recipient, 'GET', `/api/library/items/${savedVideo.ulid}`)).status).toBe(404);
    expect((await recipient.request.get(segmentUrl)).status(), 'Final reference removal physically purges the real HLS segment').toBe(404);
    expect((await success<{ data: { used_bytes: number } }>(recipient, 'GET', '/api/account/storage')).data.used_bytes).toBe(baselineUsage.used_bytes);
  } finally {
    try {
      if (adminPage && recipientAccountId !== undefined) {
        await success(adminPage, 'PATCH', `/api/admin/users/${recipientAccountId}`, { storage_limit_bytes: originalLimit, storage_unlimited: originalUnlimited });
      }
    } finally {
      try {
        if (adminPage && originalDefaultLimit !== undefined) {
          await success(adminPage, 'PUT', '/api/admin/storage-settings', { default_storage_limit_bytes: originalDefaultLimit });
        }
      } finally {
        for (const context of contexts) await context.close();
      }
    }
  }
});


interface EventMedia {
  id: number; ulid: string; url: string | null; thumbnail_url: string | null;
  video?: { status: string; master_url: string | null }; download_url: string;
  photo_versions?: { visible_count: number; preferred_ulid: string; is_preferred: boolean } | null;
  photo_details?: { width: number | null; height: number | null; has_gps: boolean; has_capture_time: boolean; has_camera: boolean } | null;
}

async function checkPhotoVersions(admin: Page, uploader: Page, viewer: Page, viewerId: number, suffix: string): Promise<void> {
  const event = (await success<{ data: { ulid: string } }>(admin, 'POST', '/api/admin/events', {
    name: `Photo versions ${suffix}`, visibility: 'everyone', timezone: 'UTC', member_ids: [],
  })).data;
  const heic = await upload(uploader, 'photo', `Camera original ${suffix}`, '', event.ulid, 'photo.heic');
  const jpeg = await upload(uploader, 'photo', `Location copy ${suffix}`, '', event.ulid, 'photo-location.jpg');
  const gallery = async (): Promise<EventMedia[]> => (await success<{ data: EventMedia[] }>(viewer, 'GET', `/api/events/media?event_ulid=${event.ulid}`)).data;
  const metadataPreferred = await gallery();
  expect(metadataPreferred.map((item) => item.ulid), 'Equal-resolution versions collapse to the metadata-rich original').toEqual([jpeg.data.ulid]);
  expect(metadataPreferred[0].photo_details).toEqual(expect.objectContaining({ width: 64, height: 48, has_gps: true, has_capture_time: true, has_camera: true }));
  expect(metadataPreferred[0].photo_versions).toEqual({ visible_count: 2, preferred_ulid: jpeg.data.ulid, is_preferred: true });
  const png = await upload(uploader, 'photo', `Larger copy ${suffix}`, '', event.ulid, 'photo-larger.png');
  const resolutionPreferred = await gallery();
  expect(resolutionPreferred.map((item) => item.ulid), 'Resolution outranks metadata without deleting any version').toEqual([png.data.ulid]);
  expect(resolutionPreferred[0].photo_details).toEqual(expect.objectContaining({ width: 128, height: 96 }));
  expect(resolutionPreferred[0].photo_versions).toEqual({ visible_count: 3, preferred_ulid: png.data.ulid, is_preferred: true });
  const versions = await success<{ data: EventMedia[]; preferred_ulid: string }>(viewer, 'GET', `/api/events/media/${heic.data.ulid}/variants`);
  expect(versions.data.map((item) => item.ulid)).toEqual([png.data.ulid, jpeg.data.ulid, heic.data.ulid]);
  expect(versions.preferred_ulid).toBe(png.data.ulid);
  for (const version of versions.data) expect((await viewer.request.get(version.download_url)).status()).toBe(200);
  const original = (await success<{ data: EventMedia }>(viewer, 'GET', `/api/events/media/${heic.data.ulid}`)).data;
  await expectOriginalHeic(viewer, await viewer.request.get(original.download_url));
  const jpegDownload = await viewer.request.get(versions.data[1].download_url);
  expect(new TextDecoder().decode(await jpegDownload.body())).toContain('Exif\0\0');
  expect(JSON.stringify(versions)).not.toMatch(/GPSLatitude|GPSLongitude|latitude|longitude|2026:10:22/);
  await viewer.goto(`/events?event_ulid=${event.ulid}&item=${png.data.ulid}`);
  await viewer.getByRole('dialog').getByRole('button', { name: 'Show versions', exact: true }).click();
  await viewer.getByRole('region', { name: 'Photo versions', exact: true }).getByRole('button', { name: /^View version 3 · HEIC · / }).click();
  await expect(viewer).toHaveURL(new RegExp(`item=${heic.data.ulid}`));
  await expect(viewer.getByRole('dialog').getByRole('link', { name: 'Download', exact: true })).toHaveAttribute('href', `/api/events/media/${heic.data.ulid}/download`);
  const saving = viewer.waitForResponse((response) => response.url().endsWith('/api/library/save') && response.request().method() === 'POST');
  await viewer.getByRole('dialog').getByRole('button', { name: 'Save to my collection', exact: true }).click();
  const saved = await saving;
  expect(saved.ok()).toBeTruthy();
  expect(saved.request().postDataJSON()).toEqual(expect.objectContaining({ media_ulid: heic.data.ulid }));
  const savedItem = (await saved.json() as { data: { ulid: string } }).data;
  await success(viewer, 'POST', '/api/library/items/bulk', { action: 'delete', item_ulids: [savedItem.ulid] });
  await success(viewer, 'POST', '/api/favorites', { type: 'media', id: heic.data.id });
  const favorite = await success<{ data: EventMedia[] }>(viewer, 'GET', `/api/events/media?event_ulid=${event.ulid}&favorites_of=${viewerId}`);
  expect(favorite.data.map((item) => item.ulid), 'Favorites retain the selected original even when another version is preferred').toEqual([heic.data.ulid]);
  expect((await success<{ data: EventMedia }>(viewer, 'GET', `/api/events/media/${heic.data.ulid}`)).data.ulid).toBe(heic.data.ulid);
}

test('Event profile real media inherits restricted access, visible favorites and private retained Saves', async ({ browser, request }, testInfo) => {
  test.skip(!eventProfile, 'Event deployment profile acceptance');
  const contexts: BrowserContext[] = [];
  try {
    const pages: Page[] = [];
    for (const email of EMAILS) {
      const context = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
      contexts.push(context);
      const page = await context.newPage();
      await login(page, request, email);
      pages.push(page);
    }
    const [admin, recipient, outsider] = pages;
    const accounts = (await success<{ data: Account[] }>(admin, 'GET', '/api/admin/users')).data;
    const adminId = accounts.find((account) => account.email === 'admin@staging.invalid')!.id;
    const recipientId = accounts.find((account) => account.email === 'recipient@staging.invalid')!.id;
    const suffix = String(Date.now());
    const uploaderContext = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
    contexts.push(uploaderContext);
    const uploader = await uploaderContext.newPage();
    const account = await registerUploader(uploader, admin, request, adminId, suffix, true);
    // Event profile grants Library access without the primary deployment's premium flag.
    await success(admin, 'PATCH', `/api/admin/users/${account.id}`, { library_enabled: false });
    const recipientAccount = accounts.find((entry) => entry.id === recipientId)!;
    const restrictedName = `Private gathering ${suffix}`;
    const restricted = (await success<{ data: { ulid: string } }>(admin, 'POST', '/api/admin/events', {
      name: restrictedName, description: 'Selected attendees', visibility: 'specific', timezone: 'America/New_York', member_ids: [account.id],
    })).data;
    // Membership is an internal organizer action; no email/join link grants it.
    await setEventAccess(admin, recipientAccount, restrictedName, true);
    const shared = (await success<{ data: { ulid: string } }>(admin, 'POST', '/api/admin/events', {
      name: `All attendees ${suffix}`, visibility: 'everyone', timezone: 'UTC', member_ids: [],
    })).data;
    // Exercise the real batch UI: one bad file must not lose/reupload good files.
    await uploader.goto(`/events?event_ulid=${shared.ulid}`);
    await uploader.getByRole('button', { name: 'Add photos & videos', exact: true }).click();
    const batch = uploader.getByRole('dialog');
    let batchReservations = 0;
    const countReservation = (sent: Request): void => {
      if (sent.method() === 'POST' && new URL(sent.url()).pathname === `/api/events/${shared.ulid}/uploads`) batchReservations += 1;
    };
    uploader.on('request', countReservation);
    try {
      await batch.locator('input[type="file"]').setInputFiles(['batch-first.png', 'unsupported.txt', 'batch-last.png']
        .map((name) => new URL(`./.fixtures/${name}`, import.meta.url).pathname));
      await expect(batch.getByRole('img', { name: 'Preview of batch-first.png', exact: true })).toBeVisible();
      await batch.getByRole('button', { name: 'Upload', exact: true }).click();
      await expect(batch.getByRole('status').filter({ hasText: '2 of 3 uploaded' })).toBeVisible();
      await expect(batch.getByRole('alert')).toContainText('Only image and video files');
      await batch.getByRole('button', { name: 'Retry failed', exact: true }).click();
      await expect(batch.getByRole('button', { name: 'Retry failed', exact: true })).toBeEnabled();
      await batch.getByRole('button', { name: 'Remove unsupported.txt', exact: true }).click();
      await batch.getByRole('button', { name: 'Done', exact: true }).click();
      await expect(batch).toHaveCount(0);
      expect(batchReservations, 'Successful batch files are not uploaded again on retry').toBe(2);
    } finally {
      uploader.off('request', countReservation);
    }
    expect((await success<{ data: { ulid: string }[] }>(outsider, 'GET', '/api/events')).data.map((event) => event.ulid)).not.toContain(restricted.ulid);
    for (const path of ['/api/chat/conversations', '/api/characters', '/api/users', '/api/stories']) {
      expect((await api(outsider, 'GET', path)).status).toBe(404);
    }
    const photoTitle = `Event photo ${suffix}`;
    const videoTitle = `Event video ${suffix}`;
    const photo = await upload(uploader, 'photo', photoTitle, '', restricted.ulid, 'photo.heic');
    const video = await upload(uploader, 'video', videoTitle, '', restricted.ulid);
    await organizerSearch(admin, account.email);
    const exemptionSaved = admin.waitForResponse((response) => response.url().endsWith(`/api/admin/users/${account.id}`) && response.request().method() === 'PATCH');
    await admin.getByRole('checkbox', { name: `Skip upload review for ${account.display_name}`, exact: true }).click();
    expect((await exemptionSaved).ok()).toBeTruthy();
    await expect(admin.getByRole('checkbox', { name: `Skip upload review for ${account.display_name}`, exact: true })).toBeChecked();
    const publicPhoto = await upload(uploader, 'photo', `Shared event ${suffix}`, '', shared.ulid);
    expect((await api(recipient, 'GET', `/api/events/media/${photo.data.ulid}`)).status).toBe(404);
    expect((await api(recipient, 'GET', `/api/events/media/${publicPhoto.data.ulid}`)).status,
      'An admin-exempt attendee publishes new Event uploads without a manual review action').toBe(200);
    await checkPhotoVersions(admin, uploader, recipient, recipientId, suffix);
    await approveHeicOriginal(admin, photo.data.id);
    await success(admin, 'POST', `/api/admin/media/${video.data.id}/moderate`, { action: 'approve' });
    const photoDetail = (await success<{ data: EventMedia }>(recipient, 'GET', `/api/events/media/${photo.data.ulid}`)).data;
    await recipient.goto(`/events?media=${photo.data.ulid}`);
    await expect.poll(() => recipient.getByRole('dialog').getByRole('img').evaluate((image: HTMLImageElement) => image.naturalWidth)).toBeGreaterThan(0);
    const downloadedPhoto = await recipient.request.get(photoDetail.download_url);
    const photoExtension = downloadedPhoto.headers()['content-type'].split(';')[0] === 'image/heif' ? 'heif' : 'heic';
    expect(downloadedPhoto.headers()['content-disposition']).toContain(`.${photoExtension}`);
    await expectOriginalHeic(recipient, downloadedPhoto);
    await expect.poll(async () => (await success<{ data: EventMedia }>(recipient, 'GET', `/api/events/media/${video.data.ulid}`)).data.video?.status,
      { timeout: 160_000, intervals: [2_000, 5_000, 10_000] }).toBe('ready');
    await recipient.goto(`/events?media=${video.data.ulid}`);
    const player = recipient.getByRole('dialog').locator('video');
    await expect.poll(() => player.evaluate((element: HTMLVideoElement) => element.readyState), { timeout: 30_000 }).toBeGreaterThanOrEqual(2);
    await player.evaluate(async (element: HTMLVideoElement) => { element.muted = true; await element.play(); });
    await expect.poll(() => player.evaluate((element: HTMLVideoElement) => element.currentTime)).toBeGreaterThan(0.5);
    await player.evaluate((element: HTMLVideoElement) => element.pause());
    await success(recipient, 'POST', '/api/favorites', { type: 'media', id: video.data.id });
    await success(admin, 'PUT', '/api/admin/events/settings', { featured_curator_ids: [recipientId] });
    const visibleIds = async (page: Page, query: string): Promise<string[]> => (await success<{ data: EventMedia[] }>(page, 'GET', `/api/events/media?${query}`)).data.map((item) => item.ulid);
    expect(await visibleIds(uploader, 'featured=1')).toContain(video.data.ulid);
    expect(await visibleIds(outsider, `favorites_of=${recipientId}`)).not.toContain(video.data.ulid);
    expect(await visibleIds(outsider, `event_ulid=${shared.ulid}`)).toContain(publicPhoto.data.ulid);
    await recipient.goto(`/events?event_ulid=${restricted.ulid}`);
    await recipient.getByRole('checkbox', { name: `Select ${photoTitle}`, exact: true }).check();
    await recipient.getByRole('checkbox', { name: `Select ${videoTitle}`, exact: true }).check();
    const downloading = recipient.waitForEvent('download');
    await recipient.getByRole('button', { name: 'Download selected (ZIP)', exact: true }).click();
    const download = await downloading;
    expect(download.suggestedFilename()).toBe('selected-media.zip');
    expect(await download.failure()).toBeNull();
    expect((await zipEntryNames(download)).sort()).toEqual([`media-${photo.data.ulid}.${photoExtension}`, `media-${video.data.ulid}.mp4`].sort());
    const bulkSaved = recipient.waitForResponse((response) => response.url().endsWith('/api/events/media/bulk-save') && response.request().method() === 'POST');
    await recipient.getByRole('button', { name: 'Save selected to my collection', exact: true }).click();
    expect((await bulkSaved).ok()).toBeTruthy();
    const savedItems = (await success<{ data: Item[] }>(recipient, 'GET', '/api/library/items')).data;
    const savedPhoto = savedItems.find((item) => item.title === photoTitle)!;
    const savedVideo = savedItems.find((item) => item.title === videoTitle)!;
    expect(savedPhoto).toBeTruthy();
    expect(savedVideo).toBeTruthy();
    await setEventAccess(admin, recipientAccount, restrictedName, false);
    for (const media of [photo, video]) {
      expect((await api(recipient, 'GET', `/api/events/media/${media.data.ulid}`)).status).toBe(404);
      expect((await recipient.request.get(`/api/events/media/${media.data.ulid}/download`)).status()).toBe(404);
    }
    if (photoDetail.url) expect((await recipient.request.get(photoDetail.url)).status()).toBe(404);
    expect((await recipient.request.get(`/api/media/${video.data.id}/hls/master.m3u8`)).status()).toBe(404);
    expect(await visibleIds(recipient, 'featured=1')).not.toContain(video.data.ulid);
    await playable(recipient, savedVideo.ulid);
    expect((await api(outsider, 'GET', `/api/library/items/${savedPhoto.ulid}`)).status).toBe(404);
    const disposable = (await success<{ data: Account[] }>(admin, 'GET', '/api/admin/users')).data.find((entry) => entry.id === account.id);
    expect(disposable?.email).toBe(`library-run-${suffix}@staging.invalid`);
    expect(disposable?.is_admin).toBe(false);
    await success(admin, 'DELETE', `/api/admin/users/${account.id}`);
    await recipient.goto(`/library?item=${savedPhoto.ulid}`);
    await expect.poll(() => recipient.getByRole('dialog').getByRole('img').evaluate((element: HTMLImageElement) => element.naturalWidth)).toBeGreaterThan(0);
    await expectOriginalHeic(recipient, await recipient.request.get(`/api/library/items/${savedPhoto.ulid}/assets/original`));
    const retainedDownload = await recipient.request.get(`/api/library/items/${savedPhoto.ulid}/download`);
    expect(retainedDownload.headers()['content-disposition']).toContain(`.${photoExtension}`);
    await expectOriginalHeic(recipient, retainedDownload);
    await playable(recipient, savedVideo.ulid);
    await recipient.screenshot({ path: testInfo.outputPath('event-retained-real-hls.png'), fullPage: true });
    await success(recipient, 'POST', '/api/library/items/bulk', { action: 'delete', item_ulids: [savedPhoto.ulid, savedVideo.ulid] });
  } finally {
    for (const context of contexts) await context.close();
  }
});
