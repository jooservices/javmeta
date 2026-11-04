import { defineStep } from '../lib/step.mjs';

export const login = defineStep('login', async ctx => {
  const { page, base, email, password } = ctx;
  await page.goto(base + '/login');
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', password);
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('button[type=submit]')]);
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
