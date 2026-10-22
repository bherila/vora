import { expect, type Page, test } from '@playwright/test';

import { login, type TestAccounts } from './helpers';

interface EventFixtures {
  event: { ulid: string; name: string };
  private_event: { ulid: string };
  media: { id: number; ulid: string; title: string }[];
}

async function expectFitsViewport(page: Page): Promise<void> {
  await expect.poll(() => page.evaluate(() => {
    const width = document.documentElement.scrollWidth;
    if (width <= window.innerWidth + 1) return null;
    const elements = Array.from(document.querySelectorAll('body *')).flatMap((element) => {
      const rect = element.getBoundingClientRect();
      return rect.width > 0 && rect.right > window.innerWidth + 1
        ? [{ tag: element.tagName, id: element.id, classes: element.getAttribute('class')?.slice(0, 100), left: rect.left, right: rect.right }]
        : [];
    }).slice(0, 8);
    return { viewport: window.innerWidth, width, elements };
  })).toBeNull();
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
  await expectFitsViewport(page);
  await page.getByRole('button', { name: 'Add photos & videos', exact: true }).click();
  const upload = page.getByRole('dialog');
  await expect(upload.getByRole('combobox')).toBeVisible();
  await expect(upload.getByRole('option', { name: fixture.event.name, exact: true })).toBeAttached();
  await expect(upload.getByRole('option', { name: 'Private shower', exact: true })).toHaveCount(0);
  await upload.getByRole('combobox').selectOption(fixture.event.ulid);
  await expect(upload.getByText(/All approved attendees/i)).toBeVisible();
  const longFilename = 'a-photo-with-a-long-original-camera-filename-to-check-the-upload-layout-on-small-screens.png';
  await upload.locator('input[type="file"]').setInputFiles(new URL(`./fixtures/${longFilename}`, import.meta.url).pathname);
  await expect.poll(() => upload.getByRole('img').evaluate((image: HTMLImageElement) => image.naturalWidth)).toBeGreaterThan(0);
  await expectFitsViewport(page);
  await upload.locator('input[type="file"]').setInputFiles([longFilename, 'attendee-photo.png'].map((name) => new URL(`./fixtures/${name}`, import.meta.url).pathname));
  await expect(upload.getByRole('img')).toHaveCount(2);
  await expect(upload.getByRole('status')).toHaveText('0 of 2 uploaded');
  await page.screenshot({ path: testInfo.outputPath('event-upload.png'), fullPage: true });
  await upload.getByRole('button', { name: 'Close', exact: true }).click();
  await expect(page.locator('[data-slot="dialog-portal"]')).toHaveCount(0);

  const first = page.getByRole('link', { name: /^Open Welcome photo / }).first();
  const secondTitle = (await page.getByRole('link', { name: /^Open Welcome photo / }).nth(1).getAttribute('aria-label'))?.replace(/^Open /, '');
  expect(secondTitle).toBeTruthy();
  await first.scrollIntoViewIfNeeded();
  const before = await page.evaluate(() => window.scrollY);
  await first.click();
  const viewer = page.getByRole('dialog');
  await expect(viewer.getByRole('img')).toBeVisible();
  await expect.poll(() => viewer.getByRole('img').evaluate((image: HTMLImageElement) => image.naturalWidth)).toBeGreaterThan(0);
  const firstTitle = await viewer.getByRole('heading').textContent();
  await viewer.getByRole('button', { name: 'Next item', exact: true }).click();
  await expect(viewer.getByRole('heading')).toHaveText(secondTitle!);
  await viewer.getByRole('button', { name: 'Previous item', exact: true }).click();
  await expect(viewer.getByRole('heading')).toHaveText(firstTitle ?? '');
  if (!testInfo.project.use.isMobile) {
    await page.keyboard.press('ArrowRight');
    await expect(viewer.getByRole('heading')).toHaveText(secondTitle!);
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
  await expectFitsViewport(page);
  await page.screenshot({ path: testInfo.outputPath('event-gallery.png'), fullPage: true });
});
