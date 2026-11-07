import { defineStep } from '../lib/step.mjs';
import { onContextClose, putSetting } from './cleanup.mjs';
import { viewText } from './dom.mjs';

const versionOf = async page => Number(((await page.locator('[data-status]').innerText().catch(() => '')).match(/Phiên bản (\d+)/) || [])[1] || 0);

export const expectReportPreview = defineStep('expectReportPreview', async ctx => {
  await ctx.page.waitForFunction(() => (document.querySelector('[data-report-preview]')?.textContent || '').includes('[XCrawler] Report'), null, { timeout: 12000 })
    .catch(() => { throw new Error('The report preview is not the XCrawler report.'); });
  const text = await viewText(ctx.page);
  if (!text.includes('Chưa có báo cáo nào được gửi.')) throw new Error('The report page does not say that nothing has been sent.');
  if (!text.includes('Chưa có lịch sử gửi.')) throw new Error('The report page does not say that the history is empty.');
  const send = ctx.page.locator('#view [data-report-send]');
  if (!(await send.isVisible()) || !(await send.innerText()).includes('Gửi ngay')) throw new Error('The Telegram send button is not shown.');
});

/** Refreshes the preview from the keyboard. It does not send the report. */
export const refreshReportPreview = defineStep('refreshReportPreview', async ctx => {
  await ctx.page.locator('#view [data-report-refresh]').focus();
  await ctx.page.keyboard.press('Enter');
  await ctx.page.waitForFunction(() => (document.querySelector('[data-report-status]')?.textContent || '').includes('Đã cập nhật bản xem trước.'), null, { timeout: 8000 })
    .catch(() => { throw new Error('Refresh did not update the preview status.'); });
  const preview = await ctx.page.locator('[data-report-preview]').innerText();
  if (!preview.includes('[XCrawler] Report')) throw new Error('Refresh cleared the report preview.');
});

async function reportBox(page) {
  const box = page.locator('#view input[data-key="report.enabled"]');
  await box.waitFor({ state: 'attached', timeout: 8000 });
  return box;
}

/** The checkbox itself is hidden; the label is the control a person clicks. */
async function clickSwitch(page, box) {
  const label = box.locator('xpath=ancestor::label[1]');
  await label.scrollIntoViewIfNeeded();
  await label.click();
}

async function flipReport(ctx, how) {
  const box = await reportBox(ctx.page);
  const before = await box.isChecked();
  if (ctx.state.reportEnabledBefore === undefined) ctx.state.reportEnabledBefore = before;
  if (how === 'keyboard') {
    await box.focus();
    await ctx.page.keyboard.press('Space');
  } else await clickSwitch(ctx.page, box);
  const after = await box.isChecked();
  if (after === before) throw new Error('The report switch did not toggle.');
  ctx.state.reportEnabledNow = after;
}

/** Clicks the periodic-report switch. The change is local until a later save. */
export const toggleReportEnabled = defineStep('toggleReportEnabled', async ctx => {
  await flipReport(ctx, 'click');
});

/** Space toggles the same switch from the keyboard. */
export const toggleReportEnabledWithKeyboard = defineStep('toggleReportEnabledWithKeyboard', async ctx => {
  await flipReport(ctx, 'keyboard');
});

export const expectReportSwitchLabel = defineStep('expectReportSwitchLabel', async ctx => {
  const box = await reportBox(ctx.page);
  const on = await box.isChecked();
  const label = await box.evaluate(input => input.parentElement?.querySelector('.txt')?.textContent?.trim() || '');
  const expected = on ? 'Bật' : 'Tắt';
  if (label !== expected) throw new Error(`The report switch is ${on ? 'on' : 'off'} but its label says "${label}".`);
});

