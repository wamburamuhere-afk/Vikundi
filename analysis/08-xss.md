# 08 — Output Escaping and XSS

_Supplementary analysis pass. Read-only: no source file was modified, no database was touched, no
request was sent to the live host. The only executions were PHP one-liners against `helpers.php` in
isolation, to test escaping behaviour rather than infer it._

_Finding prefix: `XSS`. Map references cited as `MAP §N`._

---

## Scope

**Read in full or in the cited range**

- `helpers.php:688-690` (`safe_output`), `:692-697` (`mkoba_display_ref`)
- `includes/document_sanitizer.php:72-96` — the HTMLPurifier configuration
- `includes/registration_validator.php:23,46,142-165` — `reg_valid_name` and its call sites
- `actions/process_registration.php` (write path, 342 lines — grepped for every sanitiser)
- `actions/process_contribution.php:9-34,71-72`, `actions/process_death_expense.php:10,43-65`
- `actions/fetch_pending_members.php:53-101` — the member-approvals feed
- `api/get_death_expenses.php:26,51-84` — the funeral-support feed
- `app/constant/accounts/death_expenses.php:251-270`, `app/constant/accounts/expenses.php:476-495`
- `app/bms/customer/customer_group_details.php:308-356`, `customer_group_members.php:92-98,220,287,363`
- `app/bms/customer/customer_details.php:46-51,98,150`, `customers.php:224-300`
- `app/bms/customer/transactions.php:367-377`, `contribution_view.php:128-136`,
  `print_contribution.php:130-138`, `customer_documents.php:60,71,535`
- `app/constant/profile/profile.php:13-20,95,108-128,192-230`
- `app/constant/document/view_document.php:178`, `edit_document.php:147`, `edit_writer_template.php:70`
- `actions/save_document.php:10,38`, `actions/save_writer_template.php:10,28`
- `includes/role_grants.php:24-31,79`
- `.htaccess`, `header.php`, `footer.php`, `roots.php`, `includes/*.php` — grepped for security headers

**Generated inventories**

| Scan | Result |
|---|---|
| `echo`/`<?=` of a bare variable with no escaping on the line | **892** sites across 110 files (MAP §7.3 reported ~3,214 raw echo sites; the difference is that this count excludes sites escaped inline) |
| The same, restricted to member/free-text column names on **live** pages | 102 sites |
| `htmlspecialchars(` total / with explicit `ENT_QUOTES` / bare | 683 / 2 / 350 |
| `safe_output(` | 348 |
| Inline event handlers interpolating PHP into a **JS string** | 11 |
| DataTables `render:` **arrow + template literal** producing HTML with `${…}` | 45 (live pages only) |
| DataTables `render: function(){…}` concatenating unescaped data | 0 |
| Pages using `serverSide` DataTables | 26 |

**Not read** (RoE 5): `TCPDF/`, `vendor/`, `backups/`, `uploads/`, `documents/`, `downloads/`,
`assets/`, `*.min.*`. The dead `app/bms/{pos,product,stock,loans,grn,Suppliers,purchase,sales,invoice}`
tree was excluded from all live-page scans — ARCH-007 established 31 of its 33 files are unroutable.

**Prior-art check** performed against `analysis/02-security.md`, `analysis/07-i18n.md`,
`VIKUNDI_ANALYSIS_SYNTHESIS.md` and `Vikundi_Audit_Findings.md`. XSS is unreported in all four;
`Vikundi_Audit_Findings.md:95` claims "No raw echo of `$_GET/$_POST/$_REQUEST`", which is a
different and much narrower claim than output escaping, and is not contradicted here.

---

## Findings

