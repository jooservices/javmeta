import { defineStep } from '../lib/step.mjs';

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
      expectedAsOf: new Intl.DateTimeFormat(document.documentElement.lang, {dateStyle: 'medium', timeStyle: 'short'}).format(new Date(time?.dateTime || '')),
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
