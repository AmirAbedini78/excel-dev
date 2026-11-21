import { test, expect } from '@playwright/test';
import { requireStagingBaseUrl } from '../staging-guard';

test.beforeAll(() => {
  requireStagingBaseUrl();
});

test.describe('UAT-TENANT — company isolation', () => {
  test.beforeEach(async ({ page }) => {
    const email = process.env.STAGING_UAT_EMAIL;
    const password = process.env.STAGING_UAT_PASSWORD;
    test.skip(!email || !password, 'Credentials not set');

    await page.goto('/index.php?page=login');
    await page.locator('input[name="email"], input[type="email"]').first().fill(email!);
    await page.locator('input[name="password"], input[type="password"]').first().fill(password!);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
  });

  test('trading cockpit company filter does not 500', async ({ page }) => {
    const response = await page.goto('/index.php?page=trading_cockpit');
    expect(response?.status() ?? 0).toBeLessThan(500);
    await expect(page.locator('body')).not.toContainText(/Fatal error|Parse error/i);
  });

  test('dashboard company_id query accepted', async ({ page }) => {
    const companyId = process.env.STAGING_UAT_COMPANY_ID ?? '1';
    const response = await page.goto(`/index.php?page=dashboard&company_id=${companyId}`);
    expect(response?.status() ?? 0).toBeLessThan(500);
  });
});