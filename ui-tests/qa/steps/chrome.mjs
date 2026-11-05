import { defineStep } from '../lib/step.mjs';

/** The theme button flips light/dark on the root element and the choice survives a reload. */
export const toggleTheme = defineStep('toggleTheme', async ctx => {
  const theme = () => ctx.page.evaluate(() => document.documentElement.getAttribute('data-theme') || 'dark');
  const before = await theme();
  await ctx.page.click('[data-act="theme"]');
  await ctx.page.waitForFunction(b => (document.documentElement.getAttribute('data-theme') || 'dark') !== b, before, { timeout: 4000 })
    .catch(() => { throw new Error(`The theme stayed "${before}" after clicking the theme button.`); });
  ctx.state.themeAfter = await theme();
});

export const expectThemeSurvivesReload = defineStep('expectThemeSurvivesReload', async ctx => {
  await ctx.page.reload({ waitUntil: 'load' });
  await ctx.page.waitForTimeout(1200);
  const now = await ctx.page.evaluate(() => document.documentElement.getAttribute('data-theme') || 'dark');
  if (now !== ctx.state.themeAfter) throw new Error(`The theme is "${now}" after a reload, expected "${ctx.state.themeAfter}".`);
});

/** Puts the theme back to dark (the default) so later scenarios start from the same look. */
export const resetThemeToDark = defineStep('resetThemeToDark', async ctx => {
  await ctx.page.evaluate(() => { try { localStorage.removeItem('theme'); localStorage.removeItem('jm-theme'); } catch { /* ignore */ } });
  const current = await ctx.page.evaluate(() => document.documentElement.getAttribute('data-theme') || 'dark');
  if (current !== 'dark') await ctx.page.click('[data-act="theme"]');
});

export const openHelp = defineStep('openHelp', async ctx => {
  await ctx.page.click('[data-act="help"]');
  await ctx.page.waitForSelector('#ov.on, #ov.open, #dlg:visible, .dlg', { state: 'visible', timeout: 4000 }).catch(() => { throw new Error('The help dialog did not open.'); });
  const text = await ctx.page.$eval('#dlg', e => e.innerText).catch(() => '');
  if (!/phím|Ctrl\+K|⌘K|Esc/i.test(text)) throw new Error('The help dialog does not list the keyboard shortcuts.');
});

export const expectHelpListsOnlyWorkingKeys = defineStep('expectHelpListsOnlyWorkingKeys', async ctx => {
  const info = await ctx.page.evaluate(() => ({
    kbds: [...document.querySelectorAll('#dlg kbd')].map(k => k.textContent.trim()),
    focused: document.activeElement && document.querySelector('#dlg')?.contains(document.activeElement),
    close: !!document.querySelector('#dlg [data-dlg-close]'),
  }));
  if (info.kbds.some(k => /^g [hmpsc]$/i.test(k))) throw new Error(`Help still lists chords that do nothing: ${info.kbds.join(', ')}`);
  if (!info.close) throw new Error('Help has no Đóng button.');
  if (!info.focused) throw new Error('Help did not move focus into the dialog.');
});

export const closeHelpWithButton = defineStep('closeHelpWithButton', async ctx => {
  await ctx.page.click('#dlg [data-dlg-close]');
  await ctx.page.waitForTimeout(300);
  const visible = await ctx.page.evaluate(() => document.querySelector('#ov')?.classList.contains('on'));
  if (visible) throw new Error('The Đóng button did not close the help dialog.');
});

export const expectCjkFontStack = defineStep('expectCjkFontStack', async ctx => {
  const stack = await ctx.page.evaluate(() => getComputedStyle(document.body).fontFamily);
  if (!/Noto Sans CJK|Meiryo|Yu Gothic/i.test(stack)) throw new Error(`The page font stack has no CJK fallback: ${stack}`);
});

