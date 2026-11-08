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

/** The email field is empty: no hardcoded address is shown to a visitor. */
export const expectSignInEmailEmpty = defineStep('expectSignInEmailEmpty', async ctx => {
  const value = await ctx.page.$eval('#email', el => el.value);
  if (value !== '') throw new Error(`Sign-in email is prefilled with "${value}".`);
});

/** The public front door is Vietnamese XCrawler, not the English operations console. */
export const expectFrontDoorIsXCrawlerInVietnamese = defineStep('expectFrontDoorIsXCrawlerInVietnamese', async ctx => {
  const info = await ctx.page.evaluate(() => ({
    lang: document.documentElement.lang,
    title: document.title,
    h1: document.querySelector('h1')?.innerText || '',
    text: document.body.innerText,
  }));
  if (info.lang !== 'vi') throw new Error(`Sign-in lang is "${info.lang}", expected vi.`);
  if (!/XCrawler/.test(info.title) || !/Đăng nhập/.test(info.title)) throw new Error(`Sign-in title is "${info.title}".`);
  if (info.h1 !== 'Đăng nhập') throw new Error(`Sign-in heading is "${info.h1}".`);
  if (!/riêng tư/.test(info.text)) throw new Error('Sign-in does not explain that the catalog is private.');
  if (/Operations sign in|JavMeta Operations|Command Center/.test(info.text + info.title)) throw new Error('Sign-in still uses operations jargon.');
});
