<?php
/**
 * includes/api_group_settings.php — one definition of the group's editable
 * configuration, shared by GET and PUT.
 *
 * GET used to publish seven keys under hand-written names (contributions
 * .monthly_target) while PUT accepted eighteen under their raw storage names
 * (monthly_contribution). An edit form could therefore not pre-fill itself, and
 * any client that tried had to carry a private GET-name -> PUT-name mapping —
 * the sort of table that is right the day it is written and wrong at the next
 * schema change.
 *
 * So the field list lives HERE, and both endpoints derive from it. Parity is
 * structural rather than a promise: a key added for writing is readable in the
 * same commit, under the same name, because there is only one list.
 *
 * The rule vocabulary, and what each means on the way OUT (GET) and IN (PUT):
 *
 *   'text'   string        -> string        trimmed; '' is a real cleared value
 *   'money'  numeric|''    -> float|null    '' means NOT SET, never 0
 *   'int'    integer|''    -> int|null      '' means NOT SET, never 0
 *   'day'    1-31 | a day  -> int|string    see vk_group_settings_day_names()
 *   'enum:'  fixed set     -> string        default applied when unset
 *
 * WHY 'money' AND 'int' KEEP null. Clearing monthly_contribution means "this
 * group has no monthly target", which switches the arrears calculation off
 * entirely (includes/contribution_standing.php). Zero would mean "the target is
 * nothing", and every member would be permanently in credit. The two states are
 * not interchangeable and the wire format must keep them apart.
 */

if (!function_exists('vk_group_settings_day_names')) {
    /**
     * The seven day values the web form actually stores, in order.
     *
     * app/bms/customer/group_settings.php writes SWAHILI day names regardless of
     * the user's display language — the <option value> is $d_sw and only the
     * label is translated. Anything reading meeting_day or a weekly
     * deadline_day is therefore comparing against these strings, so these are
     * the canonical stored values and English is accepted only as an alias.
     *
     * @return array<string,string> lowercased English alias => canonical Swahili
     */
    function vk_group_settings_day_names(): array
    {
        return [
            'monday'    => 'Jumatatu',
            'tuesday'   => 'Jumanne',
            'wednesday' => 'Jumatano',
            'thursday'  => 'Alhamisi',
            'friday'    => 'Ijumaa',
            'saturday'  => 'Jumamosi',
            'sunday'    => 'Jumapili',
        ];
    }
}

if (!function_exists('vk_group_settings_normalise_day')) {
    /**
     * A day value in whatever spelling -> the canonical Swahili name, or '' if
     * it is not a day at all.
     *
     * Accepting English is deliberate: a client sending 'Monday' would otherwise
     * store a value no other screen recognises, and the failure would surface
     * weeks later as a meeting day that never matches.
     */
    function vk_group_settings_normalise_day(string $value): string
    {
        $needle = strtolower(trim($value));
        if ($needle === '') {
            return '';
        }
        $names = vk_group_settings_day_names();
        if (isset($names[$needle])) {
            return $names[$needle];
        }
        foreach ($names as $swahili) {
            if (strtolower($swahili) === $needle) {
                return $swahili;
            }
        }
        return '';
    }
}

if (!function_exists('vk_group_settings_writable')) {
    /**
     * Every setting the API may read back and write, and how each is validated.
     *
     * Deliberately narrower than actions/save_group_settings.php: the loan and
     * share-out parameters stay on the web until a screen asks for them, because
     * a value nobody can see on the device is a value nobody can check before
     * saving.
     *
     * Just as deliberately, operational state kept in the same free-form table —
     * auto_termination_last_run, the cached group_balance — appears nowhere, so
     * it can be neither read nor written through the API.
     *
     * @return array<string,string> setting key => rule
     */
    function vk_group_settings_writable(): array
    {
        return [
            'group_name'                => 'text',
            'group_email'               => 'text',
            'group_phone'               => 'text',
            'group_physical_address'    => 'text',
            'group_postal_address'      => 'text',
            'group_registration_number' => 'text',
            'currency'                  => 'text',
            'meeting_day'               => 'day',
            'cycle_type'                => 'enum:monthly,weekly',
            'monthly_contribution'      => 'money',
            'entrance_fee'              => 'money',
            'meeting_absence_fine'      => 'money',
            'fine_late_meeting'         => 'money',
            'fine_late_contribution'    => 'money',
            'fine_absent_meeting'       => 'money',
            'max_members'               => 'int',
            'contribution_grace_days'   => 'int',
            'deadline_day'              => 'day',
            'auto_termination'          => 'enum:on,off',
        ];
    }
}

