// node --test lib/runner.test.mjs : the runner itself, with fake steps and a fake browser (no real browser needed).
import test from 'node:test';
import assert from 'node:assert/strict';
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
