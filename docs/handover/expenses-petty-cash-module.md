# Expenses & Petty Cash — what to know before you build it

Live on both servers. Reference: `docs/API.md` §12.

---

## Read the permission notes before you build any access-control UI

**`GET /expenses` and `GET /petty-cash` return `200` with the whole group's list for an ordinary
Member today** — verified live. For Expenses this mirrors the web exactly (`api/get_general_expenses.php`
already does this and has been through a security audit under that behaviour). For Petty Cash it's a
brand-new permission key, deliberately mirrored from Expenses' grants for consistency. **Don't build a
client-side check that hides these two screens from Member "because they shouldn't see it"** — same
guidance as the Financial Ledger handover: it's a permission-table decision, not a mobile-app one.

**`mark-paid` is not gated the way everything else in this module is.** Every other action checks a
`role_permissions` grant (`view`/`create`/`edit`/`review`/`approve`). Mark-paid checks a role
directly — Treasurer or a full admin — via `canMarkPaid()`. **A Secretary or Chairperson who can
review and approve everything else in this module will still get a `403` on mark-paid**, naming the
Treasurer specifically:

```json
{"status":"error","code":"forbidden","message":"Only the Treasurer or an administrator can mark an expense as paid."}
```

Drive your "Mark Paid" button from `actions.mark_paid` on the row, not from whether the caller can
review/approve — those are genuinely different permissions here.

---

## The workflow has FOUR stages, not three

`pending → reviewed → approved → paid`. Contributions and Condolences stop at `approved`; this module
keeps going, because "approved" only *authorises* a spend — the group's actual balance
(`fund_balance` in the Ledger endpoint, §11; the dashboard's balance, §4) only drops once something
is marked **paid**. Build a 4-segment progress indicator, not 3.

`trail.paid` is shaped like the other three stages (`by`, `role`, `at`, `signed`, `completed`) but
**`signed` is always `false`** — the web's mark-paid action has never captured an e-signature, on
either expenses or petty cash. Don't show a "no signature" warning here the way you might for a
missing review/approve signature; it's expected, not a data gap.

---

## Expenses and Petty Cash are NOT the same rules wearing two names

They look alike (both 4-stage, both have a report-quality list, both have mark-paid) but three real
differences are preserved from the web, not smoothed over:

| | Expenses | Petty Cash |
|---|---|---|
| Edit allowed while | `pending` or `reviewed` | `pending` **only** |
| Approve checks the group's fund balance | Yes — can return `409 insufficient_funds` | **No** — the web's own approve action never has |
| Permission key | `expenses` (pre-existing) | `petty_cash` (new — see below) |

Don't reuse one "can this be edited" helper across both screens — check the actual `actions.edit`
flag the API sends, which already reflects the right rule for each.

---

## A voucher's `approved_at` comes from a differently-named column

`petty_cash_vouchers` calls the column `approval_date`, not `approved_at` like `general_expenses`
does. The API exposes it as `approved_at` on both, so you can share one row-rendering widget between
the two screens without a special case — the server already did the translation.

---

## The petty-cash permission key is new — expect Member to see it too

`petty_cash` did not exist in the system's permission catalog before this module. It was registered
mirroring `expenses`' own grants (leadership full rights, Member view-only), because the web's own
gating for petty cash was inconsistent across files — and one of those files,
`actions/fetch_petty_cash.php` (the list's own data source), had **no permission check at all**
before this — any authenticated Member could pull the whole voucher list. That hole is closed now,
but the *result* for Member is the same as Expenses: `view` is a real, intentional grant, not a leak.

---

## Field types

| Field | Dart |
|---|---|
| `amount`, all `totals.*` (both modules), `pct_general`/`pct_death` (report) | **`num`**, not `double` |
| `expenses[].member` | `{"id": int, "name": String}?` — `null` for a whole-organization expense, never a bare `member_id` |
| `status` (both modules) | `String` — `pending` \| `reviewed` \| `approved` \| `paid` (plus `rejected`, which exists on the column but no code path writes) |
| `trail.paid.signed` | always `false` |
| `vouchers[].category`, `vouchers[].description` | `String?` |
| `actions.edit`, `.review`, `.approve`, `.mark_paid` (both modules) | `bool` |

---

## Checked live, so you do not have to

- Treasurer: full lifecycle both modules — create → review → approve → `PUT` blocked
  (`409 not_editable`) → mark-paid → re-mark-paid blocked (`409 already_paid`). Trail carries all
  four stages correctly attributed.
- `/reports/expense-report` picked up a newly-paid expense in both `items` and `totals` immediately.
- Member: `200` on both `GET /expenses` and `GET /petty-cash` (the mirrored grant, not a leak), `403`
  on every create/edit, `403` naming the Treasurer specifically on `mark-paid`.
- No token: `401 unauthenticated` throughout.

Two labelled test records exist on demo — expense id 9 ("API smoke test — Module 9 verification, safe
to delete") and petty-cash voucher id 6 ("Module 9 verification voucher — safe to delete"), both left
`paid` (there is no reject/undo path for either, same as every other workflow module) and harmless.
