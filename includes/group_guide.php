<?php
/**
 * includes/group_guide.php — the welcome guide every group gets at /guide.
 *
 * One guide for every group, filled in from that group: its name, its address,
 * the features the super-admin gave it (no Shares or Dividends sections when they
 * are off), and, for a signed-in user, the group's own rules (contribution,
 * Social Fund, deadline, late-payment rules, loan types). English and Kiswahili.
 *
 * vk_guide_sections() is pure: guide.php gathers the context and renders.
 * Menu and button names stay in English in both languages, as on screen.
 */

const VK_GUIDE_SUPPORT_PHONE = '+255 764 764 011';
const VK_GUIDE_SUPPORT_EMAIL = 'info@bjptechnologies.co.tz';

if (!function_exists('vk_guide_parts')) {
    /** The guide's four parts, in order. */
    function vk_guide_parts(bool $sw): array
    {
        return [
            'start'   => $sw ? 'Kuanza' : 'Getting started',
            'first'   => $sw ? 'Hatua za kwanza' : 'First steps',
            'running' => $sw ? 'Kuendesha kikundi' : 'Running the group',
            'help'    => $sw ? 'Mazoea mazuri na msaada' : 'Good practice and help',
        ];
    }
}

if (!function_exists('vk_guide_money')) {
    function vk_guide_money(float $amount): string
    {
        return 'TSh ' . number_format($amount);
    }
}

if (!function_exists('vk_guide_rules_rows')) {
    /**
     * "Your group's rules" as table rows, from the figures guide.php reads for a
     * signed-in user. Pure.
     *
     * $f: rule (float|plan from cr_monthly_rule), social, entrance, deadline_day,
     *     late (late_settings()), loan_types (rows of vicoba_loan_types)
     */
    function vk_guide_rules_rows(array $f, bool $sw, bool $shares): array
    {
        $t = static fn(string $en, string $s) => $sw ? $s : $en;
        $rows = [];

        $rule = $f['rule'] ?? 0;
        if (is_array($rule)) {
            $parts = [];
            foreach ($rule as $r) {
                $parts[] = ($r['from'] === null ? $t('From the start', 'Tangu mwanzo') : $t('From ', 'Kuanzia ') . $r['from'])
                         . ': ' . vk_guide_money((float) $r['amount']);
            }
            $rows[] = [$t('Monthly contribution', 'Mchango wa mwezi'), implode('; ', $parts)];
        } elseif ((float) $rule > 0) {
            $rows[] = [$t('Monthly contribution', 'Mchango wa mwezi'), vk_guide_money((float) $rule)];
        } else {
            $rows[] = [$t('Monthly contribution', 'Mchango wa mwezi'), $t('Not set yet', 'Bado haujawekwa')];
        }
        if ((float) ($f['social'] ?? 0) > 0) {
            $rows[] = [$t('Social Fund', 'Mfuko wa Jamii'), vk_guide_money((float) $f['social']) . $t(' a month', ' kwa mwezi')];
        }
        if ((float) ($f['entrance'] ?? 0) > 0) {
            $rows[] = [$t('Entrance fee', 'Ada ya kujiunga'), vk_guide_money((float) $f['entrance'])];
        }
        if ($shares && (float) ($f['share_price'] ?? 0) > 0) {
            $rows[] = [$t('Price of one share', 'Bei ya hisa moja'), vk_guide_money((float) $f['share_price'])];
        }
        if ((int) ($f['deadline_day'] ?? 0) > 0) {
            $rows[] = [$t('Contribution deadline', 'Mwisho wa kuchangia'), $t('Day ', 'Tarehe ') . (int) $f['deadline_day'] . $t(' of the month', ' ya mwezi')];
        }
        $late = $f['late'] ?? null;
        if (is_array($late) && !empty($late['enabled'])) {
            $rows[] = [$t('Late payment', 'Kuchelewa kulipa'), $t(
                "A month not paid by day {$late['fine_day']} of the next month: fine of " . vk_guide_money($late['fine'])
                    . ". Still not paid by day {$late['dormant_day']}: the member becomes Dormant until they pay.",
                "Mwezi usiolipwa hadi tarehe {$late['fine_day']} ya mwezi unaofuata: faini ya " . vk_guide_money($late['fine'])
                    . ". Bado haujalipwa hadi tarehe {$late['dormant_day']}: mwanachama anakuwa Dormant hadi alipe."
            )];
        }
        foreach ((array) ($f['loan_types'] ?? []) as $lt) {
            $limit = [];
            if ((float) ($lt['max_amount'] ?? 0) > 0) {
                $limit[] = $t('up to ', 'hadi ') . vk_guide_money((float) $lt['max_amount']);
            }
            if ($shares && (float) ($lt['max_share_multiple'] ?? 0) > 0) {
                $limit[] = $t('up to ', 'hadi mara ') . rtrim(rtrim(number_format((float) $lt['max_share_multiple'], 2), '0'), '.') . $t(' times your shares', ' ya hisa zako');
            }
            $type = ($lt['interest_type'] ?? '') === 'reducing' ? $t('on what is still owed', 'kwa kinachodaiwa') : $t('flat', 'ya kawaida (flat)');
            $rows[] = [
                $t('Loan: ', 'Mkopo: ') . $lt['name'],
                rtrim(rtrim(number_format((float) $lt['interest_rate'], 2), '0'), '.') . $t('% a month, ', '% kwa mwezi, ') . $type
                    . $t(', up to ', ', hadi miezi ') . (int) $lt['max_term_months'] . $t(' months', '')
                    . ($limit ? ', ' . implode(', ', $limit) : '')
                    . ', ' . (int) $lt['guarantors_required'] . $t(' guarantor(s)', ' wadhamini'),
            ];
        }
        return $rows;
    }
}

