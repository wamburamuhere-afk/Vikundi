<?php
// includes/vote_helpers.php
//
// Pure helpers for the Voting module — no DB, so they can be unit tested.

if (!function_exists('vk_vote_types')) {
    function vk_vote_types(): array { return ['candidate', 'motion']; }
}
if (!function_exists('vk_vote_statuses')) {
    function vk_vote_statuses(): array { return ['draft', 'open', 'closed']; }
}

if (!function_exists('vk_normalize_vote_type')) {
    function vk_normalize_vote_type($t): string {
        $t = strtolower(trim((string) $t));
        return in_array($t, vk_vote_types(), true) ? $t : 'candidate';
    }
}
if (!function_exists('vk_normalize_vote_status')) {
    function vk_normalize_vote_status($s): string {
        $s = strtolower(trim((string) $s));
        return in_array($s, vk_vote_statuses(), true) ? $s : 'draft';
    }
}

if (!function_exists('vk_default_motion_options')) {
    /** The fixed choices for a Yes/No/Abstain motion. */
    function vk_default_motion_options(): array { return ['Yes', 'No', 'Abstain']; }
}

if (!function_exists('vk_vote_input_errors')) {
    /**
     * Validate a vote definition (pure). Title required; a candidate election
     * needs at least two options; closing date, if given, must be valid.
     *
     * @param array $post   title, vote_type, closes_at
     * @param array $labels the option labels the caller extracted (already trimmed)
     */
    function vk_vote_input_errors(array $post, array $labels, bool $sw = false): array {
        $errors = [];
        $title = trim((string) ($post['title'] ?? ''));
        $type  = vk_normalize_vote_type($post['vote_type'] ?? 'candidate');

        if ($title === '') {
            $errors[] = $sw ? 'Kichwa cha kura kinahitajika.' : 'Vote title is required.';
        }
        if ($type === 'candidate') {
            $clean = array_values(array_filter(array_map('trim', $labels), fn($l) => $l !== ''));
            if (count($clean) < 2) {
                $errors[] = $sw ? 'Weka angalau wagombea wawili.' : 'Add at least two candidates.';
            }
        }
        $closes = trim((string) ($post['closes_at'] ?? ''));
        if ($closes !== '' && strtotime($closes) === false) {
            $errors[] = $sw ? 'Tarehe ya kufunga si sahihi.' : 'The closing date is not valid.';
        }
        return $errors;
    }
}

if (!function_exists('vk_vote_tally')) {
    /**
     * Build the tally: each option with its ballot count, sorted high to low.
     *
     * @param array $options rows [id, label, ...]
     * @param array $counts  map option_id => count
     */
    function vk_vote_tally(array $options, array $counts): array {
        $out = [];
        foreach ($options as $o) {
            $oid = (int) $o['id'];
            $out[] = ['id' => $oid, 'label' => $o['label'] ?? '', 'votes' => (int) ($counts[$oid] ?? 0)];
        }
        usort($out, fn($a, $b) => $b['votes'] <=> $a['votes']);
        return $out;
    }
}

if (!function_exists('vk_turnout_percent')) {
    /** Whole-number turnout percentage (voted / eligible), 0 when none eligible. */
    function vk_turnout_percent(int $voted, int $eligible): int {
        if ($eligible <= 0) return 0;
        return (int) round(($voted / $eligible) * 100);
    }
}
