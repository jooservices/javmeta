import { defineStep } from '../lib/step.mjs';
import { escapeRegExp, parseCount, typeInto, viewText, waitForCount, waitForHash, waitForList } from './dom.mjs';

const catalogLink = (page, name) => page.locator('a.lnk').filter({ hasText: new RegExp(`^${escapeRegExp(name)}$`) });

async function catalogSettled(page) {
  await page.waitForFunction(() => {
    if (document.querySelector('#view .loader')) return false;
    return /\d/.test(document.querySelector('#view [data-count]')?.textContent || '');
  }, null, { timeout: 12000 }).catch(() => { throw new Error('The catalog list did not finish loading.'); });
}

/** Types into the catalog search. An empty query clears it. The count is asserted by the next step, so a no-op filter can still fail. */
export const filterCatalog = defineStep('filterCatalog', async (ctx, query) => {
  const box = ctx.page.locator('#view #cf input[name=q]');
  await box.waitFor({ state: 'visible', timeout: 8000 });
  const pending = waitForList(ctx.page, '/ui-api/meta/catalog/', query ? [`q=${query}`] : []);
  await typeInto(box, query);
  if (query) await waitForHash(ctx.page, `q=${query}`);
  else {
    await ctx.page.waitForFunction(() => !decodeURIComponent(location.hash.replace(/\+/g, ' ')).includes('q='), null, { timeout: 8000 })
      .catch(() => { throw new Error('Clearing the catalog search left q in the address.'); });
  }
  await pending;
  await catalogSettled(ctx.page);
});

/** Moves the sort control with the keyboard. movie_count is the first option and name is the second. */
export const sortCatalogWithKeyboard = defineStep('sortCatalogWithKeyboard', async (ctx, sort) => {
  const select = ctx.page.locator('#view #csort');
  const current = await select.inputValue();
  if (current === sort) throw new Error(`The catalog is already sorted by ${sort}.`);
  const before = (await ctx.page.locator('#view [data-list] a.lnk').first().innerText()).trim();
  const pending = waitForList(ctx.page, '/ui-api/meta/catalog/', [`sort=${sort}`]);
  await select.focus();
  await ctx.page.keyboard.press(sort === 'name' ? 'ArrowDown' : 'ArrowUp');
  const now = await select.inputValue();
  if (now !== sort) throw new Error(`The keyboard left the sort on "${now}", expected "${sort}".`);
  await waitForHash(ctx.page, `sort=${sort}`);
  await pending;
  await ctx.page.waitForFunction(prev => {
    if (document.querySelector('#view .loader')) return false;
    const text = document.querySelector('#view [data-list] a.lnk')?.textContent?.trim() || '';
    return text !== '' && text !== prev;
  }, before, { timeout: 12000 }).catch(() => { throw new Error('Sorting the catalog did not change the first entry.'); });
});

export const expectFirstCatalogEntry = defineStep('expectFirstCatalogEntry', async (ctx, name) => {
  await catalogSettled(ctx.page);
  const text = (await ctx.page.locator('#view [data-list] a.lnk').first().innerText()).trim();
  if (text !== name) throw new Error(`The first catalog entry is "${text}", expected "${name}".`);
});

/** Remembers the movie count printed on the row so the facet page can be checked against it. */
export const expectCatalogRow = defineStep('expectCatalogRow', async (ctx, name) => {
  const row = ctx.page.locator('#view .row-i').filter({ has: catalogLink(ctx.page, name) }).first();
  await row.waitFor({ state: 'visible', timeout: 8000 });
  const count = parseCount(await row.locator('.mono').innerText());
  if (count < 1) throw new Error(`${name} shows no movies.`);
  ctx.state.catalogName = name;
  ctx.state.catalogCount = count;
});

export const openCatalogEntry = defineStep('openCatalogEntry', async (ctx, name) => {
  await ctx.page.locator('#view .row-i a.lnk').filter({ hasText: new RegExp(`^${escapeRegExp(name)}$`) }).first().click();
  await waitForHash(ctx.page, `/facet/`);
  await waitForHash(ctx.page, name);
  // The catalog row stays on screen until the facet view replaces #cf, so a count seen before that is the old one.
  await ctx.page.waitForFunction(entry => {
    const view = document.querySelector('#view');
    if (!view || view.querySelector('#cf') || view.querySelector('.loader')) return false;
    const count = view.querySelector('[data-count]')?.textContent?.trim() || '';
    return /\d/.test(count) && view.innerText.includes(entry);
  }, name, { timeout: 12000 }).catch(() => { throw new Error(`Opening ${name} did not show its movie list.`); });
});

