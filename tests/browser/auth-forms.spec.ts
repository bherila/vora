import { expect, test } from '@playwright/test';

import { peerContext, type TestAccounts } from './helpers';

function isFeedUrl(url: URL): boolean {
  return url.pathname === '/feed' && (url.search === '' || url.search === '?scope=mixed');
}

for (const remember of [false, true]) {
  test(`local password and MFA login ${remember ? 'persists' : 'does not persist'} after session cookies are discarded`, async ({ browser, page, context, request }) => {
    const fixture = await request.get('/__browser/fixtures');
    expect(fixture.ok()).toBeTruthy();
    const { alice } = await fixture.json() as TestAccounts;
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(alice.email);
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByLabel('Remember me', { exact: true }).setChecked(remember);
    const loginResponse = page.waitForResponse((response) => response.url().endsWith('/api/auth/login') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    const passwordResponse = await loginResponse;
    expect(passwordResponse.ok()).toBeTruthy();
    expect(passwordResponse.request().postDataJSON()).toMatchObject({ email: alice.email, remember });
    await expect(page).toHaveURL(/\/login\/two-factor\//);
    expect((await context.cookies()).filter((cookie) => cookie.name.startsWith('remember_web_'))).toHaveLength(0);
    const challenge = await page.request.get('/__browser/two-factor', { headers: { 'x-browser-email': alice.email } });
    expect(challenge.ok()).toBeTruthy();
    const { code } = await challenge.json() as { code: string };
    await page.getByLabel('Verification Code', { exact: true }).fill(code);
    await page.getByRole('button', { name: 'Verify Code', exact: true }).click();
    await expect(page).toHaveURL(isFeedUrl);
    const identity = page.getByRole('button', { name: 'Account and identity menu (currently Alice)' });
    await expect(identity).toBeVisible();
    const recallers = (await context.cookies()).filter((cookie) => cookie.name.startsWith('remember_web_'));
    expect(recallers).toHaveLength(remember ? 1 : 0);
    if (remember) {
      const recaller = recallers[0];
      if (!recaller) throw new Error('MFA login did not issue its requested persistent cookie.');
      expect(recaller.httpOnly).toBe(true);
      expect(recaller.path).toBe('/');
      expect(recaller.expires).toBeGreaterThan(Date.now() / 1000);
    }
    // A fresh cookie jar also excludes late Set-Cookie responses from the old
    // page's background requests, which can repopulate a cleared active jar.
    const nextContext = await peerContext(browser);
    try {
      await nextContext.addCookies(recallers);
      expect((await nextContext.cookies()).map((cookie) => cookie.name)).toEqual(recallers.map((cookie) => cookie.name));
      const nextPage = await nextContext.newPage();
      const navigation = await nextPage.goto('/feed');
      if (!navigation) throw new Error('The next browser session did not receive a document response.');
      expect(navigation.status()).toBe(200);
      const originalRequest = navigation.request().redirectedFrom() ?? navigation.request();
      expect(new URL(originalRequest.url()).pathname).toBe('/feed');
      const cookieHeader = (await originalRequest.allHeaders()).cookie ?? '';
      const cookieNames = cookieHeader.split(';').filter(Boolean).map((cookie) => cookie.trim().split('=')[0]);
      expect(cookieNames).toEqual(recallers.map((cookie) => cookie.name));
      if (remember) {
        expect(navigation.request().redirectedFrom()).toBeNull();
        await expect(nextPage).toHaveURL(isFeedUrl);
        await expect(nextPage.getByRole('button', { name: 'Account and identity menu (currently Alice)' })).toBeVisible();
      } else {
        const rejection = await originalRequest.response();
        expect(rejection?.status()).toBe(302);
        await expect(nextPage).toHaveURL(/\/login$/);
        await expect(nextPage.getByRole('button', { name: 'Sign In', exact: true })).toBeVisible();
      }
    } finally {
      await nextContext.close();
    }
  });
}

test('local password recovery keeps account privacy and completes a real reset from its mailed link', async ({ page, request }) => {
  const fixture = await request.get('/__browser/fixtures');
  expect(fixture.ok()).toBeTruthy();
  const { alice } = await fixture.json() as TestAccounts;
  const unknownEmail = `missing-${alice.id}@example.test`;
  const acknowledgement = 'If an account exists with this email, a password reset link has been sent.';
  await page.goto('/login');
  await page.getByRole('link', { name: 'Forgot your password?', exact: true }).click();
  await expect(page).toHaveURL(/\/forgot-password$/);
  await page.getByLabel('Email', { exact: true }).fill(unknownEmail);
  await page.getByRole('button', { name: 'Send Reset Link', exact: true }).click();
  await expect(page.getByRole('alert')).toHaveText(acknowledgement);
  const absentMail = await request.get('/__browser/password-reset-link', { headers: { 'x-browser-email': unknownEmail } });
  expect(absentMail.ok()).toBeTruthy();
  expect(await absentMail.json()).toEqual({ url: null });
  await page.getByRole('button', { name: 'Try a different email', exact: true }).click();
  await expect(page.getByLabel('Email', { exact: true })).toHaveValue('');
  await page.getByLabel('Email', { exact: true }).fill(alice.email);
  await page.getByRole('button', { name: 'Send Reset Link', exact: true }).click();
  await expect(page.getByRole('alert')).toHaveText(acknowledgement);
  const captured = await request.get('/__browser/password-reset-link', { headers: { 'x-browser-email': alice.email } });
  expect(captured.ok()).toBeTruthy();
  const { url } = await captured.json() as { url: string | null };
  expect(url).toBeTruthy();
  if (!url) throw new Error('The real reset confirmation mail did not contain a link.');
  await page.goto(url);
  await expect(page.getByLabel('Email', { exact: true })).toHaveValue(alice.email);
  await expect(page.locator('#reset-password')).toHaveCount(1);
  const password = page.getByLabel('New Password', { exact: true });
  const confirmation = page.getByLabel('Confirm New Password', { exact: true });
  await expect(password).toHaveAttribute('id', 'new-password');
  await expect(password).toHaveAttribute('minlength', '8');
  const resetRequests: string[] = [];
  page.on('request', (outgoing) => {
    if (new URL(outgoing.url()).pathname === '/api/auth/reset-password' && outgoing.method() === 'POST') resetRequests.push(outgoing.url());
  });
  await password.fill('new-synthetic-password');
  await confirmation.fill('different-synthetic-password');
  await page.getByRole('button', { name: 'Reset Password', exact: true }).click();
  await expect(page.getByRole('alert')).toHaveText('Passwords do not match.');
  expect(resetRequests).toHaveLength(0);
  await password.fill('short');
  await confirmation.fill('short');
  await page.getByRole('button', { name: 'Reset Password', exact: true }).click();
  expect(await password.evaluate((input: HTMLInputElement) => input.checkValidity())).toBe(false);
  expect(resetRequests).toHaveLength(0);
  await password.fill('new-synthetic-password');
  await confirmation.fill('new-synthetic-password');
  let captureResult!: (result: { status: number; body: string }) => void;
  const resetResult = new Promise<{ status: number; body: string }>((resolve) => { captureResult = resolve; });
  // Forward the real backend response unchanged, but capture its body before
  // releasing it to the page: success immediately navigates to another document.
  await page.route('**/api/auth/reset-password', async (route) => {
    const response = await route.fetch({ maxRedirects: 0 });
    captureResult({ status: response.status(), body: await response.text() });
    await route.fulfill({ response });
  });
  await page.getByRole('button', { name: 'Reset Password', exact: true }).click();
  const result = await resetResult;
  expect(result.status).toBe(200);
  const payload: unknown = JSON.parse(result.body);
  expect(payload).toMatchObject({ success: true });
  expect(resetRequests).toHaveLength(1);
  await expect(page).toHaveURL(isFeedUrl);
  await expect(page.getByRole('button', { name: 'Account and identity menu (currently Alice)' })).toBeVisible();
});
