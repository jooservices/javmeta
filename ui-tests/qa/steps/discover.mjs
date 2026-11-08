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

/** Discover suggestion chips come from catalog genres, not hardcoded empty examples. */
export const expectDiscoverChipsAreRealGenres = defineStep('expectDiscoverChipsAreRealGenres', async ctx => {
  const chips = await ctx.page.$$eval('#view .hero .chips a.chip', as => as.map(a => a.textContent.trim()));
  if (chips.some(c => /^(Drama|Văn phòng|Du lịch|Học đường)$/i.test(c))) throw new Error(`Discover still shows dead chips: ${chips.join(', ')}`);
  if (chips.length === 0) throw new Error('Discover has no suggestion chips from the catalog.');
});

/** Search reports the catalog total and, in meaning mode, says when it fell back to keywords. */
export const expectSearchIsHonest = defineStep('expectSearchIsHonest', async ctx => {
  const info = await ctx.page.evaluate(() => ({
    hash: location.hash,
    text: document.querySelector('#view')?.innerText || '',
    movieTotal: document.querySelector('[data-search-movie-total]')?.textContent || '',
    cards: document.querySelectorAll('#view .mc').length,
    fallback: !!document.querySelector('[data-search-fallback]'),
  }));
  const total = Number(String(info.movieTotal).replace(/\D/g, ''));
  if (!(total > info.cards)) throw new Error(`Search total "${info.movieTotal}" is not larger than the ${info.cards} cards on the page.`);
  if (info.hash.includes('mode=meaning') && !info.fallback && !/Tìm theo nghĩa chưa sẵn sàng/.test(info.text)) {
    throw new Error('Meaning mode did not say it fell back to keywords.');
  }
});

export const expectSearchTooShortMessage = defineStep('expectSearchTooShortMessage', async ctx => {
  await ctx.page.evaluate(() => { location.hash = '#/search?q=a'; });
  await ctx.page.waitForTimeout(800);
  const text = await ctx.page.$eval('#view', e => e.innerText);
  if (!/ít nhất 2 ký tự/.test(text)) throw new Error('A one-character query has no too-short message.');
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
