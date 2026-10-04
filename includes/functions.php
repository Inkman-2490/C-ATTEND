<?php
// includes/functions.php

function sanitize_input($data) {
    if ($data === null) {
        return '';
    }

    return trim(htmlspecialchars(stripslashes((string)$data), ENT_QUOTES, 'UTF-8'));
}

function ensure_portal_alert_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS portal_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
            source VARCHAR(40) NOT NULL DEFAULT 'portal',
            title VARCHAR(150) NOT NULL,
            message TEXT NOT NULL,
            details TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function ensure_class_columns(PDO $pdo): void {
    $student_column = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'class_name'")->fetchColumn();
    if (!$student_column) {
        $pdo->exec("ALTER TABLE students ADD COLUMN class_name ENUM('A','B') NOT NULL DEFAULT 'A' AFTER phone");
    }

    $session_column = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_sessions' AND COLUMN_NAME = 'target_class'")->fetchColumn();
    if (!$session_column) {
        $pdo->exec("ALTER TABLE attendance_sessions ADD COLUMN target_class ENUM('A','B','both') NOT NULL DEFAULT 'both' AFTER course_id");
    }
}

function ensure_privacy_acceptance_column(PDO $pdo): void {
    $column_exists = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'privacy_policy_accepted_at'")->fetchColumn();
    if (!$column_exists) {
        $pdo->exec("ALTER TABLE students ADD COLUMN privacy_policy_accepted_at TIMESTAMP NULL DEFAULT NULL AFTER password_hash");
    }

}

function ensure_attendance_approval_columns(PDO $pdo): void {
    $column_exists = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'approval_status'")->fetchColumn();
    if (!$column_exists) {
        $pdo->exec("ALTER TABLE attendance_records ADD COLUMN approval_status ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved' AFTER marked_by");
    }

    $column_exists = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'approved_by'")->fetchColumn();
    if (!$column_exists) {
        $pdo->exec("ALTER TABLE attendance_records ADD COLUMN approved_by INT NULL AFTER approval_status");
    }

    $column_exists = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'approved_at'")->fetchColumn();
    if (!$column_exists) {
        $pdo->exec("ALTER TABLE attendance_records ADD COLUMN approved_at TIMESTAMP NULL AFTER approved_by");
    }

    $column_exists = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'submitted_by_admin_id'")->fetchColumn();
    if (!$column_exists) {
        $pdo->exec("ALTER TABLE attendance_records ADD COLUMN submitted_by_admin_id INT NULL AFTER approved_at");
    }
}

