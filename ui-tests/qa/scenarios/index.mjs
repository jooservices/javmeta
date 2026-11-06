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
import { expectHoverChanges, goBack } from '../steps/interact.mjs';
import { toggleFilterDrawer, closeFilterDrawer, expectFilterField, clearFilters, expectResultCount, expectResultCountBetween, expectHashIncludes } from '../steps/browser.mjs';
import { filterPerformersByName, choosePerformerOption, filterPerformersByLocation, chooseLocationFacet, filterPerformersByAge, expectPerformerNamed, expectPerformerAbsent, openPerformer, openFirstPerformer, expectPerformerField, openPerformerTab, openPerformerTabWithKeyboard, expectPerformerTab, setFollow, expectFeedState, expectFeedMovies, expectPerformerMissing } from '../steps/performers.mjs';
import { filterCatalog, sortCatalogWithKeyboard, expectFirstCatalogEntry, expectCatalogRow, openCatalogEntry, expectFacetCountMatchesCatalog, openFirstMovie, expectDetailContains, expectCatalogEmpty, expectUnknownCatalog, shiftCalendar, shiftCalendarWithKeyboard, expectCalendarHeading, expectCalendarUsesLatestReleaseMonth, expectCalendarMonthShifted, expectCalendarIsCurrentMonth, openCalendarDay, expectDayRequestKeptTheDate, openCalendarMovie, expectCalendarIgnoresBadMonth } from '../steps/catalog.mjs';
import { armSavedSearchCleanup, searchFor, expectSearchPrompt, expectSearchEmpty, openMeaningSearch, expectMeaningMode, submitSearchWithKeyboard, expectKeywordMode, rememberResultCount, expectRememberedCount, chooseMovieChoice, filterMovieField, expectMovieListEmpty, saveCurrentSearch, cancelSaveSearch, applySavedSearch, deleteSavedSearch } from '../steps/searchpage.mjs';
import { expectFeedHasNoActions, expectLibraryUnconfigured, expectDownloadsOff, openSettingsFromEmptyState, openSettingsWithKeyboard, expectMediaCaptureOff, refreshMedia, submitDisabledCapture, openMediaLibrary, expectCrawlReadOnly } from '../steps/ownerpages.mjs';
import { expectReportPreview, refreshReportPreview, expectReportSwitchInView, expectReportStartsEnabled, toggleReportEnabled, toggleReportEnabledWithKeyboard, expectReportSwitchLabel, saveReportSettings, expectReportEnabledSaved, expectNotificationList, rememberNotificationForm, toggleNotificationEnabled, expectNotificationSwitchLabel, saveNotificationEdits, expectFirstNotificationEnabled, markNotificationRestored, toggleQuietMode } from '../steps/reports.mjs';
import { enableGuestMode, browseAsGuest, expectGuestShell, expectGuestBannerSentence, openGuestLoginWithKeyboard, expectGuestPrompt, openGuestLogin, expectPathRequiresLogin, togglePhoneMenu, choosePhoneNav, expectPhoneMenuDismisses, openBottomNav, openFirstGuestMovie, expectGuestMovie, openGuestMovieCodes, expectGuestMediaBlocked, expectGuestHasNoFollow, expectGuestSavedSearchHidden, restoreLoginRequired } from '../steps/guest.mjs';
import { expectToast, expectDiscoverHeading, expectVietnameseNav, expectOneMovieLinkPerCard, expectNoChoCrawl, expectPlayerEmptyState, filterSettings, expectSettingsFilterHidesRows, expectCrawlSubtabs, confirmLogout, cancelLogout, expectReducedMotionCounters, keyboardWalkDiscoverToMovie, openMovieTab, expectMovieTab, copyMovieCode, toggleWatchLater, openBioValue, openStatsLink } from '../steps/ux.mjs';

