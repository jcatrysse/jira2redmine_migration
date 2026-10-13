// Issue page load time in Chromium, logged in as admin: REDMINE_URL=... ISSUE=123 node page_load.mjs
// Playwright is installed globally in the cloud image; PLAYWRIGHT_BROWSERS_PATH points at its Chromium.
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '/opt/node22/lib/node_modules/playwright/index.mjs');
const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(`${process.env.REDMINE_URL}/login`);
await page.fill('#username', 'admin');
await page.fill('#password', process.env.REDMINE_ADMIN_PASSWORD || 'Admin-Test-2026');
await Promise.all([page.waitForNavigation(), page.click('#login-submit')]);
const start = Date.now();
const response = await page.goto(`${process.env.REDMINE_URL}/issues/${process.env.ISSUE}`, { waitUntil: 'load' });
console.log(`issue page http=${response.status()} load_ms=${Date.now() - start}`);
await browser.close();
