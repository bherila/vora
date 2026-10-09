import { expect, type Page, test } from '@playwright/test';

import { acceptFollow, login, peerContext, type TestAccounts } from './helpers';

async function publish(page: Page, body: string, audience: 'mutuals' | 'specific', recipient?: string): Promise<string> {
  await page.goto('/feed');
  await page.getByPlaceholder('Share an update', { exact: true }).fill(body);
  await page.getByRole('button', { name: 'Audience, persona & attachments', exact: true }).click();
  await page.getByLabel('Who can see this?', { exact: true }).selectOption(audience);
  if (recipient) {
    await page.getByLabel('Specific people', { exact: true }).fill(recipient);
    await page.getByRole('checkbox', { name: recipient, exact: true }).check();
  }
  const published = page.waitForResponse((response) => response.url().endsWith('/api/posts') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Post', exact: true }).click();
  const response = await published;
  expect(response.status()).toBe(201);
  const { data } = await response.json() as { data: { ulid: string } };
  return `/p/${data.ulid}`;
}

test('Mutuals requires both follows and Specific people grants only the selected account', async ({ browser, page, request }) => {
  // Unique names make the people picker unambiguous in the shared worker.
  const suffix = `audience-${test.info().project.name}`;
  const response = await request.get('/__browser/fixtures', { headers: { 'x-browser-name-suffix': suffix } });
  expect(response.ok()).toBeTruthy();
  const accounts = await response.json() as TestAccounts;
  const outsiders = await request.get('/__browser/fixtures', { headers: { 'x-browser-name-suffix': `outsider-${suffix}` } });
  expect(outsiders.ok()).toBeTruthy();
  const outsiderAccounts = await outsiders.json() as TestAccounts;
  const bobContext = await peerContext(browser);
  const outsiderContext = await peerContext(browser);
  const bob = await bobContext.newPage();
  const outsider = await outsiderContext.newPage();
  try {
    await login(page, accounts.alice, `Alice ${suffix}`);
    await login(bob, accounts.bob, `Bob ${suffix}`);
    await login(outsider, outsiderAccounts.alice, `Alice outsider-${suffix}`);
    const mutualUrl = await publish(page, 'A mutuals-only update', 'mutuals');
    expect((await bob.goto(mutualUrl))?.status()).toBe(404);
    await bob.goto(`/users/${accounts.alice.id}`);
    await bob.getByRole('button', { name: 'Send follow request', exact: true }).click();
    await expect(bob.getByText('Follow request sent.', { exact: true })).toBeVisible();
    await acceptFollow(page);
    expect((await bob.goto(mutualUrl))?.status()).toBe(404);
    await page.goto(`/users/${accounts.bob.id}`);
    await page.getByRole('button', { name: 'Follow back', exact: true }).click();
    await expect(page.getByText('Follow request sent.', { exact: true })).toBeVisible();
    await acceptFollow(bob);
    await bob.goto(mutualUrl);
    await expect(bob.getByText('A mutuals-only update', { exact: true })).toBeVisible();
    expect((await outsider.goto(mutualUrl))?.status()).toBe(404);

    const specificUrl = await publish(page, 'An explicitly shared update', 'specific', `Bob ${suffix}`);
    await bob.goto(specificUrl);
    await expect(bob.getByText('An explicitly shared update', { exact: true })).toBeVisible();
    expect((await outsider.goto(specificUrl))?.status()).toBe(404);
    await page.goto(`/users/${accounts.bob.id}`);
    await page.getByRole('button', { name: `Open block confirmation for Bob ${suffix}`, exact: true }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: `Block Bob ${suffix}`, exact: true }).click();
    await expect(page.getByRole('button', { name: `Open unblock confirmation for Bob ${suffix}`, exact: true })).toBeVisible();
    expect((await bob.goto(specificUrl))?.status()).toBe(404);
    await page.getByRole('button', { name: `Open unblock confirmation for Bob ${suffix}`, exact: true }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: `Unblock Bob ${suffix}`, exact: true }).click();
    await expect(page.getByRole('button', { name: 'Send follow request', exact: true })).toBeVisible();
    expect((await bob.goto(mutualUrl))?.status()).toBe(404);
    // Explicit item grants are independent of the follows removed by blocking.
    await bob.goto(specificUrl);
    await expect(bob.getByText('An explicitly shared update', { exact: true })).toBeVisible();

    await page.goto('/feed');
    await page.getByPlaceholder('Share an update', { exact: true }).fill('An update without recipients');
    await page.getByRole('button', { name: 'Audience, persona & attachments', exact: true }).click();
    await page.getByLabel('Who can see this?', { exact: true }).selectOption('specific');
    let attemptedPosts = 0;
    page.on('request', (sent) => {
      if (sent.url().endsWith('/api/posts') && sent.method() === 'POST') attemptedPosts++;
    });
    await page.getByRole('button', { name: 'Post', exact: true }).click();
    await expect(page.getByText('Choose at least one person for a specific-people post.', { exact: true })).toBeVisible();
    expect(attemptedPosts).toBe(0);
  } finally {
    await bobContext.close();
    await outsiderContext.close();
  }
});
