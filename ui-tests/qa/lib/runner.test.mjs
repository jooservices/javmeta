// node --test lib/runner.test.mjs : the runner itself, with fake steps and a fake browser (no real browser needed).
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createBrowserEnvironment, openBrowser } from './context.mjs';
import { defineStep, defineScenario, defineSuite, label } from './step.mjs';
import { runSelection, runSuite, report } from './runner.mjs';
import { runCommand, parseArguments } from '../run.mjs';

const fakeEnv = () => {
  const shots = [];
  return {
    shots: '/tmp/qa-runner-test',
    shotsTaken: shots,
    async newScenarioContext() { return { page: { screenshot: async ({ path }) => { shots.push(path); } }, context: { close: async () => {} }, issues: [], state: {} }; },
    close: async () => {},
  };
};

test('a scenario runs its steps in order and a step factory keeps its arguments', async () => {
  const order = [];
  const record = defineStep('record', async (ctx, text) => { order.push(text); });
  const suite = defineSuite('s', [defineScenario('one', [record('a'), record('b')])]);

  const results = await runSuite(suite, {}, async () => fakeEnv());

  assert.deepEqual(order, ['a', 'b']);
  assert.equal(results[0].ok, true);
  assert.equal(label(record('a')), 'record(a)');
  assert.equal(label(defineStep('plain', async () => {})()), 'plain');
});

test('the same step is reused by several scenarios without being copied', async () => {
  let runs = 0;
  const login = defineStep('login', async () => { runs += 1; });
  const suite = defineSuite('s', [defineScenario('a', [login()]), defineScenario('b', [login(), login()])]);

  await runSuite(suite, {}, async () => fakeEnv());

  assert.equal(runs, 3);
});

test('partial scenario setup closes its context and the acquired browser', async () => {
  let contextCloses = 0;
  let browserCloses = 0;
  const context = {
    async newPage() { throw new Error('page setup failed'); },
    async close() { contextCloses += 1; },
  };
  const browser = {
    async newContext() { return context; },
    async close() { browserCloses += 1; },
  };
  const env = createBrowserEnvironment(browser, {
    base: 'http://localhost', email: 'qa@example.test', password: 'test-only', shots: '/tmp/qa-runner-test',
  });
  const suite = defineSuite('s', [defineScenario('setup', [])]);

  await assert.rejects(runSuite(suite, {}, async () => env), /page setup failed/);

  assert.equal(contextCloses, 1);
  assert.equal(browserCloses, 1);
});

test('a missing password file fails before Chromium is launched', async () => {
  const previousPasswordFile = process.env.QA_PASSWORD_FILE;
  process.env.QA_PASSWORD_FILE = `/tmp/qa-runner-missing-password-${process.pid}`;
  let launchCalls = 0;
  try {
    await assert.rejects(
      openBrowser({ shots: '/tmp/qa-runner-test' }, async () => { launchCalls += 1; }),
      /ENOENT/,
    );
  } finally {
    if (previousPasswordFile === undefined) delete process.env.QA_PASSWORD_FILE;
    else process.env.QA_PASSWORD_FILE = previousPasswordFile;
  }

  assert.equal(launchCalls, 0);
});

test('a failed step still closes its scenario context and browser', async () => {
  let contextCloses = 0;
  let browserCloses = 0;
  const fail = defineStep('fail', async () => { throw new Error('expected step failure'); });
  const suite = defineSuite('s', [defineScenario('broken', [fail()])]);
  const env = {
    shots: '/tmp/qa-runner-test',
    async newScenarioContext() {
      return {
        page: { screenshot: async () => {} },
        context: { close: async () => { contextCloses += 1; } },
      };
    },
    async close() { browserCloses += 1; },
  };

  const results = await runSuite(suite, {}, async () => env);

  assert.equal(results[0].ok, false);
  assert.equal(contextCloses, 1);
  assert.equal(browserCloses, 1);
});