if (!function_exists('vk_guide_sections')) {
    /**
     * The guide's sections, in order.
     *
     * $ctx: name, address, demo (bool), shares (bool), dividends (bool),
     *       figures (array for vk_guide_rules_rows, or null when not signed in)
     *
     * Each section: id, part, title, who?, intro?, steps[], table? {head, rows}, notes[] ([kind, text]).
     */
    function vk_guide_sections(array $ctx, bool $sw): array
    {
        $t = static fn(string $en, string $s) => $sw ? $s : $en;
        $name = (string) $ctx['name'];
        $addr = (string) $ctx['address'];
        $shares = !empty($ctx['shares']);
        $dividends = !empty($ctx['dividends']);
        $leaders = $t('Chairperson, Secretary, Treasurer', 'Mwenyekiti, Katibu, Mweka Hazina');
        $s = [];

        // ── Getting started ──────────────────────────────────────────────────
        $welcome = [
            'id' => 'welcome', 'part' => 'start', 'title' => $t('Welcome to Vikundi', 'Karibu Vikundi'),
            'intro' => $t(
                "$name has its own Vikundi site at $addr, with its own database, a secure (https) address and a backup every night. It runs in the browser on a phone or a computer; there is nothing to install.",
                "$name kina tovuti yake ya Vikundi kwenye $addr, yenye database yake, anwani salama (https) na nakala ya akiba (backup) kila usiku. Inafanya kazi kwenye kivinjari (browser) cha simu au kompyuta; hakuna cha kusakinisha."
            ),
            'steps' => [],
            'notes' => [['note', $t(
                'Menus and buttons are written in this guide as they appear on screen, in English. You can switch the system to Kiswahili under My Settings.',
                'Menyu na vitufe vimeandikwa kwenye mwongozo huu kama vinavyoonekana kwenye skrini, kwa Kiingereza. Unaweza kubadilisha mfumo kuwa Kiswahili kupitia My Settings.'
            )]],
        ];
        if (!empty($ctx['demo'])) {
            $welcome['notes'][] = ['important', $t(
                'This is a demo. The members, payments and loans you see are samples, put there so you can try every page. When the group signs up, BJP Technologies removes them all and the group starts clean; your login stays.',
                'Huu ni mfumo wa majaribio (demo). Wanachama, malipo na mikopo mnayoona ni sampuli, vimewekwa ili mjaribu kila ukurasa. Kikundi kikijiunga rasmi, BJP Technologies huviondoa vyote na kikundi kinaanza upya; akaunti yako inabaki.'
            )];
        }
        $s[] = $welcome;

        $s[] = [
            'id' => 'signin', 'part' => 'start', 'title' => $t('Signing in', 'Kuingia kwenye mfumo'),
            'steps' => [
                $t("Open $addr in your browser.", "Fungua $addr kwenye kivinjari chako."),
                $t('Type your username and your password. BJP Technologies sends the Chairperson theirs separately, never in this guide.',
                   'Andika jina la mtumiaji (username) na nenosiri (password). BJP Technologies humtumia Mwenyekiti vyake kando, kamwe si kwenye mwongozo huu.'),
                $t('Press LOGIN. You arrive on the group dashboard.', 'Bonyeza LOGIN. Utaingia kwenye dashibodi ya kikundi.'),
            ],
            'notes' => [
                ['tip', $t("Add the address to your phone's home screen to open it in one tap.", 'Weka anwani hii kwenye skrini ya mwanzo ya simu yako ili uifungue kwa mguso mmoja.')],
                ['important', $t('Never share your password. If you forget it, the Chairperson can set a new one under Administration › Users.',
                                 'Usimpe mtu yeyote nenosiri lako. Ukilisahau, Mwenyekiti anaweza kukuwekea jipya kupitia Administration › Users.')],
            ],
        ];

        $s[] = [
            'id' => 'menus', 'part' => 'start', 'title' => $t('Finding your way around', 'Kuzunguka kwenye mfumo'),
            'intro' => $t('The blue bar at the top of every page holds the menus. What you see in them depends on your role.',
                          'Mstari wa bluu juu ya kila ukurasa una menyu. Unachokiona humo hutegemea nafasi yako kwenye kikundi.'),
            'steps' => [],
            'table' => ['head' => [$t('Menu', 'Menyu'), $t('What it holds', 'Kilichomo')], 'rows' => [
                ['Management', $t('Members and meetings.', 'Wanachama na mikutano.')],
                ['Finance', $t('Contributions, loans, fines, expenses' . ($dividends ? ' and dividends' : '') . '. Your own records are under My Information.',
                               'Michango, mikopo, faini, matumizi' . ($dividends ? ' na gawio' : '') . '. Kumbukumbu zako binafsi ziko chini ya My Information.')],
                ['Reports', $t('Statements and reports.', 'Taarifa na ripoti.')],
                ['Administration', $t('Group settings and user accounts. The Chairperson only.', 'Mipangilio ya kikundi na akaunti za watumiaji. Mwenyekiti pekee.')],
                [$t('Your name, top right', 'Jina lako, juu kulia'), 'My Profile, My Settings, Logout.'],
            ]],
            'notes' => [['note', $t('If you cannot see a menu or a page described here, your role does not include it. That is by design.',
                                    'Kama huoni menyu au ukurasa ulioelezwa hapa, nafasi yako haiuhusishi. Hivyo ndivyo mfumo ulivyopangwa.')]],
        ];

        $s[] = [
            'id' => 'account', 'part' => 'start', 'title' => $t('Your account and password', 'Akaunti yako na nenosiri'),
            'steps' => [
                $t('Click your name at the top right and choose My Settings.', 'Bonyeza jina lako juu kulia na uchague My Settings.'),
                $t('Under Profile, check your name, e-mail and phone number.', 'Chini ya Profile, hakikisha jina lako, barua pepe na namba ya simu.'),
                $t('Under Security, change your password.', 'Chini ya Security, badilisha nenosiri lako.'),
                $t('Under Preferences, choose English or Kiswahili.', 'Chini ya Preferences, chagua English au Kiswahili.'),
            ],
            'notes' => [['important', $t('Change the password you were first given as soon as you sign in.', 'Badilisha nenosiri ulilopewa mwanzoni mara tu unapoingia kwa mara ya kwanza.')]],
        ];

        $s[] = [
            'id' => 'roles', 'part' => 'start', 'title' => $t('Who can do what', 'Nani anaweza kufanya nini'),
            'steps' => [],
            'table' => ['head' => [$t('Role', 'Nafasi'), $t('What they do', 'Wanachofanya')], 'rows' => [
                [$t('Chairperson', 'Mwenyekiti (Chairperson)'), $t('Everything, including user accounts and all settings.', 'Kila kitu, ikiwemo akaunti za watumiaji na mipangilio yote.')],
                [$t('Secretary', 'Katibu (Secretary)'), $t('Members, meetings, payments, loans, fines, reports and Group Settings.', 'Wanachama, mikutano, malipo, mikopo, faini, ripoti na Mipangilio ya Kikundi.')],
                [$t('Treasurer', 'Mweka Hazina (Treasurer)'), $t('Payments, M-KOBA statements, paying out loans, repayments, fines and reports.', 'Malipo, taarifa za M-KOBA, kutoa mikopo, marejesho, faini na ripoti.')],
                [$t('Member', 'Mwanachama (Member)'), $t('Sees their own savings, loans and statements under My Information, and can apply for a loan.', 'Huona akiba yake, mikopo na taarifa zake chini ya My Information, na anaweza kuomba mkopo.')],
            ]],
            'notes' => [['note', $t('Nobody can approve, pay out or record a repayment on their own loan. Another leader must do it.',
                                    'Hakuna anayeweza kuidhinisha, kutoa au kurekodi rejesho la mkopo wake mwenyewe. Kiongozi mwingine ndiye hufanya hivyo.')]],
        ];

        // ── First steps ──────────────────────────────────────────────────────
        $s[] = [
            'id' => 'rules', 'part' => 'first', 'title' => $t("Set the group's rules first", 'Weka kanuni za kikundi kwanza'),
            'who' => $t('Chairperson or Secretary', 'Mwenyekiti au Katibu'),
            'intro' => $t('Every calculation in the system reads the group\'s rules. Set them before the first payment or loan is recorded.',
                          'Kila hesabu kwenye mfumo husoma kanuni za kikundi. Ziweke kabla malipo au mkopo wa kwanza haujarekodiwa.'),
            'steps' => array_values(array_filter([
                $t('The Chairperson opens Administration › Main Settings. The Secretary opens Management › Group Settings.',
                   'Mwenyekiti anafungua Administration › Main Settings. Katibu anafungua Management › Group Settings.'),
                $t('Under Contribution Rates, set the monthly contribution and the entrance fee. If the amount changed over the years, add each change with the month it started.',
                   'Chini ya Contribution Rates, weka mchango wa mwezi na ada ya kujiunga. Kama kiasi kilibadilika kwa miaka, ongeza kila badiliko na mwezi lilipoanza.'),
                $shares
                    ? $t('Under Shares & Funds, set the share price, whether the contribution buys shares, the Social Fund amount and the membership deposit.',
                         'Chini ya Shares & Funds, weka bei ya hisa, kama mchango unanunua hisa, kiasi cha Mfuko wa Jamii na amana ya uanachama.')
                    : $t('Under Funds, set the Social Fund amount and the membership deposit.', 'Chini ya Funds, weka kiasi cha Mfuko wa Jamii na amana ya uanachama.'),
                $t('If the group fines late payers, switch on the late-payment rules and set the fine and the days.',
                   'Kama kikundi kinatoza faini kwa wanaochelewa kulipa, washa kanuni za kuchelewa na weka faini na tarehe.'),
                $t('Scroll to Loan Types and add each kind of loan the group offers: interest, longest period, limit, guarantors and late penalty.',
                   'Shuka hadi Loan Types na ongeza kila aina ya mkopo kikundi kinachotoa: riba, muda mrefu zaidi, kikomo, wadhamini na adhabu ya kuchelewa.'),
                $t('Press SAVE GROUP SETTINGS.', 'Bonyeza SAVE GROUP SETTINGS.'),
            ])),
            'notes' => [['important', $t('Rules apply from the day they are saved. A change to a loan type applies to new loans only.',
                                         'Kanuni hutumika kuanzia siku zinapohifadhiwa. Badiliko la aina ya mkopo linahusu mikopo mipya tu.')]],
        ];

        $s[] = [
            'id' => 'people', 'part' => 'first', 'title' => $t('Add your leaders and members', 'Ongeza viongozi na wanachama'),
            'who' => $t('Chairperson', 'Mwenyekiti'),
            'steps' => [
                $t('Open Management › Members and press Register to add a member. Every member gets a member record and a username.',
                   'Fungua Management › Members na ubonyeze Register kuongeza mwanachama. Kila mwanachama anapata kumbukumbu na jina la mtumiaji.'),
                $t('Open Administration › Users to give the Secretary and the Treasurer their own logins and roles.',
                   'Fungua Administration › Users kuwapa Katibu na Mweka Hazina akaunti zao na nafasi zao.'),
                $t('Check every member\'s phone number: M-KOBA payments are matched to members by phone.',
                   'Hakikisha namba ya simu ya kila mwanachama: malipo ya M-KOBA huoanishwa na wanachama kwa namba ya simu.'),
            ],
            'notes' => [['tip', $t('A long member list can be loaded for you from Excel. Send it to BJP Technologies.',
                                   'Orodha ndefu ya wanachama inaweza kuingizwa kwa ajili yenu kutoka Excel. Itumeni kwa BJP Technologies.')]],
        ];

        $rulesSection = [
            'id' => 'your-rules', 'part' => 'first', 'title' => $t("Your group's rules today", 'Kanuni za kikundi chenu leo'),
            'steps' => [],
            'notes' => [],
        ];
        if (is_array($ctx['figures'] ?? null)) {
            $rulesSection['intro'] = $t("What $name's settings say now. Change them under Group Settings.", "Kile ambacho mipangilio ya $name inasema sasa. Ibadilishe kupitia Group Settings.");
            $rulesSection['table'] = ['head' => [$t('Rule', 'Kanuni'), $t('Now', 'Sasa')], 'rows' => vk_guide_rules_rows($ctx['figures'], $sw, $shares)];
        } else {
            $rulesSection['notes'][] = ['note', $t("Sign in and open this guide again to see $name's own contribution, Social Fund, deadlines and loan types here.",
                                                   "Ingia kwenye mfumo na ufungue mwongozo huu tena kuona mchango, Mfuko wa Jamii, tarehe za mwisho na aina za mikopo za $name hapa.")];
        }
        $s[] = $rulesSection;

        // ── Running the group ────────────────────────────────────────────────
        $types = [
            ['Monthly', $t('The regular contribution.', 'Mchango wa kawaida wa kila mwezi.')],
        ];
        if ($shares) {
            $types[] = ['Shares', $t('A share purchase made separately from the regular contribution.', 'Ununuzi wa hisa nje ya mchango wa kawaida.')];
        }
        $types[] = ['Social Fund', $t('A payment into the welfare fund (funerals, weddings, sickness). Kept apart from savings.', 'Malipo kwenye mfuko wa ustawi (misiba, harusi, ugonjwa). Hutunzwa mbali na akiba.')];
        $types[] = ['Membership Deposit', $t('The deposit paid on joining. Kept apart from savings.', 'Amana inayolipwa wakati wa kujiunga. Hutunzwa mbali na akiba.')];
        $types[] = ['Entrance, AGM, Other', $t('Other payments the group collects.', 'Malipo mengine kikundi kinachokusanya.')];
        $s[] = [
            'id' => 'payments', 'part' => 'running', 'title' => $t('Recording a payment', 'Kurekodi malipo'),
            'who' => $leaders,
            'steps' => [
                $t('Open Finance › Transactions.', 'Fungua Finance › Transactions.'),
                $t('Under Record Payment, choose the member, the account the money came through and the type of payment.',
                   'Chini ya Record Payment, chagua mwanachama, akaunti fedha ilipopitia na aina ya malipo.'),
                $t('Enter the amount and press SAVE.', 'Weka kiasi na ubonyeze SAVE.'),
            ],
            'table' => ['head' => [$t('Type', 'Aina'), $t('Use it for', 'Itumie kwa')], 'rows' => $types],
            'notes' => [['important', $t('Do not record a fine here. Fines have their own page.', 'Usirekodi faini hapa. Faini zina ukurasa wake.')]],
        ];

        $s[] = [
            'id' => 'mkoba', 'part' => 'running', 'title' => $t('Importing an M-KOBA statement', 'Kuingiza taarifa ya M-KOBA'),
            'who' => $leaders,
            'intro' => $t('Instead of typing each M-KOBA payment, upload the statement and let the system match the payments to members by phone number.',
                          'Badala ya kuandika kila malipo ya M-KOBA, pakia taarifa (statement) na mfumo uoanishe malipo na wanachama kwa namba ya simu.'),
            'steps' => [
                $t('Download the statement from M-KOBA.', 'Pakua taarifa kutoka M-KOBA.'),
                $t('Open Finance › Transactions, press Import M-Koba statement, choose the file and press Process Statement.',
                   'Fungua Finance › Transactions, bonyeza Import M-Koba statement, chagua faili na ubonyeze Process Statement.'),
                $t('Read the green message: Imported, Already on record, Ignored and Unmatched. Press Download unmatched rows to follow up payments from unknown numbers.',
                   'Soma ujumbe wa kijani: Imported, Already on record, Ignored na Unmatched. Bonyeza Download unmatched rows kufuatilia malipo kutoka namba zisizojulikana.'),
                $t('Open Finance › M-Koba Reconciliation to check every line of the statement against the books.',
                   'Fungua Finance › M-Koba Reconciliation kuhakiki kila mstari wa taarifa dhidi ya vitabu.'),
            ],
            'notes' => [['tip', $t('Upload the file exactly as M-KOBA gave it. Saving it in Excel first can damage the long reference numbers. A statement imported twice is not counted twice.',
                                   'Pakia faili kama M-KOBA ilivyolitoa. Kulihifadhi kwenye Excel kwanza kunaweza kuharibu namba ndefu za kumbukumbu. Taarifa ikiingizwa mara mbili haihesabiwi mara mbili.')]],
        ];

        $s[] = [
            'id' => 'approve', 'part' => 'running', 'title' => $t('Approving payments', 'Kuidhinisha malipo'),
            'who' => $leaders,
            'intro' => $t('Every payment, typed or imported, waits as Pending until a leader approves it. Only approved payments count.',
                          'Kila malipo, yaliyoandikwa au kuingizwa, husubiri kama Pending hadi kiongozi ayaidhinishe. Malipo yaliyoidhinishwa pekee ndiyo huhesabiwa.'),
            'steps' => [
                $t('Open Finance › Contributions.', 'Fungua Finance › Contributions.'),
                $t('Under Pending Approvals, press Action on a payment.', 'Chini ya Pending Approvals, bonyeza Action kwenye malipo.'),
                $t('Choose Mark Reviewed, then Approve. Choose Reject Entry if the payment is wrong.', 'Chagua Mark Reviewed, kisha Approve. Chagua Reject Entry kama malipo si sahihi.'),
            ],
            'notes' => [],
        ];

        $s[] = [
            'id' => 'loans', 'part' => 'running', 'title' => $t('Loans, from application to repayment', 'Mikopo, kuanzia maombi hadi marejesho'),
            'who' => $leaders,
            'steps' => [
                $t('Open Finance › Loans. The boxes at the top show what is waiting for a leader.', 'Fungua Finance › Loans. Visanduku vya juu vinaonyesha kinachosubiri kiongozi.'),
                $t('Apply: press New application, choose the member, the loan type, the amount, the period and the guarantors. A member who signs in can apply for themselves.',
                   'Kuomba: bonyeza New application, chagua mwanachama, aina ya mkopo, kiasi, muda na wadhamini. Mwanachama anayeingia kwenye mfumo anaweza kujiombea mwenyewe.'),
                $t('Guarantors: each guarantor accepts on their own login, or a leader records their answer with Record acceptance once they have agreed in writing.',
                   'Wadhamini: kila mdhamini anakubali kwa akaunti yake, au kiongozi anarekodi jibu lake kwa Record acceptance baada ya kukubali kwa maandishi.'),
                $t('Approve: open a loan that is Awaiting approval, check it, and press Approve (or Reject with a reason).',
                   'Kuidhinisha: fungua mkopo ulio Awaiting approval, uhakiki, na ubonyeze Approve (au Reject pamoja na sababu).'),
                $t('Pay out: the Treasurer presses Pay out, enters the date, account and reference, and presses Confirm pay-out. Repayment dates are fixed from that day.',
                   'Kutoa: Mweka Hazina anabonyeza Pay out, anaweka tarehe, akaunti na kumbukumbu, kisha Confirm pay-out. Tarehe za marejesho hupangwa kuanzia siku hiyo.'),
                $t('Repay: the Treasurer opens the loan, presses Record repayment, enters what was received and saves. The loan closes by itself when the last instalment is paid.',
                   'Kurejesha: Mweka Hazina anafungua mkopo, anabonyeza Record repayment, anaweka kilichopokelewa na kuhifadhi. Mkopo hujifunga wenyewe rejesho la mwisho likilipwa.'),
            ],
            'notes' => [['important', $t('The system will not pay out more than the group holds. An overdue instalment carries the late penalty set on its loan type.',
                                         'Mfumo hautatoa zaidi ya kile kikundi kinacho. Rejesho lililochelewa hubeba adhabu iliyowekwa kwenye aina ya mkopo wake.')]],
        ];

        $s[] = [
            'id' => 'welfare', 'part' => 'running', 'title' => $t('Social Fund support and fines', 'Msaada wa Mfuko wa Jamii na faini'),
            'who' => $leaders,
            'steps' => [
                $t('Funerals: open Finance › Expenses. The page opens on Condolences; press Record Condolence.',
                   'Misiba: fungua Finance › Expenses. Ukurasa unafunguka kwenye Condolences; bonyeza Record Condolence.'),
                $t('The group\'s running costs, such as stationery or venue hire: press Other Expenses on the same page.',
                   'Gharama za uendeshaji wa kikundi, kama vifaa vya ofisi au ukumbi: bonyeza Other Expenses kwenye ukurasa huo huo.'),
                $t('Fines: open Finance › Fines and press Record Fine. When it is paid, use the gear button and choose Mark Paid, or Waive if the group forgives it.',
                   'Faini: fungua Finance › Fines na ubonyeze Record Fine. Ikilipwa, tumia kitufe cha gia na uchague Mark Paid, au Waive kama kikundi kimeisamehe.'),
            ],
            'notes' => [['note', $t('Condolences come out of the Social Fund, which is kept apart from members\' savings. Running costs come out of the group\'s surplus.',
                                    'Rambirambi hutoka kwenye Mfuko wa Jamii, ambao hutunzwa mbali na akiba za wanachama. Gharama za uendeshaji hutoka kwenye ziada ya kikundi.')]],
        ];

        if ($dividends) {
            $s[] = [
                'id' => 'dividends', 'part' => 'running', 'title' => $t('Dividends', 'Gawio'),
                'who' => $leaders,
                'steps' => [
                    $t('Open Finance › Dividends, check the period and press Calculate. This is a preview; nothing is saved.',
                       'Fungua Finance › Dividends, hakikisha kipindi na ubonyeze Calculate. Huu ni muhtasari; hakuna kinachohifadhiwa.'),
                    $t('Check the surplus and each member\'s dividend, choose how it is paid, and press Confirm these figures.',
                       'Hakiki ziada na gawio la kila mwanachama, chagua jinsi litakavyolipwa, na ubonyeze Confirm these figures.'),
                    $t('When the leaders agree, press Distribute now.', 'Viongozi wakikubaliana, bonyeza Distribute now.'),
                ],
                'notes' => [['important', $t('Distributing cannot be undone. Have a second leader check the figures first.', 'Kugawa hakuwezi kurudishwa. Kiongozi wa pili ahakiki takwimu kwanza.')]],
            ];
        }

        $reports = [
            ['Loan Portfolio', $t('Every loan: paid out, repaid, still owed and penalties, with overdue instalments.', 'Kila mkopo: uliotolewa, uliorejeshwa, unaodaiwa na adhabu, pamoja na marejesho yaliyochelewa.')],
            ['Loan Income & Repayments', $t('Each repayment split into loan, interest and penalty.', 'Kila rejesho limegawanywa kuwa mkopo, riba na adhabu.')],
        ];
        if ($shares) {
            $reports[] = ['Shares Register', $t("Each member's share capital and share of the group.", 'Mtaji wa hisa wa kila mwanachama na sehemu yake kwenye kikundi.')];
        }
        $reports[] = ['Social Fund Statement', $t('What each member paid in, each welfare payment out, and the balance.', 'Kila mwanachama alicholipa, kila msaada uliotolewa, na salio.')];
        $reports[] = [$t('Member statement', 'Taarifa ya mwanachama'), $t('Finance › Contributions, the statement button: one member\'s payments, ready to print.', 'Finance › Contributions, kitufe cha taarifa: malipo ya mwanachama mmoja, tayari kuchapishwa.')];
        $s[] = [
            'id' => 'reports', 'part' => 'running', 'title' => $t('Reports', 'Ripoti'),
            'who' => $leaders,
            'intro' => $t('Open Reports. Every report has a Print button and prints with the group\'s letterhead.', 'Fungua Reports. Kila ripoti ina kitufe cha Print na huchapishwa na kichwa cha barua cha kikundi.'),
            'steps' => [],
            'table' => ['head' => [$t('Report', 'Ripoti'), $t('What it shows', 'Inachoonyesha')], 'rows' => $reports],
            'notes' => [],
        ];

        // ── Good practice and help ──────────────────────────────────────────
        $s[] = [
            'id' => 'practice', 'part' => 'help', 'title' => $t('Good practice for leaders', 'Mazoea mazuri kwa viongozi'),
            'steps' => [],
            'table' => ['head' => [$t('Do this', 'Fanya hivi'), $t('Why', 'Kwa nini')], 'rows' => [
                [$t('Import the M-KOBA statement regularly, then approve the payments.', 'Ingiza taarifa ya M-KOBA mara kwa mara, kisha idhinisha malipo.'),
                 $t('Members see their savings only after approval.', 'Wanachama huona akiba zao baada ya kuidhinishwa tu.')],
                [$t('Record repayments on the day they are received.', 'Rekodi marejesho siku yanapopokelewa.'),
                 $t('Penalties are worked out from the dates.', 'Adhabu huhesabiwa kutokana na tarehe.')],
                [$t('Give each leader their own account.', 'Mpe kila kiongozi akaunti yake.'),
                 $t('The system records who did what. A shared login hides that.', 'Mfumo hurekodi nani alifanya nini. Akaunti ya pamoja huficha hilo.')],
                [$t("Keep members' phone numbers right.", 'Weka namba za simu za wanachama sahihi.'),
                 $t('M-KOBA payments are matched by phone number.', 'Malipo ya M-KOBA huoanishwa kwa namba ya simu.')],
            ]],
            'notes' => [],
        ];

        $s[] = [
            'id' => 'faq', 'part' => 'help', 'title' => $t('Questions leaders often ask', 'Maswali ambayo viongozi huuliza mara nyingi'),
            'steps' => [],
            'table' => ['head' => [$t('Question', 'Swali'), $t('Answer', 'Jibu')], 'rows' => [
                [$t('A member paid, but it does not show.', 'Mwanachama amelipa, lakini haionekani.'),
                 $t('It has not been approved yet. Approve it under Finance › Contributions.', 'Bado haijaidhinishwa. Iidhinishe kupitia Finance › Contributions.')],
                [$t('An M-KOBA payment came in as unmatched.', 'Malipo ya M-KOBA yamekuja kama unmatched.'),
                 $t("The phone that paid is on no member. Correct the member's phone and import the statement again.", 'Namba iliyolipa haipo kwa mwanachama yeyote. Rekebisha namba ya mwanachama na uingize taarifa tena.')],
                [$t('A loan is stuck at Awaiting guarantors.', 'Mkopo umekwama kwenye Awaiting guarantors.'),
                 $t('Each guarantor must accept, or a leader records their written agreement on the loan.', 'Kila mdhamini lazima akubali, au kiongozi arekodi makubaliano yake ya maandishi kwenye mkopo.')],
                [$t('I forgot my password.', 'Nimesahau nenosiri langu.'),
                 $t('The Chairperson sets a new one under Administration › Users. If the Chairperson is locked out, contact BJP Technologies.', 'Mwenyekiti anakuwekea jipya kupitia Administration › Users. Kama Mwenyekiti amefungiwa, wasiliana na BJP Technologies.')],
            ]],
            'notes' => [],
        ];

        $s[] = [
            'id' => 'help', 'part' => 'help', 'title' => $t('Getting help', 'Kupata msaada'),
            'intro' => $t('If something does not work as this guide describes, write down what you were doing and what the screen showed, and contact BJP Technologies.',
                          'Kama kitu hakifanyi kazi kama mwongozo huu unavyoeleza, andika ulichokuwa ukifanya na kile skrini ilichoonyesha, kisha wasiliana na BJP Technologies.'),
            'steps' => [],
            'table' => ['head' => ['BJP Technologies (T) Ltd', ''], 'rows' => [
                [$t('Phone / WhatsApp', 'Simu / WhatsApp'), VK_GUIDE_SUPPORT_PHONE],
                [$t('E-mail', 'Barua pepe'), VK_GUIDE_SUPPORT_EMAIL],
            ]],
            'notes' => [],
        ];

        return $s;
    }
}