export const expectFacetCountMatchesCatalog = defineStep('expectFacetCountMatchesCatalog', async ctx => {
  const count = await waitForCount(ctx.page);
  if (count !== ctx.state.catalogCount) throw new Error(`The movie list has ${count}, the catalog row said ${ctx.state.catalogCount}.`);
  const body = await viewText(ctx.page);
  if (!body.includes(ctx.state.catalogName)) throw new Error(`The movie list does not show ${ctx.state.catalogName}.`);
});

export const openFirstMovie = defineStep('openFirstMovie', async ctx => {
  const link = ctx.page.locator('#view a.lnk[href*="#/movie/"]').first();
  await link.waitFor({ state: 'visible', timeout: 8000 });
  const href = await link.getAttribute('href');
  const code = decodeURIComponent((href || '').split('#/movie/')[1] || '').split('?')[0];
  if (!code) throw new Error('The list has no movie link.');
  ctx.state.movieCode = code;
  await link.click();
  await ctx.page.waitForFunction(c => {
    const view = document.querySelector('#view');
    if (!view || view.querySelector('.loader') || !location.hash.includes(c)) return false;
    const favourite = [...view.querySelectorAll('button')].some(button => /yêu thích/i.test(button.innerText));
    return favourite && view.innerText.includes(c);
  }, code, { timeout: 12000 }).catch(() => { throw new Error(`Opening ${code} did not show its detail.`); });
});

export const expectDetailContains = defineStep('expectDetailContains', async (ctx, text) => {
  await ctx.page.waitForFunction(needle => {
    const view = document.querySelector('#view');
    return !!view && !view.querySelector('.loader') && view.innerText.includes(needle);
  }, text, { timeout: 8000 }).catch(() => { throw new Error(`The detail page does not show "${text}".`); });
});

export const expectCatalogEmpty = defineStep('expectCatalogEmpty', async ctx => {
  await catalogSettled(ctx.page);
  const count = parseCount(await ctx.page.$eval('#view [data-count]', e => e.textContent));
  if (count !== 0) throw new Error(`The catalog still lists ${count} entries.`);
  const body = await viewText(ctx.page);
  if (!body.includes('Không có mục nào')) throw new Error('The empty catalog does not say there are no entries.');
});

export const expectUnknownCatalog = defineStep('expectUnknownCatalog', async ctx => {
  await ctx.page.evaluate(() => { location.hash = '#/catalog/nope'; });
  await ctx.page.waitForFunction(() => (document.querySelector('#view')?.innerText || '').includes('Danh mục không tồn tại'), null, { timeout: 8000 })
    .catch(() => { throw new Error('An unknown catalog does not say it is missing.'); });
});

const calendarControl = (page, which) => {
  if (which === 'today') return page.locator('#view a[href="#/calendar"]', { hasText: 'Hôm nay' });
  return page.locator('#view a[href*="#/calendar?month="]', { hasText: which === 'next' ? '›' : '‹' });
};

async function calendarMonth(page) {
  return page.$eval('#view .sub', e => (e.textContent || '').trim());
}

/** Clicks the month control and waits until the heading month changes. */
export const shiftCalendar = defineStep('shiftCalendar', async (ctx, which) => {
  const before = await calendarMonth(ctx.page);
  const pending = waitForList(ctx.page, '/ui-api/movies/calendar');
  await calendarControl(ctx.page, which).click();
  await ctx.page.waitForFunction(prev => {
    const sub = document.querySelector('#view .sub')?.textContent?.trim() || '';
    return sub !== prev && /\d+\s*phim/.test(sub);
  }, before, { timeout: 12000 }).catch(() => { throw new Error(`The calendar stayed on "${before}" after ${which}.`); });
  await pending.catch(() => { throw new Error('The calendar did not ask for the other month.'); });
});