test('a context close failure preserves its useful error and still closes the browser', async () => {
  let browserCloses = 0;
  const fail = defineStep('fail', async () => { throw new Error('expected step failure'); });
  const suite = defineSuite('s', [defineScenario('broken', [fail()])]);
  const env = {
    shots: '/tmp/qa-runner-test',
    async newScenarioContext() {
      return {
        page: { screenshot: async () => {} },
        context: { close: async () => { throw new Error('context close failed'); } },
      };
    },
    async close() { browserCloses += 1; },
  };

  await assert.rejects(runSuite(suite, {}, async () => env), error => {
    assert.match(error.message, /expected step failure/);
    assert.match(error.message, /context close failed/);
    return true;
  });

  assert.equal(browserCloses, 1);
});

test('a browser setup failure closes acquired resources and lets the CLI process terminate', () => {
  const runModule = new URL('../run.mjs', import.meta.url).href;
  const stepModule = new URL('./step.mjs', import.meta.url).href;
  const script = `
    import { runCommand } from ${JSON.stringify(runModule)};
    import { defineScenario, defineStep, defineSuite } from ${JSON.stringify(stepModule)};
    const suite = defineSuite('main', [defineScenario('setup', [defineStep('unused', async () => {})()])]);
    const handle = setInterval(() => {}, 1000);
    const open = async () => ({
      async newScenarioContext() { throw new Error('context setup failed'); },
      async close() { clearInterval(handle); },
    });
    process.exitCode = await runCommand([], {
      loaders: { main: async () => ({ default: suite }) },
      open,
    });
  `;
  const result = spawnSync(process.execPath, ['--input-type=module', '-e', script], {
    encoding: 'utf8', timeout: 5000,
  });

  assert.equal(result.error, undefined, result.error?.message);
  assert.equal(result.status, 2, result.stderr);
  assert.match(result.stderr, /context setup failed/);
});

test('a failing step stops its scenario, saves a screenshot, and the next scenario still runs', async () => {
  const env = fakeEnv();
  const ok = defineStep('ok', async () => {});
  const boom = defineStep('boom', async () => { throw new Error('the page is wrong\nsecond line'); });
  const never = defineStep('never', async () => { throw new Error('must not run'); });
  const suite = defineSuite('s', [defineScenario('broken', [ok(), boom(), never()]), defineScenario('fine', [ok()])]);

  const results = await runSuite(suite, {}, async () => env);

  assert.equal(results[0].ok, false);
  assert.deepEqual(results[0].steps.map(s => [s.step, s.ok]), [['ok', true], ['boom', false]]);
  assert.equal(results[0].steps[1].error, 'the page is wrong');
  assert.equal(env.shotsTaken.length, 1);
  assert.match(env.shotsTaken[0], /FAIL-broken\.png$/);
  assert.equal(results[1].ok, true);
});

test('the report says whether the whole suite passed', async () => {
  const ok = defineStep('ok', async () => {});
  const boom = defineStep('boom', async () => { throw new Error('x'); });
  const lines = [];
  const original = console.log;
  console.log = message => lines.push(String(message));
  try {
    const good = defineSuite('good', [defineScenario('a', [ok()])]);
    const bad = defineSuite('bad', [defineScenario('a', [boom()])]);
    assert.equal(report(good, await runSuite(good, {}, async () => fakeEnv())), true);
    assert.equal(report(bad, await runSuite(bad, {}, async () => fakeEnv())), false);
  } finally { console.log = original; }
  assert.ok(lines.some(l => l.includes('1/1 scenarios passed')));
  assert.ok(lines.some(l => l.includes('0/1 scenarios passed')));
});

test('a unique scenario selector runs only that scenario through the CLI command', async () => {
  const order = [];
  const record = defineStep('record', async (_ctx, value) => { order.push(value); });
  const suite = defineSuite('s', [
    defineScenario('target', [record('target')]),
    defineScenario('other', [record('other')]),
  ]);
  const output = [];
  const original = console.log;
  console.log = message => output.push(String(message));
  try {
    const exitCode = await runCommand(['--scenario', 'target'], {
      loaders: { main: async () => ({ default: suite }) },
      open: async () => fakeEnv(),
      writeError: message => output.push(String(message)),
    });
    assert.equal(exitCode, 0);
  } finally { console.log = original; }

  assert.deepEqual(order, ['target']);
  assert.ok(output.some(line => line.includes('1/1 scenarios passed')));
});

