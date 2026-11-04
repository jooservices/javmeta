// usage: node walk.mjs <baseUrl> <email> <passwordFile> <outDir>
// Logs in, visits every view of the single-page UI at desktop and phone widths, and reports per view:
// console errors, failed or 4xx/5xx requests, horizontal overflow, empty main area. Saves a screenshot per view and width.
import { chromium } from 'playwright-core';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';

const [base, email, passwordFile, outDir] = process.argv.slice(2);
mkdirSync(outDir, { recursive: true });
const password = readFileSync(passwordFile, 'utf8').trim();
const chrome = process.env.CHROME_PATH || `${homedir()}/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome`;
const routes = [
  '#/', '#/search', '#/stats', '#/movies', '#/performers', '#/bio', '#/movies?sort=update_date',
  '#/catalog/genres', '#/catalog/makers', '#/catalog/series', '#/catalog/labels', '#/catalog/years', '#/calendar',
  '#/me/favourite', '#/me/watch_later', '#/me/seen', '#/feed', '#/library', '#/downloads', '#/media', '#/crawl', '#/admin', '#/settings',
];
const widths = [{ name: 'desktop', width: 1440, height: 900 }, { name: 'phone', width: 390, height: 844 }];
const browser = await chromium.launch({ executablePath: chrome, args: ['--no-sandbox'] });
const report = [];
for (const size of widths) {
  const context = await browser.newContext({ viewport: { width: size.width, height: size.height } });
  const page = await context.newPage();
  const problems = [];
  page.on('console', m => { if (m.type() === 'error') problems.push('console: ' + m.text().slice(0, 200)); });
  page.on('pageerror', e => problems.push('pageerror: ' + String(e).slice(0, 200)));
  page.on('response', r => { if (r.status() >= 400) problems.push(`http ${r.status()} ${r.url().replace(base, '').slice(0, 120)}`); });
  page.on('requestfailed', r => problems.push('requestfailed: ' + r.url().replace(base, '').slice(0, 120)));
  await page.goto(base + '/login');
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', password);
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }).catch(() => {}), page.click('button[type=submit]')]);
  await page.waitForTimeout(1500);
  for (const route of routes) {
    problems.length = 0;
    await page.goto(base + '/app' + route, { waitUntil: 'load' }).catch(() => {});
    await page.evaluate(r => { location.hash = r; }, route).catch(() => {});
    await page.waitForTimeout(1800);
    const info = await page.evaluate(() => {
      const view = document.querySelector('#view') || document.querySelector('main');
      return {
        overflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        textLength: (view?.innerText || '').trim().length,
        title: document.title,
        headings: [...document.querySelectorAll('h1,h2')].slice(0, 3).map(h => h.innerText.trim().slice(0, 60)),
        smallButtons: [...document.querySelectorAll('button,a.btn,.ibtn')].filter(b => { const r = b.getBoundingClientRect(); return r.width > 0 && (r.height < 24 || r.width < 24); }).length,
        unlabeledButtons: [...document.querySelectorAll('button')].filter(b => !b.innerText.trim() && !b.getAttribute('aria-label') && !b.title).length,
        imagesWithoutAlt: [...document.querySelectorAll('img')].filter(i => !i.hasAttribute('alt')).length,
      };
    });
    const file = `${size.name}-${route.replace(/[^a-z0-9]+/gi, '_')}.png`;
    await page.screenshot({ path: `${outDir}/${file}`, fullPage: false });
    report.push({ size: size.name, route, ...info, problems: [...new Set(problems)], file });
  }
  await context.close();
}
await browser.close();
writeFileSync(`${outDir}/report.json`, JSON.stringify(report, null, 2));
for (const r of report) {
  const flags = [];
  if (r.problems.length) flags.push(`${r.problems.length} problems`);
  if (r.overflowX > 0) flags.push(`overflowX ${r.overflowX}px`);
  if (r.textLength < 20) flags.push('empty view');
  if (r.unlabeledButtons) flags.push(`${r.unlabeledButtons} unlabeled buttons`);
  if (r.smallButtons) flags.push(`${r.smallButtons} tiny targets`);
  console.log(`${r.size.padEnd(7)} ${r.route.padEnd(28)} ${flags.join(' | ') || 'ok'}`);
}
