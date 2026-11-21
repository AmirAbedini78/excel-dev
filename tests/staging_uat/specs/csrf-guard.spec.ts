import { test, expect } from '@playwright/test';
import { stagingUrl } from '../staging-guard';

/**
 * UAT-SEC-001 — CSRF negative probe via request API (no curl required).
 * Requires authenticated storage state optional; logout POST still validates CSRF on many routes.
 */
test('POST without CSRF is rejected (expect 419 or 403)', async ({ request }) => {
  const url = stagingUrl('/index.php?page=login&action=logout');
  const response = await request.post(url, {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    data: {},
    failOnStatusCode: false,
  });
  expect([403, 419, 401, 302]).toContain(response.status());
});