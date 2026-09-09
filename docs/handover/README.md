# Vikundi mobile API — handover

Everything the Flutter session needs, current as of **2026-09-09**.

Read these in order. `docs/API.md` is the reference; the files here are the parts that
are easy to get wrong.

| Document | Read it when |
|---|---|
| `docs/API.md` | Always. Every endpoint, every field, every error code. |
| `auth-me-permissions-fix.md` | Before writing any permission check. |
| `contributions-module.md` | Before building the contributions screens. |
| `transactions-module.md` | Before building Transactions — and before you sum anything. |
| `fines-module.md` | Before building Fines. Its access rules are **not** the contributions ones. |
| `condolences-module.md` | Before building Condolences. Its access rules are **not** the fines ones either — read it even if you've read fines-module.md. |
| `financial-ledger-module.md` | Before building the Ledger or M-Koba Reconciliation screens — read the permission note at the top before you build any access-control UI around them. |
| `expenses-petty-cash-module.md` | Before building Expenses or Petty Cash — read the mark-paid permission note first; it is not gated like the rest of the module. |
| `budgets-module.md` | Before building Budgets — the one module with no Member grant at all, and where Admin can edit an approved record but leadership cannot. |
| `payouts-module.md` | Before building Payouts — the Treasurer cannot use this screen. Don't route it through a generic "leadership" check. |
| `meetings-module.md` | Before building Meetings — attendance is upsert-only, not "resubmit the roster." |
| `documents-module.md` | Before building Documents — it's two unrelated features (Library, Document Writer) sharing a nav menu, not one screen. |
| `voting-module.md` | Before building Voting or Leadership Applications — tally visibility depends on `can_see_tally`, never your own logic. |
| `reports-module.md` | Before building any statement screen — `member-statement`/`member-transactions` have no permission gate at all, ownership is silent. |
| `communication-module.md` | Before building Messages/SMS/Email/AI — `GET /messages` is a real Member inbox, not leadership-only; SMS/Email/AI are leadership-only with no exceptions; AI is not configured on either server today. |
| `settings-roles-module.md` | Before building any Settings screen — the whole module is Admin/Chairperson only, no Member view anywhere, and there is no user-delete endpoint. |
| `profile-module.md` | Before building "My Account" — it's deliberately narrower than the Members module, and `avatar_url` needs the same Bearer token as every other request. |

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
| 7. Condolences | 7 | Manage Condolences, My Condolences, review/approve, the sustainability report |
| 8. Financial Ledger & Reconciliation | 3 | The group ledger, M-Koba statement tie-out (group + own) |
| 9. Expenses & Petty Cash | 15 | Record/edit/review/approve/mark-paid both, the spending report |
| 10. Budgets | 6 | Record/edit/review/approve/reject, with line items |
| 11. Payouts | 2 | Record member assistance, the payout history |
| 12. Meetings | 7 | Schedule/edit/delete, attendance, fine absentees |
| 13. Documents | 12 | The file Library, and the Document Writer with multi-party e-signing |
| 14. Voting & Leadership Applications | 18 | Elections end to end, applying/reviewing to stand for office |
| 15. Reports & Statements | 6 | Both NSSF-style statements, the group statement, the two summary reports |
| 16. Communication | 11 | Messages (real inbox), SMS/Email send, Email Templates, Notifications, AI Ask/Chat |
| 17. Settings & Roles | 12 | User management, role/permission editing, system settings, database backups |
| 18. Profile | 7 | My Account, my settings, change password, avatar upload |

**139 endpoints. Every module in `todo.md`'s build list is now shipped.**

Not yet built, and not going to be: Bank Reconciliation, Loans (both excluded — see `docs/API.md`'s
own notes on why: no nav link, no real data, no live permission key). Nothing left in this API is
"queued."

---

## Changed since the 2026-09-07 handover

Three modules, 30 endpoints — Communication, Settings & Roles, Profile. This is the last batch:
every module in `todo.md`'s original plan is now live. One correction to an earlier diagnosis is
also recorded below; read it if you're touching anything backup-related.

**Module 16 — Communication — is live.** `communication-module.md` covers it. `GET /messages` is a
genuine Member-facing inbox, not a leadership screen — don't gate it behind `is_leadership`. Sending
anything (a message, SMS, or email) is leadership-only, checked via `can_send` in the messages
response rather than a role guess. There is no `GET /email` at all, by design. AI Ask/Chat both need
a real provider key that isn't configured on either server yet — build the `ai_not_configured` state,
it's the only one you can currently test.

**A real permission gap was found and fixed the same day this module deployed.** The web's own
`message_center.php` send handler had no permission check at all beyond being logged in — any
Member could POST directly and message anyone. Fixed in both transports from one rule before this
shipped; verified live that a Member's direct POST is now refused with the correct message and the
page no longer crashes on the refusal (a separate, pre-existing bug in the same handler, found and
fixed in the same pass).