test('a unique step selector runs only that registered step', async () => {
  const order = [];
  const record = defineStep('record', async (_ctx, value) => { order.push(value); });
  const suite = defineSuite('s', [
    defineScenario('target', [record('before'), defineStep('selected', async () => { order.push('selected'); })(), record('after')]),
    defineScenario('other', [record('other')]),
  ]);

  const results = await runSelection(suite, { kind: 'step', name: 'selected' }, {}, async () => fakeEnv());

  assert.deepEqual(order, ['selected']);
  assert.deepEqual(results.map(result => result.steps.map(step => step.step)), [['selected']]);
});

test('a failed selected scenario returns failure and does not run another scenario', async () => {
  const order = [];
  const fail = defineStep('fail', async () => { order.push('fail'); throw new Error('expected failure'); });
  const record = defineStep('record', async (_ctx, value) => { order.push(value); });
  const suite = defineSuite('s', [
    defineScenario('target', [fail(), record('after')]),
    defineScenario('other', [record('other')]),
  ]);
  const output = [];
  const original = console.log;
  console.log = message => output.push(String(message));
  try {
    const exitCode = await runCommand(['--scenario', 'target'], {
      loaders: { main: async () => ({ default: suite }) },
      open: async () => fakeEnv(),
      writeError: message => output.push(String(message)),
    });
    assert.equal(exitCode, 1);
  } finally { console.log = original; }

  assert.deepEqual(order, ['fail']);
  assert.ok(output.some(line => line.includes('0/1 scenarios passed')));
});

test('unknown or ambiguous scenario and step selectors fail before opening a browser', async () => {
  const noop = defineStep('noop', async () => {});
  const suite = defineSuite('s', [
    defineScenario('duplicate', [noop()]),
    defineScenario('duplicate', [defineStep('reused', async () => {})()]),
    defineScenario('other', [defineStep('reused', async () => {})()]),
  ]);
  const cases = [
    { args: ['--scenario', 'missing'], message: /Unknown scenario/ },
    { args: ['--scenario', 'duplicate'], message: /Ambiguous scenario/ },
    { args: ['--step', 'missing'], message: /Unknown step/ },
    { args: ['--step', 'reused'], message: /Ambiguous step/ },
  ];

  for (const { args, message } of cases) {
    let browserCalls = 0;
    const errors = [];
    const exitCode = await runCommand(args, {
      loaders: { main: async () => ({ default: suite }) },
      open: async () => { browserCalls += 1; return fakeEnv(); },
      writeError: error => errors.push(String(error)),
    });

    assert.equal(exitCode, 2);
    assert.equal(browserCalls, 0);
    assert.match(errors[0], message);
  }
});

test('the suite positional form stays compatible and unknown suite paths are not imported', async () => {
  assert.deepEqual(parseArguments([]), { suiteName: 'main', selection: { kind: 'suite' } });
  assert.deepEqual(parseArguments(['main']), { suiteName: 'main', selection: { kind: 'suite' } });
  assert.deepEqual(parseArguments(['main', '--scenario', 'target']), {
    suiteName: 'main', selection: { kind: 'scenario', name: 'target' },
  });

  let loaderCalls = 0;
  let browserCalls = 0;
  const errors = [];
  const exitCode = await runCommand(['../../outside'], {
    loaders: { main: async () => { loaderCalls += 1; return { default: defineSuite('s', []) }; } },
    open: async () => { browserCalls += 1; return fakeEnv(); },
    writeError: error => errors.push(String(error)),
  });

  assert.equal(exitCode, 2);
  assert.equal(loaderCalls, 0);
  assert.equal(browserCalls, 0);
  assert.match(errors[0], /Unknown suite/);
});

test('the documented step selector resolves from the registered main suite', async () => {
  const routes = [];
  const env = fakeEnv();
  env.newScenarioContext = async () => ({
    page: { route: async pattern => { routes.push(pattern); } },
    context: { close: async () => {} }, issues: [], state: {},
  });
  const output = [];
  const original = console.log;
  console.log = message => output.push(String(message));
  try {
    const exitCode = await runCommand(['--step', 'mockOperationsDashboardData'], {
      open: async () => env,
      writeError: message => output.push(String(message)),
    });
    assert.equal(exitCode, 0);
  } finally { console.log = original; }

  assert.deepEqual(routes, ['**/ops/data**', '**/ops/nodes**']);
  assert.ok(output.some(line => line.includes('1/1 scenarios passed')));
});
