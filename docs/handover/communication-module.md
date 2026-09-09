# Communication — what to know before you build it

Live on both servers. Reference: `docs/API.md` §19.

---

## `GET /messages` is your own inbox — not a leadership screen

Unlike most "leadership vs. member" splits in this API, `message_center` is view-granted to every
Member by default. Every screen built on `GET /messages` is available to every logged-in user — an
ordinary Member has a real inbox, sent folder, and archive, same as leadership. Don't gate the
"Messages" nav item behind an `is_leadership` check.

**Sending is different.** `can_send` in every `GET /messages` response tells you whether to show the
compose button — it's `false` for a Member. `POST /messages` from a Member is a plain `403`. Drive
the UI off `can_send`, not a role guess: it's computed server-side from the same permission the
`POST` endpoint itself checks, so it can never drift from what the send call will actually do.

---

## SMS and Email are leadership-only, no exceptions, and there is no `GET /email`

`GET /sms`, `POST /sms`, and `POST /email` all need `create` on `message_center` — the same grant
that controls `can_send` above. A Member gets `403` on all three, always. There is deliberately no
`GET /email` — the web's own email log hands the whole group's addresses and message bodies to
anyone holding `view` on `message_center` (every Member, by default), and building a mobile
endpoint on that would have exposed the same thing through a new door. If your design has an
"Email History" screen, it isn't backed by this API — don't build it against a route that doesn't
exist.

---

## `POST /sms` and `POST /email` always return `201` — check `sent_count`, not the HTTP status

```json
{"status":"success","data":{"sent_count":0,"failed_count":1,"message":"Sent to 0 recipient(s), 1 failed."}}
```

A `sms_logs`/`email_logs` row is written for every recipient regardless of whether the actual
gateway send succeeded, so the request itself always "succeeds" — delivery outcome lives in
`sent_count`/`failed_count` in the body. **On demo and production today, every send comes back
`sent_count: 0`** — no SMS gateway or SMTP credentials are configured on either. Build the
partial/total-failure UI; don't assume a `201` means the recipient's phone buzzed.

---

## AI Ask/Chat are not configured — build for `ai_not_configured`, not just a happy path

Both `POST /ai/ask` and `POST /ai/chat` need a leadership grant (`ai_ask_data` / `ai_assistant`,
both hidden from Member by default — `403` if you test as a Member, which is correct). Even as
leadership, the real response on both demo and production right now is:

```json
{"status":"error","code":"ai_not_configured","message":"AI is not set up yet. Ask an admin to configure it in AI Settings."}
```

No provider key is set on either server. Whatever UI you build for these two screens needs a real
"AI isn't set up" state — it is not an edge case, it is the only state you can currently test
against.

---

## Field types

| Field | Dart |
|---|---|
| `message.priority` | `String`, one of `low`/`normal`/`high` |
| `message.parent_id` | `int?` — `null` for a top-level message |
| `message.is_mine` | `bool` — `true` on your own sent messages, where `is_read` is always `true` too and means nothing |
| `recipients` (message detail) | `[]` unless you are the sender — never populated on a message you received |
| `sms.error_message` | `String?` — `null` on a genuine send success, populated on `failed` |
| `sms.sent_at` | `String?` (ISO 8601) — `null` until a send actually succeeds |
| `notification.type` | `String`, one of `loan`/`payment`/`system`/`report`/`alert` |

---

## Checked live, so you do not have to

- Member: `GET /messages` succeeds with `can_send: false`; `POST /messages`, `GET /sms`,
  `POST /sms`, `POST /email`, `POST /ai/ask`, `POST /ai/chat` are all `403`.
- Chairperson sent a real message to a Member: the Member's inbox showed it unread, opening
  `GET /messages/{id}` marked it read, the unread count dropped to `0`.
- A third, unrelated user holding `message_center` view opened someone else's message id:
  `404`, not `403` — existence isn't confirmed to someone with no part in it.
- `POST /sms` against no configured gateway: `201`, `sent_count: 0`, a real `sms_logs` row created
  and visible on the next `GET /sms`.
- `GET /email-templates` and `GET /notifications` both return real, correctly-shaped empty results
  on demo (no seeded data) — not an error, not a stub.
