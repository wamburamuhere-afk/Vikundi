# Vikundi Mobile API — Build Checklist

This is a living checklist, not a plan. Every line is an API endpoint the Flutter
app needs for full parity with the web app, across every role — Member,
Treasurer, Secretary, Chairperson, Committee, Admin. `- [ ]` means the **API
endpoint** doesn't exist yet — the underlying web feature already works and is
tested; this file tracks only the new JSON layer being built on top of it.
Check a line off only once that specific endpoint is built, tested, and live in
production.

Grounded in the real app: every module below was confirmed against actual
routes in `roots.php`, actual `requireViewPermission()` / `require_login.php` /
role-check gates in the real page files, and (for Voting, Leadership
Applications, Fines, Statements, M-Koba Reconciliation) the working code built
earlier this session. Modules left out are listed at the bottom with why.

---

## 1. Auth

- [x] `POST /api/v1/auth/login` — issue a token, mirrors `actions/login.php`'s checks (status: pending/rejected/inactive/suspended all refused)
- [x] `POST /api/v1/auth/refresh` — exchange a refresh token for a new access token (rotates: the presented token is revoked)
- [x] `POST /api/v1/auth/logout` — revoke the refresh token; `all_devices: true` revokes every one
- [x] `GET /api/v1/auth/me` — current user: id, role, permissions, linked member record if any

**Shipped in PR #424.** Access token = 1h stateless JWT; refresh token = 30d opaque, stored
SHA-256-hashed in `api_refresh_tokens`, revocable. Permissions are read fresh per request, never
carried in the token. Shared plumbing for every later module lives in `includes/api_bootstrap.php`
(envelope, method guard, JSON body, CORS, `vk_api_require_auth()`, `vk_api_require_permission()`).

## 2. Dashboard

- [x] `GET /api/v1/dashboard` — role-aware summary (group totals for leadership; own arrears banner for a member) — mirrors `app/dashboard.php`

**Shipped in PR #435.** Leadership (Admin/Chairperson/Secretary/Treasurer) receive the group block —
members, contributions, expenses, balance, fines, pending queue, 6-month trend; a plain member receives
only their own position. The audit trail is admin-only, matching the web. Group figures are _withheld_,
not merely hidden, because JSON has no template to hide behind. Every money figure delegates to
`cs_group_savings_total()`, `cs_member_arrears()`, `getGroupFundBalance()` and
`approvedNotYetPaidExpenses()` — verified figure-for-figure against the web dashboard.

Fixed on the way: `app/dashboard.php` hard-coded a role list omitting `chairperson`, so the group's
Chairperson was served the _member_ dashboard. Leadership now has one definition in `includes/roles.php`
used by both transports.

## 3. Members

- [x] `GET /api/v1/members` — list, paginated, filters: status, group, search (`customers` view; sensitive fields masked for non-editors)
- [x] `GET /api/v1/members/{id}` — detail (`app/bms/customer/customer_details.php`) — a member may only open their own record
- [x] `POST /api/v1/members` — register a member (multipart/form-data; payment slip mandatory, as on the web)
- [x] `PUT /api/v1/members/{id}` — edit (`edit_customer.php`) — whitelisted columns only; POST for photo uploads
- [x] `POST /api/v1/members/{id}/approve` — member_approvals.php (admins incl. Chairperson, Secretary/Katibu)
- [x] `POST /api/v1/members/{id}/reject`
- [x] `GET /api/v1/members/dormant` — dormant_members.php list
- [x] `POST /api/v1/members/{id}/reactivate`
      ~~`GET /api/v1/member-groups` — list (`customer_groups.php`)~~ — **excluded, see below**
      ~~`GET /api/v1/member-groups/{id}` — detail + members (`customer_group_details.php`, `customer_group_members.php`)~~ — **excluded, see below**
      ~~`POST /api/v1/member-groups` — create~~ — **excluded, see below**
- [ ] `POST /api/v1/members/import` — bulk import (`customer_import.php`) — distinct upload flow, still to scope

