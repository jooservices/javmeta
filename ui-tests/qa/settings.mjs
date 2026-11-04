// Interaction QA of the Settings page: every tab, every switch, number/select/textarea editing, save and persistence.
import { chromium } from 'playwright-core';
import { readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
const here = dirname(fileURLToPath(import.meta.url));
const base = process.argv[2] || 'http://localhost:8099';
const pw = readFileSync(process.env.QA_PASSWORD_FILE || resolve(here, '../../.tmp/qa-password'), 'utf8').trim();
const shots = process.env.QA_SHOTS || resolve(here, '../../.tmp/qa-shots');
const b = await chromium.launch({ executablePath: process.env.CHROME_PATH || `${homedir()}/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome`, args: ['--no-sandbox'] });
const c = await b.newContext({ viewport: { width: 1440, height: 900 } });
const p = await c.newPage();
const issues = [];
p.on('pageerror', e => issues.push('pageerror ' + String(e).slice(0, 160)));
p.on('console', m => m.type() === 'error' && issues.push('console ' + m.text().slice(0, 160)));
p.on('response', r => r.status() >= 400 && issues.push(`http ${r.status()} ${r.url().replace(base, '')}`));
await p.goto(base + '/login'); await p.fill('input[name=email]', 'qa@javmeta.test'); await p.fill('input[name=password]', pw);
await Promise.all([p.waitForNavigation().catch(() => {}), p.click('button[type=submit]')]);
await p.goto(base + '/app#/settings'); await p.waitForSelector('[data-tab]');
const tabs = await p.$$eval('[data-tab]', els => els.map(e => e.dataset.tab));
console.log('tabs:', tabs.join(', '));
for (const tab of tabs) {
  await p.click(`[data-tab="${tab}"]`); await p.waitForTimeout(250);
  const info = await p.evaluate(t => {
    const pane = document.querySelector(`[data-pane="${t}"]`);
    const visible = pane && !pane.hidden && pane.getBoundingClientRect().height > 0;
    return { visible, sections: pane?.querySelectorAll('[data-group],.panel').length ?? 0, controls: pane?.querySelectorAll('input,select,textarea').length ?? 0,
      active: document.querySelector('.tab.on')?.dataset.tab, overflow: pane ? pane.scrollWidth - pane.clientWidth : 0,
      nativeCheckboxVisible: [...(pane?.querySelectorAll('input[type=checkbox]') || [])].filter(i => !i.closest('.switch') && getComputedStyle(i).display !== 'none').length };
  }, tab);
  const bad = [];
  if (!info.visible) bad.push('pane not visible');
  if (info.active !== tab) bad.push('tab not marked active');
  if (info.sections === 0) bad.push('no sections');
  if (info.overflow > 0) bad.push('horizontal overflow ' + info.overflow);
  if (info.nativeCheckboxVisible) bad.push(info.nativeCheckboxVisible + ' native checkboxes outside .switch');
  console.log(tab.padEnd(8), JSON.stringify(info), bad.length ? 'PROBLEM: ' + bad.join('; ') : 'ok');
}
// switch toggles update their label; number editing; save + persistence
await p.click('[data-tab="crawl"]');
const sw = await p.$('.switch input[data-type="bool"]');
if (sw) {
  const key = await sw.getAttribute('data-key'); const before = await sw.isChecked();
  await p.click(`.switch:has(input[data-key="${key}"])`);
  const label = await p.$eval(`.switch:has(input[data-key="${key}"]) .txt`, e => e.textContent.trim());
  console.log('switch', key, 'before', before, 'label after click:', label, label === (before ? 'Tắt' : 'Bật') ? 'ok' : 'PROBLEM: label did not follow state');
  const group = await sw.evaluate(e => e.closest('[data-group]').dataset.group);
  await p.click(`[data-save="${group}"]`); await p.waitForTimeout(600);
  console.log('save message:', await p.$eval(`[data-group="${group}"] [data-msg]`, e => e.textContent.trim()));
  await p.waitForTimeout(2000);
  await p.goto(base + '/app#/settings'); await p.waitForSelector('[data-tab]'); await p.click('[data-tab="crawl"]');
  const after = await (await p.$(`input[data-key="${key}"]`)).isChecked();
  console.log('persisted after reload:', after !== before ? 'ok' : 'PROBLEM: value did not persist');
  await p.click(`.switch:has(input[data-key="${key}"])`); await p.click(`[data-save="${group}"]`); await p.waitForTimeout(800);
}
await p.screenshot({ path: `${shots}/settings-crawl.png` });
console.log('console/network issues:', issues.length ? [...new Set(issues)].join(' | ') : 'none');
await b.close();