/** Focuses the month control and presses Enter. */
export const shiftCalendarWithKeyboard = defineStep('shiftCalendarWithKeyboard', async (ctx, which) => {
  const before = await calendarMonth(ctx.page);
  const link = calendarControl(ctx.page, which);
  await link.focus();
  const pending = waitForList(ctx.page, '/ui-api/movies/calendar');
  await ctx.page.keyboard.press('Enter');
  await ctx.page.waitForFunction(prev => {
    const sub = document.querySelector('#view .sub')?.textContent?.trim() || '';
    return sub !== prev && /\d+\s*phim/.test(sub);
  }, before, { timeout: 12000 }).catch(() => { throw new Error(`Enter on ${which} left the calendar on "${before}".`); });
  await pending.catch(() => { throw new Error('The keyboard month change did not load a month.'); });
});

export const expectCalendarHeading = defineStep('expectCalendarHeading', async (ctx, monthLabel, movies) => {
  const sub = await calendarMonth(ctx.page);
  const label = sub.split('·')[0].trim();
  if (label !== monthLabel) throw new Error(`The calendar shows "${sub}", expected ${monthLabel}.`);
  const count = Number((sub.match(/(\d+)\s*phim/) || [])[1]);
  if (!Number.isFinite(count)) throw new Error(`The calendar heading has no movie total: "${sub}".`);
  if (count !== movies) throw new Error(`The calendar lists ${count} movies, expected ${movies}.`);
});

/** The count chip is the control that opens that day's movies. */
export const openCalendarDay = defineStep('openCalendarDay', async (ctx, date) => {
  const link = ctx.page.locator(`#view a[href*="released_from=${date}"]`).first();
  await link.waitFor({ state: 'visible', timeout: 8000 });
  const pending = ctx.page.waitForResponse(response => response.request().method() === 'GET' && response.url().includes('/ui-api/movies?'), { timeout: 12000 });
  await link.click();
  await waitForHash(ctx.page, `released_from=${date}`);
  const response = await pending.catch(() => { throw new Error(`The day ${date} did not request a movie list.`); });
  ctx.state.dayRequest = decodeURIComponent(response.url().replace(/\+/g, ' '));
  await ctx.page.waitForFunction(() => !document.querySelector('#view .loader') && /\d/.test(document.querySelector('#view [data-count]')?.textContent || ''), null, { timeout: 12000 })
    .catch(() => { throw new Error(`The day ${date} did not open a movie list.`); });
});

/** The day link must ask for that date. A request that keeps only the year fails here. */
export const expectDayRequestKeptTheDate = defineStep('expectDayRequestKeptTheDate', async (ctx, date) => {
  if (!String(ctx.state.dayRequest || '').includes(date)) {
    throw new Error(`The day link asked for "${ctx.state.dayRequest || 'nothing'}", which does not keep ${date}.`);
  }
});

export const openCalendarMovie = defineStep('openCalendarMovie', async (ctx, code) => {
  const link = ctx.page.locator(`#view .calcell a[href*="#/movie/${code}"]`).first();
  await link.waitFor({ state: 'visible', timeout: 8000 });
  await link.click();
  await ctx.page.waitForFunction(c => {
    const view = document.querySelector('#view');
    if (!view || view.querySelector('.loader') || !location.hash.includes(c)) return false;
    return [...view.querySelectorAll('button')].some(button => /yêu thích/i.test(button.innerText));
  }, code, { timeout: 12000 }).catch(() => { throw new Error(`The calendar did not open ${code}.`); });
});

/** A month the route does not understand falls back to the current month instead of inventing a page. */
export const expectCalendarIgnoresBadMonth = defineStep('expectCalendarIgnoresBadMonth', async (ctx, month) => {
  await ctx.page.evaluate(value => { location.hash = '#/calendar?month=' + value; }, month);
  await ctx.page.waitForFunction(bad => {
    const sub = document.querySelector('#view .sub')?.textContent || '';
    return /\d+\s*phim/.test(sub) && !sub.includes(bad);
  }, month, { timeout: 8000 }).catch(() => { throw new Error(`The calendar kept the invalid month ${month}.`); });
});
