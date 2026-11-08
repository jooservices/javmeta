// node --test lib/runner.test.mjs : the runner itself, with fake steps and a fake browser (no real browser needed).
import test from 'node:test';
import assert from 'node:assert/strict';
import { defineStep, defineScenario, defineSuite, label } from './step.mjs';
import { runSuite, report } from './runner.mjs';

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
