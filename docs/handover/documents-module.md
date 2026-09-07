# Documents — what to know before you build it

Live on both servers. Reference: `docs/API.md` §16.

---

## Two completely separate features, two separate resources — don't conflate them

"Documents" on the web is really two unrelated products sharing a nav menu:

- **Library** (`GET/DELETE /documents...`) — plain file uploads (PDFs, images) a leader puts in a
  shared folder. `access_level` controls who sees what.
- **Document Writer** (`GET/POST/PUT/DELETE /authored-documents...`) — in-app rich-text letters,
  contracts and notices, with multi-party e-signing.

They are **different database tables, different permission keys, different visibility rules**.
Don't build one "documents" screen that mixes both lists together — the web doesn't either. Build
two screens (or two tabs), each hitting its own resource.

---

## The Library has no upload endpoint yet

`POST /documents` doesn't exist. This module only covers list/detail/download/delete for files
someone else (a leader, via the web) already uploaded. If your app needs "upload a file from my
phone into the Library," that's a new ask, not something you missed in this handover.

---

## A hidden document 404s — it never tells you it exists

Both the Library and the Document Writer return `404 not_found` for a document you're not allowed
to see, never `403`. This is deliberate: knowing "id 17 exists but I can't see it" is itself
information the group didn't agree to share. If your UI shows a different message for "doesn't
exist" vs. "you can't see this," you can't — the server won't tell you which one it is.

---

## Signing an authored document: check `my_slot` logic, not just a permission flag

A document with a signatory list lets each assigned person sign **their own slot** —
`POST /authored-documents/{id}/sign` — with **no `manage_documents` permission needed at all**. An
ordinary Member who was asked to countersign a letter can do it. Don't gate the "Sign" button
behind a leadership check; gate it behind "is this user one of the `signatories` on this
document, with `status: pending`" — that's the actual rule.

Only when a document has **zero** signatories does the endpoint fall back to a single
leadership-only signature. Your UI needs both paths: if `signatories` is non-empty, show
per-signatory sign buttons to the people assigned; if it's empty, show one "Sign" button gated on
`actions.edit` (which already reflects `manage_documents`).

---

## `GET .../workflow` exists so you don't have to re-fetch the whole document to poll signing status

If you build a "waiting for signatures" screen that refreshes every few seconds, hit
`GET /authored-documents/{id}/workflow`, not `GET /authored-documents/{id}`. The latter includes the
full `body_html` — potentially a long rich-text letter — on every poll. The workflow endpoint is
just `{mode, signatories, progress}` (or the legacy single-sign shape).

---

## `body_html` is trusted, sanitized server-side HTML — render it as HTML, not plain text

The API returns pre-sanitized rich text (headings, bold, lists) in `body_html`. If you flutter_html
or webview it, that's correct. If you're tempted to `Text(body_html)`, don't — you'll show raw tags
to the user.

---

## Field types

| Field | Dart |
|---|---|
| `file_path` | **Never returned**, on any Library endpoint — download the file via `.../download`, don't try to construct a URL from a field that doesn't exist in the response |
| `document.actions` | `{"delete": bool}` on Library rows (no edit — there's no metadata-edit endpoint); `{"edit": bool, "delete": bool}` on authored documents |
| `visibility` | `"shared"` or `"private"` — authored documents only, not a Library concept |
| `access_level` | `"public"`/`"restricted"`/`"private"` — Library only, not an authored-document concept |
| `signatories[].status` | `"pending"`/`"signed"`/`"declined"` |

---

## Checked live, so you do not have to

- Library: a Member saw `0` of 4 all-private seeded library documents; a private item 404'd for a
  non-owner leadership account too (private really means private, not "hidden from Members only").
- Full authored-document lifecycle on the local instance: create → assign a Member as signatory →
  the Member's list/detail/workflow all showed exactly that one document → sign → progress
  completed and the creator got an in-app notification → a second sign attempt from the same
  person correctly refused `409` → delete (confirmed gone with `404`, the signatory row confirmed
  cleared too).
- A Member's `POST /authored-documents` correctly refused `403` (no `manage_documents` create
  right).

## The permission-key story — one you should know even though it's already fixed

The Library's canonical permission key in this codebase's own migrations is `document_library`.
Production and demo's *actual* live grants, going back further than this repo's migration
tracking, are under the plain key `library`. Both are checked (`vk_api_doc_library_can()`) so this
doesn't matter to you as a client — but if you ever see a Library-related permission grant screen
in the web app's own Settings and the key printed there is `library`, that's not a typo; it's the
real one.
