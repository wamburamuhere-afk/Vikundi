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
