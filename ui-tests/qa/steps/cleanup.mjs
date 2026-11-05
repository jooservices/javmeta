/** Runs fn when the runner closes the scenario context, including after a failed step. Later jobs run first. */
export function onContextClose(ctx, fn) {
  if (!ctx.state.closeJobs) {
    ctx.state.closeJobs = [];
    const original = ctx.context.close.bind(ctx.context);
    ctx.context.close = async () => {
      const errors = [];
      for (const job of [...ctx.state.closeJobs].reverse()) {
        try { await job(); } catch (error) { errors.push(error); }
      }
      await original();
      if (errors.length) throw errors[0];
    };
  }
  ctx.state.closeJobs.push(fn);
}

/** Owner session PUT. Playwright evaluate accepts one argument, so the payload is a single object. */
export async function putSetting(page, key, value) {
  const status = await page.evaluate(async ({ key, value }) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const res = await fetch('/ui-admin/settings', {
      method: 'PUT',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ settings: { [key]: value } }),
    });
    return res.status;
  }, { key, value });
  if (status !== 200) throw new Error(`Saving ${key} failed with HTTP ${status}.`);
}
