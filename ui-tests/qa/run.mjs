// Usage: node run.mjs [suite-name] [--scenario name | --step name] (suite defaults to main).
// Selectors are resolved against the loaded suite before the browser is opened.
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { report, runSelection } from './lib/runner.mjs';

const suites = Object.freeze({
  main: () => import('./suites/main.mjs'),
});

export function parseArguments(args) {
  let suiteName = 'main';
  let index = 0;
  if (args.length > 0 && !args[0].startsWith('--')) {
    suiteName = args[0];
    index = 1;
  }

  let selection = { kind: 'suite' };
  while (index < args.length) {
    const kind = args[index].slice(2);
    if (args[index] !== '--scenario' && args[index] !== '--step') {
      throw new Error(`Unknown option ${JSON.stringify(args[index])}.`);
    }
    if (selection.kind !== 'suite') {
      throw new Error('Choose only one of --scenario or --step.');
    }
    const name = args[index + 1];
    if (typeof name !== 'string' || name.length === 0 || name.startsWith('--')) {
      throw new Error(`A name must follow --${kind}.`);
    }
    selection = { kind, name };
    index += 2;
  }

  return { suiteName, selection };
}

export async function runCommand(args, dependencies = {}) {
  const writeError = dependencies.writeError ?? (message => console.error(message));
  try {
    const { suiteName, selection } = parseArguments(args);
    const loaders = dependencies.loaders ?? suites;
    const loadSuite = Object.hasOwn(loaders, suiteName) ? loaders[suiteName] : undefined;
    if (typeof loadSuite !== 'function') {
      throw new Error(`Unknown suite ${JSON.stringify(suiteName)}.`);
    }
    const { default: suite } = await loadSuite();
    const results = await runSelection(suite, selection, {}, dependencies.open);
    return report(suite, results) ? 0 : 1;
  } catch (error) {
    writeError(error instanceof Error ? error.message : String(error));
    return 2;
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  process.exitCode = await runCommand(process.argv.slice(2));
}
