<?php
/** Sign out: POST only (the form in the top bar carries the token). */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/groups');
}
$_SESSION = [];
session_regenerate_id(true);
$_SESSION['flash'] = 'Signed out.';
redirect('/login');
