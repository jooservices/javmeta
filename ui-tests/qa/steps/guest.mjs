import { defineStep } from '../lib/step.mjs';
import { onContextClose, putSetting } from './cleanup.mjs';
import { viewText } from './dom.mjs';

const HIDDEN_NAV = ['Yêu thích', 'Xem sau', 'Đã xem', 'Theo dõi', 'Thư viện', 'Tải về', 'Media', 'Quản trị', 'Cài đặt', 'Vận hành'];
const PUBLIC_NAV = ['Phim', 'Diễn viên', 'Tìm kiếm', 'Lịch phát hành'];

function watchIssues(page, base, issues) {
  page.on('pageerror', error => issues.push('pageerror ' + String(error).slice(0, 200)));
  page.on('console', message => { if (message.type() === 'error') issues.push('console ' + message.text().slice(0, 200)); });
  page.on('response', response => { if (response.status() >= 400) issues.push(`http ${response.status()} ${response.url().replace(base, '').slice(0, 120)}`); });
  page.on('requestfailed', request => issues.push('requestfailed ' + request.url().replace(base, '').slice(0, 120)));
}

/** Turns login off for anonymous visitors and puts it back when the scenario ends, pass or fail. */
export const enableGuestMode = defineStep('enableGuestMode', async ctx => {
  if (!ctx.state.guestRestore) {
    ctx.state.guestRestore = true;
    const ownerPage = ctx.page;
    ctx.state.ownerPage = ownerPage;
    onContextClose(ctx, async () => {
      await putSetting(ownerPage, 'ui_security.login_required', true);
    });
  }
  await putSetting(ctx.page, 'ui_security.login_required', false);
  const probe = await ctx.context.browser().newContext();
  try {
    const page = await probe.newPage();
    await page.goto(ctx.base + '/app', { waitUntil: 'load' });
    const mode = await page.locator('body').getAttribute('data-guest-mode');
    if (mode !== 'true') throw new Error(`Guest mode is not on (data-guest-mode=${mode || 'missing'}, url ${page.url()}).`);
  } finally {
    await probe.close();
  }
});

/** Continues the scenario in a second browser context that has never signed in. */
export const browseAsGuest = defineStep('browseAsGuest', async ctx => {
  const guestContext = await ctx.context.browser().newContext({ viewport: ctx.page.viewportSize() || { width: 1440, height: 900 } });
  onContextClose(ctx, async () => { await guestContext.close(); });
  const guestPage = await guestContext.newPage();
  const issues = [];
  watchIssues(guestPage, ctx.base, issues);
  ctx.state.guestContext = guestContext;
  ctx.page = guestPage;
  ctx.issues = issues;
});

export const expectGuestShell = defineStep('expectGuestShell', async ctx => {
  const mode = await ctx.page.locator('body').getAttribute('data-guest-mode');
  if (mode !== 'true') throw new Error(`The shell is not in guest mode (${mode || 'missing'}).`);
  const banner = ctx.page.locator('.guest-banner');
  await banner.waitFor({ state: 'visible', timeout: 8000 });
  const info = await ctx.page.evaluate(() => ({
    mode: document.body.dataset.guestMode || '',
    banner: (document.querySelector('.guest-banner')?.innerText || '').replace(/\s+/g, ' ').trim(),
    href: document.querySelector('.guest-banner a')?.getAttribute('href') || '',
    nav: [...document.querySelectorAll('#side a.nav, #bnav a')].map(link => link.textContent.replace(/\s+/g, ' ').trim()),
    csrf: !!document.querySelector('meta[name="csrf-token"]'),
    robots: document.querySelector('meta[name="robots"]')?.content || '',
    commandCenter: !!document.querySelector('meta[name="command-center-url"]'),
  }));
  if (info.mode !== 'true') throw new Error(`The shell is not in guest mode (${info.mode || 'missing'}).`);
  if (!info.banner.includes('Chế độ khách chỉ cho phép xem nội dung công khai.')) throw new Error(`The guest banner says "${info.banner}".`);
  if (!info.banner.includes('Đăng nhập để dùng các tính năng cá nhân và quản trị')) throw new Error(`The guest banner says "${info.banner}".`);
  if (info.href !== '/login') throw new Error(`The guest banner link points at "${info.href}".`);
  if (info.csrf) throw new Error('The guest shell includes a CSRF token.');
  if (!info.robots.includes('noindex')) throw new Error('The guest shell is not marked noindex.');
  if (info.commandCenter) throw new Error('The guest shell links to the command center.');
  for (const hidden of HIDDEN_NAV) {
    if (info.nav.some(item => item.includes(hidden))) throw new Error(`Guest navigation still shows "${hidden}".`);
  }
  for (const shown of PUBLIC_NAV) {
    if (!info.nav.some(item => item.includes(shown))) throw new Error(`Guest navigation lacks "${shown}".`);
  }
});

