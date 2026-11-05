import { defineStep } from '../lib/step.mjs';

const movieLinks = page => page.$$eval('#view a[href*="#/movie/"]', as => [...new Set(as.map(a => decodeURIComponent(a.getAttribute('href').split('#/movie/')[1])))]);

/** Types a keyword into the movie filter and waits for the debounced list to refresh. */
export const filterMoviesByKeyword = defineStep('filterMoviesByKeyword', async (ctx, keyword) => {
  const box = ctx.page.locator('#view input[name=q]').first();
  await box.fill(keyword);
  await ctx.page.waitForTimeout(1800);
});

export const expectMovieResults = defineStep('expectMovieResults', async (ctx, { atLeast = 1, atMost = Infinity, contains } = {}) => {
  const codes = await movieLinks(ctx.page);
  if (codes.length < atLeast) throw new Error(`Expected at least ${atLeast} movies, the list shows ${codes.length}.`);
  if (codes.length > atMost) throw new Error(`Expected at most ${atMost} movies, the list shows ${codes.length}.`);
  if (contains && !codes.includes(contains)) throw new Error(`The list does not show ${contains}: ${codes.slice(0, 5).join(', ')}`);
});

export const openMovie = defineStep('openMovie', async (ctx, code) => {
  await ctx.page.locator(`#view a[href$="#/movie/${code}"]`).first().click();
  await ctx.page.waitForFunction(c => location.hash.includes(c), code, { timeout: 8000 });
  await ctx.page.waitForTimeout(1200);
});

export const expectMovieDetail = defineStep('expectMovieDetail', async (ctx, code, title) => {
  const text = await ctx.page.$eval('#view', e => e.innerText);
  if (!text.includes(code)) throw new Error(`The detail page does not show the code ${code}.`);
  if (title && !text.includes(title)) throw new Error(`The detail page does not show the title "${title}".`);
  if (!(await ctx.page.$('#view button:has-text("Yêu thích")'))) throw new Error('The detail page has no favourite button.');
});

/** Clicks the favourite button and waits until its label flips between "Yêu thích" and "Đã yêu thích". */
export const toggleFavourite = defineStep('toggleFavourite', async ctx => {
  const label = () => ctx.page.evaluate(() => [...document.querySelectorAll('#view button')].map(b => b.innerText.trim()).find(t => /yêu thích/i.test(t)) || '');
  const before = await label();
  if (!before) throw new Error('The page has no favourite button.');
  await ctx.page.locator('#view button', { hasText: /yêu thích/i }).first().click();
  await ctx.page.waitForFunction(b => [...document.querySelectorAll('#view button')].map(x => x.innerText.trim()).find(t => /yêu thích/i.test(t)) !== b, before, { timeout: 6000 })
    .catch(() => { throw new Error(`The favourite button still says "${before}" after the click.`); });
  ctx.state.favouriteLabel = await label();
});

export const expectCalendarOpensWithReleases = defineStep('expectCalendarOpensWithReleases', async ctx => {
  const info = await ctx.page.evaluate(() => ({
    text: document.querySelector('#view')?.innerText || '',
    movies: document.querySelectorAll('#view a[href*="#/movie/"]').length,
  }));
  if (info.movies === 0 || /0 phim/.test(info.text.split('\n')[0] || '')) throw new Error(`The calendar opened empty: ${info.text.slice(0, 120)}`);
});

export const expectInFavourites = defineStep('expectInFavourites', async (ctx, code, shouldBe = true) => {
  await ctx.page.goto(ctx.base + '/app#/me/favourite');
  await ctx.page.waitForTimeout(1800);
  const present = (await movieLinks(ctx.page)).includes(code);
  if (present !== shouldBe) throw new Error(shouldBe ? `${code} is not in the favourites list.` : `${code} is still in the favourites list.`);
});
