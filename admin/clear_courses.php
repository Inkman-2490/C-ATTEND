<?php
// admin/clear_courses.php
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
require_once __DIR__ . '/../includes/bootstrap.php';

ensure_maintenance_tables($pdo);
enforce_portal_maintenance($pdo, 'admin portal');

$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_clear_courses'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'admin_clear_courses')) {
        $_SESSION['flash_error'] = 'Security validation failed. Please try again.';
        header("Location: dashboard.php");
        exit;
    }

    $action_type = $_POST['clear_type'] ?? 'archive';

    try {
        $pdo->beginTransaction();

        if ($action_type === 'purge') {
            $pdo->exec("DELETE FROM attendance_records WHERE session_id IN (SELECT id FROM attendance_sessions)");
            $pdo->exec("DELETE FROM attendance_sessions");
            $pdo->exec("DELETE FROM courses");
            $_SESSION['flash_success'] = "Semester Reset Complete: All courses and attendance logs have been permanently deleted.";
        } elseif ($action_type === 'archive') {
            $pdo->exec("UPDATE attendance_sessions SET is_active = 0");
            $pdo->exec("UPDATE courses SET is_active = 0");
            $_SESSION['flash_success'] = "End of Semester Archival Complete: All courses and sessions marked as inactive.";
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash_error'] = "Failed to clear courses: " . $e->getMessage();
    }
}

header("Location: dashboard.php");
exit;