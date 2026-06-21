<?php
// includes/registration_validator.php
//
// Pure, DB-free, output-free validation for the public registration form.
// Returns a list of human-readable error messages (empty array = valid).
//
// This is the AUTHORITATIVE gate: it mirrors the client-side format rules in
// register.php so that even when JavaScript is bypassed, malformed data is
// rejected with a SPECIFIC message naming exactly what is wrong — never a
// vague failure and never a silent bad insert.

if (!function_exists('reg_valid_email')) {
    function reg_valid_email(string $v): bool {
        return filter_var(trim($v), FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('reg_valid_phone')) {
    // Accepts Tanzanian national (07.../06...) and international (+255...),
    // 9–13 digits after stripping spaces, dashes and parentheses.
    function reg_valid_phone(string $v): bool {
        $digits = preg_replace('/[\s\-()]/', '', trim($v));
        return (bool) preg_match('/^\+?\d{9,13}$/', $digits);
    }
}

if (!function_exists('reg_normalize_phone')) {
    // Canonical form so the SAME person typed two ways (0712… vs +255712…) is
    // detected as a duplicate and stored consistently. Tanzanian 0-prefixed
    // numbers become +255…, bare 255… gets a leading +, +… is kept.
    function reg_normalize_phone(string $v): string {
        $digits = preg_replace('/[\s\-()]/', '', trim($v));
        if ($digits === '') return '';
        if (strpos($digits, '+') === 0) return $digits;
        if (strpos($digits, '0') === 0 && strlen($digits) === 10) return '+255' . substr($digits, 1);
        if (strpos($digits, '255') === 0) return '+' . $digits;
        return $digits;
    }
}

if (!function_exists('reg_valid_name')) {
    // Letters (any script), spaces, hyphen, apostrophe and dot. 2–50 chars.
    // Rejects names made of digits/symbols (which also feed username generation).
    function reg_valid_name(string $v): bool {
        $v = trim($v);
        return (bool) preg_match("/^[\\p{L}][\\p{L}\\s.'\\-]{1,49}$/u", $v);
    }
}

if (!function_exists('reg_valid_nida')) {
    // Tanzanian National ID (NIN) is 20 digits; separators are tolerated.
    function reg_valid_nida(string $v): bool {
        $digits = preg_replace('/\D/', '', trim($v));
        return strlen($digits) === 20;
    }
}

if (!function_exists('reg_file_ext')) {
    function reg_file_ext(string $name): string {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }
}

if (!function_exists('reg_file_mime')) {
    // Real content type of an uploaded temp file. Returns null when the file is
    // not readable (e.g. during unit tests that pass placeholder paths), so the
    // content check only ever runs on a genuine upload.
    function reg_file_mime(string $tmp): ?string {
        if ($tmp === '' || !@is_readable($tmp)) return null;
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $m = finfo_file($f, $tmp);
                finfo_close($f);
                if (is_string($m) && $m !== '') return $m;
            }
        }
        $info = @getimagesize($tmp);
        return $info['mime'] ?? null;
    }
}