export const VIEWS = [
  '#/', '#/search', '#/stats', '#/movies', '#/performers', '#/bio', '#/movies?sort=update_date',
  '#/catalog/genres', '#/catalog/makers', '#/catalog/series', '#/catalog/labels', '#/catalog/years', '#/calendar',
  '#/me/favourite', '#/me/watch_later', '#/me/seen', '#/feed', '#/library', '#/downloads', '#/media', '#/player/SMP-0520', '#/crawl', '#/admin', '#/settings', '#/settings/report', '#/settings/notifications',
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
  openTab('crawl'), expectCrawlSubtabs(), filterSettings('queue'), expectSettingsFilterHidesRows('queue'),
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
  openMovie('SMP-0520'), expectMovieDetail('SMP-0520', 'Sample Movie Number 520'), expectNoChoCrawl(),
  toggleFavourite(), expectToast(/yêu thích/i), expectInFavourites('SMP-0520', true),
  openView('#/movie/SMP-0520'), toggleFavourite(), expectInFavourites('SMP-0520', false),
]);

export const topBarAndKeyboardWork = defineScenario('the theme button, help dialog and keyboard shortcuts work', [
  login(), openView('#/'), expectSidebarLinksLead(['Khám phá', 'Phim', 'Diễn viên', 'Cài đặt']), expectVietnameseNav(), expectDiscoverHeading(), expectOneMovieLinkPerCard(),
  toggleTheme(), expectThemeSurvivesReload(), resetThemeToDark(),
  openHelp(), expectHelpListsOnlyWorkingKeys(), closeHelpWithButton(),
  openHelp(), closeWithEscape(), focusSearchWithKeyboard(),
  expectCjkFontStack(), expectContrastTokensMeetAa(), expectActiveBottomNav(),
]);

export const ownerManagesAnApiKey = defineScenario('the owner creates and revokes an API key', [
  login(), openView('#/admin'), expectApiKeyRequiresAScope(), createApiKey('e2e-key-' + Date.now().toString(36)),
  expectApiKeyStatus('đang dùng'), revokeApiKey(), expectApiKeyStatus('đã thu hồi'),
]);

export const performersCanBeFilteredOpenedAndFollowed = defineScenario('performers can be filtered, opened and followed', [
  login(), openView('#/performers'), expectResultCount(210), expectViewHealthy(),
  expectHoverChanges('#view .ac', 'borderColor'),
  toggleFilterDrawer(), expectFilterField('q', false), toggleFilterDrawer(), expectFilterField('q', true),
  resizeTo(390), expectViewHealthy(), closeFilterDrawer(), expectFilterField('q', false), toggleFilterDrawer(), expectFilterField('q', true),
  filterPerformersByName('Aiko Hayashi 105'), expectResultCount(1), expectPerformerNamed('Aiko Hayashi 105'),
  closeFilterDrawer(), clearFilters(), expectResultCount(210),
  resizeTo(1440), toggleFilterDrawer(), expectFilterField('q', true),
  choosePerformerOption('cup', 'C'), expectResultCount(30), openFirstPerformer(), expectPerformerField('Cup', 'C'), goBack(), clearFilters(), expectResultCount(210),
  choosePerformerOption('blood_type', 'B'), expectResultCount(45), openFirstPerformer(), expectPerformerField('Nhóm máu', 'B'), goBack(), clearFilters(), expectResultCount(210),
  filterPerformersByLocation('Osaka'), expectResultCount(30), openFirstPerformer(), expectPerformerField('Nơi sống', 'Osaka'), goBack(), clearFilters(), expectResultCount(210),
  chooseLocationFacet('大阪府'), expectHashIncludes('location=大阪府'), expectResultCount(30), openFirstPerformer(), expectPerformerField('Nơi sống', 'Osaka'), goBack(), clearFilters(), expectResultCount(210),
  filterPerformersByAge(40, 40), expectResultCountBetween(1, 209), openFirstPerformer(), expectPerformerField('Tuổi', '40'), goBack(),
  filterPerformersByName('Aiko Ito 120'), expectResultCount(0), expectPerformerAbsent('Aiko Ito 120'), clearFilters(), expectResultCount(210),
  filterPerformersByName('Mio Sato 1'), openPerformer('Mio Sato 1'),
  expectPerformerField('Cup', 'C'), expectPerformerField('Nhóm máu', 'B'), expectPerformerField('Nơi sống', 'Osaka'), expectPerformerField('Tuổi', '40'), expectPerformerField('Số phim', '17'),
  expectPerformerTab('movies'), expectHoverChanges('#view a.tab:not(.on)', 'color'),
  openPerformerTab('Nguồn'), expectPerformerTab('sources'),
  openPerformerTabWithKeyboard('Hồ sơ chi tiết'), expectPerformerTab('bio'),
  openPerformerTab('Phim'), expectPerformerTab('movies'),
  expectHoverChanges('#view [data-mark][data-kind=follow]', 'borderColor'),
  setFollow(true), openView('#/feed'), expectFeedState(1), expectFeedMovies(1, 'SMP-0030'),
  openView('#/performer/1'), setFollow(false), openView('#/feed'), expectFeedState(0), expectFeedMovies(0),
  expectPerformerMissing('999999999'),
]);

