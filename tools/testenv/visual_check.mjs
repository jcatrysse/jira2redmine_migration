// Text conversion protocol, step 3: Jira's rendered description next to Redmine's page, per curated issue.
// The output holds Jira data: write it outside the repository.
//   REDMINE_URL=... OUT=dir ITEMS=items.json node tools/testenv/visual_check.mjs
// items.json: from tools/testenv/visual_items.php
import fs from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '/opt/node22/lib/node_modules/playwright/index.mjs');
const base = process.env.REDMINE_URL; const out = process.env.OUT;
const items = JSON.parse(fs.readFileSync(process.env.ITEMS, 'utf8'));
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1000, height: 900 } });
await page.goto(`${base}/login`);
await page.fill('#username', 'admin');
await page.fill('#password', process.env.REDMINE_ADMIN_PASSWORD || 'Admin-Test-2026');
await Promise.all([page.waitForNavigation(), page.click('#login-submit')]);
const rows = [];
for (const it of items) {
  await page.goto(`${base}/issues/${it.redmine_id}`);
  // the sticky issue header of newer Redmine versions would cover the element screenshots
  await page.addStyleTag({ content: '#sticky-issue-header{display:none!important}' });
  // Redmine 7 loads inline images lazily: load them all before the element screenshots (else blank areas)
  await page.evaluate(async () => {
    const images = [...document.querySelectorAll('img')];
    images.forEach((img) => { img.loading = 'eager'; });
    await Promise.all(images.map((img) => (img.complete ? null : new Promise((done) => { img.onload = img.onerror = done; }))));
  });
  const shot = `${it.key}-redmine.png`;
  const desc = await page.$('.issue .description');
  if (desc) await desc.screenshot({ path: `${out}/${shot}` }); else await page.screenshot({ path: `${out}/${shot}` });
  const notes = [];
  for (const c of it.comments || []) {
    const journal = await page.$(`#change-${c.journal_id}`);
    const file = `${it.key}-journal-${c.journal_id}.png`;
    if (journal) await journal.screenshot({ path: `${out}/${file}` });
    notes.push(`<section><h2>Jira comment</h2>${c.jira_html}</section>`
      + `<section><h2>Redmine journal ${c.journal_id}</h2>${journal ? `<img src="${file}">` : 'not on the page'}</section>`);
  }
  const html = `<!doctype html><meta charset="utf-8"><title>${it.key}</title>
<style>body{font:14px sans-serif;margin:0}main{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:12px}
section{border:1px solid #ccc;padding:8px;overflow:auto}h2{font-size:15px;margin:4px 0 8px}img{max-width:100%}
table{border-collapse:collapse}td,th{border:1px solid #bbb;padding:2px 4px}pre{background:#f4f4f4;padding:6px;white-space:pre-wrap}
.aui-lozenge{border:1px solid #888;border-radius:3px;padding:0 4px;font-size:11px;text-transform:uppercase}</style>
<main><section><h2>Jira (rendered HTML from the API; Jira images need a login)</h2>${it.jira_html}</section>
<section><h2>Redmine #${it.redmine_id}</h2><img src="${shot}"></section>${notes.join('')}</main>`;
  fs.writeFileSync(`${out}/${it.key}.html`, html);
  rows.push(`<li><a href="${it.key}.html">${it.key}</a> (${it.construct})</li>`);
}
fs.writeFileSync(`${out}/index.html`, `<!doctype html><meta charset="utf-8"><title>Visual check</title><h1>Curated set</h1><ul>${rows.join('')}</ul>`);
await browser.close();
console.log(`pages: ${items.length}`);
