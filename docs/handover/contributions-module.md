# Module 4 — Contributions

**Status: live on demo and production.** Deployed 2026-08-26. Full reference in
`docs/API.md` §7. This is the part you need before writing the screens.

Nothing from Modules 1–3 changes. Same envelope, same tokens, same error shape.

---

## The three screens this gives you

| Screen | Endpoint | Who |
|---|---|---|
| **My Contributions** | `GET /contributions/standing` | Every member |
| **Contributions ledger** | `GET /contributions` | Everyone — scope differs |
| **Collection summary** | `GET /contributions/summary` | Leadership only |

Plus record + the approval workflow.

---

## 0. A note if you tested against the web app

On 2026-08-26, seven **web** endpoints were found serving the whole group's savings to any
signed-in member — they gated on `manage_contributions.view`, which is the grant a Member
legitimately holds so they can open their own contributions. An ordinary member could read
all 333 group transactions, another member's contribution, and the chairperson's full
statement.

**The mobile API was never affected** — it has always used the correct test, and nothing in
this document changed because of that fix. But if you were checking behaviour against the
web to work out what a Member should see, the web was the wrong reference until that deploy.
Both now enforce the identical rule, described next.

---

## 1. The scoping rule — read this before anything else

The members roster is shared. **Savings are not.** One rule decides the whole module:

```
leader = is_admin  OR  permissions['manage_contributions'].edit == true
```

Note it is **`edit`, not `view`.** A Member holds `view` on `manage_contributions` —
that is what lets them open the screen at all — so testing `view` would show them the
entire group's savings.

A non-leader is **pinned server-side to their own member record.** Passing
`?member_id=` as a Member does nothing; the value is overwritten, not rejected. Every
list response tells you what actually happened:

```json
"scope": { "is_leader": false, "member_id": 30, "own_member_id": 30 }
```

**Render from `scope`, never from what you asked for.** Use `scope.is_leader` to pick
between "the group ledger" and "my contributions" — do not re-derive it from the
permission map, and do not key it on `role`.

```dart
final isLeader = data['scope']['is_leader'] as bool;
final memberId = data['scope']['member_id'] as int?;      // null = whole group
final ownId    = data['scope']['own_member_id'] as int?;  // null = no member record
```

Both are **`int?`**. `member_id` is null when a leader is viewing everyone;
`own_member_id` is null for the system Admin, same as `member_id` from `/auth/me`.

### The Admin has no savings

An account with no member record (the system Admin) gets:

- `GET /contributions` → **`403 no_member_record`** if it is somehow not a leader
- `GET /contributions/standing` → **`422 member_required`**

Neither is a retry. It means "this account has no savings of its own." Show the group
view, and hide My Contributions when `me.member_id == null` — which the `/auth/me`
handover already told you to do.

---

## 2. `has_target` — the switch the member screen hangs on

The group may not have set a monthly contribution amount. Vikundi supports
save-what-you-can, and roughly half the real groups run that way.

```json
"group": { "currency": "TZS", "monthly_contribution": 10000, "has_target": true }
```

**When `has_target` is `false`:**

- `expected` is `0`
- `surplus_deficit` equals what they saved
- `status` is always `"ontrack"` — never `ahead`, never `behind`
- `arrears.behind` is always `false`
- every entry in `months` has `status: "no_target"`
- `summary.members.collection_rate` is **`null`**

**Do not draw a progress bar, a percentage, or an arrears warning in that state.**
Getting it wrong tells a member in perfectly good standing that they owe money.

```dart
if (!group.hasTarget) {
  // "You have saved TZS 440,000" — a figure, not a score.
} else {
  // progress = totalSaved / expected, arrears card, the works
}
```

And guard `collection_rate`: it is `null`, not `0`, when there is no target. `?? 0`
would render "0% collected" for a group that is doing fine.

---

## 3. The approval workflow

```
pending ──review──▶ reviewed ──approve──▶ approved
   │                    │
   └─────── cancel ─────┘        approved is final
```

**Every new contribution is `pending`. You cannot post a status** — the field is
ignored entirely. Only `approved` money counts toward savings.

### Bind your buttons to `actions`, not to permissions

Every row carries what **this caller** may do to **this row**:

```json
"actions": { "review": true, "approve": false, "cancel": true }
```

