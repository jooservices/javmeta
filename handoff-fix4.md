# J335a Fix Round 4 Handoff

- Fixed the related-movies loading race by holding the mocked successful retry response until the test has asserted the pending loading state; the response is released before the result card is checked. The same delayed-response pattern is exercised for both locales.
- Fixed expected 524 console noise by tracking only console errors whose source URL is the mocked related-movies endpoint and whose message identifies status 524. Cleanup removes only the captured number of matching diagnostics and retains unrelated console errors.
- Files changed: `ui-tests/qa/steps/movies.mjs`, `handoff-fix4.md`.
- Checks: `node --check ui-tests/qa/steps/movies.mjs` and `git diff --check` passed. ESLint is not configured in this repository (ESLint 6.4.0 reports no configuration). The requested browser scenario could not run locally: the QA app at `localhost:8099` did not respond, and the worktree has no QA password file. The orchestrator's focused browser regression passed before this fix round.
