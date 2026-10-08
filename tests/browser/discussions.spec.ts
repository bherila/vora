import { expect, type Page, test } from '@playwright/test';

import { acceptFollow, login, peerContext, type TestAccounts } from './helpers';

async function comment(page: Page, body: string): Promise<void> {
  await page.getByPlaceholder('Write a comment', { exact: true }).fill(body);
  await page.getByRole('button', { name: 'Post comment', exact: true }).click();
  await expect(page.getByText(body, { exact: true })).toBeVisible();
}

test('contributors can delete their comments and replies after losing private discussion access', async ({ browser, page, request }) => {
  const fixture = await request.get('/__browser/fixtures');
  expect(fixture.ok()).toBeTruthy();
  const accounts = await fixture.json() as TestAccounts;
  const bobContext = await peerContext(browser);
  const bob = await bobContext.newPage();
  try {
    await login(page, accounts.alice, 'Alice');
    await login(bob, accounts.bob, 'Bob');
    await bob.goto(`/users/${accounts.alice.id}`);
    await bob.getByRole('button', { name: 'Send follow request', exact: true }).click();
    await expect(bob.getByText('Follow request sent.', { exact: true })).toBeVisible();
    await acceptFollow(page);
    await page.goto('/feed');
    await page.getByPlaceholder('Share an update', { exact: true }).fill('Alice private discussion');
    await page.getByRole('button', { name: 'Audience, persona & attachments', exact: true }).click();
    await page.getByLabel('Who can see this?', { exact: true }).selectOption('followers');
    const published = page.waitForResponse((response) => response.url().endsWith('/api/posts') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Post', exact: true }).click();
    const publishedResponse = await published;
    expect(publishedResponse.status()).toBe(201);
    const { data: post } = await publishedResponse.json() as { data: { ulid: string } };
    const postUrl = `/p/${post.ulid}`;
    await bob.goto(postUrl);
    await comment(bob, 'Bob root contribution');
    await page.goto(postUrl);
    await page.getByRole('button', { name: 'Reply', exact: true }).click();
    await comment(page, 'Alice reply that must survive');
    await comment(page, 'Alice second root');
    await bob.reload();
    await expect(bob.getByText('Alice second root', { exact: true })).toBeVisible();
    await expect(bob.getByRole('button', { name: 'Reply', exact: true })).toHaveCount(2);
    await bob.getByRole('button', { name: 'Reply', exact: true }).nth(1).click();
    await comment(bob, 'Bob reply contribution');

    // Blocking then unblocking removes the follow, keeping identity visibility
    // independent from the private discussion's audience gate.
    await page.goto(`/users/${accounts.bob.id}`);
    await page.getByRole('button', { name: 'Open block confirmation for Bob', exact: true }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Block Bob', exact: true }).click();
    await page.getByRole('button', { name: 'Open unblock confirmation for Bob', exact: true }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Unblock Bob', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Send follow request', exact: true })).toBeVisible();
    expect((await bob.goto(postUrl))?.status()).toBe(404);
    await bob.goto('/me/activity');
    for (const [tab, body] of [['Comments', 'Bob root contribution'], ['Replies', 'Bob reply contribution']]) {
      await bob.getByRole('button', { name: tab, exact: true }).click();
      const contribution = bob.getByRole('article').filter({ hasText: body });
      await expect(contribution.getByText('Original post unavailable', { exact: true })).toBeVisible();
      await expect(bob.getByText('Alice private discussion', { exact: true })).toHaveCount(0);
      await expect(contribution.getByRole('link', { name: 'View original post', exact: true })).toHaveCount(0);
      await contribution.getByRole('button', { name: 'Delete contribution', exact: true }).click();
      await expect(contribution).toHaveCount(0);
    }
    await bob.reload();
    await bob.getByRole('button', { name: 'Comments', exact: true }).click();
    await expect(bob.getByText('Bob root contribution', { exact: true })).toHaveCount(0);
    await bob.getByRole('button', { name: 'Replies', exact: true }).click();
    await expect(bob.getByText('Bob reply contribution', { exact: true })).toHaveCount(0);
    await page.goto(postUrl);
    await expect(page.getByText('Deleted comment', { exact: true })).toBeVisible();
    await expect(page.getByText('Alice reply that must survive', { exact: true })).toBeVisible();
    await expect(page.getByText('Alice second root', { exact: true })).toBeVisible();
    await expect(page.getByText('Bob reply contribution', { exact: true })).toHaveCount(0);
  } finally {
    await bobContext.close();
  }
});