**Member-groups excluded as BMS leftover.** Evidence gathered 2026-08-20: no nav link anywhere in the
app (reachable only by typing the URL), the routes `api/create_customer_group` and
`api/delete_customer_group` point at files that do not exist on disk, and the real data carries zero
rows in `customer_groups`. Building an API for a feature nobody can reach and whose write endpoints are
already broken would be waste.

**Module 3 writes shipped in PR #441; group-settings write in PR #443.**

- [x] `GET /api/v1/group-settings` — group_settings.php (name, logo, org type) — whitelisted keys
- [x] `PUT /api/v1/group-settings` — admins (incl. Chairperson) + Secretary; whitelisted keys, validated
- [x] GET/PUT field parity — PR #453. GET returned 7 renamed fields, PUT accepted 18 raw ones, so a
      mobile edit form could not pre-fill. One shared list (`includes/api_group_settings.php`) now
      drives both; GET gains `settings` (officer-only) + `can_edit`. Fixed `deadline_day`, which held
      a Swahili day name for weekly groups but was typed `int` — `(int) 'Jumatatu'` is `0`, so a
      pre-filled form would have zeroed a weekly group's payment deadline on every save.
- [x] `POST /api/v1/group-settings/logo` — PR #456. Multipart, officer-gated, JPG/PNG/GIF/WEBP,
      2 MB; stored in `assets/images/` so the web and the TCPDF printouts see it too. GET gains
      `group.logo_url` (absolute) — `group.logo` was the raw stored FILENAME and unloadable.
      Fixed on the way: the default logo was committed as `LOGO1.png` while 18 call sites ask for
      `logo1.png`, so on the case-sensitive servers the login page rendered a broken image.

**Module 3 read surface shipped in PR #437.** REST sub-paths (`/members/{id}`, `/members/dormant`) are
resolved by `roots.php`; handlers are flat files (`members_detail.php`) because a directory named
`api/v1/members/` makes Apache 301 the collection URL and the list endpoint stops answering.
Sensitive fields use the web's own `vk_mask_member_row()`. Writes are the next PR.

Fixed on the way: `group_settings.php` gated on `['Admin','Secretary','Katibu']`, omitting `Chairperson`,
so the group's chairperson could not open their own group settings — and the denial rendered a blank
page because `header()` ran after output had started.

## 4. Contributions

- [x] `GET /api/v1/contributions` — list, paginated, filters: member_id, status, type, date range, search
- [x] `GET /api/v1/contributions/{id}` — detail + approval trail (`contribution_view.php`)
- [x] `POST /api/v1/contributions` — record, JSON or multipart evidence (`submit_contribution.php`)
- [x] `POST /api/v1/contributions/{id}/review` — pending → reviewed
- [x] `POST /api/v1/contributions/{id}/approve` — reviewed → approved
- [x] `POST /api/v1/contributions/{id}/cancel` — pending|reviewed → cancelled
- [x] `GET /api/v1/contributions/standing` — a member's own statement: target/actual/variance, arrears, month calendar
- [x] `GET /api/v1/contributions/summary` — group collection position — leadership only
- [ ] `PUT /api/v1/contributions/{id}` — edit an unapproved row. **Deliberately deferred**: the web has
      no edit either (cancel and re-file is the flow), so this would be new behaviour, not parity.

**Shipped in PR #447.** Two corrections to the plan above. It said "leadership only
(`manage_contributions`)" — wrong: a member must see their OWN contributions, so the list is
authenticated-only and _scoped_, with `manage_contributions.edit` widening it to the whole group.
And `/my/contributions` became `/contributions/standing`, keeping one resource rather than a
parallel `/my/` tree — worth applying to the remaining modules below.

Four defects found while building it, every one silent:

1. **`manage_contributions` has no permission row on a fresh schema.** The page has gated on that key
   since it was written. On the live servers the row existed but a role was missing its grant; on a
   fresh install the key is absent entirely, so every check resolves false outside the `isAdmin()`
   _name_ bypass. `database/add_contributions_permission.php` registers it, mirroring whatever the
   target database already grants for `expenses` rather than hardcoding role ids.
