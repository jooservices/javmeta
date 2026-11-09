import { defineStep } from '../lib/step.mjs';
import { onContextClose } from './cleanup.mjs';
import { escapeRegExp, openFacetSection, typeInto, viewText, waitForCount, waitForHash, waitForList, waitForSettledCount } from './dom.mjs';

const noLoader = page => page.waitForFunction(() => !document.querySelector('#view .loader'), null, { timeout: 12000 })
  .catch(() => { throw new Error('The view kept its loading spinner.'); });

async function deleteSavedByPrefix(page, prefix) {
  const status = await page.evaluate(async needle => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token };
    const listed = await fetch('/ui-me/marks?kind=saved_search&subject=movies', { credentials: 'same-origin', headers });
    if (!listed.ok) return listed.status;
    const rows = (await listed.json()).data || [];
    for (const row of rows) {
      let name = '';
      try { name = JSON.parse(row.key).name || ''; } catch { continue; }
      if (!String(name).startsWith(needle)) continue;
      const removed = await fetch('/ui-me/marks', {
        method: 'POST', credentials: 'same-origin', headers,
        body: JSON.stringify({ kind: 'saved_search', subject: 'movies', key: row.key, on: false }),
      });
      if (!removed.ok) return removed.status;
    }
    return 200;
  }, prefix);
  if (status !== 200) throw new Error(`Removing saved searches failed with HTTP ${status}.`);
}

/** Removes e2e saved searches when the scenario context closes, including after a failed step. */
export const armSavedSearchCleanup = defineStep('armSavedSearchCleanup', async ctx => {
  if (ctx.state.savedSearchCleanup) return;
  ctx.state.savedSearchCleanup = true;
  onContextClose(ctx, () => deleteSavedByPrefix(ctx.page, 'e2e-385-'));
});

/** Types into the search page and submits with Enter. Fewer than two characters stays on the prompt. */
export const searchFor = defineStep('searchFor', async (ctx, term) => {
  const box = ctx.page.locator('#view #sq');
  await box.waitFor({ state: 'visible', timeout: 8000 });
  const pending = term.trim().length >= 2
    ? ctx.page.waitForResponse(response => {
      const url = decodeURIComponent(response.url().replace(/\+/g, ' '));
      return response.request().method() === 'GET' && url.includes('/ui-api/search?') && url.includes(`q=${term}`);
    }, { timeout: 12000 })
    : null;
  await typeInto(box, term);
  await ctx.page.keyboard.press('Enter');
  await waitForHash(ctx.page, `q=${term}`);
  if (pending) await pending.catch(() => { throw new Error(`The search for "${term}" did not request results.`); });
  await noLoader(ctx.page);
});

export const expectSearchPrompt = defineStep('expectSearchPrompt', async ctx => {
  const text = await viewText(ctx.page);
  if (!text.includes('Ví dụ')) throw new Error('The search prompt does not show its examples.');
  const chips = await ctx.page.locator('#view .hero a.chip').count();
  if (chips === 0) throw new Error('The search prompt has no example chips from the catalog.');
  if (text.includes('Không có phim khớp')) throw new Error('The search prompt is showing an empty result.');
  const movies = await ctx.page.locator('#view a[href*="#/movie/"]').count();
  if (movies !== 0) throw new Error(`The search prompt lists ${movies} movies.`);
});

export const expectSearchEmpty = defineStep('expectSearchEmpty', async ctx => {
  const text = await viewText(ctx.page);
  if (!text.includes('Không có phim khớp')) throw new Error('The search does not say that no movie matched.');
  if (!text.includes('Không có diễn viên khớp')) throw new Error('The search does not say that no performer matched.');
  const movies = await ctx.page.locator('#view a[href*="#/movie/"]').count();
  if (movies !== 0) throw new Error(`The empty search still lists ${movies} movies.`);
});

export const openMeaningSearch = defineStep('openMeaningSearch', async ctx => {
  const pending = ctx.page.waitForResponse(response => {
    const url = response.url();
    return response.request().method() === 'GET' && url.includes('/ui-api/search?') && !url.includes('understand=0');
  }, { timeout: 12000 });
  await ctx.page.locator('#view .seg a', { hasText: 'Theo nghĩa' }).click();
  await waitForHash(ctx.page, 'mode=meaning');
  await pending.catch(() => { throw new Error('Meaning mode did not request a search.'); });
  await noLoader(ctx.page);
});

