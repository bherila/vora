import { type APIRequestContext, type BrowserContext, expect, type Page, type Request, type Response, test } from '@playwright/test';

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
const EMAILS = ['admin', 'recipient', 'outsider'].map((role) => `${role}@staging.invalid`);

interface MailSummary { ID: string; To: { Address: string }[]; }
interface Account { id: number; email: string; is_admin: boolean; is_deleted: boolean; storage_limit_bytes: number | null; storage_unlimited: boolean; }
interface Item { ulid: string; title: string; source: string; media: { video?: { status: string; master_url: string | null } } | null; }
interface Upload {
  data: { id: number; ulid: string };
  upload_url: string;
  upload_headers: Record<string, string>;
  multipart: { enabled: boolean; threshold_bytes: number; part_size_bytes: number };
}
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
  await expect(page).toHaveURL(eventProfile ? /\/events$/ : /\/feed$/);
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

async function upload(page: Page, type: 'photo' | 'video', title: string, collection: string, eventUlid?: string): Promise<Upload> {
  const filename = type === 'photo' ? 'photo.png' : 'video.mp4';
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
      if (type === 'photo') expect(fixtureBytes, 'Tiny direct-upload photo fixture').toBeLessThan(1024);
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


interface EventMedia { id: number; ulid: string; url: string | null; thumbnail_url: string | null; video?: { status: string; master_url: string | null }; download_url: string; }

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
    const account = await registerUploader(uploader, admin, request, adminId, suffix);
    // Event profile grants Library access without the primary deployment's premium flag.
    await success(admin, 'PATCH', `/api/admin/users/${account.id}`, { library_enabled: false });
    const members = [account.id, recipientId];
    const restricted = (await success<{ data: { ulid: string } }>(admin, 'POST', '/api/admin/events', {
      name: `Private gathering ${suffix}`, description: 'Selected attendees', visibility: 'specific', timezone: 'America/New_York', member_ids: members,
    })).data;
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
    const photo = await upload(uploader, 'photo', `Event photo ${suffix}`, '', restricted.ulid);
    const video = await upload(uploader, 'video', `Event video ${suffix}`, '', restricted.ulid);
    const publicPhoto = await upload(uploader, 'photo', `Shared event ${suffix}`, '', shared.ulid);
    expect((await api(recipient, 'GET', `/api/events/media/${photo.data.ulid}`)).status).toBe(404);
    for (const media of [photo, video, publicPhoto]) await success(admin, 'POST', `/api/admin/media/${media.data.id}/moderate`, { action: 'approve' });
    const photoDetail = (await success<{ data: EventMedia }>(recipient, 'GET', `/api/events/media/${photo.data.ulid}`)).data;
    await recipient.goto(`/events?media=${photo.data.ulid}`);
    await expect.poll(() => recipient.getByRole('dialog').getByRole('img').evaluate((image: HTMLImageElement) => image.naturalWidth)).toBeGreaterThan(0);
    expect((await recipient.request.get(photoDetail.download_url)).headers()['content-disposition']).toContain('attachment');
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
    const savedPhoto = (await success<{ data: Item }>(recipient, 'POST', '/api/library/save', { media_ulid: photo.data.ulid })).data;
    const savedVideo = (await success<{ data: Item }>(recipient, 'POST', '/api/library/save', { media_ulid: video.data.ulid })).data;
    await success(admin, 'PATCH', `/api/admin/events/${restricted.ulid}`, { member_ids: [account.id] });
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
    await playable(recipient, savedVideo.ulid);
    await recipient.screenshot({ path: testInfo.outputPath('event-retained-real-hls.png'), fullPage: true });
    await success(recipient, 'POST', '/api/library/items/bulk', { action: 'delete', item_ulids: [savedPhoto.ulid, savedVideo.ulid] });
  } finally {
    for (const context of contexts) await context.close();
  }
});