### [XSS-001] Funeral-support and expense grids render API data into HTML with no escaping on either side
- **Severity:** S1
- **Confidence:** confirmed
- **Location:** `app/constant/accounts/death_expenses.php:259-262`, `app/constant/accounts/expenses.php:479-482`; feed at `api/get_death_expenses.php:60-61,21,33-39`
- **Source:** `death_expenses.deceased_name`, `deceased_relationship`, `phone_number` — free text written by `actions/process_death_expense.php:43-65`, which gates on `requirePermissionJson('create','death_expenses')` (leadership). Plus `member_name`, built at `api/get_death_expenses.php:61` from `customers.first_name`/`last_name`, which the **member import writes with no validation whatsoever** (`ajax/process_member_import.php`, `includes/member_import.php` — neither contains `reg_valid_name`, `preg_match`, `strip_tags` or any escaper).
- **Sink:** HTML body, via a DataTables `render` template literal. DataTables assigns the return value as cell HTML.
- **Evidence:**
  ```js
  { data: 'member_name', render: d => `<strong>${d}</strong>` },
  { data: 'phone_number', render: d => `<span class="badge bg-light text-dark border">${d}</span>` },
  { data: 'deceased_relationship', render: d => `<span class="small text-muted">${d}</span>` },
  ```
  The feed does no escaping either — `api/get_death_expenses.php:21,33-39` is `fetchAll(PDO::FETCH_ASSOC)` straight into `json_encode`.
- **Impact:** A stored `<img src=x onerror=…>` in any of those columns executes in the browser of every user who opens the funeral-support or expenses screen — which is where leadership reviews and approves payouts, so a chairperson is the expected victim. With `httponly` cookies the payload cannot steal the session, but it runs *inside* it: it can issue authenticated requests as the chairperson (approve an expense, change a role, trigger a database backup via `api/create_backup.php`), read anything the page renders, and POST it to a remote host. `deceased_name` at `:261` has no `render` at all, so it is inserted by DataTables' default path with the same result. Rated S1 rather than S0 because every write path into these columns currently requires leadership privilege or the leadership-run import — not an ordinary member. It becomes S0 the moment any member-writable field is added to this grid, or if the import is ever exposed more widely.
- **Fix effort:** trivial (<1h)
- **Fix sketch:** Apply the escaping helper this codebase already has — `txnEsc` in `app/bms/customer/transactions.php:367-377` escapes every free-text column and is the correct model — to all four columns on both pages.

---

### [XSS-002] `addslashes()` on an HTML-escaped value inside an `onclick` JS string is not an escape
- **Severity:** S1
- **Confidence:** confirmed
- **Location:** `app/bms/customer/customer_group_details.php:354` (value built at `:310-312`), `app/bms/customer/customer_group_members.php:287` (value built at `:92-98`, `:220`)
- **Source:** `customers.first_name` / `last_name` / `company_name`. Constrained for public registration (see XSS-007) but **unconstrained via the member import**, and `company_name` is not covered by `reg_valid_name` on any path.
- **Sink:** JavaScript string literal, inside a double-quoted HTML event-handler attribute — two nested contexts, escaped for neither.
- **Evidence:**
  ```php
  onclick="removeMember(<?= $group_id ?>, <?= $member['customer_id'] ?>, '<?= addslashes($customer_name) ?>')"
  ```
  where `$customer_name = safe_output(...)` at `:310-312`.
- **Impact:** `safe_output()` turns `'` into `&#039;`, so `addslashes()` then finds no quote to escape and is a no-op. The HTML parser decodes entities in an attribute value *before* handing it to the JavaScript engine, so `&#039;` becomes a live `'` in JS source. Demonstrated by execution:

  | Stage | Value |
  |---|---|
  | attacker input | `';alert(document.domain);//` |
  | after `safe_output()` | `&#039;;alert(document.domain);//` |
  | after `addslashes()` | `&#039;;alert(document.domain);//` (unchanged) |
  | what the JS engine receives | `removeMember(1, '';alert(document.domain);// ')` |

  The string closes empty, `alert()` runs as a statement, `//` comments out the remainder. HTML-escaping is simply the wrong encoding for this context, and the `addslashes()` that looks like the JS-side defence is defeated by the entity decoding that happens first.
