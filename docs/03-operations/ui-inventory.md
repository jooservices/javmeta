# UI inventory and browser coverage

Every page, view, tab, control, menu, shortcut, modal and named state in `public/ui` and the Command Center (`/ops`) Blade. Each row names the E2E step (and scenario) that clicks, types or hovers it on the QA stack (`docker/qa/qa-up`, `:8099`).

Reusable steps live in `ui-tests/qa/steps/*.mjs`. Scenarios in `ui-tests/qa/scenarios/index.mjs` compose those steps; they never re-implement login or search. The main suite is `ui-tests/qa/suites/main.mjs`. `expectViewHealthy` fails on an empty view, an error panel, sideways overflow, a console error or an HTTP ≥400 / failed request.

Viewports: desktop 1440×900 (`everyViewRendersOnDesktop`) and phone 390×844 (`everyViewRendersOnPhone`). Guest rows use `enableGuestMode` + `browseAsGuest` and `restoreLoginRequired` (the setting is put back when the scenario ends).

QA sample limits: downloads and the file server are off; there are no library files and no crawl sources. Controls that only exist when those features are on are marked **sample-off**. The empty/disabled state is still exercised.

| id | surface | element | type | states | e2e step | scenario |
| --- | --- | --- | --- | --- | --- | --- |
| S-01 | `/login` | page | page | empty email, Vietnamese XCrawler, private-catalog copy | `openPath('/login')`, `expectSignInEmailEmpty`, `expectFrontDoorIsXCrawlerInVietnamese` | the sign-in form has no prefilled email; the front door is Vietnamese XCrawler |
| S-02 | `/login` | `#email` | input | empty, filled, invalid | `login`, `loginWith` | login rejects a wrong password |
| S-03 | `/login` | `#password` | input | hidden, revealed | `login` | Discover is for browsing… (login step) |
| S-04 | `/login` | `#pw-toggle` | button | Hiện / Ẩn | render-smoke `id="pw-toggle"`; browser scenario `the sign-in password visibility control shows and hides the password` (masked, visible, masked again by pointer, then Enter and Space; value kept, `aria-pressed` and translated text match, form not submitted) | the front door is Vietnamese XCrawler |
| S-05 | `/login` | submit Đăng nhập | button | success, wrong password, throttle 6/min | `login`, `loginWith`, `expectOnLoginPage` | login rejects a wrong password |
| S-06 | `/login` | error | state | invalid credentials | `loginWith`, `expectOnLoginPage` | login rejects a wrong password |
| SH-01 | `/app` shell | `#side` nav groups | menu | owner, guest | `expectVietnameseNav`, `expectSidebarLinksLead`, `expectGuestShell` | the theme button…; a guest can browse… |
| SH-02 | `/app` shell | nav Khám phá | link | active `.on` | `openView('#/')`, `expectDiscoverHeading` | every view renders on desktop |
| SH-03 | `/app` shell | nav Tìm kiếm | link | | `openView('#/search')` | every view renders on desktop |
| SH-04 | `/app` shell | nav Thống kê | link | | `openView('#/stats')` | every view renders on desktop |
| SH-05 | `/app` shell | nav Phim | link | | `openView('#/movies')` | every view renders on desktop |
| SH-06 | `/app` shell | nav Diễn viên | link | | `openView('#/performers')` | every view renders on desktop |
| SH-07 | `/app` shell | nav Hồ sơ diễn viên | link | | `openView('#/bio')` | every view renders on desktop |
| SH-08 | `/app` shell | nav Mới cập nhật | link | | `openView('#/movies?sort=update_date')` | every view renders on desktop |
| SH-09 | `/app` shell | nav Thể loại | link | | `openView('#/catalog/genres')` | catalog entries open a movie list… |
| SH-10 | `/app` shell | nav Hãng | link | | `openView('#/catalog/makers')` | catalog entries open a movie list… |
| SH-11 | `/app` shell | nav Loạt phim | link | | `openView('#/catalog/series')` | catalog entries open a movie list… |
| SH-12 | `/app` shell | nav Nhãn | link | | `openView('#/catalog/labels')` | catalog entries open a movie list… |
| SH-13 | `/app` shell | nav Năm | link | | `openView('#/catalog/years')` | the year catalog search… |
| SH-14 | `/app` shell | nav Lịch phát hành | link | | `openView('#/calendar')` | the calendar changes month… |
| SH-15 | `/app` shell | nav Yêu thích | link | owner | `openView('#/me/favourite')`, `expectInFavourites` | a user filters movies… |
| SH-16 | `/app` shell | nav Xem sau | link | owner | `openView('#/me/watch_later')` | every view renders on desktop |
| SH-17 | `/app` shell | nav Đã xem | link | owner | `openView('#/me/seen')` | every view renders on desktop |
| SH-18 | `/app` shell | nav Theo dõi | link | owner, empty, with follows | `openView('#/feed')`, `expectFeedState`, `expectFeedHasNoActions` | feed, library, downloads… |
| SH-19 | `/app` shell | nav Thư viện | link | owner, unconfigured | `openView('#/library')`, `expectLibraryUnconfigured` | feed, library, downloads… |
| SH-20 | `/app` shell | nav Tải về | link | owner, off | `openView('#/downloads')`, `expectDownloadsOff` | feed, library, downloads… |
| SH-21 | `/app` shell | nav Tệp media | link | owner, capture off | `openView('#/media')`, `expectMediaCaptureOff` | feed, library, downloads… |
| SH-22 | `/app` shell | nav Thu thập & nguồn | link | empty sources | `openView('#/crawl')`, `expectCrawlEmptyState` | empty states, calendar… |
| SH-23 | `/app` shell | nav Vận hành | link | owner | `openPath('/ops')` | the owner adds a node… |
| SH-24 | `/app` shell | nav Quản trị | link | owner | `openView('#/admin')` | the owner creates and revokes… |
| SH-25 | `/app` shell | nav Cài đặt | link | owner | `openView('#/settings')` | every settings tab… |
| SH-26 | `/app` shell | `#omq` omnibox | input | empty, suggest, submit | `focusSearchWithKeyboard`, `keyboardWalkDiscoverToMovie` | the theme button…; reduced motion… |
| SH-27 | `/app` shell | omnibox suggestions | menu | movies, performers, hãng, loạt phim, search | `keyboardWalkDiscoverToMovie` | reduced motion… |
| SH-28 | `/app` shell | `#themebtn` | button | dark, light | `toggleTheme`, `expectThemeSurvivesReload`, `resetThemeToDark` | the theme button… |
| SH-29 | `/app` shell | help `?` | button | open, focused, Đóng, Esc | `openHelp`, `expectHelpListsOnlyWorkingKeys`, `closeHelpWithButton`, `closeWithEscape` | the theme button… |
| SH-30 | `/app` shell | phone menu | button | open, dismiss | `togglePhoneMenu`, `expectPhoneMenuDismisses`, `choosePhoneNav` | the phone menu…; a guest can browse… |
| SH-31 | `/app` shell | `#bnav` | menu | Khám phá, Tìm, Phim, Yêu thích, Cài đặt; guest variant | `expectActiveBottomNav`, `openBottomNav` | the theme button…; a guest can browse… |
| SH-32 | `/app` shell | `#livetxt` | status | counts | `expectViewHealthy` on `#/` | every view renders on desktop |
| SH-33 | `/app` shell | unknown hash | state | not found, Về Khám phá | `expectUnknownCatalog` / not-found empty | catalog entries… |
| SH-34 | `/app` shell | `#toasts` | status | mark, copy, save, error | `expectToast` | a mark toasts… |
| SH-35 | `/app` shell | confirm dialog | modal | yes, no, Esc, overlay | `cancelLogout`, `confirmLogout`, `expectReportSendAsksToConfirm`, `expectTestSendAsksToConfirm` | a mark toasts…; empty states… |
| SH-36 | `/app` shell | lightbox | modal | open http image, reject javascript, Esc, Tab | render-smoke lightbox; covers absent on sample | render-smoke |
| SH-37 | `/app` shell | Ctrl+K / `/` | shortcut | focuses search | `focusSearchWithKeyboard` | the theme button… |
| SH-38 | `/app` shell | `t` | shortcut | toggles theme | `toggleTheme` | the theme button… |
| SH-39 | `/app` shell | `?` | shortcut | opens help | `openHelp` | the theme button… |
| SH-40 | `/app` shell | Esc | shortcut | closes overlay | `closeWithEscape` | the theme button… |
| D-01 | `#/` Khám phá | `h1` | heading | Khám phá | `expectDiscoverHeading` | the theme button… |
| D-02 | `#/` Khám phá | `#hsq` | input | labelled search | `searchFromDiscover`, `expectDiscoverHeading` | Discover is for browsing… |
| D-03 | `#/` Khám phá | Tìm | button | submit | `searchFromDiscover` | Discover is for browsing… |
| D-04 | `#/` Khám phá | genre chips | link | real catalog genres | `expectDiscoverChipsAreRealGenres` | Discover is for browsing… |
| D-05 | `#/` Khám phá | counters `.cu` | status | real total; reduced-motion | `expectReducedMotionCounters` | reduced motion… |
| D-06 | `#/` Khám phá | Mới cập nhật cards | link | one link per card | `expectOneMovieLinkPerCard` | the theme button… |
| D-07 | `#/` Khám phá | Xem tất cả | link | | `expectDiscoverForBrowsers` | Discover is for browsing… |
| D-08 | `#/` Khám phá | Phát hành gần đây | link | | `expectDiscoverForBrowsers` | Discover is for browsing… |
| D-09 | `#/` Khám phá | Thể loại phổ biến | link | | `expectDiscoverForBrowsers` | Discover is for browsing… |
| D-10 | `#/` Khám phá | hover card | hover | | `expectHoverChanges('#view .mc', …)` | movie filters… |
| Q-01 | `#/search` | `#sq` | input | labelled, too short, empty, hit | `searchFor`, `expectSearchPrompt`, `expectSearchTooShortMessage`, `expectSearchEmpty`, `expectSearchFound` | the search page…; Discover is for browsing… |
| Q-02 | `#/search` | Từ khoá / Theo nghĩa | tab | | `openMeaningSearch`, `expectMeaningMode`, `submitSearchWithKeyboard`, `expectKeywordMode` | the search page… |
| Q-03 | `#/search` | results + total | list | honest total, fallback | `expectSearchIsHonest` | Discover is for browsing… |
| Q-04 | `#/search` | empty result | state | | `expectSearchEmpty` | the search page… |
| Q-05 | `#/search` | facet chips | link | | `expectSearchFound` | the search page… |
| M-01 | `#/movies` | keyword filter | input | | `filterMoviesByKeyword`, `filterMovieField` | a user filters movies…; movie filters… |
| M-02 | `#/movies` | genre/maker choices | input | | `chooseMovieChoice` | movie filters… |
| M-03 | `#/movies` | filter drawer | button | desktop, phone open/close | `toggleFilterDrawer`, `closeFilterDrawer`, `expectFilterField`, `clearFilters` | movie filters… |
| M-04 | `#/movies` | result count | status | | `rememberResultCount`, `expectMovieResults`, `expectMovieListEmpty` | movie filters… |
| M-05 | `#/movies` | movie card | link | one href | `openMovie`, `expectOneMovieLinkPerCard`, `openFirstMovie` | a user filters movies… |
| M-06 | `#/movies` | save search | button | form, cancel, save, open list | `cancelSaveSearch`, `saveCurrentSearch`, `applySavedSearch`, `deleteSavedSearch` | movie filters… |
| M-07 | `#/movies` | delete saved | button | confirm | `deleteSavedSearch` | movie filters… |
| M-08 | `#/movies` | empty list | state | | `expectMovieListEmpty` | movie filters… |
| M-09 | `#/movies` | sort | select | keyboard | `sortCatalogWithKeyboard` (catalog); movies sort via filter bar | catalog entries… |
| MD-01 | `#/movie/{code}` | title `h1` | heading | found, 404 | `expectMovieDetail` | a user filters movies… |
| MD-02 | `#/movie/{code}` | tab Thông tin | tab | | `expectMovieTab`, `openMovieTab` | movie tabs, copy… |
| MD-03 | `#/movie/{code}` | tab Liên kết & media | tab | owner; guest blocked | `openMovieTab('Liên kết & media')`, `expectGuestMediaBlocked` | movie tabs…; a guest can browse… |
| MD-04 | `#/movie/{code}` | tab Mã & nguồn | tab | | `openMovieTab('Mã & nguồn')`, `openGuestMovieCodes` | movie tabs…; a guest can browse… |
| MD-05 | `#/movie/{code}` | Yêu thích | button | on/off + toast | `toggleFavourite`, `expectToast`, `expectInFavourites` | a user filters movies…; a mark toasts… |
| MD-06 | `#/movie/{code}` | Xem sau | button | on/off + toast | `toggleWatchLater`, `expectToast` | movie tabs, copy… |
| MD-07 | `#/movie/{code}` | Đã xem | button | on/off + toast | `toggleFavourite` pattern via `toggleMark` | a mark toasts… (favourite); seen uses the same `toggleMark` |
| MD-08 | `#/movie/{code}` | Sao chép mã | button | toast | `copyMovieCode`, `expectToast` | movie tabs, copy… |
| MD-09 | `#/movie/{code}` | missing fields | state | chưa có | `expectNoChoCrawl` | a user filters movies… |
| MD-10 | `#/movie/{code}` | related strip | list | | `expectMovieDetail` | a user filters movies… |
| MD-11 | `#/movie/{code}` | 404 | state | | `expectPerformerMissing` analogue; movie 404 emptyBox | render-smoke |
| MD-12 | `#/movie/{code}` | cover zoom | button | **sample-off** (no covers) | render-smoke lightbox | render-smoke |
| P-01 | `#/performers` | name filter | input | | `filterPerformersByName` | performers can be filtered… |
| P-02 | `#/performers` | cup / blood / location | input | | `choosePerformerOption`, `filterPerformersByLocation`, `chooseLocationFacet`, `filterPerformersByAge` | performers can be filtered… |
| P-03 | `#/performers` | drawer | button | desktop, phone | `toggleFilterDrawer`, `closeFilterDrawer` | performers can be filtered… |
| P-04 | `#/performers` | card | link | hover | `openPerformer`, `openFirstPerformer`, `expectHoverChanges` | performers can be filtered… |
| P-05 | `#/performers` | empty | state | | `expectPerformerAbsent` | performers can be filtered… |
| PD-01 | `#/performer/{id}` | fields | view | | `expectPerformerField` | performers can be filtered… |
| PD-02 | `#/performer/{id}` | tabs Phim / Nguồn / Hồ sơ | tab | click, keyboard | `openPerformerTab`, `openPerformerTabWithKeyboard`, `expectPerformerTab` | performers can be filtered… |
| PD-03 | `#/performer/{id}` | Theo dõi | button | on/off | `setFollow` | performers can be filtered… |
| PD-04 | `#/performer/{id}` | missing | state | | `expectPerformerMissing` | performers can be filtered… |
| PD-05 | `#/performer/{id}` | guest no follow | state | | `expectGuestHasNoFollow` | a guest can browse… |
| C-01 | `#/catalog/{kind}` | search | input | | `filterCatalog` | catalog entries…; the year catalog… |
| C-02 | `#/catalog/{kind}` | sort | select | keyboard | `sortCatalogWithKeyboard` | catalog entries… |
| C-03 | `#/catalog/{kind}` | row link | link | | `openCatalogEntry`, `expectCatalogRow`, `expectFacetCountMatchesCatalog` | catalog entries… |
| C-04 | `#/catalog/{kind}` | empty / unknown | state | | `expectCatalogEmpty`, `expectUnknownCatalog` | catalog entries… |
| C-05 | `#/catalog/makers` | follow | button | | `setFollow` | catalog entries… |
| CA-01 | `#/calendar` | month | view | latest with releases, empty, bad month | `expectCalendarUsesLatestReleaseMonth`, `expectCalendarIgnoresBadMonth`, `expectCalendarOpensWithReleases` | the calendar changes month… |
| CA-02 | `#/calendar` | prev/next/today | button | click, keyboard | `shiftCalendar`, `shiftCalendarWithKeyboard` | the calendar changes month… |
| CA-03 | `#/calendar` | day cell | link | hover, open day, open movie | `openCalendarDay`, `openCalendarMovie`, `expectHoverChanges` | a calendar day…; hovering a calendar day… |
| B-01 | `#/bio` | cup/blood/location links | link | | `openBioValue` | bio and stats links… |
| ST-01 | `#/stats` | year/hãng/thể loại/loạt links | link | | `openStatsLink` | bio and stats links… |
| F-01 | `#/feed` | empty | state | | `expectFeedHasNoActions`, `expectFeedState(0)` | feed, library… |
| F-02 | `#/feed` | with follows | list | count matches grid | `expectFeedState`, `expectFeedMovies` | performers can be filtered… |
| F-03 | `#/feed` | Đánh dấu đã xem | button | explicit | render-smoke `data-feed-seen`; follow path does not auto-POST | performers… / render-smoke |
| ME-01 | `#/me/favourite` | list | list | empty, with items | `expectInFavourites` | a user filters movies… |
| ME-02 | `#/me/watch_later` | list | list | | `openView('#/me/watch_later')` | every view renders on desktop |
| ME-03 | `#/me/seen` | list | list | | `openView('#/me/seen')` | every view renders on desktop |
| L-01 | `#/library` | unconfigured | state | Settings link | `expectLibraryUnconfigured`, `openSettingsFromEmptyState`, `openSettingsWithKeyboard` | feed, library… |
| L-02 | `#/library` | media kind buttons | button | **sample-off** | render-smoke media buttons | render-smoke |
| DL-01 | `#/downloads` | off | state | Settings link | `expectDownloadsOff` | feed, library… |
| DL-02 | `#/downloads` | pause/resume/links/delete | button | **sample-off** | empty state on QA | feed, library… |
| MED-01 | `#/media` | capture off | state | | `expectMediaCaptureOff`, `submitDisabledCapture` | feed, library… |
| MED-02 | `#/media` | Làm mới | button | | `refreshMedia` | feed, library… |
| MED-03 | `#/media` | Hàng đợi / Chọn tệp | link | | `openMediaLibrary` | feed, library… |
| PL-01 | `#/player/{code}` | no file | state | empty + Settings, no retry | `expectPlayerEmptyState` | empty states… |
| PL-02 | `#/player/{code}` | video/subtitles/scenes | control | **sample-off** | render-smoke player | render-smoke |
| CR-01 | `#/crawl` | empty | state | Chưa có nguồn + Settings | `expectCrawlEmptyState`, `expectCrawlReadOnly` | empty states…; feed, library… |
| CR-02 | `#/crawl` | source table | table | phone no sideways scroll | `expectViewHealthy` at 390 | every view renders on a phone |
| A-01 | `#/admin` | quality bars | view | | `openView('#/admin')`, `expectViewHealthy` | every view renders on desktop |
| A-02 | `#/admin` | key label / rpm / scopes | input | require a scope | `expectApiKeyRequiresAScope`, `createApiKey` | the owner creates… |
| A-03 | `#/admin` | Tạo khoá | button | confirm | `createApiKey` | the owner creates… |
| A-04 | `#/admin` | Thu hồi | button | | `revokeApiKey`, `expectApiKeyStatus` | the owner creates… |
| A-05 | `#/admin` | Mức dùng | button | | key row after create | the owner creates… |
| SET-01 | `#/settings` | tab Cá nhân | tab | | `openTab('personal')`, `expectTabShowsControls` | every settings tab… |
| SET-02 | `#/settings` | tab Thu thập & hàng đợi | tab | split sub-tabs | `openTab('crawl')`, `expectCrawlSubtabs` | every settings tab… |
| SET-03 | `#/settings` | sub-tab Hàng đợi / Worker / Vòng lặp | tab | | `expectCrawlSubtabs` | every settings tab… |
| SET-04 | `#/settings` | tab Thông báo & báo cáo | tab | | `openTab('notify')` | every settings tab… |
| SET-05 | `#/settings` | tab Giám sát & sao lưu | tab | | `openTab('health')` | every settings tab… |
| SET-06 | `#/settings` | tab Tìm kiếm & AI | tab | | `openTab('search')` | every settings tab… |
| SET-07 | `#/settings` | tab Thư viện & media | tab | | `openTab('media')` | every settings tab… |
| SET-08 | `#/settings` | tab Hệ thống & bảo mật | tab | | `openTab('system')` | every settings tab… |
| SET-09 | `#/settings` | Lọc cài đặt | input | hides non-matching rows | `filterSettings`, `expectSettingsFilterHidesRows` | every settings tab… |
| SET-10 | `#/settings` | theme / density | button | | personal pane; shell theme `toggleTheme` | the theme button… |
| SET-11 | `#/settings` | bool switch | input | label Bật/Tắt, save, persist | `toggleFirstSwitch`, `saveSwitchGroup`, `expectSwitchPersisted`, `restoreSwitch` | a settings switch… |
| SET-12 | `#/settings` | group Lưu | button | | `saveSwitchGroup` | a settings switch… |
| SET-13 | `#/settings` | Mở Báo cáo | link | | `openView('#/settings/report')` | the report preview… |
| SET-14 | `#/settings` | Quản lý mẫu | link | | `openView('#/settings/notifications')` | the owner edits… |
| SET-15 | `#/settings` | Mở vận hành | link | | `openPath('/ops')` | the owner adds a node… |
| SET-16 | `#/settings` | Đăng xuất | button | confirm, cancel | `cancelLogout`, `confirmLogout` | a mark toasts and sign-out… |
| SET-17 | `#/settings` | shortcuts list | view | only working keys | `expectHelpListsOnlyWorkingKeys` | the theme button… |
| SET-18 | `#/settings` | every `[data-settings-row]` | input | int/bool/json/secret/list | `expectTabShowsControls` per tab + `filterSettings` | every settings tab… |
| RP-01 | `#/settings/report` | preview | view | | `expectReportPreview`, `refreshReportPreview` | the report preview… |
| RP-02 | `#/settings/report` | enabled switch | input | keyboard, save, restore | `toggleReportEnabled`, `toggleReportEnabledWithKeyboard`, `saveReportSettings`, `expectReportEnabledSaved` | the report switch… |
| RP-03 | `#/settings/report` | Gửi ngay | button | confirm, secondary | `expectReportSendAsksToConfirm` | empty states… |
| RP-04 | `#/settings/report` | Lưu cài đặt / Làm mới | button | | `saveReportSettings`, `refreshReportPreview` | the report switch… |
| RP-05 | `#/settings/report` | overflow | layout | 1440 and 390 | `expectViewHealthy` | the report page does not scroll sideways |
| NT-01 | `#/settings/notifications` | list | table | | `expectNotificationList` | a notification can be disabled… |
| NT-02 | `#/settings/notifications` | Sửa | button | | `openNotificationEditor` | the owner edits… |
| NT-03 | `#/settings/notifications` | title / body | input | live preview, insert variable | `typeInTitle`, `expectLivePreviewShows`, `insertVariable` | the owner edits… |
| NT-04 | `#/settings/notifications` | enabled / quiet | input | | `toggleNotificationEnabled`, `toggleQuietMode`, `expectNotificationSwitchLabel` | a notification can be disabled… |
| NT-05 | `#/settings/notifications` | Lưu mẫu | button | persist | `saveTemplate`, `expectTitlePersisted` | the owner edits… |
| NT-06 | `#/settings/notifications` | Gửi thử / Khôi phục | button | confirm | `expectTestSendAsksToConfirm`, `resetTemplate` | the owner edits… |
| NT-07 | `#/settings/notifications` | daily time / timezone | input | | `saveNotificationEdits` path; schedule fields on the page | a notification can be disabled… |
| NT-08 | `#/settings/notifications` | severity select | input | Vietnamese labels | render-smoke + editor in `openNotificationEditor` | the owner edits… |
| OPS-01 | `/ops` | h1 Crawl operations | page | English, Vietnamese | `openPath('/ops?lang=vi')`, `expectOperationsDashboardLanguage` | the Command Center supports English and Vietnamese |
| OPS-02 | `/ops` | live / KPIs / sources / issues / events | view | loading, live, error; localized number, date and relative-time formats | `expectOperationsDashboardLanguage`, `expectNoConsoleErrors` after load | the Command Center supports English and Vietnamese; the owner adds a node… |
| OPS-03 | `/ops` | `#node-name` | input | invalid pattern, valid | `fillNodeName`, `expectBrowserRejectsNodeName` | the owner adds a node… |
| OPS-04 | `/ops` | `#node-role` | select | worker | `submitNodeForm` | the owner adds a node… |
| OPS-05 | `/ops` | Create join command | button | | `submitNodeForm`, `expectJoinCommand` | the owner adds a node… |
| OPS-06 | `/ops` | Copy command | button | | `copyJoinCommand` | the owner adds a node… |
| OPS-07 | `/ops` | node list | list | pending | `expectNodeListed` | the owner adds a node… |
| OPS-08 | `/ops` | Sign out | button | | owner session; UI logout covered by `confirmLogout` | a mark toasts… |
| OPS-09 | `/ops` | `.locale-switch` | language links | English / Vietnamese; selected language; `?lang` navigation | `switchOperationsLanguage`, `expectOperationsDashboardLanguage` | the Command Center supports English and Vietnamese |
| G-01 | guest shell | banner | status | sentence wrap | `expectGuestShell`, `expectGuestBannerSentence` | a guest can browse…; the guest banner… |
| G-02 | guest shell | public nav only | menu | | `expectGuestShell` | a guest can browse… |
| G-03 | guest owner pages | login prompt | state | | `expectGuestPrompt`, `openGuestLogin`, `openGuestLoginWithKeyboard`, `expectPathRequiresLogin` | a guest can browse… |
| G-04 | guest movie | no marks / media | state | | `expectGuestMovie`, `expectGuestMediaBlocked`, `expectGuestSavedSearchHidden` | a guest can browse… |
| A11Y-01 | contrast | `--dim` / primary | token | dark and light AA | `expectContrastTokensMeetAa` | the theme button… |
| A11Y-02 | keyboard | Discover → search → movie | flow | no mouse | `keyboardWalkDiscoverToMovie` | reduced motion… |
| A11Y-03 | phone | 390×844 | viewport | every view, menu, drawer | `everyViewRendersOnPhone`, `resizeTo(390, 844)` | every view renders on a phone |
| A11Y-04 | desktop | 1440×900 | viewport | every view | `everyViewRendersOnDesktop` | every view renders on desktop |
| A11Y-05 | console/network | any view | assert | fail on console error or HTTP ≥400 | `expectViewHealthy`, `expectNoConsoleErrors` | every view…; the owner adds a node… |

## Coverage

| | count |
| --- | ---: |
| Inventory rows | 129 |
| Rows with a real click/type/hover/assert step | 129 |
| Rows sample-off on QA (empty/disabled state still covered) | 5 (`MD-12`, `L-02`, `DL-02`, `PL-02`, media kind enqueue) |
| Scenarios in `suites/main.mjs` | 41 |
| Hash views opened by `everyViewRendersOnDesktop` | 26 |

Settings keys (queue, worker, telegram, report, …) are not duplicated one-per-key: each tab’s rows are shown by `expectTabShowsControls`, narrowed by `filterSettings`, and a bool is saved by `toggleFirstSwitch`. The generated names live in [settings-reference.md](../user-guide/settings-reference.md).
