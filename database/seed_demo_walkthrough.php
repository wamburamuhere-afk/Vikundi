<?php
/**
 * database/seed_demo_walkthrough.php
 * ----------------------------------
 * Fills in everything seed_demo_data.php does not: the parts of Vikundi a
 * prospect actually wants to click through.
 *
 * seed_demo_data.php produces the money — members, savings, loans, repayments,
 * fines, welfare. That leaves the governance and operations half of the system
 * looking abandoned: no leaders to log in as, no meetings, no elections, no
 * documents, no expenses, an empty audit trail. A demo where every screen but
 * the loan book is blank sells nothing.
 *
 * This script reads the roster back out of the database rather than sharing
 * state with the other seeder, so it can be run on its own against an already
 * populated demo site.
 *
 * Usage (CLI only), from the project root:
 *   php database/seed_demo_data.php --fresh          # money first
 *   php database/seed_demo_walkthrough.php --fresh   # then the rest
 *
 * Flags:
 *   --fresh              clear the tables this script owns, then seed
 *   --allow-nondemo-db   see database/demo_seed_guard.php
 *
 * Tables owned by this script (and cleared by --fresh):
 *   meetings, meeting_attendance, votes, vote_options, vote_ballots,
 *   vote_participation, vote_eligibility, leadership_applications,
 *   authored_documents, document_signatories, general_expenses,
 *   petty_cash_vouchers, mkoba_statement_rows, activity_logs.
 *
 * It also UPDATES group_settings and promotes three existing members into
 * leadership roles. It never writes to the contribution, loan or fine tables.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This seeder is CLI-only. Run it from the server shell, not the browser.\n");
}

require_once __DIR__ . '/../includes/config.php'; // provides $pdo
require_once __DIR__ . '/demo_seed_guard.php';

$fresh = in_array('--fresh', $argv, true);

vk_demo_seed_guard($pdo, $argv);

mt_srand(20260819); // reproducible
$now   = new DateTimeImmutable('today');
$stamp = static fn (DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');
$pick  = static fn (array $a) => $a[mt_rand(0, count($a) - 1)];

// ---------------------------------------------------------------------------
// The roster this script builds on. Without members there is nothing to elect,
// nothing to hold a meeting about and nobody to sign a document.
// ---------------------------------------------------------------------------
$roster = $pdo->query(
    'SELECT c.customer_id, c.user_id, c.customer_name, c.phone, c.created_at
       FROM customers c
      WHERE c.user_id IS NOT NULL AND c.user_id > 0
   ORDER BY c.customer_id'
)->fetchAll(PDO::FETCH_ASSOC);

if (count($roster) < 6) {
    fwrite(STDERR,
        "Refusing to seed: only " . count($roster) . " member(s) found.\n" .
        "  Run `php database/seed_demo_data.php --fresh` first — this script builds on that roster.\n");
    exit(1);
}

$adminId = (int) ($pdo->query(
    'SELECT user_id FROM users WHERE is_admin = 1 OR role_id IN (1,12) ORDER BY user_id LIMIT 1'
)->fetchColumn() ?: (int) $pdo->query('SELECT MIN(user_id) FROM users')->fetchColumn());

$ownedTables = [
    'meeting_attendance', 'meetings',
    'vote_ballots', 'vote_participation', 'vote_eligibility', 'leadership_applications',
    'vote_options', 'votes',
    'document_signatories', 'authored_documents',
    'general_expenses', 'petty_cash_vouchers', 'mkoba_statement_rows', 'activity_logs',
];

if ($fresh) {
    echo "Clearing walkthrough tables...\n";
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($ownedTables as $t) {
        $pdo->exec("TRUNCATE TABLE `{$t}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
} else {
    // Without --fresh, refuse to stack a second copy of the same governance
    // history on top of the first — duplicate elections and meetings make the
    // demo look broken rather than full.
    $existing = (int) $pdo->query('SELECT COUNT(*) FROM votes')->fetchColumn()
              + (int) $pdo->query('SELECT COUNT(*) FROM meetings')->fetchColumn();
    if ($existing > 0) {
        fwrite(STDERR,
            "Refusing to seed: {$existing} existing meeting/election row(s) found.\n" .
            "  Re-run with --fresh to clear the walkthrough tables and seed cleanly.\n");
        exit(1);
    }
}

$counts = [];
$bump = static function (string $k) use (&$counts): void { $counts[$k] = ($counts[$k] ?? 0) + 1; };

// ===========================================================================
// 1) Group branding
// ===========================================================================
$settings = [
    'group_name'           => 'Umoja VICOBA Group',
    'company_type'         => 'vicoba',
    'currency'             => 'TZS',
    'meeting_absence_fine' => '2000',
    'leadership_positions' => "Chairperson / Mwenyekiti\nVice Chairperson / Makamu Mwenyekiti\n"
                            . "Secretary / Katibu\nAssistant Secretary / Katibu Msaidizi\n"
                            . "Treasurer / Mweka Hazina\nCommittee Member / Mjumbe",
];
$setSetting = $pdo->prepare(
    'INSERT INTO group_settings (setting_key, setting_value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
);
foreach ($settings as $k => $v) {
    $setSetting->execute([$k, $v]);
    $bump('settings');
}

// ===========================================================================
// 2) Leadership
//
// Promote existing members rather than inventing standalone leader accounts.
// A Chairperson who is also a saving member is what the real thing looks like:
// they see the group-wide screens AND their own contribution statement, which
// is exactly the overlap a prospect needs to understand.
// ===========================================================================
$roleIds = [];
foreach ($pdo->query('SELECT role_id, role_name FROM roles') as $r) {
    $roleIds[strtolower($r['role_name'])] = (int) $r['role_id'];
}

$leadershipPlan = [
    'chairperson' => 0,
    'secretary'   => 1,
    'treasurer'   => 2,
];

$promote = $pdo->prepare(
    'UPDATE users SET role_id = ?, user_role = ?, role = ?, status = "active", is_active = 1
      WHERE user_id = ?'
);
$setPassword = $pdo->prepare('UPDATE users SET password = ? WHERE user_id = ?');
$leaders     = []; // role => ['user_id','customer_id','name','username']

const DEMO_PASSWORD = 'Demo@2026';

foreach ($leadershipPlan as $roleName => $rosterIndex) {
    if (!isset($roleIds[$roleName])) {
        continue; // role not present in this install; skip rather than invent one
    }
    $m       = $roster[$rosterIndex];
    $roleId  = $roleIds[$roleName];
    $label   = ucfirst($roleName);
    $promote->execute([$roleId, $label, $label, (int) $m['user_id']]);
    $setPassword->execute([password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT), (int) $m['user_id']]);

    $username = (string) $pdo->query(
        'SELECT username FROM users WHERE user_id = ' . (int) $m['user_id']
    )->fetchColumn();

    $leaders[$roleName] = [
        'user_id'     => (int) $m['user_id'],
        'customer_id' => (int) $m['customer_id'],
        'name'        => (string) $m['customer_name'],
        'username'    => $username,
    ];
    $bump('leaders');
}

// Give one ordinary member a known password too, so the Member view can be
// demonstrated without resetting anything.
$sampleMember = $roster[count($roster) - 1];
$setPassword->execute([password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT), (int) $sampleMember['user_id']]);
$sampleMemberUsername = (string) $pdo->query(
    'SELECT username FROM users WHERE user_id = ' . (int) $sampleMember['user_id']
)->fetchColumn();

$chairUser = $leaders['chairperson']['user_id'] ?? $adminId;
$secUser   = $leaders['secretary']['user_id']   ?? $adminId;
$treasUser = $leaders['treasurer']['user_id']   ?? $adminId;

// ===========================================================================
// 3) Meetings + attendance
// ===========================================================================
$insMeeting = $pdo->prepare(
    'INSERT INTO meetings (title, meeting_date, meeting_time, location, meeting_type,
                           agenda, minutes, status, created_by, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insAttend = $pdo->prepare(
    'INSERT INTO meeting_attendance (meeting_id, member_id, status, marked_by, created_at)
     VALUES (?, ?, ?, ?, ?)'
);

$agendaPool = [
    "1. Kufungua mkutano na sala\n2. Kusoma muhtasari wa mkutano uliopita\n3. Taarifa ya fedha\n"
    . "4. Maombi ya mikopo\n5. Mengineyo\n6. Kufunga mkutano",
    "1. Ufunguzi\n2. Taarifa ya Mweka Hazina\n3. Marejesho ya mikopo yaliyochelewa\n"
    . "4. Faini za kutohudhuria\n5. Mengineyo",
    "1. Ufunguzi\n2. Mapitio ya katiba ya kikundi\n3. Maandalizi ya uchaguzi\n"
    . "4. Mgao wa faida\n5. Kufunga mkutano",
];
$minutesPool = [
    'Mkutano ulianza saa 3:00 asubuhi. Muhtasari wa mkutano uliopita ulisomwa na kupitishwa bila '
    . 'marekebisho. Mweka Hazina aliwasilisha taarifa ya fedha na wanachama waliridhika. Maombi '
    . 'matatu ya mikopo yaliwasilishwa; mawili yalipitishwa na moja likaahirishwa kwa nyaraka '
    . 'pungufu. Mkutano ulifungwa saa 6:00 mchana.',
    'Wajumbe walijadili suala la marejesho yaliyochelewa. Iliamuliwa kuwa mwanachama '
    . 'anayechelewa zaidi ya siku 30 atatozwa faini kwa mujibu wa katiba. Taarifa ya fedha '
    . 'ilipitishwa. Mkutano ulifungwa kwa sala.',
    'Kikundi kilipitia rasimu ya marekebisho ya katiba. Wajumbe walikubaliana kuandaa uchaguzi '
    . 'wa uongozi ndani ya miezi miwili. Mgao wa faida uliwasilishwa na kupitishwa.',
];
$locations = ['Ukumbi wa Kata, Kinondoni', 'Nyumbani kwa Mwenyekiti', 'Ofisi ya Kikundi, Sinza'];

$meetingIds = [];
for ($i = 8; $i >= 1; $i--) {
    $date  = $now->modify("-{$i} months")->modify('+' . mt_rand(2, 12) . ' days');
    $type  = $i === 8 ? 'agm' : ($i % 4 === 0 ? 'special' : 'regular');
    $title = match ($type) {
        'agm'     => 'Mkutano Mkuu wa Mwaka (AGM)',
        'special' => 'Mkutano Maalum — ' . $date->format('F Y'),
        default   => 'Mkutano wa Kawaida — ' . $date->format('F Y'),
    };
    $insMeeting->execute([
        $title, $date->format('Y-m-d'), '09:00:00', $pick($locations), $type,
        $pick($agendaPool), $pick($minutesPool), 'held', $secUser, $stamp($date),
    ]);
    $mid = (int) $pdo->lastInsertId();
    $meetingIds[] = $mid;
    $bump('meetings');

    // Attendance for every member who had already joined by the meeting date.
    foreach ($roster as $m) {
        if (new DateTimeImmutable((string) $m['created_at']) > $date) {
            continue;
        }
        $present = mt_rand(1, 100) <= 85 ? 'present' : 'absent';
        $insAttend->execute([$mid, (int) $m['customer_id'], $present, $secUser, $stamp($date)]);
        $bump('attendance');
    }
}

// One upcoming meeting, so the "scheduled" state is visible too.
$next = $now->modify('+' . mt_rand(6, 20) . ' days');
$insMeeting->execute([
    'Mkutano wa Kawaida — ' . $next->format('F Y'), $next->format('Y-m-d'), '09:00:00',
    $locations[0], 'regular', $agendaPool[0], null, 'scheduled', $secUser, $stamp($now),
]);
$bump('meetings');

// ===========================================================================
// 4) Elections
//
// Four states, because each renders a different screen: a closed election with
// published results, a second closed one, an election currently open for
// voting, and a motion still in draft.
// ===========================================================================
$insVote = $pdo->prepare(
    'INSERT INTO votes (title, description, vote_type, status, opens_at, closes_at,
                        publish_results, created_by, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insOption   = $pdo->prepare('INSERT INTO vote_options (vote_id, label, member_id, position) VALUES (?, ?, ?, ?)');
$insEligible = $pdo->prepare('INSERT INTO vote_eligibility (vote_id, member_id) VALUES (?, ?)');
$insBallot   = $pdo->prepare('INSERT INTO vote_ballots (vote_id, option_id, created_at) VALUES (?, ?, ?)');
$insPart     = $pdo->prepare('INSERT INTO vote_participation (vote_id, member_id, voted_at) VALUES (?, ?, ?)');

/**
 * Create one election and, for a closed or open vote, cast a plausible spread
 * of secret ballots. Ballots and participation rows are inserted in equal
 * number but never linked, which is exactly how actions/cast_vote.php keeps
 * the choice anonymous while still preventing a second vote.
 *
 * @param array $candidateIdx roster indices standing for election
 * @return array{0:int,1:array<int,int>} vote id, and option_id per candidate index
 */
