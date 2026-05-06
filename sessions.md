# Sessions Log — Vikundi VICOBA Management System

This file tracks every development session, modification, and significant change made to the project. Update it at the end of every session before pushing.

---

## Log Format

```
## Session [N] — YYYY-MM-DD
**Branch:** `branch-name`
**Developer:** Name / Claude Code
**Summary:** One-line description of what was done

### Changes
- Description of change 1
- Description of change 2

### Files Created
- `path/to/file` — purpose

### Files Modified
- `path/to/file` — what changed and why

### Database Changes
- Table/column added, modified, or removed (if any)

### Notes
Important context, decisions made, or follow-up items.
```

---

## Session 8 — 2026-05-05
**Branch:** `feat/responsive-print-ui-tier2`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** Tier 2 responsive print UI — mobile card view for expenses.php, transactions.php, vicoba_reports.php; fixed card border visibility and added 2-column grid layout across all card views.

### Changes

#### Card UI Fixes (applied retroactively to Tier 1 output)
- `style.css` — darkened card border (`#e9ecef` → `#ced4da`, 1px → 1.5px), stronger shadow, visible row dividers, actions border; added `.vk-cards-wrapper` 2-column CSS Grid for screens ≥ 480 px with compact label/avatar overrides
- `app/bms/customer/customers.php` — added `vk-cards-wrapper` class to `#memberCardsWrapper`
- `app/bms/loans/loans_list.php` — added `vk-cards-wrapper` class to `#loanCardsWrapper`
- `app/constant/accounts/petty_cash.php` — added `vk-cards-wrapper` class to `#pettyCashCardsWrapper`

#### Tier 2 — expenses.php (Death Assistance)
- Table wrapper gets `d-none d-md-block d-print-block` — hides table on mobile
- New `#deathCardsWrapper` div (`d-md-none d-print-none vk-cards-wrapper`) holds mobile cards
- `drawCallback: renderDeathCards(api)` added to `#deathExpensesTable` DataTable config
- `renderDeathCards(api)` — builds red-gradient cards from server-side AJAX data (member_name, phone_number, deceased_name, deceased_relationship, amount, status); shows View/Approve(pending)/Delete buttons
- `vkEscape(s)` helper added for XSS-safe innerHTML insertion

#### Tier 2 — transactions.php (Journal Entries)
- `<div class="table-responsive">` gets `d-none d-md-block d-print-block`
- PHP loop `#transactionCardsWrapper` with 2-column grid; cards show Reference, Amount, Created By; status-dependent buttons: View, Edit+Post (draft), Reverse (posted), Delete
- `filterTransactionCards(searchVal, statusVal)` JS function filters cards by `data-search` and `data-status` attributes
- `applyFilters()` and `clearFilters()` updated to call `filterTransactionCards()`

#### Tier 2 — vicoba_reports.php (Group Reports)
- Savings table: `d-none d-md-block d-print-block`; `#savingsCardsWrapper` PHP loop shows Member Name, Total Savings with blue avatar
- Expenses table: `d-none d-md-block d-print-block`; `#expensesCardsWrapper` PHP loop shows Type, Date, Note, Amount; red avatar for Funeral Aid, purple for General

### Files Created
- `tests/Unit/ResponsivePrintTier2Test.php` — 30 unit tests covering status badges, safe_output XSS, format_currency, number_format, date formatting, htmlspecialchars, mb_substr truncation, and card search filter logic

### Files Modified
- `style.css` — card border/shadow improvements + 2-column grid
- `app/bms/customer/customers.php` — vk-cards-wrapper class
- `app/bms/loans/loans_list.php` — vk-cards-wrapper class
- `app/constant/accounts/petty_cash.php` — vk-cards-wrapper class
- `app/constant/accounts/expenses.php` — card view + renderDeathCards
- `app/constant/accounts/transactions.php` — card view + filterTransactionCards
- `app/constant/reports/vicoba_reports.php` — savings + expenses card views

### Database Changes
- None

