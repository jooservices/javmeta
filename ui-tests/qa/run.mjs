// usage: node run.mjs [suite-name]   (default: main)  - runs every scenario of the suite against QA_BASE (default http://localhost:8099)
import { runSuite, report } from './lib/runner.mjs';

const name = process.argv[2] || 'main';
const { default: suite } = await import(`./suites/${name}.mjs`);
const results = await runSuite(suite);
process.exit(report(suite, results) ? 0 : 1);