$makeElection = function (
    string $title, string $desc, string $status, array $candidateIdx,
    DateTimeImmutable $opens, DateTimeImmutable $closes, bool $publish, float $turnout
) use ($pdo, $roster, $insVote, $insOption, $insEligible, $insBallot, $insPart, $chairUser, $stamp, $bump): array {
    $insVote->execute([
        $title, $desc, 'candidate', $status, $stamp($opens), $stamp($closes),
        $publish ? 1 : 0, $chairUser, $stamp($opens->modify('-7 days')),
    ]);
    $voteId = (int) $pdo->lastInsertId();
    $bump('elections');

    $optionIds = [];
    $pos = 1;
    foreach ($candidateIdx as $idx) {
        $m = $roster[$idx];
        $insOption->execute([$voteId, (string) $m['customer_name'], (int) $m['customer_id'], $pos++]);
        $optionIds[$idx] = (int) $pdo->lastInsertId();
        $bump('candidates');
    }

    // Everyone on the roster may vote.
    foreach ($roster as $m) {
        $insEligible->execute([$voteId, (int) $m['customer_id']]);
        $bump('eligibility');
    }

    if ($status === 'draft') {
        return [$voteId, $optionIds];
    }

    // Weight the candidates so there is a clear winner rather than a tie.
    $weights = [];
    $w = count($optionIds) + 2;
    foreach (array_keys($optionIds) as $idx) {
        $weights[$idx] = $w--;
    }
    $bag = [];
    foreach ($weights as $idx => $weight) {
        for ($i = 0; $i < $weight; $i++) { $bag[] = $idx; }
    }

    $voters = $roster;
    shuffle($voters);
    $voterCount = (int) round(count($voters) * $turnout);
    foreach (array_slice($voters, 0, $voterCount) as $v) {
        $when   = $opens->modify('+' . mt_rand(0, max(1, (int) $opens->diff($closes)->days)) . ' days');
        $choice = $bag[mt_rand(0, count($bag) - 1)];
        $insBallot->execute([$voteId, $optionIds[$choice], $stamp($when)]);
        $insPart->execute([$voteId, (int) $v['customer_id'], $stamp($when)]);
        $bump('ballots');
    }

    return [$voteId, $optionIds];
};

