import { defineStep } from '../lib/step.mjs';

/** Hover must change a computed style. A rule that misses the markup leaves the value unchanged and this step fails. */
export const expectHoverChanges = defineStep('expectHoverChanges', async (ctx, selector, property) => {
  const el = ctx.page.locator(selector).first();
  await el.waitFor({ state: 'visible', timeout: 8000 });
  await ctx.page.mouse.move(1, 1);
  const before = await el.evaluate((node, prop) => getComputedStyle(node)[prop], property);
  const box = await el.boundingBox();
  if (!box || box.width < 2 || box.height < 2) throw new Error(`${selector} has no box to hover.`);
  await ctx.page.mouse.move(box.x + Math.min(6, box.width / 2), box.y + Math.min(6, box.height / 2));
  const deadline = Date.now() + 1500;
  let after = before;
  while (Date.now() < deadline) {
    after = await el.evaluate((node, prop) => getComputedStyle(node)[prop], property);
    if (after !== before) return;
    await new Promise(resolve => setTimeout(resolve, 40));
  }
  throw new Error(`Hovering ${selector} left ${property} unchanged (${before}).`);
});

export const goBack = defineStep('goBack', async ctx => {
  const before = ctx.page.url();
  const beforeText = await ctx.page.$eval('#view', e => e.innerText.slice(0, 120));
  await ctx.page.goBack();
  await ctx.page.waitForFunction(({ url, text }) => {
    const view = document.querySelector('#view');
    if (!view || location.href === url) return false;
    if (view.querySelector('.loader')) return false;
    const now = view.innerText.slice(0, 120);
    return now.trim().length > 20 && now !== text;
  }, { url: before, text: beforeText }, { timeout: 12000 })
    .catch(() => { throw new Error('Back did not show the previous view.'); });
});
