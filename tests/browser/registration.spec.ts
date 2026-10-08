import { expect, test } from '@playwright/test';

import { login, peerContext, type TestAccount } from './helpers';

test('registration requires email verification and admin approval before login opens Feed', async ({ browser, page, request }) => {
  const fixture = await request.get('/__browser/registration');
  expect(fixture.ok()).toBeTruthy();
  const { admin, email } = await fixture.json() as { admin: TestAccount; email: string };
  const adminContext = await peerContext(browser);
  const adminPage = await adminContext.newPage();
  try {
    await login(adminPage, admin, 'Browser Admin');
    await page.goto('/register');
    await page.getByLabel('Real name', { exact: true }).fill('Dana Browser');
    await page.getByLabel('Display name', { exact: true }).fill('Dana');
    await page.getByLabel('Birth date', { exact: true }).click();
    await page.getByRole('combobox', { name: 'Choose the Year' }).selectOption('1990');
    await page.getByRole('combobox', { name: 'Choose the Month' }).selectOption('0');
    await page.getByRole('button', { name: /January 2nd, 1990/ }).click();
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByLabel('Confirm Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Create Account', exact: true }).click();
    await expect(page).toHaveURL(/\/email\/verify/);
    await page.goto('/feed');
    await expect(page).toHaveURL(/\/email\/verify/);

    await adminPage.goto('/admin/users');
    let signupRow = adminPage.getByRole('row').filter({ hasText: email });
    await expect(signupRow.getByRole('button', { name: 'Approve', exact: true })).toBeDisabled();
    const verification = await request.get('/__browser/verification-link', { headers: { 'x-browser-email': email } });
    expect(verification.ok()).toBeTruthy();
    const { url } = await verification.json() as { url: string };
    await page.goto(url);
    await expect(page).toHaveURL(/\/pending-approval$/);
    await page.goto('/feed');
    await expect(page).toHaveURL(/\/pending-approval/);
    await expect(page.getByText('Account Pending Approval', { exact: true })).toBeVisible();

    await adminPage.reload();
    signupRow = adminPage.getByRole('row').filter({ hasText: email });
    await signupRow.getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(signupRow.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Log out', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
    await login(page, { email }, 'Dana');
    expect((await page.request.get('/api/admin/users')).status()).toBe(403);
    await page.getByRole('button', { name: 'Account and identity menu (currently Dana)' }).click();
    await page.getByRole('menuitem', { name: 'Log out', exact: true }).click();
    await page.goto('/feed');
    await expect(page).toHaveURL(/\/login$/);
    await adminPage.goto('/admin/users');
    await expect(adminPage.getByRole('row').filter({ hasText: email })).toBeVisible();
  } finally {
    await adminContext.close();
  }
});