- **Fix effort:** trivial (<1h)
- **Fix sketch:** Emit the value with `json_encode()` into the JS argument position and drop both `safe_output` and `addslashes` there, or attach the handler with `data-` attributes read via `dataset` instead of building JS source in markup.

---

### [XSS-003] The same context error at three more `onclick` sites, via `htmlspecialchars()`
- **Severity:** S2
- **Confidence:** confirmed
- **Location:** `app/constant/accounts/chart_of_accounts.php:136`, `app/bms/customer/customer_documents.php:535`
- **Source:** `account_categories.category_name` (written by `api/account/save_category.php`, leadership); `documents.document_name` (written on upload).
- **Sink:** JS string literal inside a double-quoted `onclick`.
- **Evidence:**
  ```php
  onclick="deleteCategory(<?= $category['category_id'] ?>, '<?= htmlspecialchars($category['category_name']) ?>')"
  onclick="confirmDelete(<?= $doc['id'] ?>, '<?= htmlspecialchars($doc['document_name']) ?>')"
  ```
- **Impact:** Identical mechanism to XSS-002 — the entity-encoded quote is decoded back to a live quote before JS parses it. Rated S2 rather than S1 because both source columns are leadership-written and the account-categories table is confirmed empty in the verified database (`analysis/09-db-verification.md`, Block B), so the chart-of-accounts instance has no current data behind it. The document-name instance is live.
- **Fix effort:** trivial (<1h)
- **Fix sketch:** Same as XSS-002 — `json_encode()` for the JS argument, or move to `data-` attributes.

---

### [XSS-004] Server-side value concatenated directly into an inline `<script>` string
- **Severity:** S2
- **Confidence:** confirmed
- **Location:** `app/bms/customer/customer_documents.php:60,71`
- **Source:** `$response['message']` — the decoded body of an internal API response, which carries server-side error text.
- **Sink:** JavaScript string literal inside a `<script>` block. HTML escaping would not help here either; a `</script>` sequence in the value terminates the block regardless of entity encoding.
- **Evidence:**
  ```php
  echo '<script>alert("Error: ' . $response['message'] . '");</script>';
  ```
- **Impact:** Any API error string reaching this path that contains `"` breaks the JS string, and one containing `</script>` breaks the block and permits arbitrary markup. Rated S2 because the values observed on this path are server-generated messages rather than a directly attacker-controlled field — but this is precisely the class where SEC-018's raw `$e->getMessage()` echoes become dangerous, since a PDO error message can carry attacker-influenced fragments of the offending SQL.
- **Fix effort:** trivial (<1h)
- **Fix sketch:** `json_encode()` the value into the script, or set a `data-` attribute and read it from a static script block.

---

### [XSS-005] Nine further DataTables template-literal sinks render API values unescaped
- **Severity:** S2
- **Confidence:** confirmed
- **Location:** `app/constant/communication/sms_templates.php:227`, `notification_center.php:398`, `campaign_management.php:259,284`, `lead_generation.php:251,255`; `app/constant/document/loan_documents.php:412`, `document_workflow.php:453`; `app/constant/accounts/general_expenses.php:400`
- **Source:** varies by page — SMS template names, notification text, campaign names and `target_audience`, lead first/last name and phone.
- **Sink:** HTML body via `${…}` in a `render` template literal.
- **Evidence:**
  ```js
  { data: 'template_name', render: data => `<span class="badge bg-light text-dark border">${data}</span>` }
  render: (data, type, row) => `<strong>${data} ${row.last_name}</strong>…<small>${row.phone}</small>`
  ```
- **Impact:** Same mechanism as XSS-001. Rated S2 as a group because most sit on modules the database verification found empty or dead — `marketing_campaigns` and `leads` are in DATA-016's 64 unreferenced tables, and `loan_documents` belongs to the disabled loan module. They are listed so the pattern is fixed everywhere at once rather than page by page, and so they are not reintroduced when those modules are revived.
- **Fix effort:** small (<half day)
- **Fix sketch:** Define one shared `escHtml()` in a common script include and route every `render` through it; there are already four separate local copies (see **Verified sound**), which is why the coverage is patchy.

