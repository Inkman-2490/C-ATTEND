<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/portal_sessions.php';

ensure_maintenance_tables($pdo);
expire_attendance_sessions($pdo);
ensure_portal_only_session_tables($pdo);
run_daily_geofence_reset($pdo);

echo 'Daily geofence reset and portal session cleanup completed.' . PHP_EOL;