### Notes
- Tier 3 remaining: member_statement.php, expense_report.php, loan_details.php
- All 100 unit tests pass (composer test-unit)
- 2-column grid activates at ≥480 px; single column on very small phones (<480 px)
- Print always shows table, cards always hidden in print (`d-print-none`)

---

## Session 7 — 2026-05-05
**Branch:** `fix/webhook-deploy`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** Switched deployment to PHP webhook — FTP passive mode ports are blocked by hosting provider firewall, causing upload failure and .htaccess corruption (site 403). Webhook calls server via HTTPS port 443 (always open), which runs `git pull origin main` directly.

### Root Cause of FTP Failure
- FTP control connection (port 21) succeeded, but data connections use random high ports (passive mode)
- Hosting provider firewall blocks inbound connections on those ports from external IPs (GitHub Actions)
- FTP upload started but data channel timed out → partial/zeroed .htaccess → Apache 403 on all requests

### Immediate Fix (manual — .htaccess restoration)
- .htaccess was corrupted by partial FTP upload
- User must restore via cPanel File Manager → public_html/vikundi/.htaccess → Edit → paste correct content → Save → chmod 644
- Correct .htaccess content is in the repo at `.htaccess` (version in git is authoritative)

### Changes
- `deploy.yml` — replaced FTP deployment with HTTPS webhook call:
  - POST to `DEPLOY_HOOK_URL` with `Authorization: Bearer <DEPLOY_HOOK_SECRET>`
  - Server responds 200 on success, 500 on git pull failure
  - Still blocks on Job 1 (tests must pass before deploy)
  - Smoke-tests live URL after webhook succeeds
- `deploy-hook.php` — new webhook receiver script:
  - Validates `Authorization: Bearer <token>` against `/home/bjptechn/.deploy-secret` (outside web root)
  - Runs `git fetch origin main && git reset --hard origin/main`
  - Logs output to `/home/bjptechn/.deploy-log`
  - Returns plain-text 200 OK or 500 FAILED

### Files Created
- `deploy-hook.php` — HTTPS webhook receiver for GitHub Actions deploys

### Files Modified
- `.github/workflows/deploy.yml` — webhook deployment (replaces broken FTP approach)
- `sessions.md` — Session 7 entry

### GitHub Secrets to update
Remove old secrets (no longer needed): `FTP_HOST`, `FTP_USERNAME`, `FTP_PASSWORD`
Add new secrets:
  - `DEPLOY_HOOK_URL`    → https://vikundi.bjptechnologies.co.tz/deploy-hook.php
  - `DEPLOY_HOOK_SECRET` → any strong random string (e.g. output of `openssl rand -hex 32`)

### One-time server setup required
1. In cPanel File Manager, navigate to `/home/bjptechn/` (one level above public_html)
2. Create a new file named `.deploy-secret`
3. Edit it — paste in the same random string you set as `DEPLOY_HOOK_SECRET` in GitHub
4. Save and set permissions to **600** (owner read-only)
5. Verify git is set up: cPanel → Git Version Control → confirm vikundi repo is listed and tracking `main`

### Notes
- The webhook script uses `git reset --hard origin/main` (not just `git pull`) to guarantee the server matches GitHub exactly, even if someone edited files directly on the server
- The secret file at `/home/bjptechn/.deploy-secret` is outside the web root — cannot be accessed via HTTP
- Deploy log at `/home/bjptechn/.deploy-log` — check this if a deploy fails
- `.cpanel.yml` has a hardcoded path `bjptech` (old username) — it is not executed by this approach so it doesn't matter, but clean up when convenient
- `deploy-hook.php` is excluded from the deploy-gate.yml syntax checks? No — it IS included, so it must be valid PHP (it is)

---

## Session 6 — 2026-05-05
**Branch:** `fix/ftp-deploy`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** Switched deployment method from cPanel UAPI to FTP — cPanel API tokens are blocked at the hosting provider firewall level.

