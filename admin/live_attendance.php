<?php
// admin/live_attendance.php
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
require_once __DIR__ . '/../includes/bootstrap.php';
ensure_attendance_approval_columns($pdo);

header('Content-Type: application/json; charset=utf-8');

ensure_maintenance_tables($pdo);
if (is_maintenance_mode($pdo) && !isset($_SESSION['maintenance_user_id'])) {
    http_response_code(503);
    echo json_encode(['error' => 'Maintenance mode is enabled']);
    exit;
}

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

expire_attendance_sessions($pdo);

$stmt = $pdo->query("
    SELECT s.id, COUNT(r.id) AS total_attended
    FROM attendance_sessions s
    LEFT JOIN attendance_records r ON r.session_id = s.id AND r.approval_status = 'approved'
    WHERE s.is_active = 1
      AND s.end_time > NOW()
    GROUP BY s.id
");

$sessions = [];
foreach ($stmt->fetchAll() as $row) {
    $sessions[(string)$row['id']] = (int)$row['total_attended'];
}

echo json_encode([
    'sessions' => $sessions,
    'updated_at' => date(DATE_ATOM)
]);