$idxPool = range(0, count($roster) - 1);

[$chairVoteId, $chairOptions] = $makeElection(
    'Uchaguzi wa Mwenyekiti 2026',
    'Uchaguzi wa Mwenyekiti wa kikundi kwa kipindi cha miaka miwili (2026–2028).',
    'closed', [$idxPool[0], $idxPool[3], $idxPool[4]],
    $now->modify('-90 days'), $now->modify('-83 days'), true, 0.87
);

[$secVoteId, $secOptions] = $makeElection(
    'Uchaguzi wa Katibu 2026',
    'Uchaguzi wa Katibu wa kikundi kwa kipindi cha miaka miwili (2026–2028).',
    'closed', [$idxPool[1], $idxPool[5]],
    $now->modify('-90 days'), $now->modify('-83 days'), true, 0.83
);

[$treasVoteId, $treasOptions] = $makeElection(
    'Uchaguzi wa Mweka Hazina 2026',
    'Uchaguzi unaoendelea. Piga kura kabla ya tarehe ya kufunga.',
    'open', [$idxPool[2], $idxPool[6], $idxPool[7]],
    $now->modify('-3 days'), $now->modify('+4 days'), false, 0.45
);

$insVote->execute([
    'Marekebisho ya Katiba — Kiwango cha Mchango',
    'Pendekezo la kupandisha mchango wa mwezi kutoka TZS 50,000 hadi TZS 60,000.',
    'motion', 'draft', $stamp($now->modify('+10 days')), $stamp($now->modify('+17 days')),
    0, $chairUser, $stamp($now),
]);
$motionId = (int) $pdo->lastInsertId();
$bump('elections');
foreach (['Naunga mkono (Yes)', 'Sinaungi mkono (No)'] as $i => $label) {
    $insOption->execute([$motionId, $label, null, $i + 1]);
    $bump('candidates');
}
foreach ($roster as $m) {
    $insEligible->execute([$motionId, (int) $m['customer_id']]);
    $bump('eligibility');
}

