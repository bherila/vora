import { expect, test } from '@playwright/test';

test('a real provider passkey honors remember-me without creating a host remember cookie', async ({ page, context }) => {
  const devtools = await context.newCDPSession(page);
  await devtools.send('WebAuthn.enable');
  const { authenticatorId } = await devtools.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2', transport: 'internal', hasResidentKey: true,
      hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true,
    },
  });
  try {
    await page.goto('/auth/login');
    await page.getByLabel('Email', { exact: true }).fill('person@example.test');
    await page.getByLabel('Password', { exact: true }).fill('correct horse battery staple');
    await page.locator('#password-login-form').getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/auth\/?$/);
    await page.goto('/auth/settings/passkeys');
    await page.getByLabel('Passkey name', { exact: true }).fill('Synthetic browser device');
    await page.getByRole('button', { name: 'Add Passkey', exact: true }).click();
    await expect(page.getByRole('status')).toHaveText('Passkey registered successfully.');

    // Hold presence while the login page starts conditional mediation, so the
    // visible button can start its own ceremony after the choice is checked.
    await devtools.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId, enabled: false });
    const loggedOut = await page.evaluate(async () => {
      const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
      return (await fetch('/auth/logout', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf } })).ok;
    });
    expect(loggedOut).toBe(true);
    await page.goto('/auth/login');
    await page.getByLabel('Keep me signed in', { exact: true }).check();
    const options = page.waitForResponse((response) => response.url().endsWith('/auth/api/passkeys/auth/options') && response.request().method() === 'POST');
    const authenticated = page.waitForResponse((response) => response.url().endsWith('/auth/api/passkeys/auth') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Sign in with a passkey', exact: true }).click();
    expect((await options).ok()).toBe(true);
    await devtools.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId, enabled: true });
    expect((await authenticated).ok()).toBe(true);
    await expect(page).toHaveURL(/\/auth\/?$/);
    const cookies = await context.cookies();
    const remembered = cookies.find((cookie) => cookie.name.startsWith('remember_auth-manager_'));
    expect(remembered?.path).toBe('/auth');
    expect(remembered?.httpOnly).toBe(true);
    expect(remembered?.expires).toBeGreaterThan(Date.now() / 1000);
    expect(cookies.some((cookie) => cookie.name.startsWith('remember_web_'))).toBe(false);
  } finally {
    await devtools.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
    await devtools.detach();
  }
});

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
