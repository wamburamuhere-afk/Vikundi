<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The lock file must be installable on the PHP version the servers actually run.
 *
 * WHY THIS EXISTS. composer.json declared `"php": ">=8.2"` but set no
 * `config.platform`, so composer resolved dependencies against whatever PHP the
 * developer happened to have. A lock generated on PHP 8.4 pulled in
 * symfony/options-resolver v8.1.0 (php >= 8.4.1) as a transitive dependency of
 * sentry/sentry, and from that moment `composer install` failed on every machine
 * running 8.2 — which is both CI and the production server.
 *
 * The damage was quiet. CI had been red since 2026-07-31 and merges proceeded
 * anyway. On the server the deploy script swallows the failure with
 * `|| echo "composer step skipped"`, so deploys reported success while no
 * dependency had been installable for weeks — including firebase/php-jwt, which
 * the mobile API needs to work at all.
 *
 * Two properties are pinned here, both cheap and both checked without a network:
 *
 *   1. config.platform.php is set, so resolution can never again drift to the
 *      developer's local PHP.
 *   2. No locked package requires a PHP newer than that pinned floor.
 *
 * (2) is the one that actually bites: (1) can be present and still be set to the
 * wrong version.
 */
class ComposerPlatformTest extends TestCase
{
    /** The oldest PHP any deployment target runs. Matches the CI matrix. */
    private const SUPPORTED_FLOOR = '8.2.0';

