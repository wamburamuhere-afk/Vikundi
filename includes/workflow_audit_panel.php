<?php
/**
 * View-page audit-trail panel — Created / Reviewed / Approved By.
 * ---------------------------------------------------------------
 * Expects $wf array in scope:
 *   created_by_name,  created_by_role,  created_at
 *   reviewed_by_name, reviewed_by_role, reviewed_at
 *   approved_by_name, approved_by_role, approved_at
 *
 * Self-contained: all CSS is prefixed wf-audit- to avoid collisions.
 */

if (!isset($wf) || !is_array($wf)) $wf = [];

$_wf_fmt = function ($dt) {
    return $dt ? date('d M Y, h:i A', strtotime($dt)) : '';
};

$_wf_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
?>
<style>
    .wf-audit-panel {
        display: flex; gap: 0; flex-wrap: wrap;
        background: #f8f9fa;
        border-left: 4px solid #3498db;
        border-radius: 6px;
        padding: 0;
        margin: 0 0 20px 0;
        overflow: hidden;
        font-size: 12.5px;
        color: #1a252f;
    }
    .wf-audit-panel .wf-cell {
        flex: 1; min-width: 160px;
        padding: 12px 16px;
        border-right: 1px solid #dee2e6;
    }
    .wf-audit-panel .wf-cell:last-child { border-right: none; }
    .wf-audit-panel .wf-label {
        font-size: 10.5px; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.4px; color: #6c757d; margin-bottom: 4px;
    }
    .wf-audit-panel .wf-name  { font-weight: 600; color: #1a252f; }
    .wf-audit-panel .wf-role  { font-size: 11px; color: #495057; }
    .wf-audit-panel .wf-when  { font-size: 10.5px; color: #6c757d; margin-top: 2px; }
    .wf-audit-panel .wf-empty { font-style: italic; color: #adb5bd; font-size: 11px; }
    .wf-audit-panel .wf-icon  { font-size: 14px; margin-right: 4px; }
</style>

<div class="wf-audit-panel">
    <div class="wf-cell">
        <div class="wf-label"><i class="bi bi-pencil-square wf-icon"></i><?= $_wf_sw ? 'Iliundwa na' : 'Created By' ?></div>
        <?php if (!empty($wf['created_by_name'])): ?>
            <div class="wf-name"><?= htmlspecialchars($wf['created_by_name']) ?></div>
            <?php if (!empty($wf['created_by_role'])): ?>
                <div class="wf-role"><?= htmlspecialchars($wf['created_by_role']) ?></div>
            <?php endif; ?>
            <?php if (!empty($wf['created_at'])): ?>
                <div class="wf-when"><?= $_wf_fmt($wf['created_at']) ?></div>
            <?php endif; ?>
        <?php else: ?>
            <div class="wf-empty">—</div>
        <?php endif; ?>
    </div>

    <div class="wf-cell">
        <div class="wf-label"><i class="bi bi-clipboard-check wf-icon text-info"></i><?= $_wf_sw ? 'Ilipitiwa na' : 'Reviewed By' ?></div>
        <?php if (!empty($wf['reviewed_by_name'])): ?>
            <div class="wf-name text-info"><?= htmlspecialchars($wf['reviewed_by_name']) ?></div>
            <?php if (!empty($wf['reviewed_by_role'])): ?>
                <div class="wf-role"><?= htmlspecialchars($wf['reviewed_by_role']) ?></div>
            <?php endif; ?>
            <?php if (!empty($wf['reviewed_at'])): ?>
                <div class="wf-when"><?= $_wf_fmt($wf['reviewed_at']) ?></div>
            <?php endif; ?>
        <?php else: ?>
            <div class="wf-empty"><?= $_wf_sw ? 'Inasubiri uhakiki' : 'Pending review' ?></div>
        <?php endif; ?>
    </div>

    <div class="wf-cell">
        <div class="wf-label"><i class="bi bi-check2-circle wf-icon text-success"></i><?= $_wf_sw ? 'Ilipitishwa na' : 'Approved By' ?></div>
        <?php if (!empty($wf['approved_by_name'])): ?>
            <div class="wf-name text-success"><?= htmlspecialchars($wf['approved_by_name']) ?></div>
            <?php if (!empty($wf['approved_by_role'])): ?>
                <div class="wf-role"><?= htmlspecialchars($wf['approved_by_role']) ?></div>
            <?php endif; ?>
            <?php if (!empty($wf['approved_at'])): ?>
                <div class="wf-when"><?= $_wf_fmt($wf['approved_at']) ?></div>
            <?php endif; ?>
        <?php else: ?>
            <div class="wf-empty"><?= $_wf_sw ? 'Inasubiri idhini' : 'Pending approval' ?></div>
        <?php endif; ?>
    </div>
</div>
