import { defineStep } from '../lib/step.mjs';
import { waitForCount, waitForHash, waitForSettledCount } from './dom.mjs';

const drawerOpen = page => page.evaluate(() => {
  const main = document.querySelector('#view .brmain');
  return !!main && !main.classList.contains('nof');
});

/** The filter button is the desktop control. At phone width the same click closes the overlay. */
export const toggleFilterDrawer = defineStep('toggleFilterDrawer', async ctx => {
  const before = await drawerOpen(ctx.page);
  await ctx.page.locator('#view .brbar [data-act=toggleside]').click();
  await ctx.page.waitForFunction(open => {
    const main = document.querySelector('#view .brmain');
    return !!main && !main.classList.contains('nof') !== open;
  }, before, { timeout: 4000 }).catch(() => { throw new Error('The filter button did not open or close the drawer.'); });
});

export const closeFilterDrawer = defineStep('closeFilterDrawer', async ctx => {
  if (!(await drawerOpen(ctx.page))) throw new Error('The filter drawer is already closed.');
  const close = ctx.page.locator('#view .brclose');
  if (await close.isVisible()) await close.click();
  else await ctx.page.locator('#view .brbar [data-act=toggleside]').click();
  await ctx.page.waitForFunction(() => document.querySelector('#view .brmain')?.classList.contains('nof'), null, { timeout: 4000 })
    .catch(() => { throw new Error('The filter drawer stayed open.'); });
});

export const expectFilterField = defineStep('expectFilterField', async (ctx, name, visible) => {
  const field = ctx.page.locator(`#view [data-filter-form] input[name="${name}"]`);
  const shown = await field.isVisible();
  if (shown !== visible) throw new Error(visible ? `The ${name} filter is hidden.` : `The ${name} filter is still visible.`);
});

/** "Xoá tất cả" drops the query. It fails when there is nothing to clear, so a no-op click cannot pass. */
export const clearFilters = defineStep('clearFilters', async ctx => {
  const hash = await ctx.page.evaluate(() => location.hash);
  if (!hash.includes('?')) throw new Error('There is no filter to clear.');
  const previous = await ctx.page.$eval('#view [data-count]', e => e.textContent.trim()).catch(() => '');
  await ctx.page.locator('#view [data-act=clearfilters]').first().click();
  await ctx.page.waitForFunction(() => !location.hash.includes('?'), null, { timeout: 8000 })
    .catch(() => { throw new Error('Clear filters left the query in the address.'); });
  await waitForSettledCount(ctx.page, previous);
});

export const expectResultCount = defineStep('expectResultCount', async (ctx, exact) => {
  const count = await waitForCount(ctx.page);
  if (count !== exact) throw new Error(`The list count is ${count}, expected ${exact}.`);
});

export const expectResultCountBetween = defineStep('expectResultCountBetween', async (ctx, min, max) => {
  const count = await waitForCount(ctx.page);
  if (count < min || count > max) throw new Error(`The list count is ${count}, expected ${min} to ${max}.`);
  ctx.state.resultCount = count;
});

export const expectHashIncludes = defineStep('expectHashIncludes', async (ctx, needle) => {
  await waitForHash(ctx.page, needle);
});
