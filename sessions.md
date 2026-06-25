# Sessions Log — Vikundi VICOBA Management System

This file tracks every development session, modification, and significant change made to the project. Update it at the end of every session before pushing.

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
