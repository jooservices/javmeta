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
  if (!/phím|⌘K|Esc/i.test(text)) throw new Error('The help dialog does not list the keyboard shortcuts.');
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

export const expectSidebarLinksLead = defineStep('expectSidebarLinksLead', async (ctx, labels) => {
  const text = (await ctx.page.$eval('#side', e => e.innerText)).toLowerCase();
  for (const label of labels) if (!text.includes(label.toLowerCase())) throw new Error(`The sidebar has no "${label}" entry.`);
});
