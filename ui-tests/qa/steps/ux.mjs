import { defineStep } from '../lib/step.mjs';

export const expectToast = defineStep('expectToast', async (ctx, pattern) => {
  const re = pattern instanceof RegExp ? pattern : new RegExp(pattern, 'i');
  await ctx.page.waitForFunction(src => [...document.querySelectorAll('#toasts .toast')].some(t => new RegExp(src, 'i').test(t.textContent || '')), re.source, { timeout: 6000 })
    .catch(async () => {
      const texts = await ctx.page.$$eval('#toasts .toast', els => els.map(e => e.textContent.trim())).catch(() => []);
      throw new Error(`No toast matched ${re}: ${texts.join(' | ') || '(none)'}`);
    });
});

export const expectDiscoverHeading = defineStep('expectDiscoverHeading', async ctx => {
  const info = await ctx.page.evaluate(() => ({
    h1: [...document.querySelectorAll('#view h1')].map(h => h.textContent.trim()),
    searchLabel: document.querySelector('#hsq')?.getAttribute('aria-label') || '',
  }));
  if (!info.h1.includes('Khám phá')) throw new Error(`Discover h1 is ${JSON.stringify(info.h1)}`);
  if (!info.searchLabel) throw new Error('Discover search has no aria-label.');
});

export const expectVietnameseNav = defineStep('expectVietnameseNav', async ctx => {
  const labels = await ctx.page.$$eval('#side a.nav span', els => els.map(e => e.textContent.trim()));
  for (const forbidden of ['Discover', 'Maker', 'Series', 'Label', 'Command Center', 'Crawl & nguồn']) {
    if (labels.includes(forbidden)) throw new Error(`Sidebar still shows English "${forbidden}": ${labels.join(', ')}`);
  }
  for (const needed of ['Khám phá', 'Hãng', 'Loạt phim', 'Nhãn', 'Thu thập & nguồn', 'Vận hành']) {
    if (!labels.includes(needed)) throw new Error(`Sidebar lacks "${needed}": ${labels.join(', ')}`);
  }
});

export const expectOneMovieLinkPerCard = defineStep('expectOneMovieLinkPerCard', async ctx => {
  const dupes = await ctx.page.evaluate(() => {
    const cards = [...document.querySelectorAll('#view .mc')].slice(0, 8);
    return cards.map(card => ({
      hrefs: [...card.querySelectorAll('a[href*="#/movie/"]')].map(a => a.getAttribute('href')),
    })).filter(c => c.hrefs.length !== 1);
  });
  if (dupes.length) throw new Error(`U-17: ${dupes.length} movie cards do not have exactly one film link.`);
});

export const expectNoChoCrawl = defineStep('expectNoChoCrawl', async ctx => {
  const text = await ctx.page.$eval('#view', e => e.innerText);
  if (text.includes('chờ crawl')) throw new Error('U-20: the page still says "chờ crawl".');
  if (!/chưa có/i.test(text)) throw new Error('U-20: missing fields are not labelled "chưa có".');
});

export const expectPlayerEmptyState = defineStep('expectPlayerEmptyState', async ctx => {
  const info = await ctx.page.evaluate(() => ({
    text: document.querySelector('#view')?.innerText || '',
    settings: !!document.querySelector('#view a[href="#/settings"]'),
    retry: [...document.querySelectorAll('#view button')].some(b => /thử lại/i.test(b.textContent || '')),
  }));
  if (!/Không có tệp trên đĩa|Máy chủ tệp đang tắt/.test(info.text)) throw new Error(`U-18: player is not an empty state: ${info.text.slice(0, 160)}`);
  if (!info.settings) throw new Error('U-18: the empty player state has no Settings link.');
  if (info.retry) throw new Error('U-18: the empty player state still offers retry.');
});

export const filterSettings = defineStep('filterSettings', async (ctx, query) => {
  const box = ctx.page.locator('[data-settings-filter]');
  await box.waitFor({ state: 'visible', timeout: 4000 });
  await box.fill(query);
  await ctx.page.waitForTimeout(200);
});

export const expectSettingsFilterHidesRows = defineStep('expectSettingsFilterHidesRows', async (ctx, query) => {
  const info = await ctx.page.evaluate(q => {
    const rows = [...document.querySelectorAll('[data-settings-row]')];
    const visible = rows.filter(r => !r.hidden);
    return {
      total: rows.length,
      visible: visible.length,
      sample: visible.slice(0, 5).map(r => r.dataset.settingsRow),
      allMatch: visible.every(r => ((r.textContent || '') + ' ' + (r.dataset.settingsRow || '')).toLowerCase().includes(q.toLowerCase())),
    };
  }, query);
  if (info.total < 10) throw new Error('Settings has too few filterable rows to prove the filter.');
  if (info.visible === 0) throw new Error(`The settings filter hid every row for "${query}".`);
  if (info.visible >= info.total) throw new Error(`The settings filter did not hide any row for "${query}".`);
  if (!info.allMatch) throw new Error(`A visible settings row does not match "${query}": ${info.sample.join(', ')}`);
});

export const expectCrawlSubtabs = defineStep('expectCrawlSubtabs', async ctx => {
  const names = await ctx.page.$$eval('[data-pane="crawl"] [data-subtab]', els => els.map(e => e.textContent.trim()));
  if (names.length < 3) throw new Error(`U-19: crawl tab is not split (${names.join(', ') || 'none'}).`);
  await ctx.page.click('[data-pane="crawl"] [data-subtab="worker"]');
  const visible = await ctx.page.$eval('[data-pane="crawl"] [data-subpane="worker"]', el => !el.hidden);
  if (!visible) throw new Error('U-19: the worker sub-tab did not show its pane.');
});

