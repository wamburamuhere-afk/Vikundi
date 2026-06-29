<?php
// includes/expense_helpers.php
//
// Small pure helpers for the expenses module. Kept DB-free so they can be unit
// tested without a database.

if (!function_exists('vk_expense_member_id')) {
    /**
     * Normalise a raw "charge to member" input into a member id or null.
     * Empty string, '0', non-numeric, and non-positive values all mean
     * "whole-organization expense" -> null. Anything else -> positive int.
     *
     * @param mixed $raw value from the request (string|int|null)
     */
    function vk_expense_member_id($raw): ?int {
        if ($raw === null) {
            return null;
        }
        $s = trim((string) $raw);
        if ($s === '' || !ctype_digit($s)) {
            return null;
        }
        $id = (int) $s;
        return $id > 0 ? $id : null;
    }
}
