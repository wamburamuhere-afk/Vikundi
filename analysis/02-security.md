# Agent 2 — Security

Deep analysis pass, slice 2. Read-only review; no file outside `analysis/` was modified.
Every claim below was read in source. Nothing was executed (RoE 1–2).

---

## Scope

**Read in full**

- `core/permissions.php` (560 ln), `core/backup.php` (relevant block), `core/ai_prompt_builder.php` (partial)
- `includes/require_auth.php`, `require_login.php`, `require_csrf.php`, `csrf.php`, `upload_guard.php`,
  `role_grants.php`, `document_access.php`, `authored_document_access.php`, `registration_validator.php`
  (`reg_password_errors` only), `config.example.php`
- `actions/login.php`, `actions/check_auth.php`, `actions/reset_password.php`, `logout.php`
- `header.php:1-145` (auth gate, identity query, CSRF meta + fetch wrapper), root `.htaccess`, `backups/.htaccess`
- All 33 top-level `api/` shims (verbatim, 2 lines each)
- `app/constant/settings/ajax/get_role.php` (41 ln, full), `app/constant/accounts/{journals,journal_details,expense_details,edit_journal}.php` (bootstrap blocks), `app/bms/product/product_create_footer.php` (head)
- `api/account/{void_journal,update_journal,add_compound_journal,get_expenses}.php`,
  `api/{create_backup,download_backup,delete_purchase_return,search_members_with_phone,get_campaigns,
  get_leads,get_purchase_returns,get_user_signatures,get_documents,get_transactions,process_edit_customer}.php`,
  `api/document/get_documents.php`, `api/ai/{ask,chat,generate,save_settings,test_connection}.php` (gates only)
- `ajax/{get_member_beneficiaries,get_users,quick_upload_document,save_drawn_signature,get_access_log}.php`
- `actions/{calculate_penalties,contribution_reminders}.php`
- `app/bms/customer/{customer_details,edit_customer,submit_contribution,print_contribution}.php` (gates)
- `app/constant/reports/member_statement.php:1-40`
- `app/constant/accounts/print_{budget,death_expense,general_expense,petty_cash}.php` (gates)
- `app/constant/document/document_library.php` (download path + `downloadDocumentLocal`)
- `includes/sms_helper.php` (transport + signature scan)

**Generated inventories**

- Auth-marker scan over all 275 `actions/` + `ajax/` + `api/` files (markers: `require_auth`, `require_login`,
  `$_SESSION['user_id']`, `isAuthenticated()`, `require_csrf`, and any `can*()`/`requirePermission*()`/`isAdmin()`/
  `hasPermission()`). Result: **71 files with no marker**, of which 33 are shims, 4 are zero-byte, 3 are public
  by design, and 1 fatals on include — leaving **~30 genuinely unauthenticated live endpoints**.
- `ORDER BY $var` scan across `actions ajax api app includes core` — 10 sites, each triaged to source.
- **Permission-coverage recount across all 134 `app/` pages** (I own this figure; recomputed and confirmed
  independently, superseding MAP §8.4's "39", which was the count of files *using* `requireViewPermission`,
  not the count lacking a gate):

  | Measure | Count |
  |---|---|
  | `app/` pages total | 134 |
  | No *enforcing* gate (`requireView/Create/Edit/DeletePermission`, `requirePermissionJson`, `autoEnforcePermission`) | **72** |
  | No permission call of *any* kind (the above plus inline `canView/canCreate/canEdit/canDelete/canApprove/canReview/isAdmin/hasPermission`) | **49** |
  | Consult a `canX()` helper but never enforce with it | **23** |
  | No gate of any kind — no permission call, no `header.php`/`includeHeader()`, no `require_login`/`require_auth`/`isAuthenticated()` | **3** |

- All 21 `autoEnforcePermission()` call sites enumerated and split by argument form (17 explicit key / 4 no-arg).

**Not read** (RoE 5): `TCPDF/`, `vendor/`, `backups/`, `uploads/`, `documents/`, `downloads/`, `assets/`, `*.min.*`.

**Map corrections established this pass** (cite these over MAP §2.5):

| MAP claim | Corrected finding |
|---|---|
| `actions/calculate_penalties.php` is a live unauthenticated mutator | **Dead.** `:2` is `require_once '../config.php'` — no `config.php` exists at repo root. Fatals before the class is even defined; the mutation loop is additionally inside a `php_sapi_name() === 'cli'` guard (`:269`). Not web-exploitable. |
| `api/delete_purchase_return.php` is a live unauthenticated mutator | **Not unauthenticated.** `:8` `hasPermission('delete_purchase_returns')` fails closed for anonymous. It is a *different* bug — see SEC-014. |
| Print pages are all `isAuthenticated()`-only | **2 of 5.** `print_budget.php:6` and `print_petty_cash.php:7` only. The other three added `requireViewPermission()` — which does not fix them (SEC-007). |
| 29 unauthenticated endpoints | `api/account/export_invoices.php` is a 30th, absent from the map's list. Four `api/document/*` entries flagged by marker scan are zero-byte files. |
| 33 shim pairs may diverge | **No divergence, and the risk is inverted.** All 33 verified identical: `<?php` + `require_once __DIR__ . '/account/<name>.php';`, nothing before. The target's guard therefore always runs, so there is no shim bypass today. The live hazard runs the other way: a guard added to a *shim* would be bypassable by calling the canonical `api/account/X.php` URL, since `roots.php` routes both. Harden the target, never the shim. |
| `autoEnforcePermission()` silently allows unmapped basenames | **Worse: the no-arg form never enforces anything, ever.** `basename($_SERVER['PHP_SELF'])` is always `index.php` under the front controller (`.htaccess:34`), and `index.php` is both absent from the 30-entry map and listed in `$excludedPages` (`core/permissions.php:505`). The "unmapped basename" case is not an edge case — it is every call. See SEC-013. |
| MAP §8.4: "39 of 134 `app/` pages with no permission call" | **Wrong; 39 was the count of files *using* `requireViewPermission`.** Recomputed: 72 lack an enforcing gate, 49 lack any permission call, 3 lack any gate at all. See the Scope table above and SEC-020. |

---

## Findings

Ordered by severity, then by exploitability within a severity band. IDs are stable identifiers and are
therefore **not** sequential in this listing — SEC-019 and SEC-020 were added late and sit at their
severity rank, not at the end.

### [SEC-001] Any authenticated user can create, rewrite, or void arbitrary journal entries
- **Severity:** S0
- **Confidence:** confirmed
- **Location:** `api/account/void_journal.php:8-12`, `api/account/update_journal.php:8-11`, `api/account/add_compound_journal.php:9-13`; reachable at four extra URLs via `api/void_journal.php:2`, `api/update_journal.php:2`, `api/add_compound_journal.php:2`, `api/save_journal.php:2`
- **Evidence:**
  ```php
  if (!isAuthenticated()) { http_response_code(401); echo json_encode([...]); exit; }
  $entry_id = $_POST['entry_id'] ?? 0;
  $stmt = $pdo->prepare("UPDATE journal_entries SET status = 'void', ... WHERE entry_id = ?");
  ```
