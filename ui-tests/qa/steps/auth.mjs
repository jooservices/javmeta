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

/** Switch the UI language from the top-bar VI|EN control without a full navigation. */
export const switchUiLanguage = defineStep('switchUiLanguage', async (ctx, locale) => {
  const btn = ctx.page.locator(`#langsw button[data-v="${locale}"]`);
  if (await btn.count() === 0) throw new Error(`Language switcher has no ${locale} button.`);
  await btn.click();
  await ctx.page.waitForFunction(code => document.documentElement.lang === code, locale, { timeout: 5000 })
    .catch(() => { throw new Error(`lang attribute did not become ${locale}.`); });
});

/** Visible chrome texts and <html lang> match the requested locale. */
export const expectUiLanguage = defineStep('expectUiLanguage', async (ctx, locale) => {
  const info = await ctx.page.evaluate(() => ({
    lang: document.documentElement.lang,
    settings: [...document.querySelectorAll('#side a.nav span, #bnav a')].map(el => el.textContent || '').join(' | '),
  }));
  if (info.lang !== locale) throw new Error(`html lang is "${info.lang}", expected ${locale}.`);
  if (locale === 'en') {
    if (!/Settings/.test(info.settings)) throw new Error(`English chrome missing Settings: ${info.settings}`);
  } else if (!/Cài đặt/.test(info.settings)) {
    throw new Error(`Vietnamese chrome missing Cài đặt: ${info.settings}`);
  }
});

/** Reload the app shell and confirm the language choice survived. */
export const expectLanguagePersistsAfterReload = defineStep('expectLanguagePersistsAfterReload', async (ctx, locale) => {
  await ctx.page.reload({ waitUntil: 'load' });
  await ctx.page.waitForSelector('#langsw', { timeout: 8000 });
  const lang = await ctx.page.evaluate(() => document.documentElement.lang);
  if (lang !== locale) throw new Error(`After reload lang is "${lang}", expected ${locale}.`);
});

/* ---------- S-04: the sign-in password visibility control ---------- */

/** A synthetic probe, never the real QA password; it is typed into the field and only ever compared, never printed. */
const PASSWORD_PROBE = 'synthetic-Pw-0123';

const readToggle = (page, expected) => page.evaluate(probe => {
  const input = document.getElementById('password');
  const button = document.getElementById('pw-toggle');
  return {
    present: Boolean(input && button),
    type: input ? input.type : null,
    pressed: button ? button.getAttribute('aria-pressed') : null,
    label: button ? (button.textContent || '').trim() : null,
    show: button ? button.dataset.show : null,
    hide: button ? button.dataset.hide : null,
    preserved: input ? input.value === probe : false,
  };
}, expected);

/** Opens the sign-in form and counts any submission of it, so a toggle that submits the form is caught. */
export const openSignInForm = defineStep('openSignInForm', async ctx => {
  const { page, base } = ctx;
  ctx.state.signInSubmissions = 0;
  page.on('request', request => {
    if (request.method() === 'POST' && new URL(request.url()).pathname === '/login') ctx.state.signInSubmissions += 1;
  });
  await page.goto(base + '/login');
  await page.waitForLoadState('load');
});

export const typeSyntheticPassword = defineStep('typeSyntheticPassword', async ctx => {
  await ctx.page.fill('#password', PASSWORD_PROBE);
  ctx.state.probeTyped = true;
});

/** Activates the control the way a person would: a pointer click, or the keyboard (Enter / Space) on the focused button. */
export const activatePasswordToggle = defineStep('activatePasswordToggle', async (ctx, how) => {
  const button = ctx.page.locator('#pw-toggle');
  if (await button.count() === 0) throw new Error('The password visibility control (#pw-toggle) is missing from the sign-in form.');
  if (how === 'pointer') {
    await button.click();
  } else if (how === 'enter' || how === 'space') {
    await button.focus();
    await ctx.page.keyboard.press(how === 'enter' ? 'Enter' : 'Space');
  } else {
    throw new Error(`Unknown activation "${how}"; use pointer, enter or space.`);
  }
});

const expectToggleState = (visible) => async ctx => {
  const state = await readToggle(ctx.page, PASSWORD_PROBE);
  const word = visible ? 'visible' : 'masked';
  if (!state.present) throw new Error('The password field or its visibility control is missing.');
  if (state.type !== (visible ? 'text' : 'password')) throw new Error(`The password input type is "${state.type}", expected the ${word} state.`);
  if (state.pressed !== (visible ? 'true' : 'false')) throw new Error(`aria-pressed is "${state.pressed}", expected "${visible ? 'true' : 'false'}" in the ${word} state.`);
  const expectedLabel = visible ? state.hide : state.show;
  if (!expectedLabel || state.label !== expectedLabel) throw new Error(`The button text is "${state.label}", expected the translated "${expectedLabel}" in the ${word} state.`);
  if (ctx.state.probeTyped && !state.preserved) throw new Error(`The typed value was not preserved in the ${word} state.`);
};

export const expectPasswordMasked = defineStep('expectPasswordMasked', expectToggleState(false));
export const expectPasswordVisible = defineStep('expectPasswordVisible', expectToggleState(true));

/** Toggling must not submit the form or leave the sign-in page. */
export const expectSignInNotSubmitted = defineStep('expectSignInNotSubmitted', async ctx => {
  if (ctx.state.signInSubmissions !== 0) throw new Error(`The sign-in form was submitted ${ctx.state.signInSubmissions} time(s) by the visibility control.`);
  if (!new URL(ctx.page.url()).pathname.endsWith('/login')) throw new Error(`The page left the sign-in form (${ctx.page.url()}).`);
});