2. **The approval trail recorded the database user, not the officer.** `workflowActorSnapshot()` read
   `global $username`, which `includes/config.php` also sets for the PDO connection, so every
   signature written outside `header.php` named the DB account. This affected contributions, general
   and death expenses, petty cash, budgets and documents — every three-approval workflow in the app.
3. **`actions/update_contribution.php` was a workflow bypass** — it wrote `$_POST['status']` behind a
   single `edit` check, so anyone with edit could approve without the approve permission and skip
   review entirely.
4. **Contribution evidence uploads were unrestricted** — the stored filename came from the client's
   own extension, into a web-served directory.

Left open deliberately: `cs_group_standing()` anchors "expected" at a member's first contribution,
`cs_member_schedule()` at their join date, so the two disagree about members imported from M-Koba.
Pre-existing and visible on the web today. Pinned by a test, documented in `docs/API.md`, and it needs
the treasurer to say which anchor the group means before anything changes.

## 5. Transactions

**Module 5 shipped in PR #459.**

- [x] `GET /api/v1/transactions` — list, paginated, filters: member_id, status, type, account, date range,
      search (receipt / M-Koba trans id / S-No / name) — **leadership only, hard 403**. Adds the M-Koba
      statement columns (`mkoba.sno / trans_id / member_id_str / source / destination / trans_type`) that
      `/contributions` does not carry, plus the `account` filter.
- [x] `GET /api/v1/my/transactions` — signed-in member's own dated receipts, plus the month grid and year
      summary. Built from `cs_member_transactions()` / `cs_transaction_grid()` / `cs_year_summary()`, so the
      grand total is guaranteed to equal `/contributions/standing`'s `total_saved` — pinned by a test.

## 6. Fines

**Module 6 shipped in PR #464.**

- [x] `GET /api/v1/fines` — group list, paginated, filters: member_id, status, date range, search — leadership only
- [x] `POST /api/v1/fines` — record a fine; reason required, `create` on `manage_fines`, cannot be created waived
- [x] `GET /api/v1/fines/{id}` — detail (read is open, matching the ?view=all disclosure)
- [x] `PUT /api/v1/fines/{id}` — edit amount / reason / status; status validated, never normalised
- [x] `POST /api/v1/fines/{id}/waive`
- [x] `POST /api/v1/fines/{id}/pay` — added: marking a fine paid is the main leadership action and the web has it
- [x] `GET /api/v1/my/fines` — own fines, scoped from the token (no member_id parameter exists)
- [x] `GET /api/v1/my/fines?view=all` — group view, paginated, mirroring the web toggle the group asked for

## 7. Condolences / Death Expenses