const catalogList = (route, name) => [
  openView(route), filterCatalog(name), expectResultCount(1), expectCatalogRow(name), openCatalogEntry(name), expectFacetCountMatchesCatalog(),
];

export const catalogEntriesOpenMatchingMovies = defineScenario('catalog entries open a movie list with the same count', [
  login(), openView('#/catalog/genres'), expectViewHealthy(),
  expectHoverChanges('#view .row-i a.lnk', 'color'),
  resizeTo(390), expectViewHealthy(), resizeTo(1440),
  sortCatalogWithKeyboard('name'), expectFirstCatalogEntry('Sample Genre 01'),
  ...catalogList('#/catalog/genres', 'Sample Genre 01'), openFirstMovie(), expectDetailContains('Sample Genre 01'),
  ...catalogList('#/catalog/makers', 'Sample Maker 01'),
  expectHoverChanges('#view [data-mark][data-kind=follow]', 'borderColor'),
  setFollow(true), setFollow(false),
  openFirstMovie(), expectDetailContains('Sample Maker 01'),
  ...catalogList('#/catalog/series', 'Sample Series 01'), openFirstMovie(), expectDetailContains('Sample Series 01'),
  ...catalogList('#/catalog/labels', 'Sample Label 5'), openFirstMovie(), expectDetailContains('Sample Label 5'),
  openView('#/catalog/years'), expectFirstCatalogEntry('2025'), expectCatalogRow('2025'), openCatalogEntry('2025'), expectFacetCountMatchesCatalog(),
  openFirstMovie(), expectDetailContains('2025'),
  openView('#/catalog/genres'), filterCatalog('zzzz-no-catalog-entry'), expectCatalogEmpty(),
  expectUnknownCatalog(),
]);

export const yearCatalogSearchNarrowsTheList = defineScenario('the year catalog search keeps only the typed year', [
  login(), openView('#/catalog/years'), filterCatalog('2025'), expectResultCount(1), expectCatalogRow('2025'),
]);

export const calendarMonthAndDayOpenMovies = defineScenario('the calendar changes month and a day opens its movie', [
  login(), openView('#/calendar'), expectCalendarUsesLatestReleaseMonth(), expectViewHealthy(),
  shiftCalendar('next'), expectCalendarMonthShifted(1),
  shiftCalendarWithKeyboard('prev'), expectCalendarMonthShifted(-1), expectCalendarUsesLatestReleaseMonth(),
  openView('#/calendar?month=2024-06'), expectCalendarHeading('6/2024', 15),
  expectHoverChanges('#view .calcell.has a.lnk', 'color'),
  resizeTo(390), expectViewHealthy(), resizeTo(1440),
  openCalendarMovie('SMP-0259'), expectDetailContains('2024-06-02'),
  openView('#/calendar?month=2024-06'), shiftCalendar('today'), expectCalendarIsCurrentMonth(),
  expectCalendarIgnoresBadMonth('2024-13'), expectCalendarUsesLatestReleaseMonth(),
]);