- **Impact:** `isAuthenticated()` (`actions/check_auth.php:4-6`) is a bare `isset($_SESSION['user_id'])` test. Every logged-in account — including an ordinary Member, whose entire seeded grant set is view-only (`includes/role_grants.php:79`) — can POST an `entry_id` and void any journal entry in the group's ledger. `void_journal.php:30` then calls `deleteGlobalTransaction()`, removing the paired transaction row too. `update_journal.php` lets the same user replace the whole debit/credit leg set of any entry; `add_compound_journal.php` lets them fabricate entries. A member can silently write off their own arrears, credit themselves, or destroy the audit trail behind an approved expense. This is the densest concentration of the ~55 SESS-only endpoints (MAP §5.3 pattern 6 applied to the ledger) and it is the single worst authorisation gap in the codebase.
- **Fix effort:** trivial (<1h)
- **Fix sketch:** Add `requirePermissionJson('edit','journals')` / `'create'` / `'delete'` immediately after the auth line in each of the three targets — the shims inherit it automatically. `requirePermissionJson()` already exists at `core/permissions.php:537` and is the intended gate.
- **Prior art:** `[KNOWN-UNFIXED]` — first raised as audit H3 ("Authentication ≠ authorization on mutating endpoints") in `Vikundi_Audit_Findings.md`. The fix (`requirePermissionJson`) was built but never applied here.

---

### [SEC-002] Any authenticated user can dump and download the entire database
- **Severity:** S0
- **Confidence:** confirmed
- **Location:** `api/create_backup.php:5-10`, `api/download_backup.php:8-10`
- **Evidence:**
  ```php
  // Check permissions (Admin only)
  session_start();
  if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit(); }
  ```
- **Impact:** The comment says "Admin only"; the code says "logged in". Any member can trigger a full `mysqldump` into `backups/` and then fetch it via `api/download_backup.php?file=…`. The dump contains every `users.password` bcrypt hash, every member's NIDA/phone/address, and the complete financial ledger. `backups/.htaccess` correctly denies direct HTTP access — that control is entirely undone by an ungated PHP reader in front of it. The filename is a predictable `backup_v_Y-m-d_H-i-s.sql`, but the attacker creates it themselves so guessing is unnecessary. This is total data compromise from an ordinary member account, and it also hands the attacker offline hash-cracking material for the chairperson's account. Note the path-traversal control on the reader (`download_backup.php:18-23`, `realpath` + `strpos` prefix + `.sql` extension) is sound — the defect is purely authorisation.
- **Fix effort:** trivial (<1h)
- **Fix sketch:** Gate both files with `requirePermissionJson('view','backup_restore')` (the `backup_restore` key already exists in `core/permissions.php:394`), or `isAdmin()` if backup is to stay chairperson-only.
- **Prior art:** `[KNOWN-UNFIXED]` — audit H3 names both files explicitly as still SESS-only.

---

### [SEC-003] Unauthenticated enumeration of member family PII (spouse and children)
- **Severity:** S0
- **Confidence:** confirmed
- **Location:** `ajax/get_member_beneficiaries.php:1-16`; routed at `roots.php:604`, also served directly by `.htaccess:23-25`
- **Evidence:**
  ```php
  require_once __DIR__ . '/../includes/config.php';
  $member_id = $_GET['member_id'] ?? null;
  $stmt = $pdo->prepare("SELECT customer_id, first_name, ..., spouse_first_name, ..., children_data FROM customers WHERE customer_id = ?");
  ```
- **Impact:** No session check, no permission check, no `roots.php` — the file requires the DB config and goes straight to the query. Anyone on the internet can walk `?member_id=1,2,3…` and harvest every member's full name, spouse's full name, spouse-deceased flag, and the decoded `children_data` JSON (names and, per the death-expense flow, ages). This is beneficiary data for a burial-fund scheme: it identifies minors by name and links them to a named adult. Sequential integer PKs make enumeration trivial and the group is small enough to exhaust in seconds. Under any data-protection regime this is the finding that carries regulatory weight, not just technical weight.
- **Fix effort:** trivial (<1h)
- **Fix sketch:** `require_once includes/require_auth.php` then `requirePermissionJson('view','customers')` at the top; the caller is an authenticated death-expense wizard, so nothing legitimate breaks.
- **Prior art:** `[KNOWN-UNFIXED]` — audit B3 ("unauthenticated state-changing endpoints") was scoped to mutators; the read-side leak was never enumerated.

---

### [SEC-004] Unauthenticated member directory + phone-number harvest across five endpoints
- **Severity:** S0
- **Confidence:** confirmed
- **Location:** `api/search_members_with_phone.php:1-21`, `api/search_customers.php`, `api/search_expense_members.php`, `api/get_member_dependents.php`, `api/get_member_death_history.php`
- **Evidence:**
  ```php
  require_once __DIR__ . '/../includes/config.php';
  $q = $_GET['q'] ?? '';
  $stmt = $pdo->prepare("SELECT customer_id, first_name, last_name, phone FROM customers c WHERE (... LIKE :q ...)");
  ```
- **Impact:** Same shape as SEC-003: config include, then query. `?q=a` returns 20 rows of `name (phone)`; iterating the alphabet returns the entire active membership with mobile numbers. For a Tanzanian VICOBA this is a directly monetisable list — mobile-money (M-Pesa/Tigo Pesa) social-engineering against a known savings-group member, using their real name and the group's real name (also readable from `group_settings` via other ungated endpoints). `get_member_death_history` and `get_member_dependents` extend the same anonymous surface to bereavement records. Secondary issue at `:33`: the catch block echoes `$e->getMessage()`, returning raw PDO/SQL errors to an anonymous caller.
- **Fix effort:** trivial (<1h)
- **Fix sketch:** Prepend `includes/require_auth.php` + `requirePermissionJson('view','customers')` to all five, and replace the `getMessage()` echo with a static string plus `error_log()`.

---

### [SEC-005] Unauthenticated read of the group's cash position, chart of accounts, and expense ledger
- **Severity:** S0
- **Confidence:** confirmed
- **Location:** `api/account/get_bank_balance.php`, `get_bank_reconciliations.php`, `get_chart_of_accounts.php`, `get_accounts.php`, `get_account.php`, `get_account_types.php`, `get_account_categories.php`, `get_account_category.php`, `get_categories_by_type.php`, `get_category_details.php`; plus `api/get_death_expenses.php:1-5`, `api/get_general_expenses.php`, `api/get_general_expense_details.php`, `api/document/get_all_documents.php`, `api/auto_sync.php`
- **CORRECTION (2026-07-31, live curl test):** `api/account/export_invoices.php` was listed above in error and has been removed from the list — it returns **302 to `login`**, because `:3` requires `header.php`, whose `:6-9` gate redirects. It is correctly gated and was excluded from the SEC-005 remediation. Two further corrections from the same test: `api/document/get_all_documents.php` returns **HTTP 500, 0 bytes** — a PHP fatal, not an access control; the cause is a wrong relative path at `:2` (`__DIR__ . '/../roots.php'` resolves to `api/roots.php`, which does not exist; it needs `/../../`). And `api/auto_sync.php` is **not a read endpoint** — it mass-creates `users` rows from `customers`, so it is an unauthenticated *mutator* and SEC-005's framing understates it.

#### `api/auto_sync.php` — full classification (Batch 2, item 3; read-only, no request sent)

SEC-005 lists this file as an unauthenticated **read**. That is wrong. Batch 1 called it a live
unauthenticated **mutator**. That is also wrong, but much closer. What it actually is:

**An unauthenticated identity-write endpoint that is dead by accident.**

What it writes, unconditionally, for every row with no phone match and with no dry-run, no
confirmation and no CLI guard:

| Line | Write | Detail |
|---|---|---|
| `:33-40` | `INSERT INTO users` | `password_hash('123456')` — a **hard-coded default password** — with `role_id = 15` (Member) and `status = 'active'` |
| `:56-62` | `INSERT INTO customers` | member record for any Member-role user lacking one |

