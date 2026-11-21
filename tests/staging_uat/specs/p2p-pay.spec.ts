import { test, expect } from '@playwright/test';
import { requireStagingBaseUrl } from '../staging-guard';

/**
 * UAT-P2P-003 skeleton — full pay path needs owner-seeded staging data.
 * Manual steps: RC1-STAGING-UAT-PROCEDURES.md §6.
 */
test.beforeAll(() => {
  requireStagingBaseUrl();
});

test.describe('UAT-P2P — pay path', () => {
  test('procurement and p2p pages reachable after login', async ({ page }) => {
    const email = process.env.STAGING_UAT_EMAIL;
    const password = process.env.STAGING_UAT_PASSWORD;
    test.skip(!email || !password, 'Credentials not set');
    test.skip(
      process.env.STAGING_UAT_ENABLE_PAY_FLOW !== '1',
      'Pay flow disabled; set STAGING_UAT_ENABLE_PAY_FLOW=1 when synthetic AP exists',
    );

    await page.goto('/index.php?page=login');
    await page.locator('input[name="email"], input[type="email"]').first().fill(email!);
    await page.locator('input[name="password"], input[type="password"]').first().fill(password!);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    for (const pageName of ['procurement', 'p2p']) {
      const response = await page.goto(`/index.php?page=${pageName}`);
      expect(response?.status() ?? 0).toBeLessThan(500);
    }

    // TODO(owner): navigate to treasury payment UI and complete synthetic allocation when seed ids are documented.
    test.info().annotations.push({
      type: 'manual',
      description: 'Complete UAT-P2P-003 payment/allocation with staging test vendor ids',
    });
  });
});