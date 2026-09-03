# Financial Ledger & Reconciliation — what to know before you build it

Live on both servers. Reference: `docs/API.md` §11.

---

## Read the permission note before you build the access-control UI

This is the one thing to absorb before anything else in this module.

**On demo and production today, an ordinary Member gets a full `200` from `/ledger` and
`/mkoba-reconciliation` — not a 403.** Verified live: `hmbwana1` (a seeded member) got the whole
group's ledger and the whole group's imported M-Koba statement back, same as the Chairperson. This
is not a client bug and not something this session introduced — it's the current `role_permissions`
table, and it matches the *web* app exactly: `financial_ledger.php` and `mkoba_reconciliation.php`
both render fully for that same member with no "Access Denied," checked directly against the web
pages, not inferred from the API.

**Do not build a client-side check that hides these two screens from Member "because they shouldn't
see it."** Whether Member should hold this grant is a permission-table decision for Dutch, not a
mobile-app decision — see §11's callout box. If it changes, both endpoints will start returning
`403 forbidden` for Member with no other change on your end; build your access-control screen from
the response, not from an assumption about who "should" get in.

---

## `/ledger` is the whole group in one call — plan your pagination

Unlike every other list endpoint so far, `/ledger`'s `members` array is not filtered to "yours" for
anyone — leadership and Member alike get the same group-wide list, paginated the same way
(`page`/`per_page`, max 100). There is no per-member variant of this endpoint; a member's own
standing is still `GET /contributions/standing` (§7), which this module does not replace.

**`monthly_by_month` is an array, not a map** — zip it against `period.months` (same index, same
order) to build a grid. Don't assume 12 entries: the array length is `period.months.length`, which
changes with `start_date`/`end_date`.

A `0` in `monthly_by_month` means one of two different things depending on the group's settings —
check `totals.target` before you decide which to show:

| `totals.target` | A `0` cell means |
|---|---|
| `> 0` | that month is either not yet reached (`valid_months` hasn't gotten there) or genuinely unpaid |
| `0` | the group has no fixed monthly rate — there is no "owed" concept at all, don't render a red cell |

---

## `fund_balance` vs `approved_not_yet_paid` — two different numbers, don't add them

`fund_balance` is the same cash-basis figure the Dashboard (§4) already shows — money actually in the
account right now. `approved_not_yet_paid` is money **already authorised** (an approved expense or
payout) that hasn't left the account yet. They are reported separately on purpose: `fund_balance`
already reflects reality, and subtracting `approved_not_yet_paid` from it client-side would show a
number the group doesn't actually have less of yet. Show them as two tiles, not one combined figure.

---

## `/mkoba-reconciliation`'s `reconciled` flag is the whole point — lead with it

Don't just list the rows. `summary.reconciled` (`true`/`false`) is what the web's "Attention" vs.
"Reconciled" banner is built on, and the demo data right now is a real `false` case: 4 rows the
statement shows as paid never reached the ledger. Surface `summary.missing` prominently when
`reconciled` is `false` — that's the actionable list, not the full 22-row mirror.

`outcome` is `imported` \| `excluded` \| `missing`. `reason` explains `excluded`/`missing` rows in
Swahili on demo data (`"Muamala umerudiwa (duplicate transaction id)"`, `"Mwanachama amelipa lakini
muamala haupo kwenye taarifa (paid, not on statement)"`) — it's server-generated prose, not an enum;
show it as-is rather than trying to re-key it into your own strings.

**`receipt` can be `null`.** Excel mangled some receipt numbers into scientific notation on import
(`"3.8E+15"`) and there is nothing recoverable in that value — the server returns `null` rather than
the garbage string. Show "—" or similar, not an empty string.

---

## `/my/mkoba-reconciliation`'s override is a different permission than the group view

If you gate a "view another member's reconciliation" action in a leadership screen, check
`manage_contributions`'s `create` grant — **not** `mkoba_reconciliation`. A Treasurer who can see the
whole group statement (`mkoba_reconciliation` view) is not necessarily who this override checks;
they're two separate keys on the live permission table, mirroring `member_mkoba_reconciliation.php`'s
own `isAdmin() || canCreate('manage_contributions')` check exactly.

A non-leader who sends `member_id` anyway is not refused — the parameter is silently ignored and they
get their own record back regardless of what they asked for. Verified live: Member's
`?member_id=1` (the Chairperson) returned the caller's own empty record, not the Chairperson's. Don't
build a "not authorized" toast around this — there is nothing to catch, the response is just always
theirs.

An empty `rows: []` here is the common case for most seeded members, not an error state — build the
"no M-Koba transactions" empty view.

---

## Field types

| Field | Dart |
|---|---|
| `fund_balance`, `approved_not_yet_paid`, all `totals.*`, `members[].*` money fields, `summary.*` (both endpoints) | **`num`**, not `double` |
| `members[].mkoba_name` (`/ledger`) | `String?` |
| `members[].standing` | `String` — `ontrack` \| `ahead` \| `behind`, same three values as `cs_standing()` elsewhere |
| `rows[].receipt` (both reconciliation endpoints) | `String?` — null on a mangled statement value |
| `rows[].reason` (`/mkoba-reconciliation`) | `String?` — null on an `imported` row |
| `rows[].outcome` | `String` — `imported` \| `excluded` \| `missing` |
| `rows[].matched`, `rows[].ok` (`/my/mkoba-reconciliation`) | `bool` |

---

## Checked live, so you do not have to

- Treasurer `/ledger`: 30 members, `fund_balance` 17,287,000 — same figure as `/dashboard`'s
  contributions total, cross-checked.
- Treasurer `/mkoba-reconciliation`: 1 batch, 22 rows, `reconciled: false` (4 missing).
- Admin (no member record) `/my/mkoba-reconciliation`: 403 `no_member_record`.
- Admin overriding `?member_id=3`: 200, that member's real (empty) reconciliation.
- Member `/ledger` and `/mkoba-reconciliation`: both **200**, full group data — see the permission
  note at the top of this file.
- Member `/my/mkoba-reconciliation?member_id=1`: 200, their **own** empty record, not the
  Chairperson's — the override attempt was silently ignored.
- `end_date` before `start_date` on `/ledger`: 422 `invalid_range`.
- No token, any of the three: 401 `unauthenticated`.

No test data was created or modified for this module — all three endpoints are read-only.
