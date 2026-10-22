import { expect, test } from '@playwright/test';

import { login, type TestAccounts } from './helpers';

interface EventFixtures {
  event: { ulid: string; name: string };
  private_event: { ulid: string };
  media: { id: number; ulid: string; title: string }[];
}

test('attendees discover uploads, browse photos and Save privately without an organization step', async ({ page, request }, testInfo) => {
  const suffix = `Events ${testInfo.project.name}`;
  const accounts = await (await request.get('/__browser/fixtures', { headers: { 'x-browser-name-suffix': suffix } })).json() as TestAccounts;
  const response = await request.get('/__browser/event-fixtures', {
    headers: { 'x-browser-owner': String(accounts.alice.id), 'x-browser-saver': String(accounts.bob.id), 'x-browser-name-suffix': suffix },
  });
  expect(response.ok()).toBeTruthy();
  const fixture = await response.json() as EventFixtures;
  await login(page, accounts.bob, `Bob ${suffix}`, /\/events$/);
  await expect(page.getByRole('button', { name: 'Add photos & videos', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Add photos & videos', exact: true }).click();
  const upload = page.getByRole('dialog');
  await expect(upload.getByRole('combobox')).toBeVisible();
  await expect(upload.getByRole('option', { name: fixture.event.name, exact: true })).toBeAttached();
  await expect(upload.getByRole('option', { name: 'Private shower', exact: true })).toHaveCount(0);
  await upload.getByRole('combobox').selectOption(fixture.event.ulid);
  await expect(upload.getByText(/All approved attendees/i)).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath('event-upload.png'), fullPage: true });
  await upload.getByRole('button', { name: 'Close', exact: true }).click();

  const first = page.getByRole('link', { name: /^Open Welcome photo / }).first();
  await first.scrollIntoViewIfNeeded();
  const before = await page.evaluate(() => window.scrollY);
  await first.click();
  const viewer = page.getByRole('dialog');
  await expect(viewer.getByRole('img')).toBeVisible();
  await expect.poll(() => viewer.getByRole('img').evaluate((image: HTMLImageElement) => image.naturalWidth)).toBeGreaterThan(0);
  const firstTitle = await viewer.getByRole('heading').textContent();
  await viewer.getByRole('button', { name: 'Next item', exact: true }).click();
  await expect(viewer.getByRole('heading')).not.toHaveText(firstTitle ?? '');
  await viewer.getByRole('button', { name: 'Previous item', exact: true }).click();
  await expect(viewer.getByRole('heading')).toHaveText(firstTitle ?? '');
  if (!testInfo.project.use.isMobile) {
    await page.keyboard.press('ArrowRight');
    await expect(viewer.getByRole('heading')).not.toHaveText(firstTitle ?? '');
    await page.keyboard.press('ArrowLeft');
    await expect(viewer.getByRole('heading')).toHaveText(firstTitle ?? '');
  }
  await viewer.getByRole('button', { name: 'Save to my collection', exact: true }).click();
  await expect(viewer.getByRole('link', { name: 'View in my collection', exact: true })).toBeVisible();
  await expect(viewer.getByRole('heading', { name: 'Organize your saved item' })).toHaveCount(0);
  const download = await viewer.getByRole('link', { name: 'Download', exact: true }).getAttribute('href');
  const downloaded = await page.request.get(download!);
  expect(downloaded.status()).toBe(200);
  expect(downloaded.headers()['content-disposition']).toContain('attachment');
  await page.screenshot({ path: testInfo.outputPath('event-viewer.png'), fullPage: true });
  await viewer.getByRole('button', { name: 'Close', exact: true }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThanOrEqual(Math.max(0, before - 5));
  expect(new URL(page.url()).searchParams.has('item')).toBe(false);

  await page.getByRole('link', { name: `Bob ${suffix}’s favorites`, exact: true }).click();
  await expect(page.getByRole('link', { name: `Open ${fixture.media[0].title}`, exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: /^Open Welcome photo / })).toHaveCount(1);
  await expect(page.getByText('Private shower', { exact: true })).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('event-gallery.png'), fullPage: true });
});