function ensure_student_device_bindings_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS student_device_bindings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            device_token_hash CHAR(64) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_student_device_bindings_student (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function ensure_student_auth_events_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS student_auth_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            event_type ENUM('login','logout') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_student_auth_events_created_at (created_at),
            INDEX idx_student_auth_events_type_created (event_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function record_student_auth_event(PDO $pdo, int $student_id, string $event_type): void {
    if ($student_id < 1 || !in_array($event_type, ['login', 'logout'], true)) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO student_auth_events (student_id, event_type) VALUES (?, ?)');
    $stmt->execute([$student_id, $event_type]);
}

function bind_student_login_to_device(PDO $pdo, int $student_id): bool {
    if ($student_id < 1) {
        return false;
    }

    $cookie_name = 'c_attend_student_device';
    $cookie_value = $_COOKIE[$cookie_name] ?? '';
    $is_https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';

    if ($cookie_value !== '') {
        if (!preg_match('/^[a-f0-9]{64}$/', $cookie_value)) {
            return false;
        }

        $token_hash = hash('sha256', $cookie_value);
        $stmt = $pdo->prepare('SELECT student_id FROM student_device_bindings WHERE device_token_hash = ?');
        $stmt->execute([$token_hash]);
        $binding = $stmt->fetch();

        if (!$binding || (int)$binding['student_id'] !== $student_id) {
            return false;
        }

        $pdo->prepare('UPDATE student_device_bindings SET last_used_at = CURRENT_TIMESTAMP WHERE device_token_hash = ?')->execute([$token_hash]);
        return true;
    }

    $device_token = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $device_token);
    $stmt = $pdo->prepare('INSERT INTO student_device_bindings (student_id, device_token_hash) VALUES (?, ?)');
    $stmt->execute([$student_id, $token_hash]);
    setcookie($cookie_name, $device_token, [
        'expires' => time() + (86400 * 365 * 5),
        'path' => '/',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    return true;
}

function ensure_admin_roles(PDO $pdo): void {
    $role_column = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'role'")->fetchColumn();
    if (!$role_column) {
        $pdo->exec("ALTER TABLE admins ADD COLUMN role ENUM('admin','sub_admin') NOT NULL DEFAULT 'admin' AFTER password_hash");
    }

    $class_column = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'assigned_class'")->fetchColumn();
    if (!$class_column) {
        $pdo->exec("ALTER TABLE admins ADD COLUMN assigned_class ENUM('A','B') NULL DEFAULT NULL AFTER role");
    }
}

function ensure_maintenance_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS maintenance_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(80) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function get_default_geofence_radius(PDO $pdo): int {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'default_geofence_radius'");
    $stmt->execute();
    $radius = filter_var($stmt->fetchColumn(), FILTER_VALIDATE_INT);

    return $radius !== false && $radius >= 50 && $radius <= 2000 ? $radius : 300;
}

function parse_attendance_gps_reading(array $input): ?array {
    $latitude_input = $input['latitude'] ?? null;
    $longitude_input = $input['longitude'] ?? null;
    $accuracy_input = $input['accuracy'] ?? null;

    if (!is_scalar($latitude_input) || !is_scalar($longitude_input) || !is_scalar($accuracy_input)) {
        return null;
    }

    $latitude_input = trim((string)$latitude_input);
    $longitude_input = trim((string)$longitude_input);
    $accuracy_input = trim((string)$accuracy_input);
    if ($latitude_input === '' || $longitude_input === '' || $accuracy_input === ''
        || !is_numeric($latitude_input) || !is_numeric($longitude_input) || !is_numeric($accuracy_input)) {
        return null;
    }

    $latitude = (float)$latitude_input;
    $longitude = (float)$longitude_input;
    $accuracy = (float)$accuracy_input;
    if (!is_finite($latitude) || !is_finite($longitude) || !is_finite($accuracy)
        || $latitude < -90 || $latitude > 90
        || $longitude < -180 || $longitude > 180
        || $accuracy < 0) {
        return null;
    }

    return [
        'latitude' => $latitude,
        'longitude' => $longitude,
        'accuracy' => $accuracy
    ];
}

function calculate_gps_distance_meters(float $latitude_a, float $longitude_a, float $latitude_b, float $longitude_b): float {
    $latitude_delta = deg2rad($latitude_b - $latitude_a);
    $longitude_delta = deg2rad($longitude_b - $longitude_a);
    $haversine = sin($latitude_delta / 2) ** 2
        + cos(deg2rad($latitude_a)) * cos(deg2rad($latitude_b)) * sin($longitude_delta / 2) ** 2;
    $haversine = min(1.0, max(0.0, $haversine));

    return 6371000 * 2 * atan2(sqrt($haversine), sqrt(1 - $haversine));
}

function is_maintenance_mode(PDO $pdo): bool {
    try {
        $settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('maintenance_mode', 'maintenance_start', 'maintenance_end')");
        $settings = [];
        foreach ($settings_stmt->fetchAll() as $setting) {
            $settings[$setting['setting_key']] = $setting['setting_value'];
        }

        if (($settings['maintenance_mode'] ?? '0') !== '1') {
            return false;
        }

        $now = time();
        if (!empty($settings['maintenance_start']) && $now < strtotime($settings['maintenance_start'])) {
            return false;
        }
        if (!empty($settings['maintenance_end']) && $now >= strtotime($settings['maintenance_end'])) {
            return false;
        }

        return true;
    } catch (Throwable $e) {
        error_log('C-Attend maintenance mode check failed: ' . $e->getMessage());
        return false;
    }
}

function get_scheduled_maintenance(PDO $pdo): ?array {
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('maintenance_mode', 'maintenance_start', 'maintenance_end')");
        $settings = [];
        foreach ($stmt->fetchAll() as $setting) {
            $settings[$setting['setting_key']] = $setting['setting_value'];
        }

        if (($settings['maintenance_mode'] ?? '0') !== '1' || empty($settings['maintenance_start'])) {
            return null;
        }

        $start_timestamp = strtotime($settings['maintenance_start']);
        $end_timestamp = !empty($settings['maintenance_end']) ? strtotime($settings['maintenance_end']) : null;
        if (!$start_timestamp || $start_timestamp <= time() || ($end_timestamp && $end_timestamp <= time())) {
            return null;
        }

        return [
            'start' => $settings['maintenance_start'],
            'end' => $settings['maintenance_end'] ?? null
        ];
    } catch (Throwable $e) {
        error_log('C-Attend scheduled maintenance lookup failed: ' . $e->getMessage());
        return null;
    }
}

function render_scheduled_maintenance_popup(PDO $pdo): void {
    $scheduled = get_scheduled_maintenance($pdo);
    if (!$scheduled) {
        return;
    }

    $start_label = date('d M Y, h:i A', strtotime($scheduled['start']));
    $end_label = !empty($scheduled['end']) ? date('d M Y, h:i A', strtotime($scheduled['end'])) : 'until further notice';
    ?>
    <div id="scheduled-maintenance-popup" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/60 px-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="scheduled-maintenance-title">
        <div class="w-full max-w-md rounded-3xl border border-amber-300 bg-white p-6 text-slate-900 shadow-2xl">
            <div class="flex items-start gap-4">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-2xl">!</div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-700">Important notice</p>
                    <h2 id="scheduled-maintenance-title" class="mt-1 text-xl font-black">Maintenance window scheduled</h2>
                </div>
            </div>
            <p class="mt-4 text-sm leading-6 text-slate-600">The admin portal will temporarily be unavailable during the scheduled maintenance window.</p>
            <div class="mt-4 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">
                <p><strong>Starts:</strong> <?= htmlspecialchars($start_label) ?></p>
                <p class="mt-1"><strong>Ends:</strong> <?= htmlspecialchars($end_label) ?></p>
            </div>
            <button type="button" onclick="document.getElementById('scheduled-maintenance-popup').remove()" class="mt-5 w-full rounded-xl bg-amber-500 px-4 py-3 text-sm font-bold text-white transition hover:bg-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-400 focus:ring-offset-2">I understand</button>
        </div>
    </div>
    <?php
}

function enforce_portal_maintenance(PDO $pdo, string $portal_name): void {
    if (!is_maintenance_mode($pdo)) {
        return;
    }

    http_response_code(503);
    $maintenance_end = null;
    try {
        $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_end'");
        $maintenance_end = $stmt->fetchColumn() ?: null;
    } catch (Throwable $e) {
        error_log('C-Attend maintenance countdown lookup failed: ' . $e->getMessage());
    }
    $end_timestamp = $maintenance_end ? strtotime($maintenance_end) : 0;
    $remaining_seconds = max(0, $end_timestamp - time());
    $script_path = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $script_directory = rtrim(str_replace('\\', '/', dirname($script_path)), '/');
    $portal_directory = basename($script_directory);
    $asset_base = in_array($portal_directory, ['admin', 'maintenance', 'student'], true)
        ? dirname($script_directory)
        : $script_directory;
    $asset_base = $asset_base === '/' ? '' : rtrim($asset_base, '/');
    $tailwind_url = htmlspecialchars($asset_base . '/assets/tailwind.css', ENT_QUOTES, 'UTF-8');
    $logo_url = htmlspecialchars($asset_base . '/assets/logo-dashboard.png', ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Maintenance in progress</title><link rel="stylesheet" href="' . $tailwind_url . '"></head><body class="min-h-screen bg-slate-950 text-slate-100"><main class="mx-auto flex min-h-screen max-w-lg items-center px-5"><section class="w-full rounded-3xl border border-slate-800 bg-slate-900 p-8 text-center shadow-2xl"><img src="' . $logo_url . '" alt="C-Attend logo" class="mx-auto h-20 w-20 rounded-2xl bg-white p-2 object-contain shadow-lg"><p class="mt-6 text-xs font-semibold uppercase tracking-[0.25em] text-amber-300">C-Attend maintenance</p><h1 class="mt-4 text-3xl font-black">Back online in</h1><p id="maintenance-countdown" class="mt-5 text-5xl font-black tracking-wider text-amber-300">--:--:--</p></section></main><script>let remaining=' . $remaining_seconds . ';const countdown=document.getElementById("maintenance-countdown");function updateCountdown(){const hours=Math.floor(remaining/3600);const minutes=Math.floor((remaining%3600)/60);const seconds=remaining%60;countdown.textContent=[hours,minutes,seconds].map(value=>String(value).padStart(2,"0")).join(":");if(remaining>0){remaining-=1;}}updateCountdown();window.setInterval(updateCountdown,1000);</script></body></html>';
    exit;
}

function expire_attendance_sessions(PDO $pdo): void {
    $pdo->exec("UPDATE attendance_sessions SET is_active = 0 WHERE is_active = 1 AND end_time <= NOW()");
}

function expire_previous_day_attendance_sessions(PDO $pdo): int {
    $stmt = $pdo->prepare("
        UPDATE attendance_sessions
        SET is_active = 0,
            status = 'ended'
        WHERE is_active = 1
          AND created_at < CURRENT_DATE()
    ");
    $stmt->execute();

    return $stmt->rowCount();
}

function record_portal_alert(PDO $pdo, string $title, string $message, string $severity = 'medium', string $source = 'portal', ?string $details = null): bool {
    $severity = in_array($severity, ['low', 'medium', 'high', 'critical'], true) ? $severity : 'medium';
    $source = trim($source) !== '' ? substr(trim($source), 0, 40) : 'portal';

    try {
        $stmt = $pdo->prepare("INSERT INTO portal_alerts (severity, source, title, message, details) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$severity, $source, $title, $message, $details]);
    } catch (Throwable $e) {
        error_log('C-Attend portal alert log failed: ' . $e->getMessage());
        return false;
    }
}

function get_recent_portal_alerts(PDO $pdo, int $limit = 5): array {
    $limit = max(1, min(20, $limit));

    try {
        $stmt = $pdo->query("SELECT * FROM portal_alerts ORDER BY created_at DESC LIMIT $limit");
        return $stmt ? $stmt->fetchAll() : [];
    } catch (Throwable $e) {
        error_log('C-Attend portal alert fetch failed: ' . $e->getMessage());
        return [];
    }


}

function generate_csrf_token(string $key = 'default'): string {
    if (empty($_SESSION['csrf_tokens'][$key])) {
        $_SESSION['csrf_tokens'][$key] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_tokens'][$key];
}

function validate_csrf_token(?string $token, string $key = 'default'): bool {
    if (!$token) {
        return false;
    }

    if (empty($_SESSION['csrf_tokens'][$key])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_tokens'][$key], $token);
}

function normalize_phone_number(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
        return '+233' . substr($digits, 1);
    }

    if (strlen($digits) === 12 && str_starts_with($digits, '233')) {
        return '+' . $digits;
    }

    if (strlen($digits) >= 10 && str_starts_with($phone, '+')) {
        return '+' . $digits;
    }

    return $phone;
}

function is_valid_index_number(string $index_number): bool {
    return (bool) preg_match('/^[A-Za-z0-9\/-]{4,30}$/', trim($index_number));
}

function normalize_index_number(string $index_number): string {
    return strtoupper((string)preg_replace('/\s+/', '', trim($index_number)));
}

function is_valid_phone_number(string $phone): bool {
    $digits = preg_replace('/\D+/', '', $phone);
    return strlen($digits) >= 10 && strlen($digits) <= 15;
}

function is_valid_password(string $password): bool {
    return strlen($password) >= 8;
}

function generate_reset_token(): string {
    return (string) random_int(100000, 999999);
}
