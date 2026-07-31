# 09 — Database Verification

_Remediation Batch 2, item 4. Read-only: every statement executed was `SELECT` or `SHOW`.
No `ALTER`, `INSERT`, `UPDATE`, `DELETE`, migration or schema change of any kind was run._

---

## ⚠️ Read this before using any figure below

**These queries did not run against production.** No credentials for `bjptechn_vikundi` were
available in this environment — no `~/.my.cnf`, nothing in the environment, and
`includes/config.php` points at a local database.

They ran against the **local `vikundi` database**, which is populated and looks like a restored
copy or a working sync: 182 tables, 338 `users`, 326 `customers`, 573 `contributions`, 1,148
`activity_logs`. The member count is consistent with the ~327 figure in
`Vikundi_System_Quality_Pass_24_July_2026.html`.

How much each verdict transfers:

| Question type | Transfers to production? | Why |
|---|---|---|
| **Storage engine / charset** (Block A) | **Very likely** | Engines are set at `CREATE TABLE` from the same dumps and migration scripts. But DATA-012 established `sync_schema.php` is create-only, so a table created on production at a different time *could* differ. |
| **Column existence** (B2, J) | **Very likely**, and already proven divergent — see the `role_name_sw` finding, which is itself an instance of the drift |
| **`sql_mode`** (Block C) | **Unknown** — this is server configuration, not schema. Must be re-run on production. |
| **Row counts** (B1, D, E, F, G, I) | **Indicative only** | Production has been live longer. `activity_logs` in particular will be far larger. |

Everything below is labelled with the confidence that follows from this table. **Re-run Block A and
Block C against production before acting on the engine migration.**

---

## Block A — Engine and charset census

### Result

**72 MyISAM, 110 InnoDB** across 182 tables. The MyISAM count matches
`database/schema_sync.sql`'s census exactly; the InnoDB count is 6 higher, consistent with the ≥12
drift tables (DATA-016) having been created later, as InnoDB.

| Table | Engine | Collation | Rows |
|---|---|---|---:|
| `users` | **MyISAM** | **latin1_swedish_ci** | 338 |
| `loans` | **MyISAM** | **latin1_swedish_ci** | 0 |
| `fines` | **MyISAM** | utf8mb4_unicode_ci | 9 |
| `death_expenses` | **MyISAM** | utf8mb4_unicode_ci | 2 |
| `general_expenses` | **MyISAM** | utf8mb4_unicode_ci | 0 |
| `petty_cash_vouchers` | **MyISAM** | utf8mb4_unicode_ci | 0 |
| `budgets` | **MyISAM** | utf8mb4_0900_ai_ci | 0 |
| `member_payouts` | **MyISAM** | utf8mb4_unicode_ci | 0 |
| `bank_reconciliations` | **MyISAM** | utf8mb4_0900_ai_ci | 0 |
| `group_settings` | **MyISAM** | utf8mb4_unicode_ci | 7 |
| `contributions` | InnoDB | utf8mb4_unicode_ci | 573 |
| `customers` | InnoDB | utf8mb4_unicode_ci | 326 |
| `activity_logs` | InnoDB | utf8mb4_general_ci | 1,148 |
| `journal_entries` | InnoDB | utf8mb4_general_ci | 0 |
| `journal_entry_items` | InnoDB | utf8mb4_general_ci | 0 |
| `accounts` | InnoDB | utf8mb4_general_ci | 0 |
| `expenses` | InnoDB | utf8mb4_general_ci | 0 |
| `budget_items` | InnoDB | utf8mb4_0900_ai_ci | 0 |

### Verdicts

**MERGED-ENGINE (DATA-001 + FIN-016 + FIN-017), S0 — CONFIRMED.**
The engine split predicted from the stale dump is exactly what the live schema has. Every table the
analysis named as MyISAM is MyISAM. `beginTransaction()` over `users`, `fines`, the three expense
tables, `budgets`, `member_payouts`, `loans`, `bank_reconciliations` and `group_settings` is a silent
no-op. **The S0 stands, and the InnoDB conversion is confirmed as the next structural priority.**

The mixed-engine case in FIN-016 is confirmed concretely: `actions/process_registration.php` spans
`users` (MyISAM) and `customers` + `contributions` (InnoDB) inside one transaction, so a rollback
reverts the member and their contributions and **leaves the login credential committed**.

