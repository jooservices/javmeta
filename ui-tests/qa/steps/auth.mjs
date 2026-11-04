import { defineStep } from '../lib/step.mjs';

/** Signs in as the owner. Login is throttled to 6 attempts a minute (as in production), so a 429 waits out the window once. */
export const login = defineStep('login', async ctx => {
  const { page, base, email, password } = ctx;
  for (let attempt = 1; attempt <= 2; attempt += 1) {
    await page.goto(base + '/login');
    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', password);
    const [response] = await Promise.all([
      page.waitForResponse(r => r.url().endsWith('/login') && r.request().method() === 'POST'),
      page.click('button[type=submit]'),
    ]);
    if (response.status() === 429 && attempt === 1) { await page.waitForTimeout(62000); continue; }
    await page.waitForLoadState('load');
    break;
  }
  if (page.url().includes('/login')) throw new Error('Still on the login page after signing in.');
});

export const loginWith = defineStep('loginWithWrongPassword', async (ctx, password) => {
  const { page, base, email } = ctx;
  await page.goto(base + '/login');
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', password);
  await page.click('button[type=submit]');
  await page.waitForTimeout(600);
});

export const expectOnLoginPage = defineStep('expectOnLoginPage', async ctx => {
  if (!ctx.page.url().includes('/login')) throw new Error(`Expected the login page, on ${ctx.page.url()}`);
});