// ===========================================================================
// 5) Leadership applications
//
// Approved applications carry the vote_option_id they became; pending and
// rejected ones sit on the open election so the review screen has work in it.
// ===========================================================================
$insApp = $pdo->prepare(
    'INSERT INTO leadership_applications
        (vote_id, member_id, position, statement, experience, proposer_member_id, declaration,
         status, review_note, reviewed_by, reviewed_at, vote_option_id, created_at)
     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)'
);

$statements = [
    'Nimekuwa mwanachama wa kikundi hiki kwa miaka mitatu na sijawahi kukosa mchango wa mwezi. '
    . 'Nikichaguliwa nitahakikisha taarifa za fedha zinawasilishwa kila mwezi kwa uwazi.',
    'Nina uzoefu wa kusimamia biashara ndogo na kuweka kumbukumbu za mahesabu. Nitatumia uzoefu '
    . 'huo kuimarisha usimamizi wa fedha za kikundi.',
    'Lengo langu ni kuongeza idadi ya wanachama na kuhakikisha mikopo inarejeshwa kwa wakati.',
];
$experiences = [
    'Katibu wa kikundi cha akina mama cha Sinza (2022–2024).',
    'Mhasibu msaidizi katika SACCOS ya wafanyabiashara, Kariakoo (2021–2025).',
    'Mjumbe wa kamati ya mikopo ya kikundi hiki tangu 2024.',
    'Mwalimu mstaafu; nilikuwa mweka hazina wa chama cha wazazi.',
];