export const confirmLogout = defineStep('confirmLogout', async ctx => {
  await ctx.page.locator('.logout-form button[type=submit]').click();
  const yes = ctx.page.locator('[data-confirm-yes]');
  await yes.waitFor({ state: 'visible', timeout: 4000 }).catch(() => { throw new Error('U-21: sign-out did not ask to confirm.'); });
  await yes.click();
  await ctx.page.waitForURL(/\/login/, { timeout: 8000 }).catch(() => { throw new Error('Confirming sign-out did not return to login.'); });
});

export const cancelLogout = defineStep('cancelLogout', async ctx => {
  await ctx.page.locator('.logout-form button[type=submit]').click();
  const no = ctx.page.locator('[data-confirm-no]');
  await no.waitFor({ state: 'visible', timeout: 4000 }).catch(() => { throw new Error('U-21: sign-out did not ask to confirm.'); });
  await no.click();
  await ctx.page.waitForTimeout(300);
  if (ctx.page.url().includes('/login')) throw new Error('Cancelling sign-out still left the session.');
  const open = await ctx.page.evaluate(() => document.querySelector('#ov')?.classList.contains('on'));
  if (open) throw new Error('The sign-out confirm stayed open after Huỷ.');
});

export const expectReducedMotionCounters = defineStep('expectReducedMotionCounters', async ctx => {
  await ctx.page.emulateMedia({ reducedMotion: 'reduce' });
  await ctx.page.reload({ waitUntil: 'load' });
  await ctx.page.evaluate(() => { location.hash = '#/'; });
  await ctx.page.waitForFunction(() => document.querySelector('#view .cu')?.dataset.to, null, { timeout: 12000 })
    .catch(() => { throw new Error('Discover has no counter to check.'); });
  const info = await ctx.page.evaluate(() => {
    const el = document.querySelector('#view .cu');
    return { shown: el?.textContent?.trim() || '', to: el?.dataset.to || '' };
  });
  const shown = Number(String(info.shown).replace(/\D/g, ''));
  const to = Number(info.to);
  if (shown !== to) throw new Error(`U-21: reduced motion still animates the counter (${info.shown} vs ${info.to}).`);
});

export const openMovieTab = defineStep('openMovieTab', async (ctx, label) => {
  await ctx.page.locator('#view a.tab', { hasText: label }).click();
  await ctx.page.waitForTimeout(400);
});

export const expectMovieTab = defineStep('expectMovieTab', async (ctx, label) => {
  const on = await ctx.page.locator('#view a.tab.on').innerText();
  if (!on.includes(label)) throw new Error(`The active movie tab is "${on.trim()}", expected ${label}.`);
});

export const copyMovieCode = defineStep('copyMovieCode', async ctx => {
  await ctx.page.locator('#view button', { hasText: /Sao chép mã/ }).click();
});

export const toggleWatchLater = defineStep('toggleWatchLater', async ctx => {
  const btn = ctx.page.locator('#view [data-mark][data-kind=watch_later]');
  await btn.waitFor({ state: 'visible', timeout: 4000 });
  const before = await btn.getAttribute('aria-pressed');
  await btn.click();
  await ctx.page.waitForFunction(v => document.querySelector('#view [data-mark][data-kind=watch_later]')?.getAttribute('aria-pressed') !== v, before, { timeout: 6000 })
    .catch(() => { throw new Error('Watch-later did not change.'); });
});

export const openBioValue = defineStep('openBioValue', async ctx => {
  const link = ctx.page.locator('#view .panel a.lnk').first();
  await link.waitFor({ state: 'visible', timeout: 8000 });
  await link.click();
  await ctx.page.waitForFunction(() => location.hash.includes('/performers'), null, { timeout: 8000 });
});

export const openStatsLink = defineStep('openStatsLink', async ctx => {
  const link = ctx.page.locator('#view a[href*="#/facet/"], #view a[href*="#/catalog/"]').first();
  await link.waitFor({ state: 'visible', timeout: 8000 });
  await link.click();
  await ctx.page.waitForFunction(() => /#\/(facet|catalog|movies)/.test(location.hash), null, { timeout: 8000 });
});

export const keyboardWalkDiscoverToMovie = defineStep('keyboardWalkDiscoverToMovie', async ctx => {
  await ctx.page.locator('body').click({ position: { x: 8, y: 8 } });
  await ctx.page.keyboard.press('/');
  const focused = await ctx.page.evaluate(() => document.activeElement?.id);
  if (focused !== 'omq') throw new Error('Keyboard search did not focus the omnibox.');
  await ctx.page.keyboard.type('SMP-0520');
  await ctx.page.keyboard.press('Enter');
  await ctx.page.waitForFunction(() => location.hash.includes('search'), null, { timeout: 8000 });
  await ctx.page.waitForTimeout(1200);
  const link = ctx.page.locator('#view a[href*="#/movie/SMP-0520"]').first();
  await link.focus();
  await ctx.page.keyboard.press('Enter');
  await ctx.page.waitForFunction(() => location.hash.includes('/movie/SMP-0520'), null, { timeout: 8000 });
});
