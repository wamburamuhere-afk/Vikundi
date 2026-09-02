# Condolences — what to know before you build it

Live on both servers. Reference: `docs/API.md` §10.

---

## It is leadership only — not like Fines

If you built Fines first, don't carry its rule over. Fines gave every member a `?view=all`
toggle onto the group because a web screen already did that and the group asked for it.
**Condolences has no such screen.** No page here ever showed a member their own cases the way
`manage_contributions.php` or `my_fines.php` do, so there is nothing to scope — `GET /condolences`
is a hard **403** for anyone but leadership, and it says where to go instead:

```json
{"status":"error","code":"forbidden",
 "message":"You do not have permission to view the group's condolence records. Your own condolence records are at /api/v1/my/condolences."}
```

**`GET /my/condolences` is new — build the empty state.** The Member role has always held
`death_expenses.view`, but no web screen ever used it, so this is that grant's first real
consumer. On demo it currently returns an empty list for the seeded member — that is correct, not
a bug, and your screen needs a proper "no cases yet" state rather than assuming there will always
be data to show.

`GET /condolences/{id}` re-checks ownership on the row that was actually loaded — a member asking
for someone else's case id gets **404**, not 403, so guessing ids cannot map who in the group has
lost a family member.

---

## The workflow needs review before approve

Same shape as Contributions: `pending` → `reviewed` → `approved`. There is no way to jump straight
to approved — attempting it is **409**:

```json
{"status":"error","code":"invalid_status_transition",
 "message":"A condolence record that is pending cannot be approved. Expected: reviewed."}
```

Drive your buttons from `actions` on every row (`{"review": bool, "approve": bool}`), and you will
never hit this.

---

## Approving can fail on money, not just permission

`POST /condolences/{id}/approve` checks the group's **real, computed fund balance** before it
allows the case through — a condolence payout is money *leaving* the group, which contributions
never has to worry about:

```json
{"status":"error","code":"insufficient_funds",
 "message":"The group fund balance (TZS 2,302,878.00) is not enough to approve this case (TZS 999,999,999.00)."}
```

**Show this as its own error state, not a generic failure.** A treasurer needs to know the group
cannot afford the case yet, not that "something went wrong." There is no client-side way to
predict this in advance — the fund balance is not exposed on this endpoint, and computing it
client-side would drift the moment it did. Attempt the approval and handle `insufficient_funds`
when it comes back.

---

## Approving a case can also change a member's record — silently, by design

This is the one thing in this module that is not "just" a status change, and it is not optional or
skippable from the client.

`deceased_id` (set at creation, from the four fields under `deceased`) decides what happens to the
**customers table** the moment a case is approved:

| `deceased.id` | On approval |
|---|---|
| `"member"` (or `deceased.type: "mwanachama"`) | **the member's own account is marked deceased and dormant** — they can no longer sign in normally, and will appear in the dormant list |
| `"spouse"` / `"father"` / `"mother"` | that one family field is cleared from the member's profile |
| `"child_N"` | that child is flagged deceased (not removed) in the member's dependants |
| anything else | no side effect at all — a dependant outside the tracked family fields |

**Warn the leader before they tap Approve on a `member`-type case.** Approving it is the action
that dormants the member's own account — there is no "are you sure" built into the API, because
the web doesn't have one either, and adding a stricter confirmation on mobile than the web has
would just be inconsistent, not safer. But the app should still make this consequence visible in
the confirmation dialog, because it is genuinely irreversible through this API — there is no
"un-approve."

Verified live: approving a case whose `deceased.id` matched none of the four shapes left the
named member's account completely untouched (`status: "active"`, still able to sign in).

---

## Recording a case: what's accepted, what's not

`POST /condolences` — leadership only (`create`). `member_id`, `deceased_name` and `amount` are
required; everything else is optional.

**No attachments.** The web files a death certificate into the shared document library; this
endpoint does not touch that subsystem. If a certificate needs attaching, that still has to happen
from the web for now — don't build an upload field expecting it to work.

Thousands separators are accepted in `amount` — `"1,500"` stores as `1500`.

---

## The report has its own, separate permission

`GET /reports/death-analysis` is gated on **`vicoba_reports`**, not `death_expenses`. A leader who
can manage condolences is not automatically able to see this report, and vice versa — check
`vicoba_reports` specifically if you gate a menu entry for it client-side.

> **On demo, `vicoba_reports.view` is granted to Member.** Verified live against both the API and
> the web report page — this is the group's current permission table, not something the mobile
> build changed or should work around. If a member opening this report on their phone looks wrong
> to you, that's a conversation about the permission grant, not a client-side fix.

The report shows, per member who has received *paid* assistance, their lifetime contributions
against what the group has paid them (`benefit_paid`), and `variance` = contributed − paid.
Positive variance means the member has put in more than they've received; negative means the
opposite. `member_status` is `deceased` | `active` | `dormant` — deceased always wins, even if the
raw status column still says something else.

---

## Field types

| Field | Dart |
|---|---|
| `amount`, all `totals.*`, `summary.*`, `recipients[].*` money fields | **`num`**, not `double` |
| `deceased.type`, `deceased.id`, `deceased.relationship` | `String?` |
| `deceased.name` | `String` — never null, may be `""` on an old row |
| `description` | `String?` |
| `status` | `String` — `pending` \| `reviewed` \| `approved` (the only three you can ever cause) |
| `created_at`, `reviewed_at`, `approved_at` | `String?` — ISO 8601 with offset |
| `actions.review`, `actions.approve` | `bool` |

---

## Checked live, so you do not have to

- Treasurer `/condolences`: 2 rows, TZS 1,700,000. Member: 403 naming `/my/condolences`.
- Member `/my/condolences`: empty list (not an error) — they have no cases of their own.
- Member `/condolences/2` (another member's case): 404. Treasurer, same id: 200, with a full trail.
- Admin (no member record) `/my/condolences`: 403 `no_member_record`.
- Recording as a member: 403. No `deceased_name`: 422. Zero amount: 422. Unknown `member_id`: 404.
  Approving a nonexistent id: 404. A member reviewing: 403.
- Full lifecycle — create → review → approve — with `"1,500"` stored as `1500`, `sig_warning`
  present (no e-signature on file), the case appearing on the group list immediately after.
- The deceased-marking side effect fired correctly for a non-matching `deceased.id`: no change to
  the named member's account.

**One test case exists on demo:** case #3, TZS 1,500, description "API smoke test — safe to
delete", left **approved**. There is no reject/waive path for condolences, and even the web's own
delete action refuses an approved case — so it cannot be cleanly removed through any sanctioned
path. It is clearly labelled and does not affect any real member's data.
