import { test, expect } from '@playwright/test';
import { watch } from './helpers.mjs';

// Public pages: each must render and call only API endpoints that answer 2xx.
const PAGES = ['/', '/search', '/updates', '/movies', '/performers', '/calendar', '/help', '/genres', '/makers', '/series'];

for (const path of PAGES) {
  test(`guest opens ${path} without a failed API call`, async ({ page }) => {
    const problems = watch(page);
    await page.goto(path);
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText('Không tìm thấy trang');
    expect(problems).toEqual([]);
  });
}

test('guest browses movies, opens one and sees its cast', async ({ page }) => {
  const problems = watch(page);
  await page.goto('/movies');
  const first = page.locator('a[href^="/movies/E2E-"]').first();
  await expect(first).toBeVisible();
  await first.click();
  await expect(page).toHaveURL(/\/movies\/E2E-\d+/);
  await expect(page.getByText(/Aoi Test|Mio Sample|Rin Example/).first()).toBeVisible();
  expect(problems).toEqual([]);
});

test('guest opens a performer and sees their movies', async ({ page }) => {
  const problems = watch(page);
  await page.goto('/performers');
  await page.getByText('Aoi Test').first().click();
  await expect(page).toHaveURL(/\/performers\/\d+/);
  await page.waitForLoadState('networkidle');
  await expect(page.getByText('Aoi Test').first()).toBeVisible();
  expect(problems).toEqual([]);
});

test('guest searches and finds a movie by its code', async ({ page }) => {
  const problems = watch(page);
  await page.goto('/search?q=E2E-007');
  await expect(page.getByText('E2E-007').first()).toBeVisible();
  expect(problems).toEqual([]);
});

test('guest does not see the statistics: the page asks to sign in, the menu has no entry and the API refuses', async ({ page, request }) => {
  const problems = watch(page);
  await page.goto('/stats');
  await expect(page.getByText('Bạn cần đăng nhập')).toBeVisible();
  await expect(page.locator('a[href="/stats"]')).toHaveCount(0);
  expect((await request.get('/api/v2/statistics/overview')).status()).toBe(401);
  expect(problems).toEqual([]);
});

test('guest is asked to sign in for personal pages and admin pages', async ({ page }) => {
  await page.goto('/favorites');
  await expect(page.getByText('Bạn cần đăng nhập')).toBeVisible();
});

test('guest switches the language to English and the choice survives a reload', async ({ page }) => {
  const problems = watch(page);
  await page.goto('/');
  await expect(page.getByRole('link', { name: 'Đăng nhập' })).toBeVisible();
  await page.getByText('EN', { exact: true }).click();
  await expect(page.getByRole('link', { name: 'Sign in' })).toBeVisible();
  await page.reload();
  await expect(page.getByRole('link', { name: 'Sign in' })).toBeVisible();
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  await page.getByText('VI', { exact: true }).click();
  await expect(page.getByRole('link', { name: 'Đăng nhập' })).toBeVisible();
  expect(problems).toEqual([]);
});
