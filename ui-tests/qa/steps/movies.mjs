import { defineStep } from '../lib/step.mjs';

const movieLinks = page => page.$$eval('#view a[href*="#/movie/"]', as => [...new Set(as.map(a => decodeURIComponent(a.getAttribute('href').split('#/movie/')[1])))]);

let relatedMoviesFailureRegressionPassed = false;

function deferred() {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return { promise, resolve };
}

async function waitWithin(promise, timeoutMs, message) {
  let timer;
  try {
    return await Promise.race([
      promise,
      new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(message)), timeoutMs); }),
    ]);
  } finally {
    clearTimeout(timer);
  }
}

async function verifyRelatedMoviesFailureRegression(ctx, code, originalHash) {
  const { page } = ctx;
  const locale = (await page.locator('html').getAttribute('lang') || 'vi').split('-')[0];
  const messages = await page.evaluate(async currentLocale => {
    const response = await fetch(`/ui/i18n/${currentLocale}.json`);
    if (!response.ok) throw new Error(`Could not load the ${currentLocale} UI catalog.`);
    return response.json();
  }, locale);
  const relatedPath = `/ui-api/movies/${encodeURIComponent(code)}/related`;
  const detailPath = `/ui-api/movies/${encodeURIComponent(code)}`;
  const timeoutGate = deferred();
  const timeoutHandled = deferred();
  const firstRequestStarted = deferred();
  const lateGate = deferred();
  const lateHandled = deferred();
  const lateRequestStarted = deferred();
  const lateFailureGate = deferred();
  const lateFailureHandled = deferred();
  const lateFailureRequestStarted = deferred();
  const rawMarker = 'J335A_UPSTREAM_RESPONSE_MARKER';
  const staleMarker = 'J335A_STALE_RELATED_RESPONSE';
  const staleFailureMarker = 'J335A_STALE_RELATED_FAILURE';
  const issueStart = ctx.issues.length;
  let relatedRequests = 0;
  let detailRequests = 0;
  const observedRelatedRequests = [];
  const failedRelatedRequests = new WeakSet();
  const requestFailureWaiters = new WeakMap();

  const matchesRelated = url => new URL(String(url)).pathname === relatedPath;
  const countMovieDetails = request => {
    if (new URL(request.url()).pathname === detailPath) detailRequests += 1;
  };
  const observeRelatedRequest = request => {
    if (matchesRelated(request.url())) observedRelatedRequests.push(request);
  };
  const observeRelatedFailure = request => {
    if (!matchesRelated(request.url())) return;
    failedRelatedRequests.add(request);
    requestFailureWaiters.get(request)?.resolve();
  };
  const waitForRelatedFailure = request => {
    if (failedRelatedRequests.has(request)) return Promise.resolve();
    const waiter = deferred();
    requestFailureWaiters.set(request, waiter);
    return waiter.promise;
  };
  const relatedHandler = async route => {
    relatedRequests += 1;
    if (relatedRequests === 1) {
      firstRequestStarted.resolve();
      try {
        await timeoutGate.promise;
        await route.fulfill({ status: 524, contentType: 'text/html', body: `<h1>${rawMarker}</h1>` });
      } catch {
        // The client is expected to abort this request when its timeout expires.
      } finally {
        timeoutHandled.resolve();
      }
      return;
    }
    if (relatedRequests === 2) {
      await route.fulfill({
        status: 524,
        contentType: 'text/html',
        body: `<h1>${rawMarker}</h1>`,
      });
      return;
    }
    if (relatedRequests === 3) {
      await route.fulfill({
        status: 524,
        contentType: 'application/json',
        body: JSON.stringify({ detail: `<svg data-j335a-upstream>${rawMarker}</svg>` }),
      });
      return;
    }
    if (relatedRequests === 4) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ code: 'J335A-RELATED', title_en: 'Retry Successful Related Movie', performers: [], genres: [] }] }),
      });
      return;
    }
    if (relatedRequests === 5) {
      lateRequestStarted.resolve();
      try {
        await lateGate.promise;
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: [{ code: 'J335A-STALE', title_en: staleMarker, performers: [], genres: [] }] }),
        });
      } catch {
        // Navigation may cancel the delayed response after the old view is detached.
      } finally {
        lateHandled.resolve();
      }
      return;
    }
    if (relatedRequests === 6) {
      lateFailureRequestStarted.resolve();
      try {
        await lateFailureGate.promise;
        await route.fulfill({
          status: 524,
          contentType: 'application/json',
          body: JSON.stringify({ detail: staleFailureMarker }),
        });
      } catch {
        // Navigation may cancel the delayed failure after the old view is detached.
      } finally {
        lateFailureHandled.resolve();
      }
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  };
  const setHash = async hash => {
    await page.evaluate(next => { location.hash = next; }, hash);
    await page.waitForFunction(expected => location.hash === expected, hash);
  };
  const assertRelatedFailure = async (message, catalogs, marker, responseType) => {
    await message.waitFor({ timeout: 8000 });
    if (await message.innerText() !== catalogs['movie.related_failed']) {
      throw new Error(`A ${responseType} upstream error did not show the generic localized failure message.`);
    }
    const viewText = await page.locator('#view').innerText();
    if (viewText.includes(marker) || await page.locator('#view [data-j335a-upstream]').count() > 0) {
      throw new Error(`Raw ${responseType} related-movies content was rendered.`);
    }
    if (!(await page.locator('#view h1').innerText()).trim() || await page.locator('#view #movie-downloads').count() !== 1) {
      throw new Error(`The related-movies ${responseType} failure replaced movie details or another page block.`);
    }
  };

  try {
    page.on('request', countMovieDetails);
    page.on('request', observeRelatedRequest);
    page.on('requestfailed', observeRelatedFailure);
    await page.route(matchesRelated, relatedHandler);
    await setHash('#/movies');
    await page.locator('#view input[name=q]').waitFor();
    await setHash(originalHash || `#/movie/${encodeURIComponent(code)}`);

    const loading = page.locator('#relstrip[data-related-state="loading"]');
    await loading.waitFor({ timeout: 8000 });
    await waitWithin(firstRequestStarted.promise, 3000, 'The related-movies timeout request did not start.');
    if (await loading.locator('.related-skeleton').count() !== 3 || await loading.getAttribute('aria-busy') !== 'true') {
      throw new Error('The related-movies loading skeleton is missing or unstable.');
    }
    if (!(await page.locator('#view h1').innerText()).trim() || await page.locator('#view #movie-downloads').count() !== 1) {
      throw new Error('The related-movies loading state replaced movie details or another page block.');
    }

    const relatedError = page.locator('#relstrip[data-related-state="error"] [data-related-message]');
    await relatedError.waitFor({ timeout: 8000 });
    if (await relatedError.innerText() !== messages['movie.related_timeout']) {
      throw new Error('A related-movies timeout did not show its localized message.');
    }
    timeoutGate.resolve();
    if (relatedRequests >= 1) await timeoutHandled.promise;

    const retry = page.locator('#relstrip [data-related-retry]');
    if (await retry.innerText() !== messages['movie.related_retry']) throw new Error('The related-movies retry label is not localized.');
    await retry.click();
    await assertRelatedFailure(relatedError, messages, rawMarker, 'HTML');

    await page.locator('#relstrip [data-related-retry]').click();
    await assertRelatedFailure(relatedError, messages, rawMarker, 'JSON');

    await page.locator('#relstrip [data-related-retry]').click();
    await page.locator('#relstrip[data-related-state="loading"]').waitFor();
    if (!(await page.locator('#view h1').innerText()).trim() || await page.locator('#view #movie-downloads').count() !== 1) {
      throw new Error('Retry replaced movie details or another page block while loading.');
    }
    const relatedCard = page.locator('#relstrip[data-related-state="success"] a[href$="#/movie/J335A-RELATED"]');
    await relatedCard.waitFor();
    if (!(await relatedCard.innerText()).includes('Retry Successful Related Movie')) {
      throw new Error('A successful related-movies retry did not render its results.');
    }
    if (detailRequests !== 1) throw new Error('Retry reloaded the movie detail instead of only the related-movies block.');

    const navigateAwayBeforeRelatedResponse = async ({ gate, handled, started, requestIndex, marker, description }) => {
      await setHash('#/movies');
      await page.locator('#view input[name=q]').waitFor();
      await setHash(originalHash || `#/movie/${encodeURIComponent(code)}`);
      await loading.waitFor({ timeout: 8000 });
      await waitWithin(started.promise, 3000, `The delayed ${description} request did not start.`);
      const pendingRequest = observedRelatedRequests[requestIndex];
      if (!pendingRequest) throw new Error(`The delayed ${description} request was not observed.`);

      await setHash('#/movies');
      await page.locator('#view input[name=q]').waitFor();
      await waitWithin(waitForRelatedFailure(pendingRequest), 3000, `The delayed ${description} request was not aborted after navigation.`);
      gate.resolve();
      await waitWithin(handled.promise, 3000, `The delayed ${description} response handler did not finish.`);
      if ((await page.locator('#view').innerText()).includes(marker)) {
        throw new Error(`A late related-movies ${description} modified the page after navigation.`);
      }
    };

    await navigateAwayBeforeRelatedResponse({
      gate: lateGate,
      handled: lateHandled,
      started: lateRequestStarted,
      requestIndex: 4,
      marker: staleMarker,
      description: 'success',
    });
    await navigateAwayBeforeRelatedResponse({
      gate: lateFailureGate,
      handled: lateFailureHandled,
      started: lateFailureRequestStarted,
      requestIndex: 5,
      marker: staleFailureMarker,
      description: 'failure',
    });

    await setHash(originalHash || `#/movie/${encodeURIComponent(code)}`);
    await page.locator('#relstrip[data-related-state="empty"]').waitFor({ timeout: 8000 });
    if (await page.locator('#relstrip').innerText() !== messages['movie.no_related']) {
      throw new Error('An empty related-movies response did not show the empty state.');
    }
    if (!(await page.locator('#view h1').innerText()).trim()) throw new Error('The movie detail did not recover after returning from another view.');
  } finally {
    timeoutGate.resolve();
    lateGate.resolve();
    lateFailureGate.resolve();
    if (relatedRequests >= 1) await timeoutHandled.promise;
    if (relatedRequests >= 5) await lateHandled.promise;
    if (relatedRequests >= 6) await lateFailureHandled.promise;
    await page.unroute(matchesRelated, relatedHandler).catch(() => {});
    page.off('request', countMovieDetails);
    page.off('request', observeRelatedRequest);
    page.off('requestfailed', observeRelatedFailure);
    for (let index = ctx.issues.length - 1; index >= issueStart; index -= 1) {
      const issue = ctx.issues[index];
      if (issue.includes(relatedPath) && (issue.startsWith('requestfailed ') || issue.startsWith('http 524 '))) ctx.issues.splice(index, 1);
    }
  }
}

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
  if (!relatedMoviesFailureRegressionPassed) {
    const originalHash = new URL(ctx.page.url()).hash;
    for (const locale of ['en', 'vi']) {
      await ctx.page.evaluate(nextLocale => localStorage.setItem('xc-locale', nextLocale), locale);
      await ctx.page.reload();
      await ctx.page.waitForFunction(expected => document.documentElement.lang.split('-')[0] === expected, locale, { timeout: 8000 });
      await ctx.page.locator('#view h1').waitFor({ timeout: 8000 });
      await verifyRelatedMoviesFailureRegression(ctx, code, originalHash);
    }
    relatedMoviesFailureRegressionPassed = true;
  }
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
