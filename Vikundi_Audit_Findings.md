# Vikundi VICOBA — System Audit (Deep Pass)

_Scope: entire system except loans. Read-only analysis. Date: 2026-06-24._
_Severity: 🔴 Blocker · 🟠 High · 🟡 Medium · 🟢 Low/Verify_

---

## 🔴 BLOCKERS — fix before any real users

### B1 · Errors shown to end users in production

- **Where:** `roots.php:3-5` — `error_reporting(E_ALL); ini_set('display_errors',1);` runs on every request.
- **Impact:** internal errors/warnings render into pages and **break AJAX/JSON** (the "server connection failed" class). Information disclosure.
- **Fix:** force `display_errors=0` + `log_errors=1` in production (env-aware). Single change in the front controller.

### B2 · Granular RBAC is dead for all non-admin roles

- **Where:** `core/permissions.php:21-33` selects `rp.can_review, rp.can_approve`; **`role_permissions` has neither column** → `loadUserPermissions()` throws → `$_SESSION['permissions']=[]` (the `can_review` error appears 11× in the log).
- **Impact:** every `canView/canCreate/canEdit/canDelete/canApprove` returns **false** for anyone not in the hard-coded admin-bypass list (admin, mwenyekiti, chairman, secretary, treasurer, mweka hazina). The **437 configured permission rows are ignored**; the review/approve workflow only works for bypass roles. Non-bypass committee/members effectively can't use the app.
- **Fix:** add `can_review`/`can_approve` to `role_permissions` (fix the workflow-columns migration so it actually adds them); then verify the 437 grants behave as intended.

### B3 · Unauthenticated state-changing / destructive endpoints

- **Confirmed (read by eye):** `actions/delete_death_expense.php`, `actions/update_contribution.php`, `api/account/save_account.php`, `api/account/delete_account.php`, `actions/upload_attachments.php`.
- **Pattern:** auth is **inconsistent** — most endpoints guard, a cluster in the **accounting & document APIs** do not. Anyone with the URL can mutate/delete financial data via POST.
- **Fix:** a **single central guard** (`require_once auth_guard.php`) at the top of every `actions/`, `api/`, `ajax/` endpoint enforcing login (and, with B2 fixed, the right permission) — instead of per-file ad-hoc checks.

### B4 · Web-root debug/maintenance scripts (reachable, mostly unauthenticated)

- **Where:** ~29 scripts at project root. **9 mutate data with no auth:** `set_balance.php` (sets the fund to 1,000,000), `clear_expenses.php` (deletes expenses), `fix_db_schema.php`, `migrate_expenses.php`, `add_col.php`, `check_cols.php`, `setup_permissions.php`, `setup_granular_permissions.php`, `sync_members.php`. Read-only ones leak data/schema: `list_all_users.php`, `list_db.php`, `check_*`, `get_tables.php`.
- **Impact:** unauthenticated data destruction and information disclosure. `.htaccess` does **not** block them.
- **Fix:** remove from the deployed tree (or move out of web root / block via server config). None belong in production.

---

## 🟠 HIGH

### H1 · Group fund balance is not a real ledger

- **Where:** `group_settings.group_balance` is only **decremented** (`approve_death_expense.php:44`, `api/approve_general_expense.php:36` — both `current_balance - amount`). **Nothing credits it** when contributions are approved. It can also be set arbitrarily (`set_balance.php`).
- **Conflict:** the **approval gate** ("insufficient balance") uses this stored value, but the **dashboard shows a different number** — `net_balance = total_contributions - total_all_expenses` (`app/dashboard.php:49`). The two diverge → valid payouts can be blocked, or overspending allowed.
- **Fix:** make the fund balance a single source of truth — either compute it from actual contributions/expenses, or credit it on contribution approval — and gate approvals on that. Use row locking for the read-modify-write.

### H2 · Systemic schema drift (code vs database)

- **Confirmed:** `deceased_type`, `customers.status`, `users.status`, `customers.is_active`, `role_permissions.can_review/can_approve` — five already. `database/schema_sync.sql` and the live DB diverge from the code in multiple places; the workflow-columns migration is incomplete.
- **Fix:** a one-time reconciliation (diff every column the code writes vs the DB), fix the migration, regenerate `schema_sync.sql`, and add a CI check.

### H3 · Authorization gaps (authentication ≠ authorization)

