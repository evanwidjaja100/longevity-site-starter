import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  outputDir: './reports/playwright-artifacts',
  reporter: [['list'], ['html', { outputFolder: './reports/playwright', open: 'never' }]],
  retries: process.env.CI ? 1 : 0,
  use: {
    baseURL: process.env.WP_SITE_URL || 'http://localhost:8080',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure'
  }
});
