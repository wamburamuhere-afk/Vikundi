<?php
/**
 * includes/death_expense_access.php — who may see whose condolence record.
 *
 * ONE RULE, BOTH TRANSPORTS, from the start this time — written before the
 * mobile API existed for this module, so the two cannot drift the way
 * contributions did.
 *
 * WHAT WAS WRONG. `death_expenses.view` is granted to the Member role, and
 * correctly so — it is what a member's own screen would use. Three places
 * instead treated that grant as permission to see the GROUP:
 *
 *   api/get_death_expenses.php                 the whole condolences list
 *   app/constant/accounts/death_expense_view.php  any record, by id
 *   app/constant/accounts/print_death_expense.php any record, by id
 *
 * and a fourth had no permission check at all:
 *
 *   app/constant/accounts/death_expenses.php   the leadership console itself
 *
 * Verified on the live demo site as an ordinary member: both condolence
 * records in the group, including the Chairperson's TZS 900,000 case with her
 * name attached, readable both from the list and by opening the record
 * directly.
 *
 * THE DISTINCTION THIS FILE ENFORCES:
 *
 *   group-wide data  -> requires LEADERSHIP (admin, or `edit`)
 *   a single record  -> requires OWNERSHIP (it is yours), or leadership
 *
 * `edit`, not `view`, is the leadership test — `view` is the grant the Member
 * role holds so it must not also be what unlocks the group.
 *
 * UNLIKE CONTRIBUTIONS, no web screen branches on a member's own `view` grant
 * here — app/constant/accounts/death_expenses.php is a leadership console with
 * no member-facing counterpart, so the list endpoint is put behind LEADERSHIP
 * outright rather than scoped. A member's own condolence history is served by
 * the mobile API's /my/condolences, which did not exist before this file.
 */

if (!function_exists('vk_death_leader_from')) {
    /**
     * The rule itself, as a pure function of two booleans, so the web and the
     * API cannot drift apart and so it can be tested without a database.
     */
    function vk_death_leader_from(bool $isAdmin, bool $canEdit): bool
    {
        return $isAdmin || $canEdit;
    }
}

if (!function_exists('vk_death_web_is_leader')) {
    /** The session-side answer, for web pages and AJAX endpoints. */
    function vk_death_web_is_leader(): bool
    {
        return vk_death_leader_from(isAdmin(), canEdit('death_expenses'));
    }
}

if (!function_exists('vk_death_web_member_id')) {
    /** The customers.customer_id of the signed-in user, or 0 if they are not a member. */
    function vk_death_web_member_id(PDO $pdo): int
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT customer_id FROM customers WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }
}

if (!function_exists('vk_death_web_may_see_member')) {
    /**
     * May the signed-in user see this member's condolence record?
     *
     * A leader may see anyone. Anyone else may see only themselves. A member id
     * of 0 means "the whole group", which only a leader may ask for.
     */
    function vk_death_web_may_see_member(PDO $pdo, int $memberId): bool
    {
        if (vk_death_web_is_leader()) {
            return true;
        }
        if ($memberId <= 0) {
            return false;
        }
        return $memberId === vk_death_web_member_id($pdo);
    }
}

if (!function_exists('vk_death_web_require_leader')) {
    /**
     * Gate for a GROUP-WIDE endpoint. Refuses with the caller's own content type
     * — a JSON endpoint answering in HTML is how a refusal ends up rendered as
     * data by a DataTable.
     */
    function vk_death_web_require_leader(bool $json = true): void
    {
        if (vk_death_web_is_leader()) {
            return;
        }

        http_response_code(403);
        if ($json) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'error'   => 'Not authorized.',
                'message' => 'Condolence records are available to leadership only.',
            ]);
        } else {
            echo '<!doctype html><meta charset="utf-8"><title>Not authorized</title>'
               . '<p style="font-family:system-ui;padding:2rem">'
               . 'Condolence records are available to leadership only.</p>';
        }
        exit;
    }
}

if (!function_exists('vk_death_web_require_own_or_leader')) {
    /**
     * Gate for a SINGLE record identified by the member it belongs to.
     *
     * Refuses with 404 rather than 403: death_expenses ids are sequential, and
     * a 403 confirms which ids exist — on a table whose whole subject is who in
     * the group has lost a family member.
     */
    function vk_death_web_require_own_or_leader(PDO $pdo, int $memberId, bool $json = false): void
    {
        if (vk_death_web_may_see_member($pdo, $memberId)) {
            return;
        }

        http_response_code(404);
        if ($json) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode(['error' => 'Not found.']);
        } else {
            echo '<!doctype html><meta charset="utf-8"><title>Not found</title>'
               . '<p style="font-family:system-ui;padding:2rem">'
               . 'That record was not found.</p>';
        }
        exit;
    }
}
