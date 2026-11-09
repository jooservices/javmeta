import { chromium } from 'playwright-core';
import { readFileSync, mkdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

/** One browser per run, a fresh signed-out page per scenario. Console errors and failed requests are collected on the page. */
export async function openBrowser(options = {}) {
  const chrome = process.env.CHROME_PATH || `${homedir()}/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome`;
  const shots = options.shots || process.env.QA_SHOTS || resolve(here, '../../../.tmp/qa-shots');
  mkdirSync(shots, { recursive: true });
  const browser = await chromium.launch({ executablePath: chrome, args: ['--no-sandbox'] });
  const base = options.base || process.env.QA_BASE || 'http://localhost:8099';
  const email = options.email || process.env.QA_EMAIL || 'qa@javmeta.test';
  const password = options.password || readFileSync(process.env.QA_PASSWORD_FILE || resolve(here, '../../../.tmp/qa-password'), 'utf8').trim();
  return {
    base, email, password, shots, browser,
    async newScenarioContext(viewport = { width: 1440, height: 900 }) {
      const context = await browser.newContext({ viewport, locale: 'vi-VN' });
      const page = await context.newPage();
      const issues = [];
      page.on('pageerror', e => issues.push('pageerror ' + String(e).slice(0, 200)));
      page.on('console', m => { if (m.type() === 'error') issues.push('console ' + m.text().slice(0, 200)); });
      page.on('response', r => { if (r.status() >= 400) issues.push(`http ${r.status()} ${r.url().replace(base, '').slice(0, 120)}`); });
      page.on('requestfailed', r => issues.push('requestfailed ' + r.url().replace(base, '').slice(0, 120)));
      return { base, email, password, shots, context, page, issues, state: {} };
    },
    close: () => browser.close(),
  };
}
