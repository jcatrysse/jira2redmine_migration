// B37: an issue that keeps a retired select value, checked in the browser and through the REST API.
//   REDMINE_URL=... REDMINE_API_KEY=... IDS='<json printed by retired_values.rb create>' node retired_values.mjs
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '/opt/node22/lib/node_modules/playwright/index.mjs');
const base = process.env.REDMINE_URL;
const ids = JSON.parse(process.env.IDS);
const api = (path, method = 'GET', body) => fetch(`${base}${path}`, {
  method,
  headers: { 'X-Redmine-API-Key': process.env.REDMINE_API_KEY, 'Content-Type': 'application/json' },
  body: body ? JSON.stringify(body) : undefined,
});
const out = (check, value) => console.log(`${check}: ${value}`);

const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(`${base}/login`);
await page.fill('#username', 'admin');
await page.fill('#password', process.env.REDMINE_ADMIN_PASSWORD || 'Admin-Test-2026');
await Promise.all([page.waitForNavigation(), page.click('#login-submit')]);

await page.goto(`${base}/issues/${ids.issue}`);
for (const [format, id] of [['list', ids.list], ['enumeration', ids.enum]]) {
  out(`${format} display`, JSON.stringify((await page.textContent(`.cf_${id} .value`))?.trim()));
}
await page.goto(`${base}/issues/${ids.issue}/edit`);
for (const [format, id] of [['list', ids.list], ['enumeration', ids.enum]]) {
  const select = `#issue_custom_field_values_${id}`;
  const options = await page.$$eval(`${select} option`, (os) => os.map((o) => o.textContent.trim()).filter(Boolean));
  const selected = await page.$eval(select, (s) => s.options[s.selectedIndex]?.textContent.trim());
  out(`${format} edit form`, `options ${JSON.stringify(options)}, selected ${JSON.stringify(selected)}`);
}
await page.fill('#issue_notes', 'J2R retired values check: save without touching the fields');
await Promise.all([page.waitForNavigation(), page.click('#issue-form input[type=submit][name=commit]')]);
out('edit save (browser)', (await page.$('#flash_notice')) ? 'saved' : `not saved: ${(await page.textContent('#errorExplanation'))?.trim()}`);

const values = async () => Object.fromEntries((await (await api(`/issues/${ids.issue}.json`)).json())
  .issue.custom_fields.filter((f) => [ids.list, ids.enum].includes(f.id)).map((f) => [f.id === ids.list ? 'list' : 'enumeration', f.value]));
out('REST values after the browser save', JSON.stringify(await values()));
out('REST PUT notes only', (await api(`/issues/${ids.issue}.json`, 'PUT', { issue: { notes: 'J2R REST check' } })).status);
out('REST PUT the retired values again', (await api(`/issues/${ids.issue}.json`, 'PUT', { issue: {
  custom_fields: [{ id: ids.list, value: 'Old' }, { id: ids.enum, value: String(ids.old_enum_id) }] } })).status);
out('REST values after the PUTs', JSON.stringify(await values()));
const created = await api('/issues.json', 'POST', { issue: { project_id: ids.project, tracker_id: ids.tracker,
  subject: 'J2R retired values, new issue', custom_fields: [{ id: ids.list, value: 'Old' }, { id: ids.enum, value: String(ids.old_enum_id) }] } });
out('REST new issue with the retired values', `${created.status} ${JSON.stringify((await created.json()).errors ?? 'created')}`);
await browser.close();
