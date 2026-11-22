/**
 * Disposable LOCAL browser UAT (Playwright + system Chrome). Not STAGING_VERIFIED.
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BASE = process.env.LOCAL_UAT_BASE_URL || 'http://127.0.0.1:8765';
const OUT = __dirname;

async function shot(page, name) {
  await page.screenshot({ path: path.join(OUT, name), fullPage: false });
}

async function main() {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const context = await browser.newContext({ locale: 'fa-IR', viewport: { width: 1400, height: 900 } });
  const page = await context.newPage();
  const log = [];

  await page.goto(`${BASE}/index.php?page=login`, { waitUntil: 'networkidle', timeout: 60000 });
  await page.fill('input[name="email"]', 'rc1.uat@example.local');
  await page.fill('input[name="password"]', 'Rc1UatPass99!');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle', timeout: 60000 }),
    page.click('button[type="submit"]'),
  ]);
  log.push({ step: 'login', url: page.url() });

  await page.goto(`${BASE}/index.php?page=calendar`, { waitUntil: 'networkidle', timeout: 60000 });
  await shot(page, '01-login-jalali-calendar.png');
  log.push({ step: 'calendar', title: await page.title(), rtl: await page.locator('html').getAttribute('dir') });

  await page.goto(`${BASE}/index.php?page=trading_cockpit`, { waitUntil: 'networkidle', timeout: 60000 });
  await shot(page, '02-trading-cockpit-rtl-empty.png');
  log.push({ step: 'trading', title: await page.title() });

  await page.goto(`${BASE}/index.php?page=manufacturing_cockpit`, { waitUntil: 'networkidle', timeout: 60000 });
  await shot(page, '03-manufacturing-cockpit-blocked.png');
  log.push({ step: 'manufacturing', title: await page.title() });

  await page.goto(`${BASE}/index.php?page=service`, { waitUntil: 'networkidle', timeout: 60000 });
  await shot(page, '04-service-empty.png');
  log.push({ step: 'service', title: await page.title() });

  await page.goto(`${BASE}/index.php?page=settings&company_id=2`, { waitUntil: 'networkidle', timeout: 60000 });
  await shot(page, '05-retail-irr-profile.png');
  log.push({ step: 'settings_c2', title: await page.title() });

  await page.goto(`${BASE}/index.php?page=calendar&company_id=1`, { waitUntil: 'networkidle', timeout: 60000 });
  await shot(page, '06-company-isolation-calendar.png');
  log.push({ step: 'isolation', title: await page.title() });

  await page.goto(`${BASE}/index.php?page=settings&company_id=1`, { waitUntil: 'networkidle', timeout: 60000 });
  await shot(page, '07-service-toman-profile.png');
  log.push({ step: 'settings_c1', title: await page.title() });

  await page.goto(`${BASE}/index.php?page=logout`, { waitUntil: 'networkidle', timeout: 60000 });
  await page.goto(`${BASE}/index.php?page=login`, { waitUntil: 'networkidle', timeout: 60000 });
  await shot(page, '08-logout-login.png');
  log.push({ step: 'logout', url: page.url() });

  await page.fill('input[name="email"]', 'rc1.limited@example.local');
  await page.fill('input[name="password"]', 'Rc1Limited99!');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle', timeout: 60000 }),
    page.click('button[type="submit"]'),
  ]);
  await page.goto(`${BASE}/index.php?page=trading_cockpit`, { waitUntil: 'networkidle', timeout: 60000 });
  await shot(page, '09-limited-viewer-trading.png');
  log.push({ step: 'limited', title: await page.title(), url: page.url() });

  await browser.close();
  const summary = {
    ok: true,
    classification: 'LOCAL_BROWSER_VERIFIED',
    STAGING_VERIFIED: false,
    base_url: BASE,
    verified_at_utc: new Date().toISOString(),
    tool: 'playwright-chromium-channel-chrome',
    cases: log,
  };
  fs.writeFileSync(path.join(OUT, 'local_uat_playwright_rerun.json'), JSON.stringify(summary, null, 2));
  console.log(JSON.stringify(summary, null, 2));
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
