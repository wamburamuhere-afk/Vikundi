<?php
/**
 * includes/contribution_access.php — who may see whose money.
 *
 * ONE RULE, BOTH TRANSPORTS. The web and the mobile API each had their own idea
 * of this, and the web's was wrong in six places at once. This file holds the
 * decision; `includes/api_contributions.php` and the web pages both defer to it.
 *
 * WHAT WENT WRONG. `manage_contributions.view` is granted to the Member role,
 * and correctly so — it is what lets a member open their own contributions at
 * all. Six endpoints then treated that same grant as permission to see the
 * GROUP:
 *
 *   api/get_transactions.php                     the whole transaction list
 *   api/export_contributions_statement.php       group export
 *   api/export_contributions_statement_mkoba.php group export (M-Koba layout)
 *   app/bms/customer/transactions.php            the recording hub
 *   app/bms/customer/contribution_view.php       any row, by id
 *   app/bms/customer/print_contribution.php      any row, by id
 *   app/bms/customer/contribution_statement.php  any member's statement
 *
 * Verified on the live demo site as an ordinary member: 333 group transactions,
 * another member's TZS 50,000 contribution, and the chairperson's complete
 * savings statement — all readable.
 *
 * THE DISTINCTION THIS FILE ENFORCES:
 *
 *   group-wide data  -> requires LEADERSHIP (admin, or `edit`)
 *   a single record  -> requires OWNERSHIP  (it is yours), or leadership
 *
 * `edit` rather than `view` is the leadership test precisely because view is the
 * grant a Member holds. Testing view is what caused this.
 *
 * app/bms/customer/manage_contributions.php is deliberately NOT in the list
 * above: it holds `view` and is correct, because it already branches on
 * $is_leader and shows a member only their own rows.
 */

if (!function_exists('vk_contrib_leader_from')) {
    /**
     * The rule itself, as a pure function of two booleans, so the web and the
     * API cannot drift apart and so it can be tested without a database.
     */
    function vk_contrib_leader_from(bool $isAdmin, bool $canEdit): bool
    {
        return $isAdmin || $canEdit;
    }
}

if (!function_exists('vk_contrib_web_is_leader')) {
    /** The session-side answer, for web pages and AJAX endpoints. */
    function vk_contrib_web_is_leader(): bool
    {
        return vk_contrib_leader_from(isAdmin(), canEdit('manage_contributions'));
    }
}

if (!function_exists('vk_contrib_web_member_id')) {
    /** The customers.customer_id of the signed-in user, or 0 if they are not a member. */
    function vk_contrib_web_member_id(PDO $pdo): int
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

if (!function_exists('vk_contrib_web_may_see_member')) {
    /**
     * May the signed-in user see this member's money?
     *
     * A leader may see anyone. Anyone else may see only themselves. A member id
     * of 0 means "the whole group", which only a leader may ask for.
     */
    function vk_contrib_web_may_see_member(PDO $pdo, int $memberId): bool
    {
        if (vk_contrib_web_is_leader()) {
            return true;
        }
        if ($memberId <= 0) {
            return false;
        }
        return $memberId === vk_contrib_web_member_id($pdo);
    }
}

if (!function_exists('vk_contrib_web_require_leader')) {
    /**
     * Gate for a GROUP-WIDE endpoint. Refuses with the caller's own content type
     * — a JSON endpoint answering in HTML is how a refusal ends up rendered as
     * data by a DataTable.
     */
    function vk_contrib_web_require_leader(bool $json = true): void
    {
        if (vk_contrib_web_is_leader()) {
            return;
        }

        http_response_code(403);
        if ($json) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'error'   => 'Not authorized.',
                'message' => 'Group financial records are available to leadership only.',
            ]);
        } else {
            echo '<!doctype html><meta charset="utf-8"><title>Not authorized</title>'
               . '<p style="font-family:system-ui;padding:2rem">'
               . 'Group financial records are available to leadership only.</p>';
        }
        exit;
    }
}

if (!function_exists('vk_contrib_web_require_own_or_leader')) {
    /**
     * Gate for a SINGLE record identified by the member it belongs to.
     *
     * Refuses with 404 rather than 403: contribution ids are sequential, and a
     * 403 confirms which ids exist.
     */
    function vk_contrib_web_require_own_or_leader(PDO $pdo, int $memberId, bool $json = false): void
    {
        if (vk_contrib_web_may_see_member($pdo, $memberId)) {
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
