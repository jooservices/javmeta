import { defineStep } from '../lib/step.mjs';

const dashboardFixture = {
  as_of: '2026-11-10T03:04:01Z',
  ui_login_required: true,
  ui_login_state: 'Sign-in required',
  totals: { movies: 12, performers: 3, movies_first_seen_1h: 1, failed_jobs: 0 },
  queue: { pending: 1, claimed: 0, done: 8, failed: 0 },
  hour: { runs: 1, pages: 2, movies_new: 1, movies_updated: 0, failures: 0 },
  sources: [],
  issues: [
    { source: 'javdb', kind: 'performer_detail', code: 'parse_failed', attempts: 2, detail: '' },
    { source: 'archive', kind: 'legacy <img src=x onerror=alert(1)>', code: 'unknown', attempts: 1, detail: '' },
  ],
  events: [
    { source: 'javdb', kind: 'blocked', at: '2026-11-10T03:04:01Z' },
    { source: 'javdb', kind: 'queue_reclaimed_without_start', at: '2026-11-10T03:04:59Z' },
    { source: 'archive', kind: 'legacy_event_kind', at: '2026-11-10T03:04:30Z' },
  ],
};

const nodeExpiryFixture = {
  data: [{ node_name: 'worker-expiry', role: 'worker', status: 'pending', expires_at: '2026-11-10T03:04:59Z', used_at: null }],
};

export const setBrowserAcceptLanguage = defineStep('setBrowserAcceptLanguage', async (ctx, locale) => {
  await ctx.page.setExtraHTTPHeaders({ 'Accept-Language': locale });
});

export const mockNodeValidationError = defineStep('mockNodeValidationError', async ctx => {
  ctx.nodeValidationLocales = [];
  ctx.nodeValidationRequestLocales = [];
  await ctx.page.route('**/ops/nodes**', async route => {
    if (route.request().method() !== 'POST') return route.continue();
    const requestUrl = new URL(route.request().url());
    const queryLocale = requestUrl.searchParams.get('lang');
    const locale = queryLocale || route.request().headers()['accept-language']?.split(',')[0].split('-')[0] || 'vi';
    ctx.nodeValidationRequestLocales.push(queryLocale);
    const message = locale === 'en'
      ? 'The node name may only use lowercase letters, digits and hyphens, and must start with a letter.'
      : 'Tên nút chỉ được dùng chữ thường, chữ số và dấu gạch nối, đồng thời phải bắt đầu bằng một chữ cái.';
    ctx.nodeValidationLocales.push(locale);
    return route.fulfill({
      status: 422,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'The given data was invalid.', errors: { node_name: [message] } }),
    });
  });
});

export const submitNodeNameWithRegexError = defineStep('submitNodeNameWithRegexError', async (ctx, locale, message) => {
  await ctx.page.$eval('#node-name', element => { element.value = 'Invalid_name'; });
  await ctx.page.$eval('#node-form', form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
  await ctx.page.waitForFunction(expected => document.querySelector('#node-error')?.textContent === expected, message, { timeout: 5000 })
    .catch(() => { throw new Error(`The node validation error did not follow ${locale}.`); });
  if (ctx.nodeValidationLocales.at(-1) !== locale) {
    throw new Error(`The node request locale was ${ctx.nodeValidationLocales.at(-1)}, expected ${locale}.`);
  }
  if (ctx.nodeValidationRequestLocales.at(-1) !== locale) {
    throw new Error(`The node request URL did not include lang=${locale}.`);
  }
});

export const mockOperationsDashboardData = defineStep('mockOperationsDashboardData', async ctx => {
  ctx.operationsRequestLocales = [];
  await ctx.page.route('**/ops/data**', route => {
    const requestUrl = new URL(route.request().url());
    ctx.operationsRequestLocales.push(requestUrl.searchParams.get('lang') || route.request().headers()['accept-language']?.split(',')[0].split('-')[0] || '');
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(dashboardFixture) });
  });
  await ctx.page.route('**/ops/nodes**', route => {
    const requestUrl = new URL(route.request().url());
    ctx.operationsRequestLocales.push(requestUrl.searchParams.get('lang') || route.request().headers()['accept-language']?.split(',')[0].split('-')[0] || '');
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(nodeExpiryFixture) });
  });
});