export const calendarDayOpensOnlyThatDay = defineScenario('a calendar day opens only the movies released that day', [
  login(), openView('#/calendar?month=2024-06'), openCalendarDay('2024-06-02'), expectDayRequestKeptTheDate('2024-06-02'),
  expectResultCount(1), expectMovieResults({ contains: 'SMP-0259', atMost: 1 }),
]);

const savedSearchName = 'e2e-385-' + Date.now().toString(36);

export const searchPageFindsAMovieAndShowsNothing = defineScenario('the search page finds a movie and shows an empty result', [
  login(), openView('#/search'), expectSearchPrompt(),
  expectHoverChanges('#view #sform button', 'filter'),
  resizeTo(390), expectViewHealthy(), resizeTo(1440),
  searchFor('a'), expectSearchTooShortMessage(),
  searchFor('zzzz-no-such-movie-xyz'), expectSearchEmpty(),
  searchFor('SMP-0520'), expectSearchFound('SMP-0520'), expectViewHealthy(),
  expectHoverChanges('#view a.lnk', 'color'),
  openMeaningSearch(), expectMeaningMode(), expectSearchFound('SMP-0520'),
  submitSearchWithKeyboard(), expectKeywordMode(), expectSearchFound('SMP-0520'),
]);

export const movieFiltersAndSavedSearches = defineScenario('movie filters, an empty result and a saved search can be cleared', [
  login(), armSavedSearchCleanup(), openView('#/movies'), rememberResultCount(), expectViewHealthy(),
  expectHoverChanges('#view .mc', 'backgroundColor'),
  resizeTo(390), expectViewHealthy(), closeFilterDrawer(), resizeTo(1440), toggleFilterDrawer(), expectFilterField('q', true),
  chooseMovieChoice('genre', 'Sample Genre 01'), openFirstMovie(), expectDetailContains('Sample Genre 01'),
  goBack(), clearFilters(), expectRememberedCount(),
  filterMovieField('maker', 'Sample Maker 01'), openFirstMovie(), expectDetailContains('Sample Maker 01'),
  goBack(), clearFilters(), expectRememberedCount(),
  filterMovieField('q', 'zzzz-no-such-movie-xyz'), expectMovieListEmpty(), clearFilters(), expectRememberedCount(),
  filterMovieField('q', 'SMP-0520'), expectMovieResults({ contains: 'SMP-0520', atMost: 3 }),
  cancelSaveSearch(), saveCurrentSearch(savedSearchName), clearFilters(), expectRememberedCount(),
  applySavedSearch(savedSearchName, 'q=SMP-0520'), expectMovieResults({ contains: 'SMP-0520', atMost: 3 }),
  deleteSavedSearch(savedSearchName),
]);

export const calendarDayHoverChangesTheCell = defineScenario('hovering a calendar day highlights the cell', [
  login(), openView('#/calendar'), expectHoverChanges('#view .calcell:not(.off):not(.has)', 'backgroundColor'),
]);

export const featurePagesShowTheirRealState = defineScenario('feed, library, downloads, media and crawl show their real state', [
  login(),
  openView('#/feed'), expectFeedState(0), expectFeedHasNoActions(), expectViewHealthy(),
  resizeTo(390), expectViewHealthy(), resizeTo(1440),
  openView('#/library'), expectLibraryUnconfigured(), expectHoverChanges('#view a.btn', 'borderColor'),
  resizeTo(390), expectViewHealthy(), resizeTo(1440),
  openSettingsFromEmptyState(),
  openView('#/downloads'), expectDownloadsOff(), expectHoverChanges('#view a.btn', 'borderColor'),
  openSettingsWithKeyboard(),
  openView('#/media'), expectMediaCaptureOff(), expectHoverChanges('#view [data-refresh]', 'borderColor'),
  resizeTo(390), expectViewHealthy(), resizeTo(1440),
  refreshMedia(), expectMediaCaptureOff(), submitDisabledCapture(), openMediaLibrary(),
  openView('#/crawl'), expectCrawlReadOnly(), expectViewHealthy(),
  resizeTo(390), expectViewHealthy(), resizeTo(1440),
]);

