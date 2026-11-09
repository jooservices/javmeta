import { defineStep } from '../lib/step.mjs';
import { parseCount, typeInto, waitForList, waitForSettledCount } from './dom.mjs';

// Reusable steps for the one FacetList every filter uses (movies and performers). A scenario names the facet group
// (genres, makers, ...) and the steps do the same thing for each of them, so a new facet needs no new step.
export const MOVIE_FACETS = [['genres', 'genre'], ['makers', 'maker'], ['series', 'series'], ['labels', 'label'], ['years', 'year'], ['sources', 'source']];
export const PERFORMER_FACETS = [['cups', 'cup'], ['blood_types', 'blood_type'], ['locations', 'location'], ['status', 'status'], ['sources', 'source']];
const PAGE_SIZE = 20;

const panel = (page, group) => page.locator(`#view [data-facet-list="${group}"]`);
const listUrl = kind => (kind === 'performers' ? '/ui-api/performers?' : '/ui-api/movies?');
const valuesFragment = kind => `/ui-api/${kind}/facets/values?`;

/** The next facet-values request for this group whose URL contains every needle. */
const waitForValues = (page, kind, group, needles = []) => page.waitForResponse(response => {
  const url = decodeURIComponent(response.url().replace(/\+/g, ' '));
  return response.request().method() === 'GET' && url.includes(valuesFragment(kind)) && url.includes(`facet=${group}`) && needles.every(needle => url.includes(needle));
}, { timeout: 12000 });

/** Sections are collapsed <details>; the list only loads once its section is open. */
async function openPanel(page, group) {
  const host = panel(page, group);
  await host.waitFor({ state: 'attached', timeout: 12000 });
  const details = host.locator('xpath=ancestor::details[1]');
  if (await details.count() && !(await details.evaluate(element => element.open))) await details.locator('> summary').click();
  await host.waitFor({ state: 'visible', timeout: 8000 });
  return host;
}

async function settled(host) {
  await host.locator('[data-fl-list][aria-busy="false"]').waitFor({ state: 'attached', timeout: 12000 });
  await host.page().waitForFunction(element => /\d/.test(element.querySelector('[data-fl-range]')?.textContent || ''), await host.elementHandle(), { timeout: 12000 })
    .catch(() => { throw new Error('The facet list never showed its range.'); });
}

/** "1–20 / 134" → { from, to, total }; the footer reads the same for every facet. */
export async function readRange(host) {
  const text = (await host.locator('[data-fl-range]').innerText()).trim();
  const match = text.match(/^([\d.,]+)\s*[–-]\s*([\d.,]+)\s*\/\s*([\d.,]+)$/);
  if (!match) throw new Error(`The pager reads "${text}", expected "from–to / total".`);
  return { from: parseCount(match[1]), to: parseCount(match[2]), total: parseCount(match[3]), text };
}

const optionTexts = host => host.locator('[data-fl-rest] .fl-opt .t').allInnerTexts();

export const openFacet = defineStep('openFacet', async (ctx, kind, group) => {
  const host = await openPanel(ctx.page, group);
  await settled(host);
});

/** Typing in the facet's own search narrows its options and nothing else; the results region is not requested. */
export const searchFacet = defineStep('searchFacet', async (ctx, kind, group, term) => {
  const host = await openPanel(ctx.page, group);
  await settled(host);
  let listRequests = 0;
  const onRequest = request => { if (request.method() === 'GET' && request.url().includes(listUrl(kind)) && !request.url().includes('/facets')) listRequests++; };
  ctx.page.on('request', onRequest);
  try {
    const pending = waitForValues(ctx.page, kind, group, [`q=${term}`]);
    await typeInto(host.locator('[data-fl-search]'), term);
    await pending.catch(() => { throw new Error(`Searching ${group} for "${term}" did not request values.`); });
    await settled(host);
    const shown = await optionTexts(host);
    const wrong = shown.filter(text => !text.toLowerCase().includes(term.toLowerCase()));
    if (wrong.length) throw new Error(`${group} search "${term}" still lists: ${wrong.slice(0, 3).join(', ')}.`);
    if (listRequests) throw new Error(`Searching inside ${group} refreshed the results (${listRequests} list requests).`);
  } finally {
    ctx.page.off('request', onRequest);
  }
});

export const clearFacetSearch = defineStep('clearFacetSearch', async (ctx, kind, group) => {
  const host = panel(ctx.page, group);
  // Clearing the box drops `q` from the request (it is not sent as q=); wait for any values call without a non-empty q=.
  const pending = ctx.page.waitForResponse(response => {
    const url = decodeURIComponent(response.url().replace(/\+/g, ' '));
    return response.request().method() === 'GET' && url.includes(valuesFragment(kind)) && url.includes(`facet=${group}`) && !/[?&]q=[^&]+/.test(url);
  }, { timeout: 12000 }).catch(() => null);
  await typeInto(host.locator('[data-fl-search]'), '');
  await pending;
  await settled(host);
});

