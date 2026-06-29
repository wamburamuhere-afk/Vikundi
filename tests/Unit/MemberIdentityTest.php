<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Covers the pure member-identity helpers: username building, mail-domain
 * normalisation, and email assembly. The DB-backed helpers
 * (vk_member_email_domain, vk_unique_username) are verified live — they only
 * read/write group_settings and the users table.
 */
class MemberIdentityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/member_identity.php';
    }

    public function testUsernameIsFirstInitialPlusLastName(): void
    {
        $this->assertSame('jdoe', vk_build_username('John', 'Doe'));
    }

    public function testUsernameLowercasesAndStripsSpaces(): void
    {
        $this->assertSame('mvanderberg', vk_build_username('Mary', 'Van Der Berg'));
        $this->assertSame('jdoe', vk_build_username('  John ', ' Doe '));
    }

    public function testUsernameStripsPunctuationInSurname(): void
    {
        // Apostrophes, hyphens and dots in the surname are removed.
        $this->assertSame('oobrien', vk_build_username('Omari', "O'Brien"));
        $this->assertSame('amwakyusa', vk_build_username('Anna', 'Mwa-kyusa'));
    }

    public function testNormalizeEmailDomainStripsWwwAndPort(): void
    {
        $this->assertSame('vikundi.co.tz', vk_normalize_email_domain('www.vikundi.co.tz'));
        $this->assertSame('vikundi.co.tz', vk_normalize_email_domain('vikundi.co.tz:8080'));
        $this->assertSame('vikundi.co.tz', vk_normalize_email_domain('WWW.Vikundi.CO.TZ'));
    }

    public function testNormalizeEmailDomainHandlesFullUrl(): void
    {
        $this->assertSame('x.com', vk_normalize_email_domain('https://www.x.com:8080/path/page'));
        $this->assertSame('x.com', vk_normalize_email_domain('x.com/some/path'));
    }

    public function testNormalizeEmailDomainEmptyInput(): void
    {
        $this->assertSame('', vk_normalize_email_domain(''));
        $this->assertSame('', vk_normalize_email_domain(null));
    }

    public function testBuildMemberEmailJoinsUsernameAndDomain(): void
    {
        $this->assertSame('jdoe@vikundi.co.tz', vk_build_member_email('jdoe', 'vikundi.co.tz'));
    }

    public function testBuildMemberEmailNormalisesDomainAndUsername(): void
    {
        $this->assertSame('jdoe@x.com', vk_build_member_email('JDOE', 'https://www.x.com:80/'));
    }

    public function testBuildMemberEmailFallsBackToLocalhostWhenNoDomain(): void
    {
        $this->assertSame('jdoe@localhost', vk_build_member_email('jdoe', ''));
    }
}
