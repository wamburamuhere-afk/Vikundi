# Sessions Log — Vikundi VICOBA Management System

This file tracks every development session, modification, and significant change made to the project. Update it at the end of every session before pushing.

---

## Session — 2026-06-26 — VICOBA system roles & RBAC (roles PR-1)
**Branch:** `feat/vicoba-roles-rbac`
**Developer:** Claude Code / Jabir Mussa
**Summary:** First of two PRs for the four default user roles. PR-1 = the **roles + permissions**; PR-2 = the **member sensitive-data masking**. Replaced the BMS leftover roles with four VICOBA system roles and set their access. Scope confirmed: Chairperson = full admin; Secretary & Treasurer = full CRUD on operational data but **not** user/role/settings management; Member = view-only.

### Files Created
- **`database/seed_vicoba_roles.php`** — idempotent, deploy-safe seeder (registered in `database/migrate.php`):
  - Reassigns any user on a BMS role to Member, then **removes** Director/CFO/Accountant/Credit Manager/Loan Manager (ids 5–9).
  - Creates the four roles with fixed ids: **2 Chairperson · 3 Secretary · 4 Treasurer · 13 Member**.
  - Seeds **default permissions only when a role has none yet** (so deploys never wipe manual changes): Chairperson → all 77 keys, full CRUD; Secretary/Treasurer → full CRUD on every key **except** the admin keys (`users, user_roles, add_user, edit_user, system_settings, policy_management`) = 71 keys; Member → `can_view` only on `customers, customer_details, dashboard`.
- **`tests/Unit/VicobaRolesTest.php`** — unit-tests the pure `vk_role_grants()` logic (chairperson all / secretary-treasurer operational-not-admin / member view-only) + guards the role declarations, BMS removal, and migrate registration.

### Files Modified
- **`core/permissions.php`** — tightened `isAdmin()`: full-admin bypass now only **Admin + Chairperson** (role names `admin/administrator/chairperson/mwenyekiti/chairman`, role ids 1/2/12). **Removed `secretary`/`treasurer`/`sekretari`/`mweka hazina`** from the bypass — they were treated as full admins, which would have overridden the operational-only restriction.
- **`tests/Unit/PermissionsTest.php`** — secretary/treasurer now assert **not** admin; added chairperson (name + role id 2) = admin.

### Verification
- Live seeder run: roles = Admin, Chairperson, Secretary, Treasurer, Member; BMS removed. Permission counts: Chairperson **77/all-CRUD**, Secretary & Treasurer **71** (no admin keys), Member **3 view-only**. Re-run via `migrate.php` → "left unchanged" (idempotent, no wipe).
- Unit suite **699 / 1498**; `php -l` clean.
- Next: **PR-2** — hide phone/NIDA/email/financial/family from a Member viewing other members (server-side masking on the list + details).

---

## Session — 2026-06-26 — Public registration → 6-step wizard + children-when-single fix (PR-2)
**Branch:** `feat/public-registration-stepper`
**Developer:** Claude Code / Jabir Mussa
**Summary:** Mirrored the PR-1 restructure onto the **public** `register.php` (6-step wizard + children card repeater) and fixed the **children-show-when-single bug** on **both** forms.

### Bug fix (flagged after PR-1 merged)
PR-1 moved children out of the marital-status wrapper, which made the children area show for **single** members. Fix on both forms: the marital toggle (`toggleFamilyFields` / `toggleFamilyFieldsAdmin`) now hides **and disables** the spouse wrapper **and** the children section when "Single", and shows a small "applies to married members" note so the step isn't blank.

### Public form — `register.php`
- Added a **6-step stepper** nav (the public form had none — only Next/Back); `switchTab()` now drives the Bootstrap pill so Next/Back and the stepper stay in sync.
- Split into **Personal · Residence · Parents · Spouse & Children · Guarantor · Account**; carved Residence out of Personal; re-chained Next/Back.
- Children **table → card repeater** (`.child-card`); `addChildRow`/`removeRow`/`vkChildAge` rewired to cards.
- `childrenSection` + `familyNote` wired to the marital toggle (on-load call applies the initial state).

### Admin form — `customers.php`
- The children-hide-when-single fix (the actual bug, since PR-1 is what introduced it there).

### Tests
- **`tests/Unit/RegisterStepperTest.php`** — 6 panes (no dup), stepper present, no field dropped, children are cards + hide-when-single, form `<div>`s balanced.
- **`AdminRegistrationStepperTest`** — added a children-hidden-when-single guard.

### Verification
- Both forms: `<div>` balanced (register 127=127, admin from PR-1); 6 unique panes; steppers render 6 buttons; no orphaned old-table refs. Live `register.php` → **200**, no errors, 6 stepper buttons, 6 panes, child cards present.
- Unit suite **692 / 1473**; `php -l` clean.

---

## Session — 2026-06-26 — Admin registration modal → 6-step wizard (PR-1)
**Branch:** `feat/admin-registration-stepper`
**Developer:** Claude Code / Jabir Mussa
**Summary:** The admin add-member modal (`customers.php`) had grown congested — one "Family & Beneficiaries" tab held ~49 fields (parents + spouse + children + guarantor). Split it into a **6-step wizard** with a slim numbered stepper. **Pure UI** — no field/handler/DB change (every `name=` preserved; all panes stay in the one `<form>`, so submission is untouched).

### Steps (3 tabs → 6)
Personal · Residence · Parents · Spouse & Children · Guarantor · Account.

### Files Modified — `app/bms/customer/customers.php`
- Replaced the 3-pill nav with a **6-step stepper** generated from a `$__steps` array (numbered circles; labels hidden on mobile; horizontal-scroll on narrow screens).
- Split the `#personal` pane (Residence carved into its own `#residence` step) and the giant `#home` pane into `#parents`, `#family`, `#guarantor` panes; re-chained the Next/Back buttons across all 6 steps.
- **Children moved out of `familyFieldsAdmin`** so they're always shown (spouse stays conditional on "Married"), avoiding an empty step for single members.
- **Children UI: table → card repeater.** The cramped 7-column table (date/file/select jammed into narrow cells) is now a **card per child** — a bordered card with a clean grid (Name · DOB · Age · Gender, then Photo) and an "×" remove in the header. `addChildRowAdmin`/`removeRowAdmin`/`vkChildAge` rewired to cards (`.child-card-admin`); div balance 132 = 132.

### Tests
- **`tests/Unit/AdminRegistrationStepperTest.php`** — 6 step panes exist; stepper declares 6 steps; **no field dropped** (representative fields from every section); spouse wrapper opened/closed once + children table present; **form `<div>`s balanced**.
- Updated `CustomersButtonsTest` to the new stepper structure (was asserting the old `home-tab` / `switchTab('home')`).

### Verification
- `<div>` balance in the form: **122 = 122**; **6 tab-panes**; the stepper PHP loop renders **6 buttons** → personal/residence/parents/family/guarantor/account; all section fields retained; `familyFieldsAdmin` opened/closed once.
- Unit suite **686 / 1442**; `php -l` clean. (Visual/UX to be eyeballed in the browser.)
- **PR-2 (public `register.php`)** mirrors this once PR-1 is signed off.

---

## Session — 2026-06-26 — Member-family edit: passport photos (E2)
**Branch:** `feat/member-edit-photos-e2`
**Developer:** Claude Code / Jabir Mussa
**Summary:** Second/final PR of the edit-form rework — add/replace passport photos **later** on the member-family edit form (`profile.php`) for **spouse, parents, and children**. (The member's own avatar upload already existed.) Completes the "passport can be added later" requirement and the whole registration + edit rework.

### Files Modified
- **`helpers.php`** — new `vk_upload_photo($field, $dir): ?string` (single optional upload; returns null when no file, so callers keep the existing value — never wipe).
- **`app/constant/profile/profile.php`**:
  - **SELECT** loads `father_photo`, `mother_photo`, `spouse_photo` (to show current + preserve).
  - **Edit form** — each parent + spouse gains a photo field that **shows the current photo** (thumbnail) with a "choose a file to replace" input; the children table gains a **Photo** column (thumbnail + replace input). Dynamically-added child rows include the photo input.
  - **UPDATE handler** — parent/spouse photo = new upload **or** keep existing (`vk_upload_photo(...) ?? $member[...]`); children re-encode replaces a photo on new upload, otherwise preserves the existing one. **Empty upload never wipes a photo.** UPDATE extended to **81 placeholders = 81 values**.

### Tests
- **`tests/Unit/MemberEditPhotosTest.php`** — 5 tests: helper exists; SELECT loads the photo columns; form has the photo inputs; UPDATE persists them; **empty upload keeps the existing photo** (the never-wipe rule, parents/spouse + children).

### Verification
- UPDATE balanced (81 = 81); photo columns valid in an UPDATE (rolled-back round-trip of `father_photo`/`mother_photo`/`spouse_photo`); SELECT with the photo columns executes.
- Unit suite **681 / 1413**; `php -l` clean.

### Registration + edit rework — COMPLETE
Registration: PR-A ✅ · PR-B ✅ · PR-C ✅ · PR-D ✅ · spouse photo ✅ · picker fix ✅. Edit form: E1 ✅ (fields + parent-location bug) · **E2 ✅ (photos)**. Member-family data can now be entered at registration AND edited later, with optional photos for member/spouse/parents/children throughout.

---

## Session — 2026-06-26 — Member-family edit form sync (E1)
**Branch:** `feat/member-edit-sync-e1`
**Developer:** Claude Code / Jabir Mussa
**Summary:** First of two PRs bringing the member-family **edit** form (`profile.php` edit mode — open to the member on their own profile and to Admin/Secretary/Katibu on others) in sync with the registration rework. **E1 = field sync + the parent-location bug fix** (photo upload/replace is E2). Scope confirmed with Jabir: no member-picker on edit (privacy), full field parity, 2-PR split.

### Files Modified — `app/constant/profile/profile.php`
- **SELECT** — loads the new columns so the form pre-fills: parent structured names + 6-field location, guarantor 6-field location.
- **Edit form** — parents rewritten to structured First/Middle/Last + 6-field location (Title-cased, pre-filled); guarantor gains the 6-field location; children table gains **Date of Birth** (with `vkChildAge()` deriving age); labels Title-cased.
- **UPDATE handler** — now persists all the new parent/guarantor columns; legacy `*_name` rebuilt via `vk_full_name()`, legacy location from state/ward (registration parity). **Fixed the latent bug** where the form showed parent location but the handler never saved it. Children re-encode now stores DOB + derives age **and preserves a photo/`is_deceased` set elsewhere** (editing no longer wipes a registration child photo).

### Tests
- **`tests/Unit/MemberEditSyncTest.php`** — 5 tests: SELECT loads new columns; edit form has the new inputs; UPDATE persists them; the parent-location bug is fixed; children re-encode reads DOB + preserves the photo.

### Verification
- UPDATE statement balanced: **78 placeholders = 78 values** (verified by evaluating the bind array). New columns valid in an UPDATE (rolled-back transaction round-tripped `father_first_name`/`father_state`/`guarantor_district`). The full SELECT executes and returns the new keys for pre-fill.
- Unit suite **676 / 1398**; `php -l` clean.
- Next: **E2** — passport photo upload/replace on edit for member/spouse/parents/children (show current, replace, never wipe on empty).

---

## Session — 2026-06-26 — Registration: spouse passport photo
**Branch:** `feat/registration-spouse-photo`
**Developer:** Claude Code / Jabir Mussa
**Summary:** Optional **passport photo for the member's spouse** on both registration forms — the spouse section had every other field but no photo. Mirrors the parent-photo pattern.

### Database
- **`database/add_spouse_photo_column.php`** — idempotent migration; adds `customers.spouse_photo`. **Registered in `database/migrate.php`** (auto-runs on deploy).

### Files Modified
- **`register.php`** + **`app/bms/customer/customers.php`** — spouse section gains an optional `spouse_photo` file input (after Region of Birth).
- **`actions/process_registration.php`** + **`actions/add_member.php`** — upload `spouse_photo` via the existing `vk_save_photo()` closure; `customers` INSERT extended to 73 columns (72 placeholders + `created_at`).

### Tests
- **`tests/Unit/SpousePhotoTest.php`** — 3 tests: migration declares the column + is registered; both forms collect `spouse_photo`; both handlers upload and persist it.

### Verification
- Migration ran (column added); both INSERTs balanced (columns 73 / placeholders 72 / values 72); a rolled-back transaction INSERT round-tripped `spouse_photo`.
- Live: `register.php` **200**, no errors, `spouse_photo` present.
- Unit suite **671 / 1374**; `php -l` clean.
- Next: member-family **edit** form (edit parent/children/guarantor/spouse + add photos later).

---

## Session — 2026-06-26 — Fix: guarantor picker not loading members
**Branch:** `fix/guarantor-picker-url`
**Developer:** Claude Code / Jabir Mussa
**Summary:** Bug from PR-C — the admin guarantor "pull existing member" picker loaded no results. Root cause was **not** local testing: the Select2 search and the autofill `fetch` used **relative** paths (`api/search_customers.php`, `api/get_guarantor_member.php`), which resolve to the wrong path on the admin page's clean URL → 404. Every other AJAX call in `customers.php` uses `getUrl(...)` clean routes. Also, the autofill endpoint had **no route registered** (so even `getUrl` would have 404'd it).