---

### [XSS-006] No Content-Security-Policy, `X-Frame-Options` or `X-Content-Type-Options` anywhere
- **Severity:** S3
- **Confidence:** confirmed
- **Location:** absent from `.htaccess`, `header.php`, `footer.php`, `roots.php` and every file in `includes/`. The only security header in the codebase is the `X-Content-Type-Options: nosniff` on the new `api/get_upload.php`.
- **Source:** n/a — this is a missing control.
- **Sink:** n/a.
- **Evidence:** a case-insensitive grep for `content-security-policy|x-frame-options|x-content-type-options|referrer-policy` across those files returns nothing.
- **Impact:** A CSP is the one control that would blunt every finding above at once — with `script-src 'self'` and no `'unsafe-inline'`, the injected `onerror=` in XSS-001 and the broken-out statement in XSS-002 both stop executing. Its absence is also why the inline-handler pattern proliferated: the codebase has ~11 inline event handlers and many inline `<script>` blocks, so adopting a strict CSP is a migration rather than a header, and that is worth knowing before it is planned. `X-Frame-Options`/`frame-ancestors` absence separately permits clickjacking of the approval screens.
- **Fix effort:** small to add a report-only CSP; medium to reach enforcement
- **Fix sketch:** Add `X-Frame-Options: SAMEORIGIN` and `X-Content-Type-Options: nosniff` in `.htaccess` immediately — neither breaks anything. Then ship `Content-Security-Policy-Report-Only` and work the inline handlers and inline scripts down before enforcing.

---

### [XSS-007] Input validation is asymmetric: two fields on one write path, nothing on the others
- **Severity:** S3
- **Confidence:** confirmed
- **Location:** `includes/registration_validator.php:46,142-165`; absent from `ajax/process_member_import.php`, `includes/member_import.php`, `app/bms/customer/edit_customer.php`
- **Source:** n/a — this is a defence-in-depth gap that governs the reachability of XSS-001, XSS-002 and XSS-003.
- **Sink:** n/a.
- **Evidence:**
  ```php
  return (bool) preg_match("/^[\p{L}][\p{L}\s.'\-]{1,49}$/u", $v);   // reg_valid_name, :46
  ```
- **Impact:** `reg_valid_name()` is genuinely effective — it permits only Unicode letters, whitespace, `.`, `'` and `-`, and requires a letter first, so a payload needing `<`, `;`, `(` or a backtick cannot pass. **But it is applied to exactly two fields** (`first_name`, `last_name` at `:153,160`) on exactly one path (public registration). `middle_name`, `company_name`, all spouse/father/mother/guarantor/next-of-kin names and every address field are unvalidated on that same form, and the member import applies nothing at all. So the security property "member names cannot contain markup" holds on the path that was hardened and nowhere else — which is why XSS-001 and XSS-002 are reachable via import but not via registration. Escaping on output is the correct primary defence and should not be replaced by input filtering; the point here is that the *current* mitigation of those two findings is narrower than it appears.
- **Fix effort:** small (<half day)
- **Fix sketch:** Apply `reg_valid_name()` to the remaining name fields on every write path including the import, and treat it as defence in depth rather than as the fix for XSS-001/002.

---

## Verified sound

Recorded so the next pass does not re-examine these, and because the correct patterns already exist
in this codebase — the problem is coverage, not capability.

