<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the schema-drift checker's pure parser (database/check_schema_drift.php),
 * audit H2. Requiring the file only defines the function — the CLI scan is
 * guarded behind a direct-invocation check, so no DB is touched here.
 */
class SchemaDriftCheckerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../database/check_schema_drift.php';
    }

    public function testExtractsTableAndColumns(): void
    {
        $pairs = vikundi_extract_insert_columns("INSERT INTO `users` (username, email, role_id) VALUES (?, ?, ?)");
        $this->assertContains(['users', 'username'], $pairs);
        $this->assertContains(['users', 'email'], $pairs);
        $this->assertContains(['users', 'role_id'], $pairs);
    }

    public function testHandlesMultilineAndNoBackticks(): void
    {
        $sql = "INSERT INTO contributions\n  (member_id, amount, status)\n  VALUES (1, 2, 'approved')";
        $pairs = vikundi_extract_insert_columns($sql);
        $this->assertContains(['contributions', 'member_id'], $pairs);
        $this->assertContains(['contributions', 'amount'], $pairs);
        $this->assertContains(['contributions', 'status'], $pairs);
    }

    public function testSkipsDynamicColumnLists(): void
    {
        // A column list built from a PHP variable cannot be statically checked.
        $this->assertSame([], vikundi_extract_insert_columns('INSERT INTO t ($columns) VALUES ($vals)'));
    }

    public function testIgnoresNonInsertSql(): void
    {
        $this->assertSame([], vikundi_extract_insert_columns("SELECT a, b FROM users WHERE id = 1"));
    }
}