It also **echoes the usernames it creates** (`:41` `CREATED USER: $username for MEMBER: …`), so the
response body hands the caller the exact credentials it just minted. Had it been reachable, the
attack was: hit the URL → an active account is created for every member without one, all with
password `123456` → read the usernames out of the response → log in as any member. That is
unauthenticated account creation plus credential disclosure plus full authenticated access, i.e.
**S0**, not the S0-as-read that SEC-005 records.

**Why it is nonetheless not exploitable.** `:2` is `require_once 'includes/config.php'` — a *bare
relative* path, and the only one in `api/`, `actions/` or `ajax/`. Served directly by
`.htaccess:23-25`, PHP's working directory is the script's own directory, so it resolves to
`api/includes/config.php`, which does not exist; the include fatals before any write. The file is
also **not routed** in `roots.php`, so the front-controller path — under which the bare relative
*would* resolve from the repo root — is unreachable. This is the same accidental fail-closed
posture as `actions/calculate_penalties.php` (SEC-017), and it is the only thing that has been
protecting it.

**Standing risk.** The protection is a typo. Anyone tidying `:2` into
`__DIR__ . '/../includes/config.php'` — the obvious cleanup, and exactly what Batch 2 item 2 did to
twelve *other* files — re-arms it in one keystroke. Batch 1's gate at `:4-6`
(`require_auth` + `requirePermissionJson('edit','system_settings')`) sits *after* the fatal, so it
does not run today, but it is correctly positioned and would take effect the moment the path is
fixed. Item 2 deliberately did **not** touch this file for that reason.

**Recommendation: delete it.** Not implemented in this batch — flagged for a decision.
- It hard-codes `123456` as the password for every account it creates.
- It echoes those usernames to the caller.
- It is unrouted and called by no UI (`grep` over `app/`, `actions/`, `assets/` finds no caller).
- Its function duplicates `database/activate_imported_members.php`, which lives in the migration
  directory where a one-off maintenance job belongs.
- Keeping it as gated-but-broken code means its safety depends on a bare relative path nobody
  should preserve on purpose.

Second choice if it must be kept: wrap the whole body in
`if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }`, matching
`actions/calculate_penalties.php:269`, **and** fix the `:2` path so the failure mode is a
deliberate guard rather than an accident.
- **Evidence:** `api/get_death_expenses.php:1-5` — config include, `header('Content-Type: application/json')`, then the query. No session, no permission, no `roots.php`.
- **Impact:** Sixteen endpoints publish the group's financial state to anonymous callers: current bank balance, unreconciled items, the full chart of accounts with balances, every general and death expense with amounts, payees and member names, and the document index. Ten of these are additionally reachable at a second URL through the shims (`api/get_accounts.php` etc.), so blocking one path is not enough. For a community savings group, publishing the cash-on-hand figure is itself a physical-security risk — it tells anyone how much the treasurer is holding. `api/account/export_invoices.php` is not in MAP §2.5's list and is equally open.
- **Fix effort:** small (<half day)
- **Fix sketch:** One `require_once __DIR__.'/../../includes/require_auth.php';` line per file plus the matching `requirePermissionJson('view', …)` key (`chart_of_accounts`, `expenses`, `death_expenses`, `library`). Add a unit test asserting every file under `api/` contains an auth marker, in the style of the existing `tests/Unit/NoWebRootDebugScriptsTest.php`.

---

### [SEC-006] SQL injection via unvalidated `ORDER BY` direction at five endpoints
- **Severity:** S1
- **Confidence:** confirmed
- **Location:** `ajax/get_users.php:20,68`; `api/account/get_expenses.php:27,115` (+ shim `api/get_expenses.php:2`); `api/get_leads.php:21,79`; `api/get_purchase_returns.php:21,119`; `api/get_campaigns.php:15-16,22,43`
- **Evidence:**
  ```php
  $order_dir = $_GET['order'][0]['dir'] ?? 'ASC';      // ajax/get_users.php:20 — no validation
  $query .= " ORDER BY $order_col $order_dir";          // :68
  ```
- **Impact:** The sort *column* is safely resolved through an index-into-whitelist at all ten `ORDER BY $var` sites, and four sites also normalise the direction through a ternary (`api/get_transactions.php:91`, `api/get_documents.php:88`, `api/get_user_signatures.php:25`, `app/bms/product/products.php:174`). At the five sites above the direction is taken raw from the query string and concatenated. A logged-in member can append arbitrary SQL after `ORDER BY <col>` — `ORDER BY x, IF((SELECT SUBSTR(password,1,1) FROM users WHERE user_id=1)='$', SLEEP(3), 1)` extracts the chairperson's bcrypt hash one character at a time, and `ORDER BY` subselects give full read of all 176 tables. `includes/config.example.php:11-19` does not set `ATTR_EMULATE_PREPARES=false`, but MySQL still refuses stacked statements, so this is read/exfiltration rather than direct ledger writes. `api/get_campaigns.php:15-16` compounds it: `$start`/`$length` are taken with no `intval()` and interpolated into `LIMIT $start, $length` at `:43` — a second, simpler injection point in the same file. This directly falsifies `Vikundi_Audit_Findings.md:94` ("no SQL injection — prepared statements throughout"); that line must be retracted.
- **Fix effort:** trivial (<1h)
- **Fix sketch:** Replace each raw direction with `$dir = strtolower($raw) === 'asc' ? 'ASC' : 'DESC';` — the exact idiom already used at `api/get_transactions.php:91`. Cast `$start`/`$length` to int in `get_campaigns.php`, or bind them.

---

### [SEC-019] Unauthenticated disclosure of the entire RBAC matrix
- **Severity:** S1
- **Confidence:** confirmed
- **Location:** `app/constant/settings/ajax/get_role.php:1-31`; routed at `roots.php:568-569` as `ajax/get_role` and `ajax/get_role.php`
- **Evidence:**
  ```php
  require_once __DIR__ . '/../../../../roots.php';
  $role_id = $_GET['role_id'];
  $stmt = $pdo->prepare("SELECT permission_id, can_view, can_create, can_edit, can_delete FROM role_permissions WHERE role_id = ?");
  ```
- **Impact:** One of only three `app/` pages with no gate of any kind (SEC-020), and by far the most sensitive. It requires `roots.php`, which yields `$pdo`, a session and the permission helpers but **no authentication** (MAP §1.2; `roots.php:100` pulls `actions/check_auth.php`, which only *defines* `isAuthenticated()`). It then returns, to anyone, the full `roles` row for any `role_id` plus that role's complete `role_permissions` grid. Walking `?role_id=1..N` yields the group's entire authorisation model.

  This is not merely reconnaissance — it is the enabling primitive for two other findings in this report. It hands an attacker (a) the exact `role_name` strings, which is precisely what `isAdmin()` and `canMarkPaid()` string-match against (SEC-015), so an attacker learns which label to aim for; and (b) a per-role map of exactly which `page_key` each role can and cannot reach, i.e. a directory of where the soft spots are, letting them skip the guesswork on SEC-001/SEC-007 entirely. Being unauthenticated, it also confirms the deployment is live and the schema is intact before any other probe is sent. Secondary defect at `:40`: the catch block echoes `$e->getMessage()` to the anonymous caller (see SEC-018).

  Rated S1 rather than S0 because the payload is configuration rather than member PII or money — but it is the finding that makes the S0s cheap to exploit, so it should be fixed in the same change.
- **Fix effort:** trivial (<1h)
- **Fix sketch:** `require_once includes/require_auth.php;` then `requirePermissionJson('view','user_roles')` at the top — `user_roles` is already in `vk_admin_only_keys()` (`includes/role_grants.php:20`), so this correctly restricts it to the chairperson. Cast `$role_id` to int and replace the `getMessage()` echo.

---

