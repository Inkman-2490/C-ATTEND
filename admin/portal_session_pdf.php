<?php
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/portal_sessions.php';

ensure_admin_roles($pdo);
ensure_portal_only_session_tables($pdo);
finalize_and_expire_portal_only_sessions($pdo);
enforce_portal_maintenance($pdo, 'admin portal');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_stmt = $pdo->prepare('SELECT role FROM admins WHERE id = ?');
$admin_stmt->execute([(int)$_SESSION['admin_id']]);
$role = $admin_stmt->fetchColumn();
if (!in_array($role, ['admin', 'sub_admin'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$session_id = (int)($_GET['id'] ?? 0);
$params = [$session_id];
$scope = '';
if ($role === 'sub_admin') {
    $scope = ' AND created_by_admin_id = ?';
    $params[] = (int)$_SESSION['admin_id'];
}
$stmt = $pdo->prepare('SELECT course_code, pdf_data FROM portal_only_sessions WHERE id = ?' . $scope);
$stmt->execute($params);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session || $session['pdf_data'] === null) {
    http_response_code(404);
    exit('Saved session PDF was not found or has expired.');
}

$filename = 'c-attend-session-' . $session_id . '.pdf';
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($session['pdf_data']));
header('Content-Disposition: ' . (($_GET['download'] ?? '0') === '1' ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
header('Cache-Control: private, no-store, max-age=0');
echo $session['pdf_data'];