**DATA-013 (`users` is latin1), S2 — CONFIRMED, and it is worse than reported.** `users` is
`latin1_swedish_ci` while `customers` — which stores the same member's name — is
`utf8mb4_unicode_ci`. **`loans` is also latin1**, which the analysis did not identify; DATA-013
predicted "12 `MyISAM/latin1` tables… not individually identified beyond `users`". At least one of
the other eleven is now named.

**DATA-010 (runtime `CREATE TABLE` declares an FK onto MyISAM `users`), S2 — CONFIRMED.**
`users` is MyISAM, so the InnoDB `customer_documents` table created at
`app/bms/customer/customer_documents.php:170-195` cannot declare a foreign key referencing it. The
finding was marked `likely` pending exactly this check; it is now **confirmed**.

---

## Block B — The ledger-dead proof

### Result

| Table | Rows |
|---|---:|
| `journal_entries` | **0** |
| `journal_entry_items` | **0** |
| `chart_of_accounts` | **0** |
| `accounts` | **0** |
| `expenses` | **0** |
| `transactions` | **0** |

`journal_entries.transaction_id` — **column absent**.

### Verdicts

**The ledger is empty. The reachability triage in `VIKUNDI_ANALYSIS_SYNTHESIS.md` §2 holds, and
roughly 22 findings stay in their assigned buckets.**

- **FIN-003 — CONFIRMED.** `journal_entries.transaction_id` does not exist, so the five endpoints
  writing to it fail under every `sql_mode`.
- **SEC-001 — stays S3 / DEAD today, S0 on ledger repair.** There is nothing to void, rewrite or
  fabricate. The activation dependency in §4 is unchanged and remains mandatory.
- **FIN-001, FIN-002 — stay ACTIVATED-BY.** The trial balance and income statement render over
  empty tables. `chart_of_accounts` is confirmed empty, so FIN-002's "reads a table nothing writes"
  is exact.
- **QUAL-001 — stays S3 / ACTIVATED-BY.**

**Two results that go further than the analysis did:**

- **`accounts` is also empty (0 rows).** The synthesis assumed `accounts` was populated because the
  Chart of Accounts UI writes it, and used that to argue SEC-005's `get_accounts` endpoint leaked
  real financial data. It did not — there are no accounts. **SEC-005 is RESIZED**: the chart-of-
  accounts half of it exposed an empty result set. The member-PII half (SEC-003, SEC-004) and the
  expense half are unaffected, and gating all of them was still correct.
- **`expenses` is empty (0 rows), and `transactions` is empty.** This settles the open question in
  the synthesis §5.4 about whether SEC-013's `expense_details.php` was live: **it was not**. Both
  halves of SEC-013 read empty tables. It stays S3 / ACTIVATED-BY.

---

## Block C — `sql_mode`

### Result

```
ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,
ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
```

Session and global are identical. **`STRICT_TRANS_TABLES` is on.**

### Verdicts

**FIN-007 — the DISPUTED row is resolved. The strict branch is live, so the finding is
`S3 / DEAD`, not the orphan-row corruption the non-strict branch would produce.**
`expenses.category_id` is `NOT NULL` with no default and is not in `add_expense.php`'s insert column
list, so under strict mode the `INSERT` itself throws and **nothing is written**. The endpoint is
simply 100% broken rather than silently creating duplicate expense rows. `expenses` being empty
(Block B) is the corroborating evidence.

**FIN-004 — the strict branch is live.** `recordGlobalTransaction()`'s insert into the loan-scoped
`transactions` table fails on `loan_id NOT NULL` and the out-of-enum `transaction_type`, rather than
silently writing journal rows under a nonexistent loan #0. `transactions` being empty confirms it.

**Caveat:** this is server configuration, not schema, and is the one Block A/B/C result least likely
to transfer. **Re-run `SELECT @@global.sql_mode` on production.** If production is non-strict, both
verdicts above invert and FIN-007 returns to S1/LIVE.

---

## Block D — Contribution status vocabulary

### Result

| status | rows | total |
|---|---:|---:|
| `pending` | 524 | 7,968,000.00 |
| `approved` | 49 | 2,401,000.00 |

No `''` rows. No `confirmed` rows. No `reviewed` or `cancelled` rows.

### Verdicts

