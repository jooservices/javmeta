import { defineSuite } from '../lib/step.mjs';
import * as s from '../scenarios/index.mjs';

export default defineSuite('javmeta browser E2E', [
  s.loginRejectsAWrongPassword,
  s.everyViewRendersOnDesktop,
  s.everyViewRendersOnPhone,
  s.settingsTabsShowTheirControls,
  s.settingsSwitchPersists,
  s.addNodeFromTheCommandCenter,
]);
