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
8. [Transactions](#8-transactions)
9. [Fines](#9-fines)
10. [Condolences](#10-condolences)
11. [Financial Ledger & Reconciliation](#11-financial-ledger--reconciliation)
12. [Expenses & Petty Cash](#12-expenses--petty-cash)
13. [Demo logins](#13-demo-logins)

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
      "logo": "group_logo_1775154296.png",
      "logo_url": "https://demo.vikundi.bjptechnologies.co.tz/assets/images/group_logo_1775154296.png",
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

#### The logo

`logo` is the **raw stored filename**, not a URL — that is what `group_settings.group_logo`
holds and what the web pages and TCPDF printouts read. Use **`logo_url`**, which is absolute and
already has the default applied, so the app and the site show the same image.

`logo_url` is never null: a group that has never uploaded one gets the default
(`/assets/images/logo1.png`), the same fallback the web uses.

---

### POST `/group-settings/logo`

Replace the group's logo. **Multipart only**, field name `logo`. Same permission as
`PUT /group-settings` — admins (including the Chairperson) and the Secretary; everyone else gets
403.

It is a separate endpoint because `PUT /group-settings` is JSON: you should not have to switch
the whole settings form to multipart to save a phone number.

| | |
|---|---|
| Field | `logo` (multipart file) |
| Accepts | JPG, PNG, GIF, WEBP |
| Refused | PDF (cannot render in an `<img>`), SVG (script-carrying on a served origin), anything whose bytes disagree with its extension |
| Max size | 2 MB |

```json
{
  "status": "success",
  "data": {
    "logo": "group_logo_1756377600_9f3a2b1c8d4e5f60.png",
    "logo_url": "https://demo.vikundi.bjptechnologies.co.tz/assets/images/group_logo_1756377600_9f3a2b1c8d4e5f60.png",
    "message": "The group logo was updated."
  }
}
```

Errors: `no_file` (422) when the part is missing, `invalid_logo` (422) for a bad type, oversize,
or a byte/extension mismatch, `forbidden` (403) for anyone who cannot edit settings.

The previous logo file is left on disk — already-issued PDFs reference it by name.

---

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

## 8. Transactions

The same `contributions` table as §7, read a different way.

| | Answers | One 100,000 payment in January |
|---|---|---|
| §7 `/contributions` | money by the month it **covers** | five covered months |
| §8 `/transactions` | money by the date it **arrived** | one January event of 100,000 |

Year totals legitimately differ between the two — money received in 2026 can cover months in
2027. **The grand totals must agree**, and do.

---

### GET `/transactions`

The group ledger. **Leadership only — 403 for everyone else**, including a plain member. This is
not narrowed to your own rows; your own receipts are a different document at `/my/transactions`,
and the 403 body says so.

```json
{"status":"error","code":"forbidden",
 "message":"Group financial records are available to leadership only. Your own transactions are at /api/v1/my/transactions."}
```

Query: `page`, `per_page` (max 100), `member_id`, `status`, `type`, **`account`**, `date_from`,
`date_to`, `search`.

`search` covers member name, receipt number, **M-Koba trans id and S/No** — what someone holding
a paper statement actually types. `account` is one of `M-Koba`, `Bank`, `Cash`, `Mobile Money`;
anything else is 422 `invalid_account`. An unparseable date is 422 `invalid_date` rather than a
silently ignored filter.

Every row is a §7 contribution row — same `amount`, `status`, `is_opening`,
`counts_toward_savings`, `actions` — **plus** the M-Koba statement block, which `/contributions`
does not carry:

```json
"mkoba": {
  "sno": "12", "trans_id": "DBS2N6S4DVM", "member_id_str": "0783459353",
  "source": "Hawa Mtui", "destination": "UKUU Msakuzi", "trans_type": "Deposit"
}
```

Every field is `String?` and is **`null`, not `""`**, when the row was recorded in Vikundi rather
than imported from M-Koba. Render null as absent — an empty string looks like data.

`totals.filtered_amount` and `filtered_count` describe the whole filtered set, not the page, so
you can head the screen without paging everything to add it up.

---

### GET `/my/transactions`

The signed-in member's own receipts, month grid and year summary. `?member_id=` is honoured for
leadership only; for anyone else it is **silently overwritten** with their own — asking for
someone else's id returns your own record, with no error.

An Admin has no member record, so it must name one: 422 `member_required`.

```json
{
  "member": {"member_id": 30, "full_name": "Hamisi Mbwana", "is_self": true},
  "group":  {"currency": "TZS", "monthly_contribution": 10000, "has_target": true},
  "receipts": [
    {"date": "2026-08-04", "amount": 50000, "type": "monthly",
     "receipt_number": null, "description": "Mchango wa mwezi",
     "account": null, "is_opening": false, "mkoba_trans_id": null}
  ],
  "months": [{"month": "2026-08", "target": 10000, "received": 50000, "status": "received"}],
  "totals": {
    "opening_brought_forward": 20000,
    "receipts_total": 420000,
    "received_total": 440000,
    "receipt_count": 9
  }
}
```

**Read `totals` carefully — three figures, not one.**

`opening_brought_forward` is `customers.initial_savings`: money the member carried in when they
were registered. It **has no date**, so it appears in no receipt and in no month — exactly as a
bank statement shows a balance brought forward. `receipts_total` is the dated receipts.
`received_total` is the sum.

> **`received_total` equals `/contributions/standing`'s `standing.total_saved` for the same
> member.** If you show a different number on the two screens, the group will notice — checking
> that two statements agree is the first thing anyone does with them. Verified live: member 30 is
> 20,000 + 420,000 = 440,000 on both.
>
> Do **not** compute the total by summing `receipts` yourself. You will be short by
> `opening_brought_forward` for every member who carried savings in, and it will look right in
> testing because members with nothing carried in reconcile fine.

`months[].status` is `received` | `none` | `before_join` | `future`. Padding cells with no money
are already dropped.

**This endpoint shows only money that counts** — approved/confirmed, savings types. A
contribution submitted this morning is not here; it is on `/contributions` with
`status: "pending"`. That is the statement's definition, not a gap. Fines are *not* included —
they are transactions but not savings, and they get their own module.

---

## 9. Fines

**Fines are more open than contributions, on purpose.** Any member can see every fine in the
group through `?view=all` — the group asked for this, and it is the same disclosure the Group
Financial Ledger already makes. Do not build a stricter screen than the API allows; showing the
group its own fines is the point.

**Writing is leadership only.** Recording needs `create` on `manage_fines`; editing, paying and
waiving need `edit`. Every row carries `actions` — render buttons from it, never from the role.

`status` is `pending` | `paid` | `waived`. **Only `pending` is money still owed**, and
`is_outstanding` says so per row. Do not sum every row: you would tell a member they owe what
they have already paid or what was forgiven. `totals.outstanding` is the figure to show.

---

### GET `/fines`

The leadership list. 403 for anyone else — a member's own fines are at `/my/fines`.

Query: `page`, `per_page` (max 100), `member_id`, `status`, `date_from`, `date_to`, `search`
(member name or reason).

```json
{
  "fines": [{
    "fine_id": 4, "member_id": 15, "member_name": "Rehema Ngowi", "is_self": false,
    "amount": 20000, "reason": "Kutohudhuria (absence)",
    "status": "paid", "is_outstanding": false,
    "meeting_id": null, "meeting_title": null,
    "created_at": "2026-08-06T00:00:00+03:00", "updated_at": "2026-08-19T17:38:43+03:00",
    "actions": {"edit": true, "pay": false, "waive": true}
  }],
  "totals": {"outstanding": 65000, "paid": 60000, "waived": 0, "count": 8},
  "pagination": {"page": 1, "per_page": 25, "total": 8, "total_pages": 1, "has_more": false}
}
```

`amount` is `num` (whole shillings come back as `int` — see §1). `reason`, `meeting_id` and
`meeting_title` are nullable. `totals` covers the whole filtered set, not the page.

---

### GET `/my/fines`

The member's own fines. **The member comes from the token — there is no `member_id` parameter.**

`?view=all` switches to every fine in the group, paginated. `view` is echoed back so you can
render the toggle. **`mine` is the default**: anything other than an explicit `view=all` scopes
to the member.

```json
{
  "fines": [ ... ],
  "view": "all",
  "scope": {"own_member_id": 30, "is_leader": false},
  "totals": {"outstanding": 65000, "paid": 60000, "waived": 2000, "count": 9, "fined_members": 8},
  "pagination": { ... }
}
```

In the group view, `is_self` marks the reader's own rows — highlight them, as the web page does.
`fined_members` (how many different people are fined) is `null` in the `mine` view.

An account with no member record gets 403 `no_member_record` on `view=mine`, and is told to use
`view=all`.

---

### GET `/fines/{id}`

One fine. Readable by any signed-in user, because `?view=all` already lists it — refusing a
single row someone can see in a list would be theatre.

---

### POST `/fines`

Record a fine. `create` on `manage_fines`.

| Field | |
|---|---|
| `member_id` | required; 404 `member_not_found` if unknown |
| `amount` | required, > 0. **Thousands separators are accepted** — `"1,500"` stores 1500 |
| `reason` | **required** — 422 `reason_required` on a blank |
| `status` | optional, `pending` (default) or `paid` only |
| `meeting_id` | optional; 404 `meeting_not_found` if unknown |

A fine **cannot be created already waived** — forgiving something never owed is not a state the
group has a word for. Returns 201 with the created row.

---

### POST `/fines/{id}/pay` · `/waive`

Mark a fine paid, or forgive it. `edit` on `manage_fines`. No body.

Repeating a transition is **409** (`already_paid` / `already_waived`), not a silent success — a
second audit entry would record the treasurer doing something they did not do. Check
`actions.pay` / `actions.waive` before offering the button and you will not hit it.

---

### PUT `/fines/{id}`

Edit `amount`, `reason` and/or `status`. `edit` on `manage_fines`. Send only what changes; 422
`no_fields` if you send nothing. A blank `reason` is refused, and an unrecognised `status` is
**422, never silently coerced** — a typo must not reopen a settled fine.

---

## 10. Condolences

The condolences (Rambirambi) module — recording assistance for a bereaved member, the
review→approve workflow, and the sustainability report. Reference implementation:
`includes/api_death_expenses.php`.

**Leadership only, differently from every other module so far.** Unlike `/contributions`, no web
screen here ever showed a member their own cases the way `manage_contributions.php` does — so
`/condolences` is a hard 403 for anyone but leadership, and a member's own cases live at a
separate endpoint, `/my/condolences`.

---

### GET `/condolences`

The group's condolence cases. **403 for anyone but leadership**, naming where to go instead:

```json
{"status":"error","code":"forbidden",
 "message":"You do not have permission to view the group's condolence records. Your own condolence records are at /api/v1/my/condolences."}
```

Query: `page`, `per_page` (max 100), `member_id`, `status`, `date_from`, `date_to`, `search`
(member or deceased name).

```json
{
  "condolences": [{
    "id": 2, "member_id": 1, "member_name": "Rehema Mollel", "is_self": false,
    "deceased": {"type": "child", "id": null, "name": "Furaha Temba", "relationship": "Brother"},
    "amount": 900000, "description": "Msaada wa msiba (welfare/funeral support)",
    "status": "approved", "expense_date": "2026-07-25",
    "created_at": "2026-07-25T00:00:00+03:00", "reviewed_at": null, "approved_at": null,
    "actions": {"review": false, "approve": false}
  }],
  "totals": {"filtered_amount": 1700000, "filtered_count": 2},
  "pagination": {"page": 1, "per_page": 25, "total": 2, "total_pages": 1, "has_more": false}
}
```

`deceased` is nested rather than four flat fields — the four only ever mean something together.
`status` is `pending` | `reviewed` | `approved`, plus `rejected` | `inactive` | `paid` which exist
on the column but no code path writes: don't build UI for transitions the server cannot perform.

---

### GET `/my/condolences`

The signed-in member's own condolence cases. **The member comes from the token — there is no
`member_id` parameter.** An account with no member record (the Admin) gets 403
`no_member_record`.

Same shape as the group list, minus `member_id` as a query filter (there is only one member here).
This is `death_expenses.view`'s first legitimate use — the Member role has always held that grant,
and no web screen ever exercised it until this endpoint.

---

### GET `/condolences/{id}`

One case, with its approval trail (same shape as `/contributions/{id}`'s `trail`). **Ownership is
re-checked on the loaded row** — a member reading someone else's case id gets **404**, not the
data, matching `includes/death_expense_access.php`'s web-side fix.

```json
{
  "condolence": { ... },
  "trail": {
    "created":  {"by": "Admin Admin", "role": "", "at": "2026-07-25T00:00:00+03:00", "signed": false, "completed": true},
    "reviewed": {"by": "", "role": "", "at": null, "signed": false, "completed": false},
    "approved": {"by": "", "role": "", "at": null, "signed": false, "completed": false}
  }
}
```

A seeded case with no workflow signature reads exactly like this — `created` falls back to the
row's own columns; `reviewed`/`approved` show incomplete rather than fabricating a signer.

---

### POST `/condolences`

Record assistance for a bereaved member. Leadership only (`create`).

| Field | |
|---|---|
| `member_id` | required — 404 `member_not_found` if unknown |
| `deceased_name` | **required** — 422 `deceased_name_required` |
| `deceased_type`, `deceased_id`, `deceased_relationship` | optional; see below |
| `amount` | required, > 0. Thousands separators accepted (`"1,500"` → 1500) |
| `description`, `expense_date` | optional; `expense_date` defaults to today |

**`deceased_id` decides what approval does to the customers table** — it is not free text to the
server, even though the value itself is a string the web's picker sends:

| `deceased_id` | On approval |
|---|---|
| `"member"` (or `deceased_type: "mwanachama"`) | the member's own account is marked deceased **and dormant** |
| `"spouse"` / `"father"` / `"mother"` | that one family field is cleared |
| `"child_N"` | that child (by index) is flagged deceased in `children_data`, not removed |
| anything else | no side effect — a dependant outside the tracked family fields |

Attachments are **not accepted here**. The web files a death certificate into the shared document
library; that integration is out of scope until a screen asks for it. Attach a certificate from
the web if one is needed.

No status field — every case is created `pending`.

---

### POST `/condolences/{id}/review` · `/approve`

The workflow, same shape as contributions: `pending` → `reviewed` → `approved`, row-locked,
signature-captured. `review` needs the `review` grant, `approve` needs `approve` — both also need
`view`, matching `core/permissions.php`'s `canReview()`/`canApprove()`.

**`approve` additionally checks the group's real fund balance** — a condolence payout is money
*leaving* the group, unlike a contribution:

```json
{"status":"error","code":"insufficient_funds",
 "message":"The group fund balance (TZS 2,302,878.00) is not enough to approve this case (TZS 999,999,999.00)."}
```

On success, `approve` also **applies the customers-table side effect described above** — this is
not optional and cannot be skipped by the client. Like `/contributions/{id}/approve`, a missing
e-signature is a warning, not a refusal:

```json
{"condolence": {...}, "message": "Condolence case approved.",
 "sig_warning": "No e-signature on file — the approval was recorded without a signature image."}
```

Repeating either transition, or approving a case still `pending`, is **409**
`invalid_status_transition`.

---

### GET `/reports/death-analysis`

The condolences sustainability report: for every member who has received *paid* assistance
(`status IN ('approved', 'paid')`), their lifetime contributions against what the group has paid
them, and the net effect on the fund.

**Gated on `vicoba_reports`, not `death_expenses`** — a separate permission from the rest of this
module, matching `app/constant/reports/death_analysis.php` exactly.

> **On demo, `vicoba_reports.view` is granted to Member.** This is not a bug in the API — the web
> report is equally open to an ordinary member today, verified live. If that grant is broader than
> intended, it is a permission-table decision, not something to special-case in a client.

```json
{
  "summary": {
    "total_condolences_paid": 1700000, "total_contributed": 1440000,
    "net_fund_impact": 260000, "case_count": 2
  },
  "recipients": [{
    "member_id": 1, "member_name": "Rehema Mollel", "latest_date": "2026-07-25",
    "cases_count": 1, "total_contributed": 1020000, "benefit_paid": 900000,
    "variance": 120000, "member_status": "active"
  }]
}
```

`net_fund_impact` is positive when the fund is net drained by condolence assistance across these
members, negative when their contributions exceed what they were paid. `member_status` is
`deceased` | `active` | `dormant` — deceased outranks the raw status column, matching the web's
badge logic.

---

## 11. Financial Ledger & Reconciliation

Two things kept separate: the group's per-member financial standing (`/ledger`), and the M-Koba
statement tie-out (`/mkoba-reconciliation`, `/my/mkoba-reconciliation`). Reference implementations:
`includes/api_financial_ledger.php`, `includes/api_mkoba_reconciliation.php`.

**Gated on the same permission keys the web pages already use — `vicoba_reports` for `/ledger`,
`mkoba_reconciliation` for the other two — not a new, mobile-only rule.**

> **On demo and production today, Member already holds `view` on both keys**, verified against the
> web pages directly (`financial_ledger.php`, `mkoba_reconciliation.php` both render fully for a
> plain member, no "Access Denied"), the same permission-table fact already noted in §10 for
> `vicoba_reports`/death-analysis. Unlike that report, these two pages are **row-level**, not an
> aggregate: `/ledger` returns every member's individual contribution amounts and standing in one
> call, and `/mkoba-reconciliation` returns every member's name, amount and receipt number from the
> imported statement. If a member seeing this on their phone is not intended, it is a permission-table
> change for the group to make — `role_permissions` for `vicoba_reports` / `mkoba_reconciliation` on
> the Member role — not something to special-case client-side. The refusal shape below is what the
> code produces for a role that lacks the grant (verified against a test role with the grant removed);
> no live role currently gets it.

```json
{"status":"error","code":"forbidden","message":"Only leadership can view the group financial ledger."}
```

```json
{"status":"error","code":"forbidden",
 "message":"Only leadership can view the group M-Koba reconciliation. Your own is at /api/v1/my/mkoba-reconciliation."}
```

---

### GET `/ledger`

Every member's contribution standing for a period, plus the group's available fund balance. Mirrors
`app/bms/customer/financial_ledger.php` exactly — same `cs_is_opening()`/`cs_standing()` rules as
Contributions (§7), same entrance-then-monthly allocation, same "no fixed monthly => no target" rule.

Query: `start_date`, `end_date` (`YYYY-MM-DD`, default the current calendar year, capped at 120
months apart), `member_id` (narrow to one member), `search` (name or M-Koba name), `page`,
`per_page` (max 100).

```json
{
  "status": "success",
  "data": {
    "period": {
      "start_date": "2026-01-01", "end_date": "2026-12-31",
      "months": [
        {"ym": "2026-01", "label": "Jan 2026"}, {"ym": "2026-02", "label": "Feb 2026"},
        {"ym": "2026-03", "label": "Mar 2026"}, {"ym": "2026-04", "label": "Apr 2026"},
        {"ym": "2026-05", "label": "May 2026"}, {"ym": "2026-06", "label": "Jun 2026"},
        {"ym": "2026-07", "label": "Jul 2026"}, {"ym": "2026-08", "label": "Aug 2026"},
        {"ym": "2026-09", "label": "Sep 2026"}, {"ym": "2026-10", "label": "Oct 2026"},
        {"ym": "2026-11", "label": "Nov 2026"}, {"ym": "2026-12", "label": "Dec 2026"}
      ]
    },
    "fund_balance": 17287000,
    "approved_not_yet_paid": 1146500,
    "totals": {
      "members": 30, "opening": 0, "entrance": 600000, "monthly": 12850000,
      "contributed": 13450000, "assistance": 0, "agm": 0, "balance": 13450000,
      "target": 2620000, "surplus_deficit": 10830000
    },
    "members": [{
      "member_id": 25, "member_name": "Ally Minja", "mkoba_name": null, "status": "active",
      "opening": 0, "entrance_paid": 20000,
      "monthly_by_month": [10000, 10000, 10000, 10000, 10000, 10000, 10000, 10000, 10000, 0, 0, 290000],
      "monthly_total": 380000, "total_contributed": 400000, "assistance": 0, "agm_paid": 0,
      "balance": 400000, "target": 90000, "valid_months": 9, "surplus_deficit": 310000,
      "standing": "ahead"
    }],
    "pagination": {"page": 1, "per_page": 1, "total": 30, "total_pages": 30, "has_more": true},
    "__note__": "members truncated to 1 of 30 for this document"
  }
}
```

`fund_balance` is `getGroupFundBalance()` — the same cash-basis figure the dashboard (§4) shows.
`approved_not_yet_paid` is money authorised (an approved expense) but not yet disbursed; it is not
part of `fund_balance` and does not need to be subtracted from it again client-side.

**`monthly_by_month` has one entry per `period.months` column, in the same order** — zip them
together for a grid. A `0` in a column the member's `valid_months` doesn't yet reach is "not owed
yet," not "missed"; a `0` inside the valid range with no fixed monthly rate can also just mean the
group has no target (`totals.target` is `0` in that case, matching Contributions' `has_target` rule).

`agm_paid` is kept separate from `total_contributed`'s savings component and from `balance` —
`balance` is exactly `opening + monthly - assistance`, matching `standing`
(`ontrack` \| `ahead` \| `behind`, same three values as `cs_standing()` everywhere else in this API).

`end_date` before `start_date`, or a range over 120 months, is **422**:

```json
{"status":"error","code":"invalid_range","message":"end_date must not be before start_date."}
```

---

### GET `/mkoba-reconciliation`

The group-wide imported M-Koba statement, mirrored row-for-row and tied out against the ledger.
Mirrors `app/constant/accounts/mkoba_reconciliation.php`.

Query: `batch` (defaults to the most recently imported statement), `page`, `per_page` (max 200).

```json
{
  "status": "success",
  "data": {
    "batches": [{"batch": "MKOBA-2026-07", "row_count": 22}],
    "batch": "MKOBA-2026-07",
    "summary": {
      "all": {"count": 22, "amount": 1050000},
      "imported": {"count": 15, "amount": 710000},
      "excluded": {"count": 3, "amount": 130000},
      "missing": {"count": 4, "amount": 210000},
      "ledger_amount": 0,
      "reconciled": false
    },
    "rows": [{
      "sno": "1", "receipt": "RCP884011", "trans_date": "2026-08-05",
      "member_name": "Rehema Mollel", "member_id": "1", "amount": 30000,
      "trans_type": "Mchango wa mwezi", "outcome": "imported", "reason": null
    }],
    "pagination": {"page": 1, "per_page": 1, "total": 22, "total_pages": 22, "has_more": true},
    "__note__": "rows truncated to 1 of 22 for this document"
  }
}
```

`reconciled` is `true` only when nothing is `missing` **and** `ledger_amount` equals
`summary.imported.amount` to the cent — the demo example above is a real "attention" case: 4
statement rows were paid but never reached the ledger. `outcome` is `imported` \| `excluded` \|
`missing`; `reason` is only ever set on `excluded`/`missing` rows. `receipt` is `null` when Excel
mangled the source value into scientific notation (`"3.8E+15"`) — there is nothing recoverable to
show, not an empty string.

`member_id` here is the raw string from the imported statement (a phone number, typically), **not**
`customers.customer_id`.

---

### GET `/my/mkoba-reconciliation`

One member's own M-Koba tie-out: did every M-Koba transaction they made land in Vikundi, for the same
amount? Mirrors `app/constant/reports/member_mkoba_reconciliation.php`. Scoped to the token by
default — leadership may pass `member_id` to check someone else's, the same override the web page
allows via `?id=`, gated on **`manage_contributions`'s `create` grant**, deliberately not the
group-wide `mkoba_reconciliation` key above (a different question: "can this account see the whole
imported statement" vs. "can this account check on a specific member").

```json
{
  "status": "success",
  "data": {
    "member": {"id": 3, "name": "Hawa Mtui"},
    "summary": {
      "transactions": 0, "mkoba_total": 0, "book_total": 0,
      "difference": 0, "mismatches": 0, "pending": 0
    },
    "rows": []
  }
}
```

An empty `rows` array is correct, not an error, for a member with no M-Koba-linked contributions —
build the empty state. `summary.mismatches` counts rows where `mkoba_amount` and `book_amount`
disagree; `summary.pending` counts rows whose book status isn't yet `approved`/`confirmed` (still
counted in the totals, not excluded). An account with no member record at all (the Admin) and no
override gets **403**:

```json
{"status":"error","code":"no_member_record",
 "message":"This account has no member record, so it has no M-Koba reconciliation of its own."}
```

A non-leader passing `member_id` is not refused — it is silently ignored, and the response is their
own record regardless. Verified live: a Member's `?member_id=1` (the Chairperson) returned the
caller's own empty record, not the Chairperson's.

---

## 12. Expenses & Petty Cash

Two sub-modules on the same four-state workflow: `pending → reviewed → approved → paid`. `paid` is
a real fourth step neither Contributions nor Condolences has — an expense is *authorised* on
approval, but the group's available balance (§4, §11) only drops once it is actually disbursed.
Reference implementations: `includes/api_expenses.php`, `includes/api_petty_cash.php`.

**Gated on `expenses` and `petty_cash` respectively** (view for the list, matching each module's own
web page) — **except `mark-paid`, which is gated on a role check, not a permission-table grant**:
Treasurer or a full admin only, "the people who release the group's money." A Secretary or
Chairperson holding full review/approve rights on either module still cannot mark something paid.

> **Member already holds `view` on `expenses` today**, verified live: the equivalent web page and its
> own API (`api/get_general_expenses.php`) already serve the whole list to a plain member, and that
> endpoint has been through a security audit under that exact behaviour. This mirrors it faithfully
> rather than inventing a stricter rule the web has never had. `petty_cash` is a brand-new permission
> key, deliberately mirrored from `expenses`' grants for the same reason — see the note below.

---

### GET `/expenses`

The group's general expenses. Query: `page`, `per_page` (max 100), `status`, `date_from`, `date_to`,
`member_id`, `scope` (`general` = whole-org only, `member` = charged to a member), `search`.

```json
{
  "status": "success",
  "data": {
    "expenses": [{
      "id": 9, "expense_date": "2026-09-03",
      "description": "Vitabu vya kumbukumbu na kalamu", "amount": 45000, "status": "paid",
      "member": null,
      "created_at": "2026-09-03T13:56:16+03:00", "reviewed_at": "2026-09-03T13:56:34+03:00",
      "approved_at": "2026-09-03T13:56:36+03:00", "paid_at": "2026-09-03T13:56:38+03:00",
      "actions": {"edit": false, "review": false, "approve": false, "mark_paid": false}
    }],
    "totals": {"filtered_amount": 50000, "filtered_count": 2},
    "pagination": {"page": 1, "per_page": 2, "total": 9, "total_pages": 5, "has_more": true}
  }
}
```

`member` is `null` for a whole-organization expense, `{"id", "name"}` when charged to one member —
nested rather than a flat `member_id`, so a `null` reads unambiguously as "not charged to anyone"
rather than "member unknown."

---

### GET `/expenses/{id}`

One expense with its full four-stage trail. `paid` never carries an e-signature — the web's own
mark-paid action has never captured one — so it is read straight from who actually marked it paid,
not from the signature store the other three stages use:

```json
{
  "status": "success",
  "data": {
    "expense": { "...": "same shape as the list row" },
    "trail": {
      "created":  {"by": "Hawa Mtui", "role": "",          "at": "2026-09-03T13:56:16+03:00", "signed": false, "completed": true},
      "reviewed": {"by": "Hawa Mtui", "role": "Treasurer",  "at": "2026-09-03T13:56:34+03:00", "signed": false, "completed": true},
      "approved": {"by": "Hawa Mtui", "role": "Treasurer",  "at": "2026-09-03T13:56:36+03:00", "signed": false, "completed": true},
      "paid":     {"by": "Hawa Mtui", "role": "",           "at": "2026-09-03T13:56:38+03:00", "signed": false, "completed": true}
    }
  }
}
```

---

### POST `/expenses`

Record a general expense. Leadership only (`create`). `description` and `amount` required;
`expense_date` defaults to today; optional `member_id` charges it to one member (silently ignored,
not refused, if that id doesn't exist — the same rule `api/add_general_expense.php` uses). No status
field — every expense is created `pending`. No attachments, same call as Condolences: a receipt still
has to be attached from the web for now.

---

### PUT `/expenses/{id}`

Edit `description`, `amount` and/or `expense_date` — send only what changed. `edit` on `expenses`.
**Refused once `approved` or `paid`**, not just `approved`:

```json
{"status":"error","code":"not_editable","message":"An expense that is approved can no longer be edited."}
```

> The web's own edit endpoint used to allow editing a **paid** expense — money already gone — and was
> fixed alongside this endpoint to match.

---

### POST `/expenses/{id}/review` · `/approve` · `/mark-paid`

The workflow. `review`/`approve` need `view` alongside the action itself (`canReview()`/
`canApprove()`'s own rule), same as every other module. `approve` additionally checks the group's
real fund balance — an expense authorises a spend, so it must not authorise more than the group
could ever pay:

```json
{"status":"error","code":"insufficient_funds",
 "message":"The group fund balance (TZS 17,287,000.00) is not enough to approve this expense (TZS 999,999,999.00)."}
```

**`mark-paid` is the odd one out**: gated on `canMarkPaid()` — Treasurer or a full admin — not the
`expenses` permission at all. A Secretary who can review and approve everything else in this module
still gets a **403** naming the Treasurer specifically:

```json
{"status":"error","code":"forbidden","message":"Only the Treasurer or an administrator can mark an expense as paid."}
```

Marking an already-paid expense paid again is **409** `already_paid`, not a silent no-op.

---

### GET `/reports/expense-report`

Spending analysis: general expenses combined with condolence (death) expenses — **not** petty cash,
which this report has never included, matching `app/constant/reports/expense_report.php` exactly.
Gated on `vicoba_reports`, same as every other report. Both source tables are restricted to
`status IN ('approved','paid')` — an authorised-but-undisbursed expense doesn't belong in a spending
summary yet.

```json
{
  "status": "success",
  "data": {
    "items": [
      {"category": "general", "date": "2026-09-03", "amount": 5000, "description": "API smoke test"},
      {"category": "condolences", "date": "2026-09-02", "amount": 1500, "description": "..."}
    ],
    "totals": {
      "general": 50000, "death": 101622, "overall": 151622, "records": 5,
      "pct_general": 33, "pct_death": 67
    },
    "trend": [{"month": "2026-08", "label": "Aug 2026", "amount": 45000}]
  }
}
```

No pagination — this is a full-period summary, same as the web report, not a browsable list.

---

### GET `/petty-cash`

The group's petty-cash vouchers. Same query shape as `/expenses` minus `scope`/`member_id`, plus
`category`.

```json
{
  "status": "success",
  "data": {
    "vouchers": [{
      "id": 6, "voucher_no": "PCV-2609-6063", "transaction_date": "2026-09-03",
      "payee_name": "API Smoke Test", "category": "Testing",
      "description": "Module 9 verification voucher", "amount": 3000, "status": "paid",
      "prepared_by_name": "hmtui",
      "created_at": "2026-09-03T13:57:02+03:00", "reviewed_at": "2026-09-03T13:57:05+03:00",
      "approved_at": "2026-09-03T13:57:46+03:00", "paid_at": "2026-09-03T13:57:54+03:00",
      "actions": {"edit": false, "review": false, "approve": false, "mark_paid": false}
    }],
    "totals": {"filtered_amount": 131000, "filtered_count": 6},
    "pagination": {"page": 1, "per_page": 1, "total": 6, "total_pages": 6, "has_more": true}
  }
}
```

`approved_at` here is drawn from the table's own `approval_date` column (named differently from
`general_expenses.approved_at`) — exposed under the same key so the two modules read alike.

> **New permission key.** `petty_cash` did not exist in the permissions catalog before this module.
> The web's own gating for petty cash was inconsistent across files — creating a voucher checked
> `expenses` instead, the list/detail pages used a hardcoded role-name array, not RBAC at all, and
> **the list's own AJAX endpoint had no permission check whatsoever** — any authenticated Member
> could pull the whole voucher list. Confirmed live before the fix. The new key mirrors `expenses`'
> grants exactly (leadership full rights, Member view-only) and the web hole is closed alongside it.

---

### GET `/petty-cash/{id}` · PUT `/petty-cash/{id}`

Detail + four-stage trail, same shape as `/expenses/{id}`. **Edit is refused once a voucher leaves
`pending`** — stricter than Expenses, which still allows editing a `reviewed` row. This mirrors
`actions/save_petty_cash.php`'s own rule exactly; the two modules are deliberately not smoothed into
one shared edit rule.

---

### POST `/petty-cash/{id}/review` · `/approve` · `/mark-paid`

Same shape as Expenses' workflow, with one difference: **`approve` has no fund-balance check.** The
web's own `actions/approve_petty_cash.php` has never gated on the group balance, and this does not
add a check the web has never enforced. `mark-paid` is gated on `canMarkPaid()`, identically to
Expenses.

---

## 13. Demo logins

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
