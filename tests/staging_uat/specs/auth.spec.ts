import { test, expect } from '@playwright/test';
import { requireStagingBaseUrl } from '../staging-guard';

test.beforeAll(() => {
  requireStagingBaseUrl();
});

test.describe('UAT-AUTH — staging auth (requires credentials)', () => {
  test('login page loads RTL', async ({ page }) => {
    await page.goto('/index.php?page=login');
    await expect(page.locator('body')).toBeVisible();
    const dir = await page.locator('html').getAttribute('dir');
    expect(dir === 'rtl' || (await page.content()).includes('rtl')).toBeTruthy();
  });

  test('admin login and logout when env set', async ({ page }) => {
    const email = process.env.STAGING_UAT_EMAIL;
    const password = process.env.STAGING_UAT_PASSWORD;
    test.skip(!email || !password, 'STAGING_UAT_EMAIL / STAGING_UAT_PASSWORD not set');

    await page.goto('/index.php?page=login');
    await page.locator('input[name="email"], input[type="email"]').first().fill(email!);
    await page.locator('input[name="password"], input[type="password"]').first().fill(password!);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();

    await expect(page).not.toHaveURL(/page=login$/);
    await page.goto('/index.php?page=login&action=logout');
    await expect(page).toHaveURL(/page=login/);
  });
});