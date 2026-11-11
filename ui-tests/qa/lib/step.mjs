// A step is one reusable action. defineStep('name', async (ctx, ...args) => {...}) returns a factory; calling the
// factory with arguments gives a step object the runner can execute. Scenarios only list steps, they never re-implement them.
const stepGroupMarker = Symbol('qa-step-group');

export function defineStep(name, run) {
  return (...args) => ({ name, args, run: ctx => run(ctx, ...args) });
}

export function defineScenario(name, steps) {
  return { name, steps };
}

/** Defines a named, reusable group of existing steps without changing legacy suite/scenario shapes. */
export function defineStepGroup(name, steps) {
  if (typeof name !== 'string' || name.length === 0) {
    throw new TypeError('A step group needs a non-empty name.');
  }
  if (!Array.isArray(steps) || steps.some(step =>
    !step || typeof step.name !== 'string' || !Array.isArray(step.args) || typeof step.run !== 'function')) {
    throw new TypeError(`Step group ${JSON.stringify(name)} must contain executable steps.`);
  }

  const stableSteps = steps.map(step => Object.freeze({
    name: step.name,
    args: Object.freeze([...step.args]),
    run: step.run,
  }));

  return Object.freeze({
    [stepGroupMarker]: true,
    name,
    steps: Object.freeze(stableSteps),
  });
}

/** Adapts named step groups to the runner's existing scenario shape, preserving group and step order. */
export function defineScenarioFromGroups(name, groups) {
  if (!Array.isArray(groups) || groups.some(group => !group || group[stepGroupMarker] !== true)) {
    throw new TypeError('A composed scenario must contain groups created by defineStepGroup().');
  }

  return defineScenario(name, groups.flatMap(group => group.steps));
}

export function defineSuite(name, scenarios) {
  return { name, scenarios };
}

export function label(step) {
  const shown = step.args.map(a => (typeof a === 'string' ? a : JSON.stringify(a))).join(', ');
  return shown ? `${step.name}(${shown})` : step.name;
}
