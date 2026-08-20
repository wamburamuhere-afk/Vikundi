<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Whole-tree invariant: no hard-coded role list may grant Admin and Secretary
 * while omitting the Chairperson.
 *
 * WHY THIS SHAPE. The Chairperson leads a VICOBA group and
 * core/permissions.php::isAdmin() has always counted 'chairperson' as an admin.
 * But twelve screens bypassed isAdmin() and carried their own array instead —
 * typically ['Admin', 'Secretary', 'Katibu'] — none of which contained
 * 'Chairperson', the exact role name database/seed_vicoba_roles.php creates.
 *
 * The result on the live system: the head of the group could not open the
 * group's own settings, approve a new member, add a member, record a payout,
 * change a user's role or status, or see petty cash. The dashboard served them
 * the ordinary member view. Every one of those failures was silent — a blank
 * page or a quietly reduced screen, never an error anyone could act on — which
 * is why they survived so long.
 *
 * Each site was found by grep after the third instance turned up, not by any
 * test, because a hard-coded allowlist test can only pin the sites someone has
 * already thought of. This sweep is closed over the property instead: write a
 * new leadership gate that forgets the Chairperson anywhere under the scanned
 * directories and it goes red.
 *
 * IF THIS FAILS, add the chairperson to the list — or better, use
 * includes/roles.php::vk_role_is_leadership(), which is the reason that file
 * exists.
 */
class ChairpersonAccessSweepTest extends TestCase
{
    private const SCANNED_DIRS = ['app', 'actions', 'ajax', 'api', 'core', 'includes'];

    /**
     * A role array is "a leadership gate" when it names Admin AND at least one
     * officer role. That pairing is what every real gate in this codebase looks
     * like, and it avoids matching unrelated arrays that merely contain the word
     * admin.
     */
    private const ADMIN_TOKENS   = ['admin', 'administrator', 'super admin'];
    private const OFFICER_TOKENS = ['secretary', 'katibu', 'treasurer', 'mhasibu', 'mhazini', 'mweka hazina'];
    private const CHAIR_TOKENS   = ['chairperson', 'mwenyekiti', 'chairman'];

    /**
     * Files that legitimately name roles without being an access gate. Keyed by
     * path so the reason travels with the entry.
     */
    private const EXEMPT = [
        // The definition itself: vk_role_admin_names() IS the chairperson list,
        // and vk_role_officer_names() is deliberately the roles that are NOT
        // admins, so it must not contain the chairperson.
        'includes/roles.php' => 'Defines the lists this sweep checks against.',
    ];

    /** @return string[] repo-relative paths of every PHP file under the scanned dirs */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (self::SCANNED_DIRS as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($rii as $f) {
                $p = $f->getPathname();
                if (str_ends_with($p, '.php')) {
                    $files[] = str_replace($root . '/', '', $p);
                }
            }
        }
        sort($files);
        return $files;
    }

    /** Every `[ ... ]` literal in the source that looks like a list of role names. */
    private function roleArrays(string $src): array
    {
        // Comments are stripped first: several of these files describe the bug in
        // prose, and matching that would test the wording of a comment.
        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    $code .= str_repeat("\n", substr_count($t[1], "\n"));
                    continue;
                }
                $code .= $t[1];
                continue;
            }
            $code .= $t;
        }

        preg_match_all("/\[[^\[\]]*\]/", $code, $m);
        return $m[0];
    }

    private function isLeadershipGate(string $literal): bool
    {
        $lower = strtolower($literal);
        $hasAdmin = false;
        foreach (self::ADMIN_TOKENS as $t) {
            if (str_contains($lower, "'{$t}'")) {
                $hasAdmin = true;
                break;
            }
        }
        if (!$hasAdmin) {
            return false;
        }
        foreach (self::OFFICER_TOKENS as $t) {
            if (str_contains($lower, "'{$t}'")) {
                return true;
            }
        }
        return false;
    }

    private function namesTheChairperson(string $literal): bool
    {
        $lower = strtolower($literal);
        foreach (self::CHAIR_TOKENS as $t) {
            if (str_contains($lower, "'{$t}'")) {
                return true;
            }
        }
        return false;
    }

    public function testNoLeadershipGateOmitsTheChairperson(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach ($this->phpFiles() as $rel) {
            if (isset(self::EXEMPT[$rel])) {
                continue;
            }
            $src = (string) file_get_contents($root . '/' . $rel);
            foreach ($this->roleArrays($src) as $literal) {
                if ($this->isLeadershipGate($literal) && !$this->namesTheChairperson($literal)) {
                    $offenders[] = $rel . ': ' . preg_replace('/\s+/', ' ', $literal);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These role lists grant Admin and an officer but omit the Chairperson, who leads the\n"
            . "group and whom isAdmin() already treats as an admin. Each one silently locks the\n"
            . "group's chairperson out of a screen.\n\n"
            . "Prefer includes/roles.php::vk_role_is_leadership() over a new hard-coded list.\n"
        );
    }

    /** The sweep is worthless if it silently walks nothing. */
    public function testTheSweepActuallyCoversTheTree(): void
    {
        $this->assertGreaterThan(
            300,
            count($this->phpFiles()),
            'The sweep appears to be walking the wrong tree.'
        );
    }

    /** An exemption for a file that no longer exists hides drift. */
    public function testNoStaleExemptions(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (array_keys(self::EXEMPT) as $rel) {
            $this->assertFileExists($root . '/' . $rel, "Stale exemption: {$rel}");
        }
    }
}
