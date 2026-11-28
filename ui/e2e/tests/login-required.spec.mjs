import { test, expect, request as pwRequest } from '@playwright/test';
import { watch } from './helpers.mjs';

// The installation keeps the UI behind sign-in by default (ui_security.login_required). The journeys switch it on through the real
// administration API, check what a visitor sees, and always switch it off again for the other specs.
const API = `${process.env.E2E_BASE_URL || 'http://127.0.0.1:5173'}/api/v2`;

async function setLoginRequired(value) {
  const api = await pwRequest.newContext();
  const signIn = await api.post(`${API}/sessions`, { data: { email: 'admin@e2e.test', password: 'e2e-password-1' } });
  const csrf = (await signIn.json()).data.csrf;
  const group = await api.get(`${API}/settings/ui_security`);
  const put = await api.put(`${API}/settings/ui_security`, { headers: { 'X-CSRF-Token': csrf, 'If-Match': group.headers().etag }, data: { values: { 'ui_security.login_required': value } } });
  expect(put.ok(), await put.text()).toBeTruthy();
  await api.dispose();
}

test.describe.configure({ mode: 'serial' });
test.afterAll(async () => { await setLoginRequired(false); });

test('a visitor is sent to the sign-in page while sign-in is required, and the API refuses the data', async ({ page, request }) => {
  await setLoginRequired(true);
  expect((await request.get(`${API}/movies`)).status()).toBe(401);
  expect((await request.get(`${API}/sessions/current`)).status()).toBe(200);

  await page.goto('/movies');
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole('button', { name: 'Đăng nhập' })).toBeVisible();
});

test('after signing in the user reaches the data while sign-in is required', async ({ page }) => {
  await setLoginRequired(true);
  const problems = watch(page);
  await page.goto('/login');
  await page.getByLabel('Email').fill('viewer@e2e.test');
  await page.getByLabel('Mật khẩu').fill('e2e-password-1');
  await page.getByRole('button', { name: 'Đăng nhập' }).click();
  await expect(page.getByLabel('Tài khoản')).toBeVisible();
  await page.goto('/movies');
  await expect(page.locator('a[href^="/movies/E2E-"]').first()).toBeVisible();
  expect(problems).toEqual([]);
});
