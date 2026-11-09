import { defineStep } from '../lib/step.mjs';
import { onContextClose, putSetting } from './cleanup.mjs';
import { viewText } from './dom.mjs';

/** Opens a sample movie that has a magnet (every 10th sample code). */
export const openMovieWithDownloads = defineStep('openMovieWithDownloads', async ctx => {
  const code = 'SMP-0010';
  await ctx.page.goto(ctx.base + '/app#/movie/' + encodeURIComponent(code), { waitUntil: 'load' });
  await ctx.page.waitForFunction(c => (document.querySelector('#view h1')?.innerText || '').length > 0 && (document.querySelector('#view')?.innerText || '').includes(c), code, { timeout: 15000 })
    .catch(() => { throw new Error(`Movie ${code} did not open.`); });
  ctx.state.movieCode = code;
});

/** Opens a sample movie that has no magnet yet. */
export const openMovieWithoutDownloads = defineStep('openMovieWithoutDownloads', async ctx => {
  const code = 'SMP-0003';
  await ctx.page.goto(ctx.base + '/app#/movie/' + encodeURIComponent(code), { waitUntil: 'load' });
  await ctx.page.waitForFunction(c => (document.querySelector('#view')?.innerText || '').includes(c), code, { timeout: 15000 })
    .catch(() => { throw new Error(`Movie ${code} did not open.`); });
  ctx.state.movieCode = code;
});

export const expectDownloadsPanelWithLinks = defineStep('expectDownloadsPanelWithLinks', async ctx => {
  const panel = ctx.page.locator('#movie-downloads');
  await panel.waitFor({ state: 'visible', timeout: 8000 });
  const text = (await panel.innerText()).replace(/\s+/g, ' ');
  if (!/Có .* link tải|download link/i.test(text)) throw new Error(`Downloads panel status is "${text.slice(0, 120)}".`);
  if (!text.includes('magnet:?')) throw new Error('Downloads panel does not list a magnet URL.');
  const copy = await panel.locator('button[data-act=copy]').count();
  if (copy < 1) throw new Error('Downloads panel has no copy-magnet button.');
});

export const expectDownloadsPanelEmptyWithSearch = defineStep('expectDownloadsPanelEmptyWithSearch', async ctx => {
  const panel = ctx.page.locator('#movie-downloads');
  await panel.waitFor({ state: 'visible', timeout: 8000 });
  const text = (await panel.innerText()).replace(/\s+/g, ' ');
  if (!/Chưa có link|No download links/i.test(text)) throw new Error(`Empty downloads panel says "${text.slice(0, 120)}".`);
  const find = panel.locator('[data-download-search]');
  if (await find.count() !== 1) throw new Error('Empty downloads panel has no Find download links button.');
});

/** Enables qBittorrent controls only in the throw-away QA database and restores the original setting on every exit. */
export const enableQbForOwner = defineStep('enableQbForOwner', async ctx => {
  if (!ctx.state.downloadsEnabledCaptured) {
    ctx.state.downloadsEnabledBefore = await ctx.page.evaluate(async () => {
      const response = await fetch('/ui-admin/settings', { headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error(`Reading download settings failed with HTTP ${response.status}.`);
      const body = await response.json();
      return body.data?.settings?.find(setting => setting.key === 'downloads.enabled')?.value;
    });
    if (typeof ctx.state.downloadsEnabledBefore !== 'boolean') throw new Error('The downloads.enabled setting could not be read.');
    ctx.state.downloadsEnabledCaptured = true;
    ctx.state.downloadsEnabledRestore = true;
    onContextClose(ctx, async () => {
      if (ctx.state.downloadsEnabledRestore) {
        await putSetting(ctx.page, 'downloads.enabled', ctx.state.downloadsEnabledBefore);
      }
    });
  }
  await putSetting(ctx.page, 'downloads.enabled', true);
});

export const restoreQbForOwner = defineStep('restoreQbForOwner', async ctx => {
  if (!ctx.state.downloadsEnabledRestore) return;
  await putSetting(ctx.page, 'downloads.enabled', ctx.state.downloadsEnabledBefore);
  ctx.state.downloadsEnabledRestore = false;
});

export const expectOwnerCanSendToQbittorrent = defineStep('expectOwnerCanSendToQbittorrent', async ctx => {
  const actions = ctx.page.locator('#movie-downloads button[data-dl]');
  if (await actions.count() < 1) throw new Error('The owner does not see the qBittorrent action for a listed download link.');
  const label = await actions.first().getAttribute('aria-label');
  if (!/Gửi tới qBittorrent|Send to qBittorrent/i.test(label || '')) {
    throw new Error(`The owner qBittorrent action has an unexpected accessible name: "${label || ''}".`);
  }
});

export const startDownloadSearch = defineStep('startDownloadSearch', async ctx => {
  const button = ctx.page.locator('#movie-downloads [data-download-search]');
  await button.click();
  await ctx.page.waitForFunction(() => {
    const panel = document.querySelector('#movie-downloads');
    const text = panel?.innerText || '';
    return /Đang tìm|Searching|Không tìm thấy|No links found|Có .* link|download link/i.test(text);
  }, null, { timeout: 20000 }).catch(async () => {
    throw new Error(`Download search did not update the panel. View: ${(await viewText(ctx.page)).slice(0, 200)}`);
  });
});
