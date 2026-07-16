<?php
/**
 * database/seed_document_templates.php
 * ------------------------------------
 * Ready-to-use starter templates for the Document Writer, in English and Swahili.
 * Each uses the merge fields ({member_name}, {member_contributions}, {group_name},
 * {today}, {author_name}, {author_role}) so a leader can produce a finished,
 * personalised document with almost no typing.
 *
 * Idempotent: a template is inserted only when one with the same name does not
 * already exist, so this never duplicates and never overwrites edits a group has
 * made to a template of the same name.
 *
 * Run manually:  php database/seed_document_templates.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/document_sanitizer.php';

$exists = $pdo->query("SHOW TABLES LIKE 'authored_document_templates'")->fetchColumn();
if (!$exists) {
    echo "  authored_document_templates not present yet; skipped (its migration runs first).\n";
    return;
}

/** name => [doc_type, use_letterhead, body_html] */
$templates = [

    // 1 · Meeting notice ------------------------------------------------------
    'Meeting Notice (English)' => ['notice', 1, '
        <h3 style="text-align:center;">NOTICE OF MEETING</h3>
        <p><strong>{group_name}</strong><br>Date: {today}</p>
        <p>Dear Members,</p>
        <p>This is to notify all members of <strong>{group_name}</strong> that a group meeting will be held as follows:</p>
        <ul>
            <li><strong>Date:</strong> [enter meeting date]</li>
            <li><strong>Time:</strong> [enter time]</li>
            <li><strong>Venue:</strong> [enter venue]</li>
        </ul>
        <p><strong>Agenda:</strong></p>
        <ol><li>[agenda item]</li><li>[agenda item]</li></ol>
        <p>All members are kindly requested to attend on time.</p>
        <p>Yours faithfully,</p>
        <p><strong>{author_name}</strong><br>{author_role}</p>'],

    'Taarifa ya Mkutano (Kiswahili)' => ['notice', 1, '
        <h3 style="text-align:center;">TAARIFA YA MKUTANO</h3>
        <p><strong>{group_name}</strong><br>Tarehe: {today}</p>
        <p>Ndugu Wanachama,</p>
        <p>Hii ni taarifa kwa wanachama wote wa <strong>{group_name}</strong> kwamba mkutano wa kikundi utafanyika kama ifuatavyo:</p>
        <ul>
            <li><strong>Tarehe:</strong> [weka tarehe ya mkutano]</li>
            <li><strong>Muda:</strong> [weka muda]</li>
            <li><strong>Mahali:</strong> [weka mahali]</li>
        </ul>
        <p><strong>Ajenda:</strong></p>
        <ol><li>[jambo la ajenda]</li><li>[jambo la ajenda]</li></ol>
        <p>Wanachama wote mnaombwa kuhudhuria kwa wakati.</p>
        <p>Wenu,</p>
        <p><strong>{author_name}</strong><br>{author_role}</p>'],

    // 2 · Contribution confirmation ------------------------------------------
    'Contribution Confirmation Letter (English)' => ['letter', 1, '
        <p style="text-align:right;">{today}</p>
        <p><strong>TO WHOM IT MAY CONCERN</strong></p>
        <h3 style="text-align:center;">CONFIRMATION OF CONTRIBUTIONS</h3>
        <p>Dear {member_name},</p>
        <p>This letter confirms that you are a member of <strong>{group_name}</strong> and that your total confirmed contributions to date stand at <strong>{member_contributions}</strong>.</p>
        <p>This confirmation is issued at your request for whatever lawful purpose it may serve.</p>
        <p>Yours faithfully,</p>
        <p><strong>{author_name}</strong><br>{author_role}<br>{group_name}</p>'],

    'Barua ya Uthibitisho wa Michango (Kiswahili)' => ['letter', 1, '
        <p style="text-align:right;">{today}</p>
        <p><strong>KWA ANAYEHUSIKA</strong></p>
        <h3 style="text-align:center;">UTHIBITISHO WA MICHANGO</h3>
        <p>Ndugu {member_name},</p>
        <p>Barua hii inathibitisha kuwa wewe ni mwanachama wa <strong>{group_name}</strong> na kwamba jumla ya michango yako iliyothibitishwa hadi sasa ni <strong>{member_contributions}</strong>.</p>
        <p>Uthibitisho huu umetolewa kwa ombi lako kwa matumizi yoyote halali.</p>
        <p>Wako,</p>
        <p><strong>{author_name}</strong><br>{author_role}<br>{group_name}</p>'],

    // 3 · Welcome letter ------------------------------------------------------
    'New Member Welcome Letter (English)' => ['letter', 1, '
        <p style="text-align:right;">{today}</p>
        <h3 style="text-align:center;">WELCOME TO {group_name}</h3>
        <p>Dear {member_name},</p>
        <p>On behalf of all members and leadership, it is our pleasure to welcome you as a new member of <strong>{group_name}</strong>.</p>
        <p>As a member, you are encouraged to attend all meetings, make your contributions regularly, and take part in the activities and decisions of the group. Together we save, support one another, and grow.</p>
        <p>We look forward to a long and fruitful membership.</p>
        <p>Warm regards,</p>
        <p><strong>{author_name}</strong><br>{author_role}</p>'],

    'Barua ya Kumkaribisha Mwanachama Mpya (Kiswahili)' => ['letter', 1, '
        <p style="text-align:right;">{today}</p>
        <h3 style="text-align:center;">KARIBU {group_name}</h3>
        <p>Ndugu {member_name},</p>
        <p>Kwa niaba ya wanachama wote na uongozi, tunayo furaha kukukaribisha kama mwanachama mpya wa <strong>{group_name}</strong>.</p>
        <p>Kama mwanachama, unahimizwa kuhudhuria mikutano yote, kuchangia mara kwa mara, na kushiriki katika shughuli na maamuzi ya kikundi. Kwa pamoja tunaweka akiba, tunasaidiana, na tunakua.</p>
        <p>Tunatarajia uanachama wa muda mrefu na wenye manufaa.</p>
        <p>Kwa heshima,</p>
        <p><strong>{author_name}</strong><br>{author_role}</p>'],

    // 4 · Membership certificate ---------------------------------------------
    'Membership Certificate (English)' => ['other', 1, '
        <h2 style="text-align:center;">CERTIFICATE OF MEMBERSHIP</h2>
        <p style="text-align:center;">This is to certify that</p>
        <h3 style="text-align:center;">{member_name}</h3>
        <p style="text-align:center;">is a registered and recognised member in good standing of</p>
        <h3 style="text-align:center;">{group_name}</h3>
        <p style="text-align:center;">Issued on {today}</p>
        <p style="text-align:center;">&nbsp;</p>
        <p style="text-align:center;">_____________________________<br><strong>{author_name}</strong><br>{author_role}</p>'],

    'Cheti cha Uanachama (Kiswahili)' => ['other', 1, '
        <h2 style="text-align:center;">CHETI CHA UANACHAMA</h2>
        <p style="text-align:center;">Hiki ni kuthibitisha kwamba</p>
        <h3 style="text-align:center;">{member_name}</h3>
        <p style="text-align:center;">ni mwanachama aliyesajiliwa na anayetambulika mwenye hadhi nzuri wa</p>
        <h3 style="text-align:center;">{group_name}</h3>
        <p style="text-align:center;">Kimetolewa tarehe {today}</p>
        <p style="text-align:center;">&nbsp;</p>
        <p style="text-align:center;">_____________________________<br><strong>{author_name}</strong><br>{author_role}</p>'],

    // 5 · Contribution reminder ----------------------------------------------
    'Contribution Reminder Letter (English)' => ['letter', 1, '
        <p style="text-align:right;">{today}</p>
        <h3 style="text-align:center;">FRIENDLY REMINDER</h3>
        <p>Dear {member_name},</p>
        <p>We hope this message finds you well. This is a gentle reminder regarding your contributions to <strong>{group_name}</strong>.</p>
        <p>Our records show your confirmed contributions to date total <strong>{member_contributions}</strong>. We kindly encourage you to keep your contributions up to date so that you continue to enjoy the full benefits of membership and help the group grow.</p>
        <p>If you have already made a payment that is not yet reflected, please disregard this reminder or contact the treasurer.</p>
        <p>Thank you for your continued commitment.</p>
        <p>Yours faithfully,</p>
        <p><strong>{author_name}</strong><br>{author_role}</p>'],

    'Barua ya Kumkumbusha Michango (Kiswahili)' => ['letter', 1, '
        <p style="text-align:right;">{today}</p>
        <h3 style="text-align:center;">UKUMBUSHO</h3>
        <p>Ndugu {member_name},</p>
        <p>Tunatumaini u mzima. Huu ni ukumbusho wa taratibu kuhusu michango yako katika <strong>{group_name}</strong>.</p>
        <p>Kumbukumbu zetu zinaonyesha jumla ya michango yako iliyothibitishwa hadi sasa ni <strong>{member_contributions}</strong>. Tunakuhimiza kuendelea kuchangia kwa wakati ili uendelee kunufaika na uanachama na kusaidia kikundi kukua.</p>
        <p>Kama tayari umelipa na haijaonyeshwa, tafadhali puuza ukumbusho huu au wasiliana na mweka hazina.</p>
        <p>Asante kwa kujitolea kwako.</p>
        <p>Wako,</p>
        <p><strong>{author_name}</strong><br>{author_role}</p>'],

    // 6 · General announcement -----------------------------------------------
    'General Announcement (English)' => ['notice', 1, '
        <h3 style="text-align:center;">ANNOUNCEMENT</h3>
        <p><strong>{group_name}</strong><br>Date: {today}</p>
        <p>Dear Members,</p>
        <p>[Type your announcement here.]</p>
        <p>Thank you.</p>
        <p><strong>{author_name}</strong><br>{author_role}</p>'],

    'Tangazo la Jumla (Kiswahili)' => ['notice', 1, '
        <h3 style="text-align:center;">TANGAZO</h3>
        <p><strong>{group_name}</strong><br>Tarehe: {today}</p>
        <p>Ndugu Wanachama,</p>
        <p>[Andika tangazo lako hapa.]</p>
        <p>Asante.</p>
        <p><strong>{author_name}</strong><br>{author_role}</p>'],

    // 7 · Member reference letter --------------------------------------------
    'Member Reference Letter (English)' => ['letter', 1, '
        <p style="text-align:right;">{today}</p>
        <p><strong>TO WHOM IT MAY CONCERN</strong></p>
        <h3 style="text-align:center;">LETTER OF REFERENCE</h3>
        <p>We, <strong>{group_name}</strong>, hereby confirm that <strong>{member_name}</strong> is a member of our group in good standing.</p>
        <p>During their membership, they have conducted themselves honourably and met their obligations to the group. Their confirmed contributions to date total <strong>{member_contributions}</strong>.</p>
        <p>We therefore recommend them and issue this letter to support any lawful request they may make.</p>
        <p>Yours faithfully,</p>
        <p><strong>{author_name}</strong><br>{author_role}<br>{group_name}</p>'],

    'Barua ya Rejea ya Mwanachama (Kiswahili)' => ['letter', 1, '
        <p style="text-align:right;">{today}</p>
        <p><strong>KWA ANAYEHUSIKA</strong></p>
        <h3 style="text-align:center;">BARUA YA REJEA</h3>
        <p>Sisi, <strong>{group_name}</strong>, tunathibitisha kwamba <strong>{member_name}</strong> ni mwanachama wa kikundi chetu mwenye hadhi nzuri.</p>
        <p>Katika kipindi cha uanachama wake, amejiendesha kwa heshima na ametimiza wajibu wake kwa kikundi. Jumla ya michango yake iliyothibitishwa hadi sasa ni <strong>{member_contributions}</strong>.</p>
        <p>Kwa hiyo tunampendekeza na kutoa barua hii kuunga mkono ombi lolote halali atakalofanya.</p>
        <p>Wako,</p>
        <p><strong>{author_name}</strong><br>{author_role}<br>{group_name}</p>'],
];

$find = $pdo->prepare("SELECT id FROM authored_document_templates WHERE name = ? LIMIT 1");
$ins  = $pdo->prepare(
    "INSERT INTO authored_document_templates (name, doc_type, body_html, use_letterhead, created_by)
     VALUES (?, ?, ?, ?, NULL)"
);

$added = 0;
foreach ($templates as $name => [$type, $letterhead, $html]) {
    $find->execute([$name]);
    if ($find->fetchColumn()) { continue; }             // already present — leave it alone
    // Normalise whitespace and run through the same sanitiser the editor uses.
    $body = vk_sanitize_document_html(trim(preg_replace('/\s+/', ' ', $html)));
    $ins->execute([$name, $type, $body, $letterhead]);
    $added++;
}

echo "Document Writer starter templates: $added added (" . count($templates) . " defined; existing ones left unchanged).\n";