**FIN-011 — REFUTED on current data, downgrade from `likely` to no live impact.** The finding
argued that contribution reminders use a narrower status filter (`= 'approved'`) than every other
read site (`IN ('confirmed','approved','')`), so members whose contribution landed as `''` would be
dunned for money they had paid. **There are no `''` rows**, so no member is currently mis-dunned.
The filter inconsistency is real and should still be centralised, but it is a latent correctness
issue, not an active trust problem. **Re-rate S3, LATENT.**

**DATA-017 — CONFIRMED.** `'confirmed'` and `''` are indeed unreachable values: the enum cannot hold
them and no row has them. The dead branches in `includes/finance.php:49` and
`contribution_standing.php:118,266` are confirmed dead. Harmless today, exactly as reported.

**Worth flagging, outside the finding set:** 524 of 573 contributions (91%, TSh 7,968,000) sit at
`pending`. Only TSh 2,401,000 is approved and therefore counted by `getGroupFundBalance()`. Whether
that is a real backlog or an artefact of this database is a question for the group, not a code
defect — but if it reflects production, the dashboard Balance is showing roughly a quarter of the
money the group has actually collected.

---

## Block E — AGM and `initial_savings`

### Result

- AGM contributions: **0 rows, 0.00**
- `contribution_type` distribution: **`monthly` 573 rows / 10,369,000.00 — the only type present**
- Members with non-zero `initial_savings`: **0**
- DATA-003 double-count candidates: **none**

### Verdicts

**FIN-009 — RESIZED, and materially. The half the analysis called "live today" is not live.**
- The **AGM half** stays LATENT as reported: zero AGM rows, so nothing breaks yet. Confirmed.
- The **`initial_savings` half** was rated live on the strength of `actions/add_member.php:166`
  writing the column and `customers.php:37` summing it. **No member has a non-zero
  `initial_savings`**, so the group-aggregate-versus-member-statement divergence has no current
  effect. FIN-009 drops from **S1 / LIVE** to **S2 / LATENT** — both halves now await a first row.

**DATA-003 — REFUTED on current data.** The member import writes both `customers.initial_savings`
and a matching `contributions` row, which `cs_member_schedule()` would add together. No member has
both. Either the import path has not been used on this dataset, or those rows were cleaned up. The
code defect is unchanged and still worth fixing — the two onboarding routes still disagree
structurally — but **no member's savings are currently double-counted. Re-rate S2, LATENT.**

Note `entrance` never appears either: every contribution is `monthly`. `DATA-003`'s secondary
observation — that the import omits `contribution_type` and so takes the `'monthly'` DEFAULT — is
consistent with a dataset in which entrance payments are indistinguishable from monthly ones.

---

## Block F — Orphan contributions ⚠️

### Result

| | rows | amount |
|---|---:|---:|
| Orphans at `approved`/`confirmed`/`''` | **12** | **TSh 600,000.00** |
| Orphans at any status | 12 | TSh 600,000.00 |

### Verdict

**DATA-002, S0 — CONFIRMED AND SIZED. This is the most consequential result in this pass.**

Twelve contribution rows point at a `customer_id` that no longer exists, all of them `approved`.

- `includes/finance.php:49` sums `contributions` with **no join**, so all TSh 600,000 **is counted
  in the group fund shown on the dashboard**.
- `includes/contribution_standing.php:115-120` **joins `customers`**, so the same TSh 600,000 is
  **absent from every member statement and from `cs_group_savings_total()`**.

So two figures both labelled as the group's money differ by **TSh 600,000** right now, and because
the member rows are gone there is no way to attribute it. Against the TSh 2,401,000 of approved
contributions, that is **exactly a quarter of the approved fund** — not a rounding artefact.

This confirms the mechanism the finding predicted, gives the fix a concrete target, and means the
DATA-002 remediation must include a reconciliation decision for these twelve rows (reattribute,
write off, or reinstate the members), not just a code change.

---

## Blocks G & H — The history axis and index coverage

### Result

| Table | Rows | | `activity_logs` action | Rows |
|---|---:|---|---|---:|
| `activity_logs` | 1,148 | | `Viewed` | **1,036 (90%)** |
| `contributions` | 573 | | `Login` | 71 |
| `death_expenses` | 2 | | `Created` | 16 |
| `notifications` | 1 | | `Updated` | 8 |
| `general_expenses` | 0 | | `Deleted` | 7 |
| `access_log` | **0** | | `Approved` | 6 |

