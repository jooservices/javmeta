import { defineStep } from '../lib/step.mjs';
import { viewText, waitForHash } from './dom.mjs';

const settled = (page, needle) => page.waitForFunction(text => {
  const view = document.querySelector('#view');
  return !!view && !view.querySelector('.loader') && view.innerText.includes(text);
}, needle, { timeout: 12000 }).catch(() => { throw new Error(`The view did not show "${needle}".`); });

export const expectFeedHasNoActions = defineStep('expectFeedHasNoActions', async ctx => {
  await settled(ctx.page, 'Chưa theo dõi gì');
  const text = await viewText(ctx.page);
  if (!text.includes('Bấm “Theo dõi” ở trang diễn viên, maker hoặc series.')) {
    throw new Error('The empty feed does not tell the owner where to follow something.');
  }
  const buttons = await ctx.page.locator('#view button').count();
  if (buttons !== 0) throw new Error(`The empty feed has ${buttons} buttons.`);
});

export const expectLibraryUnconfigured = defineStep('expectLibraryUnconfigured', async ctx => {
  await settled(ctx.page, 'Chưa cấu hình thư mục');
  const text = await viewText(ctx.page);
  if (!text.includes('Mở Cài đặt')) throw new Error('The library page has no settings link.');
});

export const expectDownloadsOff = defineStep('expectDownloadsOff', async ctx => {
  await settled(ctx.page, 'Tải về đang tắt');
  const text = await viewText(ctx.page);
  if (!text.includes('Mở Cài đặt')) throw new Error('The downloads page has no settings link.');
});

export const openSettingsFromEmptyState = defineStep('openSettingsFromEmptyState', async ctx => {
  await ctx.page.locator('#view a.btn', { hasText: 'Mở Cài đặt' }).click();
  await waitForHash(ctx.page, '#/settings');
  await settled(ctx.page, 'chia theo nhóm');
});

export const openSettingsWithKeyboard = defineStep('openSettingsWithKeyboard', async ctx => {
  const link = ctx.page.locator('#view a.btn', { hasText: 'Mở Cài đặt' });
  await link.focus();
  await ctx.page.keyboard.press('Enter');
  await waitForHash(ctx.page, '#/settings');
  await settled(ctx.page, 'chia theo nhóm');
});

export const expectMediaCaptureOff = defineStep('expectMediaCaptureOff', async ctx => {
  await settled(ctx.page, 'Chưa có việc xử lý media.');
  const text = await viewText(ctx.page);
  if (!text.includes('Ghi luồng đang tắt. Bật trong Cài đặt khi bạn quyết định ghi.')) {
    throw new Error('The media page does not say that capture is off.');
  }
  const input = ctx.page.locator('#view [data-capture] input[name=url]');
  const button = ctx.page.locator('#view [data-capture] button');
  if (await input.isEnabled()) throw new Error('The capture URL field is enabled.');
  if (await button.isEnabled()) throw new Error('The capture button is enabled.');
});

export const refreshMedia = defineStep('refreshMedia', async ctx => {
  await ctx.page.locator('#view [data-refresh]').focus();
  await Promise.all([
    ctx.page.waitForEvent('load', { timeout: 12000 }),
    ctx.page.keyboard.press('Enter'),
  ]).catch(() => { throw new Error('Refresh did not reload the media page.'); });
  await settled(ctx.page, 'Chưa có việc xử lý media.');
});

/** A disabled capture form must not queue a job. */
export const submitDisabledCapture = defineStep('submitDisabledCapture', async ctx => {
  const button = ctx.page.locator('#view [data-capture] button');
  if (await button.isEnabled()) throw new Error('The capture button is enabled, so submitting would start a job.');
  await ctx.page.locator('#view [data-capture]').evaluate(form => {
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  });
  const status = (await ctx.page.locator('#view [data-media-message]').innerText()).trim();
  if (/xếp hàng/i.test(status)) throw new Error(`Disabled capture queued work: "${status}".`);
  const text = await viewText(ctx.page);
  if (!text.includes('Chưa có việc xử lý media.')) throw new Error('The media queue changed after a disabled submit.');
});

export const openMediaLibrary = defineStep('openMediaLibrary', async ctx => {
  await ctx.page.locator('#view a.btn', { hasText: 'Chọn tệp' }).click();
  await waitForHash(ctx.page, '#/library');
  await settled(ctx.page, 'Chưa cấu hình thư mục');
});

export const expectCrawlReadOnly = defineStep('expectCrawlReadOnly', async ctx => {
  await settled(ctx.page, 'Crawl theo nguồn');
  const text = await viewText(ctx.page);
  for (const needle of ['520', '210', '0 / 0', 'nguồn ổn', 'việc chờ']) {
    if (!text.includes(needle)) throw new Error(`Crawl does not show "${needle}".`);
  }
  if (/crawler:source-toggle|php artisan|JOOXCR-\d+/.test(text)) throw new Error('The crawl page shows operator-only instructions.');
  const buttons = await ctx.page.locator('#view button, #view a.btn').count();
  if (buttons !== 0) throw new Error(`The read-only crawl page has ${buttons} actions.`);
});