/** The closing period is part of the sentence, not a separate flex item sitting in the gap. */
export const expectGuestBannerSentence = defineStep('expectGuestBannerSentence', async ctx => {
  const banner = (await ctx.page.locator('.guest-banner').innerText()).replace(/\s+/g, ' ').trim();
  const expected = 'Chế độ khách chỉ cho phép xem nội dung công khai. Đăng nhập để dùng các tính năng cá nhân và quản trị.';
  if (banner !== expected) throw new Error(`The guest banner says "${banner}".`);
});

/** Enter on the banner link. The visitor is not signed in, so this must land on the login form. */
export const openGuestLoginWithKeyboard = defineStep('openGuestLoginWithKeyboard', async ctx => {
  const link = ctx.page.locator('.guest-banner a[href="/login"]');
  await link.focus();
  await ctx.page.keyboard.press('Enter');
  await ctx.page.waitForURL(/\/login/, { timeout: 8000 })
    .catch(() => { throw new Error(`Keyboard did not open login from the guest banner (now ${ctx.page.url()}).`); });
  if (await ctx.page.locator('input[name=email]').count() === 0) throw new Error('The login page has no email field.');
});

export const expectGuestPrompt = defineStep('expectGuestPrompt', async (ctx, label) => {
  const expected = `${label} chỉ dành cho chủ sở hữu đã đăng nhập.`;
  const shown = await ctx.page.waitForFunction(paragraph => {
    const heading = document.querySelector('#view .guest-login-prompt h1')?.textContent?.trim() || '';
    const text = document.querySelector('#view .guest-login-prompt p')?.textContent?.trim() || '';
    return heading === 'Đăng nhập để dùng tính năng này' && text === paragraph ? text : null;
  }, expected, { timeout: 8000 }).then(handle => handle.jsonValue(), async () => {
    const text = (await viewText(ctx.page)).replace(/\s+/g, ' ').trim().slice(0, 220);
    throw new Error(`The guest prompt for "${label}" is not shown. View: ${text}`);
  });
  if (shown !== expected) throw new Error(`The guest prompt says "${shown}".`);
  const href = await ctx.page.locator('#view .guest-login-prompt a.btn').getAttribute('href');
  if (href !== '/login') throw new Error(`The guest prompt link points at "${href}".`);
});

/** Clicks the prompt's login link. */
export const openGuestLogin = defineStep('openGuestLogin', async ctx => {
  await ctx.page.locator('#view .guest-login-prompt a[href="/login"]').click();
  await ctx.page.waitForURL(/\/login/, { timeout: 8000 })
    .catch(() => { throw new Error(`The guest prompt did not open login (now ${ctx.page.url()}).`); });
  if (await ctx.page.locator('input[name=email]').count() === 0) throw new Error('The login page has no email field.');
});

export const expectPathRequiresLogin = defineStep('expectPathRequiresLogin', async (ctx, path) => {
  await ctx.page.goto(ctx.base + path, { waitUntil: 'load' });
  if (!ctx.page.url().includes('/login')) throw new Error(`${path} stayed on ${ctx.page.url()}.`);
  if (await ctx.page.locator('input[name=email]').count() === 0) throw new Error(`${path} reached login without a form.`);
});

