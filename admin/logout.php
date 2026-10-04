<?php
// admin/logout.php
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
$_SESSION = [];
session_unset();
session_destroy();
header("Location: login.php");
exit;
?>