That already combines the permission check and the status check. Re-deriving it
client-side is how you end up offering a button the server refuses — the same class of
bug as the `/auth/me` permissions issue.

```dart
if (c.actions.review)  ReviewButton(),
if (c.actions.approve) ApproveButton(),
if (c.actions.cancel)  CancelButton(),
```

### Calling them

`POST /contributions/{id}/review` · `/approve` · `/cancel` — **no body**. All return
the updated row.

A wrong-order call returns **409** with a message naming the current status:

```json
{ "status": "error", "code": "invalid_status_transition",
  "message": "A contribution that is pending cannot be approved. Expected: reviewed." }
```

That message is written to be shown to the user as-is.

### `sig_warning` is not an error

`/approve` may return, alongside a **200**:

```json
"sig_warning": "No e-signature on file — the approval was recorded without a signature image."
```

**The approval succeeded.** Show it as a note ("approved — no signature on file"), not
a failure. Do not roll back or retry.

### There is no DELETE

A contribution that existed and was withdrawn stays in the audit trail. `cancel` is the
exit, and only from `pending` or `reviewed` — approved money is already in every
member's statement.

---

## 4. Recording a contribution

`POST /contributions`, **JSON or multipart** — multipart only when attaching evidence.

```json
{ "amount": 12500, "type": "monthly", "date": "2026-08-26", "description": "August savings" }
```

Returns **201** with the created row.

**Any signed-in member may file their own.** That is the normal case in a savings
group — you do not need `create` to show the button to a Member. `create` is what
allows filing against *someone else*, so only send `member_id` when
`permissions['manage_contributions'].create` is true. Sending it otherwise is harmless;
it is simply ignored.

| Field | Notes |
|---|---|
| `amount` | required, > 0 |
| `type` | `entrance` · `monthly` · `agm` · `fine` · `other` (default `monthly`) |
| `date` | `YYYY-MM-DD`, defaults to today, **cannot be in the future** |
| `account` | `M-Koba` · `Bank` · `Cash` · `Mobile Money` |
| `receipt_number` | max 100 chars |
| `evidence` | multipart only — JPG, PNG, GIF, WEBP, PDF, max 5 MB |

Every refusal is **422** with its own code — `invalid_amount`, `invalid_type`,
`invalid_date`, `invalid_account`, `invalid_upload`, `member_not_found` — so you can map
each one to the right form field rather than showing a generic error.

**The future-date rule will bite you.** A phone with a wrong clock, or a timezone slip
when you format the date, produces `invalid_date`. Send the date as the user's local
calendar date, not a UTC-converted timestamp.

---

## 5. Two fields you must not compute yourself

### `counts_toward_savings`

Do **not** sum `amount` to get a member's savings. `fine` and `agm` rows are excluded,
and so is anything not approved. Your total would disagree with their statement, and the
first thing anyone does with two figures is check they match.

Use `standing.total_saved` for the number, and `counts_toward_savings` to grey out rows
that do not contribute.

### `is_opening`

Money carried in from M-Koba — an opening balance, not a fresh payment. Worth a
different label ("carried forward") so a member does not read it as this month's saving.

### `total_saved` and `year_summary.total.paid` will differ — correctly

Live: `total_saved` 440,000, `year_summary.total.paid` 420,000. The 20,000 gap is the
**entrance fee**: real savings, so it counts in `total_saved`, but not a monthly payment,
so the month calendar never allocates it. `entrance` is returned separately for exactly
this reason.

If both appear on one screen, label them — "Total saved" vs "Allocated to months" — or
show only `total_saved`. Two unexplained totals on a savings screen reads as a bug.

### `totals` on the list is the filter, not the page

```json
"totals": { "filtered_amount": 420000.0, "filtered_count": 9 }
```

Use it for "TZS 420,000 across 9 records" without paging the whole set.

---

## 6. Types on the wire

### The one that will crash you

Every money value is a JSON **number**, but not reliably a Dart `double`. The server
casts to float; `json_encode` then emits `10000` for a whole amount and `10000.5` for a
fractional one. `jsonDecode` turns the first into an **`int`**:

```dart
final amount = json['amount'] as double;            // ✗ throws on almost every real row
final amount = (json['amount'] as num).toDouble();  // ✓
```