if (!function_exists('validate_registration_input')) {
    /**
     * Validate posted registration data.
     *
     * @param array  $post         Posted text fields (e.g. $_POST)
     * @param array  $files        Uploaded files (e.g. $_FILES)
     * @param string $lang         'en' or 'sw'
     * @param bool   $requireTerms    Require a terms-acceptance value. True for
     *                                the public self-registration form; false for
     *                                the admin "Register New Member" form.
     * @param bool   $requireSlip     Require the payment slip upload. False for the
     *                                profile edit form (which has no slip field).
     * @param bool   $requirePassword Require a password. False for the profile edit
     *                                form (password is changed separately).
     *                                When any of these are false, the corresponding
     *                                "required" check is skipped, but every FORMAT
     *                                rule for the entered parts stays identical.
     * @return string[]               List of error messages; empty when valid.
     */
    function validate_registration_input(array $post, array $files, string $lang = 'en', bool $requireTerms = true, bool $requireSlip = true, bool $requirePassword = true): array {
        $sw = ($lang === 'sw');
        $errors = [];

        $first_name = trim($post['first_name'] ?? '');
        $last_name  = trim($post['last_name'] ?? '');
        $email      = trim($post['email'] ?? '');
        $phone      = trim($post['phone'] ?? '');
        $password   = (string) ($post['password'] ?? '');
        // confirm_password is only checked when the form actually submitted it.
        $confirm    = array_key_exists('confirm_password', $post) ? (string) $post['confirm_password'] : null;

        // --- Required fields (specific messages) ---
        if ($first_name === '') {
            $errors[] = $sw ? 'Jina la kwanza linahitajika.' : 'First name is required.';
        } elseif (!reg_valid_name($first_name)) {
            $errors[] = $sw
                ? 'Jina la kwanza lina herufi zisizoruhusiwa (tumia herufi pekee, mfano John).'
                : 'First name contains invalid characters (use letters only, e.g. John).';
        }
        if ($last_name === '') {
            $errors[] = $sw ? 'Jina la mwisho linahitajika.' : 'Last name is required.';
        } elseif (!reg_valid_name($last_name)) {
            $errors[] = $sw
                ? 'Jina la mwisho lina herufi zisizoruhusiwa (tumia herufi pekee, mfano Doe).'
                : 'Last name contains invalid characters (use letters only, e.g. Doe).';
        }

        if ($email === '') {
            $errors[] = $sw ? 'Barua pepe inahitajika.' : 'Email address is required.';
        } elseif (!reg_valid_email($email)) {
            $errors[] = $sw
                ? 'Barua pepe si sahihi, mfano john@example.com'
                : 'Please enter a valid email address, e.g. john@example.com';
        }

        if ($phone === '') {
            $errors[] = $sw ? 'Namba ya simu inahitajika.' : 'Phone number is required.';
        } elseif (!reg_valid_phone($phone)) {
            $errors[] = $sw
                ? 'Namba ya simu si sahihi, mfano 0712345678 au +255712345678'
                : 'Please enter a valid phone number, e.g. 0712345678 or +255712345678';
        }

        if ($requirePassword && $password === '') {
            $errors[] = $sw ? 'Nywila inahitajika.' : 'Password is required.';
        }
        if ($confirm !== null && $password !== '' && $password !== $confirm) {
            $errors[] = $sw ? 'Nywila hazifanani.' : 'Passwords do not match.';
        }

        // --- NIDA (member): optional, but must be 20 digits when provided ---
        $nida = trim($post['nida_number'] ?? '');
        if ($nida !== '' && !reg_valid_nida($nida)) {
            $errors[] = $sw
                ? 'Namba ya NIDA lazima iwe na tarakimu 20.'
                : 'The NIDA number must be 20 digits.';
        }

        // --- Optional spouse email: validate only when provided ---
        $spouse_email = trim($post['spouse_email'] ?? '');
        if ($spouse_email !== '' && !reg_valid_email($spouse_email)) {
            $errors[] = $sw ? 'Barua pepe ya mwenzi si sahihi.' : 'The spouse email address is not valid.';
        }

        // --- Optional spouse NIDA: validate only when provided ---
        $spouse_nida = trim($post['spouse_nida'] ?? '');
        if ($spouse_nida !== '' && !reg_valid_nida($spouse_nida)) {
            $errors[] = $sw
                ? 'Namba ya NIDA ya mwenzi lazima iwe na tarakimu 20.'
                : 'The spouse NIDA number must be 20 digits.';
        }

        // --- Optional relative phones: validate only when provided ---
        $phoneLabels = $sw
            ? [
                'father_phone'    => 'Namba ya simu ya baba',
                'mother_phone'    => 'Namba ya simu ya mama',
                'spouse_phone'    => 'Namba ya simu ya mwenzi',
                'guarantor_phone' => 'Namba ya simu ya mdhamini',
            ]
            : [
                'father_phone'    => "Father's phone number",
                'mother_phone'    => "Mother's phone number",
                'spouse_phone'    => "Spouse phone number",
                'guarantor_phone' => "Guarantor's phone number",
            ];
        foreach ($phoneLabels as $key => $label) {
            $val = trim($post[$key] ?? '');
            if ($val !== '' && !reg_valid_phone($val)) {
                $errors[] = $sw ? "$label si sahihi." : "$label is not valid.";
            }
        }

        // --- Registration / entrance fee: optional, but must be a positive number ---
        $fee = trim((string) ($post['entrance_fee'] ?? ''));
        if ($fee !== '' && (!is_numeric($fee) || (float) $fee < 0)) {
            $errors[] = $sw
                ? 'Ada ya usajili lazima iwe namba chanya.'
                : 'The registration fee must be a positive number.';
        }

        // --- Children ages: optional rows, each must be 0–120 when provided ---
        $ages = $post['child_age'] ?? [];
        if (is_array($ages)) {
            foreach ($ages as $i => $a) {
                $a = trim((string) $a);
                if ($a === '') continue;
                if (!ctype_digit($a) || (int) $a > 120) {
                    $n = ((int) $i) + 1;
                    $errors[] = $sw
                        ? "Umri wa mtoto wa $n si sahihi (tumia namba 0–120)."
                        : "Child #$n age is not valid (use a number 0–120).";
                }
            }
        }

        // --- Terms & conditions must be accepted (public form only) ---
        if ($requireTerms) {
            $terms = $post['terms'] ?? '';
            $termsOk = ($terms === '1' || $terms === 1 || $terms === true
                || strtolower((string) $terms) === 'on' || strtolower((string) $terms) === 'true');
            if (!$termsOk) {
                $errors[] = $sw
                    ? 'Lazima ukubali masharti na kanuni ili kujisajili.'
                    : 'You must accept the terms and conditions to register.';
            }
        }

        // --- Payment slip (JPG/PNG/PDF; required only when $requireSlip) ---
        $slip = $files['kianzio_slip'] ?? null;
        if (!is_array($slip) || !isset($slip['error']) || $slip['error'] === UPLOAD_ERR_NO_FILE) {
            if ($requireSlip) {
                $errors[] = $sw ? 'Tafadhali pakia risiti ya malipo.' : 'Please upload your payment slip.';
            }
        } elseif ($slip['error'] === UPLOAD_ERR_INI_SIZE || $slip['error'] === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = $sw ? 'Faili la risiti ya malipo ni kubwa mno.' : 'The payment slip file is too large.';
        } elseif ($slip['error'] !== UPLOAD_ERR_OK) {
            $errors[] = $sw
                ? 'Imeshindikana kupakia risiti ya malipo. Tafadhali jaribu tena.'
                : 'The payment slip failed to upload. Please try again.';
        } else {
            $ext = reg_file_ext($slip['name'] ?? '');
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true)) {
                $errors[] = $sw
                    ? 'Risiti ya malipo lazima iwe faili la JPG, PNG, au PDF.'
                    : 'The payment slip must be a JPG, PNG, or PDF file.';
            } else {
                // Verify the REAL content, not just the file name's extension.
                $mime = reg_file_mime($slip['tmp_name'] ?? '');
                if ($mime !== null && !in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
                    $errors[] = $sw
                        ? 'Risiti ya malipo si faili halali la JPG, PNG, au PDF.'
                        : 'The payment slip is not a real JPG, PNG, or PDF file.';
                }
            }
        }

        // --- Passport photo (optional: JPG/PNG, max 2MB) ---
        $photo = $files['passport_photo'] ?? null;
        if (is_array($photo) && isset($photo['error']) && $photo['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($photo['error'] === UPLOAD_ERR_INI_SIZE || $photo['error'] === UPLOAD_ERR_FORM_SIZE) {
                $errors[] = $sw ? 'Picha ya pasipoti lazima iwe chini ya 2MB.' : 'The passport photo must be smaller than 2MB.';
            } elseif ($photo['error'] !== UPLOAD_ERR_OK) {
                $errors[] = $sw
                    ? 'Imeshindikana kupakia picha ya pasipoti. Tafadhali jaribu tena.'
                    : 'The passport photo failed to upload. Please try again.';
            } else {
                $ext = reg_file_ext($photo['name'] ?? '');
                if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                    $errors[] = $sw ? 'Picha ya pasipoti lazima iwe JPG au PNG.' : 'The passport photo must be a JPG or PNG image.';
                } elseif (isset($photo['size']) && $photo['size'] > 2 * 1024 * 1024) {
                    $errors[] = $sw ? 'Picha ya pasipoti lazima iwe chini ya 2MB.' : 'The passport photo must be smaller than 2MB.';
                } else {
                    // Verify the REAL image content, not just the extension.
                    $mime = reg_file_mime($photo['tmp_name'] ?? '');
                    if ($mime !== null && !in_array($mime, ['image/jpeg', 'image/png'], true)) {
                        $errors[] = $sw
                            ? 'Picha ya pasipoti si picha halali ya JPG au PNG.'
                            : 'The passport photo is not a real JPG or PNG image.';
                    }
                }
            }
        }

        return $errors;
    }
}
