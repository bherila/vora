import { expect, test } from '@playwright/test';

import { login, type TestAccounts } from './helpers';

test('Library bulk organization moves only the current collection membership and confirms deletion', async ({ page, request }, testInfo) => {
  const suffix = `Library bulk ${testInfo.project.name}`;
  const accountResponse = await request.get('/__browser/fixtures', { headers: { 'x-browser-name-suffix': suffix } });
  const accounts = await accountResponse.json() as TestAccounts;
  const fixtureResponse = await request.get('/__browser/library-fixtures', {
    headers: { 'x-browser-owner': String(accounts.alice.id), 'x-browser-saver': String(accounts.bob.id) },
  });
  expect(fixtureResponse.ok()).toBeTruthy();
  await login(page, accounts.bob, `Bob ${suffix}`);
  await page.goto('/library');
  const setup = await page.evaluate(async () => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    async function create(path: string, body: Record<string, string>) {
      const response = await fetch(`/api/library/${path}`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body) });
      if (!response.ok) throw new Error(`Fixture creation failed: ${response.status}`);
      return (await response.json()).data as { ulid: string };
    }
    const source = await create('collections', { name: 'Received media' });
    const other = await create('collections', { name: 'Keep membership' });
    const target = await create('collections', { name: 'Sorted media' });
    const first = await create('notes', { title: 'First received note', body: 'Private first note', collection_ulid: source.ulid });
    const second = await create('notes', { title: 'Second received note', body: 'Private second note', collection_ulid: source.ulid });
    await create(`items/${first.ulid}/collections`, { collection_ulid: other.ulid });
    return { source, other, target, first, second };
  });
  await page.goto(`/library?collection=${setup.source.ulid}`);
  await page.getByRole('checkbox', { name: 'Select all on this page', exact: true }).check();
  await expect(page.getByText('2 selected', { exact: true })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBeTruthy();
  await page.screenshot({ path: testInfo.outputPath('library-bulk-selection.png'), fullPage: true });
  await page.getByLabel('Search Library items').fill('First');
  await expect(page.getByText('2 selected', { exact: true })).toHaveCount(0);
  await page.getByLabel('Search Library items').fill('');
  await expect(page.getByRole('button', { name: 'Open Second received note', exact: true })).toBeVisible();
  await page.getByRole('checkbox', { name: 'Select all on this page', exact: true }).check();
  await page.getByRole('button', { name: 'Move to collection', exact: true }).click();
  let dialog = page.getByRole('dialog');
  await expect(dialog.getByText(/Other collection memberships remain/)).toBeVisible();
  await dialog.getByLabel('Destination collection').selectOption(setup.target.ulid);
  await dialog.getByRole('button', { name: 'Move to collection', exact: true }).click();
  await expect(dialog).toHaveCount(0);
  await expect(page.getByText('No items here yet.', { exact: true })).toBeVisible();
  await page.goto(`/library?collection=${setup.other.ulid}`);
  await expect(page.getByRole('button', { name: 'Open First received note', exact: true })).toBeVisible();
  await page.goto(`/library?collection=${setup.target.ulid}`);
  await page.getByRole('checkbox', { name: 'Select all on this page', exact: true }).check();
  await page.getByRole('button', { name: 'Remove from collection', exact: true }).click();
  dialog = page.getByRole('dialog');
  await expect(dialog.getByText(/They remain in your Library and other collections/)).toBeVisible();
  await dialog.getByRole('button', { name: 'Remove from collection', exact: true }).click();
  await expect(page.getByText('No items here yet.', { exact: true })).toBeVisible();
  await page.goto('/library');
  await page.getByRole('checkbox', { name: 'Select all on this page', exact: true }).check();
  await page.getByRole('button', { name: 'Remove from Library', exact: true }).click();
  dialog = page.getByRole('dialog');
  await expect(dialog.getByText(/keep their own saved items/)).toBeVisible();
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Open First received note', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Remove from Library', exact: true }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Remove from Library', exact: true }).click();
  await expect(page.getByText('No items here yet.', { exact: true })).toBeVisible();
  const membershipResponse = await page.request.get(`/api/library/items?collection_ulid=${setup.other.ulid}`);
  expect(membershipResponse.ok()).toBeTruthy();
  expect((await membershipResponse.json()).data).toHaveLength(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBeTruthy();
});
