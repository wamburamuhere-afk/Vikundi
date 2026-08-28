<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_group_settings.php';

/**
 * The group logo: reading it, and replacing it.
 *
 * group_settings.group_logo holds a BARE FILENAME served from /assets/images/.
 * GET published that raw value as `logo`, which a mobile client cannot load —
 * it is not a URL, and it was empty on a group that had never uploaded one,
 * while every web page fell back to a default. The app showed nothing where the
 * site showed a logo.
 */
final class GroupLogoTest extends TestCase
{
    private const SERVER = [
        'HTTP_HOST'     => 'demo.vikundi.bjptechnologies.co.tz',
        'HTTPS'         => 'on',
        'DOCUMENT_ROOT' => '/home/site/public_html',
    ];

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

    // ── the default logo actually exists ────────────────────────────────────

    /**
     * THE BUG. The file was committed as LOGO1.png while eighteen call sites ask
     * for 'logo1.png'. Windows and macOS do not care; the Linux servers do, so
     * the default logo 404'd on every page that fell back to it — visibly, on
     * the login screen.
     */
    public function testTheDefaultLogoFileExistsWithExactlyTheNameTheCodeAsksFor(): void
    {
        $name = vk_group_settings_default_logo();
        $dir  = __DIR__ . '/../../assets/images';

        $this->assertContains(
            $name,
            scandir($dir) ?: [],
            "assets/images/{$name} must exist with that exact spelling — the live "
            . 'servers are case-sensitive and the fallback 404s otherwise.'
        );
    }

    public function testTheWebFallbackAndTheApiDefaultAreTheSameFile(): void
    {
        foreach (['header.php', 'login.php'] as $file) {
            $this->assertStringContainsString(
                "'" . vk_group_settings_default_logo() . "'",
                self::code($file),
                "{$file} must fall back to the same default the API resolves to."
            );
        }
    }

    // ── resolving a name ────────────────────────────────────────────────────

    public function testAnUnsetLogoResolvesToTheDefault(): void
    {
        $this->assertSame('logo1.png', vk_group_settings_logo_name(null));
        $this->assertSame('logo1.png', vk_group_settings_logo_name(''));
        $this->assertSame('logo1.png', vk_group_settings_logo_name('   '));
    }

    public function testAStoredLogoIsUsedAsIs(): void
    {
        $this->assertSame('group_logo_1775154296.png', vk_group_settings_logo_name('group_logo_1775154296.png'));
    }

    public function testAPathInAStoredNameIsStripped(): void
    {
        // group_settings is a free-form table; a value written by another path
        // must not be able to point the URL outside assets/images/.
        $this->assertSame('passwd', vk_group_settings_logo_name('../../../../etc/passwd'));
        $this->assertSame('x.png', vk_group_settings_logo_name('/var/www/x.png'));
    }

    // ── building a URL ──────────────────────────────────────────────────────

    public function testTheUrlIsAbsoluteAndPointsAtTheServedDirectory(): void
    {
        $this->assertSame(
            'https://demo.vikundi.bjptechnologies.co.tz/assets/images/group_logo_1.png',
            vk_group_settings_logo_url('group_logo_1.png', self::SERVER)
        );
    }

    public function testTheUrlCarriesTheDefaultWhenNothingIsStored(): void
    {
        $this->assertStringEndsWith(
            '/assets/images/logo1.png',
            vk_group_settings_logo_url(null, self::SERVER)
        );
    }

    public function testASubdirectoryInstallKeepsItsBasePath(): void
    {
        // Local WAMP: the project sits at localhost/vikundi, so a URL without
        // the base path resolves nowhere.
        $url = vk_group_settings_logo_url('a.png', [
            'HTTP_HOST'     => 'localhost',
            'DOCUMENT_ROOT' => dirname(__DIR__, 3),
        ]);
        $this->assertStringContainsString('/' . basename(dirname(__DIR__, 2)) . '/assets/images/a.png', $url);
    }

    public function testPlainHttpIsNotAdvertisedAsHttps(): void
    {
        $url = vk_group_settings_logo_url('a.png', ['HTTP_HOST' => 'localhost', 'DOCUMENT_ROOT' => '/nope']);
        $this->assertStringStartsWith('http://', $url);
    }

    public function testWithNoHostTheResultIsStillUsableAsAPath(): void
    {
        // A URL with an empty authority ('https:///assets/...') resolves nowhere.
        $this->assertSame('/assets/images/logo1.png', vk_group_settings_logo_url(null, ['DOCUMENT_ROOT' => '/x']));
    }