Live proof: `"monthly_contribution": 10000`, `"total_saved": 440000` — no decimal point.
Use `num` for every money field, including inside `standing`, `arrears`, `totals`,
`entrance` and `year_summary`. This applies to Modules 1–3 too.

| Field | Dart |
|---|---|
| `amount`, all money | `(x as num).toDouble()` — **never** `as double` |
| `scope.member_id`, `scope.own_member_id` | **`int?`** |
| `evidence_url` | `String?` |
| `collection_rate` | **`double?`** via `num?` — null when no target |
| `arrears.oldest_month` | `String?` — `"2026-03"` |
| `trail.*.at` | `String?` — ISO 8601 |
| all timestamps | `String?` |

`evidence_url` is **relative to the site root, not the API base**:

```dart
final url = 'https://$host/${c.evidenceUrl}';   // NOT $baseUrl
```

---

## 7. The approval trail

`GET /contributions/{id}` returns a `trail` for a stepper:

```json
"trail": {
  "created":  { "by": "Hamisi Mbwana", "role": "",          "at": "...", "signed": false, "completed": true },
  "reviewed": { "by": "Hawa Mtui",     "role": "Treasurer", "at": "...", "signed": false, "completed": true },
  "approved": { "by": "",              "role": "",          "at": null,  "signed": false, "completed": false }
}
```

`completed` drives the stepper. `signed` says whether an e-signature image was on file —
the image itself is never returned, deliberately.

Note `created.role` is often `""`. Do not render an empty chip for it.

> Those names were wrong until this deploy. `workflowActorSnapshot()` was reading a
> global that `config.php` also sets for the database connection, so every signature
> written outside the web session recorded the **database user** as the approver. If you
> saw `"vikundi"` in a trail while testing earlier, that was the bug, and it is fixed.

---

## 8. A 404 that is not a 404

Requesting a contribution belonging to another member returns **404, not 403** — ids are
sequential and a 403 would confirm the row exists. Treat `404` on
`/contributions/{id}` as "not yours or not there", and do not special-case it.

---

## 9. One inconsistency you must NOT paper over

`summary.members.behind` and `standing.status` are anchored differently on the server:
the group figure counts from a member's **first contribution**, the member figure from
their **join date**. For members imported from M-Koba the two can reach opposite
conclusions about the same person — observed on real data: expected 400,000 and
"behind" by one, 150,000 and "ahead" by the other.

**This is a known server-side issue and it shows on the web today too.** It is being
handled separately because changing an anchor moves every savings figure on every
report, and the treasurer has to confirm which anchor the group actually means.

If a leader asks why the summary counts someone as behind while their own screen says
ahead — that is why. **Do not "fix" it client-side by computing your own figure.** That
would make the phone agree with itself while disagreeing with the printed statement,
which is worse, because it looks correct.

---

## Verified live before this was written

On the demo site, as Admin, Chairperson, Treasurer and Member:

- Member sees 9 rows / TZS 420,000; leaders see 332 / TZS 18,460,000
- `?member_id=8` and `?member_id=1` as a Member both returned only their own rows
- A Member posting `member_id: 8, status: "approved"` was recorded as **their own
  member_id, status pending**
- `pending`→approve → 409; Member review/approve → 403; review → 200; double review →
  409; cancel → 200; double cancel → 409
- Admin on `/standing` → 422 `member_required`; Member on `/summary` → 403
- Evidence: genuine PNG accepted; a PHP file renamed `.png` refused on **content**

---

## Test accounts (demo, password `Demo@2026`)

| Role | Username | member_id | What to check |
|---|---|---|---|
| Admin | `admin` | `null` | 422 on standing; ledger works; hide My Contributions |
| Chairperson | `rmollel` | 1 | Full ledger + workflow buttons |
| Treasurer | `hmtui` | 3 | Same; the review/approve path |
| Member | `hmbwana1` | 30 | 9 rows only, no workflow buttons, 403 on summary |

**Test as `hmbwana1` before you ship anything.** Member is the only role where fields and
rows are *removed* rather than added, so it is the only one that catches scoping bugs.

There is one **cancelled** contribution (id 333) on demo from this verification. It is
excluded from every money figure by design — useful for checking your cancelled-row
rendering.
