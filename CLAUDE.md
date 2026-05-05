# CLAUDE.md — Vikundi VICOBA Management System

This file gives Claude Code all the context needed to work effectively on this codebase.

## Project at a Glance

**Vikundi** is a VICOBA (Village Community Bank) group management and microfinance platform for East African community savings groups, cooperatives, and informal microfinance institutions. Bilingual: English and Swahili.

**Stack:** PHP 8.2 · MySQL 8.2 · Apache (WAMP) · Bootstrap 5.3 · jQuery 3.7.1 · PHPUnit 11

**Live at:** `http://localhost/vikundi` (local WAMP instance)

---

## Architecture

### Routing
All requests enter through `index.php` (front controller). `roots.php` defines 1,000+ clean URL → file path mappings. Apache `.htaccess` rewrites all requests through index.php.

```
Browser → Apache (.htaccess) → index.php → roots.php route lookup → target PHP file
```

### Directory Layout

| Directory | Purpose |
|---|---|
| `/actions/` | POST form handlers (login, CRUD mutations) |
| `/ajax/` | Async AJAX endpoint handlers |
| `/api/` | REST-style JSON API endpoints (100+ for accounts alone) |
| `/app/bms/customer/` | Member/customer UI pages |
| `/app/bms/loans/` | Loan management UI pages |
| `/app/constant/accounts/` | Accounting module UI |
| `/app/constant/document/` | Documents & e-signatures UI |
| `/app/constant/communication/` | Email/SMS campaigns UI |
| `/app/constant/reports/` | Reports & audit UI |
| `/app/constant/settings/` | Users, roles, permissions UI |
| `/core/` | Auth & permission helpers |
| `/database/` | SQL schema dumps |
| `/includes/` | Config, activity logger, SMS helpers |
| `/TCPDF/` | PDF generation library |
| `/tests/` | PHPUnit test suite |

### Authentication & Permissions
- **Auth:** Session-based. `$_SESSION['user_id']` set on login. Every page load verified and logged in `header.php`.
- **RBAC:** `role_permissions` table stores `can_view / can_create / can_edit / can_delete` per module/page key.
- **Permission helpers** in `core/permissions.php`: `canView()`, `canCreate()`, `canEdit()`, `canDelete()`, `isAdmin()`.
- **Admin bypass:** role_id 1 or 12, or role names: admin, mwenyekiti, chairman, secretary, treasurer, mweka hazina.
- **Gate pattern:** All protected pages call `requireViewPermission('page_key')` at the top.

### Database
- Config: `includes/config.php` (NOT in git — copy from `includes/config.example.php`)
- PDO with `ERRMODE_EXCEPTION` and `FETCH_ASSOC` default
- DB: `vikundi` | Host: `localhost` | User: `root`

### Key Patterns

| Pattern | Detail |
|---|---|
| Audit logging | Every action logged in `activity_logs` via `includes/activity_logger.php` |
| Multi-language | `$_SESSION['preferred_language']` — `en` or `sw` |
| Group branding | `group_settings` table — name, logo, org type |
| PDF export | TCPDF library |
| API responses | `json_encode(['status' => 'success'/'error', 'data'/'message' => ...])` |
| SQL queries | Always use PDO prepared statements — no string interpolation in SQL |
| HTML output | Always use `safe_output()` or `htmlspecialchars()` — never raw echo of user data |

---

## Branching Strategy

```
main          ← Production (deployment only — NEVER commit directly)
  └─ develop  ← Integration branch (all features merge here first)
       └─ feat/feature-name       ← New features
       └─ fix/bug-description     ← Bug fixes
       └─ chore/task-description  ← Maintenance/setup
       └─ hotfix/critical-fix     ← Emergency production fixes
```

**Workflow:**
1. Branch from `develop`: `git checkout -b feat/my-feature develop`
2. Make changes — include tests for every new function or behaviour
3. Run `composer test` — all must pass
4. Update `sessions.md` with what was done
5. Push branch and open PR to `develop`
6. After review and merge to `develop`, open PR `develop` → `main` for deployment

---

## Development Commands

```bash
# Install dependencies (first time)
composer install

# Run all tests
composer test

# Run unit tests only (no DB required)
composer test-unit

# Run feature tests only (requires DB)
composer test-feature

# Generate HTML coverage report
composer test-coverage
```

---

## Testing Rules

- Every new function or behaviour **must** have a test
- Every bug fix **must** include a regression test
- Pure PHP functions (no DB, no HTTP) → `tests/Unit/`
- DB-dependent or HTTP-flow tests → `tests/Feature/`
- Always run `composer test` before pushing

The bootstrap (`tests/bootstrap.php`) stubs `redirectTo()` and `isAuthenticated()` and starts a PHP session, so unit tests run without a web server or database.

---

## Key File Reference

| File | Purpose |
|---|---|
| `index.php` | Front controller — all HTTP requests start here |
| `roots.php` | Route map (1000+ entries), URL helpers: `getUrl()`, `redirectTo()` |
| `header.php` | Auth check, session validation, nav rendering, activity logging |
| `helpers.php` | Loan calculations, status badges, currency/date formatting utilities |
| `core/permissions.php` | RBAC permission-checking functions |
| `includes/config.php` | PDO DB connection (NOT in git) |
| `includes/config.example.php` | DB config template — safe to commit |
| `includes/activity_logger.php` | Audit trail — logs every create/update/delete/view/login |

---

## Scaffolding a New Feature

When adding a new feature, create the following files:

1. **UI page** → `/app/<module>/<feature>.php`
   - Include `header.php` / `footer.php`
   - Call `requireViewPermission('<page_key>')` at the top
   - Bootstrap 5 layout; DataTables for lists; SweetAlert2 for confirmations

2. **API endpoint** → `/api/<feature>.php`
   - Validate `$_SESSION['user_id']`
   - Use PDO prepared statements
   - Return `json_encode(['status' => 'success', 'data' => [...]])`

3. **Action handler** → `/actions/<feature>.php` (POST form handler)
   - Validate session and inputs
   - Call `logCreate()` / `logUpdate()` / `logDelete()` for audit

4. **Route** → register in `roots.php`

5. **Tests** → `tests/Unit/<Feature>Test.php` or `tests/Feature/<Feature>Test.php`

---

## Sessions Log

All changes tracked in `sessions.md` at the project root. Always update it when:
- Adding or modifying features
- Fixing bugs
- Changing the database schema
- Modifying config or deployment setup

---

## Custom Claude Skills

Run these from the Claude Code prompt:

| Skill | What it does |
|---|---|
| `/db-backup` | Create a timestamped MySQL database backup |
| `/run-tests` | Run the PHPUnit test suite and report results |
| `/new-feature` | Scaffold a new feature with boilerplate + test files |
| `/deploy-check` | Pre-deployment checklist before merging to main |