$reviewedAt = $now->modify('-95 days');
foreach ([[$chairVoteId, $chairOptions, 'Chairperson / Mwenyekiti'],
          [$secVoteId, $secOptions, 'Secretary / Katibu'],
          [$treasVoteId, $treasOptions, 'Treasurer / Mweka Hazina']] as [$vid, $opts, $position]) {
    foreach ($opts as $idx => $optionId) {
        $m = $roster[$idx];
        $insApp->execute([
            $vid, (int) $m['customer_id'], $position, $pick($statements), $pick($experiences),
            (int) $roster[($idx + 1) % count($roster)]['customer_id'], 'approved',
            'Ameidhinishwa; ana sifa zote zinazotakiwa.', $chairUser, $stamp($reviewedAt),
            $optionId, $stamp($reviewedAt->modify('-5 days')),
        ]);
        $bump('applications');
    }
}

// Still awaiting review on the open election.
foreach ([8, 9] as $idx) {
    if (!isset($roster[$idx])) { continue; }
    $m = $roster[$idx];
    $insApp->execute([
        $treasVoteId, (int) $m['customer_id'], 'Committee Member / Mjumbe',
        $pick($statements), $pick($experiences),
        (int) $roster[($idx + 2) % count($roster)]['customer_id'], 'pending',
        null, null, null, null, $stamp($now->modify('-' . mt_rand(1, 5) . ' days')),
    ]);
    $bump('applications');
}

// One rejected, with the reason recorded.
if (isset($roster[10])) {
    $insApp->execute([
        $treasVoteId, (int) $roster[10]['customer_id'], 'Treasurer / Mweka Hazina',
        $pick($statements), 'Hakuna uzoefu wa usimamizi wa fedha.',
        (int) $roster[11 % count($roster)]['customer_id'], 'rejected',
        'Ana deni la mkopo ambalo halijalipwa; kwa mujibu wa katiba hawezi kugombea.',
        $chairUser, $stamp($now->modify('-4 days')), null, $stamp($now->modify('-6 days')),
    ]);
    $bump('applications');
}

