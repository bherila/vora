import { type Browser, type BrowserContext, expect, type Page, test } from '@playwright/test';

export interface TestAccount {
  id: number;
  email: string;
}

export interface TestAccounts {
  alice: TestAccount;
  bob: TestAccount;
}

interface ContentRecord {
  id: number;
  ulid: string;
}

export interface ContentFixtures {
  admin: TestAccount;
  stories: { longForm: ContentRecord; adventure: ContentRecord };
  media: Record<'pending' | 'rejected' | 'uploading' | 'processing' | 'broken', ContentRecord & { title: string }>;
}

export async function contentFixtures(page: Page, owner: TestAccount): Promise<ContentFixtures> {
  const response = await page.request.get('/__browser/content-fixtures', {
    headers: { 'x-browser-owner': String(owner.id) },
  });
  expect(response.ok()).toBeTruthy();
  return response.json() as Promise<ContentFixtures>;
}

export async function login(page: Page, account: Pick<TestAccount, 'email'>, name: string): Promise<void> {
  page.on('pageerror', (error) => console.error(error));
  await page.goto('/login');
  await page.getByLabel('Email', { exact: true }).fill(account.email);
  await page.getByLabel('Password', { exact: true }).fill('password');
  await page.getByRole('button', { name: 'Sign In', exact: true }).click();
  await expect(page).toHaveURL(/\/login\/two-factor\//);
  // Read the actual emailed code from the test worker, then verify through UI.
  const response = await page.request.get('/__browser/two-factor', {
    headers: { 'x-browser-email': account.email },
  });
  expect(response.ok()).toBeTruthy();
  const { code } = await response.json() as { code: string };
  await page.getByLabel('Verification Code').fill(code);
  await page.getByRole('button', { name: 'Verify Code', exact: true }).click();
  await expect(page).toHaveURL(/\/feed$/);
  await expect(page.getByRole('button', { name: `Account and identity menu (currently ${name})` })).toBeVisible();
}

export async function acceptFollow(page: Page): Promise<void> {
  await page.goto('/users/follow-requests');
  await page.getByRole('button', { name: 'Accept', exact: true }).click();
  await expect(page.getByText('Follow request accepted.', { exact: false })).toBeVisible();
}

export async function peerContext(browser: Browser): Promise<BrowserContext> {
  const { baseURL, viewport, userAgent, deviceScaleFactor, isMobile, hasTouch } = test.info().project.use;
  return browser.newContext({ baseURL, viewport, userAgent, deviceScaleFactor, isMobile, hasTouch });
}
