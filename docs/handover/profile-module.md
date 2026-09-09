# Profile — what to know before you build it

Live on both servers. Reference: `docs/API.md` §21.

---

## This is the "My Account" screen, not the leadership member-editor

If you've already built §5 Members (`GET/PUT /members/{id}`), don't reach for this module's
`PUT /profile` expecting the same field set — it's deliberately smaller. `PUT /profile` only ever
touches `first_name`/`middle_name`/`last_name`/`email`/`phone`. There is no spouse, parents,
guarantor, or NIDA data here — that's §5's job, and a member editing their own record there already
goes through `PUT /members/{id}` if they hold `edit` on `customers` (leadership only). This module
is the opposite: every endpoint is self-only, but the field set is narrow. Every authenticated
user — including a plain Member — can `GET`/`PUT` their own `/profile`, unlike §20 (Settings &
Roles), which is Admin-only end to end.

---

## Password change is its own endpoint, not part of `PUT /profile/settings`

`POST /profile/password` takes `current_password`/`new_password`/`confirm_password` — don't try to
send these on `PUT /profile/settings`, they don't belong there and the endpoint won't accept them.
Checked in this order: all three fields present → current password correct (`401 wrong_password`
otherwise) → new/confirm match (`422 mismatch`) → password policy (`422 weak_password`, 8+
characters, a letter, a number). Build the form to surface whichever of those four failure states
comes back rather than a single generic "couldn't change password" message — the `code` field tells
you exactly which one happened.

---

## `avatar_url` always points at `GET /avatar` — never construct an upload-URL yourself

Every `avatar_url` this module returns (in `GET /profile` and `POST /profile/avatar`'s response) is
a ready-to-use, absolute URL pointing at `GET /avatar?name=...`. **Fetch it with the same Bearer
token you use for everything else** — it's a real authenticated endpoint, not a public image URL,
so a plain `Image.network(avatarUrl)` with no auth header will get a `401`. If your HTTP/image
client doesn't let you attach headers to an `Image.network`-style widget directly, download the
bytes yourself (with the token) and hand them to `Image.memory` instead.

Do not build your own avatar URL by guessing a path under `/uploads/avatars/` or similar — the
filename alone is meaningless without going through `/avatar`, and the server may change where
files are physically stored without changing this endpoint's shape.

---

## Uploading a new avatar

`POST /profile/avatar` — multipart, field name **must be** `avatar`, 2 MB max, real image bytes
required (a renamed non-image file is refused even if it has a `.png` extension). No file attached
at all is a `422 no_file`, not a silent no-op.

```json
{"status":"success","data":{"avatar":"avatar_1788947678_d3c21f1ba58c856d.png","avatar_url":"https://demo.vikundi.bjptechnologies.co.tz/api/v1/avatar?name=avatar_1788947678_d3c21f1ba58c856d.png"}}
```

Use the returned `avatar_url` directly — don't reconstruct it from `avatar` (the bare filename)
yourself, even though you technically could; the server already did the work of building the right
URL for whichever environment you're hitting (demo vs. production).

---

## Field types

| Field | Dart |
|---|---|
| `profile.middle_name` | `String` — empty string when unset, **not** `null` (unlike most optional-text fields elsewhere in this API) |
| `profile.avatar_url` | `String?` — `null` until an avatar is uploaded; once set, always absolute (`https://...`), never a relative path |
| `profile.member_id` | `int?` — `null` for a login with no linked member/customer record (e.g. the Admin account) |
| `profile.role_id` | `int?` |
| `settings.email_notifications`, `settings.sms_notifications` | `bool`, already cast server-side |
| `settings.language` | `String`, `en` or `sw` |
| `settings.theme` | `String`, `light` or `dark` |

---

## Checked live, so you do not have to

- A plain Member (not Admin) successfully used every endpoint in this module on their own
  account — `GET`/`PUT /profile`, `GET`/`PUT /profile/settings`, `POST /profile/password`,
  `POST /profile/avatar`. This module has no leadership gate anywhere to trip over.
- `PUT /profile` with a new email already used by another account: `409 email_taken`, nothing
  written.
- `PUT /profile/settings` with only `{"theme": "dark"}` sent: `language`/`timezone`/`date_format`/
  both notification flags were untouched on the next `GET`.
- `POST /profile/password`: wrong current password → `401`; new password too weak → `422
  weak_password` with the actual policy text; correct change → `200`, and the new password logged
  in successfully on the next `/auth/login` call.
- `POST /profile/avatar`: a real upload succeeded and was immediately fetchable via the returned
  `avatar_url` using the same Bearer token; a `.php` file renamed to `.png` was refused
  (`422 invalid_avatar`) by the byte-content check, not just the extension.
- `GET /avatar` without a token: `401`. With a path-traversal filename (`../../etc/passwd`-shaped):
  refused before any file was touched.
