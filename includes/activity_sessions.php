<?php
/**
 * includes/activity_sessions.php
 * ------------------------------
 * Groups a user's activity_logs rows into readable sign-in sessions for the
 * per-user timeline (app/audit_timeline.php). Pure and DB-free so it can be
 * unit-tested with fabricated events.
 */

if (!function_exists('vk_group_activity_sessions')) {
    /**
     * @param array $events   activity rows, each with at least 'action' and
     *                         'created_at', in ASCENDING chronological order.
     * @param int   $gapSecs  inactivity gap (seconds) that starts a new session.
     * @return array          sessions oldest-first; each:
     *                         [start, start_ts, last, last_ts, events[], ended]
     *                         where `ended` is 'logout' or null.
     *
     * Boundaries: a new session begins on a 'Login', or after a gap longer than
     * $gapSecs; a session closes on a 'Logout'. (Logout/Login are never 'Viewed'
     * rows, so boundaries survive even when page views are filtered out.)
     */
    function vk_group_activity_sessions(array $events, int $gapSecs = 1800): array
    {
        $sessions = [];
        $cur = null;

        foreach ($events as $e) {
            $ts  = strtotime($e['created_at'] ?? 'now');
            $act = strtolower(trim($e['action'] ?? ''));

            if ($cur === null || $act === 'login' || ($ts - $cur['last_ts']) > $gapSecs) {
                if ($cur !== null) $sessions[] = $cur;
                $cur = [
                    'start'    => $e['created_at'] ?? null,
                    'start_ts' => $ts,
                    'events'   => [],
                    'ended'    => null,
                    'last'     => $e['created_at'] ?? null,
                    'last_ts'  => $ts,
                ];
            }

            $cur['events'][] = $e;
            $cur['last']     = $e['created_at'] ?? null;
            $cur['last_ts']  = $ts;

            if ($act === 'logout') {
                $cur['ended'] = 'logout';
                $sessions[]   = $cur;
                $cur          = null;
            }
        }

        if ($cur !== null) $sessions[] = $cur;

        return $sessions;
    }
}
