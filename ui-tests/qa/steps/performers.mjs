import { defineStep } from '../lib/step.mjs';
import { onContextClose } from './cleanup.mjs';
import { escapeRegExp, typeInto, viewText, waitForHash, waitForList, waitForSettledCount } from './dom.mjs';

const nameLink = (page, name) => page.locator('#view a[href*="#/performer/"]').filter({ hasText: new RegExp(`^${escapeRegExp(name)}$`) });

async function unfollow(page, mark) {
  await page.evaluate(async target => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const res = await fetch('/ui-me/marks', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ kind: 'follow', subject: target.subject, key: String(target.key), on: false }),
    });
    if (!res.ok) throw new Error(String(res.status));
  }, mark);
}

export const filterPerformersByName = defineStep('filterPerformersByName', async (ctx, name) => {
  const box = ctx.page.locator('#view [data-filter-form] input[name=q]');
  await box.waitFor({ state: 'visible', timeout: 8000 });
  const previous = await ctx.page.$eval('#view [data-count]', e => e.textContent.trim()).catch(() => '');
  const pending = waitForList(ctx.page, '/ui-api/performers?', [name]);
  await typeInto(box, name);
  await waitForHash(ctx.page, `q=${name}`);
  await pending;
  await waitForSettledCount(ctx.page, previous);
});

export const choosePerformerOption = defineStep('choosePerformerOption', async (ctx, key, value) => {
  const box = ctx.page.locator(`#view input[data-choice="${key}"][value="${value}"]`);
  await box.waitFor({ state: 'attached', timeout: 12000 });
  if (await box.isChecked()) throw new Error(`${key}=${value} is already selected.`);
  const previous = await ctx.page.$eval('#view [data-count]', e => e.textContent.trim()).catch(() => '');
  const pending = waitForList(ctx.page, '/ui-api/performers?', [`${key}=${value}`]);
  await box.check();
  await waitForHash(ctx.page, `${key}=${value}`);
  await pending;
  await waitForSettledCount(ctx.page, previous);
});

export const filterPerformersByLocation = defineStep('filterPerformersByLocation', async (ctx, location) => {
  const box = ctx.page.locator('#view [data-filter-form] input[name=location]');
  await box.waitFor({ state: 'visible', timeout: 8000 });
  const previous = await ctx.page.$eval('#view [data-count]', e => e.textContent.trim()).catch(() => '');
  const pending = waitForList(ctx.page, '/ui-api/performers?', [`location=${location}`]);
  await typeInto(box, location);
  await waitForHash(ctx.page, `location=${location}`);
  await pending;
  await waitForSettledCount(ctx.page, previous);
});

export const chooseLocationFacet = defineStep('chooseLocationFacet', async (ctx, value) => {
  const button = ctx.page.locator(`#view button[data-facet="location"][data-value="${value}"]`).first();
  await button.waitFor({ state: 'visible', timeout: 12000 });
  const previous = await ctx.page.$eval('#view [data-count]', e => e.textContent.trim()).catch(() => '');
  const pending = waitForList(ctx.page, '/ui-api/performers?', [`location=${value}`]);
  await button.click();
  await waitForHash(ctx.page, `location=${value}`);
  await pending;
  await waitForSettledCount(ctx.page, previous);
});

export const filterPerformersByAge = defineStep('filterPerformersByAge', async (ctx, min, max) => {
  const form = ctx.page.locator('#view [data-filter-form]');
  const previous = await ctx.page.$eval('#view [data-count]', e => e.textContent.trim()).catch(() => '');
  const pending = waitForList(ctx.page, '/ui-api/performers?', [`age_min=${min}`, `age_max=${max}`]);
  await typeInto(form.locator('input[name=age_min]'), String(min));
  await typeInto(form.locator('input[name=age_max]'), String(max));
  await waitForHash(ctx.page, `age_min=${min}`);
  await waitForHash(ctx.page, `age_max=${max}`);
  await pending;
  await waitForSettledCount(ctx.page, previous);
});

