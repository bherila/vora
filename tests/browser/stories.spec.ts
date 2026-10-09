import { expect, test } from '@playwright/test';

import { contentFixtures, login, peerContext, type TestAccounts } from './helpers';

test('story readers render prose and adventure choices while edits and rejection require renewed approval', async ({ browser, page, request }) => {
  const response = await request.get('/__browser/fixtures');
  expect(response.ok()).toBeTruthy();
  const accounts = await response.json() as TestAccounts;
  const fixtures = await contentFixtures(page, accounts.alice);
  const bobContext = await peerContext(browser);
  const adminContext = await peerContext(browser);
  const bob = await bobContext.newPage();
  const admin = await adminContext.newPage();
  try {
    await login(page, accounts.alice, 'Alice');
    await login(bob, accounts.bob, 'Bob');
    await login(admin, fixtures.admin, 'Browser Admin');
    const storyUrl = `/s/${fixtures.stories.longForm.ulid}`;
    await bob.goto(storyUrl);
    await expect(bob.getByRole('heading', { name: 'The lighthouse journal', exact: true })).toBeVisible();
    await expect(bob.getByRole('heading', { name: 'A quiet harbor', exact: true })).toBeVisible();
    await expect(bob.getByText('The lantern guided us home.', { exact: true })).toBeVisible();
    await expect(bob.getByText('Story · by Alice', { exact: true })).toBeVisible();
    expect((await bob.request.get(`/api/stories/${fixtures.stories.longForm.id}`)).status()).toBe(404);
    await expect.poll(() => bob.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

    await bob.goto(`/s/${fixtures.stories.adventure.ulid}`);
    await expect(bob.getByRole('heading', { name: 'The crossroads', exact: true })).toBeVisible();
    await bob.getByRole('button', { name: 'Visit the dock', exact: true }).click();
    await expect(bob.getByRole('heading', { name: 'At the dock', exact: true })).toBeVisible();
    await expect(bob.getByText('A boat waits in the moonlight.', { exact: true })).toBeVisible();
    await expect(bob.getByText('The End.', { exact: true })).toBeVisible();
    await bob.getByRole('button', { name: 'Start over', exact: true }).click();
    await bob.getByRole('button', { name: 'Stay ashore', exact: true }).click();
    await expect(bob.getByText('The End.', { exact: true })).toBeVisible();
    await expect(bob.getByRole('heading', { name: 'The crossroads', exact: true })).toHaveCount(0);
    await bob.getByRole('button', { name: 'Start over', exact: true }).click();
    await expect(bob.getByRole('button', { name: 'Visit the dock', exact: true })).toBeVisible();

    // Real editor saves return already-approved reader text to pending review.
    await page.goto(`/stories?edit=${fixtures.stories.longForm.id}`);
    await page.getByLabel('Story (markdown)', { exact: true }).fill('The revised lantern story.');
    await page.getByRole('button', { name: 'Save details', exact: true }).click();
    await expect(page.getByText('Saved.', { exact: true })).toBeVisible();
    expect((await bob.goto(storyUrl))?.status()).toBe(404);
    await expect(bob.getByText('The revised lantern story.', { exact: true })).toHaveCount(0);
    await page.goto(storyUrl);
    await expect(page.getByText('The revised lantern story.', { exact: true })).toBeVisible();

    await admin.goto('/admin/stories');
    const row = admin.getByRole('row').filter({ has: admin.locator(`a[href="${storyUrl}"]`) });
    await row.getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(row).toHaveCount(0);
    await bob.goto(storyUrl);
    await expect(bob.getByText('The revised lantern story.', { exact: true })).toBeVisible();
    await admin.getByRole('button', { name: 'Approved', exact: true }).click();
    admin.once('dialog', (dialog) => dialog.accept('Private story review note'));
    await row.getByRole('button', { name: 'Reject', exact: true }).click();
    await expect(row).toHaveCount(0);
    expect((await bob.goto(storyUrl))?.status()).toBe(404);
    await expect(bob.getByText('Private story review note', { exact: true })).toHaveCount(0);
    await page.goto(storyUrl);
    await expect(page.getByText('The revised lantern story.', { exact: true })).toBeVisible();
  } finally {
    await bobContext.close();
    await adminContext.close();
  }
});
