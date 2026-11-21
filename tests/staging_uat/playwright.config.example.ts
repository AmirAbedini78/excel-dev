import { defineConfig, devices } from '@playwright/test';
import { requireStagingBaseUrl } from './staging-guard';

// Fail at config load if staging URL is missing or unsafe.
const baseURL = requireStagingBaseUrl();

export default defineConfig({
  testDir: './specs',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL,
    locale: 'fa-IR',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'off',
  },
  projects: [
    {
      name: 'chromium-rtl',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});