export const expectLocalizedOperationsKindsAndPreciseTimes = defineStep('expectLocalizedOperationsKindsAndPreciseTimes', async (ctx, locale, expected) => {
  await ctx.page.waitForFunction(() => document.querySelector('#events .row') && document.querySelector('#node-list .node-row'), null, { timeout: 8000 });
  await ctx.page.waitForFunction(code => document.documentElement.lang === code, locale, { timeout: 5000 });
  await ctx.page.waitForFunction(kind => document.querySelector('#events .row span:nth-child(2)')?.textContent === kind, expected.blocked, { timeout: 8000 });
  const info = await ctx.page.evaluate(() => {
    const dateTimeFormat = new Intl.DateTimeFormat(document.documentElement.lang, { dateStyle: 'medium', timeStyle: 'medium' });
    const formatted = value => dateTimeFormat.format(new Date(value));
    return {
      issueKinds: [...document.querySelectorAll('#issues .row span:last-child')].map(element => element.textContent),
      eventKinds: [...document.querySelectorAll('#events .row span:nth-child(2)')].map(element => element.textContent),
      eventTimes: [...document.querySelectorAll('#events .row span:last-child')].map(element => element.textContent),
      asOf: document.querySelector('#asof')?.textContent || '',
      expiry: document.querySelector('#node-list .node-row span:last-child')?.textContent || '',
      expectedTimes: [
        formatted('2026-11-10T03:04:01Z'),
        formatted('2026-11-10T03:04:59Z'),
      ],
    };
  });
  if (!info.issueKinds[0]?.startsWith(`${expected.issueKind} ·`)) throw new Error(`Issue kind was not translated to ${locale}: ${info.issueKinds[0]}.`);
  if (!info.issueKinds[1]?.startsWith('legacy <img src=x onerror=alert(1)> ·')) throw new Error('An unknown issue kind was lost or treated as markup.');
  if (await ctx.page.$eval('#issues img', () => true).catch(() => false)) throw new Error('An unknown issue kind rendered markup.');
  if (!info.eventKinds.includes(expected.blocked) || !info.eventKinds.includes(expected.reclaimed)) throw new Error(`Event kinds were not translated to ${locale}: ${info.eventKinds.join(', ')}.`);
  if (!info.eventKinds.includes('legacy_event_kind')) throw new Error('An unknown event kind did not keep its readable fallback.');
  if (info.eventTimes[0] !== info.expectedTimes[0] || info.eventTimes[1] !== info.expectedTimes[1] || info.eventTimes[0] === info.eventTimes[1]) {
    throw new Error(`Event times did not preserve seconds in ${locale}: ${info.eventTimes.join(' | ')}.`);
  }
  if (!info.asOf.includes(info.expectedTimes[0])) throw new Error(`The live update time did not preserve seconds in ${locale}: ${info.asOf}.`);
  if (!info.expiry.includes(info.expectedTimes[1])) throw new Error(`The node expiry did not preserve seconds in ${locale}: ${info.expiry}.`);
  if (!ctx.operationsRequestLocales.includes(locale)) throw new Error(`Dashboard requests did not send the selected ${locale} locale.`);
});

export const switchOperationsLanguage = defineStep('switchOperationsLanguage', async (ctx, locale) => {
  const link = ctx.page.locator(`.locale-switch a[lang="${locale}"]`);
  if (await link.count() !== 1) throw new Error(`Operations language switcher has no ${locale} link.`);
  await link.click();
  await ctx.page.waitForFunction(code => document.documentElement.lang === code, locale, { timeout: 5000 })
    .catch(() => { throw new Error(`Operations page lang did not become ${locale}.`); });
});

export const expectOperationsDashboardLanguage = defineStep('expectOperationsDashboardLanguage', async (ctx, locale, expected) => {
  await ctx.page.waitForFunction(() => document.querySelector('#movies')?.textContent !== '—', null, { timeout: 8000 });
  const info = await ctx.page.evaluate(async () => {
    const response = await fetch('/ops/data', {credentials: 'same-origin', cache: 'no-store'});
    if (!response.ok) throw new Error(`Dashboard data returned ${response.status}.`);
    const data = await response.json();
    const time = document.querySelector('#asof');
    const currentLanguage = document.querySelector('.locale-switch a[aria-current="true"]');
    return {
      lang: document.documentElement.lang,
      title: document.title,
      heading: document.querySelector('h1')?.textContent?.trim() || '',
      sourceHeading: document.querySelector('.grid .panel h2')?.textContent?.trim() || '',
      ariaLabel: document.querySelector('.kpis')?.getAttribute('aria-label') || '',
      currentLanguage: currentLanguage?.lang || '',
      movieCount: document.querySelector('#movies')?.textContent || '',
      expectedMovieCount: new Intl.NumberFormat(document.documentElement.lang).format(data.totals.movies),
      asOfText: time?.textContent || '',
      expectedAsOf: new Intl.DateTimeFormat(document.documentElement.lang, {dateStyle: 'medium', timeStyle: 'medium'}).format(new Date(time?.dateTime || '')),
      hasOtherLanguage: Boolean(document.querySelector(`.locale-switch a[lang="${document.documentElement.lang === 'en' ? 'vi' : 'en'}"]`)),
    };
  });

  for (const [field, value] of Object.entries({lang: locale, title: expected.title, heading: expected.heading, sourceHeading: expected.source, ariaLabel: expected.aria, currentLanguage: locale})) {
    if (info[field] !== value) throw new Error(`${field} is "${info[field]}", expected "${value}".`);
  }
  if (info.movieCount !== info.expectedMovieCount) throw new Error(`Movie count "${info.movieCount}" is not formatted for ${locale}.`);
  if (!info.asOfText.includes(info.expectedAsOf)) throw new Error(`As-of time "${info.asOfText}" is not formatted for ${locale}.`);
  if (!info.hasOtherLanguage) throw new Error(`Operations language switcher has no alternate language from ${locale}.`);
});