**Module 17 — Settings & Roles — is live.** `settings-roles-module.md` covers it — the one module
with **no Member access anywhere**, not even read-only. Gated on `role_id` directly, not a
permission-table grant. There is no user-delete endpoint in this API and there will not be one — the
web's own equivalent runs an actual `DELETE FROM users` behind a status value that isn't even real.

**A live, actively-exploitable vulnerability was found and fixed as a standalone hotfix, deployed
BEFORE this module was built on top of it.** `system_settings.php`'s permission check called a
function that doesn't exist anywhere in the codebase — commented out rather than fixed, so the page
had no gate at all. Any authenticated user, including a plain Member, could read the SMTP password
and SMS gateway secret in plaintext and rewrite mail/SMS credentials, security policy, and disable
audit logging. Closed before Module 17's own endpoints were written, let alone deployed — the mobile
API was never exposed to this.

**A correction, not a new finding: an earlier diagnosis about `isAdmin()` was wrong.** While
verifying that hotfix, a Member account was observed bypassing an admin-only backup endpoint on
demo. The first diagnosis blamed `core/permissions.php`'s `isAdmin()` name-matching fallback. On
closer inspection (via Module 17's own `GET /roles/{id}/permissions`), the actual cause is much more
mundane: **the `backup_restore` permission key had a stray `can_view` grant for the Member role** in
demo's database — a straightforward bad-grant issue, the same shape fixed by a migration in several
earlier modules, unrelated to `isAdmin()`. This does not affect anything in this API — every
endpoint here is gated on `role_id` directly or `vk_api_can()`, never a raw `role_permissions` row
without a bypass check — but if you ever see a stray reference to this in code comments or session
notes, the corrected explanation is the one that's accurate.

**Module 18 — Profile — is live.** `profile-module.md` covers it. Deliberately narrower than the
Members module (§5) — only name/email/phone/avatar, no family or guarantor data — and every endpoint
is self-only, available to any authenticated user, the opposite of Module 17's admin-only shape.

**Two real gaps were found and fixed before this module was built on top of them.** The web's own
"My Settings" page had **no CSRF protection** on any of its three forms (profile save, password
change, preferences) — a forged cross-site request could change a logged-in user's password without
their knowledge. And the obvious avatar-URL helper points at a session-gated web endpoint that a
token-authenticated mobile client can never reach — confirmed live as a `401` fetching your own
avatar. `avatar_url` in every response from this module now points at this API's own
`GET /avatar` instead, which has no such problem — **always send the same Bearer token when
fetching it**, it is not a public image URL.

---

## Changed since the 2026-09-05 (2) handover

Four modules, 43 endpoints. Everything to do with the group's money was already finished as of the
last handover; this round is the group's occasional-use administration: meetings, documents,
elections, and the reporting screens.

**Module 12 — Meetings — is live.** `meetings-module.md` covers it. No workflow at all. The one
thing worth re-reading before you start: `POST /meetings/{id}/attendance` upserts only the rows you
send — it is **not** a "resubmit the whole roster" endpoint like the web page behind it. `DELETE` is
a genuine HTTP `DELETE`, the first one in this API.

**Module 13 — Documents — is live.** `documents-module.md` covers it. Two unrelated features under
one API: the file **Library** (`/documents...`) and the in-app **Document Writer**
(`/authored-documents...`) with multi-party e-signing. A hidden document 404s, never 403s — the
server won't confirm something exists that you're not allowed to see. Signing your own slot on a
multi-party document needs no leadership permission at all; check `signatories`, not a role flag.

**A permission-key bug shipped, then was caught and fixed live the same day.** The Library was
initially gated on only the migration-tracked key (`document_library`); demo/production's actual
grants turned out to be under the plain key `library` instead, which 403'd every non-admin role for
about fifteen minutes. Both keys are checked now (`vk_api_doc_library_can()`). You were never
exposed to the broken window — this shipped and was fixed before this handover was written — but if
you ever see both strings in the source and wonder why, that's why.

**Module 14 — Voting & Leadership Applications — is live.** `voting-module.md` covers it — read it
before building anything here, it has the most non-obvious rules in this handover. Genuinely
secret-ballot: no endpoint anywhere returns who voted for what. Tally visibility is driven entirely
by the response's own `can_see_tally` flag — don't try to predict it from status/role yourself.
Applying for a position is forgiving (a second "Apply" just updates); reviewing it is not (a
rejection needs a reason, and a ruled-on application is final).

**A real privacy gap was found and fixed the same day this module deployed**: any ordinary Member
could view the Committee's entire leadership-application review queue — every applicant's
statement, proposer, and review notes, across every election. A permission-role default was missing
an exclusion its sibling already had. Fixed and re-verified live within the hour; Member correctly
gets `403` on `GET /leadership-applications` now.

**Module 15 — Reports & Statements — is live.** `reports-module.md` covers it. The URLs don't nest
the way the plan implied — `member-statement`/`member-transactions` are their own top-level
resources, not paths under `/reports/`. Those two also have **no permission gate at all** — only a
silent ownership override on `{id}`. The group-statement and summary reports are visible to ordinary
Members by existing product policy — not a leak, not something to route around with an extra
client-side check.

---

**Previously (2026-09-05): Module 10 — Budgets — went live.** `budgets-module.md` covers it in full. Three-stage workflow —
`pending → reviewed → approved`, or `pending|reviewed → rejected` — one shorter than Expenses/Petty
Cash: no `paid` state, no fund-balance gate. Full CRUD + review/approve/reject, with line items.

**Read `budgets-module.md`'s top section before building the access-control UI.** Two things are
unique to this module: **Member holds nothing here, not even `view`** — the opposite of Expenses/
Petty Cash/the Financial Ledger, where Member has a live grant. And `PUT /budgets/{id}`'s
approved-edit block **exempts Admin but not Secretary/Treasurer** — drive it from `actions.edit`,
don't hardcode the rule either way.

**The worst permission inconsistency found in any module so far was fixed before this shipped.**
Of the web's seven budget action files, only two checked any permission at all.
`api/account/update_budget_status.php` was a **complete workflow bypass** — any authenticated user
could set a budget straight to `approved`, confirmed live before the fix. `budget.php`'s own inline
AJAX data endpoint had **no auth check whatsoever** — also confirmed live (an unauthenticated
`curl` reached the query). Both closed; four more files that checked only "logged in" now check the
real permission.

**Module 11 — Payouts — is also live.** `payouts-module.md` covers it. The simplest module in the
API: no workflow, no fund-balance gate, a record is `'paid'` from the instant it's created.

**Read `payouts-module.md` before wiring this into a shared "leadership" check.** Every other
financial module this week grants full leadership including Treasurer; this one deliberately
doesn't — Admin/Chairperson/Secretary only, mirroring the web's own role list exactly. Verified
live: Treasurer gets `403` here.

---

**Previously (2026-09-03): Module 9 — Expenses & Petty Cash went live.**
`expenses-petty-cash-module.md` covers it. Four-stage workflow — `pending → reviewed → approved →
paid` — the first module where "approved" and "actually disbursed" are different, tracked states.
`mark-paid` is gated on a role (`canMarkPaid()`), not the `role_permissions` grant everything else
in the module uses. `actions/fetch_petty_cash.php` had no permission check at all before this,
confirmed live; closed alongside the build. Member gets `200` on both list endpoints — a deliberate
mirror of an already-audited web behavior, not a leak.

**Before that (2026-09-02 → 2026-09-03): Module 8 — Financial Ledger & Reconciliation went live.**
`financial-ledger-module.md` covers it. Two permission-table gaps were found and fixed the same
shape as the `manage_contributions` gap from the Contributions handover: `vicoba_reports` had no row
in the permissions catalog at all, and `mkoba_reconciliation` had a row but zero role grants — both
meant Secretary/Treasurer were refused reports Admin/Chairperson could already see. `bank-reconciliation`
was scoped in the original plan but excluded — no nav link anywhere in the web app, its backing
tables are empty, and its permission key doesn't exist in the catalog.

**Before that (2026-08-28 → 2026-09-02): Module 7 — Condolences went live**, along with a fix for a
group-wide condolence data leak (`death_expenses.view` being read as group-wide access). See
`condolences-module.md` if you haven't already — in particular, approving a condolence case whose
`deceased.id` is `"member"` marks that member's own account deceased and dormant; warn the leader
before they tap Approve.

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
| `deceased.type`, `deceased.id`, `deceased.relationship` (condolences) | `null` when absent; `deceased.name` is never null |
| `expenses[].member` | `null` for a whole-organization expense — never a bare `member_id` to check against 0 |
| `trail.paid.signed` (expenses, petty cash) | always `false` — mark-paid has never captured an e-signature, on either module |
| `budgets[].items`, `budget.items` (budgets) | omitted on the list (not `null` — the key is absent), present on the detail endpoint |
| `trail` (budgets) | only `created`/`reviewed`/`approved` keys exist — never a `rejected` key, even when `status` is `rejected` |
| `payouts[].description` (payouts) | `null` when blank, never an empty string |
| `profile.avatar_url` | `null` until an avatar is uploaded; once set, always absolute (`https://...`) |
| `profile.middle_name` | empty string `""` when unset — the one exception to the "blank means null" rule above, matching the web form it mirrors |
| `role.user_count` (settings & roles) | `null` on the permissions-grid response, populated on the plain roles list |
| `sms[].sent_at` | `null` until a send genuinely succeeds — stays `null` on `"status": "failed"` |

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