### [SEC-020] 72 of 134 `app/` pages have no enforcing permission gate; 3 have no gate at all
- **Severity:** S2
- **Confidence:** confirmed
- **Location:** whole-tree measurement (method and figures in Scope); the three ungated pages are `app/constant/settings/ajax/get_role.php`, `app/constant/accounts/add_journal.php`, `app/bms/product/product_create_footer.php`
- **Evidence:** `find app -name '*.php' | xargs grep -LE '<enforcing-gate-pattern>' | wc -l` → 72 of 134; adding the inline `canX()` helpers to the pattern → 49; adding the authentication markers → 3.
- **Impact:** The denominators matter more than any single page here, because they say the RBAC grid is advisory across roughly half the UI. **72 of 134** pages never call an enforcing helper; **49** never consult permissions at all. The great majority of those 49 (46) do at least inherit the `header.php:6-9` authentication gate, so the dominant failure mode across `app/` is the one SEC-001 shows in `api/`: *authenticated but unauthorised*. Given a Member role that is seeded view-only on nearly every page key (`includes/role_grants.php:79`), a system where half the UI never checks that grid is a system where the Member/leadership distinction is largely cosmetic on the read side.

  The **23** pages that call `canView()`/`canEdit()`/`isAdmin()` but never enforce with the result are a distinct and more interesting failure: they consult the permission and render anyway, using it only to hide buttons. That list includes `app/constant/settings/{user_roles,manage_permissions,add_user,edit_user,backup_restore,email_settings,sms_settings}.php` — the administrative screens — and `app/constant/reports/{vicoba_reports,expense_report,death_analysis,customer_analysis}.php` plus `app/bms/customer/financial_ledger.php`. Hiding a control in markup is not a control; if any of those settings screens post to a handler that is itself SESS-only, the pairing is directly exploitable. I did not trace those handlers (see Coverage gaps), which is why this is S2 rather than S1 — the measurement is confirmed, the per-page exploitability is not.

  Of the three with no gate whatsoever, only one is a live problem: `get_role.php` (SEC-019). The other two are inert — `add_journal.php:7` calls the undefined `includeConfig()` and fatals despite being routed at `roots.php:155-156`, and `product_create_footer.php` is an unrouted, PHP-free `<script>` fragment (`.htaccess:16-18` 301s a direct hit onto a clean URL that resolves to nothing).
- **Fix effort:** large (>3 days)
- **Fix sketch:** Not 72 individual edits. Move enforcement into the one place every page already passes through: have `header.php` resolve the current route back to a `page_key` via `roots.php`'s `$routes` (the route name is already the natural key — `accounts/journals` → `journals`) and call `requireViewPermission()` centrally, with an explicit opt-out list for genuinely public pages. That is the fix `autoEnforcePermission()` was reaching for and got wrong (SEC-013). Then convert the 23 display-only sites to enforce as well as hide.

---

### [SEC-007] Any member can print any other member's financial documents by iterating `?id=`
- **Severity:** S1
- **Confidence:** confirmed
- **Location:** `app/constant/accounts/print_budget.php:6`, `print_petty_cash.php:7` (auth only); `app/bms/customer/print_contribution.php:6-7`, `app/constant/accounts/print_death_expense.php:6-7`, `print_general_expense.php:6-7` (auth + view permission)
- **Evidence:**
  ```php
  if (!isAuthenticated()) die('Unauthorized');
  requireViewPermission('manage_contributions');   // print_contribution.php:7
  $id = intval($_GET['id'] ?? 0);
  ```
- **Impact:** Two distinct gaps. (a) `print_budget.php` and `print_petty_cash.php` have no permission check at all — any logged-in account renders any budget or petty-cash voucher, including preparer/reviewer/approver identities. (b) The three that *do* check permission check the wrong thing: `manage_contributions`, `death_expenses` and `expenses` are all absent from `vk_member_hidden_keys()` (`includes/role_grants.php:33-59`), so the seeder grants ordinary Members `can_view` on every one of them (`:79` returns `[1,0,0,0,0,0]`). A member therefore passes `requireViewPermission()` and can walk `?id=1..N` to print every other member's contribution receipt — amount, date, member name, phone. There is no ownership check on any of the five. In a group where relative savings levels are socially sensitive, this is the cross-member leak that matters most in practice. This is the correct reading of MAP §5.3 pattern 6: a *view* permission is not an *ownership* check, and adding one did not fix these pages.
- **Fix effort:** small (<half day)
- **Fix sketch:** Adopt the `member_statement.php` rule (SEC-008) on all five: compute `$is_leader = isAdmin() || canCreate('manage_contributions')`, and for non-leaders require the fetched row's `member_id` to equal the viewer's own `customer_id`, else 403. Add `requireViewPermission` to the two that lack it.

---

### [SEC-008] Three divergent member self-access rules; the two role-name ones fail open on an unmatched role
- **Severity:** S1
- **Confidence:** confirmed
- **Location:** `app/bms/customer/customer_details.php:12-21`, `app/bms/customer/edit_customer.php:18-27`, `app/bms/customer/submit_contribution.php:7-13`; correct implementation at `app/constant/reports/member_statement.php:18-21`; role source at `header.php:29-30`
- **Evidence:**
  ```php
  if (str_contains($user_role_lower, 'member') || str_contains($user_role_lower, 'mwanachama') || str_contains($user_role_lower, 'mjumbe')) {
  ```
- **Impact:** `$user_role_lower` is `strtolower($user['role_name'] ?? 'user')` from a **LEFT JOIN** on `roles` (`header.php:17-30`). The restriction is therefore an allow-by-default list keyed on a free-text, admin-editable DB column, and it fails open three ways:
  1. **Orphan role.** Any user whose `role_id` has no matching `roles` row falls back to the literal string `'user'`, which contains none of the three needles. The block is skipped entirely and that account reads *and edits* every member record. `role_permissions` has an FK, but `users.role_id` does not (MAP §3.1: 5 FKs total), so a deleted or renumbered role silently promotes its holders.
  2. **Rename.** Renaming the member role from "Mwanachama" to the grammatically correct plural "Wanachama" — a plausible one-word settings edit — drops the substring and disables the restriction for the entire membership at once. No error, no log, no visible change.
  3. **No permission gate underneath.** `edit_customer.php` has *no* `requireEditPermission()` at all; Pattern 5 is its only control. Once the substring test misses, the page grants full edit of any member's NIDA, phone, next-of-kin and beneficiary data. (The eventual save at `api/process_edit_customer.php:15` does check `canEdit('customers')`, which limits write impact to roles that legitimately hold it — but the *read* of every field is unconditional.)

  False positives run the other way and are a functional break rather than a hole: a leadership role named "Committee Member", "Board Member" or "Mjumbe wa Halmashauri" matches the needles and gets locked to its own record.

  `customer_details.php` does have real defence in depth at `:44-47` (`canSeeMemberSensitiveData()` + `vk_mask_member_row()`), so its worst case is a masked row rather than a full one. `edit_customer.php` has no such backstop.

  **`member_statement.php:18-21` is the pattern that should win.** It tests a *capability* (`isAdmin() || canCreate('manage_contributions')`) rather than a role name, and it degrades by *forcing* `$member_id = $own_cid` instead of redirecting — so a miss returns the user's own data rather than someone else's. Its comment at `:10-14` documents that the previous permission-key-only approach had exactly the SEC-007 bug.
- **Fix effort:** small (<half day)
- **Fix sketch:** Extract `member_statement.php:14-21` into a helper (`vk_scope_to_own_member(?int $requested): int`) in `core/permissions.php`; call it from all three Pattern 5 sites and delete the `str_contains` tests. Add `requireEditPermission('customers')` to `edit_customer.php`.

