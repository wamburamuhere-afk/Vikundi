Run the PHPUnit test suite for the Vikundi project and report results.

Steps:
1. Check that `vendor/` exists — if not, run `composer install` first
2. Run `composer test` (all suites) or the specific suite requested by the user
3. Report clearly:
   - Total tests run
   - Number passed / failed / skipped
   - Any failing test name and the assertion that failed
   - Suggested fix for any failure
4. If all tests pass, confirm the project is clean and ready

Test suites:
- `composer test`          → all tests
- `composer test-unit`     → Unit only (no DB required)
- `composer test-feature`  → Feature only

Test files:
- `tests/Unit/HelpersTest.php`      — helpers.php pure functions
- `tests/Unit/PermissionsTest.php`  — RBAC permission functions
- `tests/Feature/AuthTest.php`      — auth session and redirect logic

If a test fails, read the relevant source file before suggesting a fix.
