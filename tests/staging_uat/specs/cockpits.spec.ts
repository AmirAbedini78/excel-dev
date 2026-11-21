import { test, expect } from '@playwright/test';
import { requireStagingBaseUrl } from '../staging-guard';

test.beforeAll(() => {
  requireStagingBaseUrl();
});

async function login(page: import('@playwright/test').Page) {
  const email = process.env.STAGING_UAT_EMAIL;
  const password = process.env.STAGING_UAT_PASSWORD;
  test.skip(!email || !password, 'Credentials not set');
  await page.goto('/index.php?page=login');
  await page.locator('input[name="email"], input[type="email"]').first().fill(email!);
  await page.locator('input[name="password"], input[type="password"]').first().fill(password!);
  await page.locator('button[type="submit"], input[type="submit"]').first().click();
}

test.describe('UAT-P2P / UAT-MF — cockpits', () => {
  test.beforeEach(login);

  test('trading cockpit TR-1 surface', async ({ page }) => {
    await page.goto('/index.php?page=trading_cockpit');
    await expect(page.locator('body')).not.toContainText(/BANK_RECONCILED/i);
    const content = await page.content();
    expect(content.toLowerCase()).not.toMatch(/paid.*without.*alloc/);
  });

  test('manufacturing cockpit shows blocked valuation (RC1 residual)', async ({ page }) => {
    await page.goto('/index.php?page=manufacturing_cockpit');
    const body = await page.locator('body').innerText();
    const blocked =
      /BLOCKED|مسدود|ارزش.?گذاری|UNVALUED|GL_BLOCKED/i.test(body) ||
      (await page.content()).includes('VALUATION_DEFAULT');
    expect(
      blocked,
      'Expected MF blocked / UNVALUED messaging per RC1 scope — full GL auto-post is NOT expected',
    ).toBeTruthy();
  });
});

test.describe('UAT-SVC — service module', () => {
  test.beforeEach(login);

  test('service page loads', async ({ page }) => {
    const response = await page.goto('/index.php?page=service');
    expect(response?.status() ?? 0).toBeLessThan(500);
  });
});