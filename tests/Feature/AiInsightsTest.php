<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * DB-backed tests for core/ai_insights.php — the read-only registry behind
 * "Ask Vikundi". Confirms every insight runs and returns the expected shape.
 *
 * Requires the local `vikundi` database (skips cleanly if unavailable).
 */
class AiInsightsTest extends TestCase
{
    protected function setUp(): void
    {
        try {
            $pdo = new PDO('mysql:host=localhost;dbname=vikundi', 'root', '');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $GLOBALS['pdo'] = $pdo; // aiRunInsight uses global $pdo
        } catch (\Throwable $e) {
            $this->markTestSkipped('vikundi database not available: ' . $e->getMessage());
        }
        require_once __DIR__ . '/../../core/ai_insights.php';
    }

    public function test_catalog_lists_expected_functions(): void
    {
        $names = array_map(fn($c) => $c['name'], aiInsightCatalog());
        foreach (['total_savings', 'top_contributors', 'members_summary', 'pending_approvals', 'group_info'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function test_every_insight_runs_without_error(): void
    {
        foreach (array_keys(aiInsightRegistry()) as $name) {
            $res = aiRunInsight($name, []);
            $this->assertTrue($res['ok'], "insight '$name' should run: " . ($res['error'] ?? ''));
            $this->assertIsArray($res['data']);
        }
    }

    public function test_total_savings_returns_numeric(): void
    {
        $res = aiRunInsight('total_savings', []);
        $this->assertArrayHasKey('total_savings', $res['data']);
        $this->assertIsNumeric($res['data']['total_savings']);
    }

    public function test_total_savings_respects_period(): void
    {
        $res = aiRunInsight('total_savings', ['period' => 'this_year']);
        $this->assertStringContainsString('to', $res['data']['period']);
    }

    public function test_top_contributors_returns_list(): void
    {
        $res = aiRunInsight('top_contributors', ['limit' => 3]);
        $this->assertArrayHasKey('top_contributors', $res['data']);
        $this->assertLessThanOrEqual(3, count($res['data']['top_contributors']));
    }

    public function test_group_info_has_currency(): void
    {
        $res = aiRunInsight('group_info', []);
        $this->assertArrayHasKey('currency', $res['data']);
    }

    public function test_unknown_insight_returns_error_not_crash(): void
    {
        $res = aiRunInsight('this_does_not_exist', []);
        $this->assertFalse($res['ok']);
        $this->assertArrayHasKey('error', $res);
    }

    public function test_ai_ask_data_permission_exists(): void
    {
        $n = (int)$GLOBALS['pdo']->query("SELECT COUNT(*) FROM permissions WHERE page_key='ai_ask_data'")->fetchColumn();
        $this->assertSame(1, $n);
    }
}
