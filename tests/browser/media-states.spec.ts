import { expect, test } from '@playwright/test';

import { contentFixtures, login, peerContext, type TestAccounts } from './helpers';

test('media review and processing states stay private and a broken HLS stream never falls back to the original', async ({ browser, page, request }) => {
  const response = await request.get('/__browser/fixtures');
  expect(response.ok()).toBeTruthy();
  const accounts = await response.json() as TestAccounts;
  const { media } = await contentFixtures(page, accounts.alice);
  const bobContext = await peerContext(browser);
  const bob = await bobContext.newPage();
  try {
    await login(page, accounts.alice, 'Alice');
    await login(bob, accounts.bob, 'Bob');
    for (const item of [media.pending, media.rejected]) {
      await page.goto(`/m/${item.ulid}`);
      await expect(page.getByRole('heading', { name: item.title, exact: true })).toBeVisible();
      await expect(page.getByText('Only you can see this', { exact: false })).toBeVisible();
      await expect(page.getByText('Private moderator note', { exact: false })).toHaveCount(0);
      const image = page.getByRole('img', { name: item.title, exact: true });
      await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.naturalWidth)).toBeGreaterThan(0);
      expect((await bob.goto(`/m/${item.ulid}`))?.status()).toBe(404);
      expect((await bob.request.get(`/api/media/by-ulid/${item.ulid}`)).status()).toBe(404);
      expect((await bob.request.get(`/api/media/by-ulid/${item.ulid}/asset/original`)).status()).toBe(404);
    }
    await page.goto(`/m/${media.uploading.ulid}`);
    await expect(page.getByText('Upload in progress…', { exact: true })).toBeVisible();
    await expect(page.locator('video')).toHaveCount(0);
    expect((await bob.goto(`/m/${media.uploading.ulid}`))?.status()).toBe(404);

    await bob.goto(`/m/${media.processing.ulid}`);
    await expect(bob.getByText('Video is still processing', { exact: true })).toBeVisible();
    await expect(bob.locator('video')).toHaveCount(0);
    const mediaRequests: string[] = [];
    bob.on('request', (sent) => {
      if (sent.url().includes('/api/media/')) mediaRequests.push(sent.url());
    });
    const manifest = bob.waitForResponse((sent) => sent.url().endsWith(`/api/media/${media.broken.id}/hls/master.m3u8`));
    await bob.goto(`/m/${media.broken.ulid}`);
    expect((await manifest).status()).toBe(200);
    await expect(bob.getByText('Unavailable.', { exact: true })).toBeVisible();
    await expect(bob.locator('video')).toHaveCount(0);
    expect(mediaRequests.some((url) => url.includes('/asset/original'))).toBe(false);
    await expect(bob.getByRole('link', { name: 'Download original', exact: true })).toHaveCount(0);
  } finally {
    await bobContext.close();
  }
});
