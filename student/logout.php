<?php
// logout.php
require_once __DIR__ . '/../includes/bootstrap.php';
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
ensure_student_auth_events_table($pdo);
if (isset($_SESSION['student_id'])) {
    record_student_auth_event($pdo, (int)$_SESSION['student_id'], 'logout');
}
$_SESSION = [];
session_unset();
session_destroy();
setcookie(session_name(), '', time() - 3600, '/');
header("Location: ../index.php");
exit;
?>