| Area | Verdict |
|---|---|
| **`safe_output()` is quote-safe** | `helpers.php:688-690` calls `htmlspecialchars($value)` with no flags. On PHP 8.1+ the default changed to `ENT_QUOTES \| ENT_SUBSTITUTE`, and `composer.json` requires `>=8.2`. Verified by execution on PHP 8.4: `htmlspecialchars("a'b")` → `a&#039;b`. **The 350 bare `htmlspecialchars()` calls are therefore safe for HTML body and quoted-attribute contexts**, and MAP §7.3's concern about missing `ENT_QUOTES` does not apply on a supported PHP version. It is *not* safe for unquoted attributes, `javascript:` URLs, inline JS or CSS — which is exactly what XSS-002 and XSS-003 are. |
| **`fetch_pending_members.php`** | Correct. `:80-84` escapes `full_name`, `username`, `email`, `phone` and `evidence_path` with `htmlspecialchars` before emitting HTML, and `member_approvals.php:100-107` declares plain `{data:}` columns with no `render`, so nothing re-introduces raw data. This is the member-approvals screen — the highest-value member→leader path in the product — and it is sound. |
| **`app/bms/customer/transactions.php:367-377`** | The model implementation. Every free-text column is wrapped in `txnEsc(...)`; only the numeric amount is unwrapped. This is the pattern XSS-001 and XSS-005 should adopt. |
| **Client-side escaping helpers already exist** | `esc()` at `meetings.php:206` and `manage_voting.php:108`, `escHtml()` at `e_signatures.php:994` (`$('<div>').text(s).html()` — the correct idiom) and `select_document_add_esignature.php:721`, `safeOutput()` at `email_center.php:276`. Those five pages escape their `render` output correctly. The defect is that there are five separate local copies and no shared one, so new pages are written without it. |
| **HTMLPurifier configuration** | Sound. `includes/document_sanitizer.php:79-93` sets an explicit `HTML.Allowed` element/attribute whitelist, restricts `URI.AllowedSchemes` to `http`/`https`/`mailto` (so `javascript:` and `data:` URIs are stripped), limits `Attr.AllowedFrameTargets` to `_blank`, and constrains CSS to an allowed-property list. No `HTML.Trusted`, no `HTML.SafeIframe`, no raw `style` passthrough. |
| **The document writer sanitises on write, not on read** | `actions/save_document.php:38` and `actions/save_writer_template.php:28` both pass the body through `vk_sanitize_document_html()` before storage. The raw `<?= $doc['body_html'] ?>` at `view_document.php:178`, `edit_document.php:147` and `edit_writer_template.php:70` is therefore correct by design — it must be raw to render rich text, and the value is already purified. This is the strongest security design in the codebase. |
| **`customer_details.php` and `customers.php`** | `customer_details.php:50` escapes at assignment, so the apparently-raw `<?= $customer_name ?>` at `:150` is safe (`:98` double-escapes it, a cosmetic display bug, not a hole). `customers.php:224-300` wraps every member field in `safe_output()`. |
| **Contribution receipts** | `contribution_view.php:133` and `print_contribution.php:135` both `htmlspecialchars()` the member-written `description`. This is the member-writable free text most likely to reach a leader's screen, and it is escaped on both surfaces. |
| **`reg_valid_name()`** | Effective for the two fields it guards — see XSS-007 for the coverage caveat. |
| **DataTables `render: function(){…}` blocks** | Zero unescaped instances found on live pages. The defect is confined to the newer arrow + template-literal style. |

**Also noted, not an XSS:** `safe_output()` returns the literal string `'N/A'` for any falsy input,
because it tests `!empty($value)`. So `safe_output(0)` and `safe_output("0")` both render `N/A`.
On a money or count column that is a correctness bug — a genuine zero displays as "not available".
Out of scope here; recorded because it was found while reading the function.

---

## Summary

| Severity | Count |
|---|---:|
| S0 | 0 |
| S1 | 2 |
| S2 | 3 |
| S3 | 2 |
| **Total** | **7** |

**The single most exploitable path, traced end to end** — XSS-002, because it is the only one
demonstrated to execute rather than reasoned to:

1. A member record is created or edited through the member import
   (`ajax/process_member_import.php`), which applies no validation to any name field.