---

### [SEC-009] No session ID regeneration anywhere — session fixation
- **Severity:** S1
- **Confidence:** confirmed
- **Location:** `actions/login.php:58-66`; `grep -rn session_regenerate_id` over the whole tree returns zero hits
- **Evidence:**
  ```php
  // Success - Proceed with session setup
  require_once __DIR__ . '/../core/permissions.php';
  $_SESSION['user_id'] = $user['user_id'];
  ```
- **Impact:** The session ID the browser held before authenticating is the same one it holds after. An attacker who can set a `PHPSESSID` cookie on the victim's browser — via any XSS on the origin, a subdomain cookie write, or a physical/shared-device moment — fixes a known ID, waits for the victim to log in, and is then authenticated as them. The cookie flags are correct (`roots.php:20-24`, `httponly`, `secure` when HTTPS, `SameSite=Lax`), which removes the trivial network path but not the fixation path. Compounding factors, all confirmed: **no idle timeout**, **no absolute lifetime** (`session_set_cookie_params` lifetime `0`), and **no login rate limiting or account lockout** anywhere in `actions/login.php` — failed attempts are logged via `logFailedLogin()` (`:86,91`) but never counted or throttled, so the same file is also an unthrottled credential-stuffing target. `logout.php` is correct (clears `$_SESSION`, expires the cookie, calls `session_destroy()`), so the defect is confined to login.
- **Fix effort:** trivial (<1h)
- **Fix sketch:** `session_regenerate_id(true);` immediately before the first `$_SESSION[...] = ` at `actions/login.php:61`. Separately store `$_SESSION['last_activity']` and `['login_time']` and enforce both in `header.php:6-9` and `includes/require_auth.php`. Add a simple per-username/per-IP attempt counter keyed off the existing `activity_logs` writes.

---

### [SEC-010] Revoked permissions stay live until the user logs out
- **Severity:** S1
- **Confidence:** confirmed
- **Location:** `actions/login.php:76-78` → `core/permissions.php:16-55` (cache write at `:49`); `reloadPermissions()` defined at `core/permissions.php:344-349` with no callers
- **Evidence:**
  ```php
  $_SESSION['permissions'] = $permissions;   // core/permissions.php:49 — written once, at login
  ```
- **Impact:** The entire `role_permissions` grid is snapshotted into the session at login and never re-read. Every `canView`/`canCreate`/`canEdit`/`canDelete`/`canReview`/`canApprove` call — and therefore every one of the six authorisation patterns — reads that snapshot. Revoking a permission from a role, or demoting a role, has **no effect on anyone currently logged in**; with no idle timeout and no absolute lifetime (SEC-009) that session can persist indefinitely. The concrete scenario: a treasurer is suspended for suspected misappropriation, the chairperson strips their `expenses`/`journals` rights in the settings UI, and the treasurer's open browser tab retains full approve-and-mark-paid authority over group money until they choose to log out. `reloadPermissions()` was written for exactly this and was never wired up. Note the sibling gap: `actions/update_user_status.php` and `update_user_role.php` mutate `users.status`/`role_id` but the session gate (`header.php:6-9`) only tests `isset($_SESSION['user_id'])` — it never re-checks `status`, so a user suspended mid-session also keeps their session.
- **Fix effort:** small (<half day)
- **Fix sketch:** Call `reloadPermissions()` from `header.php` when a `permissions_version` counter in `group_settings` (bumped by the role-editing screens) differs from the one cached in the session — cheap, and avoids a per-request join. Re-validate `users.status` in the same place.
- **Prior art:** `[KNOWN-UNFIXED]` — flagged as a live gap in MAP §5.1; not in `Vikundi_Audit_Findings.md`.

---

### [SEC-011] `uploads/` is served directly by Apache with no access control — private documents and signatures are anonymously fetchable
- **Severity:** S1
- **Confidence:** likely
- **Location:** no `.htaccess` in `uploads/`, `documents/`, or `downloads/` (only `backups/.htaccess` exists); root `.htaccess:28-31`; storage paths at `ajax/quick_upload_document.php:30,52` and `ajax/save_drawn_signature.php:34,41`; the gate this bypasses is `app/constant/document/document_library.php:180-188`
- **Evidence:**
  ```apache
  RewriteCond %{REQUEST_FILENAME} -d [OR]
  RewriteCond %{REQUEST_FILENAME} -f
  RewriteCond %{REQUEST_FILENAME} !\.php$
  RewriteRule ^ - [L]          # .htaccess:28-31 — any existing non-PHP file is served as-is
  ```
- **Impact:** `downloadDocumentLocal()` correctly enforces `vk_user_can_access_document()` (`document_library.php:181-188`) — but that only guards the `?action=download` route. The file itself sits at `uploads/document_library/<uniqid>_<name>.ext`, and rule 3 above serves any existing non-`.php` file to anyone, with no session. A document uploaded as `access_level = 'private'` (which is the hard-coded default at `quick_upload_document.php:62`) is therefore readable by URL alone, bypassing the access matrix entirely. The same applies to e-signature images at `uploads/signatures/<user_id>/…` — anonymous retrieval of a member's signature graphic is a document-forgery input, and the `<user_id>` path segment makes the directory structure guessable. Filenames are the mitigating factor and they are uneven: signatures use `bin2hex(random_bytes(8))` (64 bits, sound), documents use `uniqid()` — microsecond-derived and predictable within a known upload window when the original filename is known or guessable, which it often is (`Katiba.pdf`, `Minutes_July.docx`). Marked `likely` rather than `confirmed` because the outcome depends on the deployed server honouring the committed `.htaccess`; MAP §11.2 already flags that as unverified.

  Upload validation itself is sound and I found no defect there: `quick_upload_document.php:41-50` uses an extension allowlist, a 50 MB cap, `basename()` + `preg_replace('/[^a-zA-Z0-9._-]/','_')`, a `uniqid()` prefix and `move_uploaded_file()`; `save_drawn_signature.php:24-31` requires a `data:image/png;base64,` prefix and generates the filename server-side. No path traversal and no `.php` write is reachable. The residual is the missing execution guard: a `.phtml`/`.php5` upload is blocked by the extension allowlist today, but nothing in `uploads/` would stop one executing if the allowlist ever widened.
- **Fix effort:** trivial (<1h)
- **Fix sketch:** Add `uploads/.htaccess` and `documents/.htaccess` with `Require all denied` (copy `backups/.htaccess` verbatim), and route every document/signature render through the already-correct `downloadDocumentLocal()` gate. Switch `uniqid()` to `bin2hex(random_bytes(16))` as defence in depth.

---

### [SEC-012] CSRF enforced on 33 of 275 endpoints; zero in `ajax/`; the global wrapper covers only `fetch()`
- **Severity:** S1
- **Confidence:** confirmed
- **Location:** `header.php:106-146` (meta tag + `window.fetch` monkey-patch); gate at `includes/require_csrf.php:24-40`; coverage measured across `actions/`, `ajax/`, `api/`
- **Evidence:**
  ```js
  if (window.__vkCsrfFetchPatched || !window.fetch) return;
  ...
  headers.set('X-CSRF-Token', token);        // header.php:135-141
  ```