### Changes
- `deploy.yml` — replaced cPanel UAPI approach with `SamKirkland/FTP-Deploy-Action@v4.3.4`
  - Connects via FTP port 21 (always open on shared hosting)
  - Uploads only files that changed since last deploy (state tracked in `.ftp-deploy-sync-state.json` on server)
  - Excludes: `includes/config.php`, `vendor/`, `uploads/`, `backups/`, `documents/`, `downloads/`, `tests/`, `.claude/`, `.github/`, `phpunit*`, `composer.*`, `coverage/`, `sessions.md`, `CLAUDE.md`, `.cpanel.yml`
  - Smoke-tests the live URL after deploy
- `.gitignore` — added `.ftp-deploy-sync-state.json` (state file created by FTP deploy action)

### Files Modified
- `.github/workflows/deploy.yml` — FTP deployment (replaces broken cPanel API approach)
- `.gitignore` — exclude FTP state file
- `sessions.md` — Session 6 entry

### GitHub Secrets to update
Remove old secrets (no longer needed): `CPANEL_API_TOKEN`, `CPANEL_HOSTNAME`
Add new secrets:
  - `FTP_HOST`     → bjptechnologies.co.tz (or ftp.bjptechnologies.co.tz)
  - `FTP_USERNAME` → bjptech
  - `FTP_PASSWORD` → your cPanel account password

### Notes
- Root cause: cPanel UAPI port 2083 is open but returns "Access denied" for API tokens — hosting provider restricts API token access to browser sessions only
- FTP (port 21) is universally open on all cPanel shared hosting plans
- The FTP action maintains a `.ftp-deploy-sync-state.json` on the server so only changed files are uploaded on subsequent deploys (first deploy uploads everything)

---

## Session 5 — 2026-05-05
**Branch:** `fix/cpanel-deploy-endpoint`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** Fixed cPanel deploy workflow — wrong API endpoint and improved auth error detection.

### Changes
- `deploy.yml` — two fixes:
  1. **Wrong endpoint:** `/execute/VersionControl/update` → `POST /execute/VersionControlDeployment/create` with `repository_root` parameter. The old endpoint updates repo settings, not trigger a pull. The correct one triggers git pull + `.cpanel.yml` tasks.
  2. **Better auth error detection:** Added explicit guard for plain-text "Access denied" response (means token NAME was used instead of token VALUE). Prints a clear fix message and exits 1.
  3. Added "Check secrets are configured" step that fails fast if any secret is missing.
  4. `repository_root` now uses `CPANEL_USERNAME` secret dynamically (`/home/CPANEL_USERNAME/public_html/vikundi`) instead of hard-coded path.

### Files Modified
- `.github/workflows/deploy.yml` — correct endpoint + auth error guard
- `sessions.md` — Session 5 entry

### Database Changes
- None

### Notes
- Root cause of "Access denied": token NAME ("github-deploy") was saved as the secret instead of the token VALUE (long alphanumeric string shown only at creation time)
- Fix: revoke token in cPanel → recreate → copy the VALUE immediately → update CPANEL_API_TOKEN secret in GitHub

---

## Session 4 — 2026-05-05
**Branch:** `chore/trigger-deploy-verification`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** Deployment verification trigger — re-runs the full deploy pipeline after cPanel API token was correctly saved in GitHub Secrets.

### Changes
- sessions.md updated to record this session and trigger the deploy workflow

### Files Modified
- `sessions.md` — Session 4 entry (this entry)

### Database Changes
- None

### Notes
- First deploy attempt failed because the cPanel API token was not saved before the workflow ran
- Token has now been saved correctly in GitHub → Settings → Secrets → `CPANEL_API_TOKEN`
- This PR exists solely to trigger a clean end-to-end deploy run: tests → cPanel pull → site verification

---

## Session 3 — 2026-05-05
**Branch:** `chore/cpanel-deploy-workflow`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** GitHub Actions → cPanel SSH deployment pipeline. Pushes to `main` automatically deploy to `/home/bjptech/public_html/vikundi` after tests pass.