export const expectMeaningMode = defineStep('expectMeaningMode', async ctx => {
  const on = (await ctx.page.locator('#view .seg a.on').innerText()).trim();
  if (on !== 'Theo nghĩa') throw new Error(`The active search mode is "${on}", expected meaning.`);
  const hash = await ctx.page.evaluate(() => location.hash);
  if (!hash.includes('mode=meaning')) throw new Error('The address is not in meaning mode.');
});

/** Enter in the search box submits the keyword form, which drops meaning mode. */
export const submitSearchWithKeyboard = defineStep('submitSearchWithKeyboard', async ctx => {
  const box = ctx.page.locator('#view #sq');
  const term = (await box.inputValue()).trim();
  if (term.length < 2) throw new Error('There is no search to submit.');
  const pending = ctx.page.waitForResponse(response => response.request().method() === 'GET' && response.url().includes('/ui-api/search?') && response.url().includes('understand=0'), { timeout: 12000 });
  await box.focus();
  await ctx.page.keyboard.press('Enter');
  await ctx.page.waitForFunction(() => !location.hash.includes('mode=meaning'), null, { timeout: 8000 })
    .catch(() => { throw new Error('Submitting the search left meaning mode on.'); });
  await pending.catch(() => { throw new Error('The keyword search was not requested.'); });
  await noLoader(ctx.page);
});

export const expectKeywordMode = defineStep('expectKeywordMode', async ctx => {
  const on = (await ctx.page.locator('#view .seg a.on').innerText()).trim();
  if (on !== 'Từ khoá') throw new Error(`The active search mode is "${on}", expected keyword.`);
  const hash = await ctx.page.evaluate(() => location.hash);
  if (hash.includes('mode=meaning')) throw new Error('The address is still in meaning mode.');
});

export const rememberResultCount = defineStep('rememberResultCount', async ctx => {
  const count = await waitForCount(ctx.page);
  if (count < 1) throw new Error('There is no list count to restore later.');
  ctx.state.rememberedCount = count;
});

export const expectRememberedCount = defineStep('expectRememberedCount', async ctx => {
  const count = await waitForCount(ctx.page);
  if (count !== ctx.state.rememberedCount) throw new Error(`The list has ${count}, expected the earlier ${ctx.state.rememberedCount}.`);
});

export const chooseMovieChoice = defineStep('chooseMovieChoice', async (ctx, key, value) => {
  await openFacetSection(ctx.page, key);
  const search = ctx.page.locator(`#view [data-choice-search="${key}"]`);
  if (await search.count()) await search.fill(value);
  const box = ctx.page.locator(`#view input[data-choice="${key}"][value="${value}"]`);
  await box.waitFor({ state: 'attached', timeout: 12000 });
  if (await box.isChecked()) throw new Error(`${key}=${value} is already selected.`);
  const previous = await ctx.page.$eval('#view [data-count]', element => element.textContent.trim()).catch(() => '');
  const pending = waitForList(ctx.page, '/ui-api/movies?', [`${key}=${value}`]);
  await box.check();
  await waitForHash(ctx.page, `${key}=${value}`);
  await pending;
  await waitForSettledCount(ctx.page, previous);
});

export const filterMovieField = defineStep('filterMovieField', async (ctx, name, value) => {
  const box = ctx.page.locator(`#view [data-filter-form] input[name="${name}"]`);
  await box.waitFor({ state: 'visible', timeout: 8000 });
  const previous = await ctx.page.$eval('#view [data-count]', element => element.textContent.trim()).catch(() => '');
  const pending = waitForList(ctx.page, '/ui-api/movies?', value ? [`${name}=${value}`] : []);
  await typeInto(box, value);
  if (value) await waitForHash(ctx.page, `${name}=${value}`);
  await pending;
  await waitForSettledCount(ctx.page, previous);
});

export const expectMovieListEmpty = defineStep('expectMovieListEmpty', async ctx => {
  const count = await waitForCount(ctx.page);
  if (count !== 0) throw new Error(`The movie list still has ${count}.`);
  const text = await viewText(ctx.page);
  if (!text.includes('Không có phim khớp')) throw new Error('The empty movie list does not say that nothing matched.');
});

