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

if (!function_exists('reg_file_ext')) {
    function reg_file_ext(string $name): string {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }
}

if (!function_exists('validate_registration_input')) {
    /**
     * Validate posted registration data.
     *
     * @param array  $post  Posted text fields (e.g. $_POST)
     * @param array  $files Uploaded files (e.g. $_FILES)
     * @param string $lang  'en' or 'sw'
     * @return string[]     List of error messages; empty when the input is valid.
     */
    function validate_registration_input(array $post, array $files, string $lang = 'en'): array {
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
        }
        if ($last_name === '') {
            $errors[] = $sw ? 'Jina la mwisho linahitajika.' : 'Last name is required.';
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

        if ($password === '') {
            $errors[] = $sw ? 'Nywila inahitajika.' : 'Password is required.';
        }
        if ($confirm !== null && $password !== '' && $password !== $confirm) {
            $errors[] = $sw ? 'Nywila hazifanani.' : 'Passwords do not match.';
        }

        // --- Optional spouse email: validate only when provided ---
        $spouse_email = trim($post['spouse_email'] ?? '');
        if ($spouse_email !== '' && !reg_valid_email($spouse_email)) {
            $errors[] = $sw ? 'Barua pepe ya mwenzi si sahihi.' : 'The spouse email address is not valid.';
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

        // --- Payment slip (required: JPG/PNG/PDF) ---
        $slip = $files['kianzio_slip'] ?? null;
        if (!is_array($slip) || !isset($slip['error']) || $slip['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = $sw ? 'Tafadhali pakia risiti ya malipo.' : 'Please upload your payment slip.';
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
                }
            }
        }

        return $errors;
    }
}