/** The pager always reads "from–to / total", has at most one page of rows, and next/prev follow the total. */
export const expectFacetPager = defineStep('expectFacetPager', async (ctx, kind, group) => {
  const host = await openPanel(ctx.page, group);
  await settled(host);
  const { from, to, total } = await readRange(host);
  const rows = (await host.locator('[data-fl-rest] .fl-opt').count()) + (await host.locator('[data-fl-pin] .fl-opt').count());
  if (total === 0) {
    if (from !== 0 || to !== 0) throw new Error(`${group} has no values but reads ${from}–${to}.`);
    return;
  }
  if (from !== 1) throw new Error(`${group} page 1 starts at ${from}.`);
  if (to !== Math.min(PAGE_SIZE, total)) throw new Error(`${group} page 1 ends at ${to} of ${total}, expected ${Math.min(PAGE_SIZE, total)}.`);
  if (rows < 1 || rows > PAGE_SIZE + (await host.locator('[data-fl-pin] .fl-opt').count())) throw new Error(`${group} shows ${rows} rows for a page of ${PAGE_SIZE}.`);
  const nextDisabled = await host.locator('[data-fl-next]').isDisabled();
  if (nextDisabled !== (total <= PAGE_SIZE)) throw new Error(`${group} next button is ${nextDisabled ? 'off' : 'on'} with ${total} values.`);
  if (!(await host.locator('[data-fl-prev]').isDisabled())) throw new Error(`${group} previous button is on at page 1.`);
});

/** When the facet has more than one page, next then previous move the range by one page and back. */
export const pageThroughFacet = defineStep('pageThroughFacet', async (ctx, kind, group) => {
  const host = await openPanel(ctx.page, group);
  await settled(host);
  const first = await readRange(host);
  if (first.total <= PAGE_SIZE) {
    if (!(await host.locator('[data-fl-next]').isDisabled())) throw new Error(`${group} has one page but its next button is on.`);
    return;
  }
  const before = await optionTexts(host);
  const pending = waitForValues(ctx.page, kind, group, ['page=2']);
  await host.locator('[data-fl-next]').click();
  await pending.catch(() => { throw new Error(`${group} next did not request page 2.`); });
  await settled(host);
  const second = await readRange(host);
  if (second.from !== PAGE_SIZE + 1) throw new Error(`${group} page 2 starts at ${second.from}, expected ${PAGE_SIZE + 1}.`);
  if (second.to !== Math.min(PAGE_SIZE * 2, first.total)) throw new Error(`${group} page 2 ends at ${second.to}.`);
  const after = await optionTexts(host);
  if (after.join('|') === before.join('|')) throw new Error(`${group} page 2 shows the same options as page 1.`);
  const back = waitForValues(ctx.page, kind, group, ['page=1']);
  await host.locator('[data-fl-prev]').click();
  await back.catch(() => { throw new Error(`${group} previous did not request page 1.`); });
  await settled(host);
  const again = await readRange(host);
  if (again.text !== first.text) throw new Error(`${group} came back to "${again.text}", expected "${first.text}".`);
});

export const sortFacet = defineStep('sortFacet', async (ctx, kind, group, order) => {
  const host = await openPanel(ctx.page, group);
  const pending = waitForValues(ctx.page, kind, group, [`sort=${order}`]);
  await host.locator('[data-fl-sort]').selectOption(order);
  await pending.catch(() => { throw new Error(`Sorting ${group} by ${order} did not request values.`); });
  await settled(host);
  if (order === 'name') {
    const names = await optionTexts(host);
    const sorted = [...names].sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base', numeric: true }));
    if (names.length > 1 && names.join('|') !== sorted.join('|') && names.join('|') !== [...sorted].reverse().join('|')) throw new Error(`${group} is not in name order.`);
  }
});

/** The min-count switch is on by default; turning it off can only grow the total, and hidden values are reported. */
export const toggleFacetMinCount = defineStep('toggleFacetMinCount', async (ctx, kind, group) => {
  const host = await openPanel(ctx.page, group);
  await settled(host);
  const before = (await readRange(host)).total;
  const box = host.locator('[data-fl-min]');
  const wasOn = await box.isChecked();
  const pending = waitForValues(ctx.page, kind, group, [`min_count=${wasOn ? 0 : 3}`]);
  await box.setChecked(!wasOn);
  await pending.catch(() => { throw new Error(`Toggling min-count on ${group} did not request values.`); });
  await settled(host);
  const after = (await readRange(host)).total;
  if (wasOn && after < before) throw new Error(`${group} lost values (${before} → ${after}) when low counts were shown.`);
  if (!wasOn && after > before) throw new Error(`${group} gained values (${before} → ${after}) when low counts were hidden.`);
  await box.setChecked(wasOn);
  await settled(host);
});

/** Ticks an option (the first one when no value is given): it moves to the pinned block, the chip appears, only the
 *  results region reloads, and the facet panel keeps the very same DOM node. */