export const expectPerformerNamed = defineStep('expectPerformerNamed', async (ctx, name) => {
  const names = await ctx.page.locator('#view a[href*="#/performer/"]').allInnerTexts();
  if (!names.map(n => n.trim()).includes(name)) throw new Error(`The list does not show "${name}". It shows ${names.slice(0, 4).join(', ')}.`);
});

export const expectPerformerAbsent = defineStep('expectPerformerAbsent', async (ctx, name) => {
  const names = (await ctx.page.locator('#view a[href*="#/performer/"]').allInnerTexts()).map(n => n.trim());
  if (names.includes(name)) throw new Error(`"${name}" is still in the performer list.`);
  const text = await viewText(ctx.page);
  if (!text.includes('Không có diễn viên khớp')) throw new Error('The empty performer list does not say that nobody matched.');
});

export const openPerformer = defineStep('openPerformer', async (ctx, name) => {
  const link = nameLink(ctx.page, name);
  if (await link.count() === 0) throw new Error(`No performer card is named "${name}".`);
  await link.first().click();
  await ctx.page.waitForFunction(n => (document.querySelector('#view h1')?.innerText || '').includes(n), name, { timeout: 8000 })
    .catch(() => { throw new Error(`Opening "${name}" did not show that name.`); });
});

export const openFirstPerformer = defineStep('openFirstPerformer', async ctx => {
  const link = ctx.page.locator('#view a[href*="#/performer/"]').first();
  await link.waitFor({ state: 'visible', timeout: 8000 });
  const name = (await link.innerText()).trim();
  if (!name) throw new Error('The first performer card has no name.');
  ctx.state.performerName = name;
  await link.click();
  await ctx.page.waitForFunction(n => (document.querySelector('#view h1')?.innerText || '').includes(n), name, { timeout: 8000 })
    .catch(() => { throw new Error(`Opening "${name}" did not show that profile.`); });
});

export const expectPerformerField = defineStep('expectPerformerField', async (ctx, label, value) => {
  const found = await ctx.page.evaluate(wanted => {
    const dt = [...document.querySelectorAll('#view dt')].find(node => node.textContent.trim() === wanted);
    return dt?.nextElementSibling?.innerText?.trim() || '';
  }, label);
  if (!found.includes(String(value))) throw new Error(`${label} is "${found}", expected it to include "${value}".`);
});

async function openTab(ctx, label, keyboard) {
  const tab = ctx.page.locator('#view a.tab', { hasText: label }).first();
  await tab.waitFor({ state: 'visible', timeout: 8000 });
  if (keyboard) {
    await tab.focus();
    await ctx.page.keyboard.press('Enter');
  } else {
    await tab.click();
  }
  await ctx.page.waitForFunction(wanted => [...document.querySelectorAll('#view a.tab')].some(a => a.classList.contains('on') && a.textContent.includes(wanted)), label, { timeout: 8000 })
    .catch(() => { throw new Error(`The "${label}" tab did not become active.`); });
}

export const openPerformerTab = defineStep('openPerformerTab', async (ctx, label) => openTab(ctx, label, false));
export const openPerformerTabWithKeyboard = defineStep('openPerformerTabWithKeyboard', async (ctx, label) => openTab(ctx, label, true));

export const expectPerformerTab = defineStep('expectPerformerTab', async (ctx, tab) => {
  const text = await viewText(ctx.page);
  if (tab === 'movies') {
    await ctx.page.waitForFunction(() => {
      const view = document.querySelector('#view');
      if (!view || view.querySelector('.loader')) return false;
      return view.querySelectorAll('a[href*="#/movie/"]').length > 0;
    }, null, { timeout: 12000 }).catch(() => { throw new Error('The movies tab lists no movie.'); });
    return;
  }
  if (tab === 'sources') {
    if (!text.includes('sample')) throw new Error('The sources tab does not show the sample source.');
    return;
  }
  if (tab === 'bio') {
    if (!text.includes('Sample Alias One') || !text.includes('Osaka')) throw new Error('The bio tab does not show the sample alias and Osaka.');
    return;
  }
  throw new Error(`Unknown performer tab "${tab}".`);
});

