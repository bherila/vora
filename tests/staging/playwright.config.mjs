import { defineConfig, devices } from '@playwright/test';

if (process.env.LOCAL_STAGING_ACCEPTANCE !== '1' || process.env.ALLOW_DISPOSABLE_ACCOUNT_PURGE !== '1') {
  throw new Error('Set LOCAL_STAGING_ACCEPTANCE=1 and ALLOW_DISPOSABLE_ACCOUNT_PURGE=1 only for the disposable Docker staging stack.');
}
for (const address of [process.env.STAGING_APP_URL ?? 'http://127.0.0.1:18080', process.env.STAGING_MAIL_URL ?? 'http://127.0.0.1:18025']) {
  const url = new URL(address);
  if (url.protocol !== 'http:' || !['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname) || url.username || url.password) {
    throw new Error('Staging acceptance refuses non-loopback or authenticated service URLs.');
  }
}

export default defineConfig({
  testDir: '.', testMatch: '*.spec.ts', workers: 1, retries: 0, timeout: 360_000,
  outputDir: './artifacts/results',
  metadata: {
    profile: process.env.LOCAL_STAGING_APP_PROFILE ?? 'vora',
    mailUrl: process.env.STAGING_MAIL_URL ?? 'http://127.0.0.1:18025',
    expectMultipartVideo: process.env.LOCAL_STAGING_MULTIPART_THRESHOLD_BYTES === '1024',
  },
  globalSetup: './generate-fixtures.mjs',
  reporter: [['list'], ['html', { outputFolder: './artifacts/report', open: 'never' }]],
  use: {
    ...devices['Desktop Chrome'], baseURL: process.env.STAGING_APP_URL ?? 'http://127.0.0.1:18080',
    actionTimeout: 20_000, trace: 'retain-on-failure', screenshot: 'only-on-failure',
  },
});
