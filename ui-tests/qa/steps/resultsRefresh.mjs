import { defineStep } from '../lib/step.mjs';
import { typeInto, waitForCount, waitForList, waitForSettledCount } from './dom.mjs';

// A filter change refreshes the results region only: same view, same panel, address updated with history, results
// dimmed while loading, the last good list kept when a request fails. These steps prove each of those separately.
const LIST = '**/ui-api/movies?*';

/** Ticks the first option of a facet without waiting for anything, so a held or failing request can be observed. */
async function tickFirst(page, group) {
  const box = page.locator(`#view [data-facet-list="${group}"] [data-fl-rest] input[data-fl-value]`).first();
  await box.waitFor({ state: 'attached', timeout: 12000 });
  await box.check();
}
const NODES = ['#view', '#view .search-browser', '#view [data-filter-form]', '#view .brbar', '#view [data-results]'];

export const markBrowserNodes = defineStep('markBrowserNodes', async ctx => {
  const missing = await ctx.page.evaluate(selectors => {
    const lost = [];
    selectors.forEach((selector, index) => {
      const node = document.querySelector(selector);
      if (!node) lost.push(selector); else node.dataset.qaMark = `n${index}`;
    });
    document.querySelectorAll('#view [data-facet-list]').forEach(node => { node.dataset.qaMark = `facet-${node.dataset.facetList}`; });
    window.__qaHistory = history.length;
    return lost;
  }, NODES);
  if (missing.length) throw new Error(`Cannot mark: ${missing.join(', ')} not found.`);
});

export const expectBrowserNodesSurvived = defineStep('expectBrowserNodesSurvived', async ctx => {
  const lost = await ctx.page.evaluate(selectors => {
    const gone = selectors.filter((selector, index) => document.querySelector(selector)?.dataset.qaMark !== `n${index}`);
    document.querySelectorAll('[data-qa-mark^="facet-"]').forEach(node => { if (!node.isConnected) gone.push(node.dataset.qaMark); });
    return gone;
  }, NODES);
  if (lost.length) throw new Error(`A filter change replaced: ${lost.join(', ')}.`);
});

/** Every change pushes one history entry (never a full render) and Back restores it without rebuilding the view. */
export const expectHistoryGrewBy = defineStep('expectHistoryGrewBy', async (ctx, entries) => {
  const grew = await ctx.page.evaluate(() => history.length - (window.__qaHistory ?? history.length));
  if (grew !== entries) throw new Error(`History grew by ${grew}, expected ${entries}.`);
});

export const goBackWithoutRemount = defineStep('goBackWithoutRemount', async ctx => {
  const previous = await ctx.page.$eval('#view [data-count]', element => element.textContent.trim()).catch(() => '');
  await ctx.page.goBack();
  await waitForSettledCount(ctx.page, previous);
  const loader = await ctx.page.locator('#view > .loader').count();
  if (loader) throw new Error('Back showed the full-page loader.');
  await expectNodes(ctx.page);
});

async function expectNodes(page) {
  const kept = await page.evaluate(() => document.querySelector('#view .search-browser')?.dataset.qaMark === 'n1');
  if (!kept) throw new Error('Back rebuilt the browser instead of refreshing its results.');
}

/** Ticks the first option of a facet, holds the list response, checks the dimmed / aria-busy state, then lets it through. */
export const expectResultsDimWhileLoading = defineStep('expectResultsDimWhileLoading', async (ctx, group) => {
  let release;
  const gate = new Promise(resolve => { release = resolve; });
  await ctx.page.route(LIST, async route => { await gate; await route.continue(); });
  try {
    await tickFirst(ctx.page, group);
    await ctx.page.waitForFunction(() => document.querySelector('#view [data-results]')?.getAttribute('aria-busy') === 'true', null, { timeout: 6000 })
      .catch(() => { throw new Error('The results region was not marked aria-busy while loading.'); });
    const dimmed = await ctx.page.locator('#view [data-results].is-refreshing').count();
    if (!dimmed) throw new Error('The results region was not dimmed while loading.');
    const stale = await ctx.page.locator('#view [data-list] a[href*="#/movie/"], #view [data-list] a[href*="#/performer/"]').count();
    if (stale < 1) throw new Error('The previous results were removed while the new ones loaded.');
  } finally {
    release();
    await ctx.page.unroute(LIST);
  }
  await ctx.page.waitForFunction(() => document.querySelector('#view [data-results]')?.getAttribute('aria-busy') === 'false', null, { timeout: 12000 })
    .catch(() => { throw new Error('The results region stayed aria-busy after the response.'); });
});

/** The next list request fails once: the last good results stay, the error bar offers a retry, and retry recovers. */
export const expectFailedRefreshKeepsResults = defineStep('expectFailedRefreshKeepsResults', async (ctx, group) => {
  const before = await waitForCount(ctx.page);
  const rows = () => ctx.page.locator('#view [data-list] a[href*="#/movie/"]').count();
  const shown = await rows();
  let failed = 0;
  await ctx.page.route(LIST, async route => {
    // Only the first list request fails; later retries and facet calls that do not match keep going.
    if (failed === 0) {
      failed = 1;
      await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'qa boom', detail: 'qa boom' }) });
      return;
    }
    await route.continue();
  });
  try {
    await tickFirst(ctx.page, group);
    const bar = ctx.page.locator('#view [data-results-error]');
    await bar.waitFor({ state: 'visible', timeout: 8000 }).catch(() => { throw new Error('A failed refresh showed no error bar.'); });
    await bar.locator('[data-results-retry]').waitFor({ state: 'attached', timeout: 4000 })
      .catch(() => { throw new Error('The error bar has no retry button.'); });
    if ((await rows()) !== shown) throw new Error('A failed refresh removed the last good results.');
    if ((await waitForCount(ctx.page)) !== before) throw new Error('A failed refresh changed the count.');
    const pending = waitForList(ctx.page, '/ui-api/movies?', []);
    await bar.locator('[data-results-retry]').click();
    await pending;
    await ctx.page.waitForFunction(() => {
      const el = document.querySelector('#view [data-results-error]');
      return !!el && (el.hidden || el.hasAttribute('hidden') || !el.querySelector('[data-results-retry]'));
    }, null, { timeout: 8000 }).catch(() => { throw new Error('Retry left the error bar showing.'); });
  } finally {
    await ctx.page.unroute(LIST);
  }
});

/** Fast typing makes one list request, carrying the final text (250 ms debounce). */
export const expectTypingIsDebounced = defineStep('expectTypingIsDebounced', async (ctx, field, term) => {
  const seen = [];
  const onRequest = request => {
    const url = decodeURIComponent(request.url().replace(/\+/g, ' '));
    if (request.method() === 'GET' && url.includes('/ui-api/movies?') && !url.includes('/facets')) seen.push(url);
  };
  ctx.page.on('request', onRequest);
  try {
    const pending = waitForList(ctx.page, '/ui-api/movies?', [`${field}=${term}`]);
    await typeInto(ctx.page.locator(`#view [data-filter-form] input[name="${field}"]`), term);
    await pending;
    await ctx.page.waitForTimeout(600);
  } finally {
    ctx.page.off('request', onRequest);
  }
  const partial = seen.filter(url => !url.includes(`${field}=${term}`) && new RegExp(`${field}=[^&]+`).test(url));
  if (partial.length) throw new Error(`Typing "${term}" requested partial text: ${partial[0]}`);
  const finals = seen.filter(url => url.includes(`${field}=${term}`));
  if (finals.length !== 1) throw new Error(`Typing "${term}" made ${finals.length} list requests, expected 1.`);
});
