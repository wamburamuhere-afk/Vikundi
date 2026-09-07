# Meetings — what to know before you build it

Live on both servers. Reference: `docs/API.md` §15.

---

## The simplest permission model in the API — and the first one that just worked

`meetings` already had correct grants (full leadership CRUD, Member view-only) before this module
was even built — no new permission migration was needed, the only module since Contributions where
that was true. If your app has a shared "does this role see this tab" helper, `meetings` behaves
exactly the way you'd naively expect: Member sees everything, can edit/delete nothing.

---

## Attendance is NOT "resubmit the whole roster" — read this before you build the attendance screen

The web's own `actions/save_meeting_attendance.php` resends every active member's status on every
save, and treats "in the roster but not checked" as an explicit absence. **This API does not work
that way.** `POST /meetings/{id}/attendance` only touches the rows you send — a member you don't
include keeps whatever status they already had (or shows as `absent` on the roster purely as a
*display default*, not a stored fact).

Practically: if you build a "tap present/absent per row" screen, you can `POST` just the rows that
changed since the last save. You do **not** need to resend the whole roster to avoid accidentally
un-marking someone. If your UI design assumes "submitting attendance = the final word on everyone,"
that assumption is wrong for this endpoint — build against what it actually does, not what the web
page does.

---

## Fine-absentees only fines people with a REAL absent row

`POST /meetings/{id}/fine-absentees` (not in the original plan — added because the web's own "Fine
Absentees" button is real and leadership-only) fines exactly the members who have an actual
`absent` row in `meeting_attendance`. A member nobody has marked at all is **not** fined, even
though the roster displays them as absent by default. Don't build a "fine everyone showing red" UI
that bypasses this endpoint — the redness on an unmarked row is cosmetic, not a debt.

It's also safe to tap twice: running it again skips anyone already fined for that meeting
(`fines.meeting_id` dedup), returned as `skipped` in the response.

---

## DELETE is real here — the first one in the whole API

`DELETE /meetings/{id}` is a genuine HTTP `DELETE`, not a POST-with-an-action-field like most of
this API's mutations elsewhere. It cascades `meeting_attendance` server-side — you don't need to
clean up attendance rows client-side first.

---

## Field types

| Field | Dart |
|---|---|
| `meeting_time` | `String?` truncated to `HH:MM` — never seconds, never null-vs-empty ambiguity (blank stays `null`) |
| `present_count` | Only appears on the list row, never on detail (detail has the full `attendance` array instead) |
| `attendance[].status` | Exactly `"present"` or `"absent"` — no third value on this endpoint |
| `summary` | `{present: int, absent: int, total: int}` — always all three active members, computed server-side |

---

## Checked live, so you do not have to

- Full lifecycle on demo: create → attendance for 2 of 30 members → fine-absentees created exactly
  1 fine, re-running it skipped the duplicate → edit → delete, confirmed gone with `404`.
- An unknown `member_id` in an attendance submission refused with `404` before any row was written.
- `api/get_meetings.php` and `api/get_meeting_details.php` (the web's own list/detail data sources)
  had no permission check beyond being logged in — both now require `canView('meetings')`, fixed as
  part of this module.

One labelled test record exists on demo — meeting id 10 ("API smoke test — Module 12 verification,
safe to delete"). Harmless; feel free to attendance/fine against it further while testing your own
screens.
