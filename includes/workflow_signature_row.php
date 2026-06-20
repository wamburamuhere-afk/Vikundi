<?php
/**
 * Print-page signature row — Created / Reviewed / Approved By.
 * ------------------------------------------------------------
 * Expects $wf array in scope:
 *   created_by_name,  created_by_role,  created_sig_path,  created_signed_at
 *   reviewed_by_name, reviewed_by_role, reviewed_sig_path, reviewed_signed_at
 *   approved_by_name, approved_by_role, approved_sig_path, approved_signed_at
 *   __include_css  — set true to output the <style> block
 *
 * The three signature lines always render. When a slot is unfilled the line
 * is blank — leaving a physical space for handwritten signing.
 */

if (!isset($wf) || !is_array($wf)) $wf = [];

if (!empty($wf['__include_css'])):
?>
<style>
    .signature-box {
        margin-top: 46px;
        display: flex;
        justify-content: space-around;
        gap: 40px;
    }
    .signature-line {
        width: 210px;
        padding-top: 7px;
        text-align: center;
        border-top: 1.5px solid #1a252f;
        font-size: 11px;
        color: #1a252f;
        font-weight: 600;
    }
    .signature-line small {
        display: block;
        margin-top: 4px;
        font-size: 10px;
        font-weight: 400;
        color: #495057;
    }
    .sig-img-wrap {
        min-height: 48px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-end;
        margin-bottom: 4px;
    }
    .sig-img-wrap img {
        max-height: 45px;
        max-width: 150px;
        object-fit: contain;
    }
    .sig-protocol {
        display: block;
        font-size: 7.5px;
        font-weight: 600;
        color: #0a6efd;
        letter-spacing: 0.02em;
        margin-top: 2px;
    }
    .sig-timestamp {
        display: block;
        font-size: 7px;
        font-weight: 400;
        color: #6c757d;
        margin-top: 1px;
    }
</style>
<?php endif;

/**
 * Render one signature column.
 */
$_renderSigCol = function (string $label, string $name, string $role,
                            ?string $sigPath, ?string $signedAt): void {
    $base = rtrim((function () {
        $root = str_replace('\\', '/', ROOT_DIR);
        $doc  = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
        $base = trim(str_replace($doc, '', $root), '/');
        return $base !== '' ? '/' . $base : '';
    })(), '/');

    echo '<div class="signature-line">';

    if ($sigPath) {
        $sigUrl = $base . '/' . ltrim($sigPath, '/');
        $ts = '';
        if ($signedAt) {
            $dt = new DateTime($signedAt);
            $ts = $dt->format('d M Y  H:i:s');
        }
        echo '<div class="sig-img-wrap">';
        echo '<img src="' . htmlspecialchars($sigUrl) . '" alt="e-signature">';
        echo '<span class="sig-protocol">Digitally signed</span>';
        if ($ts) {
            echo '<span class="sig-timestamp">' . htmlspecialchars($ts) . '</span>';
        }
        echo '</div>';
    }

    echo htmlspecialchars($label) . '<br>';
    echo '<small>';
    echo htmlspecialchars($name) . ($role ? ' &mdash; ' . htmlspecialchars($role) : '');
    echo '</small>';
    echo '</div>';
};

echo '<div class="signature-box">';
$_renderSigCol(
    'Created By',
    $wf['created_by_name']   ?? '',
    $wf['created_by_role']   ?? '',
    $wf['created_sig_path']  ?? null,
    $wf['created_signed_at'] ?? null
);
$_renderSigCol(
    'Reviewed By',
    $wf['reviewed_by_name']   ?? '',
    $wf['reviewed_by_role']   ?? '',
    $wf['reviewed_sig_path']  ?? null,
    $wf['reviewed_signed_at'] ?? null
);
$_renderSigCol(
    'Approved By',
    $wf['approved_by_name']   ?? '',
    $wf['approved_by_role']   ?? '',
    $wf['approved_sig_path']  ?? null,
    $wf['approved_signed_at'] ?? null
);
echo '</div>';