export const expectActiveBottomNav = defineStep('expectActiveBottomNav', async ctx => {
  await ctx.page.setViewportSize({ width: 390, height: 844 });
  await ctx.page.waitForTimeout(400);
  const info = await ctx.page.evaluate(() => {
    const on = document.querySelector('#bnav a.on');
    if (!on) return { ok: false, reason: 'no .bnav a.on' };
    const color = getComputedStyle(on).color;
    const others = [...document.querySelectorAll('#bnav a:not(.on)')].map(a => getComputedStyle(a).color);
    const omni = document.querySelector('#omq')?.getBoundingClientRect();
    return { ok: true, color, others, omniWidth: omni?.width || 0 };
  });
  if (!info.ok) throw new Error(info.reason);
  if (info.others.includes(info.color)) throw new Error(`The active bottom-nav color ${info.color} matches inactive items.`);
  if (info.omniWidth < 180) throw new Error(`Phone search is only ${info.omniWidth}px wide.`);
});

export const closeWithEscape = defineStep('closeWithEscape', async ctx => {
  await ctx.page.keyboard.press('Escape');
  await ctx.page.waitForTimeout(300);
  const visible = await ctx.page.evaluate(() => { const d = document.querySelector('#dlg'); return !!d && d.getBoundingClientRect().height > 0 && getComputedStyle(d).visibility !== 'hidden' && !!d.closest('#ov')?.classList.contains('on'); });
  if (visible) throw new Error('Escape did not close the dialog.');
});

/** Ctrl+K (or /) focuses the global search box. */
export const focusSearchWithKeyboard = defineStep('focusSearchWithKeyboard', async ctx => {
  await ctx.page.locator('body').click({ position: { x: 5, y: 5 } });
  await ctx.page.keyboard.press('/');
  const focused = await ctx.page.evaluate(() => document.activeElement?.id);
  if (focused !== 'omq') throw new Error('The "/" shortcut did not focus the search box.');
});

export const expectContrastTokensMeetAa = defineStep('expectContrastTokensMeetAa', async ctx => {
  const ratio = await ctx.page.evaluate(() => {
    const lin = c => { const s = c / 255; return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; };
    const parse = color => {
      const m = color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
      return m ? [Number(m[1]), Number(m[2]), Number(m[3])] : [0, 0, 0];
    };
    const L = rgb => 0.2126 * lin(rgb[0]) + 0.7152 * lin(rgb[1]) + 0.0722 * lin(rgb[2]);
    const contrast = (a, b) => { const [x, y] = [L(parse(a)), L(parse(b))].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
    const probe = document.createElement('span');
    probe.className = 'dim';
    probe.textContent = 'Gợi ý';
    document.body.appendChild(probe);
    const dim = getComputedStyle(probe).color;
    const bg = getComputedStyle(document.body).backgroundColor;
    probe.remove();
    return contrast(dim, bg);
  });
  if (ratio < 4.5) throw new Error(`Dim text contrast is ${ratio.toFixed(2)}:1, below 4.5:1.`);
});

export const expectCrawlEmptyState = defineStep('expectCrawlEmptyState', async ctx => {
  const text = await ctx.page.$eval('#view', e => e.innerText);
  if (/crawler:source-toggle|php artisan/.test(text)) throw new Error('Crawl still tells the owner to run artisan.');
  if (!/Chưa có nguồn|ổn/.test(text)) throw new Error('Crawl has no empty-state or source table.');
});

export const expectPlainEmptyStates = defineStep('expectPlainEmptyStates', async ctx => {
  const text = await ctx.page.$eval('#view', e => e.innerText);
  if (/php artisan|JOOXCR-\d+|crawler:source-toggle/.test(text)) throw new Error(`The page still uses operator jargon: ${text.slice(0, 200)}`);
});

export const expectSidebarLinksLead = defineStep('expectSidebarLinksLead', async (ctx, labels) => {
  const text = (await ctx.page.$eval('#side', e => e.innerText)).toLowerCase();
  for (const label of labels) if (!text.includes(label.toLowerCase())) throw new Error(`The sidebar has no "${label}" entry.`);
});