if (!function_exists('vk_group_settings_defaults')) {
    /**
     * What the web form shows when a key has never been saved.
     *
     * Mirrored from the gs($settings_raw, $key, $default) calls in
     * app/bms/customer/group_settings.php so the app and the web page open on
     * the same numbers. A key absent from here defaults to ''.
     *
     * @return array<string,string>
     */
    function vk_group_settings_defaults(): array
    {
        return [
            'currency'         => 'TZS',
            'cycle_type'       => 'monthly',
            'max_members'      => '30',
            'deadline_day'     => '15',
            'auto_termination' => 'off',
        ];
    }
}

if (!function_exists('vk_group_settings_cast')) {
    /**
     * A stored string -> the JSON value GET should publish for it.
     *
     * @return float|int|string|null
     */
    function vk_group_settings_cast(string $rule, string $stored)
    {
        if (str_starts_with($rule, 'enum:')) {
            return $stored;
        }

        if ($rule === 'money' || $rule === 'int') {
            if ($stored === '' || !is_numeric($stored)) {
                return null;
            }
            return $rule === 'int' ? (int) $stored : (float) $stored;
        }

        if ($rule === 'day') {
            if ($stored === '') {
                return null;
            }
            // A monthly cycle stores 1-31; a weekly cycle stores a day NAME.
            // Both live in the same key, so the type is whichever was written.
            return is_numeric($stored) ? (int) $stored : $stored;
        }

        return $stored;
    }
}

if (!function_exists('vk_group_settings_validate')) {
    /**
     * A submitted value -> [normalised string to store, error message or null].
     *
     * Returns the value as a STRING because group_settings.setting_value is a
     * text column; the typing is a wire concern, not a storage one.
     *
     * @return array{0:string,1:?string}
     */
    function vk_group_settings_validate(string $key, string $rule, string $value): array
    {
        if (str_starts_with($rule, 'enum:')) {
            $allowed = explode(',', substr($rule, 5));
            if (!in_array($value, $allowed, true)) {
                return ['', "{$key} must be one of: " . implode(', ', $allowed) . '.'];
            }
            return [$value, null];
        }

        if ($rule === 'money' || $rule === 'int') {
            if ($value === '') {
                return ['', null]; // "not set" — preserved, never coerced to 0
            }
            if (!is_numeric($value) || (float) $value < 0) {
                return ['', "{$key} must be a number of 0 or more."];
            }
            return [$rule === 'int' ? (string) (int) $value : (string) (float) $value, null];
        }

        if ($rule === 'day') {
            if ($value === '') {
                return ['', null];
            }
            if (is_numeric($value)) {
                $day = (int) $value;
                if ((string) $day !== ltrim($value, '+') || $day < 1 || $day > 31) {
                    return ['', "{$key} must be a day of the month from 1 to 31, or a day name."];
                }
                return [(string) $day, null];
            }
            // NOT coerced with (int): a weekly group stores 'Jumatatu' here, and
            // (int) 'Jumatatu' is 0 — a silent corruption that a pre-filled edit
            // form would commit on every save without changing the field.
            $named = vk_group_settings_normalise_day($value);
            if ($named === '') {
                return ['', "{$key} must be a day of the month from 1 to 31, or one of: "
                    . implode(', ', array_values(vk_group_settings_day_names())) . '.'];
            }
            return [$named, null];
        }

        return [$value, null];
    }
}

if (!function_exists('vk_group_settings_may_edit')) {
    /**
     * Who may change the group's configuration: admins — which includes the
     * Chairperson, see includes/roles.php — plus the Secretary.
     *
     * The same rule app/bms/customer/group_settings.php gates its form on, held
     * here so the two transports cannot drift apart about it.
     */
    function vk_group_settings_may_edit(array $auth): bool
    {
        require_once __DIR__ . '/roles.php';

        $role = strtolower(trim((string) ($auth['user']['user_role'] ?? '')));
        if (in_array($role, ['secretary', 'katibu'], true)) {
            return true;
        }
        return vk_role_is_admin($auth['role_id'] ?? null, $auth['user']['user_role'] ?? null);
    }
}
