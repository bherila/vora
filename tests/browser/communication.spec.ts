import { expect, type Page, test } from '@playwright/test';

import { acceptFollow, login, peerContext, type TestAccounts } from './helpers';

async function sendMessage(page: Page, body: string): Promise<void> {
  await page.getByLabel('Message', { exact: true }).fill(body);
  await page.getByRole('button', { name: 'Send message', exact: true }).click();
  await expect(page.getByLabel('Message', { exact: true })).toHaveValue('');
  await expect(page.getByRole('log').getByText(body, { exact: true })).toHaveCount(1);
  await expect(page.getByText('Message sent.', { exact: true })).toHaveCount(1);
}

test('two accounts log in, follow, chat, navigate history, block, unblock, and log out', async ({ browser, page, request }) => {
  const fixtureResponse = await request.get('/__browser/fixtures');
  expect(fixtureResponse.ok()).toBeTruthy();
  const accounts = await fixtureResponse.json() as TestAccounts;
  const csrfRejected = await request.post('/login', {
    data: { email: accounts.alice.email, password: 'password' },
  });
  expect(csrfRejected.status()).toBe(419);
  // A second independent cookie jar with the same desktop/mobile settings.
  const bobContext = await peerContext(browser);
  const bob = await bobContext.newPage();
  try {
    await login(page, accounts.alice, 'Alice');
    await login(bob, accounts.bob, 'Bob');
    // Alternating sessions must retain their own identities in the PHP worker.
    await page.goto('/me');
    await expect(page.getByRole('button', { name: 'Edit profile', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Account and identity menu (currently Alice)' })).toBeVisible();

    await page.goto(`/users/${accounts.bob.id}`);
    await expect(page.getByRole('button', { name: 'Send follow request', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Message', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Send follow request', exact: true }).click();
    await expect(page.getByText('Follow request sent.', { exact: true })).toBeVisible();
    await acceptFollow(bob);
    await bob.goto(`/users/${accounts.alice.id}`);
    await expect(bob.getByRole('button', { name: 'Follow back', exact: true })).toBeVisible();
    await expect(bob.getByRole('button', { name: 'Message', exact: true })).toHaveCount(0);
    await bob.getByRole('button', { name: 'Follow back', exact: true }).click();
    await expect(bob.getByText('Follow request sent.', { exact: true })).toBeVisible();
    await acceptFollow(page);

    await page.goto(`/users/${accounts.bob.id}`);
    await page.getByRole('button', { name: 'Message', exact: true }).click();
    await expect(page).toHaveURL(/\/messages\/[0-9A-HJKMNP-TV-Z]{26}$/);
    const threadUrl = page.url();
    await sendMessage(page, 'Hello Bob from Alice');
    await page.reload();
    await expect(page.getByRole('log').getByText('Hello Bob from Alice', { exact: true })).toHaveCount(1);

    await bob.goto('/messages');
    const inboxRow = bob.getByRole('button', { name: /Alice.*Hello Bob from Alice/ });
    await expect(inboxRow).toContainText('Unread messages: 1');
    await inboxRow.click();
    await expect(bob.getByRole('heading', { name: 'Alice', exact: true })).toBeFocused();
    await expect(bob.getByRole('log').getByText('Hello Bob from Alice', { exact: true })).toHaveCount(1);
    await sendMessage(bob, 'Hello Alice from Bob');
    await bob.goto('/messages');
    const readInboxRow = bob.getByRole('button', { name: /Alice.*Hello Alice from Bob/ });
    await expect(readInboxRow).toBeVisible();
    await expect(readInboxRow).not.toContainText('Unread messages:');

    await page.goto('/messages');
    await page.getByRole('button', { name: /Bob.*Hello Alice from Bob/ }).click();
    await expect(page.getByRole('heading', { name: 'Bob', exact: true })).toBeFocused();
    await expect(page.getByRole('log').getByText('Hello Alice from Bob', { exact: true })).toHaveCount(1);
    await page.goBack();
    await expect(page).toHaveURL(/\/messages$/);
    await expect(page.getByLabel('Message', { exact: true })).toHaveCount(0);
    await page.goForward();
    await expect(page).toHaveURL(threadUrl);
    await expect(page.getByRole('log').getByText('Hello Alice from Bob', { exact: true })).toHaveCount(1);

    await page.goto(`/users/${accounts.bob.id}`);
    await page.getByRole('button', { name: 'Open block confirmation for Bob', exact: true }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Block Bob', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Open unblock confirmation for Bob', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Message', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /^(Send follow request|Follow back)$/ })).toHaveCount(0);
    await expect(page.getByText('Request:', { exact: false })).toHaveCount(0);
    await expect(page.getByText('You blocked this account', { exact: true })).toBeVisible();

    await bob.goto(threadUrl);
    await expect(bob).toHaveURL(/\/messages$/);
    await expect(bob.getByText('That conversation is unavailable.', { exact: true })).toBeVisible();
    await expect(bob.getByLabel('Message', { exact: true })).toHaveCount(0);
    await expect(bob.getByRole('log')).toHaveCount(0);
    const hiddenProfile = await bob.request.get(`/api/users/${accounts.alice.id}`);
    expect(hiddenProfile.status()).toBe(404);

    await page.getByRole('button', { name: 'Open unblock confirmation for Bob', exact: true }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Unblock Bob', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Open block confirmation for Bob', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Send follow request', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Message', exact: true })).toHaveCount(0);
    await expect(page.getByText('Request:', { exact: false })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Follow back', exact: true })).toHaveCount(0);
    await expect(page.getByText('You blocked this account', { exact: true })).toHaveCount(0);
    await page.goto(threadUrl);
    await expect(page.getByText('Messaging is unavailable. Your existing history remains here.', { exact: true })).toBeVisible();
    await expect(page.getByRole('log').getByText('Hello Alice from Bob', { exact: true })).toHaveCount(1);

    await page.getByRole('button', { name: 'Account and identity menu (currently Alice)' }).click();
    await page.getByRole('menuitem', { name: 'Log out', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
    await page.goto('/messages');
    await expect(page).toHaveURL(/\/login$/);
    await bob.goto('/me');
    await expect(bob.getByRole('button', { name: 'Account and identity menu (currently Bob)' })).toBeVisible();
  } finally {
    await bobContext.close();
  }
});