### Files Modified
- **`roots.php`** — register `api/get_guarantor_member` (and the `.php` variant) clean routes.
- **`app/bms/customer/customers.php`** — picker search → `getUrl("api/search_customers")`; autofill → `getUrl("api/get_guarantor_member")`.

### Tests
- **`tests/Unit/GuarantorPickerUrlTest.php`** — picker uses `getUrl` clean routes (not bare relative `.php`); the autofill endpoint route is registered.
- Updated `GuarantorDetailsTest` to match the clean-route reference.

### Verification
- Live (front controller): `/api/search_customers?q=a` returns the member list; `/api/get_guarantor_member?id=2` resolves (auth error when unauthenticated — gate intact).
- Unit suite **668 / 1366**; `php -l` clean.

---

## Session — 2026-06-26 — Registration form PR-D (children passport photo)
**Branch:** `feat/registration-pr-d-children-photo`
**Developer:** Claude Code / Jabir Mussa
**Summary:** Optional **passport photo per child** on both registration forms — the last piece of the children work. No DB migration (children are stored as JSON, so the photo path lives in each child entry).

### Files Modified
- **`register.php`** + **`app/bms/customer/customers.php`** — children table gains a **Photo (Optional)** column with a `child_photo[]` file input; both `addChildRow` / `addChildRowAdmin` JS templates updated to match.
- **`helpers.php`** — new `vk_save_child_photo($files, $i, $dir)`: saves one optional photo from the array-style `$_FILES['child_photo']` (returns '' when no file for that row).
- **`actions/process_registration.php`** + **`actions/add_member.php`** — capture `$_FILES['child_photo']` and store `photo` in each child's `children_data` JSON entry via the helper.

### Tests
- **`tests/Unit/ChildPhotoTest.php`** — 5 tests: helper guard logic (no file / missing row → ''); both forms collect `child_photo` (static + JS rows); both handlers persist `photo`.

### Verification
- Live: `register.php` **200**, no errors, `child_photo[]` present (static + dynamic rows), Photo column renders.
- Unit suite **666 / 1361**; `php -l` clean.

### Deferred follow-up (flagged, scope decision by Jabir)
"Add parents'/children's passport **later** on the edit screens" is **not** delivered here — investigation showed the member-family **edit flow has no home**: the "Edit Member" page (`edit_customer.php`) is a BMS business-customer KYC form (company/BRELA/TIN docs, no family fields), and `profile.php`'s edit mode is outdated (old flat `father_name`/`father_phone` + children name/age/gender only — none of the PR-A/B/C structured fields). The member's **own** passport add-later already works (`profile.php` avatar upload). Proper "add later for parents/children" needs a dedicated **member-family edit form** (sync edit/profile with all PR-A/B/C fields + photos) — a separate effort, agreed to defer.

### Registration rework status
PR-A ✅ · PR-B ✅ · PR-C ✅ · **PR-D ✅** — registration data-entry rework complete. Follow-up: member-family **edit** form sync (incl. passport add-later for parents/children).

---

## Session — 2026-06-26 — Registration form PR-C (guarantor details)
**Branch:** `feat/registration-pr-c-guarantor`
**Developer:** Claude Code / Jabir Mussa
**Summary:** Richer guarantor details. The guarantor now has the **same six-field location** as the member (on **both** forms), and the **admin** add-member form can **pull an existing member** as the guarantor and autofill their name/phone/location.

### Security decision (member directory privacy)
The "pull existing member" picker is on the **admin form only**. `register.php` is the **public, anonymous** self-registration page — adding a member search there would expose every member's name + phone to anonymous visitors (reversing the audit B3/H3 hardening). So the public form keeps **manual guarantor entry + the six-field location**, and only the authenticated admin form gets the picker. The autofill endpoint is auth-gated.

### Database
- **`database/add_guarantor_detail_columns.php`** — idempotent migration; adds 7 columns to `customers`: `guarantor_member_id` + `guarantor_{country,state,district,ward,street,house_number}`. Legacy `guarantor_name/phone/rel/location` kept and populated. **Registered in `database/migrate.php`** (auto-runs on deploy).

### Files Created
- **`api/get_guarantor_member.php`** — auth-gated (`require_auth.php`); returns a member's name/phone/six-field location by id, for the admin autofill.

### Files Modified
- **`register.php`** (public) — guarantor section gains the six-field location; **no** member picker.
- **`app/bms/customer/customers.php`** (admin) — guarantor section gains the six-field location **+ a Select2 "pull existing member" picker** (searches `api/search_customers.php`); on select it fetches `get_guarantor_member.php` and autofills name/phone/location, and sets a hidden `guarantor_member_id`.
- **`actions/process_registration.php`** + **`actions/add_member.php`** — capture the six-field location; `add_member` also captures `guarantor_member_id` (public handler forces it null); legacy `guarantor_location` populated from state; `customers` INSERT extended to 72 columns (71 placeholders + `created_at`).

### Tests
- **`tests/Unit/GuarantorDetailsTest.php`** — 6 tests: migration declares columns + is registered in the runner; endpoint is auth-gated; six-field location on both forms; picker is **admin-only** (absent from the public form); handlers persist the columns.
- Updated `CustomersRegistrationLanguageTest` guarantor labels for the new structure.

### Verification
- Migration ran → 7 columns added; all 72 INSERT columns exist; placeholders = values = 71 in both handlers.
- Live: endpoint **blocks anonymous** (auth error) and returns member details when authed; public `register.php` shows the six-field location with **no picker**; admin form has the picker + member-id + autofill JS; a rolled-back INSERT round-tripped `guarantor_member_id` + structured location.
- Unit suite **661 / 1348**; `php -l` clean.
- Registration tasks: PR-A ✅ · PR-B ✅ · **PR-C ✅** · PR-D ⏳ (passport "add later" on edit/profile + children passport photo).

---

## Session — 2026-06-26 — Registration form PR-B (parent details)
**Branch:** `feat/registration-pr-b-parents`
**Developer:** Claude Code / Jabir Mussa
**Summary:** Richer parent information on the member record, on **both** the public form and the admin add-member form. Each parent (father + mother) now has structured **First / Middle / Last** names, the **same six-field location** as the member's residence (Country, Region/State, District, Ward, Street/Village, House No.), and an **optional passport photo**. First DB migration of the registration rework.

### Database
- **`database/add_parent_detail_columns.php`** — idempotent migration (mirrors `sync_workflow_columns.php` + the existing `nok_*` pattern). Adds 20 columns to `customers`: `{father,mother}_{first_name,middle_name,last_name,country,state,district,ward,street,house_number,photo}`. Legacy `father_name/father_location/father_sub_location/father_phone` (and mother_*) are **kept and still populated**, so existing records/reports are unaffected. **Registered in `database/migrate.php`**, which `deploy.yml` already runs on every deploy — so it applies automatically (no manual server step).

### Files Modified
- **`register.php`** + **`app/bms/customer/customers.php`** — parent blocks rewritten with the 3 name fields + 6 location fields + phone + optional photo (`<input type="file" name="father_photo">` / `mother_photo`); customers.php kept bilingual.
- **`helpers.php`** — new `vk_full_name(first, middle, last)`: joins parts, drops blanks (keeps the legacy `*_name` columns populated).
- **`actions/process_registration.php`** + **`actions/add_member.php`** — capture the new parent fields, upload the optional photos (same store as the member avatar, via a `vk_save_photo()` closure), build `father_name`/`mother_name` via `vk_full_name()`, populate legacy location columns (state→location, ward→sub_location), and extend the `customers` INSERT to the 65-column form (64 placeholders + `created_at`).

### Tests
- **`tests/Unit/ParentDetailsTest.php`** — 5 tests: `vk_full_name`; migration declares every parent column; both forms collect the fields; both handlers persist them (+ keep `*_name` via `vk_full_name`).
- Updated `ChildDobTest` and `CustomersRegistrationLanguageTest` for the new parent label structure.

### Verification
- Migration ran: **20 columns added**. All 65 INSERT columns exist in `customers`; placeholders = values = 64 in both handlers.
- Live: `register.php` **200**, no errors, `enctype=multipart`, all parent fields render. Transaction-wrapped INSERT (rolled back) round-tripped `father_name='John Mike Doe'`, structured columns, and photos.
- Unit suite **655 / 1312**; `php -l` clean on all touched files.
- Registration tasks: PR-A ✅ · **PR-B ✅** · PR-C ⏳ (guarantor) · PR-D ⏳ (passport "add later" + children photo).

---

## Session — 2026-06-26 — Registration form PR-A (label casing + children DOB)
**Branch:** `feat/registration-pr-a-casing-child-dob`
**Developer:** Claude Code / Jabir Mussa
**Summary:** First of the assigned registration-form tasks. (1) Title-cased the Family & Beneficiaries tab field labels so they match the Personal & Residence tab (they were ALL CAPS). (2) Added a **Date of Birth** field for children, with **age derived from DOB** (server-side, not trusting the client). Applied to **both** the public form and the admin add-member form (per the agreed scope).

### Files Modified
- **`register.php`** + **`app/bms/customer/customers.php`** — parents/guarantor/children labels → Title Case (Swahili kept, just cased, e.g. `JINA LA BABA` → `Jina la Baba`); children table gains a **Date of Birth** column; Age is now a read-only auto-filled field; `vkChildAge()` JS derives the preview age; `addChildRow`/`addChildRowAdmin` updated to match.
- **`helpers.php`** — new pure `vk_age_from_dob(?string): ?int` (whole years; null for empty/invalid/future).
- **`actions/process_registration.php`** + **`actions/add_member.php`** — capture `child_dob[]`, store `dob` in `children_data` JSON, derive `age` server-side via `vk_age_from_dob()` (add_member also now requires `helpers.php`). No schema change — children are JSON.

### Tests
- **`tests/Unit/ChildDobTest.php`** — 7 tests: `vk_age_from_dob` (exact birthday, day-before, future, empty/invalid); both forms collect `child_dob`; both handlers persist `dob` + derive age; Family labels are Title-Cased.
- **`tests/Unit/CustomersRegistrationLanguageTest.php`** — updated the locked label expectations to the new Title Case (purpose preserved: Swahili labels still present) + assert the new DOB header.

### Verification
- Live (`register.php`, built-in server): **200**, no PHP errors; Title-Case labels render, old all-caps gone, `child_dob[]` present.
- Unit suite **650 / 1268**; `php -l` clean on all touched files.
- Registration tasks: **PR-A ✅** · PR-B ⏳ (parents: names + 6-field location + photo) · PR-C ⏳ (guarantor: pull-member + location) · PR-D ⏳ (passport "add later" + children photo).

---

## Session — 2026-06-26 — Audit fix M1
**Branch:** `fix/m1-currency-normalize`
**Developer:** Claude Code / Dutch
**Summary:** Audit Medium **M1** — currency inconsistent for Tanzania. Two formatters disagreed on the symbol (`helpers.php` → `'TSh '`, `dashboard.php` → hardcoded `'TZS '`), and the group-currency settings offered USD/KES/EUR/etc. Normalized to one TZS formatter and made TZS the only selectable group currency.

### Files Modified
- **`helpers.php`** — `format_currency($amount, $currency = 'TZS', $decimals = 2)`: added the optional `$decimals` so whole-shilling displays (dashboard) and 2-decimal accounting views share one symbol map. Backward-compatible (default 2 decimals → existing 15 callers + tests unchanged).
- **`app/dashboard.php`** — `fmt_currency()` now delegates to `format_currency($n, 'TZS', 0)` instead of hardcoding `'TZS '`. Symbol is now `TSh` like the rest of the app; 0-decimal card style preserved.
- **`app/constant/settings/system_settings.php`**, **`app/bms/customer/group_settings.php`**, **`app/bms/purchase/purchase_order_create.php`** — currency selectors/list trimmed to **TZS only** (dropped USD/KES/EUR/GBP/UGX).

### Scope note
Left the **unused BMS** supplier/POS/employee currency dropdowns (`suppliers.php`, `supplier_payments.php`, `pos/employees.php`) untouched — not named by M1 and part of the unused e-commerce modules flagged in H2; they have their own per-supplier currency coupling.

### Tests
- **`tests/Unit/HelpersTest.php`** — +2 (default decimals unchanged; 0-decimals path).
- **`tests/Unit/CurrencyNormalizationTest.php`** — dashboard delegates to the central formatter (no hardcoded symbol); the three named selectors offer no foreign currency.

### Verification
- `format_currency(50000,'TZS')` → `TSh 50,000.00`; `format_currency(50000,'TZS',0)` → `TSh 50,000`.
- Unit suite **643 / 1246**; `php -l` clean on all touched files.
- Medium tier: M1 ✅ · M2 ⏳ · M3 ✅ · M4 ✅ · M5 ✅ · M6 ✅.

