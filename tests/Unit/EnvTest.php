<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for vikundi_is_dev_host() (includes/env.php), which decides whether PHP
 * errors may be displayed. Production hosts must resolve to false (errors hidden).
 */
class EnvTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/env.php';
    }

    public function testLocalHostsAreDev(): void
    {
        foreach (['localhost', '127.0.0.1', '::1', 'vikundi.localhost', 'app.test', 'localhost:8080'] as $h) {
            $this->assertTrue(vikundi_is_dev_host($h, 'apache2handler'), "$h should be dev");
        }
    }

    public function testProductionHostsAreNotDev(): void
    {
        foreach (['vikundi.co.tz', 'bjptech.example.com', 'example.com', 'sub.domain.org'] as $h) {
            $this->assertFalse(vikundi_is_dev_host($h, 'apache2handler'), "$h should be production");
        }
    }

    public function testUnknownOrEmptyHostIsProduction(): void
    {
        $this->assertFalse(vikundi_is_dev_host('', 'apache2handler'));
        $this->assertFalse(vikundi_is_dev_host(null, 'apache2handler'));
    }

    public function testCliIsDev(): void
    {
        $this->assertTrue(vikundi_is_dev_host('', 'cli'));
        $this->assertTrue(vikundi_is_dev_host('example.com', 'cli'));
    }

    // --- vikundi_is_https() (audit H5: gates the secure session-cookie flag) ---

    public function testDirectTlsIsHttps(): void
    {
        $this->assertTrue(vikundi_is_https(['HTTPS' => 'on']));
        $this->assertTrue(vikundi_is_https(['HTTPS' => '1']));
    }

    public function testStandardHttpsPortIsHttps(): void
    {
        $this->assertTrue(vikundi_is_https(['SERVER_PORT' => 443]));
        $this->assertTrue(vikundi_is_https(['SERVER_PORT' => '443']));
    }

    public function testForwardedProtoIsHttps(): void
    {
        $this->assertTrue(vikundi_is_https(['HTTP_X_FORWARDED_PROTO' => 'https']));
        $this->assertTrue(vikundi_is_https(['HTTP_X_FORWARDED_PROTO' => 'HTTPS']));
    }

    public function testPlainHttpIsNotHttps(): void
    {
        // Local WAMP dev: secure flag must stay off or the session cookie is dropped.
        $this->assertFalse(vikundi_is_https(['HTTPS' => 'off', 'SERVER_PORT' => 80]));
        $this->assertFalse(vikundi_is_https(['SERVER_PORT' => 80, 'HTTP_X_FORWARDED_PROTO' => 'http']));
        $this->assertFalse(vikundi_is_https([]));
    }
}
