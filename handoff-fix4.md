# T22 fix round 4 handoff

## Defect classes fixed

- Method PHPDoc dependencies now use the declared method reflection, including PHPDoc-only parameters.
- Property PHPDoc dependencies, including nullable model types, are checked against the source-location baseline.
- Dynamic `class-string<Model>` values are resolved for static calls and instantiation.
- Array-offset reads and writes on inferred model values are checked.
- PHPDoc expansion fixtures use an existing controller/model exception pair and verify that each new dependency is rejected.

## Files changed for T22

- `tests/Unit/Architecture/ControllerModelBoundaryRule.php`
- `tests/Unit/Architecture/ControllerModelBoundaryTest.php`
- `handoff-fix4.md`

## Checks run

- `scripts/docker/runtime php vendor/bin/pint tests/Unit/Architecture/ControllerModelBoundaryRule.php tests/Unit/Architecture/ControllerModelBoundaryTest.php` — passed.
- `scripts/docker/runtime php composer lint` — passed.
- `scripts/docker/runtime php vendor/bin/phpunit tests/Unit/Architecture/ControllerModelBoundaryTest.php` — passed (15 tests, 57 assertions).

## Existing develop handoff with the same filename

The merged `origin/develop` side contained a T08b handoff. Its record is preserved here because both branches used `handoff-fix4.md`:

- Defect: removed an integration test's workstation-specific Redis username assertion; real Redis reservation and per-source lock assertions remain.
- Files: `tests/Integration/SourceRequestPacerRedisTest.php` and this handoff.
- Checks: `composer lint` passed; scoped test blocked because the shared runner required an exact registered worktree path; locked SonarQube scan passed.