export const selectFacetOption = defineStep('selectFacetOption', async (ctx, kind, group, key, value = null) => {
  const host = await openPanel(ctx.page, group);
  await settled(host);
  const box = value === null
    ? host.locator('[data-fl-rest] input[data-fl-value]').first()
    : host.locator(`input[data-fl-value][value="${value}"]`).first();
  await box.waitFor({ state: 'attached', timeout: 12000 });
  const chosen = await box.getAttribute('value');
  if (await box.isChecked()) throw new Error(`${group} option ${chosen} is already selected.`);
  await host.evaluate(element => { element.dataset.qaNode = 'kept'; });
  await ctx.page.evaluate(() => { document.querySelector('#view').dataset.qaView = 'kept'; });
  const previous = await ctx.page.$eval('#view [data-count]', element => element.textContent.trim()).catch(() => '');
  // Year is stored as released_from = released_to, not as year=.
  // A tick sends genre, maker, series and label in double quotes (exact match).
  const sent = ['genre', 'maker', 'series', 'label'].includes(key) ? `"${chosen}"` : `${key}=${chosen}`;
  const needles = key === 'year' ? [`released_from=${chosen}`, `released_to=${chosen}`] : [sent];
  const hashNeedle = key === 'year' ? `released_from=${chosen}` : sent;
  const pending = waitForList(ctx.page, listUrl(kind), needles);
  await box.check();
  await pending.catch(() => { throw new Error(`Selecting ${group} ${chosen} did not request the list.`); });
  await ctx.page.waitForFunction(needle => decodeURIComponent(location.hash.replace(/\+/g, ' ')).includes(needle), hashNeedle, { timeout: 8000 })
    .catch(() => { throw new Error(`The address never included ${hashNeedle}.`); });
  await waitForSettledCount(ctx.page, previous).catch(() => null);
  const kept = await panel(ctx.page, group).evaluate(element => element.dataset.qaNode === 'kept');
  if (!kept) throw new Error(`The ${group} panel was rebuilt by a filter change.`);
  const viewKept = await ctx.page.evaluate(() => document.querySelector('#view')?.dataset.qaView === 'kept');
  if (!viewKept) throw new Error('The whole view was re-rendered by a filter change.');
  if (!(await host.locator(`[data-fl-pin] input[value="${chosen}"]`).count())) throw new Error(`${group} ${chosen} is not pinned to the top after selecting it.`);
  const chips = await ctx.page.locator('#view [data-active]').innerText();
  if (!chips.toLowerCase().includes(String(chosen).toLowerCase())) throw new Error(`The active filter bar does not show ${chosen}.`);
  ctx.state.selectedFacets = { ...(ctx.state.selectedFacets || {}), [group]: chosen };
});

export const expectFacetSelected = defineStep('expectFacetSelected', async (ctx, kind, group) => {
  const chosen = ctx.state.selectedFacets?.[group];
  if (!chosen) throw new Error(`No ${group} option was selected earlier.`);
  const host = await openPanel(ctx.page, group);
  const box = host.locator(`[data-fl-pin] input[value="${chosen}"]`);
  await box.waitFor({ state: 'attached', timeout: 8000 }).catch(() => { throw new Error(`${group} ${chosen} is no longer pinned.`); });
  if (!(await box.isChecked())) throw new Error(`${group} ${chosen} is pinned but not ticked.`);
});

export const unselectFacetOption = defineStep('unselectFacetOption', async (ctx, kind, group, key) => {
  const chosen = ctx.state.selectedFacets?.[group];
  if (!chosen) throw new Error(`No ${group} option was selected earlier.`);
  const host = panel(ctx.page, group);
  const pending = waitForList(ctx.page, listUrl(kind), []);
  await host.locator(`[data-fl-pin] input[value="${chosen}"]`).uncheck();
  await pending.catch(() => { throw new Error(`Clearing ${group} ${chosen} did not request the list.`); });
  const hashNeedle = key === 'year' ? `released_from=${chosen}` : `${key}=${chosen}`;
  await ctx.page.waitForFunction(needle => !decodeURIComponent(location.hash.replace(/\+/g, ' ')).includes(needle), hashNeedle, { timeout: 8000 })
    .catch(() => { throw new Error(`The address still includes ${hashNeedle}.`); });
  delete ctx.state.selectedFacets[group];
});

/** The rule that explains OR inside a group and AND across groups is visible in the panel. */
export const expectFacetRuleNote = defineStep('expectFacetRuleNote', async ctx => {
  const note = ctx.page.locator('#view [data-facet-rule]');
  if (!(await note.count())) throw new Error('The OR-within / AND-across note is missing.');
});

/** Genre only: the "all of" switch is there and puts genre_all=1 in the address. */
export const toggleGenreAllOf = defineStep('toggleGenreAllOf', async (ctx, on) => {
  const host = await openPanel(ctx.page, 'genres');
  const box = host.locator('[data-fl-all]');
  await box.waitFor({ state: 'attached', timeout: 8000 });
  await box.setChecked(Boolean(on));
  await ctx.page.waitForFunction(want => location.hash.includes('genre_all=1') === want, Boolean(on), { timeout: 8000 })
    .catch(() => { throw new Error(`genre_all is ${on ? 'missing from' : 'still in'} the address.`); });
});