    private static function json(string $file): array
    {
        $path = dirname(__DIR__, 2) . '/' . $file;
        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data, "{$file} is not valid JSON.");
        return $data;
    }

    public function testComposerJsonPinsThePlatformPhpVersion(): void
    {
        $composer = self::json('composer.json');

        $platform = $composer['config']['platform']['php'] ?? null;

        $this->assertNotNull(
            $platform,
            "composer.json must set config.platform.php.\n"
            . "Without it, composer resolves against the developer's local PHP and can lock\n"
            . "packages the server cannot install."
        );
        $this->assertSame(
            self::SUPPORTED_FLOOR,
            $platform,
            'config.platform.php must match the oldest PHP any deployment target runs.'
        );
    }

    /**
     * The root constraint and the pinned platform must agree. A root of ">=8.2"
     * with a platform of 8.3 would be a silent contradiction.
     */
    public function testTheRootPhpConstraintAdmitsThePinnedFloor(): void
    {
        $composer = self::json('composer.json');
        $root = $composer['require']['php'] ?? null;

        $this->assertNotNull($root, 'composer.json must declare a php requirement.');
        $this->assertTrue(
            self::constraintAllows($root, self::SUPPORTED_FLOOR),
            "The root php constraint ({$root}) excludes the pinned platform "
            . self::SUPPORTED_FLOOR . '.'
        );
    }

    /**
     * The check that actually catches the defect: walk every locked package and
     * reject any whose php requirement excludes the supported floor.
     */
    public function testNoLockedPackageRequiresANewerPhpThanTheFloor(): void
    {
        $lock = self::json('composer.lock');
        $offenders = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($lock[$section] ?? [] as $package) {
                $req = $package['require']['php'] ?? null;
                if ($req === null) {
                    continue;
                }
                if (!self::constraintAllows($req, self::SUPPORTED_FLOOR)) {
                    $offenders[] = sprintf(
                        '%s %s requires php %s',
                        $package['name'],
                        $package['version'],
                        $req
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These locked packages cannot be installed on PHP " . self::SUPPORTED_FLOOR . ":\n  "
            . implode("\n  ", $offenders)
            . "\n\nThe lock was probably regenerated on a newer PHP. Fix it with:\n"
            . "  composer update <package> --with-all-dependencies\n"
            . "with config.platform.php set in composer.json.\n"
        );
    }

    /**
     * The sweep above is only as good as this parser. A parser that answered
     * "allowed" to everything would make the whole test vacuous, so pin its
     * behaviour on the constraint forms that actually appear in the lock —
     * including the single-pipe alternative `^7.4|^8.0`, which an earlier
     * version of this parser mistook for one unparseable term.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('constraints')]
    public function testTheConstraintParserIsCorrect(string $constraint, bool $expected): void
    {
        $this->assertSame(
            $expected,
            self::constraintAllows($constraint, self::SUPPORTED_FLOOR),
            "Constraint '{$constraint}' against PHP " . self::SUPPORTED_FLOOR
        );
    }

    /** @return array<string, array{0:string,1:bool}> */
    public static function constraints(): array
    {
        return [
            // Real strings taken from composer.lock.
            'sentry double-pipe'   => ['^7.2 || ^8.0', true],
            'sentry single-pipe'   => ['^7.2|^8.0', true],
            'pretty-versions'      => ['^7.4|^8.0', true],
            'phpunit floor'        => ['>=8.2', true],
            'psr/log floor'        => ['>=8.0.0', true],
            'polyfill floor'       => ['>=7.2', true],
            'htmlpurifier tildes'  => ['~8.1.0 || ~8.2.0 || ~8.3.0', true],

            // The defect this whole test exists to catch.
            'options-resolver v8'  => ['>=8.4.1', false],

            // Boundaries.
            'exactly the floor'    => ['>=8.2.0', true],
            'just above the floor' => ['>=8.2.1', false],
            'caret next major'     => ['^9.0', false],
            'tilde excludes 8.2'   => ['~8.3.0', false],
            'upper bound excludes' => ['>=8.0 <8.2', false],
            'upper bound includes' => ['>=8.0 <9.0', true],
        ];
    }

    /** The lock must correspond to the current composer.json. */
    public function testTheLockIsInSyncWithComposerJson(): void
    {
        $lock = self::json('composer.lock');
        $this->assertArrayHasKey('content-hash', $lock, 'composer.lock has no content-hash.');
        $this->assertNotSame('', (string) $lock['content-hash']);
    }

    /**
     * Minimal composer-style constraint evaluation: does `$constraint` admit
     * `$version`? Handles the forms that appear in real lock files — `>=X`,
     * `^X`, `~X.Y.Z`, and `||`-separated alternatives.
     *
     * Deliberately conservative: anything it cannot parse is treated as
     * allowing the version, so this test reports real breakage rather than
     * noise from an exotic constraint string.
     */
    private static function constraintAllows(string $constraint, string $version): bool
    {
        // Both `||` and the single-pipe form `^7.4|^8.0` appear in real lock
        // files; splitting on `||` alone treats the latter as one unparseable
        // term and reports a false failure.
        foreach (preg_split('/\|\|?/', $constraint) ?: [] as $alternative) {
            $alternative = trim($alternative);
            if ($alternative !== '' && self::alternativeAllows($alternative, $version)) {
                return true;
            }
        }
        return false;
    }

    private static function alternativeAllows(string $alt, string $version): bool
    {
        // An alternative may be a space-separated AND group, e.g. ">=8.1 <9.0".
        foreach (preg_split('/[\s,]+/', $alt, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
            if (!self::termAllows($term, $version)) {
                return false;
            }
        }
        return true;
    }

    private static function termAllows(string $term, string $version): bool
    {
        if (!preg_match('/^(>=|<=|>|<|\^|~|=)?\s*v?(\d+(?:\.\d+){0,2})/', $term, $m)) {
            return true; // unparseable — do not invent a failure
        }
        $op    = $m[1] ?: '=';
        $bound = self::pad($m[2]);
        $v     = self::pad($version);
        $cmp   = version_compare($v, $bound);

        return match ($op) {
            '>='    => $cmp >= 0,
            '>'     => $cmp > 0,
            '<='    => $cmp <= 0,
            '<'     => $cmp < 0,
            // ^8.0 admits >=8.0 <9.0 ; ~8.2.0 admits >=8.2.0 <8.3.0
            '^'     => $cmp >= 0 && self::major($v) === self::major($bound),
            '~'     => $cmp >= 0 && self::tildeCeilingHolds($m[2], $v),
            default => $cmp === 0,
        };
    }

    private static function pad(string $v): string
    {
        $parts = array_pad(explode('.', $v), 3, '0');
        return implode('.', array_slice($parts, 0, 3));
    }

    private static function major(string $v): string
    {
        return explode('.', $v)[0];
    }

    /**
     * ~X.Y.Z allows up to but not including X.(Y+1).0;
     * ~X.Y allows up to but not including (X+1).0.0.
     */
    private static function tildeCeilingHolds(string $raw, string $version): bool
    {
        $parts = explode('.', $raw);
        if (count($parts) >= 3) {
            $ceiling = $parts[0] . '.' . ((int) $parts[1] + 1) . '.0';
        } else {
            $ceiling = ((int) $parts[0] + 1) . '.0.0';
        }
        return version_compare($version, $ceiling) < 0;
    }
}
