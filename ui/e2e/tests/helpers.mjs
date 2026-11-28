import { expect } from '@playwright/test';

/** Collects every page error, console error and failed (4xx/5xx) API call of a page, so a test can assert there were none. */
export function watch(page) {
  const problems = [];
  page.on('pageerror', e => problems.push(`pageerror on ${page.url()}: ${e.message}`));
  page.on('console', m => { if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) problems.push(`console: ${m.text()}`); });
  page.on('response', r => { if (r.url().includes('/api/') && r.status() >= 400) problems.push(`${r.status()} ${r.request().method()} ${new URL(r.url()).pathname}${new URL(r.url()).search}`); });
  return problems;
}

export async function signIn(page, email, password = 'e2e-password-1') {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Mật khẩu').fill(password);
  await page.getByRole('button', { name: 'Đăng nhập' }).click();
  await expect(page.getByLabel('Tài khoản')).toBeVisible();
}
