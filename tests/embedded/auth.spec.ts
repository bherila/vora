import { expect, test } from '@playwright/test';

test('mounted provider signs in over OAuth, keeps separate cookies and ends both sessions', async ({ page, context }) => {
  const failures: string[] = [];
  page.on('pageerror', (error) => failures.push(error.message));

  await page.goto('/login');
  await expect(page).toHaveURL(/\/auth\/login$/);
  await page.getByLabel('Email', { exact: true }).fill('person@example.test');
  await page.getByLabel('Password', { exact: true }).fill('correct horse battery staple');
  await page.locator('#password-login-form').getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/feed(?:\?|$)/);
  await expect(page.getByRole('link', { name: 'Log in', exact: true })).toHaveCount(0);

  const account = await (await page.request.get('/__embedded/account')).json();
  expect(account.count).toBe(1);
  expect(account.provider).toBe('embedded-test');
  expect(account.subject).toBeTruthy();
  expect(account.last_login_at).toBeTruthy();
  const cookies = await context.cookies();
  expect(cookies.find((cookie) => cookie.name === 'auth_manager_session')?.path).toBe('/auth');
  expect(cookies.some((cookie) => cookie.name !== 'auth_manager_session' && cookie.httpOnly && cookie.path === '/')).toBe(true);

  // Use the actual navbar action, including its JSON logout and provider navigation.
  await page.getByRole('button', { name: /^Account and identity menu/ }).click();
  await page.getByRole('menuitem', { name: 'Log out', exact: true }).click();
  await expect(page).toHaveURL(/\/$/);
  await page.goto('/login');
  await expect(page).toHaveURL(/\/auth\/login$/);
  await expect(page.getByLabel('Password', { exact: true })).toBeVisible();

  // Vora's own disabled/deleted account remains refused even when the provider
  // successfully authenticates the same subject again.
  await page.request.post('/__embedded/disable');
  await page.getByLabel('Email', { exact: true }).fill('person@example.test');
  await page.getByLabel('Password', { exact: true }).fill('correct horse battery staple');
  await page.locator('#password-login-form').getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page.getByText('Your account here has been disabled.', { exact: false })).toBeVisible();
  await page.request.post('/__embedded/delete');
  await page.goto('/login');
  await expect(page.getByText('This account has been deleted.', { exact: false })).toBeVisible();
  expect((await (await page.request.get('/__embedded/account')).json()).count).toBe(1);
  expect(failures).toEqual([]);
});
