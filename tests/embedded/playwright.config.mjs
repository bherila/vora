import { fileURLToPath } from 'node:url';

import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  testMatch: 'auth.spec.ts',
  workers: 1,
  timeout: 60_000,
  use: {
    baseURL: 'https://localhost:4188',
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'embedded-chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    cwd: fileURLToPath(new URL('../..', import.meta.url)),
    command: 'node tests/embedded/server.mjs',
    url: 'https://localhost:4188/__embedded/ready',
    ignoreHTTPSErrors: true,
    reuseExistingServer: false,
    gracefulShutdown: { signal: 'SIGTERM', timeout: 6_000 },
  },
});