- Most mutating endpoints check only that _someone is logged in_, not _whether they may do it_. With B2, the granular model is unused entirely. A logged-in low-privilege user could perform privileged actions.
- **Fix:** centralize authz (endpoint → required permission) alongside the B3 guard.

### H4 · Possibly broken registration pages (missing include)

- `actions/upload_attachments.php`, `actions/register.php`, `actions/register_customer.php` all `require .../includes/db.php` — **`includes/db.php` does not exist.** If the require is unconditional, these pages **fatal**.
- **Fix:** verify at runtime; point them at `includes/config.php` or remove if dead. (Registration is a critical path.)

### H5 · Session cookie not hardened

- `roots.php:11-15` sets `samesite=Lax` but **no `httponly`, no `secure`.**
- **Fix:** add `'httponly'=>true` and `'secure'=>true` (on HTTPS).

### H6 · CSRF enforced on only ~3 endpoints

- `includes/csrf.php` exists (`csrf_token()`, `csrf_verify()`), but only `process_registration.php`, `add_member.php`, `process_member_import.php` validate it. All other state-changing POSTs (contributions, expenses, death expenses, accounts, budgets, user role/status) are unprotected.
- **Fix:** validate CSRF in the central guard (B3) for all POST mutations.

---

## 🟡 MEDIUM

- **M1 · Currency wrong/inconsistent for Tanzania.** USD & KES are offered as selectable currencies (`system_settings.php`, `group_settings.php`, `purchase_order_create.php`); formatting is split between `'TZS '` (`dashboard.php:90`, hardcoded, ignores the setting) and `'TSh '` (`helpers.php:527`). Normalize to TZS; drop USD/KES for this group.
- **M2 · Bilingual gaps.** 84 files / 847 inline language ternaries remain beyond login+dashboard → real risk of mixed English/Swahili. (Central JSON system already in place to migrate onto.)
- **M3 · Broken + dead endpoint.** `actions/upload_attachments.php` (missing `db.php`, unauthenticated, unused by any UI) → remove.
- **M4 · Per-request side effect.** `header.php:4` runs `actions/auto_terminate_members.php` on **every page load** → DB work each request; should be a scheduled job.
- **M5 · `Undefined array key "user_id"` warnings** in `profile.php` (lines 10/15) and `my_settings.php:6` → auth-guard ordering; leaks warnings (worsened by B1).
- **M6 · Weak password policy** (min 6 chars, no complexity). Verify `forgot_password.php` proves identity before issuing the reset token.

---

## 🟢 LOW / VERIFY

- **L1 ·** Rotate the production DB password — it sat in plaintext in the old deploy copy moved aside in `/var/www/html`; ensure that copy is deleted.
- **L2 ·** `deploy-hook.php` is tracked in git — review exposure of the deploy mechanism.
- **L3 ·** Confirm no leftover **loan UI** (nav/dashboard menu) is shown to users.
- **L4 ·** **End-of-cycle share-out (mgao):** no clear cycle/share-out engine; `app/bms/customer/record_payout.php` exists. Confirm whether the group needs automated share-out or does payouts manually.
- **L5 ·** Two separate settings screens (`system_settings.php` and `group_settings.php`) both manage currency/branding — consolidate to avoid divergence.

---

## ✅ Healthy (verified)

- **No SQL injection** — prepared statements throughout; no request data interpolated into SQL.
- **No raw echo** of `$_GET/$_POST/$_REQUEST`.
- **Password hashing** via `password_hash()`.
- **Approval workflow guarding is solid** — `assertApprovable()` + DB transactions used consistently across approve endpoints (contribution, general expense, death expense, budget, petty cash) → double-approval protected.
- **Audit logging** present; **CSRF infrastructure** present; **token-based password reset** with 1-hour expiry.

---

## Suggested remediation order

1. **B1** (display_errors) — 1 line, immediate.
2. **B4** (remove web-root debug scripts) — quick, removes destructive unauth surface.
3. **B2 + H2** (RBAC columns + schema reconciliation) — unblocks the whole permission model.
4. **B3 + H3 + H6** (central auth/authz/CSRF guard) — one architectural change closes the biggest class.
5. **H1** (fund-balance ledger) — financial correctness.
6. **H4, H5**, then Mediums, then Lows.

Each as its own `fix/` branch → PR → tests → sign-off.