- **Impact:** The infrastructure is well built — `csrf_verify()` uses `hash_equals` (`includes/csrf.php:38`), tokens are 32 random bytes (`:19`), and `csrf_extract_token()` accepts both the header and the POST field (`:62-68`). The problem is reach, on both sides:
  - **Server side:** only 33 of 275 endpoints `require_once` the gate. Every unprotected mutator is forgeable — including SEC-001's `void_journal`/`update_journal`/`add_compound_journal`, which combine into a one-click "log in as any member, visit my page, your ledger is now wrong".
  - **Client side:** the wrapper patches `window.fetch` only. It does **not** cover (a) classic `<form method="POST">` submissions, which the app uses heavily (e.g. `app/bms/customer/edit_customer.php:137`) and which never call `fetch`; (b) `XMLHttpRequest`; (c) **`jQuery.ajax`**, which is jQuery 3.7.1's own XHR wrapper and is the dominant AJAX idiom in this codebase (DataTables' server-side mode issues every list request through it). So the pages most likely to mutate state are the ones the wrapper misses.
  - It also only runs on pages that include `header.php`, which by definition excludes every `api/` and `ajax/` caller invoked from a page that doesn't.

  Net: the wrapper protects the newest `fetch()`-based screens and nothing else, while the coverage number (33/275) suggests broader protection than exists.
- **Fix effort:** medium (1-3 days)
- **Fix sketch:** Extend the wrapper to `$.ajaxSetup({ headers: { 'X-CSRF-Token': token } })` and patch `XMLHttpRequest.prototype.open`/`send`; emit `csrf_field()` into every server-rendered `<form>`. Then make `includes/require_csrf.php` the default by requiring it from a small shared bootstrap that every `actions/`/`ajax/`/`api/` file includes, rather than opting in file by file.
- **Prior art:** `[KNOWN-UNFIXED]` — audit H6. The gate and wrapper shipped; the rollout did not.

---

### [SEC-013] `autoEnforcePermission()` with no argument is a permanent no-op — any member can read any journal entry or expense
- **Severity:** S1
- **Confidence:** confirmed
- **Location:** `core/permissions.php:493-511` (map at `:356-397`, exclusion list at `:505`); the four no-arg call sites are `app/constant/accounts/journal_details.php:15`, `expense_details.php:15`, `journals.php:12`, `edit_journal.php:16`
- **Evidence:**
  ```php
  $currentPage = basename($_SERVER['PHP_SELF']);            // :501 — always "index.php"
  $excludedPages = ['dashboard.php', 'my-dashbord.php', 'index.php'];   // :505
  if (isset($mapping[$currentPage]) && !in_array($currentPage, $excludedPages)) { ... }
  ```
- **Impact:** This is not "unmapped pages fall through" — the no-arg form **cannot ever enforce anything**. Every `app/` page is reached through the front controller (`.htaccess:34` rewrites everything not under `actions|ajax|api` to `index.php`; `.htaccess:16-18` even 301s a direct `.php` hit onto the clean URL first), so `$_SERVER['PHP_SELF']` is always `/index.php` and `$currentPage` is always `index.php`. That key is absent from the 30-entry map *and* present in `$excludedPages`, so the condition is false on both clauses and the function returns having checked nothing. The 30-entry mapping table is dead code.

  Splitting the 21 call sites by argument form localises the damage precisely:
  - **17 pass an explicit key** (`chart_of_accounts.php:15`, `transactions.php:15`, `budget.php:50`, `trial_balance.php:11`, `invoices.php:7`, …). These short-circuit at `:496-499` into `requireViewPermission($pageKey)` before the broken derivation is reached, and enforce correctly. They are fine.
  - **4 pass nothing**, and all four are financial pages in `app/constant/accounts/`.

  Of those four, two are dead on arrival and two are live:
  - `journals.php:12` — dead. `:15` redeclares `safe_output()`, already defined at `helpers.php:651` (loaded by `roots.php:95`); the include fatals on `Cannot redeclare`. This is MAP §1.3's third duplication axis surfacing as a blank page.
  - `edit_journal.php:16` — dead. `:9` calls `includeConfig()` and `:13` calls `requireAuth()`, **neither of which is defined anywhere in the codebase** (only `includeHeader()` exists, `roots.php:1427`). Fatals at `:9`, before the permission line. Ironically `:17` holds a correct `requireEditPermission('journals')` that never runs.
  - **`journal_details.php:15` and `expense_details.php:15` — live and exploitable.** Both are clean: `includeHeader()` is defined, nothing is redeclared, and both are routed (`roots.php:151-152, 157-158`). `includeHeader()` pulls `header.php`, so the `header.php:6-9` authentication gate does apply — but authorisation does not. Any logged-in account, including an ordinary view-only Member, can walk `?id=1..N` and read every journal entry in full (all debit and credit legs, accounts, amounts, narration) and every expense detail record. The pages *look* gated to anyone reading them, which is why this survived.

  This is the same class as SEC-007 — a control that appears present and is not — but with a worse mechanism, because here the control cannot work by construction rather than merely being the wrong key.
- **Fix effort:** small (<half day)
- **Fix sketch:** Delete `autoEnforcePermission()` and `getPagePermissionMapping()` outright; convert the 17 explicit-key sites to `requireViewPermission('<key>')` (a mechanical substitution — it is what they already resolve to) and give the four no-arg sites real keys (`journals`, `expenses`). That removes one of the six competing patterns at the same time. If the function must stay, make the derivation failure fail closed rather than fall through.

---

### [SEC-014] `hasPermission()` grants delete rights to anyone holding view
- **Severity:** S1
- **Confidence:** confirmed
- **Location:** `core/permissions.php:150-169`; misused at `api/delete_purchase_return.php:8`, `api/get_purchase_returns.php:142,156`
- **Evidence:**
  ```php
  function hasAnyPermission($pageKey) { ... return canView($pageKey) || canEdit($pageKey) || canDelete($pageKey); }
  // api/delete_purchase_return.php:8
  if (!hasPermission('delete_purchase_returns')) { ... }
  ```
- **Impact:** `hasPermission()` is an alias for `hasAnyPermission()`, i.e. an OR across view/edit/delete. Using it to gate a destructive operation means **`can_view` alone authorises the delete** — the finest-grained part of the RBAC grid is discarded at the point it matters most. `api/delete_purchase_return.php` then executes two `DELETE` statements inside a transaction (`:34-42`). This also corrects MAP §2.5: the endpoint is *not* unauthenticated (the check fails closed for anonymous callers, since `isAdmin()` and the session permission array are both empty), but it is under-authorised. `api/get_purchase_returns.php:142,156` uses the same call to decide whether to render approve/delete buttons, so the UI and the endpoint agree — on the wrong rule. Blast radius is currently limited: `purchase_returns` is part of the inherited BMS tree (MAP §3.4 / the ~64 dead tables) and is probably unused in production. The pattern is the finding; if `hasPermission()` is ever reached for by a live module it repeats.
- **Fix effort:** trivial (<1h)
- **Fix sketch:** Replace with `requirePermissionJson('delete','purchase_returns')`. Consider renaming `hasPermission()` to `hasAnyPermission()` at every call site so its semantics are unmissable, or deleting it.

---

### [SEC-015] Admin and treasurer status depend on free-text role names; a rename grants or revokes silently
- **Severity:** S1
- **Confidence:** confirmed
- **Location:** `core/permissions.php:263-274` (`isAdmin()`), `:282-289` (`canMarkPaid()`)
- **Evidence:**
  ```php
  $admin_roles = ['admin', 'administrator', 'chairperson', 'mwenyekiti', 'chairman'];
  return (isset($_SESSION['role_id']) && in_array((int)$_SESSION['role_id'], [1, 2, 12])) ||
         (isset($_SESSION['role']) && in_array(strtolower($_SESSION['role']), $admin_roles)) || ...
  ```