---

## Session — 2026-06-26 — Audit fix M4
**Branch:** `fix/m4-auto-terminate-throttle`
**Developer:** Claude Code / Dutch
**Summary:** Audit Medium **M4** — `header.php:4` included `actions/auto_terminate_members.php`, which ran a heavy aggregate (customers ⋈ users ⋈ contributions, GROUP BY/HAVING) plus a write per late member on **every page load**, for every user. Refactored into testable functions and throttled to run **at most once per calendar day**; added a CLI entry point for a real cron. The sweep is idempotent (only touches `status='active'`), so the throttle is a pure performance win.

### Files Modified
- **`actions/auto_terminate_members.php`** — rewritten:
  - `vk_required_contribution_total(array $settings, DateTime $now): float` — pure deadline math, now unit-testable.
  - `vk_run_auto_termination(PDO): int` — the sweep, returns members moved.
  - `vk_auto_termination_due()` / `vk_mark_auto_termination_ran()` — once-per-day throttle via a `group_settings` row `auto_termination_last_run` (PK upsert; no schema change).
  - Entry points: direct CLI run (cron) executes unconditionally; web include throttles to the first hit of the day. A `realpath($argv[0]) === __FILE__` guard keeps the file inert when PHPUnit loads it (no DB in tests).
- **`header.php`** — unchanged; still `include_once`s the file, which now self-throttles. Per-request cost drops from a full aggregate + writes to a single primary-key lookup.

### Files Created
- **`tests/Unit/AutoTerminationTest.php`** — 6 tests: deadline math (before first deadline, after deadline, previous-months-only, grace days, deadline-time boundary) + recurrence guard for the throttle/CLI entry point.

### Verification
- Live: marker absent → CLI sweep (`php actions/auto_terminate_members.php`) ran ("0 moved" — dev DB already swept, idempotent) → marker set to today → `vk_auto_termination_due()` now **false**, so header.php skips the heavy sweep for the rest of the day.
- Unit suite **637 / 1209**; `php -l` clean. (Optional cron: `0 1 * * * php /path/to/vikundi/actions/auto_terminate_members.php`.)
- Medium tier: M1 ⏳ · M2 ⏳ · M3 ✅ · M4 ✅ · M5 ✅ · M6 ✅.

---

## Session — 2026-06-26 — Audit fix M6
**Branch:** `fix/m6-password-policy`
**Developer:** Claude Code / Dutch
**Summary:** Audit Medium **M6** — weak/inconsistent password policy. `reset_password.php` allowed **6** chars, registration enforced **nothing**, and admin create/edit + profile-change required 8 but with **no complexity**. Centralized one policy and applied it to every server-side password path. The "verify `forgot_password.php` proves identity" half was a pass — it already requires **username + NIDA** to match before issuing the (1-hour, session-stored) reset token.

### Policy (single source of truth)
- **`includes/registration_validator.php`** — new pure `reg_password_errors($password, $lang)`: ≥ 8 chars **and** at least one letter **and** one digit; returns bilingual error messages (empty = OK). Balanced for the member base — stronger than "6, no complexity" without locking users out.

### Files Modified
- **`includes/registration_validator.php`** — add the helper; `validate_registration_input()` now runs it whenever a password is provided (registration previously had no strength check).
- **`actions/reset_password.php`** — replace `strlen < 6` with the central helper (+ require the validator).
- **`app/constant/settings/add_user.php`**, **`edit_user.php`** — replace `strlen < 8` with the helper (+ require the validator).
- **`app/constant/profile/profile.php`** — change-password path now uses the helper.

### Tests
- **`tests/Unit/RegistrationValidatorTest.php`** — +6 tests (strong passes; short / no-digit / no-letter rejected; Swahili messages; registration rejects a weak password). Bumped the base valid fixture `secret1` → `secret12` to satisfy the new policy.

### Verification
- Live (seeded reset session, non-existent user so 0 rows change): `abc123` (6 chars, previously allowed) → **rejected** "at least 8 characters"; `onlyletters` → **rejected** "must contain at least one number"; `secret12` → **accepted**. reset_password.php with no token → clean "session expired" JSON (no fatal).
- Unit suite **631 / 1201**; `php -l` clean on all touched files.
- Medium tier: M1 ⏳ · M2 ⏳ · M3 ✅ · M4 ⏳ · M5 ✅ · M6 ✅.

---

## Session — 2026-06-26 — Audit fix M5
**Branch:** `fix/m5-profile-auth-guard`
**Developer:** Claude Code / Dutch
**Summary:** Audit Medium **M5** — `profile.php` and `my_settings.php` read `$_SESSION['user_id']` at the top before any auth check (their `header.php` / auth gate runs much later or not at all), so an anonymous hit emitted `Undefined array key "user_id"` warnings and ran queries with a null user id (louder since B1). Added an HTML auth gate that redirects to login first.

### Files Created
- **`includes/require_login.php`** — central auth gate for HTML pages (sibling of `require_auth.php`, which serves JSON). No `$_SESSION['user_id']` → redirect to `getUrl('login')` + exit.
- **`tests/Unit/RequireLoginGuardTest.php`** — 3 tests: guard redirects/stops; both pages include the guard *before* the first `$_SESSION['user_id']` read.

### Files Modified
- **`app/constant/profile/profile.php`** — require the login guard right after `roots.php`, before any session use.
- **`app/constant/profile/my_settings.php`** — same.

### Verification
- Live (PHP built-in server, dev SAPI = display_errors on): both pages anonymous → **302 → /login**, zero `Undefined array key` warnings in the body (was: 200 + warnings).
- Unit suite **625 / 1193**; `php -l` clean.
- Medium tier: M1 ⏳ · M2 ⏳ · M3 ✅ (done in H4) · M4 ⏳ · M5 ✅ · M6 ⏳.

---

## Session — 2026-06-26 — Audit fix H6
**Branch:** `fix/h6-csrf-central-guard`
**Developer:** Claude Code / Dutch
**Summary:** Audit High **H6** — CSRF was verified on only ~3 endpoints. Built a reusable central CSRF guard plus app-wide token delivery, then enforced it on the financially/security-sensitive mutating endpoints (chosen scope: sensitive set + global plumbing, lower regression risk — mirrors how H3 was scoped). Remaining endpoints are a documented follow-up; the infra now makes wiring them a one-line add.

### Design
- **Server guard** `includes/require_csrf.php` (drop-in like `require_auth.php`): on unsafe methods (POST/PUT/PATCH/DELETE) requires a valid per-session token, else JSON **403** + exit. Safe methods pass through. Token read from the `X-CSRF-Token` header **or** the `csrf_token` POST field.
- **App-wide token delivery** (front-end uses both `$.ajax` *and* `fetch()`): `header.php` now emits `<meta name="csrf-token">` and installs two hooks that attach the token to same-origin state-changing requests automatically — a `window.fetch` wrapper (in `<head>`, before any body script) and a jQuery `$(document).ajaxSend` hook. So existing AJAX/fetch calls are covered with no per-call edits. Header maps to `$_SERVER['HTTP_X_CSRF_TOKEN']` (verified live).

### Files Created
- **`includes/require_csrf.php`** — central CSRF gate.
- **`tests/Unit/CsrfTest.php`** — 10 tests: token verify, safe-method gating, header-vs-field extraction, guard decision.
- **`tests/Unit/CsrfCoverageTest.php`** — recurrence guard: every protected endpoint keeps the guard; header.php keeps the meta + both delivery hooks.

### Files Modified
- **`includes/csrf.php`** — added pure helpers `csrf_is_safe_method()` and `csrf_extract_token()`.
- **`header.php`** — CSRF meta + fetch wrapper + jQuery `ajaxSend` hook.
- **17 endpoints** wired to `require_csrf.php` (after `require_auth.php` where present): `actions/` update_contribution, delete_death_expense, delete_petty_cash, process_death_expense, process_contribution, save_petty_cash, approve_death_expense, approve_petty_cash, update_user_role, update_user_status, approve_member; `api/account/` save_account, save_category, delete_account, delete_account_category, create_reconciliation, delete_reconciliation.
- **`app/bms/customer/submit_contribution.php`** — `csrf_field()` added (only native HTML form POST in the set; all others reach the endpoints via fetch/$.ajax and are covered by the hooks).

### Verification
- Live (PHP built-in server): tokenless POST → **403**; wrong token → **403**; valid `X-CSRF-Token` header + session → **200**; safe GET → **200**. Confirmed every protected endpoint's UI path (fetch / $.ajax / $.post / native form) actually carries the token before enforcing.
- Unit suite **622 / 1184**; `php -l` clean on all touched files.
- High tier complete: H1 ✅ · H2 ✅ · H3 ✅ · H4 ✅ · H5 ✅ · **H6 ✅**.
- **Follow-up:** extend `require_csrf.php` to the remaining ~220 mutating endpoints (infra + global token delivery already in place; each is now a one-line require + `csrf_field()` for any remaining native forms).

---

## Session — 2026-06-26 — Audit fix H5
**Branch:** `fix/h5-session-cookie-hardening`
**Developer:** Claude Code / Dutch
**Summary:** Audit High **H5** — session cookie hardening. `roots.php` set `samesite=Lax` but omitted `httponly` and `secure`, leaving `PHPSESSID` readable by JS (XSS session theft) and sendable over plain HTTP. Added both flags; `secure` is gated on a new testable HTTPS helper so plain-HTTP local WAMP dev still works.

### Files Modified
- **`includes/env.php`** — new `vikundi_is_https(?array $server = null)`: pure, override-able HTTPS detector (direct TLS / `HTTPS` not "off", port 443, `X-Forwarded-Proto: https` for reverse proxies). Mirrors the existing `vikundi_is_dev_host()` pattern.
- **`roots.php`** — session cookie params now set `httponly => true` and `secure => vikundi_is_https()` alongside the existing `samesite => 'Lax'`.

### Files Created
- *(none — tests appended to existing `tests/Unit/EnvTest.php`)*

### Tests
- **`tests/Unit/EnvTest.php`** — 4 new cases for `vikundi_is_https()` (direct TLS, port 443, forwarded-proto, and plain-HTTP-stays-false).

### Verification
- Live `Set-Cookie` over plain HTTP (PHP built-in server): `PHPSESSID=…; path=/; HttpOnly; SameSite=Lax` — `HttpOnly` present, `Secure` correctly **absent** (local dev unbroken), page **200**.
- Unit suite **608 / 1123**; `php -l` clean on both files.
- High tier: H1 ✅ · H2 ✅ · H3 ✅ · H4 ✅ · H5 ✅ · H6 ⏳ — next is **H6**.

---

## Session — 2026-06-25 — Audit fix H4 (+ M3)
**Branch:** `fix/h4-broken-db-include`
**Developer:** Claude Code / Dutch
**Summary:** Audit High **H4** — three dead action handlers `require '../includes/db.php'`, which **does not exist**, so they fatal if reached. The audit had over-stated this as "registration broken" — verified the **live** registration is healthy: the page `register.php` (HTTP 200) posts to `actions/process_registration.php`, which works. The broken files are all unreferenced legacy.

### Files Removed (3)
- **`actions/register.php`** — legacy registration handler, superseded by `process_registration.php`; not routed, not posted to.
- **`actions/register_customer.php`** — legacy; also wrote non-existent `customers` columns (the H2 drift item).
- **`actions/upload_attachments.php`** — unused + broken (this also closes Medium **M3**).

### Files Created
- **`tests/Unit/NoBrokenDbIncludeTest.php`** — asserts `includes/db.php` doesn't exist and that no file requires it (recurrence guard).

### Verification
- After removal: **no** file references `includes/db.php`; `/register` 200, `/login` 200. Unit suite **604 / 1114**.
- Removing these also clears the only "core" drift the H2 checker reported (`register_customer.php`).
- Next: **H5** (session cookie `httponly`/`secure`).

---

## Session — 2026-06-25 — Audit fix H3
**Branch:** `fix/h3-endpoint-authorization`
**Developer:** Claude Code / Dutch
**Summary:** Audit High **H3** — authorization. B2 revived the permission model and B3 added authentication (logged-in?). H3 adds *authorization* (allowed?) to the mutating endpoints that only checked login, so a regular member can't perform committee actions.

### Finding
Member/user endpoints (`add_member`, `update_user_role`, `update_user_status`, `approve_member`) **already authorize** via custom committee-role checks, and the `approve_*` endpoints already use `canApprove(...)`. The gaps were the B3-guarded endpoints (authenticate-only). Note: the `permissions` table has loan/BMS keys but lacks VICOBA keys (`death_expenses`, `manage_contributions`, `petty_cash`) — so `canX('<vicoba_key>')` correctly means **committee-only** (admin-bypass passes; others denied until a key is added & granted).