### Changes
- Created `.github/workflows/deploy.yml` — two-job deployment pipeline:
  - **Job 1 (test):** Re-runs full PHPUnit suite (Unit + Feature) on the main branch commit — a failing commit cannot reach production
  - **Job 2 (deploy):** Blocked on Job 1. SSHes into cPanel server, runs `git reset --hard origin/main`, runs `composer install --no-dev --optimize-autoloader`, checks `config.php` exists, sets file permissions on `uploads/`, `documents/`, `backups/`
  - GitHub Environments configured (`production`) — shows deployment status in GitHub repo
  - Deployment summary written to GitHub Actions step summary (commit SHA, actor, timestamp)

### Files Created
- `.github/workflows/deploy.yml` — CI-gated SSH deployment workflow

### Files Modified
- `sessions.md` — Added Session 3 entry (this entry)

### Database Changes
- None

### One-time server setup required (see instructions given to user)
1. SSH into cPanel → clone repo or init git in `/home/bjptech/public_html/vikundi`
2. Run `composer install --no-dev` on server once (creates `composer.phar` or uses system composer)
3. Generate `~/.ssh/github_deploy` key pair on server
4. Add public key to `~/.ssh/authorized_keys`
5. Add 4 GitHub Secrets: `DEPLOY_SSH_KEY`, `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PORT`
6. Create a `production` GitHub Environment in repo Settings → Environments (optional but recommended for protection rules)

### Notes
- `includes/config.php` is NOT in git and is never touched by the cPanel pull — it is safe
- `vendor/` is not needed in production (app uses direct requires, not Composer autoloader) — no composer step needed on server
- Deploy uses cPanel UAPI (`/execute/VersionControl/update?name=vikundi`) — no SSH required
- `.cpanel.yml` runs post-pull tasks on the server (permissions, config.php safety check)
- Production URL updated to `https://vikundi.bjptechnologies.co.tz`
- If SSH becomes available later, the workflow can be switched to the SSH approach for more control
- cPanel API port 2083 is used (SSL); if host blocks it try port 2082 (non-SSL)

---

## Session 2 — 2026-05-05
**Branch:** `chore/github-actions-ci`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** GitHub Actions CI/CD workflows — automated PHPUnit test runs on every PR and a deployment quality gate before merging to main.

### Changes
- Created `.github/workflows/` directory with three workflow files
- `ci.yml` — runs on every push to working branches and PRs to `develop`/`main`
  - Job 1: **Unit Tests** (PHP 8.2) — fast gate, no database required
  - Job 2: **Feature Tests** (PHP 8.2) — runs after unit tests pass; MySQL 8.0 service pre-wired and ready to enable for future DB-dependent feature tests
  - Composer dependency caching between runs for speed
- `deploy-gate.yml` — runs only on PRs targeting `main` (deployment quality gate)
  - Validates `composer.json`
  - Checks `includes/config.php` is NOT committed (credentials guard)
  - Checks `.env` files are NOT committed
  - PHP syntax check across all application files (excludes `vendor/`, `TCPDF/`)
  - Full PHPUnit test suite (Unit + Feature)
  - Warns (non-blocking) if `sessions.md` was not updated in the PR
  - Confirms `vendor/` is gitignored
- `pr-labeler.yml` — auto-labels PRs based on branch prefix (`feat/` → feature, `fix/` → bug, `chore/` → chore, `hotfix/` → hotfix, etc.)

### Files Created
- `.github/workflows/ci.yml` — PHPUnit CI pipeline (unit + feature jobs)
- `.github/workflows/deploy-gate.yml` — Pre-merge checks for main branch
- `.github/workflows/pr-labeler.yml` — Automatic PR label applicator
- `phpunit.coverage.xml` — Separate PHPUnit config for local coverage reports (requires Xdebug/PCOV)
- `composer.lock` — Locked dependency versions (PHPUnit 11.5.55 + 26 transitive packages)

### Files Modified
- `phpunit.xml` — Removed `<source>` and `<coverage>` blocks; these triggered a "No code coverage driver" PHPUnit warning (exit code 1) that would have failed CI steps. Moved to `phpunit.coverage.xml`.
- `composer.json` — Updated `test-coverage` script to use `-c phpunit.coverage.xml`
- `sessions.md` — Added Session 2 entry (this entry)

