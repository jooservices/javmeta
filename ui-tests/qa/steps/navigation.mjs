import { defineStep } from '../lib/step.mjs';

/** Opens a single-page view by its hash route and waits for it to settle. */
export const openView = defineStep('openView', async (ctx, route) => {
  await ctx.page.goto(ctx.base + '/app' + route, { waitUntil: 'load' });
  await ctx.page.evaluate(r => { location.hash = r; }, route);
  await ctx.page.waitForTimeout(1500);
  ctx.issues.length = 0;
});

export const openPath = defineStep('openPath', async (ctx, path) => {
  await ctx.page.goto(ctx.base + path, { waitUntil: 'load' });
  await ctx.page.waitForTimeout(800);
  ctx.issues.length = 0;
});

/** The view has content, shows no error panel or not-found page, does not scroll sideways and raised no console error or failed request. */
export const expectViewHealthy = defineStep('expectViewHealthy', async ctx => {
  const info = await ctx.page.evaluate(() => {
    const view = document.querySelector('#view') || document.querySelector('main');
    return {
      text: (view?.innerText || '').trim().length,
      overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      errors: [...document.querySelectorAll('#view .err, #view [role=alert]')].map(e => e.innerText.trim().slice(0, 120)).filter(Boolean),
      notFound: /không tìm thấy trang|404/i.test(view?.innerText || '') && (view?.innerText || '').trim().length < 400,
    };
  });
  const problems = [];
  if (info.text < 20) problems.push('the view is empty');
  if (info.overflow > 0) problems.push(`it scrolls sideways by ${info.overflow}px`);
  if (info.errors.length) problems.push(`it shows an error: ${info.errors.join(' / ')}`);
  if (info.notFound) problems.push('it shows a not-found page');
  if (ctx.issues.length) problems.push([...new Set(ctx.issues)].join(' | '));
  if (problems.length) throw new Error(problems.join('; '));
});

export const resizeTo = defineStep('resizeTo', async (ctx, width, height = 900) => {
  await ctx.page.setViewportSize({ width, height });
  await ctx.page.waitForTimeout(300);
});

export const expectNoConsoleErrors = defineStep('expectNoConsoleErrors', async ctx => {
  if (ctx.issues.length) throw new Error([...new Set(ctx.issues)].join(' | '));
});
