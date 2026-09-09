<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_communication.php';

/**
 * Module 16 — Communication (Messages, SMS, Email send, Notifications, AI
 * Ask/Chat).
 *
 * ONE leadership test reused everywhere: vk_api_comm_is_leader() wraps
 * vk_api_can($auth, 'create', 'message_center') and gates message-sending,
 * SMS-sending, email-sending, AND the SMS log read — so "who may compose"
 * cannot answer differently across those four surfaces the way api/get_transactions.php
 * once answered `view` where it meant `edit` (see FinesApiTest's own note on
 * that class of bug).
 *
 * SCOPE NOTE, mirrored from includes/api_communication.php's own header:
 * GET /api/v1/messages and /api/v1/notifications are NOT leadership-gated —
 * they are the caller's own inbox and own notifications, open to every
 * Member by default. GET /api/v1/sms IS leadership-gated (tighter than the
 * web's own api/sms_center.php?action=list, a deliberate narrowing).
 *
 * message_center.php's own send handler previously had no `create` check at
 * all — only requireViewPermission() gated the page, so any signed-in
 * Member (view-only by default) could POST send_message directly. Fixed in
 * the same change as this module, on both the web and the API; the
 * regression coverage lives in the "web page fix" section below.
 */
final class CommunicationApiTest extends TestCase
{
    private static function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(__DIR__ . '/../../' . $rel)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    private static function auth(bool $leader, bool $admin = false, int $userId = 1): array
    {
        return [
            'user_id' => $userId,
            'role_id' => $admin ? 1 : ($leader ? 4 : 13),
            'user'    => ['user_role' => $admin ? 'Admin' : ($leader ? 'Treasurer' : 'Member')],
            'permissions' => $leader || $admin
                ? ['message_center' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 1]]
                // Member holds `view` here too — same as the live grants — but
                // never create.
                : ['message_center' => ['view' => 1, 'create' => 0, 'edit' => 0, 'delete' => 0]],
        ];
    }

    private static function messageRaw(array $over = []): array
    {
        return $over + [
            'message_id' => 8,
            'subject'    => 'September Contributions',
            'message'    => 'Please remember to contribute by the 10th.',
            'priority'   => 'normal',
            'parent_id'  => null,
            'sender_id'  => 1,
            'sender_name'=> 'Amina Hando',
            'created_at' => '2026-09-01 09:00:00',
        ];
    }

    // ── leadership test: the single source of truth ─────────────────────────

    public function testCommIsLeaderTrueWhenCreateGrantedOnMessageCenter(): void
    {
        $this->assertTrue(vk_api_comm_is_leader(self::auth(true)));
    }

    public function testCommIsLeaderFalseForAViewOnlyMember(): void
    {
        $this->assertFalse(vk_api_comm_is_leader(self::auth(false)));
    }

    public function testCommIsLeaderTrueForAnAdminRegardlessOfPermissions(): void
    {
        $this->assertTrue(vk_api_comm_is_leader(self::auth(false, true)));
    }

    public function testCommRequireLeaderThrowsWithTheGivenDetailWhenNotLeader(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/forbidden/');
        $this->expectExceptionMessageMatches('/SMS is available to leadership only/');
        vk_api_comm_require_leader(self::auth(false), 'SMS is available to leadership only.');
    }

    public function testCommRequireLeaderDoesNotThrowForLeadership(): void
    {
        vk_api_comm_require_leader(self::auth(true), 'unreachable');
        $this->assertTrue(true, 'no exception means the leadership check passed');
    }

    public function testTheLeadershipTestIsCreateNotView(): void
    {
        // The rule this whole module hinges on: a role holding only `view` on
        // message_center (Member's default) must never be treated as leadership.
        $code = self::code('includes/api_communication.php');
        $this->assertStringContainsString("vk_api_can(\$auth, 'create', 'message_center')", $code);

        $viewOnly = [
            'user_id' => 9, 'role_id' => 13, 'user' => ['user_role' => 'Member'],
            'permissions' => ['message_center' => ['view' => 1, 'create' => 0, 'edit' => 0, 'delete' => 0]],
        ];
        $this->assertFalse(vk_api_comm_is_leader($viewOnly));
    }

    // ── messages: the row ────────────────────────────────────────────────────

    public function testMessagePrioritiesAreLowNormalHigh(): void
    {
        $this->assertSame(['low', 'normal', 'high'], vk_api_message_priorities());
    }

    public function testMessageFoldersAreInboxSentArchived(): void
    {
        $this->assertSame(['inbox', 'sent', 'archived'], vk_api_message_folders());
    }

    public function testIsMineTrueWhenTheCallerSentIt(): void
    {
        $this->assertTrue(vk_api_message_row(self::messageRaw(['sender_id' => 5]), 5)['is_mine']);
    }

    public function testIsMineFalseWhenTheCallerDidNotSendIt(): void
    {
        $this->assertFalse(vk_api_message_row(self::messageRaw(['sender_id' => 5]), 9)['is_mine']);
    }

    public function testIsReadDefaultsTrueForTheSenderWhenNoIsReadKeyIsSupplied(): void
    {
        // The 'sent' folder query never joins message_recipients, so a
        // sender's own copy of a message they sent must never look unread.
        $row = vk_api_message_row(self::messageRaw(['sender_id' => 1]), 1);
        $this->assertTrue($row['is_read']);
    }

    public function testIsReadDefaultsFalseForARecipientWhenNoIsReadKeyIsSupplied(): void
    {
        $row = vk_api_message_row(self::messageRaw(['sender_id' => 1]), 9);
        $this->assertFalse($row['is_read']);
    }

    public function testIsReadReadsTheSuppliedValueWhenPresent(): void
    {
        // The key wins over the sender-default whenever it is actually
        // present — a recipient's own mr.is_read is the source of truth.
        $unread = vk_api_message_row(self::messageRaw(['sender_id' => 1, 'is_read' => 0]), 9);
        $this->assertFalse($unread['is_read']);

        $read = vk_api_message_row(self::messageRaw(['sender_id' => 1, 'is_read' => 1]), 9);
        $this->assertTrue($read['is_read']);
    }

    public function testParentIdIsNullWhenAbsent(): void
    {
        $this->assertNull(vk_api_message_row(self::messageRaw(['parent_id' => null]), 1)['parent_id']);
    }

    public function testParentIdIsCastToIntWhenPresent(): void
    {
        $this->assertSame(3, vk_api_message_row(self::messageRaw(['parent_id' => '3']), 1)['parent_id']);
    }

    public function testHasRepliesAndIsArchivedCastToBoolAndDefaultFalse(): void
    {
        $row = vk_api_message_row(self::messageRaw(), 1);
        $this->assertFalse($row['has_replies']);
        $this->assertFalse($row['is_archived']);

        $row2 = vk_api_message_row(self::messageRaw(['has_replies' => 1, 'is_archived' => '1']), 1);
        $this->assertTrue($row2['has_replies']);
        $this->assertTrue($row2['is_archived']);
    }

    public function testPriorityDefaultsToNormalWhenAbsent(): void
    {
        $row = vk_api_message_row(self::messageRaw(['priority' => null]), 1);
        $this->assertSame('normal', $row['priority']);
    }

    public function testSenderNameIsTrimmedOrNullWhenBlank(): void
    {
        $this->assertNull(vk_api_message_row(self::messageRaw(['sender_name' => '  ']), 1)['sender_name']);
        $this->assertSame('Amina Hando', vk_api_message_row(self::messageRaw(['sender_name' => ' Amina Hando ']), 1)['sender_name']);
    }

    public function testCreatedAtIsNullWhenEmpty(): void
    {
        $this->assertNull(vk_api_message_row(self::messageRaw(['created_at' => null]), 1)['created_at']);
    }

    // ── message recipients row ───────────────────────────────────────────────

    public function testRecipientRowCastsIdAndReadStatus(): void
    {
        $row = vk_api_message_recipients_row([
            'recipient_id' => '7', 'recipient_name' => ' Baraka Juma ', 'is_read' => 1, 'read_at' => '2026-09-02 08:00:00',
        ]);
        $this->assertSame(7, $row['recipient_id']);
        $this->assertSame('Baraka Juma', $row['recipient_name']);
        $this->assertTrue($row['is_read']);
        $this->assertNotNull($row['read_at']);
    }

    public function testRecipientRowNameAndReadAtDefaultToNull(): void
    {
        $row = vk_api_message_recipients_row(['recipient_id' => 7]);
        $this->assertNull($row['recipient_name']);
        $this->assertNull($row['read_at']);
        $this->assertFalse($row['is_read']);
    }

    // ── recipient validation (pure branches only — DB is never reached) ─────

    private function fakePdoNeverCalled(): PDO
    {
        // vk_api_messages_validate_recipients() only queries the DB after the
        // submitted array has passed shape validation — both cases below fail
        // that validation first, so no real connection is ever needed. Same
        // technique as MeetingsApiTest::fakePdoNeverCalled(): an uninitialised
        // PDO subclass stands in as "a PDO that must not be queried."
        return new class extends PDO {
            public function __construct() {}
        };
    }

    public function testValidateRecipientsRejectsAnEmptyArray(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/recipients_required/');
        vk_api_messages_validate_recipients($this->fakePdoNeverCalled(), [], 1);
    }

    public function testValidateRecipientsRejectsANonArray(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/recipients_required/');
        vk_api_messages_validate_recipients($this->fakePdoNeverCalled(), 'not-an-array', 1);
    }

    public function testValidateRecipientsRejectsWhenTheOnlyIdIsTheCallerThemself(): void
    {
        // Filtered out before the DB is ever touched — a caller cannot be the
        // sole recipient of their own message.
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/recipients_required/');
        vk_api_messages_validate_recipients($this->fakePdoNeverCalled(), [1], 1);
    }

    // ── phone recipient parsing ───────────────────────────────────────────────

    public function testParsePhoneRecipientsAcceptsAJsonArray(): void
    {
        $out = vk_api_comm_parse_phone_recipients(['0712345678', '0765432109']);
        $this->assertSame(['255712345678', '255765432109'], $out);
    }

    public function testParsePhoneRecipientsAcceptsADelimitedString(): void
    {
        // comma, semicolon, space, and newline all split it.
        $raw = "0712345678,0765432109; 0788111222\n0700222333";
        $out = vk_api_comm_parse_phone_recipients($raw);
        $this->assertSame(['255712345678', '255765432109', '255788111222', '255700222333'], $out);
    }

    public function testParsePhoneRecipientsDedupesAfterNormalization(): void
    {
        // '0712345678' and '255712345678' normalize to the same number.
        $out = vk_api_comm_parse_phone_recipients(['0712345678', '255712345678']);
        $this->assertSame(['255712345678'], $out);
    }

    public function testParsePhoneRecipientsDropsNumbersUnderTenDigitsAfterNormalization(): void
    {
        $out = vk_api_comm_parse_phone_recipients(['12345']);
        $this->assertSame([], $out);
    }

    public function testParsePhoneRecipientsRejectsNonNumericJunk(): void
    {
        $out = vk_api_comm_parse_phone_recipients(['abcdef', '  ']);
        $this->assertSame([], $out);
    }

    public function testParsePhoneRecipientsAcceptsNineDigitLocalFormat(): void
    {
        $out = vk_api_comm_parse_phone_recipients(['712345678']);
        $this->assertSame(['255712345678'], $out);
    }

    // ── sms: the row ────────────────────────────────────────────────────────

    public function testSmsRowShapesTheCoreFields(): void
    {
        $row = vk_api_sms_row([
            'sms_id' => 3, 'recipient_phone' => '255712345678', 'message' => 'Hello',
            'status' => 'sent', 'created_at' => '2026-09-01 10:00:00',
        ]);
        $this->assertSame(3, $row['sms_id']);
        $this->assertSame('255712345678', $row['recipient_phone']);
        $this->assertSame('sent', $row['status']);
        $this->assertSame(1, $row['segments'], 'segments defaults to 1 when not supplied');
        $this->assertNull($row['provider']);
        $this->assertNull($row['error_message']);
    }

    public function testSmsRowSenderNameIsTrimmedOrNull(): void
    {
        $this->assertNull(vk_api_sms_row($this->smsRaw(['sender_name' => '   ']))['sender_name']);
        $this->assertSame('Juma Said', vk_api_sms_row($this->smsRaw(['sender_name' => ' Juma Said ']))['sender_name']);
    }

    public function testSmsRowSentAtIsNullWhenEmpty(): void
    {
        $this->assertNull(vk_api_sms_row($this->smsRaw(['sent_at' => null]))['sent_at']);
        $this->assertNotNull(vk_api_sms_row($this->smsRaw(['sent_at' => '2026-09-01 10:05:00']))['sent_at']);
    }

    private function smsRaw(array $over = []): array
    {
        return $over + [
            'sms_id' => 3, 'recipient_phone' => '255712345678', 'message' => 'Hello',
            'status' => 'sent', 'created_at' => '2026-09-01 10:00:00',
        ];
    }

    // ── sms filters ─────────────────────────────────────────────────────────

    public function testSmsFiltersRejectsAnUnknownStatus(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_status/');
        vk_api_sms_filters(['status' => 'delivered']);
    }

    public function testSmsFiltersAcceptsAKnownStatus(): void
    {
        [$where, $params] = vk_api_sms_filters(['status' => 'failed']);
        $this->assertSame(['s.status = ?'], $where);
        $this->assertSame(['failed'], $params);
    }

    public function testSmsFiltersRejectsAnUnparseableDate(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_date/');
        vk_api_sms_filters(['date_from' => 'yesterday']);
    }

    public function testSmsFiltersAcceptsAValidDateRange(): void
    {
        [$where, $params] = vk_api_sms_filters(['date_from' => '2026-09-01', 'date_to' => '2026-09-30']);
        $this->assertCount(2, $where);
        $this->assertSame(['2026-09-01', '2026-09-30'], $params);
    }

    public function testSmsFiltersSearchProducesThreeBoundLikes(): void
    {
        [$where, $params] = vk_api_sms_filters(['search' => 'Hamisi']);
        $this->assertCount(1, $where);
        $this->assertSame(['%Hamisi%', '%Hamisi%', '%Hamisi%'], $params);
        $this->assertStringNotContainsString('Hamisi', $where[0], 'the search term must be bound, never interpolated');
    }

    public function testSmsFiltersNoFiltersMeansNoConditions(): void
    {
        $this->assertSame([[], []], vk_api_sms_filters([]));
    }

    // ── notifications: the row ─────────────────────────────────────────────

    private function notificationRaw(array $over = []): array
    {
        return $over + [
            'notification_id' => 5, 'title' => 'New contribution', 'message' => 'Amina paid 20,000.',
            'type' => 'payment', 'priority' => 'medium', 'is_read' => 0,
            'action_url' => null, 'created_at' => '2026-09-01 08:00:00', 'read_at' => null,
        ];
    }

    public function testNotificationRowCastsIsReadToBool(): void
    {
        $this->assertFalse(vk_api_notification_row($this->notificationRaw(['is_read' => 0]))['is_read']);
        $this->assertTrue(vk_api_notification_row($this->notificationRaw(['is_read' => 1]))['is_read']);
    }

    public function testNotificationRowPriorityDefaultsToMedium(): void
    {
        $row = vk_api_notification_row($this->notificationRaw(['priority' => null]));
        $this->assertSame('medium', $row['priority']);
    }

    public function testNotificationRowActionUrlDefaultsToNull(): void
    {
        $row = vk_api_notification_row($this->notificationRaw());
        $this->assertNull($row['action_url']);
    }

    public function testNotificationRowReadAtIsNullWhenEmpty(): void
    {
        $this->assertNull(vk_api_notification_row($this->notificationRaw(['read_at' => null]))['read_at']);
        $this->assertNotNull(vk_api_notification_row($this->notificationRaw(['read_at' => '2026-09-01 09:00:00']))['read_at']);
    }

    // ── notification filters ───────────────────────────────────────────────

    public function testNotificationFiltersRejectsAnUnknownType(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_type/');
        vk_api_notification_filters(['type' => 'birthday']);
    }

    public function testNotificationFiltersAcceptsAKnownType(): void
    {
        [$where, $params] = vk_api_notification_filters(['type' => 'loan']);
        $this->assertSame(['n.type = ?'], $where);
        $this->assertSame(['loan'], $params);
    }

    public function testNotificationFiltersIsReadAcceptsBooleanishStrings(): void
    {
        [, $paramsTrue]  = vk_api_notification_filters(['is_read' => 'true']);
        [, $paramsFalse] = vk_api_notification_filters(['is_read' => '0']);
        $this->assertSame([1], $paramsTrue);
        $this->assertSame([0], $paramsFalse);
    }

    public function testNotificationFiltersIsReadIsOmittedWhenBlankOrAbsent(): void
    {
        $this->assertSame([[], []], vk_api_notification_filters([]));
        $this->assertSame([[], []], vk_api_notification_filters(['is_read' => '']));
    }

    public function testNotificationFiltersNoFiltersMeansNoConditions(): void
    {
        $this->assertSame([[], []], vk_api_notification_filters([]));
    }

    // ── structural: messages.php ─────────────────────────────────────────────

    public function testMessagesListIsScopedToTheCallerNotLeadershipGated(): void
    {
        // GET /api/v1/messages is the caller's own inbox — every Member holds
        // `view` on message_center by default, and this must stay that way.
        $code = self::code('api/v1/messages.php');
        $this->assertStringContainsString("vk_api_require_permission(\$auth, 'view', 'message_center')", $code);
        $this->assertStringContainsString('mr.recipient_id = ?', $code);
    }

    public function testMessagesGateOrderIsAuthThenPermissionThenSendGate(): void
    {
        $code = self::code('api/v1/messages.php');
        $auth        = strpos($code, 'vk_api_require_auth()');
        $permission  = strpos($code, "vk_api_require_permission(\$auth, 'view', 'message_center')");
        $sendGate    = strpos($code, 'vk_api_comm_require_leader(');
        $this->assertNotFalse($auth);
        $this->assertNotFalse($permission);
        $this->assertNotFalse($sendGate);
        $this->assertLessThan($permission, $auth);
        $this->assertLessThan($sendGate, $permission);
    }

    public function testSendingRequiresLeadershipBeforeValidatingRecipients(): void
    {
        $code = self::code('api/v1/messages.php');
        $sendGate  = strpos($code, 'vk_api_comm_require_leader(');
        $recipients = strpos($code, 'vk_api_messages_validate_recipients(');
        $this->assertNotFalse($sendGate);
        $this->assertNotFalse($recipients);
        $this->assertLessThan($recipients, $sendGate, 'leadership must be checked before touching the submitted recipients');
    }

    public function testEveryMessageSendIsAuditedAgainstTheRealUser(): void
    {
        // Unlike Meetings/Voting, this file audits with $callerId rather than
        // $auth['user_id'] inline — so the assertion has two parts: the audit
        // call uses $callerId, and $callerId really is $auth['user_id'].
        $code = self::code('api/v1/messages.php');
        $this->assertStringContainsString("\$callerId = (int) \$auth['user_id']", $code);
        $this->assertMatchesRegularExpression("/logCreate\([^;]*\\\$callerId\)/s", $code);
    }

    // ── structural: messages_detail.php ─────────────────────────────────────

    public function testMessageDetailOwnershipIsEnforcedInTheQueryNotAExtraForbidden(): void
    {
        // A message the caller has no part in must 404, not 403 — existence
        // is not revealed. The ownership check lives in the WHERE clause
        // itself, so there is no separate leadership/forbidden gate to trip.
        $code = self::code('api/v1/messages_detail.php');
        $this->assertStringContainsString(
            "WHERE m.message_id = ?\n       AND (m.sender_id = ?" ,
            str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../../api/v1/messages_detail.php'))
        );
        $this->assertStringContainsString("vk_api_error(404, 'not_found'", $code);
        $this->assertStringNotContainsString('vk_api_comm_require_leader', $code);
    }

    public function testMessageDetailGateOrderIsAuthThenPermission(): void
    {
        $code = self::code('api/v1/messages_detail.php');
        $auth = strpos($code, 'vk_api_require_auth()');
        $permission = strpos($code, "vk_api_require_permission(\$auth, 'view', 'message_center')");
        $this->assertNotFalse($auth);
        $this->assertNotFalse($permission);
        $this->assertLessThan($permission, $auth);
    }

    public function testOpeningAMessageMarksItReadOnlyForTheRecipientNotTheSender(): void
    {
        $code = self::code('api/v1/messages_detail.php');
        $this->assertStringContainsString("(int) \$row['sender_id'] !== \$callerId && empty(\$row['is_read'])", $code);
    }

    // ── structural: sms.php ─────────────────────────────────────────────────

    public function testSmsIsGatedOnLeadershipNotOnAPermissionKey(): void
    {
        // Tighter than the web's ?action=list, which is view-gated and so
        // discloses the whole group's outbound numbers/text to every Member.
        $code = self::code('api/v1/sms.php');
        $this->assertStringNotContainsString('vk_api_require_permission(', $code);
        $this->assertStringContainsString("vk_api_comm_require_leader(\$auth, 'SMS is available to leadership only.')", $code);
    }

    public function testSmsLeadershipGateAppliesToBothGetAndPostUnconditionally(): void
    {
        $code = self::code('api/v1/sms.php');
        $gate   = strpos($code, 'vk_api_comm_require_leader(');
        $branch = strpos($code, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')");
        $this->assertNotFalse($gate);
        $this->assertNotFalse($branch);
        $this->assertLessThan($branch, $gate, 'the leadership check must run before the method branches, so GET is gated too');
    }

    public function testEverySmsSendIsAuditedAgainstTheRealUser(): void
    {
        $code = self::code('api/v1/sms.php');
        $this->assertStringContainsString("\$callerId = (int) \$auth['user_id']", $code);
        $this->assertMatchesRegularExpression("/logCreate\([^;]*\\\$callerId\)/s", $code);
    }

    // ── structural: email.php ─────────────────────────────────────────────────

    public function testEmailIsPostOnlyAndGatedOnLeadership(): void
    {
        $code = self::code('api/v1/email.php');
        $this->assertStringContainsString("vk_api_require_method(['POST'])", $code);
        $this->assertStringContainsString("vk_api_comm_require_leader(\$auth, 'Email is available to leadership only.')", $code);
        $this->assertStringNotContainsString('vk_api_require_permission(', $code);
    }

    public function testEmailGateComesBeforeAnySend(): void
    {
        $code  = self::code('api/v1/email.php');
        $gate  = strpos($code, 'vk_api_comm_require_leader(');
        $send  = strpos($code, 'email_send(');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($send);
        $this->assertLessThan($send, $gate);
    }

    // ── structural: email-templates.php ────────────────────────────────────

    public function testEmailTemplatesIsGatedOnMessageCenterNotItsOwnKey(): void
    {
        // Deliberate reuse, documented in the file's own comment — mirrors
        // api/get_email_templates.php exactly.
        $code = self::code('api/v1/email-templates.php');
        $this->assertStringContainsString("vk_api_require_permission(\$auth, 'view', 'message_center')", $code);
        $this->assertStringNotContainsString("'email_templates'", $code, 'no permission check must key on email_templates itself');
    }

    public function testEmailTemplatesIsReadOnly(): void
    {
        $code = self::code('api/v1/email-templates.php');
        $this->assertStringContainsString("vk_api_require_method(['GET'])", $code);
        $this->assertStringNotContainsString('INSERT INTO email_templates', $code);
        $this->assertStringNotContainsString('UPDATE email_templates', $code);
        $this->assertStringNotContainsString('DELETE FROM email_templates', $code);
    }

    // ── structural: notifications.php ──────────────────────────────────────

    public function testNotificationsListIsScopedToTheCaller(): void
    {
        $code = self::code('api/v1/notifications.php');
        $this->assertStringContainsString("vk_api_require_permission(\$auth, 'view', 'notification_center')", $code);
        $this->assertStringContainsString("array_unshift(\$where, 'n.user_id = ?')", $code);
        $this->assertStringContainsString('array_unshift($params, $callerId)', $code);
    }

    public function testNotificationsGateOrderIsAuthThenPermissionThenQuery(): void
    {
        $code = self::code('api/v1/notifications.php');
        $auth = strpos($code, 'vk_api_require_auth()');
        $permission = strpos($code, "vk_api_require_permission(\$auth, 'view', 'notification_center')");
        $query = strpos($code, 'FROM notifications n');
        $this->assertNotFalse($auth);
        $this->assertNotFalse($permission);
        $this->assertNotFalse($query);
        $this->assertLessThan($permission, $auth);
        $this->assertLessThan($query, $permission);
    }

    // ── structural: notifications_read.php ─────────────────────────────────

    public function testNotificationReadIs404OnAMismatchedOwnerNotASilentNoop(): void
    {
        $code = self::code('api/v1/notifications_read.php');
        $this->assertStringContainsString('WHERE notification_id = ? AND user_id = ?', $code);
        $this->assertStringContainsString("vk_api_error(404, 'not_found'", $code);
    }

    public function testNotificationReadGateOrderIsAuthThenPermissionThenOwnershipThenUpdate(): void
    {
        $code = self::code('api/v1/notifications_read.php');
        $auth = strpos($code, 'vk_api_require_auth()');
        $permission = strpos($code, "vk_api_require_permission(\$auth, 'view', 'notification_center')");
        $ownership = strpos($code, "vk_api_error(404, 'not_found'");
        $update = strpos($code, 'UPDATE notifications SET is_read = 1');
        $this->assertNotFalse($auth);
        $this->assertNotFalse($permission);
        $this->assertNotFalse($ownership);
        $this->assertNotFalse($update);
        $this->assertLessThan($permission, $auth);
        $this->assertLessThan($ownership, $permission);
        $this->assertLessThan($update, $ownership);
    }

    // ── structural: ai/ask.php and ai/chat.php ───────────────────────────────

    public function testAiAskIsGatedOnItsOwnDataPermission(): void
    {
        $code = self::code('api/v1/ai/ask.php');
        $this->assertStringContainsString("vk_api_require_permission(\$auth, 'view', 'ai_ask_data')", $code);
        $this->assertStringContainsString('aiConfigured()', $code);
        $this->assertStringContainsString('aiRateLimited()', $code);
        $this->assertStringContainsString('aiInsightCatalog()', $code);
        $this->assertStringContainsString('aiRunInsight(', $code);
    }

    public function testAiChatIsGatedOnTheAssistantPermission(): void
    {
        $code = self::code('api/v1/ai/chat.php');
        $this->assertStringContainsString("vk_api_require_permission(\$auth, 'view', 'ai_assistant')", $code);
        $this->assertStringContainsString('aiConfigured()', $code);
        $this->assertStringContainsString('aiRateLimited()', $code);
    }

    public function testAiChatTrimsHistoryToTenTurns(): void
    {
        $this->assertStringContainsString('array_slice($history, -10)', self::code('api/v1/ai/chat.php'));
    }

    public function testAiEndpointsSetTheSessionUserBeforeRateLimitingBecauseThoseHelpersReadTheSession(): void
    {
        foreach (['api/v1/ai/ask.php', 'api/v1/ai/chat.php'] as $file) {
            $code = self::code($file);
            $setSession = strpos($code, "\$_SESSION['user_id'] = (int) \$auth['user_id']");
            $rateLimit  = strpos($code, 'aiRateLimited()');
            $this->assertNotFalse($setSession, $file);
            $this->assertNotFalse($rateLimit, $file);
            $this->assertLessThan($rateLimit, $setSession, "{$file}: aiRateLimited() reads \$_SESSION, so it must be set first");
        }
    }

    // ── routing ─────────────────────────────────────────────────────────────

    public function testFlatEndpointsAreNamedWhatTheRouterResolvesTo(): void
    {
        $expect = [
            'api/v1/messages'             => 'messages.php',
            'api/v1/messages/8'           => 'messages_detail.php',
            'api/v1/sms'                  => 'sms.php',
            'api/v1/email'                => 'email.php',
            'api/v1/email-templates'      => 'email-templates.php',
            'api/v1/notifications'        => 'notifications.php',
            'api/v1/notifications/5/read' => 'notifications_read.php',
        ];
        foreach ($expect as $uri => $file) {
            if (preg_match('#^api/v1/([a-z0-9-]+)/(\d+)(?:/([a-z0-9_-]+))?$#', $uri, $m)) {
                $resolved = $m[1] . '_' . ($m[3] ?? 'detail') . '.php';
            } elseif (preg_match('#^api/v1/([a-z0-9-]+)/([a-z][a-z0-9_-]*)$#', $uri, $m)) {
                $resolved = $m[1] . '_' . $m[2] . '.php';
            } else {
                $resolved = basename($uri) . '.php';
            }
            $this->assertSame($file, $resolved, "{$uri} resolves elsewhere");
            $this->assertFileExists(__DIR__ . '/../../api/v1/' . $resolved);
        }
    }

    public function testAiEndpointsResolveAsLiteralNestedFilesNotTheFlatUnderscoreConvention(): void
    {
        // Unlike every other api/v1 handler, ai/ask.php and ai/chat.php live in
        // a REAL subdirectory. roots.php's rule 2 (literal URI + '.php') finds
        // them before rule 3's flat-file regexes ever run — so 'api/v1/ai/ask'
        // resolves straight to api/v1/ai/ask.php, NOT api/v1/ai_ask.php, which
        // is what the flat-file convention every other endpoint above follows
        // would otherwise imply.
        $this->assertFileExists(__DIR__ . '/../../api/v1/ai/ask.php');
        $this->assertFileExists(__DIR__ . '/../../api/v1/ai/chat.php');
        $this->assertFileDoesNotExist(__DIR__ . '/../../api/v1/ai_ask.php');
        $this->assertFileDoesNotExist(__DIR__ . '/../../api/v1/ai_chat.php');
    }

    // ── all new endpoints reach the auth check the repo-wide sweep looks for ──

    public function testEveryNewEndpointReachesTheApiAuthMarkerTheSweepLooksFor(): void
    {
        // tests/Unit/EndpointAuthSweepTest.php walks api/, actions/ and ajax/
        // recursively — api/v1/ (and its ai/ subdirectory) is already inside
        // that walk, so these files were never exempt from it. This just pins
        // the specific marker it is looking for so a refactor that drops
        // vk_api_require_auth() here fails loudly in this file too, not only
        // in the whole-tree sweep.
        foreach ([
            'api/v1/messages.php', 'api/v1/messages_detail.php', 'api/v1/sms.php',
            'api/v1/email.php', 'api/v1/email-templates.php',
            'api/v1/notifications.php', 'api/v1/notifications_read.php',
            'api/v1/ai/ask.php', 'api/v1/ai/chat.php',
        ] as $file) {
            $this->assertStringContainsString('vk_api_require_auth(', self::code($file), $file);
        }
    }

    // ── regression: message_center.php's send handler now checks canCreate() ──

    public function testWebSendHandlerNowChecksCanCreateBeforeProcessingTheSubmission(): void
    {
        // Regression test for the fix: previously only requireViewPermission()
        // gated the whole page, so any signed-in Member (view-only by default)
        // could POST send_message directly and message anyone. The check must
        // sit inside the handler, before the submitted fields are read.
        $raw = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../../app/constant/communication/message_center.php'));

        $handler   = strpos($raw, "isset(\$_POST['send_message'])");
        $check     = strpos($raw, "if (!canCreate('message_center'))");
        $readField = strpos($raw, "\$recipient_ids = \$_POST['recipient_ids']");

        $this->assertNotFalse($handler, 'send_message handler not found');
        $this->assertNotFalse($check, 'the canCreate(message_center) fix is missing');
        $this->assertNotFalse($readField);
        $this->assertLessThan($check, $handler, 'the check must be inside the send_message handler');
        $this->assertLessThan($readField, $check, 'the check must run before the submitted fields are read');
    }

    public function testWebSendHandlerThrowsABilingualMessageWhenPermissionIsMissing(): void
    {
        $code = self::code('app/constant/communication/message_center.php');
        $this->assertStringContainsString('Huna ruhusa ya kutuma ujumbe', $code);
        $this->assertStringContainsString('You do not have permission to send messages', $code);
    }

    public function testComposeUiIsHiddenForEveryViewOnlyMember(): void
    {
        // $can_create gates the sidebar Compose button, the "New Message"
        // quick action, the folder-header Compose button, the empty-state
        // "Compose First Message" button, and Reply (inbox only) — five
        // `if ($can_create)`-shaped conditionals, fed by exactly one
        // assignment near the top of the file.
        $code = (string) file_get_contents(__DIR__ . '/../../app/constant/communication/message_center.php');
        $this->assertSame(
            1,
            substr_count($code, "\$can_create = canCreate('message_center')"),
            'expected exactly one assignment of $can_create'
        );
        $this->assertSame(5, substr_count($code, '$can_create)'), 'expected exactly 5 UI conditionals gated on $can_create');
    }

    public function testReplyIsOnlyOfferedInTheInboxAndOnlyWithCreatePermission(): void
    {
        $code = self::code('app/constant/communication/message_center.php');
        $this->assertStringContainsString("if (\$folder == 'inbox' && \$can_create)", $code);
    }

    // ── regression: two more bugs found while verifying the fix above live ──

    public function testErrorAndSuccessMessageArraysAreDeclaredBeforeThePostBlockReadsThem(): void
    {
        // Previously $success_messages/$error_messages were declared AFTER the
        // if ($_POST) block that both appends to them and (via header.php's
        // rendering further down) reads them — every catch{}'s refusal reason
        // was silently wiped before the page ever rendered it. The block
        // already refused the write correctly; the user just never saw why.
        $raw = str_replace("\r\n", "\n", (string) file_get_contents(
            __DIR__ . '/../../app/constant/communication/message_center.php'
        ));

        $declareErrors  = strpos($raw, '$error_messages = []');
        $declareSuccess = strpos($raw, '$success_messages = []');
        $postBlock      = strpos($raw, 'if ($_POST)');

        $this->assertNotFalse($declareErrors, 'expected exactly one $error_messages = [] declaration');
        $this->assertNotFalse($declareSuccess, 'expected exactly one $success_messages = [] declaration');
        $this->assertNotFalse($postBlock);
        $this->assertLessThan($postBlock, $declareErrors, '$error_messages must be declared before if ($_POST)');
        $this->assertLessThan($postBlock, $declareSuccess, '$success_messages must be declared before if ($_POST)');

        // And not re-declared a second time further down (the old, dead second
        // declaration that caused the bug must actually be gone, not just
        // shadowed by a new one before it).
        $this->assertSame(1, substr_count($raw, '$error_messages = []'));
        $this->assertSame(1, substr_count($raw, '$success_messages = []'));
    }

    public function testSendMessageRollbackIsGuardedByInTransaction(): void
    {
        // $pdo->beginTransaction() only runs deep inside the try block (after
        // recipient/subject/message validation, and now after the canCreate()
        // check). Every earlier throw — the three original validation throws,
        // and the new permission check — used to hit catch{}'s unconditional
        // $pdo->rollBack(), which itself throws an uncaught PDOException
        // ("There is no active transaction") with no active transaction to
        // roll back. Confirmed live: POSTing send_message as a view-only
        // Member previously crashed the whole page instead of showing the
        // refusal message.
        $code = self::code('app/constant/communication/message_center.php');
        $this->assertStringContainsString('if ($pdo->inTransaction())', $code);

        $guard    = strpos($code, 'if ($pdo->inTransaction())');
        $rollback = strpos($code, '$pdo->rollBack();', $guard === false ? 0 : $guard);
        $this->assertNotFalse($guard);
        $this->assertNotFalse($rollback);
        $this->assertLessThan($rollback, $guard, 'rollBack() must sit inside the inTransaction() guard');
    }
}