export const saveReportSettings = defineStep('saveReportSettings', async ctx => {
  if (ctx.state.reportEnabledBefore !== undefined && !ctx.state.reportCleanup) {
    ctx.state.reportCleanup = true;
    onContextClose(ctx, async () => {
      await putSetting(ctx.page, 'report.enabled', ctx.state.reportEnabledBefore);
    });
  }
  await ctx.page.locator('#view [data-report-save]').click();
  const confirmed = await ctx.page.waitForFunction(() => (document.querySelector('[data-report-status]')?.textContent || '').includes('Đã lưu cài đặt.'), null, { timeout: 8000 }).then(() => true, () => false);
  if (!confirmed) {
    const status = (await ctx.page.locator('[data-report-status]').innerText().catch(() => '')).replace(/\s+/g, ' ').trim();
    throw new Error(status ? `Saving the report settings failed: ${status}` : 'Saving the report settings did not confirm.');
  }
});

export const expectReportStartsEnabled = defineStep('expectReportStartsEnabled', async ctx => {
  const box = await reportBox(ctx.page);
  if (!(await box.isChecked())) throw new Error('The report switch is off before the scenario toggles it.');
});

export const expectReportSwitchInView = defineStep('expectReportSwitchInView', async ctx => {
  const label = ctx.page.locator('#view label.switch').filter({ has: ctx.page.locator('input[data-key="report.enabled"]') });
  const box = await label.boundingBox();
  const width = ctx.page.viewportSize().width;
  if (!box || box.width < 8) throw new Error('The report switch has no box.');
  if (box.x < 0 || box.x + box.width > width + 1) {
    throw new Error(`The report switch runs from ${Math.round(box.x)} to ${Math.round(box.x + box.width)} in a ${width}px wide window.`);
  }
});

export const expectReportEnabledSaved = defineStep('expectReportEnabledSaved', async ctx => {
  const on = ctx.state.reportEnabledNow;
  await ctx.page.reload({ waitUntil: 'load' });
  // Restore the report hash after reload, then wait for the hidden switch like reportBox().
  await ctx.page.evaluate(() => { if (!location.hash.includes('settings/report')) location.hash = '#/settings/report'; });
  const box = ctx.page.locator('#view input[data-key="report.enabled"]');
  await box.waitFor({ state: 'attached', timeout: 12000 });
  const checked = await box.isChecked();
  if (checked !== on) throw new Error(`After a reload the report switch is ${checked ? 'on' : 'off'}, expected ${on ? 'on' : 'off'}.`);
});

export const expectNotificationList = defineStep('expectNotificationList', async ctx => {
  await ctx.page.waitForSelector('.notifications-list tbody tr', { timeout: 12000 });
  const info = await ctx.page.evaluate(() => {
    const rows = [...document.querySelectorAll('.notifications-list tbody tr')];
    const chip = document.querySelector('.notifications-list .chip')?.textContent?.trim() || '';
    return {
      rows: rows.length,
      chip,
      key: rows[0]?.querySelector('[data-key]')?.getAttribute('data-key') || '',
      enabled: rows[0]?.querySelector('td:nth-child(4)')?.textContent?.trim() || '',
      states: rows.map(row => row.querySelector('td:nth-child(4)')?.textContent?.trim() || ''),
    };
  });
  if (info.rows < 1) throw new Error('The notification list is empty.');
  if (String(info.rows) !== info.chip) throw new Error(`The list chip says ${info.chip} but there are ${info.rows} rows.`);
  if (info.key !== 'crawl.success_rate_low') throw new Error(`The first notification is "${info.key}".`);
  if (info.enabled !== 'Bật') throw new Error(`The first notification is "${info.enabled}", expected Bật.`);
  if (!info.states.includes('Bật') || !info.states.includes('Tắt')) throw new Error('The list does not show both enabled and disabled rows.');
  ctx.state.notificationRows = info.rows;
});

