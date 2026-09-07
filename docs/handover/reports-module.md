# Reports & Statements — what to know before you build it

Live on both servers. Reference: `docs/API.md` §18.

---

## The URLs don't nest the way you'd expect — read this before you hardcode a base path

There is no `/reports/member-statement/{id}` — it's `/member-statement/{id}`, its own top-level
resource. Same for `/member-transactions/{id}`. Only `reports/vicoba` and
`reports/customer-analysis` actually live under a `reports/` prefix (they don't need an id, so the
router can express them that way; the id-bearing ones can't be nested three segments deep). If
you're scaffolding a `ReportsApi` service class in Dart, don't assume one shared base path for all
six endpoints — check `docs/API.md` §18 for the exact five distinct paths.

---

## `member-statement` and `member-transactions` have NO permission check — ownership is the only gate

Unlike almost every other endpoint in this API, these two never call a permission check at all.
Anyone logged in can hit them. What controls **whose** statement you see is a silent id override:
send no id (or someone else's id, if you're not a leader) and you get **your own** record — the
server doesn't error, doesn't warn you, just quietly substitutes your own `customer_id`. A leader
(Admin, or anyone holding `create` on `manage_contributions`) is the only role for whom `{id}` in
the URL actually does something.

**Practical implication**: don't build "insufficient permission" error handling for these two
endpoints — a wrong or missing id never produces a `403`, it produces someone's real statement
(your own). If you need to confirm you're a leader before showing an "view another member's
statement" picker in your UI, check `GET /auth/me`'s permissions for `manage_contributions.create`
— the response from these endpoints alone won't tell you whether the id you sent was honored or
silently overridden.

---

## The group-statement endpoints are visible to ordinary Members — this is not a bug to route around

`GET /group-statement/contributions` and `/transactions` are gated on `vicoba_reports`, which
(today, on both demo and production) reaches the Member role. So a member browsing the app can see
**every other member's** name, join date, contribution target/actual/variance, and behind/up-to-date
status. This is the group's own existing policy on the web (savings-group transparency is normal in
this product), not something this API introduced or something you should hide behind an extra
client-side leadership check. If product later decides Members shouldn't see this, that's a
permission-grant change on the server, not a client-side filter.

---

## `available_fund` on `/reports/vicoba` will not match `total_savings - total_expenses` on the same payload — that's correct

Do the subtraction yourself on a real response and you'll get a different number than
`summary.available_fund`. This is deliberate: `available_fund` comes from the same cash-basis
calculation the Dashboard and Financial Ledger already show (it also accounts for fines collected
and petty-cash/payout money that's gone out), while `total_savings`/`total_expenses` are this
report's own narrower figures. If you're building a screen that shows both, don't "fix" the
apparent inconsistency by recomputing one from the other — show both numbers as the API sends them.

---

## The contribution/transactions calendar grid is a map, not an array — and it can be much bigger than 12 months

`calendar.years` is keyed by year as a string (`"2026"`), each value keyed by month `"1"`–`"12"`. A
year with nothing to show simply has no key — don't assume every year from `first_year` to
`last_year` is present, and don't assume the grid tops out at the current year: a member with a
data-entry error (a contribution dated years in the future, for instance) can stretch
`last_year` a decade out. Iterate the keys that exist; don't loop `first_year..last_year` assuming
every year in between has data.

---

## `as_of=YYYY-MM` means "as of the END of that month" everywhere it appears

All four endpoints that accept it (`member-statement`, `member-transactions`, both
`group-statement` routes) treat the whole named month as elapsed — there's no partial-month
behavior, and the day-of-month is never consulted even if you tried to send one. Omit it entirely
for "as of today." A malformed value (wrong format, garbage string) silently falls back to today
rather than erroring — don't build client-side validation stricter than the server; a bad value
just means "today," not a `422`.

---

## Field types

| Field | Dart |
|---|---|
| `calendar.years` | `Map<String, Map<String, CellData>>` — string keys both levels, never a `List` |
| `member.registration_number`/`nida_number`/`residence` | `String?` — `null` when unset, never an empty string |
| `condolences.items[].amount`, all money fields | `num`, not `double` |
| `group.members[].status` | `"no_target"` / `"behind"` / `"up_to_date"` — pre-computed, don't re-derive from `variance` |
| `regions[].region` | `null` when the member's `state` field is blank — render as "Unspecified," don't treat as an error |

---

## Checked live, so you do not have to

- A Member's own statement (no `?id`) returned correctly; the same Member's attempt to view another
  member via `?id` was silently forced back to their own record (confirmed by checking the returned
  `member.id`, not just the HTTP status).
- A leader's `?id` override returned the requested member's statement, not their own.
- The group statement returned all 30 real seeded members with correct `member_count`/`behind_count`.
- A Member successfully loaded the group statement (confirming the "Members can see this" design
  decision above is real, live behavior, not theoretical).
- `reports/vicoba` and `reports/customer-analysis` returned correct aggregates against real data;
  `available_fund` confirmed to genuinely differ from the naive subtraction on the same payload.

## One data-integrity bug found and fixed while building this module

`customer_analysis.php`'s member-count queries used to filter on a legacy text field
(`user_role != 'Admin'`) instead of the real `role_id`. On live data this miscounted a genuine Admin
account (whose stale `user_role` field happened to read `'Member'`) as an ordinary member in every
stat on that page. Fixed in both the web page and this endpoint — if you compare an old screenshot
of the web report against this endpoint's numbers, a one-member difference in `total_members` is
this fix, not a discrepancy to chase.
