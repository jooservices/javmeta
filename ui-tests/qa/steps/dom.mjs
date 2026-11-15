/** vi-VN counts use "." as the thousands separator (1.234). */
export function parseCount(text) {
  const raw = String(text ?? '').trim().match(/[\d.,]+/)?.[0] ?? '';
  const n = Number(raw.replace(/\./g, '').replace(',', '.'));
  if (!raw || !Number.isFinite(n)) throw new Error(`"${text}" is not a count.`);
  return n;
}

export async function waitForCount(page, selector = '#view [data-count]') {
  const handle = await page.waitForFunction(sel => {
    if (document.querySelector('#view .loader')) return null;
    const text = document.querySelector(sel)?.textContent?.trim() || '';
    return /\d/.test(text) ? text : null;
  }, selector, { timeout: 12000 }).catch(() => { throw new Error(`No count appeared in ${selector}.`); });
  return parseCount(await handle.jsonValue());
}

export async function viewText(page) {
  return page.$eval('#view', e => e.innerText);
}

export async function waitForHash(page, needle) {
  await page.waitForFunction(n => decodeURIComponent(location.hash.replace(/\+/g, ' ')).includes(n), needle, { timeout: 8000 })
    .catch(() => { throw new Error(`The address never included "${needle}".`); });
}

/** The next list request whose URL contains every needle. Ignores facet calls. */
export function waitForList(page, fragment, needles = []) {
  return page.waitForResponse(response => {
    const url = decodeURIComponent(response.url().replace(/\+/g, ' '));
    return response.request().method() === 'GET' && url.includes(fragment) && !url.includes('/facets') && needles.every(needle => url.includes(needle));
  }, { timeout: 12000 });
}

/** After a hash change, wait until the new list has replaced the previous count. */
export async function waitForSettledCount(page, previous = null) {
  const handle = await page.waitForFunction(prev => {
    if (document.querySelector('#view .loader')) return null;
    const text = document.querySelector('#view [data-count]')?.textContent?.trim() || '';
    if (!/\d/.test(text) || text === prev) return null;
    return text;
  }, previous, { timeout: 12000 }).catch(() => { throw new Error('The list did not finish loading a new count.'); });
  return parseCount(await handle.jsonValue());
}

export function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** Replaces a field with the keyboard so the debounced filter actually runs. */
export async function typeInto(locator, value) {
  await locator.click();
  await locator.fill('');
  if (value !== '') await locator.pressSequentially(String(value), { delay: 12 });
}

/** Filter sections are collapsed <details>; open the one holding this facet so its list loads and its controls can be used. */
export async function openFacetSection(page, key) {
  const search = page.locator(`#view [data-choice-search="${key}"]`);
  await search.waitFor({ state: 'attached', timeout: 12000 });
  const details = search.locator('xpath=ancestor::details[1]');
  if (await details.count() && !(await details.evaluate(element => element.open))) await details.locator('> summary').click();
  await search.waitFor({ state: 'visible', timeout: 8000 });
}