2. `customers.last_name` is set to `';alert(document.domain);//`.
3. Leadership opens a customer group page — `customers/group_members` or `customers/group_details`,
   both routed at `roots.php:268-269`.
4. `customer_group_members.php:92-98` builds `$customer_name` through `safe_output()`, producing
   `&#039;;alert(document.domain);//`.
5. `:287` emits it through `addslashes()` — a no-op, since no literal quote remains — into
   `onclick="removeMember(…, '…')"`.
6. The HTML parser decodes `&#039;` back to `'` before the JavaScript engine sees the attribute.
7. The JS string closes, `alert(document.domain)` executes as a statement, `//` comments out the
   rest. Substituting a `fetch()` to a remote host exfiltrates whatever the chairperson's session
   can read.

**Is `safe_output()` safe?** Yes for HTML body and quoted attributes, on PHP 8.1+ — verified by
execution, not assumed. No for unquoted attributes, `javascript:`/`data:` URLs, inline JavaScript,
or CSS. Both S1 findings are cases of it being used in a JavaScript context, where it is not merely
insufficient but actively misleading: it makes the call site *look* escaped, and the entity encoding
it applies is undone by the HTML parser before JS runs.

**Is the DataTables/JSON surface exploitable?** Yes, and it is the largest surface — but it is
split. Of 26 `serverSide` pages, five escape correctly with a local helper and the rest interpolate
raw. `api/` feeds return raw `fetchAll` output with no escaping (verified across eight endpoints),
so the entire defence is client-side and is applied inconsistently. XSS-001 is the live instance.
My first scan reported zero because it only matched `render: function(){…}`; the defect lives in the
newer `render: d => \`…\`` arrow-and-template-literal form, which is worth stating plainly as a
scanner limitation others will hit.

---

## Resolved in Batch 3

Two open questions from this report were traced to a conclusion during remediation.

### The open S0 question — CLOSED, no S0 exists

**Does any anonymously-writable, unvalidated field reach an unescaped sink? No.**

`actions/process_registration.php` is public and writes **76 distinct `$_POST` fields** into
`customers`. `includes/registration_validator.php` applies `reg_valid_name()` to exactly **two** of
them (`first_name` at `:153`, `last_name` at `:160`). The other 74 — `middle_name`, all spouse
fields, `children_data` (via `child_name[]`), all father/mother fields, `guarantor_name`,
`next_of_kin_name`, `religion`, `birth_region` and every address component — are stored with no
validation of any kind.

Every render of those fields was traced. **All of them escape:**

| Sink | Verdict |
|---|---|
| `app/bms/customer/customer_details.php:218-262,336-350` — spouse, parents, guarantor, next-of-kin | `safe_output()` on every field |
| `app/bms/customer/edit_customer.php:310,441-477` — the same fields in form inputs | `safe_output()` on every field |
| `app/constant/profile/profile.php:1287-1288` — child name and DOB in edit inputs | `htmlspecialchars()` |
| `app/constant/profile/profile.php:1584` — children in the view-mode table | `htmlspecialchars()` on name, age and gender |
| `app/constant/reports/member_statement.php:48-52` | decodes `children_data` but only **counts** it; no child value is rendered |
| `ajax/get_member_beneficiaries.php` | returns spouse and children as JSON, but **has no consumer anywhere in `app/`** — the orphan endpoint Batch 1 gated |

The apparent exceptions are all comparisons that emit only `selected`/`''`
(`profile.php:1228-1242`), counters (`member_statement.php:146`), or values passed through `date()`
(`profile.php:1556`). None renders the stored value.

**Conclusion: the validation asymmetry in XSS-007 is real, but it is not currently reachable to an
unescaped sink. There is no S0 in this codebase.** That said, the safety rests entirely on output
escaping being universal across those six surfaces — XSS-007's fix (extend `reg_valid_name()` to
the remaining name fields and to the import) remains worthwhile as defence in depth, because a
single new unescaped render of a spouse or child name would create an S0 with no other barrier.

### Coverage gap #5 — CLOSED, no reflected XSS