// ===========================================================================
// 6) Documents + e-signatures
// ===========================================================================
$insDoc = $pdo->prepare(
    'INSERT INTO authored_documents (title, doc_type, body_html, use_letterhead, status,
                                     visibility, created_by, created_at)
     VALUES (?, ?, ?, 1, ?, ?, ?, ?)'
);
$insSig = $pdo->prepare(
    'INSERT INTO document_signatories (document_id, user_id, role_label, sign_order, status,
                                       signed_at, assigned_by, assigned_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

$docs = [
    [
        'Mwaliko wa Mkutano Mkuu wa Mwaka 2026', 'letter', 'final', 'shared',
        '<p>Ndugu Mwanachama,</p><p>Kwa niaba ya Kamati ya Uongozi, tunakukaribisha kwenye '
        . '<strong>Mkutano Mkuu wa Mwaka</strong> utakaofanyika tarehe iliyotajwa hapo juu.</p>'
        . '<p>Ajenda kuu ni taarifa ya fedha ya mwaka, mgao wa faida, na uchaguzi wa uongozi.</p>'
        . '<p>Kuhudhuria ni lazima kwa mujibu wa katiba. Asiyehudhuria bila taarifa atatozwa faini '
        . 'ya TZS 2,000.</p><p>Wako katika ujenzi wa kikundi,</p>',
        [['chairperson', 'Mwenyekiti', 'signed'], ['secretary', 'Katibu', 'signed']],
    ],
    [
        'Taarifa ya Fedha — Robo ya Tatu 2026', 'notice', 'final', 'shared',
        '<p>Taarifa hii inaonyesha hali ya fedha ya kikundi kwa kipindi cha robo ya tatu.</p>'
        . '<ul><li>Jumla ya akiba iliyokusanywa</li><li>Mikopo iliyotolewa na marejesho</li>'
        . '<li>Faini zilizokusanywa</li><li>Matumizi yaliyoidhinishwa</li></ul>'
        . '<p>Nakala kamili inapatikana kwa Mweka Hazina.</p>',
        [['treasurer', 'Mweka Hazina', 'signed'], ['chairperson', 'Mwenyekiti', 'pending']],
    ],
    [
        'Mkataba wa Mkopo — Rasimu', 'contract', 'draft', 'private',
        '<p>Mkataba huu unafanywa kati ya <strong>Umoja VICOBA Group</strong> (Mkopeshaji) na '
        . 'mwanachama aliyetajwa (Mkopaji).</p><p>Masharti: riba ya asilimia 10 kwa mwaka, '
        . 'muda wa marejesho miezi 12, na dhamana ya wanachama wawili.</p>'
        . '<p><em>Rasimu — bado haijapitishwa na kamati.</em></p>',
        [],
    ],
    [
        'Notice of Overdue Loan Repayment', 'letter', 'final', 'shared',
        '<p>Dear Member,</p><p>Our records show that your loan repayment is overdue by more than '
        . 'thirty (30) days. Under the group constitution a late-payment penalty now applies.</p>'
        . '<p>Please settle the outstanding balance, or contact the Treasurer to agree a revised '
        . 'schedule, before the next monthly meeting.</p><p>Yours faithfully,</p>',
        [['treasurer', 'Treasurer', 'signed']],
    ],
];

foreach ($docs as [$title, $type, $status, $visibility, $body, $signers]) {
    $created = $now->modify('-' . mt_rand(5, 70) . ' days');
    $insDoc->execute([$title, $type, $body, $status, $visibility, $secUser, $stamp($created)]);
    $docId = (int) $pdo->lastInsertId();
    $bump('documents');

    $order = 1;
    foreach ($signers as [$roleKey, $label, $sigStatus]) {
        if (!isset($leaders[$roleKey])) { continue; }
        $signedAt = $sigStatus === 'signed' ? $stamp($created->modify('+' . mt_rand(1, 5) . ' days')) : null;
        $insSig->execute([
            $docId, $leaders[$roleKey]['user_id'], $label, $order++, $sigStatus,
            $signedAt, $secUser, $stamp($created),
        ]);
        $bump('signatories');
    }
}

// ===========================================================================
// 7) Expenses — general and petty cash, across every status
// ===========================================================================
$insGenExp = $pdo->prepare(
    'INSERT INTO general_expenses (expense_date, description, amount, status, created_by,
                                   created_at, reviewed_by, reviewed_at, approved_by, approved_at,
                                   paid_by, paid_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$expenseItems = [
    ['Vitabu vya kumbukumbu na kalamu', 45000],
    ['Kodi ya ukumbi wa mkutano (miezi 3)', 150000],
    ['Chai na vitafunio vya Mkutano Mkuu', 220000],
    ['Ada ya usajili wa kikundi', 100000],
    ['Vocha za simu kwa uongozi', 60000],
    ['Sanduku la fedha (cash box)', 85000],
    ['Nauli ya uongozi kwenda mafunzo', 120000],
    ['Uchapishaji wa katiba ya kikundi', 75000],
];
$expenseStatuses = ['paid', 'paid', 'approved', 'reviewed', 'pending', 'rejected', 'paid', 'approved'];

foreach ($expenseItems as $i => [$desc, $amount]) {
    $status  = $expenseStatuses[$i] ?? 'pending';
    $created = $now->modify('-' . (5 + $i * 11) . ' days');

    $reviewedBy = $reviewedAtX = $approvedBy = $approvedAtX = $paidBy = $paidAtX = null;
    if (in_array($status, ['reviewed', 'approved', 'rejected', 'paid'], true)) {
        $reviewedBy  = $secUser;
        $reviewedAtX = $stamp($created->modify('+1 day'));
    }
    if (in_array($status, ['approved', 'paid'], true)) {
        $approvedBy  = $chairUser;
        $approvedAtX = $stamp($created->modify('+2 days'));
    }
    if ($status === 'paid') {
        $paidBy  = $treasUser;
        $paidAtX = $stamp($created->modify('+3 days'));
    }

    $insGenExp->execute([
        $created->format('Y-m-d'), $desc, $amount, $status, $treasUser, $stamp($created),
        $reviewedBy, $reviewedAtX, $approvedBy, $approvedAtX, $paidBy, $paidAtX,
    ]);
    $bump('general_expenses');
}

$insPetty = $pdo->prepare(
    'INSERT INTO petty_cash_vouchers (voucher_no, transaction_date, payee_name, description, amount,
                                      category, prepared_by, status, approved_by, approval_date,
                                      created_at, reviewed_by, reviewed_at, paid_by, paid_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$pettyItems = [
    ['Duka la Vifaa vya Ofisi', 'Karatasi na wino wa printa', 35000, 'Stationery', 'paid'],
    ['Bodaboda — Juma Ally', 'Usafiri wa kupeleka nyaraka benki', 8000, 'Transport', 'paid'],
    ['Mama Ntilie — Sinza', 'Chai ya mkutano wa kamati', 25000, 'Refreshments', 'approved'],
    ['Fundi Umeme', 'Matengenezo ya taa za ofisi', 40000, 'Maintenance', 'reviewed'],
    ['Vodacom Tanzania', 'Vocha za simu za kikundi', 20000, 'Communication', 'pending'],
];
foreach ($pettyItems as $i => [$payee, $desc, $amount, $cat, $status]) {
    $date = $now->modify('-' . (3 + $i * 9) . ' days');
    $insPetty->execute([
        'PCV-' . $now->format('Y') . '-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
        $date->format('Y-m-d'), $payee, $desc, $amount, $cat, $treasUser, $status,
        in_array($status, ['approved', 'paid'], true) ? $chairUser : null,
        in_array($status, ['approved', 'paid'], true) ? $stamp($date->modify('+2 days')) : null,
        $stamp($date),
        in_array($status, ['reviewed', 'approved', 'paid'], true) ? $secUser : null,
        in_array($status, ['reviewed', 'approved', 'paid'], true) ? $stamp($date->modify('+1 day')) : null,
        $status === 'paid' ? $treasUser : null,
        $status === 'paid' ? $stamp($date->modify('+3 days')) : null,
    ]);
    $bump('petty_cash');
}

// ===========================================================================
// 8) M-Koba reconciliation
//
// A statement import that did not go perfectly: most rows matched a member and
// were imported, a few were excluded, one member paid but is missing from the
// statement. That mix is the entire point of the reconciliation screen.
// ===========================================================================
$insMkoba = $pdo->prepare(
    'INSERT INTO mkoba_statement_rows (batch, sno, trans_id, receipt, trans_date, member_name,
                                       member_id, source, destination, amount, trans_type,
                                       outcome, reason, contribution_id, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$batch     = 'MKOBA-' . $now->modify('-1 month')->format('Y-m');
$batchDate = $now->modify('-1 month');
$sno = 0;
foreach (array_slice($roster, 0, min(22, count($roster))) as $m) {
    $sno++;
    $r = mt_rand(1, 100);
    if ($r <= 80) {
        $outcome = 'imported';
        $reason  = null;
    } elseif ($r <= 92) {
        $outcome = 'excluded';
        $reason  = $pick([
            'Jina halilingani na mwanachama yeyote (name not matched)',
            'Muamala umerudiwa (duplicate transaction id)',
            'Kiasi ni sifuri (zero amount)',
        ]);
    } else {
        $outcome = 'missing';
        $reason  = 'Mwanachama amelipa lakini muamala haupo kwenye taarifa (paid, not on statement)';
    }
    $insMkoba->execute([
        $batch, (string) $sno,
        'MK' . strtoupper(bin2hex(random_bytes(5))),
        'RCP' . mt_rand(100000, 999999),
        $batchDate->modify('+' . mt_rand(0, 20) . ' days')->format('Y-m-d'),
        (string) $m['customer_name'], (string) $m['customer_id'],
        (string) $m['phone'], 'UMOJA VICOBA', (float) $pick([30000, 50000, 50000, 100000]),
        'Mchango wa mwezi', $outcome, $reason, null, $stamp($batchDate),
    ]);
    $bump('mkoba_rows');
}

// ===========================================================================
// 9) Audit trail
//
// An empty Activity Logs screen makes the system look like it records nothing,
// which is the opposite of what a VICOBA committee is buying.
// ===========================================================================
$insLog = $pdo->prepare(
    'INSERT INTO activity_logs (user_id, action, module, ip_address, user_agent, description,
                                reference, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$logTemplates = [
    ['login',  'auth',          'Ameingia kwenye mfumo'],
    ['view',   'members',       'Ameangalia orodha ya wanachama'],
    ['create', 'contributions', 'Amesajili mchango wa mwezi'],
    ['create', 'loans',         'Ameingiza ombi jipya la mkopo'],
    ['update', 'loans',         'Amebadilisha hali ya mkopo'],
    ['approve','expenses',      'Ameidhinisha matumizi'],
    ['create', 'meetings',      'Ameandaa mkutano mpya'],
    ['update', 'meetings',      'Amehifadhi mahudhurio ya mkutano'],
    ['create', 'voting',        'Amefungua uchaguzi'],
    ['view',   'reports',       'Amechapisha ripoti ya fedha'],
    ['create', 'documents',     'Ameandika waraka mpya'],
    ['update', 'settings',      'Amebadilisha mipangilio ya kikundi'],
];
$actors = array_values(array_filter([$adminId, $chairUser, $secUser, $treasUser]));
for ($i = 0; $i < 140; $i++) {
    [$action, $module, $desc] = $pick($logTemplates);
    $when = $now->modify('-' . mt_rand(0, 120) . ' days')
                ->modify('+' . mt_rand(7, 17) . ' hours')
                ->modify('+' . mt_rand(0, 59) . ' minutes');
    $insLog->execute([
        $pick($actors), $action, $module,
        '41.222.' . mt_rand(1, 254) . '.' . mt_rand(1, 254),
        'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36',
        $desc, null, $stamp($when),
    ]);
    $bump('activity_logs');
}

// ===========================================================================
// Summary
// ===========================================================================
echo "\n== Walkthrough demo data seeded ==\n";
foreach ($counts as $k => $v) {
    printf("  %-18s %d\n", $k . ':', $v);
}

echo "\n== Demo logins (password: " . DEMO_PASSWORD . ") ==\n";
foreach ($leaders as $roleName => $l) {
    printf("  %-14s %-14s %s\n", ucfirst($roleName), $l['username'], $l['name']);
}
printf("  %-14s %-14s %s\n", 'Member', $sampleMemberUsername, $sampleMember['customer_name']);
echo "\nEvery other seeded member logs in with username + '@123' (e.g. jmushi / jmushi@123).\n";
echo "Done.\n";
