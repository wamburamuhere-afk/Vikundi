# Fines — what to know before you build it

Live on both servers. Reference: `docs/API.md` §9.

---

## Fines are deliberately visible to everyone

This is the one thing that will look wrong if you assume the contributions rules carry over.
**Any member can see every fine in the group**, through `?view=all` on `/my/fines`.

That is not an oversight and it is not a leak I missed. The web page `my_fines.php` has the same
toggle, the group asked for it, and it is the same disclosure the Group Financial Ledger already
makes — which shows any member every other member's contributions and shortfall. Fines are an
accountability mechanism in a VICOBA; the group decided they are public within the group.

**So build the toggle.** Do not hide it, do not gate it on role, and do not "improve" on the API
by showing members only their own. The data is one browser tab away in the web app; a stricter
app protects nobody and just fails to show what the group agreed to show.

What *is* closed is writing. Nobody but leadership records, edits, pays or waives.

---

## Only `pending` is money owed

`status` is `pending` | `paid` | `waived`.

**Never sum every row to get "what I owe".** You would be adding fines that were already paid and
fines the group forgave — telling a member they owe money they settled months ago.

Each row carries `is_outstanding` (true only for `pending`), and each list carries:

```json
"totals": {"outstanding": 65000, "paid": 60000, "waived": 2000, "count": 9, "fined_members": 8}
```

`totals.outstanding` is the figure to put on the screen. It covers the **whole filtered set**,
not the page, so it does not change as the user scrolls.

`fined_members` (how many different people are fined) is `null` in the `mine` view — it only
means something for the group.

---

## Render buttons from `actions`, never from the role

```json
"actions": {"edit": true, "pay": false, "waive": true}
```

`pay` is false on an already-paid fine and `waive` is false on an already-waived one, because
repeating a transition is refused — **409** `already_paid` / `already_waived`, not a silent
success. That is deliberate: a second audit entry would record the treasurer doing something they
did not do. Honour `actions` and you will never hit the 409.

For a member every action is `false`. Verified live.

---

## The two list endpoints are not interchangeable

| | Who | Default |
|---|---|---|
| `GET /fines` | **Leadership only — 403 for a member** | the whole group |
| `GET /my/fines` | anyone signed in | **own fines** |
| `GET /my/fines?view=all` | anyone signed in | the whole group, paginated |

`/my/fines` takes the member **from the token — there is no `member_id` parameter at all**, so
there is nothing to tamper with and nothing to pass.

`view` is echoed back in the response so you can drive the toggle's state from the server's
answer rather than your own local flag.

**Own fines are the default.** Anything other than an explicit `view=all` scopes to the member.
The screen is called "My Fines"; opening it on other people's debts would be a surprise about
other people's money.

In the group view, `is_self` marks the reader's own rows. The web page highlights them in red —
do something equivalent.

---

## An account with no member record

The Admin has none. Note that the two modules answer differently, and both are correct:

| | Admin gets |
|---|---|
| `/my/fines` (`view=mine`) | **403 `no_member_record`** — and is told to use `?view=all` |
| `/my/transactions` | **422 `member_required`** — and is told to pass `?member_id=` |

Different because the situations differ: there is no parameter that makes `view=mine` work for an
account with no member record, whereas a statement can be read for anyone once you name them.
Both verified live. Handle them separately rather than looking for one shared code.

---

## Recording a fine

`POST /fines`, `create` on `manage_fines`.

| Field | |
|---|---|
| `member_id` | required — 404 `member_not_found` |
| `amount` | required, > 0. **`"1,500"` is accepted** and stores 1500 |
| `reason` | **required** — 422 `reason_required` |
| `status` | optional, `pending` (default) or `paid` **only** |
| `meeting_id` | optional — 404 `meeting_not_found` |

Two things worth surfacing in the UI:

**The reason is mandatory on the server.** A fine with no reason is a figure nobody can defend
when the member asks why. Make the field required in the form rather than letting the user submit
and bounce.

**A fine cannot be created already waived** — forgiving something never owed is not a state the
group has a word for. Offer only Pending and Paid at creation; waiving is a later act.

On `PUT /fines/{id}`, an unrecognised `status` is **422, never silently coerced**. Send only the
fields that changed; sending none is 422 `no_fields`.

---

## Field types

| Field | Dart |
|---|---|
| `amount`, all `totals.*` | **`num`**, not `double` |
| `reason`, `meeting_title` | `String?` |
| `meeting_id`, `totals.fined_members`, `scope.own_member_id` | `int?` |
| `is_outstanding`, `is_self`, `actions.*` | `bool` |
| `created_at`, `updated_at` | `String?` — ISO 8601 with offset |

---

## Checked live, so you do not have to

- Treasurer `/fines`: 8 rows, outstanding 65,000 / paid 60,000. Member: **403**.
- Member `/my/fines?view=all`: 8 rows across 8 members, every action `false`.
- Member recording a fine → 403. No reason → 422. Zero amount → 422. `status: "waived"` at
  creation → 422. Unknown member → 404. Unknown fine → 404. Member waiving → 403.
- Paying an already-paid fine → **409 `already_paid`**.
- Waive → pay round trip, ending in the original state.
- `"1,500"` stored as 1500; empty `PUT` → 422; `status: "disputed"` → 422.

**One test row exists on demo:** fine #9, TZS 2,000 against Hamisi Mbwana, reason
"API smoke test — edited", left **waived** so it is not money anyone owes. Ignore it, or ask for
it to be deleted from the web Fines page — there is no delete endpoint.
