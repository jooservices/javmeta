import { test, expect } from '@playwright/test';
import { watch, signIn } from './helpers.mjs';

test('a user signs in, marks a movie as a favourite and finds it on the favourites page', async ({ page }) => {
  const problems = watch(page);
  await signIn(page, 'viewer@e2e.test');
  await page.goto('/movies/E2E-005');
  await page.getByRole('button', { name: /yêu thích/i }).first().click();
  await page.goto('/favorites');
  await expect(page.getByText('E2E-005').first()).toBeVisible();
  expect(problems).toEqual([]);
});

test('a wrong password is refused with a message', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('Email').fill('viewer@e2e.test');
  await page.getByLabel('Mật khẩu').fill('wrong-password');
  await page.getByRole('button', { name: 'Đăng nhập' }).click();
  await expect(page.getByText('Sai email hoặc mật khẩu.')).toBeVisible();
});

test('a signed-in user opens the personal pages without a failed API call', async ({ page }) => {
  await signIn(page, 'viewer@e2e.test');
  const problems = watch(page);
  for (const path of ['/profile', '/activity', '/history', '/favorites', '/watch-later', '/watched', '/following', '/library', '/downloads', '/media']) {
    await page.goto(path);
    await page.waitForLoadState('networkidle');
  }
  expect(problems).toEqual([]);
});

test('a plain user is kept out of the admin area', async ({ page }) => {
  await signIn(page, 'viewer@e2e.test');
  await page.goto('/admin');
  await expect(page.getByText('Command Center')).toHaveCount(0);
});
