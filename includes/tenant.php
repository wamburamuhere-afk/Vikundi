<?php
/**
 * includes/tenant.php — which group (tenant) this request belongs to, and where
 * that group's files live on disk.
 *
 * Two modes:
 *
 *   SINGLE  — the old layout: one code folder per site, its own includes/config.php,
 *             files under the code folder (uploads/, backups/, assets/images/).
 *             This is what every site runs until it is moved, and what local
 *             development runs. Nothing in this file changes its behaviour.
 *
 *   TENANT  — one shared code folder serves every group. includes/tenant_config.php
 *             reads the host name, finds the group in the vikundi_control registry,
 *             opens that group's own database and calls vk_tenant_set(). From then
 *             on every file path resolves under /var/lib/vikundi/<subdomain>/.
 *
 * Every place that reads or writes a stored file asks vk_storage_path() for the
 * path instead of building it from __DIR__ or ROOT_DIR. That single rule is what
 * keeps one group's uploads, logo and backups out of every other group's reach.
 *
 * Pure helpers first (unit-tested without a database), runtime state after.
 */

if (!function_exists('vk_tenant_reserved_subdomains')) {
    /** Names that can never be a group: infrastructure and our own sites. */
    function vk_tenant_reserved_subdomains(): array
    {
        // 'demo' is not reserved: the demo site is the group `demo` on the
        // shared server (moved 2026-10-18).
        return ['www', 'admin', 'api', 'app', 'staging', 'mail', 'smtp', 'ftp', 'test', 'dev', 'static', 'cdn'];
    }
}

if (!function_exists('vk_tenant_valid_subdomain')) {
    /**
     * 3 to 30 characters: lowercase letters, digits and inner hyphens. The same
     * rule names the database (vk_<subdomain>, hyphens become underscores) and
     * the folder under the data root, so it must stay this narrow.
     */
    function vk_tenant_valid_subdomain(string $sub): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{1,28})[a-z0-9]$/', $sub)
            && !str_contains($sub, '--');
    }
}

if (!function_exists('vk_tenant_normalize_host')) {
    /** Lowercase, no port, no trailing dot. '' when there is nothing usable. */
    function vk_tenant_normalize_host(?string $host): string
    {
        $host = strtolower(trim((string) $host));
        if ($host === '' || str_starts_with($host, '[')) {
            return ''; // bracketed IPv6 literals are never a group's address
        }
        $host = explode(':', $host)[0];
        $host = rtrim($host, '.');
        return preg_match('/^[a-z0-9.-]{1,253}$/', $host) ? $host : '';
    }
}

if (!function_exists('vk_tenant_subdomain_for_host')) {
    /**
     * 'wisewallet.vikundi.example.co.tz' with base 'vikundi.example.co.tz' →
     * 'wisewallet'. Exactly ONE label in front of the base, or null: a nested
     * 'a.b.vikundi…' and the bare base itself are not group addresses.
     */
    function vk_tenant_subdomain_for_host(string $host, string $baseDomain): ?string
    {
        $host = vk_tenant_normalize_host($host);
        $base = vk_tenant_normalize_host($baseDomain);
        if ($host === '' || $base === '' || !str_ends_with($host, '.' . $base)) {
            return null;
        }
        $label = substr($host, 0, -strlen('.' . $base));
        if (str_contains($label, '.') || !vk_tenant_valid_subdomain($label)) {
            return null;
        }
        return $label;
    }
}

if (!function_exists('vk_tenant_decide')) {
    /**
     * What to do with a request, given the registry row (or null).
     *   'serve'      active group
     *   'suspended'  the offline page (HTTP 503)
     *   'not_found'  no such group, archived, or still being created (HTTP 404)
     */
    function vk_tenant_decide(?array $row): string
    {
        return match ($row['status'] ?? null) {
            'active'    => 'serve',
            'suspended' => 'suspended',
            default     => 'not_found',
        };
    }
}

if (!function_exists('vk_tenant_cli_name')) {
    /**
     * The group a command-line script was pointed at: --tenant=<name> (or
     * --tenant <name>) wins over the VIKUNDI_TENANT environment variable.
     */
    function vk_tenant_cli_name(array $argv, ?string $env): ?string
    {
        foreach ($argv as $i => $arg) {
            if (str_starts_with((string) $arg, '--tenant=')) {
                return substr((string) $arg, 9);
            }
            if ($arg === '--tenant' && isset($argv[$i + 1])) {
                return (string) $argv[$i + 1];
            }
        }
        $env = trim((string) $env);
        return $env === '' ? null : $env;
    }
}

