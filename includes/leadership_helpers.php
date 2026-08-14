<?php
/**
 * includes/leadership_helpers.php — shared rules for leadership applications.
 *
 * The flow the group set out:
 *   member applies  ->  Committee reviews  ->  approved names become the ballot
 *
 * An election is a `votes` row of type 'candidate'. While it is still `draft` it
 * accepts applications; opening it starts the voting and closes applications. The
 * voting module's own lifecycle is the single source of truth for that, so the two
 * can never disagree about whether nominations are open.
 */

if (!function_exists('vk_leadership_positions')) {
    /**
     * The positions members may apply for, from group_settings — editable so that
     * renaming an office never needs a release. One per line; blanks dropped.
     *
     * Returns an empty array when nothing is configured, and callers must treat
     * that as "applications cannot be taken yet" rather than inventing a default:
     * a member choosing from a list the group never agreed to is worse than a
     * member being told to come back later.
     */
    function vk_leadership_positions(PDO $pdo): array
    {
        $raw = $pdo->query("SELECT setting_value FROM group_settings WHERE setting_key = 'leadership_positions'")
                   ->fetchColumn();
        if (!$raw) {
            return [];
        }
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $raw) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }
}

if (!function_exists('vk_elections_accepting_applications')) {
    /**
     * Elections currently taking applications: candidate votes still in draft.
     * A motion vote never takes applications — there is nobody to stand for it.
     */
    function vk_elections_accepting_applications(PDO $pdo): array
    {
        return $pdo->query("
            SELECT id, title, description, created_at
              FROM votes
             WHERE vote_type = 'candidate' AND status = 'draft'
             ORDER BY created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('vk_member_application')) {
    /** This member's application to one election, whatever its status, or null. */
    function vk_member_application(PDO $pdo, int $voteId, int $memberId): ?array
    {
        $q = $pdo->prepare("
            SELECT a.*, TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS proposer_name
              FROM leadership_applications a
              LEFT JOIN customers p ON p.customer_id = a.proposer_member_id
             WHERE a.vote_id = ? AND a.member_id = ?
             LIMIT 1");
        $q->execute([$voteId, $memberId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('vk_application_status_badge')) {
    /** Bootstrap colour for an application status. */
    function vk_application_status_badge(string $status): string
    {
        return [
            'pending'   => 'warning',
            'approved'  => 'success',
            'rejected'  => 'danger',
            'withdrawn' => 'secondary',
        ][$status] ?? 'secondary';
    }
}

if (!function_exists('vk_application_status_label')) {
    /** Bilingual label for an application status. */
    function vk_application_status_label(string $status, bool $isSw): string
    {
        $map = [
            'pending'   => ['Awaiting review', 'Inasubiri kupitiwa'],
            'approved'  => ['Approved — on the ballot', 'Imekubaliwa — yupo kwenye kura'],
            'rejected'  => ['Not approved', 'Haikukubaliwa'],
            'withdrawn' => ['Withdrawn', 'Imeondolewa'],
        ];
        $pair = $map[$status] ?? ['Unknown', 'Haijulikani'];
        return $isSw ? $pair[1] : $pair[0];
    }
}

if (!function_exists('vk_application_is_editable')) {
    /**
     * An application can be changed or withdrawn only while it is still pending
     * AND its election is still in draft. Once the Committee has ruled, or voting
     * has opened, the record is fixed — a candidate cannot quietly rewrite their
     * statement after it was approved, or vanish from a ballot people are voting on.
     */
    function vk_application_is_editable(?array $application, string $electionStatus): bool
    {
        return $application !== null
            && $application['status'] === 'pending'
            && $electionStatus === 'draft';
    }
}
