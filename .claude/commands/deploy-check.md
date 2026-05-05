Run a pre-deployment checklist for the Vikundi project before merging to main.

Check each item and report PASS or FAIL:

---

**1. Tests**
Run `composer test`. All tests must pass.
- PASS: 0 failures, 0 errors
- FAIL: any failure or error → do NOT merge

**2. Credentials not committed**
Check that `includes/config.php` is in `.gitignore` and NOT staged or tracked by git.
- Run: `git status includes/config.php`
- PASS: file is untracked / ignored
- FAIL: file is staged or committed → remove it immediately

**3. Sensitive directories excluded**
Verify `uploads/`, `backups/`, `downloads/`, `documents/` contents are NOT staged.
- Run: `git status`
- PASS: only `.gitkeep` files tracked in those folders
- FAIL: any real file in those folders is staged

**4. sessions.md updated**
Check that `sessions.md` has an entry for the current branch/session describing all changes.
- PASS: file modified, has today's date entry
- FAIL: file unchanged from develop baseline

**5. Branch safety**
Confirm we are NOT on `main` or `develop` directly.
- Run: `git branch --show-current`
- PASS: on a feature/fix/chore branch
- FAIL: on main or develop → stop, never commit directly to these

**6. Composer vendor excluded**
Confirm `vendor/` is in `.gitignore` and not tracked.
- PASS: `vendor/` is gitignored
- FAIL: vendor files are tracked → add to .gitignore

**7. No PHP syntax errors**
Run a quick syntax check on recently changed files.
- For each modified .php file: `php -l <file>`
- PASS: no syntax errors
- FAIL: fix before merging

---

**Final verdict:**
- All PASS → READY TO MERGE TO DEVELOP
- Any FAIL → ISSUES FOUND — list each blocking item and fix before merging
