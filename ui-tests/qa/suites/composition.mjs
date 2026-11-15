import { defineSuite } from '../lib/step.mjs';
import {
  dashboardScenario,
  signInFormScenario,
  signInThenDashboardScenario,
} from '../scenarios/composition.mjs';

export default defineSuite('composition', [
  signInFormScenario,
  dashboardScenario,
  signInThenDashboardScenario,
]);
