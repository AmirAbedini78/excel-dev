import { test, expect } from '@playwright/test';
import { requireStagingBaseUrl } from '../staging-guard';

test.beforeAll(() => {
  requireStagingBaseUrl();
});

test.describe('UAT-UX — RTL / Jalali', () => {
  test('login shell RTL + Jalali today marker when present', async ({ page }) => {
    await page.goto('/index.php?page=login');
    const html = await page.content();
    const rtl = (await page.locator('html').getAttribute('dir')) === 'rtl' || html.includes('dir="rtl"');
    expect(rtl).toBeTruthy();
    // Jalali: four-digit year 13xx–15xx or Persian month names — loose check
    const jalaliHint = /14\d{2}\/\d{2}\/\d{2}|آبان|فروردین|امروز/.test(html);
    expect(jalaliHint, 'Expected Jalali date or Persian month label on shell').toBeTruthy();
  });
});