- **Impact:** `isAdmin()` is the universal bypass — every `can*()` helper returns true immediately when it does (`:66,82,99,116,129,139`). It grants full access on three independent grounds, two of which are `roles.role_name` string equality against a hard-coded list, checked against **two** session keys (`role` and `user_role`) that are populated from different columns at `actions/login.php:63-64` (`role_name` vs the legacy `users.user_role` free-text column). Consequences, all reachable from the settings UI without touching code:
  - **Escalation by rename.** Renaming any role to "Chairman" — or creating one — grants that role full administrative access to users, roles, settings, backups and the ledger, bypassing `role_permissions` entirely. The same applies to `canMarkPaid()`: naming a role "Mhasibu" hands it authority to record that money left the account.
  - **Revocation by rename.** Renaming role 2 from "Chairperson" to "Mwenyekiti Mkuu" removes the string match; the holder keeps admin only because `role_id ∈ {1,2,12}` still matches. Renaming a role whose id is *not* in that set removes admin outright.
  - **Legacy id 12** is retained as "legacy admin" with no comment on whether that row still exists, so the id list cannot be safely pruned.
  - `users.user_role` is a free-text column with no FK; anything that writes it (`actions/process_registration.php:252-255` inserts it directly from the registration flow) is a potential path to setting a session value that matches `$admin_roles`.

  The docblock at `:265-268` is careful and correct about *intent* (Secretary and Treasurer are deliberately not admins); the mechanism cannot enforce that intent because it depends on a mutable label.
- **Fix effort:** small (<half day)
- **Fix sketch:** Make `isAdmin()` depend on a single immutable signal — an `is_admin` boolean column on `roles`, or a dedicated `system_admin` permission key seeded through `role_permissions` — and delete the string lists and the `role`/`user_role` dual read. Same for `canMarkPaid()` (`mark_paid` permission key).

---

### [SEC-016] Unauthenticated endpoint sends SMS to the entire membership
- **Severity:** S2
- **Confidence:** confirmed
- **Location:** `actions/contribution_reminders.php:1-14,91-120`; served directly by `.htaccess:23-25`
- **Evidence:**
  ```php
  require_once __DIR__ . '/../includes/config.php';
  require_once __DIR__ . '/../includes/sms_helper.php';
  $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'group_settings'");
  ```
- **Impact:** The file is a cron body with no CLI guard and no auth — the header comment says "Use absolute paths for CLI compatibility" but nothing restricts it to CLI (contrast `actions/calculate_penalties.php:269`, which *does* wrap its work in `php_sapi_name() === 'cli'` and is inert over HTTP). Anyone can hit the URL. Two limits keep this at S2 rather than S1: it exits early unless `monthly_rate > 0` (`:20-22`), and it only fires inside four narrow windows relative to the due date (`:44-48`), so it cannot be replayed for unbounded cost on demand. Within a window, repeated requests bill the group for SMS at the gateway and spam every member — the `auto_reminder_logs` insert at `:74` appears to be a dedupe record, but it is written after sending. Message construction itself is safe: `includes/sms_helper.php:257-289` builds every gateway request with `http_build_query()`/`rawurlencode()`, so there is no header or parameter injection into the SMS transport from member names or amounts.
- **Fix effort:** trivial (<1h)
- **Fix sketch:** Wrap the whole file in `if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }`, or move it out of the web root and invoke it from cron by absolute path.

---

### [SEC-017] Dead unauthenticated endpoints and a stub still routed
- **Severity:** S3
- **Confidence:** confirmed
- **Location:** `actions/calculate_penalties.php:2`; `roots.php:614,949` (route `ajax/calculate_penalties` and `api/calculate_penalties` to `AJAX_DIR . '/calculate_penalties.php'`, which does not exist); `ajax/get_access_log.php:4-9`; `api/dashboard_updates.php:12-20`; `api/helpers/transaction_helper.php`; four zero-byte files in `api/document/`
- **Evidence:** `actions/calculate_penalties.php:2` — `require_once '../config.php';` — there is no `config.php` at the repo root.
- **Impact:** Not exploitable, but each is a trap for the next reader and for any future automated auth scan. `actions/calculate_penalties.php` fatals on include, so the loan-penalty mutator MAP §2.5 lists as a live unauthenticated writer cannot run over HTTP at all; it is dead code carrying a live-looking name. Two routes point at a non-existent `ajax/calculate_penalties.php` and resolve to the `COMING_SOON_FILE` path (`roots.php:1313`). `ajax/get_access_log.php` returns hard-coded zeros and `api/dashboard_updates.php` returns a static placeholder — both read as working features. `api/helpers/transaction_helper.php` is a library with no guard sitting inside a web-served directory; it defines functions and produces no output when hit directly, but it should not be reachable. The four zero-byte `api/document/*.php` files (`send_template_email`, `update_customer_document`, `update_loan_document`, `upload_collateral_doc`) appear in any marker-based auth scan as unauthenticated endpoints and will keep generating false findings.
- **Fix effort:** trivial (<1h)
- **Fix sketch:** Delete the dead files and their routes; move `api/helpers/` outside the web root or add a `defined('ROOT_DIR') || exit;` header. Extend `tests/Unit/NoWebRootDebugScriptsTest.php` to assert no zero-byte PHP under `api/`.

---

### [SEC-018] Database error text returned to the client on several endpoints
- **Severity:** S3
- **Confidence:** confirmed
- **Location:** `api/search_members_with_phone.php:33`, `actions/reset_password.php:50`; the pattern recurs across `api/`
- **Evidence:**
  ```php
  } catch (Exception $e) { echo json_encode(['results' => [], 'error' => $e->getMessage()]); }
  ```
- **Impact:** With `ERRMODE_EXCEPTION` set (`includes/config.example.php:16`), `getMessage()` on a PDO failure carries the SQLSTATE, the driver message and often the offending SQL fragment with table and column names. On `search_members_with_phone.php` that text is returned to an **anonymous** caller (SEC-004), turning a failed query into free schema disclosure and giving an attacker probing the SEC-006 injection sites direct error-based feedback. `actions/reset_password.php:50` prefixes it with "Database error: " and returns it during an unauthenticated password-reset flow. `roots.php:5-10` correctly gates `display_errors` on `vikundi_is_dev_host()` (audit B1, fixed) — these hand-written echoes route around that control.
- **Fix effort:** small (<half day)
- **Fix sketch:** Return a fixed string and `error_log($e->getMessage())` instead. A repo-wide grep for `getMessage()` inside `json_encode` finds the rest.

---

## Verified sound — do not re-report

Recorded so the next pass does not spend time here.

