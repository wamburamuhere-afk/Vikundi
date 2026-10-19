<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * bin/import_members.php and its rules (includes/member_list_import.php):
 * loading a group's member list from a prepared CSV.
 */
class MemberListImportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/member_list_import.php';
    }

    private static function row(array $over = []): array
    {
        $r = array_merge([
            'source_ref' => '7', 'first_name' => 'Amina', 'middle_name' => '', 'last_name' => 'Juma', 'phone' => '255712345678',
            'gender' => 'Mwanamke', 'member_number' => 'M/AJ/0007/KE', 'role' => 'Member', 'title' => '',
            'spouse' => 'Hassan Ali Juma', 'father' => 'Juma Said', 'mother' => '', 'children' => 'Zuhura | Said', 'note' => '',
        ], $over);
        return [array_keys($r), array_values($r)];
    }

    public function testAGoodRowIsAccepted(): void
    {
        [$h, $c] = self::row();
        [$r, $errors] = vk_import_row($h, $c);
        $this->assertSame([], $errors);
        $this->assertSame('Amina', $r['first_name']);
    }

    public function testBadValuesAreRefusedWithTheReason(): void
    {
        foreach ([
            [['phone' => '0712345678'], 'phone'],
            [['gender' => 'ME'], 'gender'],
            [['role' => 'Admin'], 'role'],
            [['last_name' => ''], 'last name'],
            [['source_ref' => 'a b'], 'source_ref'],
        ] as [$change, $word]) {
            [$h, $c] = self::row($change);
            [, $errors] = vk_import_row($h, $c);
            $this->assertStringContainsString($word, implode(' ', $errors), json_encode($change));
        }
    }

    public function testAnEmptyRoleMeansMember(): void
    {
        [$h, $c] = self::row(['role' => '']);
        [$r, $errors] = vk_import_row($h, $c);
        $this->assertSame('Member', $r['role']);
        $this->assertSame([], $errors);
    }

    public function testTheHeaderMayComeInAnyOrderButMustBeComplete(): void
    {
        [$h] = self::row();
        $this->assertNull(vk_import_check_header(array_reverse($h)));
        $this->assertNull(vk_import_check_header(array_merge(["\xEF\xBB\xBFsource_ref"], array_slice($h, 1))), 'an Excel BOM is ignored');
        $this->assertStringContainsString('member_number', (string) vk_import_check_header(array_diff($h, ['member_number'])));
    }

    public function testNamesSplitIntoFirstMiddleLast(): void
    {
        $this->assertSame(['Hassan', 'Ali', 'Juma'], vk_import_split_name('Hassan Ali Juma'));
        $this->assertSame(['Anna', 'Maria Rose', 'Mtey'], vk_import_split_name(' Anna  Maria Rose Mtey '));
        $this->assertSame(['Juma', '', ''], vk_import_split_name('Juma'));
        $this->assertSame(['', '', ''], vk_import_split_name(''));
    }

    public function testChildrenTakeTheMemberFormsShape(): void
    {
        $kids = json_decode(vk_import_children_json('Zuhura | Said |'), true);
        $this->assertCount(2, $kids);
        $this->assertSame(['name' => 'Zuhura', 'dob' => null, 'age' => null, 'gender' => '', 'photo' => null], $kids[0]);
        $this->assertSame('[]', vk_import_children_json(''));
    }

    public function testRepeatedRowKeysAndMemberNumbersStopTheImport(): void
    {
        [$h, $c] = self::row();
        [$a] = vk_import_row($h, $c);
        $b = $a; $b['source_ref'] = '8';
        $this->assertSame(["member number 'M/AJ/0007/KE' appears 2 times"], vk_import_duplicates([$a, $b]));
        $b['member_number'] = '';
        $this->assertSame([], vk_import_duplicates([$a, $b]));
        $b['source_ref'] = '7';
        $this->assertSame(["source_ref '7' appears 2 times"], vk_import_duplicates([$a, $b]));
    }

    public function testAnExistingLoginIsTheSamePersonOnlyWhenPhoneAndBothNamesMatch(): void
    {
        [$h, $c] = self::row(['first_name' => 'Florian', 'last_name' => 'Mtey', 'phone' => '255762171054']);
        [$r] = vk_import_row($h, $c);
        $this->assertTrue(vk_import_same_person($r, ['phone' => '+255 762 171 054', 'first_name' => 'FLORIAN', 'last_name' => 'mtey']));
        $this->assertFalse(vk_import_same_person($r, ['phone' => '255762171054', 'first_name' => 'Lightnas', 'last_name' => 'Mtey']), 'a spouse sharing the phone');
        $this->assertFalse(vk_import_same_person($r, ['phone' => '255700000000', 'first_name' => 'Florian', 'last_name' => 'Mtey']));
        $r['phone'] = '';
        $this->assertFalse(vk_import_same_person($r, ['phone' => '', 'first_name' => 'Florian', 'last_name' => 'Mtey']), 'no phone, no match');
    }

    public function testValuesTooLongForTheDatabaseAreFoundBeforeAnythingIsWritten(): void
    {
        [$h, $c] = self::row(['middle_name' => str_repeat('x', 63), 'spouse' => 'Hassan ' . str_repeat('y', 120) . ' Juma']);
        [$r] = vk_import_row($h, $c);
        $problems = vk_import_too_long($r, ['middle_name' => 50, 'spouse_middle_name' => 100, 'first_name' => 100]);
        $this->assertSame([
            'middle_name is 63 characters; the database allows 50',
            'spouse_middle_name is 120 characters; the database allows 100',
        ], $problems);
    }

    public function testTheCommandIsSafeToRunAgainAndCleansUpAHalfMadeMember(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/import_members.php');
        $this->assertMatchesRegularExpression("/if \\(PHP_SAPI !== 'cli'\\) \\{\\s*http_response_code\\(404\\);\\s*exit;/", $src);
        $this->assertStringContainsString('PRIMARY KEY (source, source_ref)', $src, 'each row is logged once per source');
        $this->assertStringContainsString("if (isset(\$done[\$r['source_ref']])) {", $src, 'logged rows are skipped');
        $this->assertStringContainsString("\$pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([\$uid]);", $src, 'users is MyISAM: undo by hand');
        $this->assertStringContainsString('if (!$commit) {', $src, 'dry run by default');
    }

    public function testOnlyLeadersAreGivenAPasswordAnyoneSees(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/import_members.php');
        $this->assertStringContainsString("\$password = \$leader ? vk_prov_secret(9) : vk_prov_secret(32);", $src);
        $this->assertStringContainsString("if (\$leader) {\n            \$credentials[] =", $src);
        $this->assertStringNotContainsString("@123'", $src, 'never the guessable username@123 default');
    }
}
