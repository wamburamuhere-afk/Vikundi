# Settings & Roles — what to know before you build it

Live on both servers. Reference: `docs/API.md` §20.

---

## Every endpoint in this module is Admin/Chairperson only — full stop

Unlike almost every other module in this API, there is no Member view here at all, not even
read-only. Users, Roles & Permissions, System Settings, Backups — all of it is gated on
`role_id IN (1, 2, 12)`, checked directly, not through a `role_permissions` grant. If your app has a
"Settings" section in the nav, gate the whole thing on `is_admin` from `/auth/me`, not a
page-by-page permission check the way you would for Expenses or Budgets.

---

## There is no user deletion anywhere in this API — don't build a delete button

The web's own quick-action endpoint has a `'deleted'` status value that doesn't soft-delete — it
runs an actual `DELETE FROM users` — and it isn't even a real value in the live database's
`users.status` enum (`pending`/`active`/`rejected`/`dormant` are the only four). `PUT /users/{id}`
validates against that real enum only; sending `"status": "deleted"` is a plain `422`, not a path to
anything destructive. If a "deactivate user" screen is in scope, it means setting `status` to
`dormant` or `rejected` — there is no way to remove a user account through this API, and there
shouldn't be a button that implies otherwise.

---

## Role `1` (Admin) is permission-protected

`GET /roles/1/permissions` works normally. `PUT /roles/1/permissions` always refuses:

```json
{"status":"error","code":"protected_role","message":"The Admin role is protected and its permissions cannot be modified."}
```

If you're building a "manage roles" screen with a permissions grid per role, the Admin row should
render read-only (or hide its Save button) rather than let the user submit and then show them this
error.

---

## `PUT /settings/system` is a partial update, section by section

The body is `{"general": {...}, "email": {...}, "sms": {...}, "security": {...}, "group": {...}}` —
send only the section(s) you're changing; anything else on the server is untouched. If your
Settings screen has separate tabs (General / Email / SMS / Security), each tab's Save button should
send only its own section object, not the whole settings payload — matching the web's own
independent per-tab Save buttons.

**SMTP and SMS secrets come back in the clear to an Admin caller.** `GET /settings/system` includes
`smtp_password` and `sms_api_secret` as plain strings when they're set — this is intentional (an
Admin managing their own configured credentials needs to see and edit them, same as the web) and
this endpoint is Admin-only, so there's no exposure beyond that. Don't log the full response body
anywhere it might persist.

---

## Backups: no restore, and downloading is a separate endpoint

`POST /settings/backup` creates a real database dump and returns its filename; `GET /settings/backup`
lists existing ones. There is no restore action in this API — the web's own restore overwrites the
live database, and it was never in scope to expose that on a phone. To actually fetch a backup file,
use `GET /backup-download?file=<filename>` (a top-level endpoint, not nested under `/settings/`) —
it streams the raw `.sql` file, not a JSON envelope, so handle it as a binary download in your HTTP
client rather than parsing it as JSON.

---

## Field types

| Field | Dart |
|---|---|
| `user.status` | `String`, one of `pending`/`active`/`rejected`/`dormant` — never `deleted` |
| `user.role_id` | `int?` — in practice always set for a real account |
| `role.description` | `String?` |
| `role.user_count` | `int?` — `null` on the permissions-grid response (`GET /roles/{id}/permissions`), populated on the plain `GET /roles` list |
| `permissions[].can_view` etc. | `bool`, not `0`/`1` — already cast server-side |
| `settings.*` (every section) | every key is present even when unset; unset values are `null`, not omitted |
| `settings.group` | `Map<String, dynamic>` — a passthrough JSON blob, no fixed shape |
| `backups[].size` | `String` (e.g. `"602.88 KB"`) — pre-formatted for display; use `size_bytes` (`int`) if you need the raw number |

---

## Checked live, so you do not have to

- Member: `403` on every single endpoint in this module, including `GET /roles` and
  `GET /settings/system` — there is no read-only Member view anywhere here.
- A freshly-created user, promoted to `role_id: 2` (Chairperson) via `PUT /users/{id}`, then set to
  `status: "dormant"` — both writes reflected immediately on the next `GET`.
- `status: "deleted"` on `PUT /users/{id}`: `422 invalid_status`, nothing written.
- `PUT /roles/1/permissions`: `403 protected_role`. `GET /roles/1/permissions`: works normally.
- `POST /settings/backup` created a real ~600 KB dump; `GET /settings/backup` listed it;
  `GET /backup-download?file=...` streamed it back with the Bearer token, and a `../` traversal
  attempt in `file` was refused before any filesystem access.
- `PUT /settings/system` with only `{"general":{"company_name":"..."}}` sent never reset the
  `email`/`sms`/`security` sections — confirmed by re-fetching after the write.
