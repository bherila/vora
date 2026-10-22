import { expect, test } from '@playwright/test';

import { login, peerContext, type TestAccounts } from './helpers';

test('private collections share explicitly and saved media survives its uploader’s erasure', async ({ browser, page, request }, testInfo) => {
  const suffix = `Library ${testInfo.project.name}`;
  const accountResponse = await request.get('/__browser/fixtures', { headers: { 'x-browser-name-suffix': suffix } });
  const accounts = await accountResponse.json() as TestAccounts;
  const fixtures = await request.get('/__browser/library-fixtures', {
    headers: { 'x-browser-owner': String(accounts.alice.id), 'x-browser-saver': String(accounts.bob.id) },
  });
  expect(fixtures.ok()).toBeTruthy();
  const { media, persona } = await fixtures.json() as { media: { ulid: string }; persona: { ulid: string; display_name: string } };
  const aliceContext = await peerContext(browser);
  const alice = await aliceContext.newPage();
  try {
    await login(page, accounts.bob, `Bob ${suffix}`);
    await login(alice, accounts.alice, `Alice ${suffix}`);
    await page.goto('/library');
    await page.getByRole('button', { name: 'New collection', exact: true }).click();
    const editor = page.getByRole('dialog');
    await editor.getByLabel('Collection name', { exact: true }).fill('Received from a persona');
    await expect(editor.getByRole('option', { name: persona.display_name, exact: true })).toHaveCount(1);
    await editor.getByLabel('Related persona (optional)').selectOption(persona.ulid);
    await editor.getByRole('button', { name: 'Create collection', exact: true }).click();
    await page.getByRole('link', { name: 'Received from a persona Private collection', exact: true }).click();
    const collectionUlid = new URL(page.url()).searchParams.get('collection');
    expect(collectionUlid).toBeTruthy();
    await expect(page.getByRole('link', { name: persona.display_name, exact: true })).toBeVisible();
    expect((await alice.request.get(`/api/library/items?collection_ulid=${collectionUlid}`)).status()).toBe(404);

    await page.getByRole('button', { name: 'Add note', exact: true }).click();
    await page.getByRole('dialog').getByLabel('Title (optional)').fill('Received note');
    await page.getByRole('dialog').getByLabel('Note', { exact: true }).fill('Received in an off-site chat.');
    await page.getByRole('dialog').getByRole('button', { name: 'Add note', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Open Received note', exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Share collection', exact: true }).click();
    const sharing = page.getByRole('dialog');
    await expect(sharing.getByText(/all current and future items you upload or write/)).toBeVisible();
    await sharing.getByLabel('Specific people').fill(`Alice ${suffix}`);
    await sharing.getByRole('checkbox', { name: `Alice ${suffix}`, exact: true }).check();
    await sharing.getByRole('button', { name: 'Save sharing', exact: true }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    expect((await alice.request.get(`/api/library/items?collection_ulid=${collectionUlid}`)).status()).toBe(200);
    await alice.goto(`/library?collection=${collectionUlid}`);
    await expect(alice.getByRole('button', { name: 'Open Received note', exact: true })).toBeVisible();
    await expect(alice.getByRole('button', { name: 'Share collection', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Share collection', exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Stop direct sharing', exact: true }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    expect((await alice.request.get(`/api/library/items?collection_ulid=${collectionUlid}`)).status()).toBe(404);

    await page.goto(`/m/${media.ulid}`);
    await page.getByRole('button', { name: 'Save to Library', exact: true }).click();
    const saveDialog = page.getByRole('dialog');
    await expect(saveDialog.getByText(/Keep access here even if the original uploader/)).toBeVisible();
    await saveDialog.getByLabel('Collection (optional)').selectOption(collectionUlid ?? '');
    await saveDialog.getByRole('button', { name: 'Save to Library', exact: true }).click();
    const savedLink = page.getByRole('link', { name: 'Open in Library', exact: true });
    await expect(savedLink).toBeVisible();
    const savedHref = await savedLink.getAttribute('href');
    await savedLink.click();
    await expect(page.getByRole('dialog').getByRole('heading', { name: 'Shared library photo', exact: true })).toBeVisible();
    const image = page.getByRole('dialog').getByRole('img', { name: 'Shared library photo', exact: true });
    await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.naturalWidth)).toBeGreaterThan(0);
    await expect(page.getByRole('dialog').getByRole('button', { name: 'Share', exact: true })).toHaveCount(0);
    await expect(page.getByRole('dialog').getByText(/Saved items are visible only to you/)).toBeVisible();
    await page.getByRole('dialog').getByRole('button', { name: 'Close', exact: true }).click();
    await page.goto(`/library?collection=${collectionUlid}`);
    await page.getByRole('button', { name: 'Share collection', exact: true }).click();
    await expect(page.getByRole('dialog').getByText(/Items saved from someone else remain private to you/)).toBeVisible();
    await page.getByRole('dialog').getByLabel('Specific people').fill(`Alice ${suffix}`);
    await page.getByRole('dialog').getByRole('checkbox', { name: `Alice ${suffix}`, exact: true }).check();
    await page.getByRole('dialog').getByRole('button', { name: 'Save sharing', exact: true }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await alice.goto(`/library?collection=${collectionUlid}`);
    await expect(alice.getByRole('button', { name: 'Open Received note', exact: true })).toBeVisible();
    await expect(alice.getByRole('button', { name: 'Open Shared library photo', exact: true })).toHaveCount(0);
    const savedItemUlid = new URL(savedHref ?? '', page.url()).searchParams.get('item');
    expect(savedItemUlid).toBeTruthy();
    expect((await alice.request.get(`/api/library/items/${savedItemUlid}`)).status()).toBe(404);
    expect((await alice.request.get(`/api/library/items/${savedItemUlid}/assets/original`)).status()).toBe(404);

    const purge = await request.post('/__browser/library-purge-uploader', { headers: { 'x-browser-owner': String(accounts.alice.id) } });
    expect(purge.ok()).toBeTruthy();
    await page.goto(savedHref ?? '/library');
    await expect(page.getByRole('dialog').getByRole('heading', { name: 'Shared library photo', exact: true })).toBeVisible();
    await expect.poll(() => page.getByRole('dialog').getByRole('img', { name: 'Shared library photo', exact: true }).evaluate((element: HTMLImageElement) => element.naturalWidth)).toBeGreaterThan(0);
    await page.getByRole('dialog').getByRole('button', { name: 'Close', exact: true }).click();
    await page.goto(`/library?collection=${collectionUlid}`);
    await expect(page.getByText('The related persona is unavailable.', { exact: false })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Open Received note', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Open Shared library photo', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBeTruthy();
    await page.screenshot({ path: testInfo.outputPath('library.png'), fullPage: true });
  } finally {
    await aliceContext.close();
  }
});
