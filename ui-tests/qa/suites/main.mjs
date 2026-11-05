import { defineSuite } from '../lib/step.mjs';
import * as s from '../scenarios/index.mjs';

export default defineSuite('javmeta browser E2E', [
  s.signInFormHasNoPrefilledEmail,
  s.frontDoorIsVietnameseXCrawler,
  s.loginRejectsAWrongPassword,
  s.everyViewRendersOnDesktop,
  s.everyViewRendersOnPhone,
  s.discoverIsForBrowsingAndSearchWorks,
  s.browseFilterOpenAndFavouriteAMovie,
  s.topBarAndKeyboardWork,
  s.ownerManagesAnApiKey,
  s.ownerEditsAndResetsANotificationTemplate,
  s.emptyStatesCalendarAndReportConfirm,
  s.settingsTabsShowTheirControls,
  s.settingsSwitchPersists,
  s.addNodeFromTheCommandCenter,
]);
