import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  use: {
    baseURL: process.env.WP_SITE_URL || 'http://localhost:8080',
    trace: 'retain-on-failure'
  },
  webServer: process.env.CI ? undefined : undefined
});
