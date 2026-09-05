# Payouts — what to know before you build it

Live on both servers. Reference: `docs/API.md` §14.

---

## Treasurer cannot use this screen — and that's correct, not a bug

Every other financial module built this week (Contributions, Fines, Condolences, Expenses, Petty
Cash, Budgets) grants the Treasurer full leadership rights. **This one doesn't.**
`member_payouts` is Admin/Chairperson/Secretary only — verified live, the Treasurer gets a plain
`403` on both `GET` and `POST /payouts`. This mirrors the web's own `record_payout.php`
(`$viongozi_roles`) exactly; it isn't a gap to "fix" by adding Treasurer to match the other modules.
If your app has a shared "leadership" role check gating a bottom-nav tab or a menu section, **don't
route Payouts through it** — check `member_payouts` specifically, not a generic
`is_leadership`/`is_admin` flag the way you might for Budgets or Expenses.

---

## There is no workflow, at all

Every other module in this API has some version of pending → reviewed → approved. Payouts has
none of that: a record is `'paid'` the instant it's created, full stop. Don't build a status
progress indicator, a review button, or an approve button for this screen — there's nothing to
show. The `status` field is present in the response (always `"paid"` in practice) purely for shape
consistency with every other module's row.

There's also no fund-balance check — recording a payout larger than the group's available balance
is not refused server-side, because the web has never refused it either. If that matters to the
group, it's a policy conversation for Dutch, not something to guard against client-side as if the
server already does.

---

## `GET /payouts` is new — it didn't exist on the web as its own screen

The web page (`record_payout.php`) shows its own fixed "10 most recent" table inline, on the same
page as the create form, with no pagination. The API splits this into a real paginated list. If
you're building a "Payout History" screen, this is what feeds it — you don't need to reconstruct
the recent-10 behavior specifically; just page normally.

---

## The member existence check happens before anything is written

Posting an unknown `member_id` gets a clean `404 member_not_found` — the row is never inserted.
There's no need for a client-side "does this member exist" pre-check before submitting; the create
call is safe to fire and handle the 404 if it comes back.

---

## Field types

| Field | Dart |
|---|---|
| `amount`, `totals.filtered_amount` | **`num`**, not `double` |
| `description` | `String?` — `null` when blank, never an empty string |
| `status` | `String` — always `"paid"` in current practice |
| `member` | `{"id": int, "name": String}` — never a bare `member_id` |

---

## Checked live, so you do not have to

- Secretary: `GET` and `POST /payouts` both succeed — a real member, an amount with thousands
  separators (`"5,000"` → `5000`).
- Treasurer: `403` on `GET /payouts` — confirms the deliberately narrower role set, not an
  oversight.
- Member: `403`. No token: `401`.
- An unknown `member_id`: `404 member_not_found`, confirmed nothing was written.

One labelled test record exists on demo — payout id 1 ("API smoke test — Module 11 verification,
safe to delete"). Harmless; there is no delete/reversal path for a payout on the web either, so it
stays.
