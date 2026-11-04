import { defineStep } from '../lib/step.mjs';

/** Discover is for people who browse: it must not show queue, worker or source-health panels (those live in the Command Center). */
export const expectDiscoverForBrowsers = defineStep('expectDiscoverForBrowsers', async ctx => {
  // Headings are upper-cased by CSS, so compare without regard to case.
  const text = (await ctx.page.$eval('#view', e => e.innerText)).toLowerCase();
  for (const needle of ['Mới cập nhật', 'Diễn viên mới cập nhật', 'Thể loại phổ biến']) {
    if (!text.includes(needle.toLowerCase())) throw new Error(`Discover lacks the "${needle}" block.`);
  }
  for (const forbidden of ['WORKER', 'Chưa có tín hiệu', 'Sức khoẻ crawl', 'việc đang chờ']) {
    if (text.includes(forbidden.toLowerCase())) throw new Error(`Discover shows operations content: "${forbidden}".`);
  }
});

/** Types a question into the big search box of Discover and submits it. */
export const searchFromDiscover = defineStep('searchFromDiscover', async (ctx, term) => {
  const box = ctx.page.locator('#view .bigq input[type=text], #view input[type=search], #view .bigq input').first();
  await box.fill(term);
  await Promise.all([ctx.page.waitForFunction(() => location.hash.startsWith('#/search'), null, { timeout: 8000 }), box.press('Enter')]);
  await ctx.page.waitForTimeout(1500);
});

/** The search result lists at least one movie whose link opens its detail page, and the typed words are shown back. */
export const expectSearchFound = defineStep('expectSearchFound', async (ctx, term) => {
  const found = await ctx.page.evaluate(() => ({
    movieLinks: [...document.querySelectorAll('#view a[href*="#/movie/"]')].map(a => a.getAttribute('href')),
    text: (document.querySelector('#view')?.innerText || ''),
    hash: decodeURIComponent(location.hash),
  }));
  if (!found.hash.toLowerCase().includes(term.toLowerCase().split(' ')[0])) throw new Error(`The address does not carry the search "${term}": ${found.hash}`);
  if (!found.text.includes(term)) throw new Error(`The result page does not show the search "${term}" back to the user.`);
  if (found.movieLinks.length === 0) throw new Error(`The search for "${term}" lists no movie although the sample data has it.`);
});
