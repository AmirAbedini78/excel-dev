import { test, expect } from '@playwright/test';
import { requireStagingBaseUrl } from '../staging-guard';

/**
 * UAT-SALES-002 skeleton — TR-2 settle requires owner-seeded staging data.
 */
test.beforeAll(() => {
  requireStagingBaseUrl();
});

test.describe('UAT-SALES — settle path', () => {
  test('trade page reachable', async ({ page }) => {
    const email = process.env.STAGING_UAT_EMAIL;
    const password = process.env.STAGING_UAT_PASSWORD;
    test.skip(!email || !password, 'Credentials not set');

    await page.goto('/index.php?page=login');
    await page.locator('input[name="email"], input[type="email"]').first().fill(email!);
    await page.locator('input[name="password"], input[type="password"]').first().fill(password!);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    const response = await page.goto('/index.php?page=trade');
    expect(response?.status() ?? 0).toBeLessThan(500);
  });

  test('settle flow placeholder', async () => {
    test.skip(
      process.env.STAGING_UAT_ENABLE_SALES_SETTLE !== '1',
      'Set STAGING_UAT_ENABLE_SALES_SETTLE=1 when synthetic TR-2 chain exists on staging',
    );
    // Implement with documented invoice/customer ids from owner seed manifest.
  });
});