import { defineScenario } from '../lib/step.mjs';
import { login, loginWith, expectOnLoginPage, expectSignInEmailEmpty, expectFrontDoorIsXCrawlerInVietnamese } from '../steps/auth.mjs';
import { openView, openPath, expectViewHealthy, resizeTo, expectNoConsoleErrors } from '../steps/navigation.mjs';
import { openTab, expectTabShowsControls, tabNames, toggleFirstSwitch, saveSwitchGroup, expectSwitchPersisted, restoreSwitch } from '../steps/settings.mjs';
import { filterMoviesByKeyword, expectMovieResults, openMovie, expectMovieDetail, toggleFavourite, expectInFavourites, expectCalendarOpensWithReleases } from '../steps/movies.mjs';
import { toggleTheme, expectThemeSurvivesReload, resetThemeToDark, openHelp, closeWithEscape, closeHelpWithButton, focusSearchWithKeyboard, expectSidebarLinksLead, expectHelpListsOnlyWorkingKeys, expectCjkFontStack, expectActiveBottomNav, expectContrastTokensMeetAa, expectCrawlEmptyState, expectPlainEmptyStates } from '../steps/chrome.mjs';
import { openNotificationEditor, typeInTitle, expectLivePreviewShows, insertVariable, saveTemplate, expectTitlePersisted, expectTitleDiffersFromOriginal, resetTemplate, expectTestSendAsksToConfirm, expectReportSendAsksToConfirm } from '../steps/notifications.mjs';
import { createApiKey, expectApiKeyStatus, revokeApiKey, expectApiKeyRequiresAScope } from '../steps/admin.mjs';
import { expectDiscoverForBrowsers, searchFromDiscover, expectSearchFound, expectDiscoverChipsAreRealGenres, expectSearchIsHonest, expectSearchTooShortMessage } from '../steps/discover.mjs';
import { fillNodeName, submitNodeForm, expectBrowserRejectsNodeName, expectJoinCommand, copyJoinCommand, expectNodeListed } from '../steps/nodes.mjs';

export const VIEWS = [
  '#/', '#/search', '#/stats', '#/movies', '#/performers', '#/bio', '#/movies?sort=update_date',
  '#/catalog/genres', '#/catalog/makers', '#/catalog/series', '#/catalog/labels', '#/catalog/years', '#/calendar',
  '#/me/favourite', '#/me/watch_later', '#/me/seen', '#/feed', '#/library', '#/downloads', '#/media', '#/crawl', '#/admin', '#/settings',
];
export const SETTINGS_TABS = ['personal', 'crawl', 'notify', 'health', 'search', 'media', 'system'];

export const loginRejectsAWrongPassword = defineScenario('login rejects a wrong password', [loginWith('definitely-wrong'), expectOnLoginPage()]);

export const signInFormHasNoPrefilledEmail = defineScenario('the sign-in form has no prefilled email', [
  openPath('/login'), expectSignInEmailEmpty(),
]);

export const frontDoorIsVietnameseXCrawler = defineScenario('the front door is Vietnamese XCrawler', [
  openPath('/login'), expectFrontDoorIsXCrawlerInVietnamese(),
]);

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
  login(), openView('#/'), expectViewHealthy(), expectDiscoverForBrowsers(), expectDiscoverChipsAreRealGenres(),
  searchFromDiscover('Sample Movie Number 520'), expectViewHealthy(), expectSearchFound('Sample Movie Number 520'),
  openView('#/search?q=Sample&mode=meaning'), expectSearchIsHonest(), expectSearchTooShortMessage(),
]);

export const browseFilterOpenAndFavouriteAMovie = defineScenario('a user filters movies, opens one and favourites it', [
  login(), openView('#/movies'), expectMovieResults({ atLeast: 10 }),
  filterMoviesByKeyword('Number 520'), expectMovieResults({ atLeast: 1, atMost: 3, contains: 'SMP-0520' }),
  openMovie('SMP-0520'), expectMovieDetail('SMP-0520', 'Sample Movie Number 520'),
  toggleFavourite(), expectInFavourites('SMP-0520', true),
  openView('#/movie/SMP-0520'), toggleFavourite(), expectInFavourites('SMP-0520', false),
]);

export const topBarAndKeyboardWork = defineScenario('the theme button, help dialog and keyboard shortcuts work', [
  login(), openView('#/'), expectSidebarLinksLead(['Discover', 'Phim', 'Diễn viên', 'Cài đặt']),
  toggleTheme(), expectThemeSurvivesReload(), resetThemeToDark(),
  openHelp(), expectHelpListsOnlyWorkingKeys(), closeHelpWithButton(),
  openHelp(), closeWithEscape(), focusSearchWithKeyboard(),
  expectCjkFontStack(), expectContrastTokensMeetAa(), expectActiveBottomNav(),
]);

export const ownerManagesAnApiKey = defineScenario('the owner creates and revokes an API key', [
  login(), openView('#/admin'), expectApiKeyRequiresAScope(), createApiKey('e2e-key-' + Date.now().toString(36)),
  expectApiKeyStatus('đang dùng'), revokeApiKey(), expectApiKeyStatus('đã thu hồi'),
]);

export const ownerEditsAndResetsANotificationTemplate = defineScenario('the owner edits, saves and resets a notification template', [
  login(), openView('#/settings/notifications'), openNotificationEditor(0),
  typeInTitle('E2E title {success_rate} ' + Date.now().toString(36)), expectLivePreviewShows('E2E title'), insertVariable(),
  saveTemplate(), expectTitlePersisted(), expectTitleDiffersFromOriginal(),
  expectTestSendAsksToConfirm(), resetTemplate(),
]);

export const emptyStatesCalendarAndReportConfirm = defineScenario('empty states, calendar and report send are honest', [
  login(),
  openView('#/crawl'), expectCrawlEmptyState(), expectPlainEmptyStates(),
  openView('#/library'), expectPlainEmptyStates(),
  openView('#/downloads'), expectPlainEmptyStates(),
  openView('#/calendar'), expectCalendarOpensWithReleases(),
  openView('#/settings/report'), expectReportSendAsksToConfirm(),
]);
