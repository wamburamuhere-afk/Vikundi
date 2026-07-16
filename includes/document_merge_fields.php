<?php
/**
 * includes/document_merge_fields.php
 * ----------------------------------
 * Mail-merge for the Document Writer. A template or document can contain
 * {placeholder} tokens; when a document is saved they are resolved into real
 * values and FROZEN into the stored HTML.
 *
 * Freezing at save time is the important decision: a document is a record. A
 * signed letter must never silently change because a member's phone number was
 * edited later. Templates, by contrast, keep the tokens raw (that is their point).
 *
 * The registry (which tokens exist) and the resolver (str_replace) are pure and
 * unit-tested. Only vk_build_merge_values() touches the database.
 */

if (!function_exists('vk_document_merge_fields')) {
    /**
     * The available merge fields, grouped for the "Insert field" menu.
     * @return array<string,array{label:string,fields:array<array{token:string,label:string}>}>
     */
    function vk_document_merge_fields(bool $isSw = false): array
    {
        $t = fn($en, $sw) => $isSw ? $sw : $en;
        return [
            'group' => ['label' => $t('Group', 'Kikundi'), 'fields' => [
                ['token' => '{group_name}', 'label' => $t('Group name', 'Jina la kikundi')],
            ]],
            'date' => ['label' => $t('Date', 'Tarehe'), 'fields' => [
                ['token' => '{today}', 'label' => $t("Today's date", 'Tarehe ya leo')],
            ]],
            'author' => ['label' => $t('Author', 'Mwandishi'), 'fields' => [
                ['token' => '{author_name}', 'label' => $t('Author name', 'Jina la mwandishi')],
                ['token' => '{author_role}', 'label' => $t('Author role', 'Cheo cha mwandishi')],
            ]],
            'member' => ['label' => $t('Member (pick one below)', 'Mwanachama (chagua hapa chini)'), 'fields' => [
                ['token' => '{member_name}', 'label' => $t('Member name', 'Jina la mwanachama')],
                ['token' => '{member_username}', 'label' => $t('Member username', 'Jina la mtumiaji')],
                ['token' => '{member_phone}', 'label' => $t('Member phone', 'Simu ya mwanachama')],
                ['token' => '{member_email}', 'label' => $t('Member email', 'Barua pepe ya mwanachama')],
                ['token' => '{member_contributions}', 'label' => $t('Total contributions', 'Jumla ya michango')],
            ]],
        ];
    }
}

if (!function_exists('vk_resolve_merge_fields')) {
    /**
     * Replace {tokens} present in $values. Tokens without a value are left as-is,
     * so a writer notices a field they forgot to fill (e.g. member fields with no
     * member selected). Values must already be HTML-safe (the builder escapes).
     */
    function vk_resolve_merge_fields(string $html, array $values): string
    {
        if ($html === '' || $values === []) { return $html; }
        return str_replace(array_keys($values), array_values($values), $html);
    }
}

if (!function_exists('vk_build_merge_values')) {
    /**
     * Build the token => value map for a document.
     *
     * Group / date / author values are always present. Member values are included
     * only when a member is chosen — otherwise those tokens stay literal.
     * All values are HTML-escaped because they are injected into HTML.
     *
     * @return array<string,string>
     */
    function vk_build_merge_values(PDO $pdo, ?int $memberId, string $authorName, string $authorRole): array
    {
        $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $groupName = $pdo->query("SELECT setting_value FROM group_settings WHERE setting_key = 'group_name' LIMIT 1")->fetchColumn();

        $values = [
            '{group_name}'  => $esc($groupName !== false && $groupName !== null ? $groupName : 'VIKUNDI'),
            '{today}'       => $esc(date('d M Y')),
            '{author_name}' => $esc($authorName),
            '{author_role}' => $esc($authorRole),
        ];

        if ($memberId !== null && $memberId > 0) {
            $m = $pdo->prepare(
                "SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) AS name, username, phone, email
                   FROM users WHERE user_id = ?"
            );
            $m->execute([$memberId]);
            if ($row = $m->fetch(PDO::FETCH_ASSOC)) {
                // Contributions key on customers.customer_id, resolved from the
                // login user_id — the SAME figure shown on the member statement
                // and the Member Home. (Using user_id directly reads the wrong
                // member's savings.)
                require_once __DIR__ . '/member_savings.php';
                $cid   = vk_member_customer_id($pdo, (int) $memberId);
                $total = $cid !== null ? vk_member_savings_total($pdo, $cid) : 0.0;

                $values['{member_name}']          = $esc($row['name'] !== '' ? $row['name'] : $row['username']);
                $values['{member_username}']      = $esc($row['username']);
                $values['{member_phone}']         = $esc($row['phone'] ?: '—');
                $values['{member_email}']         = $esc($row['email'] ?: '—');
                $values['{member_contributions}'] = $esc(function_exists('format_currency') ? format_currency($total) : number_format($total, 2));
            }
        }

        return $values;
    }
}

if (!function_exists('vk_render_merge_field_menu')) {
    /**
     * Render the "Insert field" dropdown, wired to insert a token into the given
     * Summernote editor at the cursor. A plain Bootstrap-5 dropdown (outside the
     * note-editor), so it is unaffected by the editor's own dropdown handling.
     *
     * @param string $selector jQuery selector of the Summernote element
     */
    function vk_render_merge_field_menu(string $selector, bool $isSw = false): void
    {
        $groups = vk_document_merge_fields($isSw);
        $label  = $isSw ? 'Ingiza uga' : 'Insert field';
        ?>
        <div class="dropdown d-inline-block">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-braces me-1"></i><?= htmlspecialchars($label) ?>
            </button>
            <ul class="dropdown-menu shadow-sm" style="max-height:340px;overflow:auto;">
                <?php foreach ($groups as $g): ?>
                <li><h6 class="dropdown-header"><?= htmlspecialchars($g['label']) ?></h6></li>
                    <?php foreach ($g['fields'] as $f): ?>
                    <li><a class="dropdown-item small" href="#" onclick="vkInsertMergeField(<?= json_encode($selector) ?>, <?= json_encode($f['token']) ?>);return false;">
                        <code class="text-primary"><?= htmlspecialchars($f['token']) ?></code>
                        <span class="text-muted ms-1"><?= htmlspecialchars($f['label']) ?></span>
                    </a></li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
        </div>
        <script>
        function vkInsertMergeField(sel, token) {
            var $ed = window.jQuery ? jQuery(sel) : null;
            if ($ed && $ed.summernote) {
                $ed.summernote('focus');
                $ed.summernote('insertText', token);
            }
        }
        </script>
        <?php
    }
}