export const reportPreviewRefreshes = defineScenario('the report preview shows the real report and refreshes', [
  login(), openView('#/settings/report'), expectReportPreview(), expectViewHealthy(),
  expectHoverChanges('#view [data-report-refresh]', 'borderColor'),
  resizeTo(390), expectViewHealthy(), resizeTo(1440),
  refreshReportPreview(), expectReportSwitchInView(),
]);

export const reportSwitchLabelFollowsTheClick = defineScenario('the report switch label follows the click', [
  login(), openView('#/settings/report'), toggleReportEnabledWithKeyboard(), expectReportSwitchLabel(),
]);

export const reportSwitchSavesAndRestores = defineScenario('the report switch saves and is restored', [
  login(), openView('#/settings/report'), expectReportStartsEnabled(), toggleReportEnabled(), saveReportSettings(), expectReportEnabledSaved(),
  toggleReportEnabled(), saveReportSettings(), expectReportEnabledSaved(),
]);

export const notificationListTogglesAndRestores = defineScenario('a notification can be disabled and enabled again', [
  login(), openView('#/settings/notifications'), expectNotificationList(), expectViewHealthy(),
  resizeTo(390), expectViewHealthy(), resizeTo(1440),
  expectHoverChanges('#view [data-action=edit]', 'borderColor'),
  openNotificationEditor(0), rememberNotificationForm(),
  toggleQuietMode(), toggleQuietMode(),
  toggleNotificationEnabled(), saveNotificationEdits(), expectFirstNotificationEnabled(false),
  toggleNotificationEnabled(), saveNotificationEdits(), expectFirstNotificationEnabled(true), markNotificationRestored(),
]);

export const notificationSwitchLabelFollowsTheClick = defineScenario('the notification switch label follows the click', [
  login(), openView('#/settings/notifications'), openNotificationEditor(0), toggleNotificationEnabled(), expectNotificationSwitchLabel(),
]);

export const guestCanBrowsePublicPages = defineScenario('a guest can browse public pages and private routes ask for login', [
  login(), enableGuestMode(), browseAsGuest(),
  openView('#/'), expectGuestShell(), expectViewHealthy(), expectDiscoverForBrowsers(),
  expectHoverChanges('#side a.nav[href="#/movies"]', 'color'),
  resizeTo(390), expectViewHealthy(), expectGuestShell(), togglePhoneMenu(), expectGuestShell(), choosePhoneNav('Tìm kiếm'), openBottomNav('Phim'),
  resizeTo(1440),
  openGuestLoginWithKeyboard(), expectOnLoginPage(),
  openView('#/search'), expectGuestShell(), expectSearchPrompt(), searchFor('SMP-0520'), expectSearchFound('SMP-0520'),
  searchFor('zzzz-no-such-movie-xyz'), expectSearchEmpty(),
  openView('#/movies'), expectViewHealthy(), expectGuestSavedSearchHidden(), openFirstGuestMovie(), expectGuestMovie(), openGuestMovieCodes(), expectGuestMediaBlocked(),
  openView('#/performers'), expectViewHealthy(), openFirstPerformer(), expectGuestHasNoFollow(), openPerformerTab('Hồ sơ chi tiết'),
  openView('#/catalog/genres'), expectCatalogRow('Sample Genre 01'), openCatalogEntry('Sample Genre 01'), expectFacetCountMatchesCatalog(),
  openView('#/calendar'), expectCalendarUsesLatestReleaseMonth(),
  openView('#/crawl'), expectCrawlReadOnly(), expectViewHealthy(),
  openView('#/catalog/nope'), expectUnknownCatalog(),
  openView('#/admin'), expectGuestPrompt('Quản trị'), expectHoverChanges('#view .guest-login-prompt a.btn', 'filter'),
  resizeTo(390), expectGuestPrompt('Quản trị'), expectViewHealthy(), resizeTo(1440),
  openView('#/settings'), expectGuestPrompt('Cài đặt'),
  openView('#/settings/report'), expectGuestPrompt('Cài đặt'),
  openView('#/settings/notifications'), expectGuestPrompt('Cài đặt'),
  openView('#/library'), expectGuestPrompt('Thư viện'),
  openView('#/downloads'), expectGuestPrompt('Tải về'),
  openView('#/media'), expectGuestPrompt('Tệp media'),
  openView('#/feed'), expectGuestPrompt('Tính năng cá nhân'),
  openView('#/me/favourite'), expectGuestPrompt('Tính năng cá nhân'), openGuestLogin(), expectOnLoginPage(),
  expectPathRequiresLogin('/ops'),
  restoreLoginRequired(),
]);

