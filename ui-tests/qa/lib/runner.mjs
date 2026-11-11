import { label } from './step.mjs';
import { openBrowser } from './context.mjs';

/** Runs the selected scenarios step by step. A failing step stops its scenario (later steps depend on it), saves a screenshot and the run goes on with the next scenario. */
async function runScenarios(scenarios, options, open) {
  const env = await open(options);
  const results = [];
  for (const scenario of scenarios) {
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

/** Runs every scenario in a suite. */
export async function runSuite(suite, options = {}, open = openBrowser) {
  return runScenarios(suite.scenarios, options, open);
}

/** Resolve a suite, scenario, or step name without starting the browser. */
export function selectScenarios(suite, selection = { kind: 'suite' }) {
  if (selection.kind === 'suite') return suite.scenarios;

  const name = selection.name;
  if (typeof name !== 'string' || name.length === 0) {
    throw new Error(`A non-empty ${selection.kind} name is required.`);
  }

  if (selection.kind === 'scenario') {
    const matches = suite.scenarios.filter(scenario => scenario.name === name);
    if (matches.length === 0) throw new Error(`Unknown scenario ${JSON.stringify(name)}.`);
    if (matches.length > 1) throw new Error(`Ambiguous scenario ${JSON.stringify(name)}.`);
    return matches;
  }

  if (selection.kind === 'step') {
    const matches = suite.scenarios.flatMap(scenario =>
      scenario.steps.filter(step => step.name === name).map(step => ({ scenario, step })));
    if (matches.length === 0) throw new Error(`Unknown step ${JSON.stringify(name)}.`);
    if (matches.length > 1) throw new Error(`Ambiguous step ${JSON.stringify(name)}.`);
    const [{ scenario, step }] = matches;
    return [{ ...scenario, steps: [step] }];
  }

  throw new Error(`Unknown selection type ${JSON.stringify(selection.kind)}.`);
}

/** Resolves the selector before opening a browser, then runs only its scenarios or step. */
export async function runSelection(suite, selection, options = {}, open = openBrowser) {
  const scenarios = selectScenarios(suite, selection);
  return runScenarios(scenarios, options, open);
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
