# Transactions — what to know before you build it

Live on both servers. Reference: `docs/API.md` §8. This file is the part that is easy to get
wrong.

---

## It is not a second contributions list

Both read the same table. They answer different questions, and they are *supposed* to disagree.

| | Question | One 100,000 payment made in January |
|---|---|---|
| `/contributions` (§7) | which months does this money **cover**? | five covered months |
| `/transactions` (§8) | when did this money **arrive**? | one January event of 100,000 |

So a member's 2026 total can legitimately differ between the two screens — money received in
2026 may cover months in 2027. **That is not a bug, and it is not something to reconcile in the
app.** If a member asks, the answer is that one document is a bill and the other is a receipt
book.

What must never differ is the grand total. See below.

---

## The three-figure total, and the bug it came from

`/my/transactions` returns:

```json
"totals": {
  "opening_brought_forward": 20000,
  "receipts_total": 420000,
  "received_total": 440000,
  "receipt_count": 9
}
```

**Do not compute the total by summing `receipts`.** You will be short by
`opening_brought_forward`.

`customers.initial_savings` — money a member carried in when they were registered — **has no
date**. It cannot sit in any month, so it appears in no receipt and in no grid cell. The web
statement shows it as a brought-forward opening line, the way a bank statement does, and the API
does the same.

> This shipped wrong. The first version omitted it and I claimed in the PR that the totals were
> guaranteed to agree. Live, demo member 30 read **420,000** here against **440,000** on
> `/contributions/standing`. It passed every local test because **every member in the dev database
> has `initial_savings` of 0** — only real data has carried-in balances.
>
> The relevance to you: if you sum `receipts` yourself, it will look correct in testing for
> exactly the same reason, and be wrong for the members who have been in the group longest.

**`received_total` equals `/contributions/standing` → `standing.total_saved` for the same
member.** Verified live on two members. If your two screens ever show different numbers, one of
them is computing instead of reading.

---

## `/transactions` is leadership-only, and says where to go instead

```json
{"status":"error","code":"forbidden",
 "message":"Group financial records are available to leadership only. Your own transactions are at /api/v1/my/transactions."}
```

This is a hard 403, not a narrowing to your own rows. Route members to `/my/transactions`; do not
call `/transactions` and handle the failure.

On `/my/transactions`, `?member_id=` is honoured **for leadership only**. For anyone else it is
**silently overwritten** with their own — asking for someone else's id returns your own record,
with **no error**. Verified live: `hmbwana1` asking for `member_id=3` got member 30 back. Do not
send it from a member screen and assume it worked.

---

## The M-Koba block

`/transactions` carries what `/contributions` does not — this is the main reason the endpoint
exists:

```json
"mkoba": {
  "sno": "12", "trans_id": "DBS2N6S4DVM", "member_id_str": "0783459353",
  "source": "Hawa Mtui", "destination": "UKUU Msakuzi", "trans_type": "Deposit"
}
```

Every field is `String?` and is **`null`, not `""`**, when the row was recorded in Vikundi rather
than imported from M-Koba. Render null as absent — an empty string draws a blank cell that looks
like missing data rather than inapplicable data.

**On demo every one of these is `null`**, because demo has no imported M-Koba history. You cannot
build or verify this part of the screen against demo data. Ask before pointing anything at
production.

`account` filter values: `M-Koba`, `Bank`, `Cash`, `Mobile Money`. Anything else is 422. On demo
`account` is `null` on every row, so both filters return 0 — that is the data, not a fault.

---

## What `/my/transactions` deliberately leaves out

Only money that **counts**: approved or confirmed, and of a savings type.

- A contribution submitted this morning is **not here**. It is on `/contributions` with
  `status: "pending"`.
- **Fines are not here.** They are transactions but not savings, and they have their own module
  (§9). Never add them into this total.

If a member says "I paid yesterday and it's missing", the answer is that it is awaiting approval,
and `/contributions` is the screen that shows it.

---

## Field types

| Field | Dart |
|---|---|
| `amount`, all money | **`num`**, not `double` — see README rule 1 |
| `mkoba.*` | `String?` |
| `receipts[].receipt_number`, `description`, `account`, `mkoba_trans_id` | `String?` |
| `months[].status` | `String` — `received` \| `none` \| `before_join` \| `future` |
| `totals.*` | `num` |

`months` already has the padding cells dropped; render it as given.

---

## Checked live, so you do not have to

- Treasurer `/transactions`: 333 rows, TZS 18,461,000.
- Member `/transactions`: 403 with the message above.
- `account=Crypto` → 422 `invalid_account`; `date_from=last-tuesday` → 422 `invalid_date`.
- Admin (no member record) `/my/transactions` → **422 `member_required`**, telling you to pass
  `?member_id=`.
- Member asking `?member_id=3` → got their own record, silently.
