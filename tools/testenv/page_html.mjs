// Print the attachment ids that the inline images of an issue page point to, logged in as admin:
//   REDMINE_URL=... ISSUE=123 node page_html.mjs
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '/opt/node22/lib/node_modules/playwright/index.mjs');
const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(`${process.env.REDMINE_URL}/login`);
await page.fill('#username', 'admin');
await page.fill('#password', process.env.REDMINE_ADMIN_PASSWORD || 'Admin-Test-2026');
await Promise.all([page.waitForNavigation(), page.click('#login-submit')]);
await page.goto(`${process.env.REDMINE_URL}/issues/${process.env.ISSUE}`);
const where = { description: '.issue .description .wiki img', journal: '#history .wiki img' };
for (const [name, selector] of Object.entries(where)) {
  const srcs = await page.$$eval(selector, (imgs) => imgs.map((i) => i.getAttribute('src')));
  console.log(`${name}: ${srcs.join(', ') || '(no image)'}`);
}
const listed = await page.$$eval('.attachments a.icon-attachment, .attachments .filename a, table.attachments a', (as) => as.map((a) => a.textContent.trim()).filter(Boolean));
console.log(`attachment list on the page: ${listed.join(', ')}`);
await browser.close();
