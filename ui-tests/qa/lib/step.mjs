// A step is one reusable action. defineStep('name', async (ctx, ...args) => {...}) returns a factory; calling the
// factory with arguments gives a step object the runner can execute. Scenarios only list steps, they never re-implement them.
export function defineStep(name, run) {
  return (...args) => ({ name, args, run: ctx => run(ctx, ...args) });
}

export function defineScenario(name, steps) {
  return { name, steps };
}

export function defineSuite(name, scenarios) {
  return { name, scenarios };
}

export function label(step) {
  const shown = step.args.map(a => (typeof a === 'string' ? a : JSON.stringify(a))).join(', ');
  return shown ? `${step.name}(${shown})` : step.name;
}