### Files Modified
- **`core/permissions.php`** — new `requirePermissionJson($action, $pageKey)` helper: JSON **403** + exit if the user lacks the permission (admins bypass via `isAdmin()`).
- **`actions/`** `delete_death_expense` (`delete`/death_expenses), `process_death_expense` (`create`/death_expenses), `update_contribution` (`edit`/manage_contributions), `delete_petty_cash` (`delete`/petty_cash) — added the permissions include + authz call.
- **`api/account/`** `save_account`/`save_category` (`edit`/chart_of_accounts), `delete_account`/`delete_account_category` (`delete`/chart_of_accounts), `create_reconciliation` (`create`/bank_reconciliation), `delete_reconciliation` (`delete`/bank_reconciliation).

### Files Created
- **`tests/Unit/EndpointAuthorizationTest.php`** — 11 tests (helper emits 403; each endpoint authorizes on the expected key).

### Verification
- **admin** → `delete_death_expense` **200** (passes); **member** → `delete_death_expense` and `api/account/save_account` **403** (denied). Unit suite **602 / 1112**; `php -l` clean.
- Next: **H4** (broken registration include `includes/db.php`).

---

## Session — 2026-06-25 — Audit fix H2
**Branch:** `fix/h2-schema-reconciliation`
**Developer:** Claude Code / Dutch
**Summary:** Audit High **H2** — systemic schema reconciliation. Built a checker that diffs every `INSERT` column-list in the codebase against the live DB. **Outcome: the VICOBA core is already reconciled** (the earlier B2 + death-expense fixes resolved the real drift). Remaining drift is **not** in core:
- **Unused BMS modules** (`brands`, `invoice_items`, `purchase_returns`, `warehouses`, `locations`, `deleted_expenses`) — e-commerce/inventory features the group doesn't use.
- **`register_customer.php`** writes legacy `customers` columns (`date_of_birth`, `phone_number`, `id_number`, …) — but it's a **dead/broken file** (the missing-`includes/db.php`, see H4); the live path `add_member.php` uses the correct columns.
- **Communication tables** (`email_logs`, `sms_logs`, `auto_reminder_logs`) are **self-creating** (`CREATE TABLE IF NOT EXISTS` in `email_helper.php`/`sms_helper.php`/`contribution_reminders.php`) — no missing-table impact.

### Files Created
- **`database/check_schema_drift.php`** — permanent drift guard: reports columns the code writes that don't exist in the DB; ignores the unused BMS tables to keep the signal on core. Pure, unit-testable parser `vikundi_extract_insert_columns()`; CLI scan guarded behind direct-invocation.
- **`tests/Unit/SchemaDriftCheckerTest.php`** — 4 tests for the parser.

### Files Modified
- **`composer.json`** — added `composer check-schema`.

### Notes
- The checker confirms the only "core" drift is the dead `register_customer.php` (handed to **H4**). Hiding/removing the unused BMS modules is a separate cleanup (relates to L3). Unit suite **591 / 1090**.
- Next: **H3** (authorization — permission checks on mutations, not just login).

---

## Session — 2026-06-25 — Audit fix H1
**Branch:** `fix/h1-fund-balance-ledger`
**Developer:** Claude Code / Dutch
**Summary:** Audit High **H1** — the group fund balance was not a real ledger. Three inconsistent numbers existed: the approval **gate** read `group_settings.group_balance` (only ever decremented, never credited by contributions); the **dashboard** computed `approved contributions − death_expenses(ALL statuses) − expenses(WHERE paid)` but summed the **wrong, empty `expenses` table** instead of `general_expenses` and counted pending/rejected deaths. The gate could block valid payouts or allow overspending.

### Decision (confirmed with group)
Available fund = **(approved contributions + paid fines) − (approved death + approved general expenses + approved petty cash + member payouts[approved/paid])**.

