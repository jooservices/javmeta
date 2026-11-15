import { defineScenarioFromGroups, defineStepGroup } from '../lib/step.mjs';
import { expectSignInEmailEmpty, login } from '../steps/auth.mjs';
import { expectViewHealthy, openPath, openView } from '../steps/navigation.mjs';

export const signInFormGroup = defineStepGroup('Open the sign-in form', [
  openPath('/login'),
  expectSignInEmailEmpty(),
]);

export const dashboardGroup = defineStepGroup('Sign in and open the dashboard', [
  login(),
  openView('#/'),
  expectViewHealthy(),
]);

export const signInFormScenario = defineScenarioFromGroups(signInFormGroup.name, [signInFormGroup]);
export const dashboardScenario = defineScenarioFromGroups(dashboardGroup.name, [dashboardGroup]);
export const signInThenDashboardScenario = defineScenarioFromGroups(
  'Open the sign-in form, then sign in and open the dashboard',
  [signInFormGroup, dashboardGroup],
);