if (!function_exists('vk_tenant_lookup')) {
    /**
     * The registry row for a host, or null. A full host name in tenant_hosts wins
     * (that is how production keeps vikundi.bjptechnologies.co.tz); otherwise the
     * subdomain in front of the base domain is looked up in tenants.
     */
    function vk_tenant_lookup(PDO $control, string $host, string $baseDomain): ?array
    {
        $host = vk_tenant_normalize_host($host);
        if ($host === '') {
            return null;
        }
        $st = $control->prepare(
            'SELECT t.* FROM tenant_hosts h JOIN tenants t ON t.id = h.tenant_id WHERE h.host = ? LIMIT 1'
        );
        $st->execute([$host]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        $sub = vk_tenant_subdomain_for_host($host, $baseDomain);
        return $sub === null ? null : vk_tenant_find($control, $sub);
    }
}

if (!function_exists('vk_tenant_find')) {
    /** The registry row for a subdomain, or null. */
    function vk_tenant_find(PDO $control, string $sub): ?array
    {
        if (!vk_tenant_valid_subdomain($sub)) {
            return null;
        }
        $st = $control->prepare('SELECT * FROM tenants WHERE subdomain = ? LIMIT 1');
        $st->execute([$sub]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('vk_storage_relative')) {
    /**
     * A stored path as the database keeps it ('uploads/x.pdf', '/uploads/x.pdf',
     * '../uploads/x.pdf') reduced to a clean relative path, or null when it tries
     * to climb out ('uploads/../../etc') or is empty.
     */
    function vk_storage_relative(string $stored): ?string
    {
        $stored = str_replace('\\', '/', trim($stored));
        $parts = [];
        foreach (explode('/', $stored) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($parts === []) {
                    continue; // a leading '../' is how some handlers spell "from the code root"
                }
                return null;  // a '..' after a real segment climbs out: refuse
            }
            $parts[] = $seg;
        }
        return $parts === [] ? null : implode('/', $parts);
    }
}

// ── Runtime state ───────────────────────────────────────────────────────────

if (!function_exists('vk_tenant_set')) {
    /**
     * Called once by includes/tenant_config.php after the group is found.
     * $dataDir is that group's folder, e.g. /var/lib/vikundi/wisewallet.
     */
    function vk_tenant_set(array $tenant, string $dataDir): void
    {
        $GLOBALS['__vk_tenant'] = ['row' => $tenant, 'data_dir' => rtrim($dataDir, '/')];
    }
}

if (!function_exists('vk_tenant_current')) {
    /** The current group's registry row, or null in single-site mode. */
    function vk_tenant_current(): ?array
    {
        return $GLOBALS['__vk_tenant']['row'] ?? null;
    }
}

if (!function_exists('vk_storage_base')) {
    /** The folder stored files live under: the group's data folder, or the code folder. */
    function vk_storage_base(): string
    {
        return $GLOBALS['__vk_tenant']['data_dir'] ?? dirname(__DIR__);
    }
}

if (!function_exists('vk_storage_path')) {
    /**
     * The absolute path of a stored file or folder. Pass the path as the database
     * stores it, or the folder a handler writes into:
     *
     *   vk_storage_path('uploads/avatars')            → …/uploads/avatars
     *   vk_storage_path($doc['file_path'])             → …/uploads/document_library/x.pdf
     *
     * A path that tries to climb out of the storage base resolves to a name that
     * cannot exist, so file_exists()/readfile() simply fail instead of reaching
     * somewhere else.
     */
    function vk_storage_path(string $stored): string
    {
        $rel = vk_storage_relative($stored);
        $base = vk_storage_base();
        return $rel === null ? $base . '/.vk-invalid-path' : $base . '/' . $rel;
    }
}

if (!function_exists('vk_storage_dir')) {
    /** vk_storage_path() for a folder, created if missing, with a trailing slash. */
    function vk_storage_dir(string $folder, int $mode = 0755): string
    {
        $dir = vk_storage_path($folder);
        if (!is_dir($dir)) {
            @mkdir($dir, $mode, true);
        }
        return $dir . '/';
    }
}

if (!function_exists('vk_logo_file')) {
    /**
     * Where a group logo's bytes are on disk. Logos a group uploaded live in its
     * own storage; the logos shipped with the code (logo1.png, the demo logos)
     * stay in assets/images/ and are found there.
     */
    function vk_logo_file(string $name): string
    {
        $name = basename($name);
        $stored = vk_storage_path('assets/images/' . $name);
        return is_file($stored) ? $stored : dirname(__DIR__) . '/assets/images/' . $name;
    }
}

if (!function_exists('vk_secret_key_file')) {
    /**
     * The local encryption key for stored API secrets (AI, email, SMS). Each group
     * has its own in tenant mode, so a key leak from one group decrypts nothing of
     * another's. In single-site mode it stays at includes/ai_secret.key.
     */
    function vk_secret_key_file(): string
    {
        return vk_tenant_current() === null
            ? dirname(__DIR__) . '/includes/ai_secret.key'
            : vk_storage_dir('private', 0700) . 'ai_secret.key';
    }
}