**Security fix shipped first, in PR #469/#470 — deployed & verified live 2026-09-02.**
`death_expenses.view` (the Member's own grant) was being read as group-wide access on 4 endpoints,
the same shape as the contributions leak from 2026-08-26. Fixed by `includes/death_expense_access.php`,
mirroring `includes/contribution_access.php`. Unlike contributions, the list is leadership-only
outright (no web screen scopes it to "my own" the way manage_contributions.php does) — a member's
own condolence history will be `/my/condolences` in the API module below.

**Module 7 shipped in PR #472.**

- [x] `GET /api/v1/condolences` — list, paginated, filters: member_id, status, date range, search —
      leadership only, hard 403 naming `/my/condolences` (mirrors Transactions, not Contributions:
      no web screen ever scoped this to "my own")
- [x] `GET /api/v1/condolences/{id}` — detail + approval trail; ownership re-checked at the loaded
      row (404 for a non-owned id), same discipline as `includes/death_expense_access.php`
- [x] `POST /api/v1/condolences` — record; leadership only (`create`), member_id and deceased_name
      required
- [x] `POST /api/v1/condolences/{id}/review` — added: the workflow is pending→reviewed→approved,
      same as contributions, so approve cannot be reached without it
- [x] `POST /api/v1/condolences/{id}/approve` — reviewed→approved, gated on the group's real fund
      balance (`getGroupFundBalance()` — money leaving, unlike contributions), plus the same
      deceased/dependant-marking side effects as `actions/approve_death_expense.php`
- [x] `GET /api/v1/my/condolences` — added: the member's own cases, scoped from the token. This is
      `death_expenses.view`'s first legitimate use — no web screen ever exercised it
- [x] `GET /api/v1/reports/death-analysis` — `death_analysis.php` report data — leadership only
      (`vicoba_reports`), paid cases only

Found and fixed along the way: `tests/bootstrap.php` had no stub for `vk_api_error()`, so every
`expectException(Throwable::class)` test against a config-free `api_*.php` helper (Fines,
Transactions, and now Condolences) was catching PHP's "undefined function" fatal rather than the
intended refusal — passing regardless of whether the validation was correct. Fixed with a proper
throwing stub; all pre-existing tests still pass, now for the right reason.

## 8. Financial Ledger & Reconciliation

- [x] `GET /api/v1/ledger` — financial_ledger.php, group fund balance — leadership only
- [x] `GET /api/v1/mkoba-reconciliation` — group-wide, statement vs books tie-out — leadership only
- [x] `GET /api/v1/my/mkoba-reconciliation` — own reconciliation (statement mirror + tie-out)
      ~~`GET /api/v1/bank-reconciliation` — bank_reconciliation.php~~ — **excluded, see below**

**Module 8 built, tested locally, pending PR.** `/ledger` mirrors `app/bms/customer/financial_ledger.php`
exactly — same `cs_is_opening()`/`cs_standing()` rules, same entrance-then-monthly allocation — and adds
the group fund balance (`getGroupFundBalance()`) the page itself doesn't show. `/mkoba-reconciliation` and
`/my/mkoba-reconciliation` mirror `app/constant/accounts/mkoba_reconciliation.php` and
`app/constant/reports/member_mkoba_reconciliation.php`. New shared files:
`includes/api_financial_ledger.php`, `includes/api_mkoba_reconciliation.php`.

**`bank_reconciliation` excluded as BMS leftover, same evidence shape as member-groups (Module 3).**
Checked 2026-09-03: no nav link anywhere in the app (`header.php` links `financial_ledger`,
`mkoba_reconciliation` and `my_mkoba_reconciliation` but never `bank_reconciliation`), its four backing
tables (`bank_reconciliations`, `accounts`, `banks`, `bank_transactions`) all carry zero rows, and its
permission key (`bank_reconciliation`) has no row in `permissions` at all — the page still gates on the
pre-RBAC `in_array($user_role, ['Admin','Manager','Accountant'])` pattern (todo.md's own judgment call #3),
never migrated to `requireViewPermission()` the way every live module was. Building an API for it would be
the same waste flagged for member-groups.

**Two permission-catalog gaps found and fixed along the way, same shape as Module 4's
`manage_contributions` gap:**

1. **`vicoba_reports` had no row in `permissions` at all**, despite `financial_ledger.php`,
   `app/constant/reports/vicoba_reports.php` and `death_analysis.php` all gating on
   `canView('vicoba_reports')` since they were written. Every check silently resolved false for anyone
   not caught by the `isAdmin()` bypass (Admin/Chairperson only) — a Secretary or Treasurer opening any
   of these three reports was refused. `database/add_vicoba_reports_permission.php` registers the key
   and grants view-only to all four leadership roles; not mirrored from an existing key, because the
   Reports-module keys already in the table belong to the dead accounting-ledger module and carry
   leftover BMS grants (including Member view) that would be wrong to copy here.
2. **`mkoba_reconciliation` had a permission row (added when the statement-mirror table was created) but
   zero grants for any role** — Secretary and Treasurer were silently refused the group reconciliation
   page too. `database/grant_mkoba_reconciliation_to_leadership.php` backfills view-only grants, mirroring
   `grant_meetings_to_leadership.php`'s structure.

Both registered in `database/migrate.php`, run locally, confirmed idempotent on a second run, and
verified live against the local WAMP instance (`vikundi.localhost`): leadership token succeeds on all
three endpoints, a Member-role token gets a named 403 on the two group endpoints, an attempted
`member_id` override on `/my/mkoba-reconciliation` from a non-leadership token is silently ignored (falls
back to the caller's own — never leaks another member's reconciliation), and the group tie-out's
`ledger_amount`/`reconciled` figures agree with the live `mkoba_statement_rows` data.

## 9. Expenses & Petty Cash

- [x] `GET /api/v1/expenses` — general expenses list, paginated — leadership only
- [x] `GET /api/v1/expenses/{id}` — detail + trail
- [x] `POST /api/v1/expenses` — record
- [x] `PUT /api/v1/expenses/{id}` — edit — blocked once `approved` or `paid`
- [x] `POST /api/v1/expenses/{id}/mark-paid` — cash-basis approved-vs-paid distinction
- [x] `GET /api/v1/reports/expense-report` — expense_report.php data
- [x] `GET /api/v1/petty-cash` — vouchers list, paginated — leadership only
- [x] `GET /api/v1/petty-cash/{id}` — detail + trail
- [x] `POST /api/v1/petty-cash` — record a voucher

**Module 9 built, tested, verified locally.** Two workflow steps not in the original plan but required
by it, same reasoning as Condolences: `POST /expenses/{id}/review` and `/approve` (mark-paid cannot be
reached without them — `assertReviewable()`/`assertApprovable()` enforce `pending → reviewed →
approved → paid` at the DB level), and the same pair for petty cash
(`POST /petty-cash/{id}/review`, `/approve`). Also added for parity: `PUT /petty-cash/{id}` (edit,
only while `pending` — the web already supports this via `actions/save_petty_cash.php`) and
`POST /petty-cash/{id}/mark-paid` (the same cash-basis distinction `/expenses` gets; the web's
`actions/mark_expense_paid.php` already handles `type=petty` identically).

**Permission model.** `expenses` (list/detail/create/edit/review/approve) already existed with correct
grants (leadership full CRUD+review+approve, Member view-only) — reused as-is. `mark-paid` on both
modules is gated on `canMarkPaid()` (Treasurer or a full admin — "the people who release the group's
money"), not a `role_permissions` grant, mirroring `actions/mark_expense_paid.php` exactly.

**`petty_cash` permission key registered new** (`database/add_petty_cash_permission.php`, mirrored
from `expenses`, run + confirmed idempotent). The web's own gating for petty cash was inconsistent
across files — create checked `expenses`, review/approve/delete checked `petty_cash` (which had no
catalog row at all, so those checks always resolved false outside the admin bypass), the list/detail
pages used a hardcoded role-name array, and **`actions/fetch_petty_cash.php` had no permission check
at all beyond being logged in** — any authenticated Member could pull the whole group's voucher list.
Per todo.md's own judgment call #3, the mobile API normalizes on `petty_cash` throughout, and the web
hole is closed in the same change (`canView('petty_cash')` added to `fetch_petty_cash.php`).

**One more defect fixed on the way:** `api/update_general_expense.php` only refused to edit a
row `=== 'approved'`, so a **`paid`** expense — money that had already left the account — could still
be silently edited. Fixed to block both statuses; the new API endpoint (`PUT /expenses/{id}`) uses the
corrected rule from the start.

Verified live against the local WAMP instance: full lifecycle both modules (create → review → approve
→ edit-blocked → mark-paid → re-mark-paid blocked with `409 already_paid`), the report picks up a
newly-paid expense immediately, a Member token gets `200` on both list endpoints (view is a genuine,
mirrored-from-`expenses` grant, not a leak — matches the already-audited `api/get_general_expenses.php`
behavior) but `403` on every mutation and on `mark-paid` specifically (named to the Treasurer), and an
unauthenticated request gets `401` on all of it.

## 10. Budgets

- [ ] `GET /api/v1/budgets` — list — leadership only
- [ ] `GET /api/v1/budgets/{id}` — detail + line items (`budget_details.php`)
- [ ] `POST /api/v1/budgets` — create
- [ ] `PUT /api/v1/budgets/{id}` — edit

## 11. Payouts

- [ ] `POST /api/v1/payouts` — record_payout.php — Admin/Secretary/Katibu only

## 12. Meetings

- [ ] `GET /api/v1/meetings` — list, paginated
- [ ] `GET /api/v1/meetings/{id}` — detail + attendance (`meeting_view.php`)
- [ ] `POST /api/v1/meetings` — create
- [ ] `PUT /api/v1/meetings/{id}` — edit
- [ ] `POST /api/v1/meetings/{id}/attendance` — record attendance

## 13. Documents

- [ ] `GET /api/v1/documents` — library, paginated (`document_library.php`)
- [ ] `GET /api/v1/documents/{id}` — view (`view_document.php`)
- [ ] `GET /api/v1/documents/authored` — documents_authored.php, own drafts
- [ ] `POST /api/v1/documents` — author a new document
- [ ] `PUT /api/v1/documents/{id}` — edit
- [ ] `GET /api/v1/document-templates` — list
- [ ] `POST /api/v1/documents/{id}/sign` — e-signature (`e_signatures.php`, `select_document_add_esignature.php`)
- [ ] `GET /api/v1/documents/{id}/workflow` — approval workflow state

## 14. Voting & Leadership Applications

- [ ] `GET /api/v1/elections` — manage_voting.php list, all statuses — leadership only
- [ ] `GET /api/v1/elections/{id}` — detail incl. options/candidates
- [ ] `POST /api/v1/elections` — create (draft, 0 candidates allowed)
- [ ] `POST /api/v1/elections/{id}/open` — refuses under 2 options
- [ ] `POST /api/v1/elections/{id}/close`
- [ ] `GET /api/v1/elections/{id}/results`
- [ ] `GET /api/v1/voting/open` — member view: elections currently open to vote in
- [ ] `POST /api/v1/votes` — cast a ballot (`vote_id`, `option_id`) — server refuses a second vote
- [ ] `GET /api/v1/leadership-positions` — the group's configured positions list
- [ ] `POST /api/v1/leadership-applications` — a member applies
- [ ] `PUT /api/v1/leadership-applications/{id}` — edit own (draft election only)
- [ ] `POST /api/v1/leadership-applications/{id}/withdraw`
- [ ] `GET /api/v1/leadership-applications/mine` — own application(s)
- [ ] `GET /api/v1/leadership-applications` — Committee review queue — leadership only, includes contribution-standing badge
- [ ] `POST /api/v1/leadership-applications/{id}/approve` — writes the ballot option
- [ ] `POST /api/v1/leadership-applications/{id}/reject` — reason required

## 15. Reports & Statements

- [ ] `GET /api/v1/reports/member-statement/{id}?as_of=YYYY-MM` — contributions statement, NSSF layout — self or leadership-with-id
- [ ] `GET /api/v1/reports/member-transactions/{id}?as_of=YYYY-MM` — transactions statement
- [ ] `GET /api/v1/reports/group-statement/contributions?as_of=YYYY-MM` — combined + per-member views — leadership only
- [ ] `GET /api/v1/reports/group-statement/transactions?as_of=YYYY-MM`
- [ ] `GET /api/v1/reports/vicoba` — vicoba_reports.php group summary
- [ ] `GET /api/v1/reports/customer-analysis` — customer_analysis.php

## 16. Communication

- [ ] `GET /api/v1/messages` — message_center.php, paginated — leadership only
- [ ] `POST /api/v1/messages` — send
- [ ] `GET /api/v1/sms` — sms_center.php list
- [ ] `POST /api/v1/sms` — send
- [ ] `GET /api/v1/sms-templates` — list
- [ ] `GET /api/v1/sms-alerts` — sms_alerts.php list, filters: status, alert_type
- [ ] `GET /api/v1/email-templates` — list
- [ ] `GET /api/v1/notifications` — notification_center.php, paginated
- [ ] `POST /api/v1/notifications/{id}/read`
- [ ] `POST /api/v1/ai/ask` — ai_ask.php, natural-language data query — leadership only (`ai_ask_data`)
- [ ] `POST /api/v1/ai/chat` — ai_chat.php assistant turn (`ai_assistant`)

## 17. Settings & Roles

- [ ] `GET /api/v1/users` — list, paginated — admin only
- [ ] `GET /api/v1/users/{id}` — detail
- [ ] `POST /api/v1/users` — add_user.php
- [ ] `PUT /api/v1/users/{id}` — edit_user.php
- [ ] `GET /api/v1/roles` — user_roles.php list
- [ ] `GET /api/v1/roles/{id}/permissions` — manage_permissions.php
- [ ] `PUT /api/v1/roles/{id}/permissions` — set can_view/can_create/can_edit/can_delete per page_key
- [ ] `GET /api/v1/settings/system` — system_settings.php
- [ ] `PUT /api/v1/settings/system`
- [ ] `POST /api/v1/settings/backup` — create_backup.php equivalent (SEC-002-gated: `backup_restore` permission)
- [ ] `GET /api/v1/settings/backup/{id}/download`

## 18. Profile

- [ ] `GET /api/v1/profile` — own profile (`profile.php`)
- [ ] `PUT /api/v1/profile`
- [ ] `GET /api/v1/profile/settings` — my_settings.php (language, notification prefs)
- [ ] `PUT /api/v1/profile/settings`

---

## Excluded — confirmed dead, not built

- **Accounting ledger / double-entry books** — `chart_of_accounts.php`, `journals.php`, `add_journal.php`, `edit_journal.php`, `journal_details.php`, `trial_balance.php`. Established in `docs/analysis/VIKUNDI_ANALYSIS_SYNTHESIS.md`: this module has never recorded a real transaction and is dead in five independent ways (renders a green "BALANCED" trial balance over an empty table). The group's real money runs entirely through `includes/finance.php`'s cash-basis system, which the modules above (Contributions, Transactions, Expenses, Petty Cash, Ledger) already cover.
- **Loans** — 4 routes only in `roots.php` (`LOANS_DIR`), UI disabled per project memory ("loans UI disabled"). Not part of this group's product.
- **Collections, Guarantors** — permission-table modules with zero live pages found under any of the swept directories; inherited BMS scaffolding, no route reaches them.
- **Marketing** — `campaign_management.php`, `lead_generation.php`, `customer_feedback` — BMS lead-gen leftovers, not a VICOBA feature.
- **`app/bms/{Suppliers,pos,product,purchase,sales,stock,banking,grn}`** — zero routes in `roots.php` point at any of these directories. Confirmed unreachable, not scaffolding worth excluding case-by-case.
- **`loan_documents.php`** — tied to the disabled Loans module.

---

## Judgment calls to flag

1. **Communication module scope** — `campaign_management.php`/`lead_generation.php` are excluded as Marketing-BMS leftovers, but the rest of Communication (message center, SMS, email templates, notifications, AI assistant) all have real `requireViewPermission()` gates matching real `permissions` table entries, so they're included as live. Worth a quick human sanity check on whether SMS/email are actually wired to a real provider in production or still scaffolded — I didn't trace that far (would need to check for a Twilio/Africa's Talking-style API key in config).
2. **Members import and bulk operations** (`customer_import.php`) — flagged as "scope separately" rather than folded into the standard Members CRUD list, since a CSV/XLSX upload flow is a different shape of endpoint (multipart upload + a preview/commit step, per how the M-Koba import works elsewhere in this codebase) and probably isn't a mobile-first feature anyway.
3. **Two different gating patterns exist side by side** — `requireViewPermission('key')` (most admin/leadership pages) and a looser `require_once 'header.php'` + in-page role array check (member-facing and several accounts/communication pages, e.g. `record_payout.php`'s `$viongozi_roles` check). Both are real, live gates — the API layer should normalize these into one consistent permission-check pattern rather than copying the web app's inconsistency forward.
