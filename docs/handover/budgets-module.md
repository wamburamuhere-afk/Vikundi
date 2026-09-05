# Budgets — what to know before you build it

Live on both servers. Reference: `docs/API.md` §13.

---

## Leadership only — no Member grant at all, unlike every other module

This is the one module in the whole API where Member holds **nothing**, not even `view`.
`GET /budgets` and everything else return `200` only for Admin/Chairperson/Secretary/Treasurer;
Member gets a plain `403`. Don't reuse the "Member might have view" caution from the Financial
Ledger or Expenses handovers here — it doesn't apply. There is no `/my/budgets` and never will be
unless the group asks for one.

---

## The workflow is THREE stages, not four — and there's a fourth path

`pending → reviewed → approved`. Unlike Expenses/Petty Cash, there's no `paid` step (a budget is a
plan, not a disbursement) and no fund-balance check on approve. There's also a side path:
`pending` or `reviewed` → `rejected`, via its own endpoint (`POST /budgets/{id}/reject`), gated on
review **or** approve rights (whoever can move it forward can also stop it).

Build a 3-segment progress indicator, and a separate "Rejected" terminal state that isn't part of
that progression — don't try to fit `rejected` into the same bar as pending/reviewed/approved.

**A rejected budget's trail has nothing extra to show.** The API's `trail` object only ever has
`created`/`reviewed`/`approved` keys — there's no `rejected` entry, because the underlying table
has never recorded who rejected a budget or when. If `status` is `rejected`, just show that as the
badge; don't expect a fourth trail stage to fill in.

---

## `PUT /budgets/{id}` behaves differently for Admin than for everyone else

Every other edit-block in this API is a flat rule. This one isn't:

| Caller | Can edit an `approved` budget? |
|---|---|
| Admin (or Chairperson — `is_admin` in `/auth/me`) | **Yes** |
| Secretary, Treasurer | **No** — `409 not_editable` |

Drive this from `actions.edit` on the row, which already reflects the right answer for whoever is
asking — don't hardcode "approved is never editable" anywhere in the app.

---

## Line items replace wholesale on every edit

Sending `items` in a `PUT` **replaces the entire set** — there's no per-item update, no "add one
line" endpoint. If your edit screen only changed the budget name, don't send `items` at all
(omit the key entirely) or you'll silently wipe and rebuild every line with whatever partial state
your form happened to hold. Always resubmit the *complete* item list you want the budget to end up
with.

A blank-description line is silently dropped, same as the web — if your form has an empty "add
another line" row at the bottom, you don't need to filter it out before sending.

---

## Don't build a "variance vs. actual spending" chart

`allocated_amount`, `actual_amount`, `variance`, `variance_percentage` are all real fields on the
row, but `actual_amount` is always `0` and `variance`/`variance_percentage` always equal
`allocated_amount`/`100` on every budget that has ever existed on this system — there is no live
code path anywhere, web or mobile, that computes a budget's real spend against its plan. The web's
own attempt at this joins tables (`expenses`, `accounts`, `expense_categories`) that are dead BMS
scaffolding with zero real rows. If a "budget vs. actual" screen is wanted, that's a new feature to
scope with Dutch — not something to build from these fields as they stand today.

`category_id`/`category_name` aren't in the API response at all, for the same reason — every budget
has it hardcoded `null`.

---

## Field types

| Field | Dart |
|---|---|
| `allocated_amount`, `actual_amount`, `variance`, `variance_percentage`, `totals.filtered_allocated`, `items[].qty`/`price_per_item`/`total_amount` | **`num`**, not `double` |
| `notes` | `String?` |
| `status` | `String` — `pending` \| `reviewed` \| `approved` \| `rejected` (`draft` exists on the column, no code path writes it) |
| `items[].units` | `String?` |
| `actions.edit`, `.delete`, `.review`, `.approve`, `.reject` | `bool` — no `mark_paid` key at all in this module |

---

## Checked live, so you do not have to

- Treasurer: full lifecycle — create with line items (blank-description line dropped) → review →
  approve → `PUT` blocked with `409 not_editable`.
- Separately: Admin editing an **approved** budget — succeeds, confirming the `canEditDocument()`
  exemption.
- A second budget: created with zero items → rejected → a review attempt on it refused with
  `409 invalid_status_transition`.
- Member: `403` on the list. No token: `401`.
- The two web-side security fixes this module shipped with — `budget.php`'s unauthenticated AJAX
  branch, and `update_budget_status.php`'s workflow-bypass — both confirmed closed against the live
  server, not just in code review.

One labelled test record exists on demo — budget id 1 ("API smoke test — Module 10 verification,
safe to delete"), left `approved` (there is no un-approve path). Harmless.