| Area | Verdict |
|---|---|
| **Shim parity, all 33 pairs** | No divergence. Every top-level shim is exactly `<?php` + `require_once __DIR__ . '/account/<name>.php';` with nothing before it, so the target's guards always run first. The duplication is Architecture's problem, not a bypass. Corollary: fixing SEC-001 in `api/account/` fixes both URLs — and a guard placed on a *shim* instead would be bypassable via the canonical URL, so always harden the target. |
| **17 of 21 `autoEnforcePermission()` call sites** | Correct. Passing an explicit key short-circuits at `core/permissions.php:496-499` into `requireViewPermission()` before the broken derivation runs. Only the 4 no-arg sites are defective (SEC-013). |
| **`core/backup.php:57-76` identifier interpolation** | Not injectable. `$table`/`$view` come from `SHOW FULL TABLES` (`:34-46`), i.e. the server, never a request. Values are backtick-quoted; row data goes through `$pdo->quote()` (`:64`). Correctly written for a case where binding is impossible. |
| **Password policy (audit M6)** | **Fixed.** `includes/registration_validator.php:94-113` enforces ≥8 chars, ≥1 letter, ≥1 digit, and is called from all six password-setting paths: `actions/reset_password.php:26`, `actions/process_registration.php:13`, `actions/add_member.php:172`, `app/constant/settings/add_user.php:103`, `edit_user.php:115`, `app/constant/profile/profile.php:379`. Hashing is `password_hash` throughout. |
| **Reset-token flow** | Sound. `actions/forgot_password.php:31` uses `bin2hex(random_bytes(16))` (128 bits); the token is held server-side in `$_SESSION`, compared against the URL token (`reset_password.php:10`), expires at 3600s, and is unset on use (`actions/reset_password.php:44-46`). Not guessable, not replayable. |
| **`api/download_backup.php` path handling** | Traversal-safe: `realpath()` + `strpos($filepath, realpath($backup_dir)) !== 0` + `.sql` extension check (`:18-25`). Only the authorisation is wrong (SEC-002). |
| **Upload validation** | `ajax/quick_upload_document.php:41-56` and `ajax/save_drawn_signature.php:24-45` are both correct — extension allowlist, size cap, sanitised/server-generated filenames, `move_uploaded_file()`. No traversal, no `.php` write. The gap is storage-side (SEC-011). |
| **`ORDER BY` at 4 of 10 sites** | Safe: direction normalised via ternary at `api/get_transactions.php:91`, `api/get_documents.php:88`, `api/get_user_signatures.php:25`, `app/bms/product/products.php:174`. Column resolution is index-into-whitelist at all ten sites. |
| **AI endpoints** | Properly gated: `api/ai/ask.php:17-18`, `chat.php:12-13`, `generate.php:12-13`, `save_settings.php`, `test_connection.php:12` all pair `isAuthenticated()` with a permission key, and `ai_assistant`/`ai_ask_data`/`ai_settings` are all in `vk_member_hidden_keys()` (`includes/role_grants.php:38-39,36`). `core/ai_prompt_builder.php:26-50` reads templates through bound prepared statements. |
| **Document access rules** | `includes/document_access.php` and `authored_document_access.php` are pure, well-documented, correct, and correctly applied at `document_library.php:181-188`. |
| **`logout.php`** | Correct: clears `$_SESSION`, expires the cookie with the original params, calls `session_destroy()`. |
| **`requirePermissionJson()` / `require_auth` / `require_csrf` / `csrf`** | The gates themselves are correctly implemented and fail closed. Their problem is adoption, not logic. |

---

## Which authorisation pattern should win

Of the seven variants in MAP §5.3, **Pattern 3 (`requirePermissionJson`) for JSON endpoints and Pattern 1 (`requireViewPermission`) for UI pages** are the only two that are correctly implemented, fail closed, and key off `role_permissions` rather than a label. Pattern 7 (`member_statement.php:18-21`) is not a competitor but the missing *ownership* layer that must sit underneath both — a permission key answers "may this role reach this screen", never "may this user see this row", and SEC-007 is exactly what happens when the first is mistaken for the second.

Retire: Pattern 2 (`autoEnforcePermission`, SEC-013 — the no-arg form cannot enforce at all, and its 17 sound call sites are just Pattern 1 with extra steps), Pattern 5 (role-name substring, SEC-008 — fails open on rename), Pattern 6 (`isAuthenticated()`-only, SEC-001/SEC-007 — no authorisation at all). Pattern 4 (inline `canX()` + hand-rolled response) is correct in substance but writes ~110 different failure responses; fold it into Pattern 3.

The structural fix behind all of it is SEC-020's: enforce centrally in `header.php` off the route name, so a page is gated by default and opting *out* is the explicit act. Every finding in this report except SEC-006 and SEC-011 is ultimately a page or endpoint that forgot to opt in.

## Ranking of the unauthenticated surface by what an attacker actually gets

1. **Full member + family PII** — SEC-003, SEC-004. Names, phones, spouses, children. Directly monetisable in-market; carries data-protection exposure.
2. **Full financial position** — SEC-005. Bank balance, chart of accounts, every expense.
3. **The complete RBAC matrix** — SEC-019. Not damaging by itself, but it is the map that makes 1, 2 and the authenticated S0s cheap to find and target. Fix it alongside them.
4. **SMS spend** — SEC-016. Window-limited.
5. **Nothing else.** The two mutators MAP §2.5 names are a dead file (SEC-017) and a permission-gated one (SEC-014). There is **no live unauthenticated write path** in `actions/`, `ajax/` or `api/` — the write-side catastrophes (SEC-001, SEC-002) all require *a* session, just not the right one.

---

## Coverage gaps

- **Not executed.** Nothing was run — no request, no query, no test suite. Every claim is static (RoE 1–2). Whether the ~30 unauthenticated endpoints respond in production depends on the deployed server honouring the committed `.htaccess`; MAP §11.2 flags this and it remains open. One `curl` per endpoint with no cookie would settle it.
- **`register.php` (1,092 ln) not read in full.** I verified its password path only (via `actions/process_registration.php:13` → `registration_validator.php`). Its own input handling, file upload of avatars, and duplicate-account logic are unexamined. Same for `app/constant/profile/profile.php` (175 KB) beyond its `reg_password_errors` call at `:379`.
- **The ~55 SESS-only `api/` endpoints were sampled, not enumerated.** I read the five the brief names plus `get_expenses`, `process_edit_customer`, `get_documents`, `get_transactions`, `get_user_signatures`. The remainder almost certainly contain more SEC-001-class gaps; the reproducible fix is the repo-wide marker test proposed in SEC-005 rather than more reading.
- **The 23 "check but never enforce" pages (SEC-020) were identified, not individually traced.** I have the list and the measurement, but I did not follow each page's form/AJAX target to its handler. The pairing that would turn this from S2 into S1 is an admin settings screen that hides a control via `isAdmin()` while its handler is SESS-only — `app/constant/settings/{user_roles,manage_permissions,backup_restore}.php` are the three to check first, and `backup_restore.php` is already suspicious given SEC-002. This is the highest-value single follow-up in my slice.
- **`app/constant/accounts/add_journal.php` and `edit_journal.php` are called dead on the strength of `includeConfig()` being undefined tree-wide** (`grep -rn "function includeConfig"` returns nothing), and `journals.php` on the `safe_output()` redeclaration against `helpers.php:651`. Both are sound static reads, but PHP's exact binding behaviour for a redeclared top-level function in an included file is worth one runtime confirmation before anyone relies on those three pages being unreachable. If `journals.php` in fact renders, SEC-013's live set grows from two pages to three.
- **XSS not assessed.** Output escaping is MAP §7.3 and was out of my slice; I did not evaluate `safe_output()` coverage. This matters for SEC-009 — session fixation is materially worse if a stored-XSS sink exists, so someone should close that loop.
- **`includes/email_helper.php` (20 KB) not read.** I read `sms_helper.php` far enough to confirm the transport is injection-safe; the email equivalent (header injection via member-supplied names into `To:`/`Subject:`) is unverified. `api/document/send_template_email.php` is zero-byte, so at least that trigger is inert.
- **`actions/auto_terminate_members.php`** — unauthenticated and mutating, runs on every page load via `header.php:4`. I confirmed it is throttled to once daily (`:176`) and MAP §9.2/M4 records it as inert pending a definition of "dormant". I did not read its mutation body; whether the sweep is genuinely a no-op is Agent 3's call.
- **Session storage.** I read the cookie configuration (`roots.php:14-27`) but not the server-side handler — default files-based storage on shared cPanel hosting can be world-readable to other tenants in `/tmp`. Would need `session.save_path` from the live `php.ini`.
- **`deploy-hook.php`** (audit L2) is Architecture's file; I did not re-verify its HMAC comparison for timing safety.
