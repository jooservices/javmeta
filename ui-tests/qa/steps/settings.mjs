import { defineStep } from '../lib/step.mjs';

export const openTab = defineStep('openSettingsTab', async (ctx, tab) => {
  await ctx.page.click(`[data-tab="${tab}"]`);
  await ctx.page.waitForTimeout(250);
});

export const expectTabShowsControls = defineStep('expectTabShowsControls', async (ctx, tab) => {
  const info = await ctx.page.evaluate(t => {
    const pane = document.querySelector(`[data-pane="${t}"]`);
    return {
      visible: !!pane && !pane.hidden && pane.getBoundingClientRect().height > 0,
      active: document.querySelector('.tab.on')?.dataset.tab,
      sections: pane?.querySelectorAll('[data-group],.panel').length ?? 0,
      overflow: pane ? pane.scrollWidth - pane.clientWidth : 0,
      nativeCheckboxes: [...(pane?.querySelectorAll('input[type=checkbox]') || [])].filter(i => !i.closest('.switch') && getComputedStyle(i).display !== 'none').length,
    };
  }, tab);
  const problems = [];
  if (!info.visible) problems.push('the pane is not visible');
  if (info.active !== tab) problems.push('the tab is not marked active');
  if (!info.sections) problems.push('the pane has no section');
  if (info.overflow > 0) problems.push(`the pane scrolls sideways by ${info.overflow}px`);
  if (info.nativeCheckboxes) problems.push(`${info.nativeCheckboxes} native checkboxes outside a .switch`);
  if (problems.length) throw new Error(`${tab}: ${problems.join('; ')}`);
});

export const tabNames = defineStep('rememberTabNames', async ctx => {
  ctx.state.tabs = await ctx.page.$$eval('[data-tab]', els => els.map(e => e.dataset.tab));
});

export const toggleFirstSwitch = defineStep('toggleFirstSwitch', async ctx => {
  const input = await ctx.page.$('.switch input[data-type="bool"]');
  if (!input) throw new Error('No switch on this tab.');
  const key = await input.getAttribute('data-key');
  const before = await input.isChecked();
  await ctx.page.click(`.switch:has(input[data-key="${key}"])`);
  const text = await ctx.page.$eval(`.switch:has(input[data-key="${key}"]) .txt`, e => e.textContent.trim());
  if (text !== (before ? 'Tắt' : 'Bật')) throw new Error(`The switch label says "${text}" but the switch is ${before ? 'off' : 'on'}.`);
  ctx.state.switchKey = key;
  ctx.state.switchBefore = before;
  ctx.state.switchGroup = await input.evaluate(e => e.closest('[data-group]').dataset.group);
});

export const saveSwitchGroup = defineStep('saveSwitchGroup', async ctx => {
  await ctx.page.click(`[data-save="${ctx.state.switchGroup}"]`);
  await ctx.page.waitForFunction(g => /Đã lưu/.test(document.querySelector(`[data-group="${g}"] [data-msg]`)?.textContent || ''), ctx.state.switchGroup, { timeout: 8000 });
  await ctx.page.waitForTimeout(1800);
});

export const expectSwitchPersisted = defineStep('expectSwitchPersisted', async ctx => {
  await ctx.page.goto(ctx.base + '/app#/settings');
  await ctx.page.waitForSelector('[data-tab]');
  await ctx.page.click('[data-tab="crawl"]');
  const now = await (await ctx.page.$(`input[data-key="${ctx.state.switchKey}"]`)).isChecked();
  if (now === ctx.state.switchBefore) throw new Error('The saved value did not survive a reload.');
});

export const restoreSwitch = defineStep('restoreSwitch', async ctx => {
  await ctx.page.click(`.switch:has(input[data-key="${ctx.state.switchKey}"])`);
  await ctx.page.click(`[data-save="${ctx.state.switchGroup}"]`);
  await ctx.page.waitForTimeout(1200);
});