### Database Changes
- None

### Notes
- **To use the MySQL service in Feature tests:** un-comment the "Create CI database config" and "Import database schema" blocks in `ci.yml`'s feature-tests job. You will also need a `database/vikundi.sql` schema dump that creates all tables cleanly.
- **PR labels:** Create these labels in GitHub → Issues → Labels before they auto-apply: `feature`, `bug`, `chore`, `hotfix`, `documentation`, `refactor`, `tests`
- **composer.lock:** Run `composer install` locally once you have PHP 8.2+, then commit the generated `composer.lock`. This makes CI builds fully reproducible and faster (cache hits on exact versions).
- Trigger: CI will fire on the next push to any `feat/**`, `fix/**`, `chore/**`, `hotfix/**` branch, and on every PR to `develop` or `main`.

---

## Session 1 — 2026-05-05
**Branch:** `chore/project-scaffolding`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** Initial project scaffolding — git setup, GitHub branches, documentation, PHPUnit test suite, and custom Claude Code skills/agents.

### Changes
- Initialized git repository and pushed to GitHub (`https://github.com/wamburamuhere-afk/Vikundi.git`)
- Established `main` as the deployment (king) branch — `git branch -M main`
- Created `develop` as the integration branch (branched from main)
- Created `chore/project-scaffolding` as the working branch for this session (branched from develop)
- Codebase fully committed to `main` (application files), scaffolding on working branch

### Files Created
- `README.md` — Full project README: features, stack, installation guide, contributing workflow
- `CLAUDE.md` — Claude Code context file: architecture, patterns, branching rules, commands
- `sessions.md` — This file (session tracking log going forward)
- `.gitignore` — Excludes: `includes/config.php`, `uploads/`, `backups/`, `downloads/`, `documents/`, `vendor/`, `scratch/`, IDE files, coverage output
- `includes/config.example.php` — Database config template (safe to commit; copy to `config.php` locally)
- `composer.json` — PHPUnit 11 dependency + `composer test` / `test-unit` / `test-feature` / `test-coverage` scripts
- `phpunit.xml` — PHPUnit configuration: Unit + Feature test suites, bootstrap, colors
- `tests/bootstrap.php` — Test bootstrapper: stubs `redirectTo()` + `isAuthenticated()`, starts PHP session, loads helpers + permissions
- `tests/Unit/HelpersTest.php` — 30+ unit tests covering: `calculateTotalInterest()`, `addMonthsWithAnchor()`, `get_status_badge()`, `format_currency()`, `format_date()`, `calculate_leave_days()`, `format_phone()`, `safe_output()`, `get_variance_color()`, `format_number()`
- `tests/Unit/PermissionsTest.php` — 20+ unit tests covering: `isAdmin()`, `canView()`, `canCreate()`, `canEdit()`, `canDelete()`, `getPermissionSummary()`, `arePermissionsLoaded()`
- `tests/Feature/AuthTest.php` — Feature test placeholder: auth session checks, redirect stub verification
- `.claude/commands/db-backup.md` — Skill: create timestamped MySQL database backup
- `.claude/commands/run-tests.md` — Skill: run PHPUnit test suite and report results
- `.claude/commands/new-feature.md` — Skill: scaffold a new feature with boilerplate + test files
- `.claude/commands/deploy-check.md` — Skill: pre-deployment checklist before merging to main
- `.claude/agents/vicoba-reviewer.md` — Agent: VICOBA-aware code reviewer (security, RBAC, audit logging, patterns)
- `.claude/agents/test-writer.md` — Agent: PHPUnit test writer specialized in Vikundi codebase

### Files Modified
- None (initial scaffolding session)

### Database Changes
- None

### Notes
- `includes/config.php` is in `.gitignore` — every developer must copy from `config.example.php`
- All new features going forward require tests in `tests/Unit/` or `tests/Feature/`
- Branching rules: `feat/*` / `fix/*` / `chore/*` / `hotfix/*` → PR to `develop` → PR to `main`
- User will manually PR `chore/project-scaffolding` → `develop` → `main`
