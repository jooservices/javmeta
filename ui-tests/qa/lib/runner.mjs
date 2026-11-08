import { label } from './step.mjs';
import { openBrowser } from './context.mjs';

/** Runs every scenario of a suite step by step. A failing step stops its scenario (later steps depend on it), saves a screenshot and the run goes on with the next scenario. */
export async function runSuite(suite, options = {}, open = openBrowser) {
  const env = await open(options);
  const results = [];
  for (const scenario of suite.scenarios) {
    const ctx = await env.newScenarioContext(scenario.viewport);
    const record = { scenario: scenario.name, steps: [], ok: true };
    for (const step of scenario.steps) {
      const started = Date.now();
      try {
        await step.run(ctx);
        record.steps.push({ step: label(step), ok: true, ms: Date.now() - started });
      } catch (error) {
        record.ok = false;
        const file = `${env.shots}/FAIL-${scenario.name.replace(/\W+/g, '_')}.png`;
        await ctx.page.screenshot({ path: file }).catch(() => {});
        record.steps.push({ step: label(step), ok: false, ms: Date.now() - started, error: String(error.message || error).split('\n')[0].slice(0, 300), screenshot: file });
        break;
      }
    }
    await ctx.context.close();
    results.push(record);
  }
  await env.close();
  return results;
}

export function report(suite, results) {
  console.log(`Suite: ${suite.name}`);
  for (const r of results) {
    console.log(`${r.ok ? 'PASS' : 'FAIL'}  ${r.scenario}`);
    for (const s of r.steps) console.log(`   ${s.ok ? '✓' : '✗'} ${s.step} (${s.ms} ms)${s.error ? '\n      ' + s.error : ''}${s.screenshot ? '\n      screenshot: ' + s.screenshot : ''}`);
  }
  const failed = results.filter(r => !r.ok).length;
  console.log(`\n${results.length - failed}/${results.length} scenarios passed, ${results.reduce((n, r) => n + r.steps.length, 0)} steps run`);
  return failed === 0;
}
