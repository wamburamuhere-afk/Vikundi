# Vikundi mobile API — handover

Everything the Flutter session needs, current as of **2026-08-28**.

Read these in order. `docs/API.md` is the reference; the files here are the parts that
are easy to get wrong.

| Document | Read it when |
|---|---|
| `docs/API.md` | Always. Every endpoint, every field, every error code. |
| `auth-me-permissions-fix.md` | Before writing any permission check. |
| `contributions-module.md` | Before building the contributions screens. |
| `transactions-module.md` | Before building Transactions — and before you sum anything. |
| `fines-module.md` | Before building Fines. Its access rules are **not** the contributions ones. |

---

## What is live

Both `vikundi.bjptechnologies.co.tz` and `demo.vikundi.bjptechnologies.co.tz`.

| Module | Endpoints | Screens it unlocks |
|---|---|---|
| 1. Auth | 4 | Login, session, token refresh, logout |
| 2. Dashboard | 1 | Home — role-aware |
| 3. Members | 8 | Roster, detail, register, edit, approve/reject/reactivate |
| — Group settings | 3 | Chrome, the editable settings form, logo upload |
| 4. Contributions | 8 | My Contributions, the ledger, the approval workflow |
| 5. Transactions | 2 | The group ledger by date, the member's own receipts |
| 6. Fines | 7 | Manage Fines, My Fines, the group-fines view |

**33 endpoints.** Group settings also gained a full read/write field set and a logo upload
since the last handover — see below.

Not yet built: Condolences / Death Expenses, Financial Ledger, Expenses & Petty Cash,
Budgets, Payouts, Meetings, Documents, Loans. Anything on those screens has to stub or wait.

---

## Changed since the 2026-08-26 handover

**Group settings now round-trips.** `GET /group-settings` returns a flat `settings` object keyed
**exactly** as `PUT` accepts, so an edit form pre-fills itself with no name mapping. It is
returned only to admins/Chairperson/Secretary — branch on the new `can_edit`, not on
`settings == null`. The Treasurer gets `null` here despite being leadership everywhere else.

Two traps in it, both fixed server-side and documented in `docs/API.md` §6:

- `deadline_day` holds an **`int` for a monthly cycle and a Swahili day name for a weekly one**.
  Decode it as `dynamic` and branch on the new `cycle_type`. It used to be typed as an integer,
  so echoing a weekly group's value back would have written `0`.
- `meeting_day` is **always Swahili** (`Jumatatu` … `Jumapili`), whatever the display language.
  `PUT` accepts English and normalises it; `GET` always returns Swahili.

**The group logo is now uploadable and loadable.** `POST /group-settings/logo` (multipart, field
`logo`, officer-only, JPG/PNG/GIF/WEBP, 2 MB). `group.logo` was always the raw stored *filename*,
not a URL — use the new **`group.logo_url`**, which is absolute and has the default applied.

---

## Four rules that apply to every module

These are the ones that have actually cost time. None is specific to one endpoint.

### 1. Money is a JSON number, not a Dart `double`

```dart
final amount = json['amount'] as double;            // ✗ throws on most real rows
final amount = (json['amount'] as num).toDouble();  // ✓
```

The server casts to float, but `json_encode` writes `10000` for a whole amount. Live:
`"monthly_contribution": 10000`, `"total_saved": 440000`, `"collection_rate": 100` — all
`int` after `jsonDecode`. Use `num` everywhere, in every module. This is the single most
likely cause of a runtime crash in a screen that "worked yesterday": the value stayed whole.

### 2. `permissions[page][action] == true` is the whole check

Sufficient for **every** role including Admin, since the `/auth/me` fix. Do not
special-case Admin beyond an optional `isAdmin ||` in front.

**Never key logic on `data.user.role`** — it is an empty string for the Admin on live data,
and role names are editable in Settings. Use `role_id`, `is_admin`, `is_leadership`, or the
permission map.

### 3. Nullable fields that look non-null

| Field | Why |
|---|---|
| `user.member_id` | `null` for an account with no member record (the Admin) |
| `scope.member_id` | `null` when a leader is viewing the whole group |
| `scope.own_member_id` | `null` for the Admin |
| `collection_rate` | `null` when the group has no monthly target — **not** `0`. And when it is present it arrives as `num`, not `double`: live it came back as `100`, an `int`. |
| `settings` (group settings) | `null` for anyone who cannot edit — branch on `can_edit` |
| `mkoba.*` (transactions) | `null`, never `""`, on a row not imported from M-Koba |
| `reason`, `meeting_title` (fines) | `null` when absent |
| `totals.fined_members` (fines) | `null` in the `mine` view — it only means something for the group |

### 4. Responses are shape-variant by role

`/dashboard` for a Member is **missing** six keys entirely — `balance`, `members`,
`contributions`, `expenses`, `fines`, `trend` — not null. Use `containsKey`, and branch on
`is_leadership`.

Verified live: a Treasurer's `/dashboard` has 11 keys, a Member's has 5. The six missing are
exactly `balance`, `contributions`, `expenses`, `fines`, `members`, `trend`.

`recent_activity` appears only when **`is_admin` is true** — that is the Admin *and the
Chairperson* (role_ids 1, 2, 12), not the Admin account alone. Check `is_admin`, not the
username.

This is deliberate: JSON has no template to hide behind, so figures a member may not see
are withheld rather than blanked.

---

## Do not infer API rules from the web app

On 2026-08-26 seven web endpoints were found serving the whole group's savings to any
signed-in member, because they gated on `manage_contributions.view` — the grant a Member
legitimately holds so they can open their own contributions.

**The mobile API was never affected.** It has always used the correct test. But if you were
comparing behaviour against the web to work out what a role should see, the web was the
wrong reference until that deploy.

The rule, now identical on both:

```
group-wide data  ->  LEADERSHIP: is_admin, or permissions['<page>'].edit
a single record  ->  OWNERSHIP:  it is yours, or you are leadership
```

`edit`, never `view`. `view` is what a Member holds.

Every list endpoint tells you which side you landed on:

```json
"scope": { "is_leader": false, "member_id": 30, "own_member_id": 30 }
```

**Render from `scope`.** Do not re-derive it, and do not send a `member_id` you were not
given — as a non-leader it is silently overwritten with your own, which is correct but
means a screen built on the assumption it worked will quietly show the wrong person's name.

---

## Test accounts

Demo site, password `Demo@2026`:

| Role | Username | `member_id` | Use it for |
|---|---|---|---|
| Admin | `admin` | **`null`** | The no-member-record path — hide personal screens |
| Chairperson | `rmollel` | 1 | Full leadership |
| Secretary | `amhando` | 2 | Leadership without the audit trail |
| Treasurer | `hmtui` | 3 | Leadership; **cannot** edit group settings |
| Member | `hmbwana1` | 30 | The restricted view |

Any other seeded member is `username` + `@123`.

**Test as `hmbwana1` and `admin` before shipping any screen.** Member is the only role where
fields and rows are *removed* rather than added, and Admin is the only one with no member
record — between them they catch nearly every role bug.

---

## Reporting a problem

If a response looks wrong, say so rather than working around it. The `/auth/me` permissions
issue was reported from the Flutter side, was a genuine server bug, and was fixed on the
server — a workaround would have hidden it and every later consumer would have hit it too.

Include the endpoint, the account you used, and the actual response.
