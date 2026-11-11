# T22 fix round 4 handoff

## Defect classes fixed

- Method PHPDoc dependencies now use the declared method reflection, including PHPDoc-only parameters.
- Property PHPDoc dependencies, including nullable model types, are checked against the source-location baseline.
- Dynamic `class-string<Model>` values are resolved for static calls and instantiation.
- Array-offset reads and writes on inferred model values are checked.
- PHPDoc expansion fixtures use an existing controller/model exception pair and verify that each new dependency is rejected.

## Files changed

- `tests/Unit/Architecture/ControllerModelBoundaryRule.php`
- `tests/Unit/Architecture/ControllerModelBoundaryTest.php`
- `handoff-fix4.md`

## Checks run

- `scripts/docker/runtime php vendor/bin/pint tests/Unit/Architecture/ControllerModelBoundaryRule.php tests/Unit/Architecture/ControllerModelBoundaryTest.php` — passed.
- `scripts/docker/runtime php composer lint` — passed.
- `scripts/docker/runtime php vendor/bin/phpunit tests/Unit/Architecture/ControllerModelBoundaryTest.php` — passed (15 tests, 57 assertions).
