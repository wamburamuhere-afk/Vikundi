# Voting & Leadership Applications — what to know before you build it

Live on both servers. Reference: `docs/API.md` §17.

---

## This is genuinely a secret ballot — don't build a UI that assumes otherwise

There is **no endpoint, anywhere, that returns who voted for what**. `GET /elections/{id}/results`
gives you counts per option and a turnout number — never a list of voters. If a screen you're
designing needs "show me which members voted for candidate X," that data does not exist in this
system by design, not by omission. Don't ask for it as a "missing endpoint" — it's a feature.

Related: `POST /votes` writes **no activity-log entry**. If you have a generic "recent activity"
feed elsewhere in the app pulling from an audit-log endpoint, a cast vote will never appear in it.
That's intentional.

---

## Tally visibility depends on THREE things — status, role, and a publish flag. Drive the UI from `can_see_tally`, not your own logic

`GET /elections/{id}/results` always includes `can_see_tally: bool`. When `true`, you get a
`tally` array (with vote counts). When `false`, you get an `options` array instead (labels only, no
counts) for the exact same election. **Don't try to predict this client-side** from
`status`/`publish_results` yourself — call the endpoint and branch on `can_see_tally`. The three
inputs are: election still open → never see tally, no matter who you are. Election closed +
caller is leadership → always see it. Election closed + caller is a Member → only if
`publish_results` was set when the election was created.

---

## A member's "which elections can I vote in" list is already scoped — don't re-filter it

`GET /voting/open` does **not** return every open election — it returns only the ones the calling
member is eligible for, per a snapshot taken the moment the election opened. If someone joined the
group *after* an election opened, it will never appear for them, even while it's open. Don't add
your own "is this member allowed" check on top of what this endpoint returns; there isn't a
separate "am I eligible" flag to check — absence from the list **is** the answer.

---

## Applying for a leadership position is forgiving — a second "Apply" tap just updates, it doesn't error

If a member withdraws and then applies again for the same election, `POST /leadership-applications`
updates their existing application rather than creating a duplicate or refusing. Build your "Apply"
button to just fire the POST every time the form is submitted — you don't need to first check
"do I already have an application" and switch to a PUT. The one time it *does* refuse
(`409 already_reviewed`) is when the Committee has already ruled — approved or rejected — and that
decision is final.

---

## The Committee review queue does NOT need an election picker — it's flat by default

The web page makes you pick one election first, then shows its applications. This API's
`GET /leadership-applications` returns **every** application across **every** election in one call
(optionally narrow with `?election_id=`/`?status=`). Build the mobile review queue as one flat,
filterable list — you don't need to reproduce the web's "pick an election" step.

---

## `contribution_standing` on a review-queue row is informational only — never a blocker

Every row in the Committee queue carries `contribution_standing: {behind, amount, months, oldest}`.
This is shown so the Committee makes an informed decision, not to gate the approve button — a
member behind on contributions can still be approved. Don't disable "Approve" based on this field.

---

## Field types

| Field | Dart |
|---|---|
| `election.status` | `"draft"` / `"open"` / `"closed"` — auto-transitions server-side past `closes_at`, so don't assume a stale client-cached status is still accurate |
| `option_count`/`eligible_count`/`voted_count` | Only on the list row, not on detail |
| `application.status` | `"pending"`/`"approved"`/`"rejected"`/`"withdrawn"` |
| `application.actions` | `{edit, withdraw, approve, reject, reset}` — all five always present; which are `true` depends on both who's asking and the row's own state |
| `proposer` | `null` when no proposer was named — never a zeroed id |

---

## Checked live, so you do not have to

- Full lifecycle on the local instance: created an empty candidate election → two members applied
  → Committee approved both (confirmed each wrote a real `vote_options` row) → opening with only
  one approved candidate correctly refused `too_few_options` → opened with two → a member's
  `voting/open` view showed both with `has_voted: false` → voted → a second vote from the same
  member refused `409` → results hid the tally for both an Admin and a Member token while open →
  closed → Admin saw the tally, Member (unpublished) still didn't → deleted, cascade confirmed to
  have removed the applications and options too.
- Withdraw → re-apply updates the same application row (same id, not a new one).
- Approve → reset correctly deleted the ballot option and returned the application to pending.
- Reject without a `note` refused `422`; with one, succeeded.
- A motion election got the fixed Yes/No/Abstain options automatically on creation.

## Two permission gaps found and fixed while this module was being built — both already live

**Secretary/Treasurer couldn't vote in their own group's elections.** They could create and manage
elections (`manage_voting`) but had no grant on the plain `voting` key needed to cast a personal
ballot. Fixed with a migration; if you're testing with older seeded accounts and a leadership
account gets `403` on `POST /votes`, that account's permissions predate the fix — re-run
`database/migrate.php`.

**Any Member could view the Committee's entire review queue.** Found and fixed the same day this
module deployed — a role-permission default was missing an exclusion that its sibling permission
already had. If you're testing against an old cached permissions snapshot for a Member account and
`GET /leadership-applications` unexpectedly succeeds, that's stale data, not a regression — live
demo/production correctly return `403` for Member now.