export const guestBannerKeepsItsSentence = defineScenario('the guest banner keeps its sentence together', [
  login(), enableGuestMode(), browseAsGuest(), openView('#/'), expectGuestBannerSentence(), restoreLoginRequired(),
]);

export const phoneMenuDismisses = defineScenario('the phone menu can be dismissed without choosing a page', [
  login(), enableGuestMode(), browseAsGuest(), openView('#/'), resizeTo(390), togglePhoneMenu(), expectPhoneMenuDismisses(), restoreLoginRequired(),
]);

export const markToastsAndLogoutConfirm = defineScenario('a mark toasts and sign-out asks to confirm', [
  login(), openView('#/movie/SMP-0520'), expectMovieDetail('SMP-0520', 'Sample Movie Number 520'),
  toggleFavourite(), expectToast(/yêu thích/i), toggleFavourite(), expectToast(/bỏ yêu thích/i),
  openView('#/settings'), cancelLogout(), confirmLogout(),
]);

export const reducedMotionAndKeyboardWalk = defineScenario('reduced motion keeps counters honest and a keyboard walk opens a movie', [
  login(), openView('#/'), expectReducedMotionCounters(), expectDiscoverHeading(),
  keyboardWalkDiscoverToMovie(), expectMovieDetail('SMP-0520', 'Sample Movie Number 520'), expectNoConsoleErrors(),
]);

export const reportPageDoesNotOverflow = defineScenario('the report page does not scroll sideways', [
  login(), openView('#/settings/report'), expectViewHealthy(), resizeTo(390, 844), expectViewHealthy(), resizeTo(1440, 900), expectViewHealthy(),
]);

export const movieTabsCopyAndWatchLater = defineScenario('movie tabs, copy and watch-later work', [
  login(), openView('#/movie/SMP-0520'), expectMovieDetail('SMP-0520', 'Sample Movie Number 520'), expectMovieTab('Thông tin'),
  copyMovieCode(), expectToast(/sao chép|SMP-0520/i),
  openMovieTab('Liên kết & media'), expectMovieTab('Liên kết'),
  openMovieTab('Mã & nguồn'), expectMovieTab('Mã'),
  openMovieTab('Thông tin'), toggleWatchLater(), expectToast(/xem sau/i), toggleWatchLater(),
]);

export const bioAndStatsLinksOpenLists = defineScenario('bio and stats links open matching lists', [
  login(), openView('#/bio'), expectViewHealthy(), openBioValue(), expectViewHealthy(),
  openView('#/stats'), expectViewHealthy(), openStatsLink(), expectViewHealthy(),
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
  openView('#/settings/report'), expectReportSendAsksToConfirm(), expectViewHealthy(),
  openView('#/player/SMP-0520'), expectPlayerEmptyState(), expectViewHealthy(),
]);
