<?php
/**
 * includes/entrance_fee_import.php — pure helpers for loading each member's own
 * entrance fee (customers.entrance_fee) from a leader's list. No DB, unit-tested.
 *
 * Used by bin/import_entrance_fees.php:
 *   match — the leader's list (name, M-Koba account name, fee, note) against the
 *           group's member list, by name: exact, then same words, then close spelling.
 *           Close spellings and clashes are never applied; a person confirms them.
 *   apply — the prepared file (source_ref, fee, join date) into the database.
 */

if (!function_exists('efi_norm')) {
    /**
     * A name for comparing: upper case, no phone numbers or "(old No: ...)" notes,
     * no punctuation, single spaces. "Gines G.Kweka" => "GINES G KWEKA".
     */
    function efi_norm(string $name): string
    {
        $s = mb_strtoupper($name, 'UTF-8');
        $s = preg_replace('/OLD\s*NO\s*:?/u', ' ', $s);
        $s = preg_replace('/\+?\d[\d\s]{5,}\d/u', ' ', $s); // phone numbers
        $s = preg_replace('/[^\p{L}\s]+/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }
}

if (!function_exists('efi_tokens')) {
    /** The words of a name, sorted, initials (one letter) left out. */
    function efi_tokens(string $name): array
    {
        $t = array_values(array_filter(explode(' ', efi_norm($name)), static fn($w) => mb_strlen($w) > 1));
        sort($t);
        return array_values(array_unique($t));
    }
}

if (!function_exists('efi_similarity')) {
    /** 0..1: how alike two names are, word order ignored. */
    function efi_similarity(string $a, string $b): float
    {
        $x = implode(' ', efi_tokens($a));
        $y = implode(' ', efi_tokens($b));
        if ($x === '' || $y === '') {
            return 0.0;
        }
        $max = max(strlen($x), strlen($y));
        return 1.0 - levenshtein($x, $y) / $max;
    }
}

if (!function_exists('efi_phones')) {
    /** Phone numbers written into a name, as their last 9 digits: "X - 0740121044" => ['740121044']. */
    function efi_phones(string $text): array
    {
        preg_match_all('/\d[\d\s]{7,}\d/', $text, $m);
        $out = [];
        foreach ($m[0] as $p) {
            $d = preg_replace('/\D/', '', $p);
            if (strlen($d) >= 9) {
                $out[] = substr($d, -9);
            }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('efi_join_date')) {
    /** "New member as of 11/10/2026" => "2026-10-11"; anything else => null. */
    function efi_join_date(string $note): ?string
    {
        if (!preg_match('/(\d{1,2})\s*\/\s*(\d{1,2})\s*\/\s*(\d{4})/', $note, $m)) {
            return null;
        }
        [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }
}

if (!function_exists('efi_fee')) {
    /** "45,000" / "45000" / 45000.0 => 45000.0; blank or not a number => null. */
    function efi_fee($raw): ?float
    {
        $s = trim(str_replace([',', ' '], '', (string) $raw));
        if ($s === '' || !is_numeric($s) || (float) $s < 0) {
            return null;
        }
        return round((float) $s, 2);
    }
}

if (!function_exists('efi_match')) {
    /**
     * Match the leader's list to the members.
     *
     * @param array $rows    each ['no', 'name', 'mkoba_name', 'fee', 'note']
     * @param array $members each ['ref', 'names' => string[], 'phones' => string[]] — a member's
     *                       full name, first + last, any M-Koba name on record, and phones
     * @return array one result per row, in order:
     *   row + ['status' => 'matched'|'check'|'none', 'ref' => ?string, 'how' => string,
     *          'member' => ?string (the member's first name in $members), 'join_date' => ?string]
     *   matched — sure: a phone written in the row, the same name, or the same words
     *   check   — a member whose record holds all the row's words (records that name two
     *             people), a close spelling, or more than one row/member fits: a person decides
     *   none    — no member found
     */
    function efi_match(array $rows, array $members): array
    {
        // Indexes: exact normalised name, and the sorted words.
        $byName = [];
        $byWords = [];
        $byPhone = [];
        foreach ($members as $m) {
            foreach ($m['phones'] ?? [] as $ph) {
                $d = preg_replace('/\D/', '', (string) $ph);
                if (strlen($d) >= 9) {
                    $byPhone[substr($d, -9)][$m['ref']] = true;
                }
            }
            foreach ($m['names'] as $n) {
                if (efi_norm($n) === '') {
                    continue;
                }
                $byName[efi_norm($n)][$m['ref']] = true;
                $byWords[implode(' ', efi_tokens($n))][$m['ref']] = true;
            }
        }
        $label = [];
        foreach ($members as $m) {
            $label[$m['ref']] = $m['names'][0] ?? '';
        }

        $out = [];
        foreach ($rows as $r) {
            $res = $r + ['status' => 'none', 'ref' => null, 'how' => '', 'member' => null,
                         'join_date' => efi_join_date((string) ($r['note'] ?? ''))];
            $names = array_values(array_filter([(string) ($r['name'] ?? ''), (string) ($r['mkoba_name'] ?? '')], static fn($n) => efi_norm($n) !== ''));

            // A phone written into the row settles two members with the same name. Only
            // one next to the real name is sure: an M-Koba account (and its phone) is
            // often someone else's, so a phone found there is only a suggestion.
            foreach (['name' => 'matched', 'mkoba_name' => 'check'] as $col => $status) {
                $phoneHits = [];
                foreach (efi_phones((string) ($r[$col] ?? '')) as $ph) {
                    $phoneHits += $byPhone[$ph] ?? [];
                }
                if (count($phoneHits) === 1) {
                    $res['status'] = $status;
                    $res['ref'] = (string) array_key_first($phoneHits);
                    $res['how'] = $col === 'name' ? 'phone' : 'phone (M-Koba name)';
                    break;
                }
            }

            foreach ($res['status'] !== 'none' ? [] : [
                ['exact', static fn($n) => $byName[efi_norm($n)] ?? []],
                ['same words', static fn($n) => $byWords[implode(' ', efi_tokens($n))] ?? []],
            ] as [$how, $find]) {
                $hits = [];
                foreach ($names as $i => $n) {
                    foreach (array_keys($find($n)) as $ref) {
                        // The real name first: an M-Koba account can be someone else's.
                        $hits[$ref] = min($hits[$ref] ?? 9, $i);
                    }
                }
                if (!$hits) {
                    continue;
                }
                $best = min($hits);
                $refs = array_keys(array_filter($hits, static fn($i) => $i === $best));
                if (count($refs) === 1) {
                    // Found only through the M-Koba name: a suggestion, not sure.
                    $res['status'] = $best === 0 ? 'matched' : 'check';
                    $res['ref'] = (string) $refs[0];
                    $res['how'] = $how . ($best === 1 ? ' (M-Koba name)' : '');
                } else {
                    $res['status'] = 'check';
                    $res['how'] = 'more than one member has this name';
                }
                break;
            }

            if ($res['status'] === 'none' && $names) {
                // A member record holding every word of the name ("Ally Mzungu/Abdul
                // Bakari Seif" holds "Abdul Bakari Seif"): suggest it when only one does.
                $holds = [];
                foreach ($names as $i => $n) {
                    $words = efi_tokens($n);
                    if (count($words) < 2) {
                        continue;
                    }
                    foreach ($members as $m) {
                        foreach ($m['names'] as $mn) {
                            if (!array_diff($words, efi_tokens($mn))) {
                                $holds[$m['ref']] = min($holds[$m['ref']] ?? 9, $i);
                            }
                        }
                    }
                    if ($holds) {
                        break; // the real name first; the M-Koba name only if it finds nothing
                    }
                }
                if (count($holds) === 1) {
                    $res['status'] = 'check';
                    $res['ref'] = (string) array_key_first($holds);
                    $res['how'] = 'the member record holds this name' . (reset($holds) === 1 ? ' (M-Koba name)' : '');
                }
            }

            if ($res['status'] === 'none' && $names) {
                // Close spelling: suggest the single best member, never apply it unasked.
                $scores = [];
                foreach ($members as $m) {
                    $s = 0.0;
                    foreach ($m['names'] as $mn) {
                        $s = max($s, efi_similarity($names[0], $mn));
                    }
                    $scores[$m['ref']] = $s;
                }
                arsort($scores);
                $top = array_slice($scores, 0, 2, true);
                $refs = array_keys($top);
                $first = $top[$refs[0]] ?? 0.0;
                $second = isset($refs[1]) ? $top[$refs[1]] : 0.0;
                if ($first >= 0.75 && $first - $second >= 0.05) {
                    $res['status'] = 'check';
                    $res['ref'] = (string) $refs[0];
                    $res['how'] = sprintf('close spelling (%d%%)', (int) round($first * 100));
                }
            }
            if ($res['ref'] !== null) {
                $res['member'] = $label[$res['ref']] ?? null;
            }
            $out[] = $res;
        }

        // A member claimed by two rows: neither is sure.
        $claims = [];
        foreach ($out as $i => $r) {
            if ($r['ref'] !== null) {
                $claims[$r['ref']][] = $i;
            }
        }
        foreach ($claims as $ref => $idx) {
            if (count($idx) > 1) {
                foreach ($idx as $i) {
                    $out[$i]['status'] = 'check';
                    $out[$i]['how'] = 'two rows point to the same member';
                }
            }
        }
        return $out;
    }
}
