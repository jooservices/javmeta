import { defineScenario } from '../lib/step.mjs';
import { login, loginWith, expectOnLoginPage } from '../steps/auth.mjs';
import { openView, openPath, expectViewHealthy, resizeTo, expectNoConsoleErrors } from '../steps/navigation.mjs';
import { openTab, expectTabShowsControls, tabNames, toggleFirstSwitch, saveSwitchGroup, expectSwitchPersisted, restoreSwitch } from '../steps/settings.mjs';
import { filterMoviesByKeyword, expectMovieResults, openMovie, expectMovieDetail, toggleFavourite, expectInFavourites } from '../steps/movies.mjs';
import { expectDiscoverForBrowsers, searchFromDiscover, expectSearchFound } from '../steps/discover.mjs';
import { fillNodeName, submitNodeForm, expectBrowserRejectsNodeName, expectJoinCommand, copyJoinCommand, expectNodeListed } from '../steps/nodes.mjs';

export const VIEWS = [
  '#/', '#/search', '#/stats', '#/movies', '#/performers', '#/bio', '#/movies?sort=update_date',
  '#/catalog/genres', '#/catalog/makers', '#/catalog/series', '#/catalog/labels', '#/catalog/years', '#/calendar',
  '#/me/favourite', '#/me/watch_later', '#/me/seen', '#/feed', '#/library', '#/downloads', '#/media', '#/crawl', '#/admin', '#/settings',
];
export const SETTINGS_TABS = ['personal', 'crawl', 'notify', 'health', 'search', 'media', 'system'];

export const loginRejectsAWrongPassword = defineScenario('login rejects a wrong password', [loginWith('definitely-wrong'), expectOnLoginPage()]);

export const everyViewRendersOnDesktop = defineScenario('every view renders on desktop', [login(), ...VIEWS.flatMap(v => [openView(v), expectViewHealthy()])]);

export const everyViewRendersOnPhone = {
  ...defineScenario('every view renders on a phone', [login(), resizeTo(390, 844), ...VIEWS.flatMap(v => [openView(v), expectViewHealthy()])]),
  viewport: { width: 390, height: 844 },
};

export const settingsTabsShowTheirControls = defineScenario('every settings tab shows its controls', [
  login(), openView('#/settings'), ...SETTINGS_TABS.flatMap(t => [openTab(t), expectTabShowsControls(t)]),
]);

export const settingsSwitchPersists = defineScenario('a settings switch saves and survives a reload', [
  login(), openView('#/settings'), openTab('crawl'), toggleFirstSwitch(), saveSwitchGroup(), expectSwitchPersisted(), restoreSwitch(),
]);

export const addNodeFromTheCommandCenter = defineScenario('the owner adds a node from the Command Center', [
  login(), openPath('/ops'), fillNodeName('Bad Name'), submitNodeForm(), expectBrowserRejectsNodeName(),
  fillNodeName('worker-e2e-' + Date.now().toString(36)), submitNodeForm(), expectJoinCommand('worker-e2e-[a-z0-9]+'), copyJoinCommand(),
  expectNodeListed('worker-e2e-', 'pending'), expectNoConsoleErrors(),
]);

export const discoverIsForBrowsingAndSearchWorks = defineScenario('Discover is for browsing and its search finds a movie', [
  login(), openView('#/'), expectViewHealthy(), expectDiscoverForBrowsers(), searchFromDiscover('Sample Movie Number 520'), expectViewHealthy(), expectSearchFound('Sample Movie Number 520'),
]);

export const browseFilterOpenAndFavouriteAMovie = defineScenario('a user filters movies, opens one and favourites it', [
  login(), openView('#/movies'), expectMovieResults({ atLeast: 10 }),
  filterMoviesByKeyword('Number 520'), expectMovieResults({ atLeast: 1, atMost: 3, contains: 'SMP-0520' }),
  openMovie('SMP-0520'), expectMovieDetail('SMP-0520', 'Sample Movie Number 520'),
  toggleFavourite(), expectInFavourites('SMP-0520', true),
  openView('#/movie/SMP-0520'), toggleFavourite(), expectInFavourites('SMP-0520', false),
]);
