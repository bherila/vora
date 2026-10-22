import { type APIRequestContext, type BrowserContext, expect, type Page, test } from '@playwright/test';

let mailUrl = 'http://127.0.0.1:18025';

test.beforeAll(async ({ request }, testInfo) => {
  mailUrl = String(testInfo.config.metadata.mailUrl);
  expect((await request.get(`${mailUrl}/api/v1/messages?limit=1`)).ok()).toBeTruthy();
});
const PASSWORD = 'Local-Staging-Only-2026!';
const EMAILS = ['admin', 'recipient', 'outsider'].map((role) => `${role}@staging.invalid`);

interface MailSummary { ID: string; To: { Address: string }[]; }
interface Account { id: number; email: string; is_admin: boolean; is_deleted: boolean; storage_limit_bytes: number | null; }
interface Item { ulid: string; title: string; source: string; media: { video?: { status: string; master_url: string | null } } | null; }
interface Upload { data: { id: number; ulid: string }; upload_url: string; upload_headers: Record<string, string>; }
interface Result<T> { status: number; body: T; }

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
  await expect(page).toHaveURL(/\/feed$/);
  await page.goto('/library');
}

async function registerUploader(page: Page, admin: Page, request: APIRequestContext, adminId: number, suffix: string): Promise<Account> {
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
  await success(admin, 'POST', `/api/admin/users/${uploader!.id}/approve`, {});
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

async function upload(page: Page, type: 'photo' | 'video', title: string, collection: string): Promise<Upload> {
  const filename = type === 'photo' ? 'photo.png' : 'video.mp4';
  await page.goto(`/library?collection=${collection}`);
  await page.getByRole('button', { name: 'Upload media', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await dialog.locator('input[type="file"]').setInputFiles(new URL(`./.fixtures/${filename}`, import.meta.url).pathname);
  await dialog.getByPlaceholder('Title (optional)', { exact: true }).fill(title);
  const pendingResponse = page.waitForResponse((response) => response.url().endsWith('/api/library/uploads') && response.request().method() === 'POST');
  const completeResponse = page.waitForResponse((response) => /\/api\/media\/[0-9]+\/complete$/.test(response.url()) && response.request().method() === 'POST');
  const objectPut = page.waitForResponse((response) => response.request().method() === 'PUT' && new URL(response.url()).port === '19000');
  await dialog.getByRole('button', { name: 'Upload', exact: true }).click();
  const pending = await pendingResponse;
  expect(pending.ok()).toBeTruthy();
  const result = await pending.json() as Upload;
  expect(['127.0.0.1', 'localhost', '[::1]']).toContain(new URL(result.upload_url).hostname);
  expect((await objectPut).ok(), 'Real browser PUT to private object storage, including CORS').toBeTruthy();
  expect((await completeResponse).ok()).toBeTruthy();
  await expect(dialog).toHaveCount(0, { timeout: 30_000 });
  return result;
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
    const accounts = (await success<{ data: Account[] }>(admin, 'GET', '/api/admin/users')).data;
    const account = (email: string): Account => {
      const result = accounts.find((entry) => entry.email === email);
      if (!result || result.is_deleted || !EMAILS.includes(result.email)) throw new Error('Expected active seeded staging accounts; inspect the disposable Docker staging setup.');
      return result;
    };
    const recipientAccount = account('recipient@staging.invalid');
    recipientAccountId = recipientAccount.id;
    originalLimit = recipientAccount.storage_limit_bytes;
    await success(admin, 'PATCH', `/api/admin/users/${recipientAccount.id}`, { storage_limit_bytes: null });
    const baselineUsage = (await success<{ data: { used_bytes: number; saved_bytes: number } }>(recipient, 'GET', '/api/account/storage')).data;
    const suffix = String(Date.now());
    const uploaderContext = await browser.newContext({ baseURL: testInfo.project.use.baseURL });
    contexts.push(uploaderContext);
    const uploader = await uploaderContext.newPage();
    const uploaderAccount = await registerUploader(uploader, admin, request, account('admin@staging.invalid').id, suffix);
    expect(uploaderAccount.is_admin).toBe(false);
    expect(uploaderAccount.id).not.toBe(1);
    const collection = (await success<{ data: { ulid: string } }>(uploader, 'POST', '/api/library/collections', { name: `Received ${suffix}` })).data;
    await success(uploader, 'PUT', `/api/library/collections/${collection.ulid}/sharing`, { user_ids: [recipientAccount.id] });
    const photoTitle = `Staging photo ${suffix}`;
    const videoTitle = `Staging video ${suffix}`;
    const photo = await upload(uploader, 'photo', photoTitle, collection.ulid);
    const video = await upload(uploader, 'video', videoTitle, collection.ulid);
    const photoItem = await findItem(uploader, collection.ulid, photoTitle);
    const videoItem = await findItem(uploader, collection.ulid, videoTitle);
    expect((await api(recipient, 'GET', `/api/library/items/${photoItem.ulid}`)).status).toBe(404);
    expect((await api(outsider, 'GET', `/api/library/items/${videoItem.ulid}`)).status).toBe(404);
    for (const media of [photo, video]) await success(admin, 'POST', `/api/admin/media/${media.data.id}/moderate`, { action: 'approve' });
    await recipient.goto(`/library?item=${photoItem.ulid}`);
    const image = recipient.getByRole('dialog').getByRole('img', { name: photoTitle, exact: true });
    await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.naturalWidth)).toBeGreaterThan(0);
    await playable(recipient, videoItem.ulid);
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
    if (adminPage && recipientAccountId !== undefined) {
      try { await success(adminPage, 'PATCH', `/api/admin/users/${recipientAccountId}`, { storage_limit_bytes: originalLimit }); }
      finally { for (const context of contexts) await context.close(); }
    } else {
      for (const context of contexts) await context.close();
    }
  }
});

