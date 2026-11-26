import { test, expect } from '@playwright/test';
import { watch, signIn } from './helpers.mjs';

test('an admin opens every Command Center and settings page without a failed API call', async ({ page }) => {
  await signIn(page, 'admin@e2e.test');
  const problems = watch(page);
  for (const path of ['/admin', '/admin/infrastructure', '/admin/queue', '/admin/nodes', '/admin/alerts', '/admin/errors', '/admin/runs', '/admin/events', '/admin/sources', '/admin/keys', '/admin/settings', '/admin/notifications', '/admin/reports']) {
    await page.goto(path);
    await page.waitForLoadState('networkidle');
  }
  // Prometheus is not configured in this environment: the infrastructure page degrades (200), it is not an error.
  expect(problems).toEqual([]);
});