### Files Created
- **`includes/finance.php`** — single source of truth: `getGroupFundBalance(PDO)` (computed from live records, can't drift) + pure, testable `fundBalanceFromTotals(...)`.
- **`tests/Unit/FundBalanceTest.php`** — 4 tests for the arithmetic.

### Files Modified
- **`actions/approve_death_expense.php`** & **`api/approve_general_expense.php`** — gate now uses `getGroupFundBalance()`; removed the stale `group_balance` read+decrement (the fund derives from records, so approving auto-reduces it).
- **`app/dashboard.php`** — balance now uses `getGroupFundBalance()`; fixed expense totals to approved death + approved general (was: all-status death + the empty `expenses` table).

### Verification
- Computed balance matches records exactly (2,399,878 = 2,400,000 contrib − 122 pre-existing approved death). Over-budget approval **blocked** (status stayed `reviewed`); affordable approval **passed**. Unit suite **587 / 1082**; `php -l` clean.

### Notes
- The vestigial `group_settings.group_balance` setting is no longer authoritative (still read by `core/ai_insights.php` for AI context only — harmless).
- Known limitation: the gate read-modify lacks a hard concurrency lock; for a single-treasurer group this is low-risk (same as before, now at least always records-accurate). Can add row-locking later if needed.
- Next: **H2** (systemic schema reconciliation).

---

## Session — 2026-06-25 — Audit fix B4
**Branch:** `fix/b4-remove-webroot-debug-scripts`
**Developer:** Claude Code / Dutch
**Summary:** Audit Blocker **B4** — ~28 one-off debug/maintenance scripts sat at the web root, reachable and unauthenticated. Several were destructive (`set_balance.php` overwrote the fund to 1,000,000; `clear_expenses.php` deleted expenses; `fix_db_schema.php`, `setup_permissions.php`) and many leaked data/schema (`list_all_users.php`, `list_db.php`, `check_*`).

### Files Removed (28)
`add_col.php`, `check_accounts.php`, `check_banks.php`, `check_cols.php`, `check_customer_cols.php`, `check_customers_cols.php`, `check_db.php`, `check_death_cols.php`, `check_raw_users.php`, `check_roles.php`, `check_users_cols.php`, `clear_expenses.php`, `compare_counts.php`, `describe_docs.php`, `find_bank_accounts.php`, `find_route.php`, `fix_db_schema.php`, `get_tables.php`, `list_account_names.php`, `list_all_users.php`, `list_db.php`, `list_fields.php`, `list_tables.php`, `migrate_expenses.php`, `set_balance.php`, `setup_granular_permissions.php`, `setup_permissions.php`, `sync_members.php`.

### Files Created
- **`tests/Unit/NoWebRootDebugScriptsTest.php`** — fails if any of these scripts reappear at the web root.

### Verification
- All 28 confirmed **unreferenced** (no code include, no `roots.php` route, not in the deploy workflow) before removal. `deploy-hook.php` (the legit deploy webhook) was kept.
- After removal: site still serves (`/login` 200); deleted paths route to the login page (nothing executes). Unit suite **583 / 1078** green.

### Notes
- Recoverable from git history if a one-off is ever needed; proper migrations live in `database/migrate.php`.
- **Blocker tier COMPLETE** (B1–B4). Next tier: **High** (H1 fund-balance ledger, H2 schema reconciliation, H3 authz, H4 broken registration include, H5 cookie flags, H6 CSRF).

---

## Session — 2026-06-25 — Audit fix B3
**Branch:** `fix/b3-central-auth-guard`
**Developer:** Claude Code / Dutch
**Summary:** Audit Blocker **B3** — several state-changing endpoints ran with no login check, so anyone with the URL could mutate/delete financial data via POST.

### Approach
A single central gate instead of per-file ad-hoc checks.

### Files Created
- **`includes/require_auth.php`** — `require_once` at the top of an endpoint; if there's no `$_SESSION['user_id']` it emits a clean JSON **401** and `exit`s. Authentication only (authorization stays with the can* helpers).
- **`tests/Unit/EndpointAuthGuardTest.php`** — asserts the gate emits 401/exit and that each guarded endpoint includes it (11 tests).

### Files Modified (guard added)
- Actions: `delete_death_expense.php`, `update_contribution.php`, `process_death_expense.php`, `delete_petty_cash.php`.
- API: `api/account/save_account.php`, `delete_account.php`, `save_category.php`, `delete_account_category.php`, `create_reconciliation.php`, `delete_reconciliation.php`.

### Scope notes
- Confirmed by reading each file. Excluded: endpoints that already guard via the `$_SESSION['user_id'] ?? null; if (!$user_id)` idiom (`add_member`, `update_user_role`, `update_user_status`, `approve_member`); intentionally-public flows (login/register/reset); and cron/CLI scripts (`auto_terminate_members`, `calculate_penalties`, `contribution_reminders`) which need a CLI guard, not web-auth (tracked separately). `upload_attachments.php` is broken+dead → handled by M3.

### Notes
- Verified at `vikundi.localhost`: unauthenticated POST → **401** (action and API); authenticated → **200** (passes to the endpoint's own logic). Unit suite **582 / 1050** green; `php -l` clean.
- Next Blocker: **B4** (remove web-root debug/maintenance scripts).

---

## Session — 2026-06-25 — Audit fix B2
**Branch:** `fix/b2-rbac-review-approve-columns`
**Developer:** Claude Code / Dutch
**Summary:** Audit Blocker **B2** — RBAC was dead. `role_permissions` lacked `can_review`/`can_approve`, but `core/permissions.php` SELECTs them, so `loadUserPermissions()` threw on every request (`Unknown column 'rp.can_review'`, 11× in the log) and `$_SESSION['permissions']` was set to `[]` — every non-admin-bypass role got **zero** permissions.

### Root cause
`database/sync_workflow_columns.php` adds workflow *tracking* columns to transaction tables but its `$map` never included `role_permissions`, so the two permission flags were never created.

### Files Modified
- **`database/sync_workflow_columns.php`** — added `role_permissions => can_review, can_approve` (`TINYINT(1) NOT NULL DEFAULT 0`, matching existing `can_*`). Idempotent; runs via `migrate.php` on deploy, so production self-heals.

### Files Created
- **`tests/Unit/WorkflowColumnsMigrationTest.php`** — guards that the migration declares the two flags and that `core/permissions.php` still selects them (kept in lockstep).

### Notes
- Verified: migration adds both columns (idempotent); `loadUserPermissions()` no longer throws — admin loads **71 page-keys** (was 0); an authed login+dashboard produced **0** new `can_review` errors. Unit suite **571 / 1027** green.
- **Behaviour change:** with permissions now loading, the configured `role_permissions` grants (view/create/edit/delete) actually take effect for non-admin roles. `can_review`/`can_approve` default to 0 — granting them to committee roles is a config task in the Roles UI (admins still review/approve via bypass).
- Next Blocker: **B3** (central auth guard for unauthenticated endpoints).

---

## Session — 2026-06-25 — Audit fix B1
**Branch:** `fix/b1-disable-display-errors`
**Developer:** Claude Code / Dutch
**Summary:** Audit Blocker **B1** — `roots.php` forced `display_errors=1` on every request, leaking PHP errors to end users and corrupting AJAX/JSON responses (the "server connection failed" class).

### Files Created
- **`includes/env.php`** — dependency-free `vikundi_is_dev_host()`; returns true only for local/dev contexts (localhost, 127.0.0.1, ::1, `*.localhost`, `*.test`, CLI). Unknown/empty host ⇒ production (safe default).
- **`tests/Unit/EnvTest.php`** — 4 tests (dev hosts, production hosts, empty/unknown host, CLI; includes IPv6 `::1`).

### Files Modified
- **`roots.php`** — errors are always reported + logged, but **displayed only on dev hosts**; production never shows error text. Loads `includes/env.php` at the top of the front controller.

### Notes
- Verified: dev host (`vikundi.localhost`) still serves normally; unit suite **569 tests, 1022 assertions** green; `php -l` clean.
- Per-finding workflow: one Blocker per branch/PR. Next: **B2** (RBAC `role_permissions.can_review/can_approve`).

---

## Session — 2026-06-24
**Branch:** `fix/death-expense-schema-and-child-retention`
**Developer:** Claude Code / Dutch
**Summary:** Fixed two death-expense bugs that also affect production: (1) "Data truncated for column 'deceased_type'" when recording a **parent's** death, and (2) a **deceased child being removed** from the member's profile on approval.

### Root causes
- **Truncation:** schema drift. `death_expenses.deceased_type` was `enum('member','spouse','child')`, but `/api/get_member_dependents` emits `mwanachama|spouse|child|parent` — `parent` (and `mwanachama`) weren't allowed. Related drift: `customers.status`/`users.status` lacked `dormant`, and `customers.is_active` was missing — all written on a member's death approval.
- **Child vanishes:** `approve_death_expense.php` ran `unset($children[$idx])`, permanently deleting the deceased child from `children_data`.

### Files Created
- **`database/fix_death_expense_schema.php`** — idempotent, non-destructive migration: `deceased_type` → `VARCHAR(20)`; add `dormant` to `customers.status` + `users.status` (preserving existing values); add `customers.is_active`. Each step checks the schema first; safe to run on every deploy.
- **`tests/Unit/MarkChildDeceasedJsonTest.php`** — 4 tests for the retention helper.

### Files Modified
- **`database/migrate.php`** — registered `fix_death_expense_schema.php` so it runs on deploy (`.github/workflows/deploy.yml` → `php database/migrate.php`), applying the fix to **production automatically**.
- **`helpers.php`** — new `markChildDeceasedJson()` (pure, unit-tested): flags a child `is_deceased`/`deceased_date`, keeping the entry and sibling indexes.
- **`actions/approve_death_expense.php`** — child death now **marks the child deceased instead of deleting**; loads `helpers.php`.
- **`app/constant/profile/profile.php`** — read-only & edit views show a "Deceased / Marehemu" badge; profile save preserves the `is_deceased` flag (merged from stored data by index, so the edit form can't silently wipe it).

### Database Changes
- Applied locally via `migrate.php`; reaches prod through the deploy pipeline's `migrate.php` run. No manual DB surgery.

### Notes
- Verified end-to-end locally at `vikundi.localhost`: recording a **parent** death now succeeds (`deceased_type='parent'` stored); approving a **child** death retains the child flagged. Verification rows/balance reverted afterwards.
- Full unit suite green: **565 tests, 1008 assertions** (+4).
- **Scope:** spouse & parents are still erased (`NULL`ed) on death — unchanged existing behavior (user confirmed the spouse case "works fine"). Retaining-and-flagging them like children is an optional follow-up (needs small flag columns).
- Past approvals that already deleted a child cannot be recovered; this only prevents future loss.

---

## Session — 2026-06-23
**Branch:** `feat/i18n-json-translations`
**Developer:** Claude Code / Dutch
**Summary:** Introduced a centralized, file-based (JSON) i18n system to replace the scattered inline `($_SESSION['preferred_language'] ?? 'en') === 'sw' ? '<sw>' : '<en>'` ternaries. Stable-key design, no database. Migrated the **login** page and **dashboard** as the proven pattern; remaining pages to follow page-by-page.

### Files Created
- **`includes/i18n.php`** — i18n helper: `t($key, $vars)` (current-lang → English → key fallback, with `{placeholder}` interpolation), `et()` (HTML-escaped), `current_lang()`, `i18n_load()` (per-request static cache). Also handles a global `?lang=en|sw` switch (session only — no DB write).
- **`lang/en.json`** / **`lang/sw.json`** — 53 stable keys each (`common.*`, `login.*`, `dashboard.*`), full en/sw parity.
- **`tests/Unit/I18nTest.php`** — 7 tests: default/sw/invalid-language behaviour, missing-key→key fallback, placeholder interpolation, `et()` escaping, and en/sw key-parity guard.

### Files Modified
- **`roots.php`** — `require_once includes/i18n.php` right after `ROOT_DIR` is defined, so `t()`/`et()`/`current_lang()` are available on every page (pre-auth login included) and the `?lang=` switch is global.
- **`login.php`** — added an EN | SW language toggle (uses `?lang=`); replaced all hardcoded English with `et()`; moved JS strings (signing-in, errors, "coming soon") into a PHP-populated `I18N` object via `json_encode`; `<html lang>` now follows `current_lang()`.
- **`app/dashboard.php`** — replaced every inline UI ternary (alert banner, chips, collapse-toggle JS, quick actions, KPI cards/subs, chart + audit-log headers, table headers, empty/system fallbacks) with `t()`/`et()`; `fmt_time_ago()` now uses `t()` with `{n}` placeholders (dropped the `$is_sw` param). The date-driven month-name arrays were intentionally left in place (localized data, not UI chrome); the audit-log DB-content module translation was left untouched.

### Database Changes
- None. Translations live in JSON files; the per-user `preferred_language` column continues to persist a user's saved choice (set via the profile page, unchanged).

### Notes
- Verified at `http://vikundi.localhost`: login + dashboard render fully in both languages, no raw translation keys leak.
- Full unit suite green: **561 tests, 999 assertions** (was 554; +7 from `I18nTest`).
- Behaviour to be aware of: `actions/login.php` resets the session language to the user's saved profile preference on login, so a language picked on the login screen does not carry past login (the saved preference wins). The `?lang=` switch still works ad-hoc afterwards.
- Local-dev environment also set up this session: `includes/config.php` (dedicated `vikundi` MySQL user), DB built via `database/migrate.php` + RBAC seed import, served at `http://vikundi.localhost` through an Apache name-based VirtualHost (the `.htaccess` `RewriteBase /` requires a site root, not a `/vikundi` subdirectory).

---

## Session — 2026-06-22
**Branch:** `fix/register-member-language-mixing`
**Developer:** Claude Code / Wambura
**Summary:** Fixed mixed-language display in the "Register New Member" modal on `app/bms/customer/customers.php`. The modal scaffolding (tabs, headings, primary fields, buttons, success popup) was bilingual, but many detail-field labels were hardcoded in English and stayed English in Swahili mode, producing a mixed-language form.

### Changes
- **`app/bms/customer/customers.php`** — wrapped every hardcoded label in the existing `($_SESSION['preferred_language'] ?? 'en') === 'sw' ? '<sw>' : '<en>'` pattern:
  - Personal tab: Region of Birth, Marital Status (+ Single/Married/Widowed/Divorced options), Date of Birth, NIDA Number.
  - Parents: Father's/Mother's Name, Region/District, Ward/Village/Street, Phone Number.
  - Spouse: First/Middle/Last Name, Email, Phone, Gender (+ Select/Male/Female), DOB, Religion (+ Christianity/Islam/Other), NIDA Number, Region of Birth.
  - Children: table headers (S/NO, Child Name, Age, Gender), static + JS-generated gender options, "Add Child" button.
  - Guarantor: Name, Phone, Relationship, Region.
  - Account: Initial/Confirm Password, Admin role option, "Member can change password…" note.
  - JS row generators (`resetSpouseReligionAdmin`, `addChildRowAdmin`) also translated so dynamically-added rows stay bilingual.
  - Placeholders (example hints like "Full Name", "0xxxxxxxxx") intentionally left as-is.
- **New** `tests/Unit/CustomersRegistrationLanguageTest.php` (8 tests) — asserts the Swahili translations are present and the old English-only markup is gone (regression guard). `php -l` clean.

### Follow-up — mixed-language registration error popups
Reported: on an email-duplicate error the popup showed an English title with a Swahili body at once.
- **`actions/add_member.php`** — root cause: server error messages (email/phone already in use, slip required, required fields, not-logged-in, no-permission, DB error) were hardcoded Swahili-only, and the front-end paired them with a hardcoded English `'Error'` title. Added `$ui_lang` from `$_SESSION['preferred_language']` and made every admin-facing message bilingual. Also changed `$val_lang` to follow the **admin's UI language** (`$val_lang = $ui_lang`) instead of the new member's chosen account-language toggle (`$preferred_lang` is still used only to store the member's account language), so validation/dedup messages match the page the admin is viewing.
- **`app/bms/customer/customers.php`** — made the four SweetAlert popup titles/texts bilingual: server-error title (`Error`→`Hitilafu`), AJAX-error title, password-mismatch popup, and receipt-required popup.
- **New** `tests/Unit/AddMemberLanguageTest.php` (5 tests) + extended `CustomersRegistrationLanguageTest` with `test_error_popup_titles_are_bilingual`. Both files `php -l` clean.
- Full unit suite green: **554 tests, 986 assertions**.

---

## Session — 2026-06-21
**Branch:** `feat/admin-backup-restore` (registration hardening — to be split into its own branch)
**Developer:** Claude Code / Wambura
**Summary:** Hardened public member registration validation — specific (non-silent) error messages, plus CSRF, honeypot, phone normalization, NIDA/name/fee/child-age checks, terms enforcement and real-file-content checks. Password length intentionally left unchanged.

### Changes
- **New** `includes/csrf.php` — `csrf_token()`, `csrf_field()`, `csrf_verify()` (dependency-free session CSRF).
- **`includes/registration_validator.php`** — added `reg_normalize_phone()`, `reg_valid_name()`, `reg_valid_nida()`, `reg_file_mime()`; new rules: name format, member/spouse NIDA (20 digits, optional), entrance fee ≥ 0, child ages 0–120 (named by row), terms acceptance, and real MIME content checks for slip/photo (guarded so unit tests still run without real files). Every rule emits a specific EN/SW message.
- **`actions/process_registration.php`** — CSRF verify + bot honeypot gate at top; canonicalize email (lowercase) and phone (`reg_normalize_phone`) after validation so duplicate detection/storage are consistent.
- **`register.php`** — added `csrf_field()` + honeypot field; gave the terms checkbox a `name`/`value`; added Widowed/Divorced marital options (family section now shows for any non-Single status); aligned client slip-type check with server (JPG/PNG/PDF); added live + on-submit client validators (`checkName/checkNida/checkFee/checkChildAge`) that jump to the offending field with a specific message.
- **Tests** — extended `tests/Unit/RegistrationValidatorTest.php` (now 30 tests): names, NIDA, fee, child age, terms, phone normalization. Full suite green: **522 tests, 910 assertions**.
- **Not changed:** password length/strength (left as-is per request); nothing deleted.

### Follow-up — same rules imposed on internal "Register New Member" + profile Edit
- **`includes/registration_validator.php`** — added flags `requireTerms`, `requireSlip`, `requirePassword` (all default true, so public form unchanged). Lets the admin/edit forms reuse the SAME format rules while skipping checks they don't have (terms/slip/password).
- **`actions/add_member.php`** (internal Register New Member) — now CSRF-verified, calls `validate_registration_input(... requireTerms:false)` BEFORE moving uploads (no orphan files on failure), maps `initial_savings`→`entrance_fee`, and canonicalizes email/phone for dedup + storage.
- **`app/bms/customer/customers.php`** — added `csrf_field()`, named the confirm-password field, Widowed/Divorced marital options, and the same live client validators (`validateRegistrationFormAdmin`) with specific EN/SW messages + jump-to-field.
- **`app/constant/profile/profile.php`** (Edit form) — CSRF-verified, `validate_registration_input(... requireTerms:false, requireSlip:false, requirePassword:false)` for the entered parts, email/phone canonicalization, phone uniqueness (exclude-self), `csrf_field()` + live client validators (`validateProfileEditForm`).
- **Tests** — `RegistrationValidatorTest` now 34 tests (admin path, edit path, flag behaviour). Full unit suite green: **519 tests, 888 assertions**. Both new client IIFEs pass `node --check`.

### Follow-up — Batch Member Import hardening (`ajax/process_member_import.php` + `customers.php`)
- Per-row **format validation** using the shared helpers (`reg_valid_name/email/phone/nida`) — same rules as the interactive forms; email stays optional (validated only when present, matching the template).
- **Phone/email canonicalisation** (`reg_normalize_phone`, lowercase email) before duplicate checks and storage.
- **Email duplicate check** added (was phone-only); **intra-file duplicate** guard for phone & email.
- **CSRF** token on the import form + verify in the handler.
- **All errors reported** (was first-5) with row numbers and a total count; still all-or-nothing ("Nobody was imported").
- **Audit log** (`logCreate`) for every bulk-created member; generic DB-error message (no internals leaked, real error to `error_log`).
- **Unchanged / preserved:** all field mappings + inserts (users, customers, contributions), 'pending' status, and the `username@123` password scheme (kept per request). Marital-default + language changes also live: register.php/customers.php default Married; customers.php language flags fixed; profile edit gained Widowed/Divorced.

---

## Log Format

```
## Session [N] — YYYY-MM-DD
**Branch:** `branch-name`
**Developer:** Name / Claude Code
**Summary:** One-line description of what was done

### Changes
- Description of change 1
- Description of change 2

### Files Created
- `path/to/file` — purpose

### Files Modified
- `path/to/file` — what changed and why

### Database Changes
- Table/column added, modified, or removed (if any)

### Notes
Important context, decisions made, or follow-up items.
```

---

## Session 15 — 2026-06-20
**Branch:** `feat/comms-email-center`
**Developer:** Wambura Muhere / Claude Code
**Summary:** Wired up the **SMS** placeholder (comms > SMS) into a real, working SMS module — mirroring the email module — with actual gateway delivery (Beem Africa / Africa's Talking / Twilio / custom). Ideal for phone-only members.

### Why / verification-first
The old `send_sms()` only **simulated** success (its cURL call was commented out, and it inserted columns that don't exist in the loan-coupled `sms_alerts` table). Before relying on a gateway I probed reachability: apisms.beem.africa, api.africastalking.com, api.twilio.com all **reachable on :443**, and cURL is loaded — so **no new dependency** (SMS is HTTP, unlike email's PHPMailer).

### Changes
- **`includes/sms_helper.php`** rewritten into a provider-agnostic REAL sender: `sms_normalize_phone()` (TZ 255 default), `sms_segments()`, `sms_gateways()` presets (Beem/Africa's Talking/Twilio/Custom + bilingual help + required fields), `sms_ensure_logs_table()` (new `sms_logs` table, separate from the loan-centric `sms_alerts`), `sms_get_config()` (group 'sms', decrypts secrets via `core/ai_crypto.php`), `sms_send_via_gateway()` (per-provider cURL requests), `sms_send()` (real + honest log), and `send_sms()` kept as a backward-compatible wrapper. Removed the top-level `config.php` require so pure helpers are unit-testable.
- **SMS Settings page** `app/constant/settings/sms_settings.php` (admin-only): gateway preset dropdown that shows only the fields each gateway needs, encrypted API key/secret (masked + show/hide), Sender ID, enable switch, Save + **Send Test SMS**, status badge. Bilingual, ui-constants compliant.
- **Endpoints** `api/sms/save_settings.php` (encrypts key+secret, skips masked, admin-gated, audit) and `api/sms/test_connection.php` (sends a real test SMS).
- **SMS Center** `app/constant/communication/sms_center.php` + `api/sms_center.php`: compose/send/log mirroring the Email Center — AJAX recipient search by phone (members), 160-char/segment counter, status log DataTable, mobile cards, gear dropdown, bilingual. Actions: list/get/send/resend/delete/search_recipients.
- **Linking**: SMS menu placeholder now → SMS Center; SMS Settings in the Settings menu; admin Settings shortcut + a "set up SMS" prompt in the SMS Center.

### Files Created
- `includes/sms_helper.php` (rewrite), `app/constant/settings/sms_settings.php`, `app/constant/communication/sms_center.php`
- `api/sms/save_settings.php`, `api/sms/test_connection.php`, `api/sms_center.php`
- `tests/Unit/SmsHelperTest.php` (12), `tests/Unit/SmsSettingsTest.php` (15)

### Files Modified
- `roots.php` (page + 5 API/settings routes), `header.php` (SMS menu link + SMS Settings menu link)

### Database Changes
- New table `sms_logs` (self-healing via `sms_ensure_logs_table()`).
- New `system_settings` keys, group `'sms'`: `sms_provider, sms_api_key_enc (encrypted), sms_api_secret_enc (encrypted), sms_username, sms_sender_id, sms_base_url, sms_enabled`.

### Notes
- Full suite green: **507 tests, 867 assertions** (+27). All files `php -l` clean; both SMS pages render with zero warnings in EN + SW.
- Verified end-to-end: settings save (key+secret encrypted, no plaintext leak; decrypt round-trips), and `sms_send()` reaching **Beem's real API** returning *"Gateway HTTP 401: Invalid Authentication Parameters"* with dummy creds — proving the transport engages and will deliver with valid credentials. Honest failure logged to `sms_logs`. Dummy creds cleaned from the DB.
- No new Composer dependency (cURL + HTTPS already available).

---

## Session 14 — 2026-06-20
**Branch:** `feat/comms-email-center`
**Developer:** Wambura Muhere / Claude Code
**Summary:** Made email actually deliver. Researched how this system already does provider integrations (AI assistant: encrypted key, provider presets, Save + Test; SMS gateway table) and built a matching **Email Settings** page with real SMTP delivery via PHPMailer. Designed for low-tech, phone-only users: one admin sets it up once with provider presets, everyone else just composes.

### Why
"Sent to 0 recipient(s), N failed" was PHP `mail()` failing because WAMP has no MTA on localhost:25. Before installing anything I probed the environment: PHP 8.2 + openssl/mbstring/ctype/filter/curl present; **smtp.gmail.com:587 and :465 reachable (~130ms)**; localhost:25 refused. So SMTP via PHPMailer is viable here — install justified by evidence.

### Changes
- **PHPMailer** (`composer require phpmailer/phpmailer` → v7.1.1). App doesn't bootstrap Composer, so the autoloader is required explicitly and guarded with `class_exists` (graceful fallback to `mail()`).
- **`includes/email_helper.php`**: added `email_smtp_providers()` (Gmail/Outlook/Yahoo/Custom presets with host/port/encryption + bilingual help), `email_get_config()` (reads group `'email'`; decrypts SMTP password via existing `core/ai_crypto.php`), `email_send_smtp()` (PHPMailer transport), `email_save_setting()`. `email_send()` now uses SMTP when fully configured, else falls back to `mail()` with an honest "ask an admin to configure Email Settings" message.
- **Email Settings page** `app/constant/settings/email_settings.php`: admin-only; provider preset dropdown that auto-fills host/port/encryption (custom exposes advanced fields); email + masked password (show/hide); From name; enable switch; **Save** + **Send Test Email**; status badge (Active / Disabled / Not set up). Bilingual, blue/ui-constants compliant, mobile-friendly.
- **Endpoints**: `api/email/save_settings.php` (admin-gated; encrypts password with `aiEncryptSecret`; skips masked placeholder; audit) and `api/email/test_connection.php` (sends a real test email via `email_send`). Mirror the AI settings endpoints.
- **Linking**: Email Settings in the Settings menu (after AI Assistant); an admin "Settings" shortcut in the Email Center header; "Back to Email Center" on the settings page.

### Files Created
- `app/constant/settings/email_settings.php`, `api/email/save_settings.php`, `api/email/test_connection.php`
- `tests/Unit/EmailSettingsTest.php` (23)

### Files Modified
- `includes/email_helper.php` (SMTP transport + config + presets + save helper)
- `roots.php` (page + 2 API routes), `header.php` (Settings menu link), `app/constant/communication/email_center.php` (admin Settings shortcut)
- `composer.json` / `composer.lock` (phpmailer/phpmailer ^7.1)
- `database/email_logs.sql` (documents the SMTP setting keys)
- `tests/Unit/EmailHelperTest.php` (provider-preset tests)

### Database Changes
- New `system_settings` keys, group `'email'`: `email_provider, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password_enc (encrypted), mail_from_name, email_enabled`. Created on save; no migration required.

### Notes
- Full suite green: **480 tests, 801 assertions** (+23). All files `php -l` clean; settings page renders with zero warnings in EN + SW.
- Verified end-to-end: save (password stored encrypted, no plaintext leak; Gmail preset auto-fills host/port/tls; decrypt round-trips), and `email_send()` reaching Gmail and returning a real *"SMTP Error: Could not authenticate"* with dummy creds — proving the transport engages and will deliver with a valid App Password. Dummy creds cleaned from the DB after testing.
- `vendor/` is gitignored, so only `composer.json`/`composer.lock` are committed; deploy runs `composer install`.
- Low-tech note for users: Gmail needs a one-time App Password (the page explains this bilingually). Suggestion for later: WhatsApp/SMS as the primary channel for phone-only members.

---

## Session 13 — 2026-06-20
**Branch:** `feat/comms-email-center`
**Developer:** Wambura Muhere / Claude Code
**Summary:** Completed the email module to a professional standard — fixed the Email Center code-review findings, and finished the half-built **Email Templates** page (it was non-functional: stub list API, missing save/delete/test endpoints, no DB table, zero Swahili, native alerts, green cards). Rebuilt it fully bilingual + ui-constants compliant, gave it a real backend, and linked it to the Email Center.

### Changes
- **Email Center review fixes** (`email_center.php`): the View modal now renders the email body in a **sandboxed `<iframe srcdoc>`** instead of raw `.html()` — closes a stored-XSS hole. The Date column now sorts on the **raw timestamp** (orthogonal `render(d,type)`) instead of the localized string, so the log is in correct chronological order.
- **Select2 Bootstrap-5 theme** now loaded globally in `header.php` (ui-constants §UI-3 prescribes `theme:'bootstrap-5'`, but the theme CSS was never included — the option was a silent no-op everywhere).
- **Email Templates backend (was missing/stub):** real `get_email_templates.php` (data + stats), new `save_email_template.php` (create/update) and `delete_email_template.php`. All session + RBAC gated (shared `message_center` key), prepared statements, audit logging, bilingual.
- **Email Templates page rebuilt** (`email_templates.php`): fully bilingual (language picked once, server-side `$is_sw`), blue stat cards, SweetAlert2 (no more `alert()`/`confirm()`), gear-dropdown actions, mobile cards, Select2 type picker, client-side DataTable. Preview renders template HTML in a sandboxed iframe; "Send Test" reuses the Email Center send+log path.
- **Linking:** the Email Center compose modal gained a **"Use a Template"** picker that prefills subject/body from active templates (with overwrite confirmation). **Email Templates** added to the Communication menu.

### Files Created
- `api/save_email_template.php`, `api/delete_email_template.php` — template create/update + delete endpoints.
- `database/email_templates.sql` — canonical `email_templates` schema.
- `tests/Unit/EmailTemplatesApiTest.php` (9), `tests/Unit/EmailTemplatesPageTest.php` (12).

### Files Modified
- `includes/email_helper.php` — added `email_ensure_templates_table()` and bilingual `email_template_types()`.
- `api/get_email_templates.php` — replaced the 10-line empty stub with a real `{success,data,stats}` query (`active_only` filter for the picker).
- `app/constant/communication/email_center.php` — XSS + sort fixes, template picker.
- `app/constant/communication/email_templates.php` — full professional rebuild.
- `header.php` — Select2 BS5 theme; Email Templates menu item.
- `tests/Unit/EmailHelperTest.php`, `EmailCenterPageTest.php` — added coverage for the new helper, the XSS/sort fixes and the template picker.

### Database Changes
- New table `email_templates` (self-healing via `email_ensure_templates_table()`; also `database/email_templates.sql`).

### Notes
- Full suite green: **450 tests, 713 assertions** (was 424). All files `php -l` clean. Both pages render with **zero warnings in EN and SW**; language is picked once (no mixing).
- Verified end-to-end: template create / edit / delete with audit entries (Created/Updated/Deleted); list + stats; permission + auth gates; compose template picker feed.
- Deliberate call: kept the **blue** scale (ui-constants §UI-1 is binding) rather than the demo's green stat cards.
- The orphaned `test_email_config.php` / `setup_email_templates.php` routes are now unused (the rebuilt page tests via the Email Center send path); left as-is, no UI references them.

---

## Session 12 — 2026-06-20
**Branch:** `fix-deploy-yml`
**Developer:** Wambura Muhere / Claude Code
**Summary:** Wired up the **Email** item under Communication (comms > Email) into a working Email Center, modelled on the existing communication module and fully compliant with `.claude/ui-constants.md`.

### Changes
- The Communication menu's **Email** entry was a dead `href="#"` placeholder (added in commit b6a388b). Built it out into a real feature: compose & send email to members/staff, with a tracked email log.
- Reuses the existing `message_center` RBAC permission key (view/create/delete), so no new permission rows or role changes are required.
- `email_send()` writes every attempt to `email_logs` and records the **honest** outcome — on local WAMP with no MTA, `mail()` fails and is logged as `failed` rather than faked as sent.

### Files Created
- `includes/email_helper.php` — email helper (mirrors `sms_helper.php`). Pure DB-free functions `email_is_valid()`, `email_parse_recipients()`, `email_render_template()`; DB functions `email_ensure_logs_table()`, `email_get_settings()`, `email_send()`.
- `api/email_center.php` — JSON API (`list`, `get`, `recipients`, `send`, `resend`, `delete`); session + RBAC gated, prepared statements, audit logging.
- `app/constant/communication/email_center.php` — Email Center UI; ui-constants.md compliant (blue scale, stat cards `#e7f0ff`/`#b6ccfe`, DataTable `dom:'rtipB'`, Select2 recipient picker with tags, SweetAlert2, gear-dropdown actions, mobile card view, bilingual EN/SW, Bootstrap Icons).
- `database/email_logs.sql` — canonical schema for `email_logs` + optional `system_settings` email keys.
- `tests/Unit/EmailHelperTest.php` — 11 tests for the pure helper functions.
- `tests/Unit/EmailCenterPageTest.php` — 18 tests asserting UI-constants compliance, API security/audit, and route/menu wiring.

### Files Modified
- `header.php` — Email menu item now links to `getUrl('email_center')` instead of `#`.
- `roots.php` — registered page routes (`email_center`, `email_center.php`, `communication/email_center`) and API route (`api/email_center`).

### Database Changes
- New table `email_logs` (self-healing: created on demand by `email_ensure_logs_table()`; also in `database/email_logs.sql`).
- Optional `system_settings` keys (`enable_email_notifications`, `mail_from_name`, `mail_from_email`) — defaults applied in code, rows not required.

### Notes
- Full suite green: **424 tests, 650 assertions** (29 new). All new files `php -l` clean.
- Verified end-to-end via simulated authenticated requests: list, recipients (27 members + 28 staff), send (correctly logged as failed — no MTA locally), resend, delete; audit entries written; permission + auth gates reject unauthorized/unauthenticated callers; page renders with zero warnings for a real user.
- Follow-up (optional): when an SMTP/gateway is configured, swap the `mail()` transport in `email_send()` for PHPMailer; add an Email Templates picker to the compose modal (a `get_email_templates.php` stub already exists).

---

## Session 11 — 2026-05-06
**Branch:** `feat/mobile-expenses`
**Developer:** mbosso khani / Claude Code
**Summary:** Shared print footer created and deployed to all report pages; mobile card views added for audit-logs.php and general_expenses.php; professional print layout applied to general_expenses.php.

### Changes

#### Shared Print Footer — includes/print_footer.php
- New reusable bilingual footer for all printable pages
- Displays: printed-by username, role, date and time (live at print time)
- BJP Technologies copyright branding
- Only visible during print (`d-none d-print-block`), fixed to page bottom
- Registered as `PRINT_FOOTER_FILE` constant in `roots.php`
- Included in 6 report pages: `vicoba_reports.php`, `member_statement.php`, `expense_report.php`, `death_analysis.php`, `customer_analysis.php`, `financial_ledger.php`

#### Mobile Card View — audit-logs.php
- Added `d-none d-md-block d-print-block` to table wrapper
- Server-side `drawCallback` renders `#auditCardsWrapper` from current DataTable page data
- Cards show: action type badge (avatar), user, module, description, IP address, date
- `vkEscU` / `vkEscL` XSS helpers used throughout

#### Mobile Card View + Print Layout — general_expenses.php
- Mobile cards (`#generalExpenseCardsWrapper`) rendered via PHP loop
- Avatar colour: teal for General, red for Death benefit
- Rows: Category, Note, Amount; status badge in card header
- Professional print layout: actions column hidden on print, `@page { margin: 1cm }` applied
- Fixed `isSw` variable scope — moved to global scope so it is accessible inside all JS functions
- Fixed card rendering robustness and restored print visibility after earlier regression

### Files Created
- `includes/print_footer.php` — shared bilingual print footer (username, role, datetime, branding)

### Files Modified
- `roots.php` — registered `PRINT_FOOTER_FILE` constant
- `app/constant/reports/vicoba_reports.php` — include print footer
- `app/constant/reports/member_statement.php` — include print footer
- `app/constant/reports/expense_report.php` — include print footer
- `app/constant/reports/death_analysis.php` — include print footer
- `app/constant/reports/customer_analysis.php` — include print footer
- `app/bms/customer/financial_ledger.php` — include print footer
- `app/constant/accounts/audit_logs.php` — mobile card view + drawCallback
- `app/constant/accounts/general_expenses.php` — mobile card view + print layout fixes + isSw scope fix

### Database Changes
- None

### Notes
- Print footer relies on `$username` and `$user_role` globals set by `header.php` — must be included after header
- All existing unit tests continue to pass

---

## Session 10 — 2026-05-05
**Branch:** `feat/responsive-print-ui-tier4`
**Developer:** mbosso khani / Claude Code
**Summary:** Tier 4 responsive print UI — card views for 7 remaining pages (dormant members, budget, death analysis, financial ledger, user roles, users, manage contributions)

### Changes
- Added `.vk-member-card` card views (mobile-only, `d-md-none d-print-none`) to all 7 pages
- Added `d-none d-md-block d-print-block` to all affected `table-responsive` divs so tables stay visible on desktop and print
- Used `vk-cards-wrapper` class (2-column CSS grid at 480–767px) on all card containers
- Client-side DataTable files: PHP loop generates cards; `drawCallback` filters by search term; `data-search` attributes on cards
- Server-side AJAX files (`users.php`, `manage_contributions.php` ledger): `drawCallback` rebuilds cards from current page data using `vkEscU`/`vkEscL` XSS helpers
- `financial_ledger.php`: collects per-row summary into `$ledger_rows[]` array during table loop, then renders simplified cards (Total, Balance, Target, Surplus/Deficit) — per-month columns intentionally omitted from mobile view
- `user_roles.php`: card view added only to "User Assignments" tab; Roles list-group and Permissions Matrix left as-is (already mobile-friendly / too complex for cards)
- `manage_contributions.php`: two separate card views — pending approvals (PHP loop with Approve/Reject buttons) and ledger grid (server-side AJAX drawCallback)
- 35 new PHPUnit unit tests added in `ResponsivePrintTier4Test.php`

### Files Created
- `tests/Unit/ResponsivePrintTier4Test.php` — 35 unit tests covering badge logic, avatar colours, variance signs, date formatting, XSS safety

### Files Modified
- `app/bms/customer/dormant_members.php` — card view + filterDormantCards() + drawCallback
- `app/constant/accounts/budget.php` — card view + drawCallback search sync
- `app/constant/reports/death_analysis.php` — card view + drawCallback
- `app/bms/customer/financial_ledger.php` — $ledger_rows[] collection + simplified card view + drawCallback
- `app/constant/settings/user_roles.php` — card view for usersTable (User Assignments tab) + drawCallback
- `app/constant/settings/users.php` — server-side drawCallback + renderUsersCards() with full action buttons
- `app/bms/customer/manage_contributions.php` — pending PHP loop cards + ledger server-side drawCallback + renderLedgerCards()

### Database Changes
- None

### Notes
- `library.php` and `customer_analysis.php` skipped: library not found in codebase; customer_analysis is charts/stats only, no list table
- `financial_ledger.php` card shows summary only (no per-month columns) — same rationale as monthly analysis matrix in member_statement.php
- All 155 unit tests pass (composer test-unit)

---

## Session 9 — 2026-05-05
**Branch:** `feat/responsive-print-ui-tier2`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** Tier 3 responsive card view — member_statement.php (death benefit history), expense_report.php (consolidated expenses), loan_details.php (repayment schedule).

### Changes

#### Tier 3 — member_statement.php (Member Financial Statement)
- Monthly analysis grid (12+ columns) intentionally left as table — matrix layout is not suitable for cards
- Death Benefit History section: `<div class="table-responsive">` → `d-none d-md-block d-print-block`
- New `#deathBenefitCardsWrapper` PHP loop; red-gradient avatar (deceased initial); rows: Date, Amount; type badge in header

#### Tier 3 — expense_report.php (Consolidated Expense Report)
- `#expenseDetailTable` table-responsive → `d-none d-md-block d-print-block`
- New `#expenseReportCardsWrapper` PHP loop; teal avatar (G) for General, red avatar (D) for Death; rows: Note, Amount; date in header

#### Tier 3 — loan_details.php (Loan Repayment Schedule)
- Repayment schedule table-responsive → `d-none d-md-block d-print-block`
- New `#scheduleCardsWrapper` PHP loop; avatar colour: green (paid), red (overdue), blue (pending); rows: Deni, Ulipaji; status badge + overdue warning in header
- Left-column loan summary intentionally left unchanged — already a card-style responsive layout

### Files Created
- `tests/Unit/ResponsivePrintTier3Test.php` — 24 unit tests covering instalment badge logic, overdue detection, avatar colour selection, expense category labels, XSS escaping, number/date formatting (124 total tests, all pass)

### Files Modified
- `app/constant/reports/member_statement.php` — death benefit card view
- `app/constant/reports/expense_report.php` — consolidated expense card view
- `app/bms/loans/loan_details.php` — repayment schedule card view
- `sessions.md` — Session 9 entry

### Database Changes
- None

### Notes
- All three Tiers of responsive print UI are now complete
- Print always shows table (d-print-block on table-responsive, d-print-none on card wrapper)
- 2-column card grid activates at ≥480 px via .vk-cards-wrapper; single column on very small phones

---

## Session 8 — 2026-05-05
**Branch:** `feat/responsive-print-ui-tier2`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** Tier 2 responsive print UI — mobile card view for expenses.php, transactions.php, vicoba_reports.php; fixed card border visibility and added 2-column grid layout across all card views.

### Changes

#### Card UI Fixes (applied retroactively to Tier 1 output)
- `style.css` — darkened card border (`#e9ecef` → `#ced4da`, 1px → 1.5px), stronger shadow, visible row dividers, actions border; added `.vk-cards-wrapper` 2-column CSS Grid for screens ≥ 480 px with compact label/avatar overrides
- `app/bms/customer/customers.php` — added `vk-cards-wrapper` class to `#memberCardsWrapper`
- `app/bms/loans/loans_list.php` — added `vk-cards-wrapper` class to `#loanCardsWrapper`
- `app/constant/accounts/petty_cash.php` — added `vk-cards-wrapper` class to `#pettyCashCardsWrapper`

#### Tier 2 — expenses.php (Death Assistance)
- Table wrapper gets `d-none d-md-block d-print-block` — hides table on mobile
- New `#deathCardsWrapper` div (`d-md-none d-print-none vk-cards-wrapper`) holds mobile cards
- `drawCallback: renderDeathCards(api)` added to `#deathExpensesTable` DataTable config
- `renderDeathCards(api)` — builds red-gradient cards from server-side AJAX data (member_name, phone_number, deceased_name, deceased_relationship, amount, status); shows View/Approve(pending)/Delete buttons
- `vkEscape(s)` helper added for XSS-safe innerHTML insertion

#### Tier 2 — transactions.php (Journal Entries)
- `<div class="table-responsive">` gets `d-none d-md-block d-print-block`
- PHP loop `#transactionCardsWrapper` with 2-column grid; cards show Reference, Amount, Created By; status-dependent buttons: View, Edit+Post (draft), Reverse (posted), Delete
- `filterTransactionCards(searchVal, statusVal)` JS function filters cards by `data-search` and `data-status` attributes
- `applyFilters()` and `clearFilters()` updated to call `filterTransactionCards()`

#### Tier 2 — vicoba_reports.php (Group Reports)
- Savings table: `d-none d-md-block d-print-block`; `#savingsCardsWrapper` PHP loop shows Member Name, Total Savings with blue avatar
- Expenses table: `d-none d-md-block d-print-block`; `#expensesCardsWrapper` PHP loop shows Type, Date, Note, Amount; red avatar for Funeral Aid, purple for General

### Files Created
- `tests/Unit/ResponsivePrintTier2Test.php` — 30 unit tests covering status badges, safe_output XSS, format_currency, number_format, date formatting, htmlspecialchars, mb_substr truncation, and card search filter logic

### Files Modified
- `style.css` — card border/shadow improvements + 2-column grid
- `app/bms/customer/customers.php` — vk-cards-wrapper class
- `app/bms/loans/loans_list.php` — vk-cards-wrapper class
- `app/constant/accounts/petty_cash.php` — vk-cards-wrapper class
- `app/constant/accounts/expenses.php` — card view + renderDeathCards
- `app/constant/accounts/transactions.php` — card view + filterTransactionCards
- `app/constant/reports/vicoba_reports.php` — savings + expenses card views

### Database Changes
- None

### Notes
- Tier 3 remaining: member_statement.php, expense_report.php, loan_details.php
- All 100 unit tests pass (composer test-unit)
- 2-column grid activates at ≥480 px; single column on very small phones (<480 px)
- Print always shows table, cards always hidden in print (`d-print-none`)

---

## Session 7 — 2026-05-05
**Branch:** `fix/webhook-deploy`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** Switched deployment to PHP webhook — FTP passive mode ports are blocked by hosting provider firewall, causing upload failure and .htaccess corruption (site 403). Webhook calls server via HTTPS port 443 (always open), which runs `git pull origin main` directly.

### Root Cause of FTP Failure
- FTP control connection (port 21) succeeded, but data connections use random high ports (passive mode)
- Hosting provider firewall blocks inbound connections on those ports from external IPs (GitHub Actions)
- FTP upload started but data channel timed out → partial/zeroed .htaccess → Apache 403 on all requests

### Immediate Fix (manual — .htaccess restoration)
- .htaccess was corrupted by partial FTP upload
- User must restore via cPanel File Manager → public_html/vikundi/.htaccess → Edit → paste correct content → Save → chmod 644
- Correct .htaccess content is in the repo at `.htaccess` (version in git is authoritative)

### Changes
- `deploy.yml` — replaced FTP deployment with HTTPS webhook call:
  - POST to `DEPLOY_HOOK_URL` with `Authorization: Bearer <DEPLOY_HOOK_SECRET>`
  - Server responds 200 on success, 500 on git pull failure
  - Still blocks on Job 1 (tests must pass before deploy)
  - Smoke-tests live URL after webhook succeeds
- `deploy-hook.php` — new webhook receiver script:
  - Validates `Authorization: Bearer <token>` against `/home/bjptechn/.deploy-secret` (outside web root)
  - Runs `git fetch origin main && git reset --hard origin/main`
  - Logs output to `/home/bjptechn/.deploy-log`
  - Returns plain-text 200 OK or 500 FAILED

### Files Created
- `deploy-hook.php` — HTTPS webhook receiver for GitHub Actions deploys

### Files Modified
- `.github/workflows/deploy.yml` — webhook deployment (replaces broken FTP approach)
- `sessions.md` — Session 7 entry

### GitHub Secrets to update
Remove old secrets (no longer needed): `FTP_HOST`, `FTP_USERNAME`, `FTP_PASSWORD`
Add new secrets:
  - `DEPLOY_HOOK_URL`    → https://vikundi.bjptechnologies.co.tz/deploy-hook.php
  - `DEPLOY_HOOK_SECRET` → any strong random string (e.g. output of `openssl rand -hex 32`)

### One-time server setup required
1. In cPanel File Manager, navigate to `/home/bjptechn/` (one level above public_html)
2. Create a new file named `.deploy-secret`
3. Edit it — paste in the same random string you set as `DEPLOY_HOOK_SECRET` in GitHub
4. Save and set permissions to **600** (owner read-only)
5. Verify git is set up: cPanel → Git Version Control → confirm vikundi repo is listed and tracking `main`

### Notes
- The webhook script uses `git reset --hard origin/main` (not just `git pull`) to guarantee the server matches GitHub exactly, even if someone edited files directly on the server
- The secret file at `/home/bjptechn/.deploy-secret` is outside the web root — cannot be accessed via HTTP
- Deploy log at `/home/bjptechn/.deploy-log` — check this if a deploy fails
- `.cpanel.yml` has a hardcoded path `bjptech` (old username) — it is not executed by this approach so it doesn't matter, but clean up when convenient
- `deploy-hook.php` is excluded from the deploy-gate.yml syntax checks? No — it IS included, so it must be valid PHP (it is)

---

## Session 6 — 2026-05-05
**Branch:** `fix/ftp-deploy`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** Switched deployment method from cPanel UAPI to FTP — cPanel API tokens are blocked at the hosting provider firewall level.

### Changes
- `deploy.yml` — replaced cPanel UAPI approach with `SamKirkland/FTP-Deploy-Action@v4.3.4`
  - Connects via FTP port 21 (always open on shared hosting)
  - Uploads only files that changed since last deploy (state tracked in `.ftp-deploy-sync-state.json` on server)
  - Excludes: `includes/config.php`, `vendor/`, `uploads/`, `backups/`, `documents/`, `downloads/`, `tests/`, `.claude/`, `.github/`, `phpunit*`, `composer.*`, `coverage/`, `sessions.md`, `CLAUDE.md`, `.cpanel.yml`
  - Smoke-tests the live URL after deploy
- `.gitignore` — added `.ftp-deploy-sync-state.json` (state file created by FTP deploy action)

### Files Modified
- `.github/workflows/deploy.yml` — FTP deployment (replaces broken cPanel API approach)
- `.gitignore` — exclude FTP state file
- `sessions.md` — Session 6 entry

### GitHub Secrets to update
Remove old secrets (no longer needed): `CPANEL_API_TOKEN`, `CPANEL_HOSTNAME`
Add new secrets:
  - `FTP_HOST`     → bjptechnologies.co.tz (or ftp.bjptechnologies.co.tz)
  - `FTP_USERNAME` → bjptech
  - `FTP_PASSWORD` → your cPanel account password

### Notes
- Root cause: cPanel UAPI port 2083 is open but returns "Access denied" for API tokens — hosting provider restricts API token access to browser sessions only
- FTP (port 21) is universally open on all cPanel shared hosting plans
- The FTP action maintains a `.ftp-deploy-sync-state.json` on the server so only changed files are uploaded on subsequent deploys (first deploy uploads everything)

---

## Session 5 — 2026-05-05
**Branch:** `fix/cpanel-deploy-endpoint`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** Fixed cPanel deploy workflow — wrong API endpoint and improved auth error detection.

### Changes
- `deploy.yml` — two fixes:
  1. **Wrong endpoint:** `/execute/VersionControl/update` → `POST /execute/VersionControlDeployment/create` with `repository_root` parameter. The old endpoint updates repo settings, not trigger a pull. The correct one triggers git pull + `.cpanel.yml` tasks.
  2. **Better auth error detection:** Added explicit guard for plain-text "Access denied" response (means token NAME was used instead of token VALUE). Prints a clear fix message and exits 1.
  3. Added "Check secrets are configured" step that fails fast if any secret is missing.
  4. `repository_root` now uses `CPANEL_USERNAME` secret dynamically (`/home/CPANEL_USERNAME/public_html/vikundi`) instead of hard-coded path.

### Files Modified
- `.github/workflows/deploy.yml` — correct endpoint + auth error guard
- `sessions.md` — Session 5 entry

### Database Changes
- None

### Notes
- Root cause of "Access denied": token NAME ("github-deploy") was saved as the secret instead of the token VALUE (long alphanumeric string shown only at creation time)
- Fix: revoke token in cPanel → recreate → copy the VALUE immediately → update CPANEL_API_TOKEN secret in GitHub

---

## Session 4 — 2026-05-05
**Branch:** `chore/trigger-deploy-verification`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** Deployment verification trigger — re-runs the full deploy pipeline after cPanel API token was correctly saved in GitHub Secrets.

### Changes
- sessions.md updated to record this session and trigger the deploy workflow

### Files Modified
- `sessions.md` — Session 4 entry (this entry)

### Database Changes
- None

### Notes
- First deploy attempt failed because the cPanel API token was not saved before the workflow ran
- Token has now been saved correctly in GitHub → Settings → Secrets → `CPANEL_API_TOKEN`
- This PR exists solely to trigger a clean end-to-end deploy run: tests → cPanel pull → site verification

---

## Session 3 — 2026-05-05
**Branch:** `chore/cpanel-deploy-workflow`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** GitHub Actions → cPanel SSH deployment pipeline. Pushes to `main` automatically deploy to `/home/bjptech/public_html/vikundi` after tests pass.

### Changes
- Created `.github/workflows/deploy.yml` — two-job deployment pipeline:
  - **Job 1 (test):** Re-runs full PHPUnit suite (Unit + Feature) on the main branch commit — a failing commit cannot reach production
  - **Job 2 (deploy):** Blocked on Job 1. SSHes into cPanel server, runs `git reset --hard origin/main`, runs `composer install --no-dev --optimize-autoloader`, checks `config.php` exists, sets file permissions on `uploads/`, `documents/`, `backups/`
  - GitHub Environments configured (`production`) — shows deployment status in GitHub repo
  - Deployment summary written to GitHub Actions step summary (commit SHA, actor, timestamp)

### Files Created
- `.github/workflows/deploy.yml` — CI-gated SSH deployment workflow

### Files Modified
- `sessions.md` — Added Session 3 entry (this entry)

### Database Changes
- None

### One-time server setup required (see instructions given to user)
1. SSH into cPanel → clone repo or init git in `/home/bjptech/public_html/vikundi`
2. Run `composer install --no-dev` on server once (creates `composer.phar` or uses system composer)
3. Generate `~/.ssh/github_deploy` key pair on server
4. Add public key to `~/.ssh/authorized_keys`
5. Add 4 GitHub Secrets: `DEPLOY_SSH_KEY`, `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PORT`
6. Create a `production` GitHub Environment in repo Settings → Environments (optional but recommended for protection rules)

### Notes
- `includes/config.php` is NOT in git and is never touched by the cPanel pull — it is safe
- `vendor/` is not needed in production (app uses direct requires, not Composer autoloader) — no composer step needed on server
- Deploy uses cPanel UAPI (`/execute/VersionControl/update?name=vikundi`) — no SSH required
- `.cpanel.yml` runs post-pull tasks on the server (permissions, config.php safety check)
- Production URL updated to `https://vikundi.bjptechnologies.co.tz`
- If SSH becomes available later, the workflow can be switched to the SSH approach for more control
- cPanel API port 2083 is used (SSL); if host blocks it try port 2082 (non-SSL)

---

## Session 2 — 2026-05-05
**Branch:** `chore/github-actions-ci`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** GitHub Actions CI/CD workflows — automated PHPUnit test runs on every PR and a deployment quality gate before merging to main.

### Changes
- Created `.github/workflows/` directory with three workflow files
- `ci.yml` — runs on every push to working branches and PRs to `develop`/`main`
  - Job 1: **Unit Tests** (PHP 8.2) — fast gate, no database required
  - Job 2: **Feature Tests** (PHP 8.2) — runs after unit tests pass; MySQL 8.0 service pre-wired and ready to enable for future DB-dependent feature tests
  - Composer dependency caching between runs for speed
- `deploy-gate.yml` — runs only on PRs targeting `main` (deployment quality gate)
  - Validates `composer.json`
  - Checks `includes/config.php` is NOT committed (credentials guard)
  - Checks `.env` files are NOT committed
  - PHP syntax check across all application files (excludes `vendor/`, `TCPDF/`)
  - Full PHPUnit test suite (Unit + Feature)
  - Warns (non-blocking) if `sessions.md` was not updated in the PR
  - Confirms `vendor/` is gitignored
- `pr-labeler.yml` — auto-labels PRs based on branch prefix (`feat/` → feature, `fix/` → bug, `chore/` → chore, `hotfix/` → hotfix, etc.)

### Files Created
- `.github/workflows/ci.yml` — PHPUnit CI pipeline (unit + feature jobs)
- `.github/workflows/deploy-gate.yml` — Pre-merge checks for main branch
- `.github/workflows/pr-labeler.yml` — Automatic PR label applicator
- `phpunit.coverage.xml` — Separate PHPUnit config for local coverage reports (requires Xdebug/PCOV)
- `composer.lock` — Locked dependency versions (PHPUnit 11.5.55 + 26 transitive packages)

### Files Modified
- `phpunit.xml` — Removed `<source>` and `<coverage>` blocks; these triggered a "No code coverage driver" PHPUnit warning (exit code 1) that would have failed CI steps. Moved to `phpunit.coverage.xml`.
- `composer.json` — Updated `test-coverage` script to use `-c phpunit.coverage.xml`
- `sessions.md` — Added Session 2 entry (this entry)

### Database Changes
- None

### Notes
- **To use the MySQL service in Feature tests:** un-comment the "Create CI database config" and "Import database schema" blocks in `ci.yml`'s feature-tests job. You will also need a `database/vikundi.sql` schema dump that creates all tables cleanly.
- **PR labels:** Create these labels in GitHub → Issues → Labels before they auto-apply: `feature`, `bug`, `chore`, `hotfix`, `documentation`, `refactor`, `tests`
- **composer.lock:** Run `composer install` locally once you have PHP 8.2+, then commit the generated `composer.lock`. This makes CI builds fully reproducible and faster (cache hits on exact versions).
- Trigger: CI will fire on the next push to any `feat/**`, `fix/**`, `chore/**`, `hotfix/**` branch, and on every PR to `develop` or `main`.

---

## Session 1 — 2026-05-05
**Branch:** `chore/project-scaffolding`
**Developer:** Claude Code (wamburamuhere@gmail.com)
**Summary:** Initial project scaffolding — git setup, GitHub branches, documentation, PHPUnit test suite, and custom Claude Code skills/agents.

### Changes
- Initialized git repository and pushed to GitHub (`https://github.com/wamburamuhere-afk/Vikundi.git`)
- Established `main` as the deployment (king) branch — `git branch -M main`
- Created `develop` as the integration branch (branched from main)
- Created `chore/project-scaffolding` as the working branch for this session (branched from develop)
- Codebase fully committed to `main` (application files), scaffolding on working branch

### Files Created
- `README.md` — Full project README: features, stack, installation guide, contributing workflow
- `CLAUDE.md` — Claude Code context file: architecture, patterns, branching rules, commands
- `sessions.md` — This file (session tracking log going forward)
- `.gitignore` — Excludes: `includes/config.php`, `uploads/`, `backups/`, `downloads/`, `documents/`, `vendor/`, `scratch/`, IDE files, coverage output
- `includes/config.example.php` — Database config template (safe to commit; copy to `config.php` locally)
- `composer.json` — PHPUnit 11 dependency + `composer test` / `test-unit` / `test-feature` / `test-coverage` scripts
- `phpunit.xml` — PHPUnit configuration: Unit + Feature test suites, bootstrap, colors
- `tests/bootstrap.php` — Test bootstrapper: stubs `redirectTo()` + `isAuthenticated()`, starts PHP session, loads helpers + permissions
- `tests/Unit/HelpersTest.php` — 30+ unit tests covering: `calculateTotalInterest()`, `addMonthsWithAnchor()`, `get_status_badge()`, `format_currency()`, `format_date()`, `calculate_leave_days()`, `format_phone()`, `safe_output()`, `get_variance_color()`, `format_number()`
- `tests/Unit/PermissionsTest.php` — 20+ unit tests covering: `isAdmin()`, `canView()`, `canCreate()`, `canEdit()`, `canDelete()`, `getPermissionSummary()`, `arePermissionsLoaded()`
- `tests/Feature/AuthTest.php` — Feature test placeholder: auth session checks, redirect stub verification
- `.claude/commands/db-backup.md` — Skill: create timestamped MySQL database backup
- `.claude/commands/run-tests.md` — Skill: run PHPUnit test suite and report results
- `.claude/commands/new-feature.md` — Skill: scaffold a new feature with boilerplate + test files
- `.claude/commands/deploy-check.md` — Skill: pre-deployment checklist before merging to main
- `.claude/agents/vicoba-reviewer.md` — Agent: VICOBA-aware code reviewer (security, RBAC, audit logging, patterns)
- `.claude/agents/test-writer.md` — Agent: PHPUnit test writer specialized in Vikundi codebase

### Files Modified
- None (initial scaffolding session)

### Database Changes
- None

### Notes
- `includes/config.php` is in `.gitignore` — every developer must copy from `config.example.php`
- All new features going forward require tests in `tests/Unit/` or `tests/Feature/`
- Branching rules: `feat/*` / `fix/*` / `chore/*` / `hotfix/*` → PR to `develop` → PR to `main`
- User will manually PR `chore/project-scaffolding` → `develop` → `main`