export const saveCurrentSearch = defineStep('saveCurrentSearch', async (ctx, name) => {
  const hash = await ctx.page.evaluate(() => location.hash);
  if (!hash.includes('?')) throw new Error('There is no filter to save.');
  await ctx.page.locator('#view [data-save-search]').click();
  const form = ctx.page.locator('#view [data-save-form]');
  await form.waitFor({ state: 'visible', timeout: 4000 });
  await typeInto(form.locator('input[name=search_name]'), name);
  const pending = ctx.page.waitForResponse(response => response.url().includes('/ui-me/marks') && response.request().method() === 'POST', { timeout: 12000 });
  await form.locator('button[type=submit]').click();
  const response = await pending.catch(() => { throw new Error('Saving the search did not call the server.'); });
  if (!response.ok()) throw new Error(`Saving the search failed with HTTP ${response.status()}.`);
  await ctx.page.waitForFunction(saved => (document.querySelector('#view [data-saved-list]')?.textContent || '').includes(saved), name, { timeout: 8000 })
    .catch(() => { throw new Error(`The saved list does not show "${name}".`); });
  const open = await ctx.page.$eval('#view details', el => el.open).catch(() => false);
  if (!open) throw new Error('U-14: saving a filter left the saved list closed.');
});

export const cancelSaveSearch = defineStep('cancelSaveSearch', async ctx => {
  await ctx.page.locator('#view [data-save-search]').click();
  const form = ctx.page.locator('#view [data-save-form]');
  await form.waitFor({ state: 'visible', timeout: 4000 });
  await form.locator('[data-save-cancel]').click();
  await ctx.page.waitForFunction(() => document.querySelector('#view [data-save-form]')?.hidden === true, null, { timeout: 4000 })
    .catch(() => { throw new Error('Cancel left the save form open.'); });
});

async function openSavedList(page) {
  const summary = page.locator('#view summary', { hasText: 'Bộ lọc đã lưu' });
  await summary.waitFor({ state: 'visible', timeout: 8000 });
  const open = await summary.locator('xpath=..').evaluate(details => details.open);
  if (!open) await summary.click();
  await page.locator('#view [data-saved-list]').waitFor({ state: 'visible', timeout: 4000 });
}

export const applySavedSearch = defineStep('applySavedSearch', async (ctx, name, needle) => {
  await openSavedList(ctx.page);
  const link = ctx.page.locator('#view [data-saved-list] a', { hasText: new RegExp(`^${escapeRegExp(name)}$`) });
  await link.waitFor({ state: 'visible', timeout: 8000 });
  const href = decodeURIComponent((await link.getAttribute('href')) || '');
  if (!href.includes(needle)) throw new Error(`The saved search points at "${href}", which does not include ${needle}.`);
  const pending = waitForList(ctx.page, '/ui-api/movies?', [needle]);
  await link.click();
  await waitForHash(ctx.page, needle);
  await pending;
  await noLoader(ctx.page);
});

export const deleteSavedSearch = defineStep('deleteSavedSearch', async (ctx, name) => {
  await openSavedList(ctx.page);
  const target = ctx.page.locator(`#view [data-saved-list] button[aria-label="Xoá ${name}"]`);
  await target.waitFor({ state: 'visible', timeout: 8000 });
  const pending = ctx.page.waitForResponse(response => response.url().includes('/ui-me/marks') && response.request().method() === 'POST', { timeout: 12000 });
  await target.click();
  const yes = ctx.page.locator('[data-confirm-yes]');
  await yes.waitFor({ state: 'visible', timeout: 4000 }).catch(() => { throw new Error('U-14: deleting a saved filter did not ask to confirm.'); });
  await yes.click();
  const response = await pending.catch(() => { throw new Error('Deleting the search did not call the server.'); });
  if (!response.ok()) throw new Error(`Deleting the search failed with HTTP ${response.status()}.`);
  await ctx.page.waitForFunction(saved => !(document.querySelector('#view [data-saved-list]')?.textContent || '').includes(saved), name, { timeout: 8000 })
    .catch(() => { throw new Error(`"${name}" is still in the saved list.`); });
});