/** Clicks follow only when the button is in the other state, and removes the follow if a later step fails. */
export const setFollow = defineStep('setFollow', async (ctx, on) => {
  const button = ctx.page.locator('#view [data-mark][data-kind=follow]');
  await button.waitFor({ state: 'visible', timeout: 8000 });
  const pressed = await button.getAttribute('aria-pressed');
  const want = on ? 'true' : 'false';
  if (pressed === want) throw new Error(on ? 'Already following, so the click would prove nothing.' : 'Already not following, so the click would prove nothing.');
  const mark = { subject: await button.getAttribute('data-subject'), key: await button.getAttribute('data-key') };
  if (on) ctx.state.followedMark = mark;
  if (on && !ctx.state.followCleanup) {
    ctx.state.followCleanup = true;
    onContextClose(ctx, async () => {
      if (!ctx.state.followedMark) return;
      await unfollow(ctx.page, ctx.state.followedMark);
      ctx.state.followedMark = null;
    });
  }
  await button.click();
  await ctx.page.waitForFunction(expected => document.querySelector('#view [data-mark][data-kind=follow]')?.getAttribute('aria-pressed') === expected, want, { timeout: 8000 })
    .catch(() => { throw new Error(`Follow stayed ${pressed === 'true' ? 'on' : 'off'} after the click.`); });
  if (!on) ctx.state.followedMark = null;
});

export const expectFeedState = defineStep('expectFeedState', async (ctx, follows) => {
  const text = await viewText(ctx.page);
  if (follows === 0) {
    if (!text.includes('Chưa theo dõi gì')) throw new Error('The feed does not say that nothing is followed.');
    return;
  }
  if (!text.includes(`${follows} mục đang theo dõi`)) throw new Error(`The feed does not show ${follows} follow(s): ${text.slice(0, 180)}`);
});

export const expectFeedMovies = defineStep('expectFeedMovies', async (ctx, atLeast, code) => {
  const codes = await ctx.page.$$eval('#view a[href*="#/movie/"]', as => [...new Set(as.map(a => decodeURIComponent((a.getAttribute('href') || '').split('#/movie/')[1] || '').split('?')[0]))]);
  if (atLeast === 0) {
    if (codes.length !== 0) throw new Error(`The feed still lists ${codes.length} movies.`);
    return;
  }
  if (codes.length < atLeast) throw new Error(`The feed lists ${codes.length} movies, expected at least ${atLeast}.`);
  if (code && !codes.includes(code)) throw new Error(`The feed does not list ${code}.`);
});

export const expectPerformerMissing = defineStep('expectPerformerMissing', async (ctx, id = '999999999') => {
  const seen = [];
  const onResponse = response => { if (response.status() >= 400) seen.push(`${response.status()} ${response.url()}`); };
  ctx.page.on('response', onResponse);
  await ctx.page.evaluate(route => { location.hash = route; }, `#/performer/${id}`);
  try {
    await ctx.page.waitForFunction(() => (document.querySelector('#view')?.innerText || '').includes('Không tìm thấy diễn viên'), null, { timeout: 8000 });
  } catch {
    throw new Error('The unknown performer page does not say it is missing.');
  } finally {
    ctx.page.off('response', onResponse);
  }
  if (!seen.some(line => line.startsWith('404') && line.includes(`/performers/${id}`))) {
    throw new Error(`The unknown performer did not return HTTP 404. Saw: ${seen.join(' | ') || 'nothing'}.`);
  }
  ctx.issues.length = 0;
});
