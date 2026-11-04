import { defineSuite } from '../lib/step.mjs';
import * as s from '../scenarios/index.mjs';

export default defineSuite('javmeta browser E2E', [
  s.loginRejectsAWrongPassword,
  s.everyViewRendersOnDesktop,
  s.everyViewRendersOnPhone,
  s.discoverIsForBrowsingAndSearchWorks,
  s.browseFilterOpenAndFavouriteAMovie,
  s.topBarAndKeyboardWork,
  s.ownerManagesAnApiKey,
  s.ownerEditsAndResetsANotificationTemplate,
  s.settingsTabsShowTheirControls,
  s.settingsSwitchPersists,
  s.addNodeFromTheCommandCenter,
  s.performersCanBeFilteredOpenedAndFollowed,
  s.catalogEntriesOpenMatchingMovies,
  s.yearCatalogSearchNarrowsTheList,
  s.calendarMonthAndDayOpenMovies,
  s.calendarDayOpensOnlyThatDay,
  s.calendarDayHoverChangesTheCell,
  s.searchPageFindsAMovieAndShowsNothing,
  s.movieFiltersAndSavedSearches,
  s.featurePagesShowTheirRealState,
]);
