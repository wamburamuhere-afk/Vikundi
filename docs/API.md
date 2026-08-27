# Vikundi Mobile API — Reference

Everything the Flutter app needs, endpoint by endpoint.

**Every JSON response in this document was captured from a running server**, not written by
hand. Field names and types are what you will actually receive. Tokens are shortened; nothing
else is edited except two long lists, which are marked with a `__note__` key that does **not**
exist in the real response.

- **Demo (build against this):** `https://demo.vikundi.bjptechnologies.co.tz/api/v1`
- **Production:** `https://vikundi.bjptechnologies.co.tz/api/v1`

Same code on both. Demo has synthetic data and is safe to hit freely.

---

## Contents

1. [How every request works](#1-how-every-request-works)
2. [Errors](#2-errors)
3. [Auth](#3-auth)
4. [Dashboard](#4-dashboard)
5. [Members](#5-members)
6. [Group settings](#6-group-settings)
7. [Contributions](#7-contributions)
8. [Demo logins](#8-demo-logins)

---

## 1. How every request works

### The envelope

Every response — success or failure — is one of these two shapes. There is no third.

```json
{ "status": "success", "data": { ... } }
```

```json
{ "status": "error", "code": "machine_readable_string", "message": "Human sentence." }
```

Branch on `status`. Switch on `code`, never on `message`: messages are prose and may be
reworded or translated; `code` is the contract.

### Authentication

Send the access token on every request except `login` and `refresh`:

```
Authorization: Bearer <access_token>
```

### The 401 → refresh → retry loop

**Build this before you build any screen.** The access token lasts one hour, so you will hit
this constantly and every screen would otherwise need its own error handling.

```
request → 401
   ↓
POST /auth/refresh with the stored refresh_token
   ↓ success                     ↓ 401
store the new pair,          wipe both tokens,
retry the original request   send the user to login
```

The refresh token **rotates**: the one you present is revoked and a new one comes back. Store
the new pair immediately — if you keep using the old refresh token every later refresh fails
and the user is logged out for no visible reason.

Do not attempt more than one refresh per failed request, or a genuinely expired session becomes
an infinite loop.

### Deciding what to show

Call `/auth/me` at start-up and read `permissions`. **Do not hardcode role names in the app.**
The server enforces that same map, so anything you render outside it will fail when tapped, and
anything you hide inside it is a feature the user paid for and cannot reach.

`user.member_id` is `null` for an account with no member record (a system Admin). Personal
screens — My Contributions, My Fines — do not apply to that account and should not be shown.

### Pagination

List endpoints take `page` (default 1) and `per_page` (default 25, **max 100** — larger values
are clamped, not rejected). They return:

```json
{ "pagination": { "page": 1, "per_page": 25, "total": 30, "total_pages": 2, "has_more": true } }
```

Use `has_more` for infinite scroll rather than comparing counts yourself.

### Money and dates

- Amounts are **numbers**, not strings, in whole Tanzanian shillings. Currency is `TZS`
  (`/group-settings` confirms it).
- **A money value is not reliably a Dart `double`.** The server casts to float, but
  `json_encode` writes `10000` for a whole amount and `10000.5` for a fractional one — so
  `jsonDecode` hands you an **`int`** for most real rows and `x as double` throws. Use
  `(x as num).toDouble()` for every money field, in every module. Verified live:
  `"monthly_contribution": 10000`, `"total_saved": 440000`.
- Timestamps are **ISO-8601** (`2026-08-19T14:22:31+03:00`). Dates are `YYYY-MM-DD`.
- The server never sends a pre-formatted "2 hours ago" string — format in the app so it follows
  the user's language.

---

## 2. Errors

| HTTP | `code` | Means |
|---|---|---|
| 400 | `invalid_json` | Body was not valid JSON |
| 401 | `unauthenticated` | Missing, malformed, expired, or wrong-type token |
| 401 | `invalid_credentials` | Wrong username or password |
| 401 | `invalid_refresh_token` | Refresh token unknown, already rotated, or revoked |
| 403 | `forbidden` | Authenticated, but not allowed to do this |
| 404 | `not_found` | No such record, or unknown endpoint |
| 405 | `method_not_allowed` | Wrong HTTP method |
| 409 | `phone_taken` | That phone belongs to another member |
| 422 | `missing_fields`, `invalid_id`, `slip_required`, `invalid_slip`, `no_fields`, `invalid_settings`, `invalid_status` | Request understood, contents rejected |
| 500 | `server_misconfigured` | `JWT_SECRET` not set on the server — a deployment fault, not the client's |

**401 and 403 mean different things.** 401 = "I don't know who you are" → run the refresh loop.
403 = "I know exactly who you are and the answer is no" → show a message; refreshing will not help.

Unauthenticated:

```json
{
  "status": "error",
  "code": "unauthenticated",
  "message": "A valid access token is required."
}
```

Forbidden — a Member opening another member's record:

```json
{
  "status": "error",
  "code": "forbidden",
  "message": "You may only view your own member record."
}
```

Not found:

```json
{
  "status": "error",
  "code": "not_found",
  "message": "Member not found."
}
```

Wrong method:

```json
{
  "status": "error",
  "code": "method_not_allowed",
  "message": "This endpoint does not accept that HTTP method."
}
```

---

## 3. Auth

### POST `/auth/login`

Public. `Content-Type: application/json`.

| Field | Required | Notes |
|---|---|---|
| `username` | yes | Username **or** email address |
| `password` | yes | |

```json
{
  "status": "success",
  "data": {
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1...",
    "refresh_token": "49198d0c3a589c62...",
    "token_type": "Bearer",
    "expires_in": 3600,
    "user": {
      "user_id": 483,
      "username": "rmollel",
      "full_name": "Rehema Mollel",
      "role_id": 2,
      "role": "Chairperson",
      "language": "sw",
      "member_id": 1
    }
  }
}
```

`expires_in` is seconds (3600 = one hour). Store `access_token` and `refresh_token` in secure
storage, not SharedPreferences.

**Failures.** Wrong password and unknown username return a byte-identical response, so the
endpoint cannot be used to discover which accounts exist. Do not try to tell them apart:

```json
{
  "status": "error",
  "code": "invalid_credentials",
  "message": "Incorrect username/email or password."
}
```

Accounts with status `pending`, `rejected`, `inactive` or `suspended` are refused with their own
`code`, so the app can explain why rather than saying "wrong password".

---

### POST `/auth/refresh`

Public — authorised by the refresh token itself, so send **no** `Authorization` header.

| Field | Required |
|---|---|
| `refresh_token` | yes |

```json
{
  "status": "success",
  "data": {
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1...",
    "refresh_token": "19e437d8be2436cf...",
    "token_type": "Bearer",
    "expires_in": 3600
  }
}
```

**Rotation:** the token you sent is now dead. Overwrite both stored tokens with what comes back.
Presenting a rotated token returns 401 `invalid_refresh_token` — which is also what a stolen
token gets once the real client has refreshed.

---

### POST `/auth/logout`

Requires a valid access token.

| Field | Required | Notes |
|---|---|---|
| `refresh_token` | one of the two | The token to revoke |
| `all_devices` | one of the two | `true` revokes every session — the lost-phone case |

```json
{
  "status": "success",
  "data": {
    "revoked": 1,
    "all_devices": false
  }
}
```

Revoking a token that is already dead, or not yours, is reported as success with `revoked: 0` —
your goal ("that token cannot be used by me") holds either way, and distinguishing them would
confirm whether a guessed token exists.

The **access token is not revoked** and keeps working until it expires, within the hour. Clear
it from storage on logout; do not rely on the server to reject it.

---

### GET `/auth/me`

Requires a valid access token. Call at start-up.

```json
{
  "status": "success",
  "data": {
    "user": {
      "user_id": 483,
      "username": "rmollel",
      "full_name": "Rehema Mollel",
      "email": "rehema.mollel1@example.co.tz",
      "role_id": 2,
      "role": "Chairperson",
      "language": "sw",
      "member_id": 1,
      "is_admin": true
    },
    "permissions": {
      "expenses": {
        "view": true,
        "create": true,
        "edit": true,
        "delete": true,
        "review": true,
        "approve": true
      },
      "dashboard": {
        "view": true,
        "create": true,
        "edit": true,
        "delete": true,
        "review": true,
        "approve": true
      }
    },
    "__note__": "permissions truncated for this document: 31 page keys in the real response"
  }
}
```

`permissions` is keyed by page, each with `view`/`create`/`edit`/`delete`/`review`/`approve`.
It is read fresh from the database on every call, not baked into the token, so a permission
change takes effect on the next request rather than at next login.

**These are EFFECTIVE permissions, not raw role rows.** An admin (`is_admin: true`) receives
every page key with every action `true`, because that is what the server actually does —
`vk_api_can()` short-circuits for an admin and never consults the map. Rendering straight from
`permissions` is therefore correct for every role.

A defensive `is_admin || permissions[page][action]` in the client is harmless and stays correct.

`member_id` is `null` — never `0` — when the account has no member record. `is_leadership` is
true for Admin, Chairperson, Secretary and Treasurer; false for a Member.

---

## 4. Dashboard

### GET `/dashboard`

**Role-aware, and the shape genuinely differs.** Leadership (Admin, Chairperson, Secretary,
Treasurer) receive the group block; an ordinary member does not — the keys are **absent**, not
null. Check with `containsKey`, and drive it off `role.is_leadership`.

| Key | Member | Leadership | Admin only |
|---|---|---|---|
| `role`, `me`, `pending`, `currency`, `generated_at` | ✅ | ✅ | |
| `members`, `contributions`, `expenses`, `balance`, `fines`, `trend` | ❌ | ✅ | |
| `recent_activity` | ❌ | ❌ | ✅ |

`recent_activity` is admin-only: a Secretary is leadership but does not see the audit trail on
the web either.

**As a leader:**

```json
{
  "status": "success",
  "data": {
    "role": {
      "role_id": 2,
      "role": "Chairperson",
      "is_admin": true,
      "is_leadership": true
    },
    "me": {
      "member_id": 1,
      "total_contributions": 1020000,
      "pending_contributions": 0,
      "arrears": {
        "behind": false,
        "amount": 0,
        "months": 0,
        "oldest": null
      }
    },
    "members": {
      "total": 30,
      "active": 30,
      "pending": 0
    },
    "contributions": {
      "total": 18460000,
      "this_month": 1750000,
      "pending_count": 0
    },
    "expenses": {
      "death": 1700000,
      "general": 610000,
      "total": 2310000,
      "approved_not_paid": 1145000
    },
    "balance": {
      "net": 17287000
    },
    "fines": {
      "pending_total": 65000
    },
    "pending": {
      "members": 0,
      "contributions": 0,
      "death_expenses": 0,
      "general_expenses": 1,
      "budgets": 0,
      "total": 1
    },
    "trend": {
      "labels": [
        "Mar 2026",
        "Apr 2026",
        "May 2026",
        "Jun 2026",
        "Jul 2026",
        "Aug 2026"
      ],
      "contributions": [
        1620000,
        1750000,
        1750000,
        1750000,
        1750000,
        1750000
      ],
      "expenses": [
        0,
        0,
        875000,
        120000,
        1120000,
        195000
      ]
    },
    "recent_activity": [
      {
        "id": 301,
        "action": "Login",
        "module": "Auth",
        "description": "Hamisi Mbwana logged into the system",
        "user": "Hamisi Mbwana",
        "role": "Member",
        "created_at": "2026-08-20T16:02:48+03:00"
      },
      {
        "id": 300,
        "action": "Login",
        "module": "Auth",
        "description": "Rehema Mollel logged into the system",
        "user": "Rehema Mollel",
        "role": "Chairperson",
        "created_at": "2026-08-20T16:02:46+03:00"
      },
      {
        "id": 299,
        "action": "Login",
        "module": "Auth",
        "description": "Rehema Mollel logged into the system",
        "user": "Rehema Mollel",
        "role": "Chairperson",
        "created_at": "2026-08-20T15:43:27+03:00"
      },
      {
        "id": 298,
        "action": "Login",
        "module": "Auth",
        "description": "Hamisi Mbwana logged into the system",
        "user": "Hamisi Mbwana",
        "role": "Member",
        "created_at": "2026-08-20T15:43:23+03:00"
      },
      {
        "id": 297,
        "action": "Updated",
        "module": "Group Settings",
        "description": "Updated Group Settings record: System Configuration (meeting_absence_fine)",
        "user": "Rehema Mollel",
        "role": "Chairperson",
        "created_at": "2026-08-20T15:43:13+03:00"
      },
      {
        "id": 296,
        "action": "Login",
        "module": "Auth",
        "description": "Rehema Mollel logged into the system",
        "user": "Rehema Mollel",
        "role": "Chairperson",
        "created_at": "2026-08-20T15:43:12+03:00"
      },
      {
        "id": 295,
        "action": "Updated",
        "module": "Group Settings",
        "description": "Alibadilisha rekodi kwenye Group Settings: System Configuration",
        "user": "Athumani Mhando",
        "role": "Secretary",
        "created_at": "2026-08-20T15:43:11+03:00"
      },
      {
        "id": 294,
        "action": "Login",
        "module": "Auth",
        "description": "Athumani Mhando ameingia kwenye mfumo",
        "user": "Athumani Mhando",
        "role": "Secretary",
        "created_at": "2026-08-20T15:43:10+03:00"
      },
      {
        "id": 293,
        "action": "Updated",
        "module": "Group Settings",
        "description": "Alibadilisha rekodi kwenye Group Settings: System Configuration",
        "user": "Rehema Mollel",
        "role": "Chairperson",
        "created_at": "2026-08-20T15:43:07+03:00"
      },
      {
        "id": 292,
        "action": "Login",
        "module": "Auth",
        "description": "Rehema Mollel ameingia kwenye mfumo",
        "user": "Rehema Mollel",
        "role": "Chairperson",
        "created_at": "2026-08-20T15:43:06+03:00"
      }
    ],
    "currency": "TZS",
    "generated_at": "2026-08-20T16:02:51+03:00"
  }
}
```

**As an ordinary member** — note what is missing:

```json
{
  "status": "success",
  "data": {
    "role": {
      "role_id": 15,
      "role": "Member",
      "is_admin": false,
      "is_leadership": false
    },
    "me": {
      "member_id": 30,
      "total_contributions": 420000,
      "pending_contributions": 0,
      "arrears": {
        "behind": false,
        "amount": 0,
        "months": 0,
        "oldest": null
      }
    },
    "pending": {
      "contributions": 0,
      "total": 0
    },
    "currency": "TZS",
    "generated_at": "2026-08-20T16:02:53+03:00"
  }
}
```

`me.arrears` is the member's own position and is present for **every** role, because a Treasurer
is also a saving member. `behind: false` with `amount: 0` means up to date; it can also mean no
monthly target is set, in which case there is nothing to be behind on.

`trend` arrays are parallel to `trend.labels` and always six entries, oldest first — safe to
feed a chart without padding.

---

## 5. Members

### GET `/members`

Requires the `customers` **view** permission — which an ordinary Member holds. Members can see
who else is in the group.

| Query | Default | Notes |
|---|---|---|
| `page` | 1 | |
| `per_page` | 25 | max 100, clamped |
| `status` | — | `active`, `pending`, `rejected`, `dormant`. Anything else → 422 |
| `search` | — | first name, last name, username or full name |
| `group_id` | — | members of one customer group |

**As leadership:**

```json
{
  "status": "success",
  "data": {
    "members": [
      {
        "member_id": 25,
        "user_id": 507,
        "username": "aminja",
        "full_name": "Ally Minja",
        "first_name": "Ally",
        "last_name": "Minja",
        "gender": "Male",
        "status": "active",
        "role": "Member",
        "joined_at": "2025-08-25T00:00:00+03:00",
        "phone": "+255743817513",
        "email": "ally.minja25@example.co.tz",
        "nida_number": "19750501772320538851",
        "registration_number": null,
        "address": "Tabata, Dar es Salaam",
        "district": "Dar es Salaam",
        "initial_savings": 20000,
        "is_self": false
      }
    ],
    "sensitive_visible": true,
    "pagination": {
      "page": 1,
      "per_page": 2,
      "total": 30,
      "total_pages": 15,
      "has_more": true
    },
    "__note__": "list truncated to 1 of 2 rows for this document"
  }
}
```

**As an ordinary member** — same people, sensitive fields `null`:

```json
{
  "status": "success",
  "data": {
    "members": [
      {
        "member_id": 25,
        "user_id": 507,
        "username": "aminja",
        "full_name": "Ally Minja",
        "first_name": "Ally",
        "last_name": "Minja",
        "gender": "Male",
        "status": "active",
        "role": "Member",
        "joined_at": "2025-08-25T00:00:00+03:00",
        "phone": null,
        "email": null,
        "nida_number": null,
        "registration_number": null,
        "address": null,
        "district": "Dar es Salaam",
        "initial_savings": null,
        "is_self": false
      }
    ],
    "sensitive_visible": false,
    "pagination": {
      "page": 1,
      "per_page": 2,
      "total": 30,
      "total_pages": 15,
      "has_more": true
    },
    "__note__": "list truncated to 1 of 2 rows for this document"
  }
}
```

**`sensitive_visible` tells you which you got.** When it is `false`, a `null` phone means
*hidden from you*, not *not recorded* — say "hidden" in the UI rather than showing a blank.

The caller's **own** row is never masked: find it with `is_self: true` and its real phone is
there.

---

### GET `/members/{id}`

Full profile, grouped into `contact`, `identity`, `location`, `financial`, `next_of_kin`.

**An ordinary member may only open their own record** — anyone else returns **403**, whether or
not that id exists. In the app, only link to a member's detail page when the viewer is
leadership, or when it is their own record.

```json
{
  "status": "success",
  "data": {
    "member": {
      "member_id": 1,
      "user_id": 483,
      "username": "rmollel",
      "full_name": "Rehema Mollel",
      "first_name": "Rehema",
      "middle_name": "",
      "last_name": "Mollel",
      "gender": "Female",
      "marital_status": "Married",
      "dob": "1981-10-03",
      "status": "active",
      "role": "Chairperson",
      "is_deceased": false,
      "joined_at": "2025-10-28T00:00:00+03:00",
      "customer_code": "VIK-0001",
      "registration_number": null,
      "contact": {
        "phone": "+255765376638",
        "mobile": "+255765376638",
        "email": "rehema.mollel1@example.co.tz"
      },
      "identity": {
        "nida_number": "19811003002699580509"
      },
      "location": {
        "address": "Kariakoo, Dar es Salaam",
        "ward": "Kariakoo",
        "district": "Dar es Salaam",
        "city": "Dar es Salaam",
        "country": "Tanzania"
      },
      "financial": {
        "initial_savings": 20000
      },
      "next_of_kin": {
        "name": "Hawa Mwakyusa",
        "relationship": "Parent",
        "phone": "+255774951694"
      },
      "children": []
    },
    "is_self": true,
    "sensitive_visible": true
  }
}
```

---

### GET `/members/dormant`

Members who have gone dormant or are recorded as deceased. Same masking rules as the roster,
plus a `summary` block.

```json
{
  "status": "success",
  "data": {
    "members": [],
    "summary": {
      "total": 0,
      "deceased": 0,
      "other_dormant": 0
    },
    "sensitive_visible": true,
    "pagination": {
      "page": 1,
      "per_page": 25,
      "total": 0,
      "total_pages": 0,
      "has_more": false
    }
  }
}
```

---

### POST `/members`

Register a member. Requires the `customers` **create** permission.

**`multipart/form-data`, not JSON.** The payment slip is mandatory, exactly as on the web —
nobody is registered until the entrance fee is evidenced.

| Field | Required | Notes |
|---|---|---|
| `first_name`, `last_name`, `phone` | yes | |
| `kianzio_slip` | yes | **file** — JPG, PNG, GIF, WEBP or PDF, max 5 MB |
| `middle_name`, `gender`, `dob`, `marital_status`, `nida_number` | no | `dob` is `YYYY-MM-DD` |
| `address`, `ward`, `district`, `city` | no | |
| `next_of_kin_name`, `next_of_kin_relationship`, `next_of_kin_phone` | no | |
| `initial_savings` | no | number |
| `preferred_language` | no | `en` or `sw`, default `sw` |

Returns **201**:

```json
{
  "status": "success",
  "data": {
    "member": {
      "member_id": 33,
      "user_id": 1417,
      "full_name": "Testina Mwakalinga",
      "username": "tmwakalinga",
      "email": "tmwakalinga@vikundi.localhost",
      "phone": "+255700111999",
      "status": "active"
    },
    "initial_password": "tmwakalinga@123",
    "must_change_password": true
  }
}
```

`initial_password` is returned **once**, at creation, so the registering officer can pass it on.
Show it, then prompt for a change — it is the predictable `username@123`.

**The upload is validated by its contents, not its name.** A `.php` renamed to `.png` is
refused. Do not strip or rewrite the extension client-side.

Missing slip:

```json
{
  "status": "error",
  "code": "slip_required",
  "message": "A payment slip (kianzio_slip) must be uploaded to complete registration."
}
```

Other failures: 403 (no permission), 409 `phone_taken`, 422 `missing_fields`, 422 `invalid_slip`
(wrong type, too large, or contents not matching the extension).

---

### PUT `/members/{id}`

Edit a profile. Requires the `customers` **edit** permission — an ordinary Member does not have
it and gets **403**, including on their own record. Self-service profile editing is a separate
module.

Send **only the fields you are changing**; anything omitted is left alone.

Writable: `first_name`, `middle_name`, `last_name`, `email`, `phone`, `mobile`, `gender`,
`marital_status`, `dob`, `nida_number`, `address`, `city`, `state`, `district`, `ward`, `street`,
`house_number`, `country`, `postal_code`, `mpesa_name`, `mpesa_number`, `next_of_kin_*`, `nok_*`.

```json
{
  "status": "success",
  "data": {
    "member_id": 33,
    "full_name": "Testina Mwakalinga",
    "updated_fields": [
      "ward",
      "next_of_kin_name"
    ]
  }
}
```

**`updated_fields` is what the server actually accepted.** Anything not on the whitelist —
`user_id`, `status`, `is_deceased` — is **silently ignored**, not rejected. Read this array back
rather than assuming your payload applied.

Use **POST** instead of PUT if you ever need to send a file: PHP does not populate a multipart
body on PUT.

---

### POST `/members/{id}/approve` · `/reject` · `/reactivate`

Admins (including the Chairperson) and the Secretary. Empty JSON body.

| Endpoint | Sets status to |
|---|---|
| `approve` | `active` |
| `reject` | `rejected` |
| `reactivate` | `active` |

```json
{
  "status": "success",
  "data": {
    "member_id": 33,
    "status": "active",
    "changed": false
  }
}
```

**Safe to retry.** If the member is already in that state you get 200 with `changed: false`
rather than an error — so a retry after a dropped connection is not a failure:

```json
{
  "status": "success",
  "data": {
    "member_id": 33,
    "status": "active",
    "changed": false
  }
}
```

---

## 6. Group settings

### GET `/group-settings`

Any signed-in user gets the top four blocks — the app needs the group name, logo and currency
to render its own chrome, and the monthly target and absence fine are the rules a member is
personally held to.

`settings` is the **edit form's pre-fill**, so it is returned only to those who may submit that
form: admins (including the Chairperson) and the Secretary. For everyone else it is `null`.
`can_edit` tells you which case you are in — branch on it rather than on `settings == null`.

```json
{
  "status": "success",
  "data": {
    "group": {
      "name": "Umoja VICOBA Group",
      "logo": "",
      "org_type": "vicoba",
      "currency": "TZS"
    },
    "contributions": { "monthly_target": null },
    "fines":         { "meeting_absence": 2000 },
    "leadership_positions": ["Chairperson / Mwenyekiti", "..."],

    "can_edit": true,
    "settings": {
      "group_name": "Umoja VICOBA Group",
      "group_email": "",
      "group_phone": "",
      "group_physical_address": "",
      "group_postal_address": "",
      "group_registration_number": "",
      "currency": "TZS",
      "meeting_day": "Jumatatu",
      "cycle_type": "monthly",
      "monthly_contribution": null,
      "entrance_fee": 20000,
      "meeting_absence_fine": 2000,
      "fine_late_meeting": null,
      "fine_late_contribution": null,
      "fine_absent_meeting": null,
      "max_members": 30,
      "contribution_grace_days": null,
      "deadline_day": 15,
      "auto_termination": "off"
    }
  }
}
```

**Every key in `settings` is a key `PUT` accepts, spelled identically.** Read it, edit it, send
back the changed subset — no name mapping. The two lists come from one definition
(`includes/api_group_settings.php`), so they cannot drift apart.

The older `contributions.monthly_target` and `fines.meeting_absence` are unchanged and still
correct; they are the same values as `settings.monthly_contribution` and
`settings.meeting_absence_fine`, published to callers who cannot see `settings`.

#### Dart types

| Field | Dart | Notes |
|---|---|---|
| `group_name` | `String` | never empty — PUT refuses a blank |
| `group_email`, `group_phone`, `group_physical_address`, `group_postal_address`, `group_registration_number`, `currency` | `String` | `""` when unset, never `null` |
| `meeting_day` | `String?` | a **Swahili** day name — see below |
| `cycle_type` | `String` | `"monthly"` or `"weekly"` |
| `monthly_contribution`, `entrance_fee`, `meeting_absence_fine`, `fine_late_meeting`, `fine_late_contribution`, `fine_absent_meeting` | `num?` | **`num`, not `double`** — see §1 |
| `max_members`, `contribution_grace_days` | `int?` | |
| `deadline_day` | `int` **or** `String?` | depends on `cycle_type` — see below |
| `auto_termination` | `String` | `"on"` or `"off"` |

`null` on a number means **not set**, which is a different state from `0`. For
`monthly_contribution` it means the group has no monthly target and arrears are not calculated
at all; `0` would mean the target is nothing and put every member permanently in credit. Never
render `null` as 0, and never send `0` when you meant to clear — send `""`.

Defaults are applied server-side to match the web form, so the app and the web page open on the
same numbers: `currency` `TZS`, `cycle_type` `monthly`, `max_members` `30`, `deadline_day` `15`,
`auto_termination` `off`.

#### `meeting_day` and `deadline_day`

The web form stores **Swahili** day names regardless of display language, so those are the
canonical values:

| English | Stored |
|---|---|
| Monday | `Jumatatu` |
| Tuesday | `Jumanne` |
| Wednesday | `Jumatano` |
| Thursday | `Alhamisi` |
| Friday | `Ijumaa` |
| Saturday | `Jumamosi` |
| Sunday | `Jumapili` |

Translate for display; store what you were given. PUT also accepts the English name and
normalises it, so `"Monday"` is safe to send — but GET always returns the Swahili form.

`deadline_day` carries **two different types in one key**, decided by `cycle_type`:

- `cycle_type: "monthly"` → an `int` day of the month, 1–31
- `cycle_type: "weekly"` → a `String` day name, as above

So decode it as `dynamic` and branch on `cycle_type`. Do not parse it as an int
unconditionally — `int.tryParse("Jumatatu")` is `null`, and coercing it to `0` is exactly the
corruption the server now refuses (see PUT below).

---

### PUT `/group-settings`

Admins (including the Chairperson) and the Secretary. Everyone else gets **403**, including the
Treasurer. This is the same rule that decides `can_edit` on GET, so a screen you were allowed
to pre-fill is a screen you are allowed to submit.

Send only what you are changing — a key you omit is left alone.

| Field | Accepts |
|---|---|
| `group_name` | text, cannot be empty |
| `group_email`, `group_phone`, `group_physical_address`, `group_postal_address`, `group_registration_number`, `currency` | text |
| `meeting_day`, `deadline_day` | 1–31, or a day name (Swahili or English) |
| `cycle_type` | `monthly` or `weekly` |
| `monthly_contribution`, `entrance_fee`, `meeting_absence_fine`, `fine_late_meeting`, `fine_late_contribution`, `fine_absent_meeting` | number ≥ 0, or `""` to clear |
| `max_members`, `contribution_grace_days` | integer ≥ 0, or `""` to clear |
| `auto_termination` | `on` or `off` |

```json
{
  "status": "success",
  "data": { "updated": ["meeting_absence_fine"], "count": 1 }
}
```

Sending `""` for a numeric field **clears** it rather than setting 0. For
`monthly_contribution` that means "no monthly target", which switches arrears off — not a
target of zero.

A day outside 1–31, or a name that is not one of the seven, is now **refused** rather than
stored:

```json
{
  "status": "error",
  "code": "invalid_settings",
  "message": "deadline_day must be a day of the month from 1 to 31, or a day name."
}
```

> **Round-trip safety.** Until this change `deadline_day` was validated as an integer, so a
> weekly group's stored `"Jumatatu"` became `"0"` on the way back in. A pre-filled edit form
> would have committed that on every save without anyone touching the field. It is now typed as
> a day and preserved. This is the one field where blindly echoing GET back to PUT used to
> destroy data, and it is fixed on the server — no client workaround needed.

Keys outside the list are ignored; if nothing writable was sent you get 422 `no_fields`.

Not writable through the API: the loan and share-out parameters (web only, because a value
nobody can see on the device is a value nobody can check before saving), and the operational
keys `auto_termination_last_run` and `group_balance`.

---

## 7. Contributions

The money module. Eight endpoints covering three screens: a member's own standing,
the leadership ledger, and the approval workflow.

### Who sees whose money

One rule decides everything here, and it is not the same as the members roster.
The roster is shared — you can see who else is in your group. **Savings are not.**

```
leader  =  role_id in (1, 2, 12)  OR  permissions['manage_contributions'].edit
```

A leader sees the whole group. Anyone else is **pinned to their own member record**,
server-side. Passing `?member_id=4` as a Member does not widen anything — the
parameter is overwritten, not validated — and the response tells you what actually
happened:

```json
"scope": { "is_leader": false, "member_id": 30, "own_member_id": 30 }
```

Render from `scope`, never from what you asked for. For a leader viewing the whole group,
`member_id` is `null`; for the system Admin, `own_member_id` is `null` too — **both fields
are `int?` in Dart.**

Verified live on the demo site as `hmbwana1`: `?member_id=8` and `?member_id=1` both
returned only member 30's own nine rows.

An account with **no member record** (the system Admin) gets `403 no_member_record`
on the member-scoped endpoints. That is not an error to retry — it means "this
account has no savings of its own". Show the group view instead.

### The approval workflow

```
pending ──review──▶ reviewed ──approve──▶ approved
   │                   │
   └────── cancel ─────┘                (approved cannot be cancelled)
```

Every new contribution is `pending`. **You cannot post a status** — the field is
ignored. Only an `approved` contribution counts toward savings.

Each row carries what *this* caller may do to it, so you never re-derive the rules:

```json
"actions": { "review": true, "approve": false, "cancel": true }
```

Bind buttons to `actions`. A transition the server would refuse returns `409
invalid_status_transition` with a message naming the current status.

---

### GET `/contributions`

The ledger. Paginated, filtered, scoped as above.

**Query:** `page`, `per_page` (max 100), `member_id` (leaders only), `status`,
`type`, `date_from`, `date_to` (both `YYYY-MM-DD`), `search`.

```json
{
  "status": "success",
  "data": {
    "contributions": [
      {
        "contribution_id": 2150,
        "member_id": 3,
        "member_name": "Neema Joseph Mushi",
        "amount": 12500.0,
        "type": "monthly",
        "status": "approved",
        "date": "2026-08-26",
        "description": "August savings",
        "receipt_number": null,
        "account": "Cash",
        "evidence_url": "uploads/contributions/receipt_1787738939_d277.png",
        "is_opening": false,
        "counts_toward_savings": true,
        "created_at": "2026-08-26T13:04:23+00:00",
        "reviewed_at": "2026-08-26T13:04:38+00:00",
        "approved_at": "2026-08-26T13:04:38+00:00",
        "actions": { "review": false, "approve": false, "cancel": false }
      }
    ],
    "scope":  { "is_leader": false, "member_id": 30, "own_member_id": 30 },
    "totals": { "filtered_amount": 420000.0, "filtered_count": 9 },
    "pagination": { "page": 1, "per_page": 25, "total": 9, "total_pages": 1, "has_more": false }
  }
}
```

`totals` reflects the **filters, not the page** — use it for "TZS 300,000 across 6
records" without paging the whole set.

`counts_toward_savings` is computed server-side. Do not add up `amount` yourself:
`fine` and `agm` rows and anything not approved are excluded from savings, so your
sum would disagree with the member's statement.

`is_opening` marks money carried in from M-Koba — an opening balance, not a fresh
payment. Worth labelling differently in the UI.

`evidence_url` is **relative to the site root**, not the API base. Build it as
`https://<host>/<evidence_url>`.

---

### GET `/contributions/{id}`

One contribution plus its approval trail — who reviewed, who approved, when. This
is what a member disputing a figure actually asks for.

```json
{
  "status": "success",
  "data": {
    "contribution": { "...as above..." },
    "trail": {
      "created":  { "by": "Neema Joseph Mushi", "role": "",          "at": "2026-08-26T13:04:23+00:00", "signed": false, "completed": true },
      "reviewed": { "by": "Juma Hassan Mwakyusa", "role": "Treasurer", "at": "2026-08-26T13:04:38+00:00", "signed": false, "completed": true },
      "approved": { "by": "Juma Hassan Mwakyusa", "role": "Treasurer", "at": "2026-08-26T13:04:38+00:00", "signed": false, "completed": true }
    }
  }
}
```

`completed` is the flag to drive a stepper. `signed` says whether an e-signature
image was on file — the signature image itself is never returned.

A row belonging to someone else returns **404, not 403**, so ids cannot be probed.

---

### POST `/contributions`

Record a contribution. Accepts **JSON or multipart** — multipart only when
attaching evidence.

```json
{
  "member_id": 3,
  "amount": 12500,
  "type": "monthly",
  "date": "2026-08-26",
  "description": "August savings",
  "receipt_number": "MK-0099",
  "account": "Cash"
}
```

| Field | Required | Notes |
|---|---|---|
| `member_id` | leaders only | Ignored otherwise — you always file your own |
| `amount` | ✅ | > 0 |
| `type` | | `entrance` · `monthly` · `agm` · `fine` · `other` — default `monthly` |
| `date` | | `YYYY-MM-DD`, defaults to today, **cannot be in the future** |
| `description` | | |
| `receipt_number` | | max 100 chars |
| `account` | | `M-Koba` · `Bank` · `Cash` · `Mobile Money` |
| `evidence` | | multipart file — JPG, PNG, GIF, WEBP, PDF, max 5 MB |

**Any signed-in member may file their own contribution.** That is the normal case
in a savings group. `permissions['manage_contributions'].create` is what allows
filing against *someone else*.

Returns **201** with the created row. Status is always `pending`.

Refusals are `422` with a distinct code: `invalid_amount`, `invalid_type`,
`invalid_date`, `invalid_account`, `invalid_upload`, `member_not_found`.

Evidence is validated by **content**, not filename — a PHP file renamed `.png` is
rejected with `The file contents do not match its extension.`

---

### POST `/contributions/{id}/review` · `/approve` · `/cancel`

| Endpoint | From | To | Needs |
|---|---|---|---|
| `/review` | `pending` | `reviewed` | `view` + `review` |
| `/approve` | `reviewed` | `approved` | `view` + `approve` |
| `/cancel` | `pending`, `reviewed` | `cancelled` | `edit` |

No body. All return the updated row.

`/approve` may include `sig_warning` when the approver has no e-signature on file.
The approval still succeeded — show it as a note, not a failure.

Wrong-order calls return `409`:

```json
{ "status": "error", "code": "invalid_status_transition",
  "message": "A contribution that is pending cannot be approved. Expected: reviewed." }
```

There is **no DELETE**. A contribution that existed and was withdrawn stays in the
audit trail.

---

### GET `/contributions/standing`

**The member screen.** "Am I up to date?"

`?member_id=` is honoured for leaders only. An account with no member record must
pass one, or gets `422 member_required`.

```json
{
  "status": "success",
  "data": {
    "member": { "member_id": 30, "full_name": "Hamisi Mbwana", "is_self": true },
    "group":  { "currency": "TZS", "monthly_contribution": 10000.0, "has_target": true },
    "entrance": { "amount": 20000.0, "paid": 20000.0, "status": "paid" },
    "standing": {
      "opening": 0.0,
      "new": 440000.0,
      "total_saved": 440000.0,
      "expected": 80000.0,
      "surplus_deficit": 360000.0,
      "status": "ahead"
    },
    "arrears": { "behind": false, "amount": 0.0, "months": 0, "oldest_month": null },
    "months": [
      { "month": "2026-08", "label": "Aug 2026", "target": 10000.0, "paid": 10000.0, "shortfall": 0.0, "status": "paid" }
    ],
    "year_summary": { "years": { "2026": { "target": 80000, "actual": 420000, "variance": 340000 } },
                      "total": { "target": 80000, "actual": 420000, "variance": 340000, "unallocated": 0, "paid": 420000 } }
  }
}
```

> **`standing.total_saved` and `year_summary.total.paid` are not the same number**, and
> that is correct. Above: 440,000 vs 420,000. The 20,000 gap is the **entrance fee** — it
> is real savings, so it is in `total_saved`, but it is not a monthly payment, so the
> month calendar never allocates it. That is why `entrance` is returned separately. If
> both figures appear on one screen, label them ("Total saved" vs "Allocated to months")
> or show only `total_saved`.

**`has_target` is the switch the whole screen hangs on.** The group may not have set
a monthly amount. When it is `false`: nothing is expected, nobody is behind,
`expected` is `0`, `status` is always `ontrack`, and every month reads `no_target`.
**Do not draw a progress bar or an arrears warning** — the member is simply saving
what they can. Getting this wrong tells a member in good standing that they owe money.

`status`: `ahead` · `behind` · `ontrack`. `months` is newest-first and contains only
months that were actually due — a member who joined in June is not shown January.

Month `status`: `paid` · `partial` · `unpaid` · `no_target`.

---

### GET `/contributions/summary`

**The leadership screen.** `403 forbidden` for anyone else — every figure here is
about other people. A member's equivalent is `/contributions/standing`.

```json
{
  "status": "success",
  "data": {
    "currency": "TZS",
    "group": { "monthly_contribution": 10000.0, "has_target": true },
    "totals": {
      "all_time": 18460000.0,
      "expected_to_date": 3320000.0,
      "this_month": { "month": "2026-08", "amount": 1750000.0, "count": 30 }
    },
    "awaiting_action": {
      "pending_review":   { "count": 0, "amount": 0.0 },
      "pending_approval": { "count": 0, "amount": 0.0 }
    },
    "members": {
      "total": 30, "behind": 0, "ahead": 30,
      "total_deficit": 0.0,
      "collection_rate": 100.0
    }
  }
}
```

`collection_rate` is **`null`** when the group has no target — guard it, or the
dashboard shows `NaN%`.

`awaiting_action` is split by which action is waiting, so the two queues can be two
separate cards.

> **Known divergence — do not "fix" this in the app.**
> `summary.members.behind` and `standing.status` are anchored differently in the
> server's shared standing module: the group figure counts from a member's first
> contribution, the member figure from their join date. For members imported from
> M-Koba the two can disagree about the same person. This shows on the web today
> too. It is a server-side change that moves every savings figure on every report,
> so it is being handled separately. If a member asks why the two disagree, that is
> why — do not paper over it client-side.

---

## 8. Demo logins

All on the demo site, password `Demo@2026`:

| Role | Username | Use it to see |
|---|---|---|
| Admin | `admin` | Everything, including the audit trail |
| Chairperson | `rmollel` | Full leadership view |
| Secretary | `amhando` | Leadership without the audit trail |
| Treasurer | `hmtui` | Leadership; **cannot** edit group settings |
| Member | `hmbwana1` | The masked, restricted view |

Any other seeded member is `username` + `@123` (e.g. `jmtui` / `jmtui@123`).

Test with **at least** the Chairperson and the Member. Most role bugs only appear from the
member side, because that is the only role where fields are removed rather than added.

---

## Status

| Module | Endpoints | State |
|---|---|---|
| 1. Auth | 4 | ✅ live |
| 2. Dashboard | 1 | ✅ live |
| 3. Members | 8 | ✅ live |
| — Group settings | 2 | ✅ live |
| 4. Contributions | 8 | ✅ live |
| 5. Transactions | — | in progress |
| 6. Fines | — | queued |

**23 endpoints live** on both `vikundi.bjptechnologies.co.tz` and
`demo.vikundi.bjptechnologies.co.tz`.

Shipped shapes are treated as a contract: if a field has to change, you will be told before it
deploys. This file is updated with every module.

See `docs/handover/README.md` for the short version — what is live, the four rules that apply
to every module, and the test accounts.