export const togglePhoneMenu = defineStep('togglePhoneMenu', async ctx => {
  await ctx.page.locator('.top [data-act=menu]').click();
  await ctx.page.waitForFunction(() => document.querySelector('#side')?.classList.contains('open'), null, { timeout: 4000 })
    .catch(() => { throw new Error('The phone menu did not open the sidebar.'); });
});

/** A destination in the open sidebar is the control that dismisses it. render() drops the open class. */
export const choosePhoneNav = defineStep('choosePhoneNav', async (ctx, label) => {
  const link = ctx.page.locator('#side a.nav').filter({ hasText: label }).first();
  await link.click();
  await ctx.page.waitForFunction(wanted => {
    const side = document.querySelector('#side');
    const view = document.querySelector('#view');
    const current = document.querySelector('#side a.nav.on');
    return !!side && !side.classList.contains('open') && !!view && !view.querySelector('.loader')
      && !!current && current.textContent.includes(wanted);
  }, label, { timeout: 12000 }).catch(() => { throw new Error(`Choosing "${label}" did not close the phone menu.`); });
});

/** Tapping the page beside an open phone menu should dismiss it. The button that opened it sits underneath the drawer. */
export const expectPhoneMenuDismisses = defineStep('expectPhoneMenuDismisses', async ctx => {
  const menu = ctx.page.locator('.top [data-act=menu]');
  await ctx.page.waitForFunction(() => {
    const button = document.querySelector('.top [data-act=menu]');
    const box = button?.getBoundingClientRect();
    if (!box || box.width < 2) return false;
    const node = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
    return !!node && !node.closest('[data-act=menu]');
  }, null, { timeout: 1000 }).catch(() => {});
  const box = await menu.boundingBox();
  if (!box) throw new Error('The phone menu button has no box.');
  const covered = await ctx.page.evaluate(({ x, y }) => {
    const node = document.elementFromPoint(x, y);
    return !node || node.closest('[data-act=menu]') ? '' : (node.closest('#side') ? 'the sidebar' : node.tagName);
  }, { x: box.x + Math.min(box.width / 2, 8), y: box.y + box.height / 2 });
  const banner = await ctx.page.locator('.guest-banner').boundingBox();
  if (banner) await ctx.page.mouse.click(banner.x + banner.width - 12, banner.y + Math.min(12, banner.height / 2));
  const stillOpen = await ctx.page.locator('#side.open').count();
  if (stillOpen || covered) {
    throw new Error(`The phone menu stays open${covered ? ` and its button is covered by ${covered}` : ''}.`);
  }
});

export const openBottomNav = defineStep('openBottomNav', async (ctx, label) => {
  const link = ctx.page.locator('#bnav a').filter({ hasText: label }).first();
  await link.waitFor({ state: 'visible', timeout: 8000 });
  await link.click();
  await ctx.page.waitForFunction(wanted => {
    const view = document.querySelector('#view');
    const current = document.querySelector('#bnav a.on');
    return !!view && !view.querySelector('.loader') && !!current && current.textContent.includes(wanted);
  }, label, { timeout: 12000 }).catch(() => { throw new Error(`The phone tab "${label}" did not open.`); });
});

export const expectGuestSavedSearchHidden = defineStep('expectGuestSavedSearchHidden', async ctx => {
  const text = await viewText(ctx.page);
  if (!text.includes('Đăng nhập để dùng tìm kiếm đã lưu.')) throw new Error('The movie list does not ask a guest to log in for saved searches.');
  const save = await ctx.page.locator('#view [data-save-search]').count();
  if (save !== 0) throw new Error('A guest can save a search.');
});