Indexes on the hot tables:

| Table | Indexes present |
|---|---|
| `activity_logs` | PK, `created_at`, `(user_id,created_at)`, `(module,created_at)`, `action` |
| `contributions` | PK, `member_id`, `contribution_date`, `status`, `created_at`, `(status,contribution_date)` |
| `customers` | PK, `customer_code` ×2, `customer_name`, `customer_type`, `status` |
| `death_expenses` | PK, `member_id` |
| `general_expenses` | **PK only** |
| `journal_entry_items` | **PK only** |
| `member_payouts` | **PK only** |
| `notifications` | **PK only** |
| `users` | **PK only** |

### Verdicts

**PERF-001 — CONFIRMED, and the ratio is worse than estimated.** **90% of `activity_logs` is
`Viewed` navigation noise** (1,036 of 1,148). The fix sketch — stop writing `Viewed` rows, or split
them into a separate table — would shrink the audit trail by an order of magnitude at no cost to its
evidentiary value. Every `Created`/`Updated`/`Deleted`/`Approved` row combined is 37 entries.

**PERF-010 — CONFIRMED. `access_log` has 0 rows and no writer**, so `profile.php:515-533` runs two
non-sargable scans against a permanently empty table. Pure dead cost, exactly as reported.

**DATA-008 / PERF-002 / PERF-005 / PERF-007 — CONFIRMED.** The missing indexes are missing:
`general_expenses` and `member_payouts` and `notifications` and `journal_entry_items` and `users`
each carry a primary key and nothing else. `contributions` and `activity_logs` are well covered, as
the analysis said.

**PERF-014 — CONFIRMED.** `users.role_id` carries no index, and the correction stands: with only 338
rows and both sides of the header join driven by primary keys, it does not matter. Index effort
belongs on `general_expenses.status`, `death_expenses.status`, `member_payouts.status`,
`notifications.user_id` and `expense_date`.

**Caveat: these row counts are the least transferable results in this document.** Production has
been live longer and its `activity_logs` will be substantially larger. The *shape* of the finding
(90% navigation noise, monotonic growth, no pruning code anywhere) transfers; the magnitude does not.

---

## Block I — The presumed-dead tables

### Result

**Only 19 of 182 tables hold any rows. 163 are empty.** Every non-empty table is a live-product
table:

`activity_logs` 960 · `contributions` 573 · `mkoba_statement_rows` 560 · `role_permissions` 350 ·
`users` 338 · `customers` 326 · `permissions` 74 · `authored_document_templates` 15 ·
`workflow_signatures` 9 · `fines` 9 · `ai_prompts` 8 · `meeting_attendance` 7 · `group_settings` 7 ·
`sms_logs` 7 · `roles` 5 · `authored_documents` 4 · `documents` 3 · `death_expenses` 2 ·
`notifications` 1

### Verdict

**DATA-016 — CONFIRMED. None of the 64 presumed-dead tables holds a single row.** The dead-table
list is accurate, and the drop recommendation is supported by data for the first time.

**Do not act on it from this result alone.** These counts are from the local database; the same
query must be run on production before anything is dropped, and `TABLE_ROWS` is an estimate for
InnoDB. This block is data gathering, as instructed.

Also confirmed: **`mkoba_statement_rows` (560 rows) is a live, actively-used table that is absent
from `database/schema_sync.sql`** — a concrete instance of DATA-016's "≥12 drift tables" in the
opposite direction. Same for `authored_documents`, `authored_document_templates` and `sms_logs`.

---

## Block J — Roles

### Result

| role_id | role_name |
|---:|---|
| 1 | Admin |
| 2 | Chairperson |
| 3 | Secretary |
| 4 | Treasurer |
| 13 | Member |

**`roles.role_name_sw` does not exist in the live schema** — the query failed with
`Unknown column 'role_name_sw'`.

### Verdicts

**SEC-015 — CONFIRMED, and narrowed usefully.** `isAdmin()` matches `role_id ∈ {1,2,12}` plus name
strings. Roles 1 and 2 are Admin and Chairperson, matching the documented intent; Secretary (3) and
Treasurer (4) are correctly not admins. **Role 12 does not exist**, so the "legacy admin" id in the
list is dead and can be pruned — the finding flagged it as unsafe to prune without this check.
`canMarkPaid()`'s `role_id === 4` correctly resolves to Treasurer. The escalation-by-rename risk is
unchanged and real: renaming any role to "Chairman" still grants full admin.