**`app/constant/communication/message_center.php:317` does not render request-derived input.**

`$message = trim($_POST['message'])` at `:14` never reaches `:317`, for two independent reasons:

1. `:315` is `foreach ($success_messages as $message)`, which **rebinds** `$message` before the
   render.
2. `:141-142` unconditionally reset `$success_messages = []` and `$error_messages = []`, and those
   lines run **after** the POST handlers at `:64-135` that push into them. The only value that can
   reach `:317` is the static literal pushed at `:144` behind `isset($_GET['success'])`.

`$error_messages` is therefore always empty at render time, so the `$e->getMessage()` values pushed
at `:64,92,108,135` never reach the unescaped render at `:326` either.

**Side effect worth recording, though it is not a security issue:** because `:141-142` reset the
arrays after the handlers populate them, **every success and error message raised by the POST
handlers on this page is silently discarded**. Sending, deleting, archiving or marking a message
read produces no feedback. That is a pre-existing functional bug, out of scope for the security
batches, and it is what makes the reflected-XSS question moot.

---

## Coverage gaps

1. **`register.php` (1,092 lines) was not read in full.** Its validation was assessed through
   `actions/process_registration.php` and `includes/registration_validator.php`. Client-side-only
   constraints in the form markup — which an attacker bypasses trivially — were not enumerated, and
   there may be additional fields posted that the validator does not see.
2. **`app/constant/profile/profile.php` (2,358 lines) was read only around its permission gate and
   update block** (`:13-20,95,108-128,192-230`). Its ~1,900 remaining lines include several render
   surfaces. I established that `:122` requires `isAdmin() || canEdit('customers')` so an ordinary
   member cannot edit even their own names — an earlier reading of mine that suggested otherwise was
   wrong and is corrected here — but I did not audit the page's own output escaping.
3. **The 892-site raw-echo inventory was triaged, not exhausted.** I filtered to member/free-text
   column names on live pages (102 sites) and traced the highest-value ones. Sites echoing values
   escaped at *assignment* rather than at output are indistinguishable from unescaped ones to a line
   scanner — `customer_details.php:150` is exactly that false positive — so the true unescaped count
   is lower than 892 and I did not compute it.
4. **The remaining ~17 `serverSide` pages were not individually opened.** XSS-005 lists what the
   pattern scan surfaced; a page whose `render` uses a different idiom again would have been missed.
5. **No reflected-XSS pass.** `$_GET`/`$_POST` reaching output directly was not systematically
   traced. `Vikundi_Audit_Findings.md:95` claims none exists; I neither confirmed nor refuted it.
   The `$message` variables on the settings pages (`system_settings.php:163`, `user_roles.php:251`,
   `message_center.php:317`) are rendered unescaped — the two I checked are static literals, but
   `message_center.php:14` assigns `$message = trim($_POST['message'])` earlier in the same file and
   I did not establish whether the same variable reaches `:317`. **Marked `suspected`; one read of
   that file's control flow settles it.**
6. **Nothing was executed against the application.** No page was rendered and no payload was
   submitted. XSS-002's breakout was demonstrated by reproducing the exact encoding chain in
   isolation (`safe_output` → `addslashes` → `html_entity_decode`), which proves the encoding is
   defeated but not that the page renders as expected. One request to
   `customers/group_members` with a seeded name would convert it from `confirmed`-by-construction
   to `confirmed`-by-observation.
7. **`assets/` was excluded per RoE 5**, so any escaping or injection in shared non-minified
   JavaScript is unassessed. Several pages call helpers (`money()`, `formatCurrency()`, `esc()`)
   that may be defined there rather than inline.
8. **Stored XSS via the transaction import was not traced to a render.**
   `includes/transaction_import.php` writes `mkoba_*` columns with no validation, and
   `transactions.php` escapes them correctly — but the M-Koba statement columns also reach
   `app/constant/reports/` and the printouts, which I did not audit for this class.