    public function testASpaceInAStoredNameIsEncoded(): void
    {
        $this->assertStringContainsString('my%20logo.png', vk_group_settings_logo_url('my logo.png', self::SERVER));
    }

    // ── GET exposes it ──────────────────────────────────────────────────────

    public function testGetReturnsBothTheRawNameAndALoadableUrl(): void
    {
        $code = self::code('api/v1/group-settings.php');

        $this->assertStringContainsString("'logo' =>", $code, 'the raw value stays for existing consumers');
        $this->assertStringContainsString(
            "'logo_url' => vk_group_settings_logo_url(",
            $code,
            'the app needs something it can actually load'
        );
    }

    // ── upload: who, what, where ────────────────────────────────────────────

    /**
     * The router builds the filename from the URL: /api/v1/group-settings/logo
     * resolves to api/v1/group-settings_logo.php, hyphen and all. A file named
     * group_settings_logo.php is never reached and the endpoint silently 404s.
     */
    public function testTheUploadFileIsNamedWhatTheRouterResolvesTo(): void
    {
        preg_match('#^api/v1/([a-z0-9-]+)/([a-z][a-z0-9_-]*)$#', 'api/v1/group-settings/logo', $m);
        $this->assertNotEmpty($m, 'the URL must match the static sub-resource rule');

        $this->assertFileExists(
            __DIR__ . '/../../api/v1/' . $m[1] . '_' . $m[2] . '.php',
            'POST /api/v1/group-settings/logo resolves to this exact filename.'
        );
    }

    public function testTheUploadIsGatedOnTheSameRuleAsTheSettingsForm(): void
    {
        $this->assertStringContainsString(
            'vk_group_settings_may_edit($auth)',
            self::code('api/v1/group-settings_logo.php'),
            'A client that may edit the settings may change the logo, and one that may not, may not.'
        );
    }

    public function testTheUploadRefusesAnythingThatCannotRenderInAnImgTag(): void
    {
        $types = vk_group_settings_logo_types();

        $this->assertNotContains('pdf', $types, 'a PDF logo cannot render, so it must be refused not stored');
        $this->assertNotContains('svg', $types, 'an SVG on this origin is stored XSS');
        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ok) {
            $this->assertContains($ok, $types);
        }
    }

    public function testTheLogoTypesAreASubsetOfWhatTheUploaderAccepts(): void
    {
        require_once __DIR__ . '/../../includes/api_upload.php';
        $allowed = array_keys(vk_api_allowed_upload_types());

        foreach (vk_group_settings_logo_types() as $ext) {
            $this->assertContains($ext, $allowed, "vk_api_store_upload() would reject '{$ext}' anyway.");
        }
    }

    /**
     * Eighteen web call sites and the TCPDF printouts read the logo as
     * '/assets/images/' . $group_logo. Storing it anywhere else means a logo
     * uploaded from the phone renders in the app and nowhere else.
     */
    public function testTheLogoIsStoredWhereTheWebAndThePrintoutsLookForIt(): void
    {
        $code = self::code('api/v1/group-settings_logo.php');

        $this->assertMatchesRegularExpression(
            "/dirname\(__DIR__, 2\) \. '\/assets\/images'/",
            $code,
            'The upload must land in the directory the rest of the app reads.'
        );
        $this->assertStringContainsString("'group_logo'", $code, 'and be recorded under the key they read.');
    }

    public function testTheUploadIsAudited(): void
    {
        $this->assertMatchesRegularExpression(
            "/logUpdate\([^;]*\\\$auth\['user_id'\]\s*\)/s",
            self::code('api/v1/group-settings_logo.php'),
            'The API has no session, so the audit user id must be passed explicitly.'
        );
    }

    public function testTheUploadOnlyAcceptsPost(): void
    {
        $this->assertStringContainsString(
            "vk_api_require_method(['POST'])",
            self::code('api/v1/group-settings_logo.php')
        );
    }

    public function testTheRefusalPrecedesTheWrite(): void
    {
        $code  = self::code('api/v1/group-settings_logo.php');
        $gate  = strpos($code, 'vk_group_settings_may_edit(');
        $store = strpos($code, 'vk_api_store_upload(');

        $this->assertNotFalse($gate);
        $this->assertNotFalse($store);
        $this->assertLessThan($store, $gate, 'The file is saved before the caller is checked.');
    }
}