**I18N-005 — CONFIRMED, and it is worse than reported.** The finding was that `roles.role_name_sw`
has no write path, so every role has a NULL Swahili name. In fact **the column does not exist at
all** in the live schema, while `database/schema_sync.sql` declares it — a textbook instance of
DATA-012's create-only sync failure (the column was added to the dump and never applied to the
existing table).

### ⚠️ New finding — a live fatal on a printed financial document

`role_name_sw` is not merely unread. **Three code sites read it, and they fail.** Verified by
executing the exact query shape:

```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'r1.role_name_sw' in 'field list'
```

| Site | Effect |
|---|---|
| `app/constant/accounts/print_petty_cash.php:20` | The petty-cash voucher printout **fatals**. It is in the `SELECT` list of the main query, unconditionally. |
| `app/constant/communication/message_center.php:179,194` | Guarded by `$is_sw`, so the Message Centre **fatals for Swahili users only** |

This was not found by any of the seven analysis agents, because it is invisible to static reading —
the code and the committed dump agree with each other, and only the live schema disagrees. It is
exactly the failure class `database/check_schema_drift.php` was written for and cannot catch
(DATA-011: it scans only `INSERT` column lists, so a column referenced in a `SELECT` is out of
scope).

**Not fixed in this batch** — rule 5 forbids schema changes and rule 7 forbids work outside the four
items. It needs either a migration adding the column or the three read sites changed. **Confirm on
production first**: if production has the column, only this local database is affected.

---

## Summary of severity and bucket changes

| Finding | Was | Now | Basis |
|---|---|---|---|
| **MERGED-ENGINE** (DATA-001/FIN-016/FIN-017) | S0 LIVE | **S0 LIVE — confirmed** | 72 MyISAM incl. `users` and all money tables |
| **DATA-002** | S0 LIVE | **S0 LIVE — confirmed, sized at TSh 600,000 / 12 rows** | Block F |
| **DATA-010** | S2 `likely` | **S2 `confirmed`** | `users` is MyISAM, so the FK cannot be created |
| **DATA-013** | S2 | **S2 — confirmed, `loans` is a second latin1 table** | Block A |
| **DATA-016** | S3 | **S3 — confirmed, 0 rows in all 64** | Block I |
| **FIN-007** | S3 **DISPUTED** | **S3 DEAD — resolved** | `STRICT_TRANS_TABLES` on ⇒ the insert throws |
| **FIN-009** | S1 LIVE | **S2 LATENT** | 0 AGM rows *and* 0 `initial_savings` |
| **FIN-011** | S2 `likely` | **S3 LATENT** | no `''` status rows exist |
| **DATA-003** | S1 LIVE | **S2 LATENT** | no member has both representations |
| **SEC-005** | S0 LIVE | **S0 LIVE — resized** | the chart-of-accounts half exposed empty tables |
| **SEC-013** | S3 ACTIVATED-BY | **unchanged — now certain** | `expenses` confirmed empty |
| **PERF-001** | S2 | **S2 — confirmed, 90% is `Viewed` noise** | Block G |
| **I18N-005** | S2 | **S2 → escalate: live fatal** | column absent; 3 readers, incl. a printed voucher |

Net: **five findings de-escalate** (FIN-007, FIN-009, FIN-011, DATA-003, and the accounts half of
SEC-005), **five are confirmed at their existing severity**, and **one new live defect** was found
that no static pass could have seen.

The two S0s that drive the action list — **MERGED-ENGINE and DATA-002 — both survive verification
intact.** Nothing in this pass reduces the case for the InnoDB migration or the member-deletion fix;
Block F strengthens the second considerably by putting a number on it.

---

## What still needs production

1. **`SELECT @@global.sql_mode`** — the least transferable result here, and it decides FIN-007 and
   FIN-004.
2. **Block A on production** — before the InnoDB migration is scheduled.
3. **`SHOW COLUMNS FROM roles LIKE 'role_name_sw'`** — decides whether the petty-cash printout is
   broken in production or only locally.
4. **Block F on production** — the orphan figure there is the one that matters to the group.
5. **Block I on production** — required before any table is dropped.