export const rememberNotificationForm = defineStep('rememberNotificationForm', async ctx => {
  const key = await ctx.page.locator('[data-action=edit]').first().getAttribute('data-key');
  const original = await ctx.page.evaluate(() => {
    const form = document.querySelector('[data-editor] form');
    const value = name => form.elements[name].value;
    const checked = name => form.elements[name].checked;
    return {
      title_template: value('title_template'),
      body_template: value('body_template'),
      enabled: checked('enabled'),
      default_severity: value('default_severity'),
      tier: value('tier'),
      cooldown_minutes: Number(value('cooldown_minutes')),
      escalation_minutes: Number(value('escalation_minutes')),
      include_in_periodic_report: checked('include_in_periodic_report'),
      include_in_daily_report: checked('include_in_daily_report'),
      send_resolved: checked('send_resolved'),
    };
  });
  ctx.state.notificationKey = key;
  ctx.state.notificationOriginal = original;
  if (!ctx.state.notificationCleanup) {
    ctx.state.notificationCleanup = true;
    onContextClose(ctx, async () => {
      if (!ctx.state.notificationDirty) return;
      const status = await ctx.page.evaluate(async ({ key, body }) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const res = await fetch('/ui-admin/notifications/' + encodeURIComponent(key), {
          method: 'PUT', credentials: 'same-origin',
          headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
          body: JSON.stringify(body),
        });
        return res.status;
      }, { key: ctx.state.notificationKey, body: ctx.state.notificationOriginal });
      if (status !== 200) throw new Error(`Restoring the notification failed with HTTP ${status}.`);
      ctx.state.notificationDirty = false;
    });
  }
});

export const toggleNotificationEnabled = defineStep('toggleNotificationEnabled', async ctx => {
  const box = ctx.page.locator('[data-editor] input[name=enabled]');
  await box.waitFor({ state: 'attached', timeout: 8000 });
  const before = await box.isChecked();
  await clickSwitch(ctx.page, box);
  if (await box.isChecked() === before) throw new Error('The notification enabled switch did not toggle.');
});

export const expectNotificationSwitchLabel = defineStep('expectNotificationSwitchLabel', async ctx => {
  const info = await ctx.page.locator('[data-editor] input[name=enabled]').evaluate(input => ({
    on: input.checked,
    label: input.parentElement?.querySelector('.txt')?.textContent?.trim() || '',
  }));
  const expected = info.on ? 'Đang bật' : 'Đang tắt';
  if (info.label !== expected) throw new Error(`The notification switch is ${info.on ? 'on' : 'off'} but its label says "${info.label}".`);
});

export const saveNotificationEdits = defineStep('saveNotificationEdits', async ctx => {
  const before = await versionOf(ctx.page);
  ctx.state.notificationDirty = true;
  await ctx.page.locator('[data-editor] [data-action=save]').click();
  await ctx.page.waitForFunction(previous => {
    const text = document.querySelector('[data-status]')?.textContent || '';
    const now = Number((text.match(/Phiên bản (\d+)/) || [])[1] || 0);
    return now > previous;
  }, before, { timeout: 8000 }).catch(() => { throw new Error(`Saving the notification did not move the version past ${before}.`); });
});

export const expectFirstNotificationEnabled = defineStep('expectFirstNotificationEnabled', async (ctx, on) => {
  const expected = on ? 'Bật' : 'Tắt';
  await ctx.page.waitForFunction(label => document.querySelector('.notifications-list tbody tr td:nth-child(4)')?.textContent?.trim() === label, expected, { timeout: 8000 })
    .catch(() => { throw new Error(`The first notification is not "${expected}".`); });
});

export const markNotificationRestored = defineStep('markNotificationRestored', async ctx => {
  ctx.state.notificationDirty = false;
});

export const toggleQuietMode = defineStep('toggleQuietMode', async ctx => {
  const box = ctx.page.locator('#view [data-quiet-mode]');
  await box.waitFor({ state: 'attached', timeout: 8000 });
  const before = await box.isChecked();
  if (!ctx.state.quietCleanup) {
    ctx.state.quietBefore = before;
    ctx.state.quietCleanup = true;
    onContextClose(ctx, async () => {
      await putSetting(ctx.page, 'notifications.quiet_mode', ctx.state.quietBefore);
    });
  }
  const want = !before;
  await clickSwitch(ctx.page, box);
  await ctx.page.waitForFunction(expected => {
    const input = document.querySelector('[data-quiet-mode]');
    const status = document.querySelector('[data-quiet-status]')?.textContent || '';
    return !!input && input.checked === expected && status.includes(expected ? 'Đã bật chế độ yên lặng.' : 'Đã tắt chế độ yên lặng.');
  }, want, { timeout: 8000 }).catch(() => { throw new Error('Quiet mode did not save the new state.'); });
});
