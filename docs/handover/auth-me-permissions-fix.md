# API change — `/auth/me` now reports effective permissions

**Status: live on demo and production.** Deployed 2026-08-22.

You were right, and it was a server bug. `permissions['customers'].view` really did
come back false for the Admin. Do **not** work around it — the API has been fixed.

---

## What was wrong

`vk_api_can()` short-circuits for an admin and never reads the permission map:

```php
if (vk_api_is_admin($auth['role_id'])) return true;
```

An admin is therefore granted every page regardless of `role_permissions` — and on
the live system those rows are nearly empty, because the web app never needed
them: `isAdmin()` has always bypassed the check.

`/auth/me` was returning that raw map, while documenting itself as "what the
server will actually enforce". It wasn't. Your client did the right thing with
wrong data.

---

## What changed

### 1. An admin now receives the full catalogue

Before — Admin, 10 keys, no `customers`:

```
expenses · manage_fines · ai_assistant · ai_settings · ai_ask_data
meetings · manage_voting · manage_documents
leadership_applications · manage_leadership_applications
```

After — **33 keys**, every action `true`, `customers` included.

Non-admin roles are **unchanged**. Their map was already correct and remains the
authority for them.

### 2. `is_leadership` added

It existed in `/dashboard` but not here, so you couldn't tell a Secretary from a
Member without inspecting the permission map.

### 3. `member_id` is now `null`, never `0`

⚠️ **The one breaking change.** `/dashboard` already returned `null`; `/auth/me`
returned `0`. Same field, two meanings, depending which endpoint you asked.

If you parse it as a non-nullable `int`, that will now throw. Make it `int?`.

---

## Live values, all five demo roles

| user | `is_admin` | `is_leadership` | `member_id` | keys | `customers.view` |
|---|---|---|---|---|---|
| `admin` | `true` | `true` | **`null`** | 33 | `true` |
| `rmollel` (Chairperson) | `true` | `true` | `1` | 33 | `true` |
| `amhando` (Secretary) | `false` | `true` | `2` | 28 | `true` |
| `hmtui` (Treasurer) | `false` | `true` | `3` | 28 | `true` |
| `hmbwana1` (Member) | `false` | `false` | `30` | 24 | `true` |

---

## The rule for permission checks

```dart
bool can(String page, String action) =>
    me.permissions[page]?[action] == true;
```

That is now sufficient for **every** role, including Admin.

Keeping `is_admin ||` in front is harmless and stays correct — belt and braces
against a future role gaining the bypass without the map keeping up:

```dart
bool can(String page, String action) =>
    me.isAdmin || me.permissions[page]?[action] == true;
```

Either is fine. Do **not** special-case Admin any further than that.

---

## What to change in the app

1. **`member_id` → nullable `int?`.** Required. `null` means the account has no
   member record — a system Admin. Hide the personal screens (My Contributions,
   My Fines) when it's null; they don't apply.
2. **Remove any Admin workaround** you added for the missing `customers` key.
3. **`is_leadership`** is available if useful for layout. It's true for Admin,
   Chairperson, Secretary and Treasurer; false for Member.
4. Re-test as `admin` — Members List should now appear.

Nothing else changes. Same envelope, same endpoints, same tokens.

---

## Field reference

```
data.user.user_id        int
data.user.username       string
data.user.full_name      string
data.user.email          string
data.user.role_id        int      1 Admin · 2 Chairperson · 3 Secretary
                                  4 Treasurer · 15 Member (live) / 13 (fresh install)
data.user.role           string   may be EMPTY for the Admin — do not key logic on it
data.user.language       string   "en" | "sw"
data.user.member_id      int?     null when the account has no member record
data.user.is_admin       bool
data.user.is_leadership  bool     NEW
data.permissions         map      page_key -> {view, create, edit, delete, review, approve}
```

**Never key logic on `role`.** The Admin's is an empty string on the live data,
and role names are editable in Settings. Use `role_id`, `is_admin`,
`is_leadership`, or the permission map.

---

## Also worth knowing

`/dashboard` is **shape-variant by role** — a Member's response has no `balance`,
`members`, `contributions`, `expenses`, `fines` or `trend` keys **at all**, not
null. Use `containsKey`, and branch on `role.is_leadership`.

`recent_activity` appears only when **`is_admin`** is true — which is the Admin *and the
Chairperson* (role_ids 1, 2, 12), not the Admin account alone. Verified live: present for
`admin` and `rmollel`, absent for `hmtui`.

If you built the dashboard against a leader login with `?? 0` fallbacks, test it
as `hmbwana1` before moving on.
