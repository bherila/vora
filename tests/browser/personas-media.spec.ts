import { expect, type Page, test } from '@playwright/test';

import { login, peerContext, type TestAccounts } from './helpers';

interface Persona { id: number; ulid: string; display_name: string; }
interface MediaFixture { ulid: string; title: string; }

async function createPersona(page: Page, name: string, linked: boolean): Promise<Persona> {
  await page.goto('/personas/new');
  await page.getByLabel('Display name', { exact: true }).fill(name);
  await page.getByLabel('Who can see this character?', { exact: true }).selectOption('everyone');
  await page.getByRole('radio', { name: linked ? /^Linked/ : /^Separate/ }).check();
  const saved = page.waitForResponse((response) => response.url().endsWith('/api/characters') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Save character', exact: true }).click();
  const response = await saved;
  expect(response.status()).toBe(201);
  // Creation immediately navigates away, so read the persisted record after
  // navigation rather than racing Chromium's discarded POST response body.
  await expect(page).toHaveURL(/\/c\/[0-9A-HJKMNP-TV-Z]{26}\/edit$/);
  await expect(page.getByRole('heading', { name: `Edit ${name}`, exact: true })).toBeVisible();
  const ulid = new URL(page.url()).pathname.split('/')[2];
  const listing = await page.request.get('/api/characters');
  expect(listing.ok()).toBeTruthy();
  const { data } = await listing.json() as { data: Persona[] };
  const persona = data.find((record) => record.ulid === ulid);
  if (!persona) throw new Error('Created persona missing from owner listing.');
  expect(persona.display_name).toBe(name);
  return persona;
}

test('Linked and Separate personas retain their privacy across profiles, posts, followers, and media', async ({ browser, page, request }) => {
  const fixture = await request.get('/__browser/fixtures');
  expect(fixture.ok()).toBeTruthy();
  const accounts = await fixture.json() as TestAccounts;
  const bobContext = await peerContext(browser);
  const bob = await bobContext.newPage();
  try {
    await login(page, accounts.alice, 'Alice');
    await login(bob, accounts.bob, 'Bob');
    const linked = await createPersona(page, 'Kira', true);
    const separate = await createPersona(page, 'Vex', false);
    const mediaFixture = await request.get('/__browser/media-fixtures', { headers: { 'x-browser-persona': String(separate.id) } });
    expect(mediaFixture.ok()).toBeTruthy();
    const media = await mediaFixture.json() as { account: MediaFixture; persona: MediaFixture };
    await page.goto('/feed');
    await page.getByRole('button', { name: 'Account and identity menu (currently Alice)' }).click();
    await page.getByRole('menuitemradio', { name: 'Vex', exact: true }).click();
    await expect(page.getByText("New posts, uploads, and stories will be from Vex. What you can see doesn't change.", { exact: false })).toBeVisible();
    await page.getByPlaceholder('Share an update', { exact: true }).fill('Separate persona followers update');
    await page.getByRole('button', { name: 'As Vex', exact: true }).click();
    await page.getByLabel('Who can see this?', { exact: true }).selectOption('followers');
    await expect(page.getByLabel('Post as', { exact: true })).toHaveValue(String(separate.id));
    const published = page.waitForResponse((response) => response.url().endsWith('/api/posts') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Post', exact: true }).click();
    const publishedResponse = await published;
    expect(publishedResponse.status()).toBe(201);
    const { data: post } = await publishedResponse.json() as { data: { ulid: string } };
    // Authoring as a persona must not change the owner's viewing permissions.
    await page.goto(`/m/${media.account.ulid}`);
    await expect(page.getByRole('heading', { name: media.account.title, exact: true })).toBeVisible();

    await bob.goto(`/c/${linked.ulid}`);
    await expect(bob.getByText('A persona of', { exact: false })).toBeVisible();
    await expect(bob.locator(`a[href="/users/${accounts.alice.id}"]`)).toContainText('Alice');
    await bob.goto(`/users/${accounts.alice.id}`);
    await expect(bob.getByRole('button', { name: 'Kira', exact: true })).toBeVisible();
    await expect(bob.getByRole('button', { name: 'Vex', exact: true })).toHaveCount(0);
    await bob.goto(`/c/${separate.ulid}`);
    await expect(bob.getByRole('heading', { name: 'Vex', exact: true })).toBeVisible();
    await expect.poll(() => bob.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await expect(bob.getByText('A persona of', { exact: false })).toHaveCount(0);
    await expect(bob.locator(`a[href="/users/${accounts.alice.id}"]`)).toHaveCount(0);
    expect((await bob.request.get(`/api/posts/by-ulid/${post.ulid}`)).status()).toBe(404);
    const assetUrl = `/api/media/by-ulid/${media.persona.ulid}/asset/original`;
    expect((await bob.request.get(assetUrl)).status()).toBe(404);
    await bob.getByRole('button', { name: 'Follow Vex', exact: true }).click();
    await expect(bob.getByRole('button', { name: 'Following Vex', exact: true })).toBeDisabled();
    await bob.getByRole('button', { name: '1 follower', exact: true }).click();
    await expect(bob.getByRole('dialog').getByText('Bob', { exact: true })).toBeVisible();
    await expect(bob.getByRole('dialog').getByText('Alice', { exact: true })).toHaveCount(0);
    await bob.getByRole('dialog').getByRole('button', { name: 'Close', exact: true }).click();
    await bob.goto(`/p/${post.ulid}`);
    await expect(bob.getByText('Separate persona followers update', { exact: true })).toBeVisible();
    await expect(bob.locator(`a[href="/c/${separate.ulid}"]`).first()).toContainText('Vex');
    await expect(bob.locator(`a[href="/users/${accounts.alice.id}"]`)).toHaveCount(0);
    await bob.goto(`/m/${media.persona.ulid}`);
    await expect(bob.getByRole('heading', { name: media.persona.title, exact: true })).toBeVisible();
    const image = bob.getByRole('img', { name: media.persona.title, exact: true });
    await expect(image).toBeVisible();
    await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.naturalWidth)).toBeGreaterThan(0);
    await expect(bob.locator(`a[href="/users/${accounts.alice.id}"]`)).toHaveCount(0);
    const asset = await bob.request.get(assetUrl);
    expect(asset.status()).toBe(200);
    expect(asset.headers()['cache-control']).toContain('no-store');
    // A persona follow grants no account-level access.
    expect((await bob.request.get(`/api/media/by-ulid/${media.account.ulid}`)).status()).toBe(404);
    await bob.goto(`/c/${separate.ulid}`);
    await bob.getByRole('button', { name: 'Open block confirmation for Vex', exact: true }).click();
    await bob.getByRole('alertdialog').getByRole('button', { name: 'Block Vex', exact: true }).click();
    await expect(bob.getByRole('button', { name: 'Open unblock confirmation for Vex', exact: true })).toBeVisible();
    expect((await bob.request.get(assetUrl)).status()).toBe(404);
    expect((await bob.request.get(`/api/media/by-ulid/${media.persona.ulid}`)).status()).toBe(404);
    expect((await bob.request.get(`/api/posts/by-ulid/${post.ulid}`)).status()).toBe(404);
  } finally {
    await bobContext.close();
  }
});