export const openFirstGuestMovie = defineStep('openFirstGuestMovie', async ctx => {
  const link = ctx.page.locator('#view a.lnk[href*="#/movie/"]').first();
  await link.waitFor({ state: 'visible', timeout: 8000 });
  const href = await link.getAttribute('href');
  const code = decodeURIComponent((href || '').split('#/movie/')[1] || '').split('?')[0];
  if (!code) throw new Error('The list has no movie link.');
  ctx.state.movieCode = code;
  await link.click();
  await ctx.page.waitForFunction(c => {
    const view = document.querySelector('#view');
    if (!view || view.querySelector('.loader') || !location.hash.includes(`/movie/${c}`)) return false;
    const title = view.querySelector('h1')?.innerText || '';
    return title.includes('Sample Movie') && [...view.querySelectorAll('a.tab')].some(tab => tab.textContent.includes('Metadata'));
  }, code, { timeout: 12000 }).catch(() => { throw new Error(`Opening ${code} did not show its detail.`); });
});

export const expectGuestMovie = defineStep('expectGuestMovie', async ctx => {
  const info = await ctx.page.evaluate(() => {
    const view = document.querySelector('#view');
    return {
      tabs: [...view.querySelectorAll('a.tab')].map(tab => tab.textContent.trim()),
      favourite: [...view.querySelectorAll('button')].some(button => /yêu thích/i.test(button.innerText)),
      code: (view.innerText.match(/SMP-\d+/) || [])[0] || '',
    };
  });
  if (!info.code) throw new Error('The guest movie page shows no sample code.');
  if (info.favourite) throw new Error('A guest can see the favourite button.');
  if (!info.tabs.includes('Metadata') || !info.tabs.includes('Mã & nguồn')) throw new Error(`Guest movie tabs are ${info.tabs.join(', ') || 'missing'}.`);
  if (info.tabs.some(tab => tab.includes('Liên kết'))) throw new Error('A guest can see the media tab.');
});

export const openGuestMovieCodes = defineStep('openGuestMovieCodes', async ctx => {
  await ctx.page.locator('#view a.tab', { hasText: 'Mã & nguồn' }).click();
  await ctx.page.waitForFunction(() => location.hash.includes('tab=codes') && !document.querySelector('#view .loader') && [...document.querySelectorAll('#view a.tab')].some(tab => tab.classList.contains('on') && tab.textContent.includes('Mã & nguồn')), null, { timeout: 12000 })
    .catch(() => { throw new Error('The codes tab did not open for a guest.'); });
  const text = await viewText(ctx.page);
  if (!text.includes('Mã')) throw new Error('The codes tab has no code table.');
});

export const expectGuestMediaBlocked = defineStep('expectGuestMediaBlocked', async ctx => {
  const code = ctx.state.movieCode;
  if (!code) throw new Error('No movie is open.');
  await ctx.page.evaluate(c => { location.hash = `#/movie/${encodeURIComponent(c)}?tab=media`; }, code);
  await ctx.page.waitForFunction(() => (document.querySelector('#view')?.innerText || '').includes('Đăng nhập để dùng liên kết và nội dung media.'), null, { timeout: 12000 })
    .catch(() => { throw new Error('The media tab does not ask a guest to log in.'); });
});

export const expectGuestHasNoFollow = defineStep('expectGuestHasNoFollow', async ctx => {
  const follow = await ctx.page.locator('#view [data-mark][data-kind=follow], #view button').filter({ hasText: /theo dõi/i }).count();
  if (follow !== 0) throw new Error('A guest can see the follow button.');
  const heading = (await ctx.page.locator('#view h1').innerText()).trim();
  if (!ctx.state.performerName || !heading.includes(ctx.state.performerName)) {
    throw new Error(`The profile heading is "${heading}".`);
  }
});

/** Puts login back before the context closes, and checks an anonymous visit is rejected again. */
export const restoreLoginRequired = defineStep('restoreLoginRequired', async ctx => {
  const owner = ctx.state.ownerPage;
  if (!owner) throw new Error('There is no owner page to restore login from.');
  await putSetting(owner, 'ui_security.login_required', true);
  await ctx.page.goto(ctx.base + '/app', { waitUntil: 'load' });
  if (!ctx.page.url().includes('/login')) throw new Error(`After restoring login, /app stayed on ${ctx.page.url()}.`);
  const mode = await ctx.page.locator('body').getAttribute('data-guest-mode').catch(() => null);
  if (mode === 'true') throw new Error('Anonymous /app is still in guest mode after login was required again.');
});
