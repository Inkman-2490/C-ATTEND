<?php
session_start(['cookie_httponly' => true, 'use_strict_mode' => true, 'cookie_samesite' => 'Lax']);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/portal_sessions.php';
ensure_portal_alert_table($pdo);
ensure_attendance_approval_columns($pdo);
ensure_class_columns($pdo);
ensure_privacy_acceptance_column($pdo);
ensure_admin_roles($pdo);
ensure_maintenance_tables($pdo);
ensure_student_auth_events_table($pdo);
if (!isset($_SESSION['maintenance_user_id'])) { header('Location: login.php'); exit; }
expire_attendance_sessions($pdo);
ensure_portal_only_session_tables($pdo);
$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];
$message = '';
$error = '';
$daily_reset_counts = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf_token($_POST['csrf_token'] ?? null, 'maintenance_actions')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle_maintenance') {
        $value = ($_POST['value'] ?? '0') === '1' ? '1' : '0';
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('maintenance_mode', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$value]);
        if ($value === '0') {
            $clear_schedule = $pdo->prepare("DELETE FROM system_settings WHERE setting_key IN ('maintenance_start', 'maintenance_end')");
            $clear_schedule->execute();
        }
        record_portal_alert($pdo, 'Maintenance mode changed', 'Maintenance mode was ' . ($value === '1' ? 'enabled' : 'disabled'), 'high', 'maintenance');
        $message = 'Maintenance mode updated.';
    } elseif ($action === 'schedule_maintenance') {
        $start = trim($_POST['maintenance_start'] ?? '');
        $end = trim($_POST['maintenance_end'] ?? '');
        $start_date = $start !== '' ? DateTime::createFromFormat('Y-m-d\TH:i', $start) : null;
        $end_date = $end !== '' ? DateTime::createFromFormat('Y-m-d\TH:i', $end) : null;
        $start_time = $start_date ? $start_date->format('Y-m-d H:i:s') : null;
        $end_time = $end_date ? $end_date->format('Y-m-d H:i:s') : null;

        if (($start !== '' && !$start_date) || ($end !== '' && !$end_date)) {
            $error = 'Please enter valid maintenance start and end times.';
        } elseif ($start_time && $end_time && strtotime($end_time) <= strtotime($start_time)) {
            $error = 'Maintenance end time must be after the start time.';
        } else {
            $settings = [
                'maintenance_mode' => '1',
                'maintenance_start' => $start_time,
                'maintenance_end' => $end_time
            ];
            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute([$key, $value]);
            }
            record_portal_alert($pdo, 'Maintenance window scheduled', 'Maintenance was scheduled by the maintenance user.', 'high', 'maintenance');
            $message = 'Maintenance window scheduled.';
        }
    } elseif ($action === 'save_default_radius') {
        $radius = filter_var($_POST['default_geofence_radius'] ?? null, FILTER_VALIDATE_INT);
        if ($radius === false || $radius < 50 || $radius > 2000) {
            $error = 'Default geofence radius must be a whole number between 50 and 2000 meters.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value)
                VALUES ('default_geofence_radius', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([(string)$radius]);
            $message = 'Default radius updated. It will be prefilled for new admin sessions.';
        }
    } elseif ($action === 'repair_schema') {
        ensure_portal_alert_table($pdo);
        ensure_class_columns($pdo);
        ensure_privacy_acceptance_column($pdo);
        ensure_admin_roles($pdo);
        ensure_maintenance_tables($pdo);
        $message = 'Schema checks and repairs completed.';
    } elseif ($action === 'expire_sessions') {
        expire_attendance_sessions($pdo);
        $message = 'Expired attendance sessions were closed.';
    } elseif ($action === 'reset_daily_geofences') {
        $daily_reset_counts = run_daily_geofence_reset($pdo, true);
        $message = 'Previous-day geofences were reset. Standard sessions closed: '
            . $daily_reset_counts['standard_sessions']
            . '; portal-only sessions closed: '
            . $daily_reset_counts['portal_sessions']
            . '.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = 'Security validation failed.';
}

if ($daily_reset_counts === null) {
    $daily_reset_counts = run_daily_geofence_reset($pdo);
}

$maintenance_enabled = is_maintenance_mode($pdo);
$settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('maintenance_start', 'maintenance_end', 'last_daily_geofence_reset_at')");
$maintenance_schedule = [];
foreach ($settings_stmt->fetchAll() as $setting) {
    $maintenance_schedule[$setting['setting_key']] = $setting['setting_value'];
}
$maintenance_start_value = !empty($maintenance_schedule['maintenance_start']) ? date('Y-m-d\TH:i', strtotime($maintenance_schedule['maintenance_start'])) : '';
$maintenance_end_value = !empty($maintenance_schedule['maintenance_end']) ? date('Y-m-d\TH:i', strtotime($maintenance_schedule['maintenance_end'])) : '';
$default_geofence_radius = get_default_geofence_radius($pdo);
$last_geofence_reset = $maintenance_schedule['last_daily_geofence_reset_at'] ?? null;
$previous_day_standard_sessions = (int)$pdo->query("SELECT COUNT(*) FROM attendance_sessions WHERE is_active = 1 AND created_at < CURRENT_DATE()")->fetchColumn();
$previous_day_portal_sessions = (int)$pdo->query("SELECT COUNT(*) FROM portal_only_sessions WHERE is_active = 1 AND created_at < CURRENT_DATE()")->fetchColumn();
$tables = [];
foreach (['admins', 'students', 'courses', 'attendance_sessions', 'attendance_records', 'password_resets', 'portal_alerts', 'maintenance_users', 'system_settings'] as $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    $tables[$table] = (bool)$stmt->fetchColumn();
}
$counts = [];
foreach (['students', 'courses', 'attendance_sessions', 'attendance_records', 'password_resets', 'portal_alerts'] as $table) {
    $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}
$alerts = get_recent_portal_alerts($pdo, 12);
$auth_chart_rows = $pdo->query("
    SELECT DATE(created_at) AS event_date,
           SUM(event_type = 'login') AS login_count,
           SUM(event_type = 'logout') AS logout_count
    FROM student_auth_events
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY event_date ASC
")->fetchAll();
$auth_chart = [];
for ($day_offset = 6; $day_offset >= 0; $day_offset--) {
    $date_key = date('Y-m-d', strtotime('-' . $day_offset . ' days'));
    $auth_chart[$date_key] = ['login_count' => 0, 'logout_count' => 0];
}
foreach ($auth_chart_rows as $chart_row) {
    $auth_chart[$chart_row['event_date']] = [
        'login_count' => (int)$chart_row['login_count'],
        'logout_count' => (int)$chart_row['logout_count']
    ];
}
$auth_chart_max = 1;
foreach ($auth_chart as $chart_day) {
    $auth_chart_max = max($auth_chart_max, $chart_day['login_count'], $chart_day['logout_count']);
}
$active_sessions = [];
$active_session_stmt = $pdo->query("
    SELECT
        s.id,
        s.target_class,
        s.start_time,
        s.end_time,
        s.radius_meters,
        c.course_code,
        c.course_title,
        st.index_number,
        st.full_name,
        r.marked_at,
        r.distance_meters
    FROM attendance_sessions s
    JOIN courses c ON c.id = s.course_id
    LEFT JOIN attendance_records r ON r.session_id = s.id AND r.approval_status = 'approved'
    LEFT JOIN students st ON st.id = r.student_id
    WHERE s.is_active = 1
      AND s.end_time > NOW()
    ORDER BY s.end_time ASC, r.marked_at ASC, st.index_number ASC
");
foreach ($active_session_stmt->fetchAll() as $row) {
    $session_key = (int)$row['id'];
    if (!isset($active_sessions[$session_key])) {
        $active_sessions[$session_key] = [
            'id' => $session_key,
            'target_class' => $row['target_class'],
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'radius_meters' => $row['radius_meters'],
            'course_code' => $row['course_code'],
            'course_title' => $row['course_title'],
            'students' => []
        ];
    }
    if ($row['index_number'] !== null) {
        $active_sessions[$session_key]['students'][] = [
            'index_number' => $row['index_number'],
            'full_name' => $row['full_name'],
            'marked_at' => $row['marked_at'],
            'distance_meters' => $row['distance_meters']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="icon" href="../assets/logo-dashboard.png" type="image/png"><link rel="apple-touch-icon" href="../assets/logo-dashboard.png"><title>Maintenance Dashboard</title><link rel="stylesheet" href="../assets/tailwind.css"></head>
<body class="min-h-screen bg-slate-950 text-slate-100"><div class="mx-auto max-w-7xl px-4 py-6">
<header class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-3xl border border-slate-800 bg-slate-900 p-5"><div class="flex items-center gap-3"><img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="h-12 w-12 rounded-2xl bg-white p-1.5 object-contain shadow-sm"><div><p class="text-xs uppercase tracking-[0.25em] text-amber-300">System operations</p><h1 class="mt-1 text-2xl font-black">Maintenance dashboard</h1><p class="text-sm text-slate-400">Signed in as <?= htmlspecialchars($_SESSION['maintenance_user_name']) ?></p></div></div><div class="flex items-center gap-2"><a href="#student-activity-chart" class="rounded-xl bg-sky-500 px-4 py-2 text-sm font-bold text-white hover:bg-sky-600">Student activity</a><a href="logout.php" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold">Sign out</a></div></header>
<?php if ($message): ?><div class="mb-4 rounded-xl border border-emerald-400/30 bg-emerald-400/10 p-3 text-sm text-emerald-200"><?= htmlspecialchars($message) ?></div><?php endif; ?><?php if ($error): ?><div class="mb-4 rounded-xl border border-red-400/30 bg-red-400/10 p-3 text-sm text-red-200"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<section class="mb-5 rounded-3xl border border-sky-400/20 bg-slate-900 p-5"><div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs uppercase tracking-[0.2em] text-sky-300">Daily geofence reset</p><h2 class="mt-1 text-xl font-black">Maintenance-controlled session rollover</h2><p class="mt-2 max-w-3xl text-sm text-slate-400">Yesterday's standard and portal-only sessions are closed here and by the scheduled cleanup task. Student check-in blocks previous-day sessions even if the scheduled reset is delayed.</p></div><form method="post" onsubmit="return confirm('Reset all still-active sessions created before today?');"><input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_actions') ?>"><input type="hidden" name="action" value="reset_daily_geofences"><button class="rounded-xl bg-sky-400 px-4 py-3 text-sm font-bold text-slate-950 hover:bg-sky-300">Reset previous-day geofences</button></form></div><div class="mt-4 grid gap-3 sm:grid-cols-3"><div class="rounded-2xl bg-slate-950 p-3"><p class="text-xs uppercase text-slate-500">Last daily reset</p><p class="mt-1 text-sm font-bold text-slate-100"><?= $last_geofence_reset ? htmlspecialchars(date('M j, Y g:i A', strtotime($last_geofence_reset))) : 'Not recorded yet' ?></p></div><div class="rounded-2xl bg-slate-950 p-3"><p class="text-xs uppercase text-slate-500">Previous-day standard sessions active</p><p class="mt-1 text-xl font-black <?= $previous_day_standard_sessions ? 'text-amber-300' : 'text-emerald-300' ?>"><?= $previous_day_standard_sessions ?></p></div><div class="rounded-2xl bg-slate-950 p-3"><p class="text-xs uppercase text-slate-500">Previous-day portal sessions active</p><p class="mt-1 text-xl font-black <?= $previous_day_portal_sessions ? 'text-amber-300' : 'text-emerald-300' ?>"><?= $previous_day_portal_sessions ?></p></div></div><p class="mt-3 text-xs text-slate-500">The cPanel cleanup task should run every five minutes. Align the database timezone with the institution's local timezone.</p></section>
<section class="mb-5 rounded-3xl border border-amber-400/20 bg-slate-900 p-5"><div class="max-w-3xl"><p class="text-xs uppercase tracking-[0.2em] text-amber-300">Admin portal defaults</p><h2 class="mt-1 text-xl font-black">Default geofence radius</h2><p class="mt-2 text-sm text-slate-400">This value is prefilled when an admin or sub-admin creates a standard or portal-only session. It does not change existing sessions, and admins may adjust the radius for each new session.</p></div><form method="post" class="mt-4 flex flex-wrap items-end gap-3"><input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_actions') ?>"><input type="hidden" name="action" value="save_default_radius"><label class="text-sm font-semibold text-slate-300">Radius in meters<input type="number" name="default_geofence_radius" min="50" max="2000" step="1" required value="<?= $default_geofence_radius ?>" class="mt-1 block w-48 rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-white"></label><button class="rounded-xl bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950 hover:bg-amber-200">Save default radius</button><span class="text-xs text-slate-500">Allowed: 50–2,000 meters. Current default: <?= $default_geofence_radius ?>m.</span></form></section>
<div class="grid gap-5 lg:grid-cols-3"><section class="rounded-3xl border border-slate-800 bg-slate-900 p-5 lg:col-span-2"><div class="flex items-center justify-between"><div><p class="text-xs uppercase tracking-[0.2em] text-slate-400">System status</p><h2 class="mt-1 text-xl font-black">Core health checks</h2></div><span class="rounded-full px-3 py-1 text-xs font-bold <?= $maintenance_enabled ? 'bg-amber-300 text-slate-950' : 'bg-emerald-300 text-slate-950' ?>"><?= $maintenance_enabled ? 'Maintenance mode' : 'Online' ?></span></div><div class="mt-5 grid gap-3 sm:grid-cols-2"><?php foreach ($tables as $table => $exists): ?><div class="flex items-center justify-between rounded-2xl bg-slate-950 p-3"><span class="text-sm"><?= htmlspecialchars($table) ?></span><span class="text-xs font-bold <?= $exists ? 'text-emerald-300' : 'text-red-300' ?>"><?= $exists ? 'Ready' : 'Missing' ?></span></div><?php endforeach; ?></div><div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-6"><?php foreach ($counts as $name => $count): ?><div class="rounded-2xl bg-slate-950 p-3"><p class="text-[10px] uppercase text-slate-500"><?= htmlspecialchars($name) ?></p><p class="mt-1 text-xl font-black"><?= $count ?></p></div><?php endforeach; ?></div></section>
<section class="rounded-3xl border border-slate-800 bg-slate-900 p-5"><p class="text-xs uppercase tracking-[0.2em] text-slate-400">Controls</p><h2 class="mt-1 text-xl font-black">Safe operations</h2><div class="mt-5 space-y-3"><form method="post"><input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_actions') ?>"><input type="hidden" name="action" value="toggle_maintenance"><input type="hidden" name="value" value="<?= $maintenance_enabled ? '0' : '1' ?>"><button class="w-full rounded-xl bg-amber-300 px-4 py-3 text-sm font-bold text-slate-950"><?= $maintenance_enabled ? 'Disable maintenance mode' : 'Enable maintenance mode' ?></button></form><form method="post" class="rounded-2xl border border-slate-700 bg-slate-950 p-3"><input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_actions') ?>"><input type="hidden" name="action" value="schedule_maintenance"><p class="text-xs font-bold uppercase tracking-[0.15em] text-amber-300">Schedule maintenance</p><p class="mt-1 text-xs text-slate-400">Portals will close automatically during this window.</p><label class="mt-3 block text-xs font-semibold text-slate-300">Start time<input type="datetime-local" name="maintenance_start" value="<?= htmlspecialchars($maintenance_start_value) ?>" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white"></label><label class="mt-3 block text-xs font-semibold text-slate-300">End time<input type="datetime-local" name="maintenance_end" value="<?= htmlspecialchars($maintenance_end_value) ?>" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white"></label><button class="mt-3 w-full rounded-xl bg-amber-300 px-4 py-3 text-sm font-bold text-slate-950">Save maintenance window</button></form><a href="backup.php" class="block w-full rounded-xl bg-slate-800 px-4 py-3 text-center text-sm font-semibold">Download database backup</a><form method="post"><input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_actions') ?>"><input type="hidden" name="action" value="repair_schema"><button class="w-full rounded-xl bg-slate-800 px-4 py-3 text-sm font-semibold">Check and repair schema</button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_actions') ?>"><input type="hidden" name="action" value="expire_sessions"><button class="w-full rounded-xl bg-slate-800 px-4 py-3 text-sm font-semibold">Close expired sessions</button></form></div></section></div>
<section class="mt-5 rounded-3xl border border-slate-800 bg-slate-900 p-5"><div class="flex items-center justify-between"><div><p class="text-xs uppercase tracking-[0.2em] text-slate-400">Monitoring</p><h2 class="mt-1 text-xl font-black">Recent portal alerts</h2></div><div class="flex items-center gap-3"><a href="students.php" class="rounded-xl bg-amber-300 px-3 py-2 text-xs font-bold text-slate-950">Manage students</a><span class="text-xs text-slate-500"><?= count($alerts) ?> shown</span></div></div><div class="mt-4 space-y-2"><?php if (!$alerts): ?><p class="text-sm text-slate-400">No alerts recorded.</p><?php else: foreach ($alerts as $alert): ?><div class="rounded-2xl bg-slate-950 p-3"><div class="flex flex-wrap justify-between gap-2"><span class="text-sm font-semibold"><?= htmlspecialchars($alert['title']) ?></span><span class="text-xs text-amber-300"><?= htmlspecialchars($alert['severity']) ?> · <?= htmlspecialchars($alert['source']) ?></span></div><p class="mt-1 text-sm text-slate-400"><?= htmlspecialchars($alert['message']) ?></p><?php if (!empty($alert['details'])): ?><p class="mt-2 text-xs text-slate-300"><span class="font-semibold text-amber-300">Reason:</span> <?= htmlspecialchars($alert['details']) ?></p><?php endif; ?><p class="mt-1 text-xs text-slate-600"><?= htmlspecialchars($alert['created_at']) ?></p></div><?php endforeach; endif; ?></div></section>
<section id="student-activity-chart" class="mt-5 scroll-mt-6 rounded-3xl border border-slate-800 bg-slate-900 p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Student activity</p>
            <h2 class="mt-1 text-xl font-black">Login and logout activity</h2>
            <p class="mt-1 text-sm text-slate-400">Student authentication events over the last 7 days.</p>
        </div>
        <div class="flex gap-2 text-xs font-semibold">
            <button type="button" id="toggle-login-chart" onclick="toggleAuthBars('login')" class="rounded-lg border border-sky-300/40 bg-sky-400/15 px-3 py-1.5 text-sky-300 transition hover:bg-sky-400/25" aria-pressed="true">Login</button>
            <button type="button" id="toggle-logout-chart" onclick="toggleAuthBars('logout')" class="rounded-lg border border-amber-300/40 bg-amber-300/15 px-3 py-1.5 text-amber-300 transition hover:bg-amber-300/25" aria-pressed="true">Logout</button>
            <button type="button" onclick="window.location.reload()" class="rounded-lg border border-slate-600 bg-slate-800 px-3 py-1.5 text-slate-300 transition hover:bg-slate-700">Refresh</button>
        </div>
    </div>
    <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <?php foreach ($auth_chart as $event_date => $chart_day): ?>
            <div class="rounded-2xl bg-slate-950 p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-500"><?= htmlspecialchars(date('D', strtotime($event_date))) ?></p>
                <p class="mt-1 text-sm font-semibold text-slate-300"><?= htmlspecialchars(date('d M', strtotime($event_date))) ?></p>
                <div class="mt-4 grid grid-cols-2 gap-2">
                    <div class="login-chart-bar rounded-xl bg-sky-400/10 p-3">
                        <p class="text-[10px] uppercase tracking-wide text-sky-300">Login</p>
                        <p class="mt-1 text-2xl font-black text-white"><?= $chart_day['login_count'] ?></p>
                    </div>
                    <div class="logout-chart-bar rounded-xl bg-orange-400/10 p-3">
                        <p class="text-[10px] uppercase tracking-wide text-orange-300">Logout</p>
                        <p class="mt-1 text-2xl font-black text-white"><?= $chart_day['logout_count'] ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<script>
    function toggleAuthBars(type) {
        var button = document.getElementById(type === 'login' ? 'toggle-login-chart' : 'toggle-logout-chart');
        var bars = document.querySelectorAll(type === 'login' ? '.login-chart-bar' : '.logout-chart-bar');
        var visible = button.getAttribute('aria-pressed') === 'true';
        button.setAttribute('aria-pressed', visible ? 'false' : 'true');
        bars.forEach(function (bar) {
            bar.style.display = visible ? 'none' : 'block';
        });
        button.style.opacity = visible ? '0.45' : '1';
    }
    window.setInterval(function () {
        window.location.reload();
    }, 30000);
</script>
<section class="mt-5 rounded-3xl border border-slate-800 bg-slate-900 p-5"><div class="flex items-center justify-between"><div><p class="text-xs uppercase tracking-[0.2em] text-slate-400">Live monitoring</p><h2 class="mt-1 text-xl font-black">Active attendance sessions</h2></div><span class="text-xs text-slate-500"><?= count($active_sessions) ?> active</span></div><div class="mt-4 space-y-4"><?php if (!$active_sessions): ?><p class="text-sm text-slate-400">No active attendance sessions.</p><?php else: foreach ($active_sessions as $active_session): ?><div class="rounded-2xl bg-slate-950 p-4"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-[0.15em] text-amber-300">Session #<?= $active_session['id'] ?></p><h3 class="mt-1 text-base font-bold"><?= htmlspecialchars($active_session['course_title']) ?></h3><p class="text-sm text-slate-400"><?= htmlspecialchars($active_session['course_code']) ?> · Class <?= htmlspecialchars($active_session['target_class'] === 'both' ? 'A & B' : $active_session['target_class']) ?> · Radius <?= (int)$active_session['radius_meters'] ?>m</p><p class="mt-1 text-xs text-slate-500"><?= date('d M Y, h:i A', strtotime($active_session['start_time'])) ?> — <?= date('h:i A', strtotime($active_session['end_time'])) ?></p></div><span class="rounded-full bg-emerald-400/15 px-3 py-1 text-xs font-bold text-emerald-300"><?= count($active_session['students']) ?> marked</span></div><?php if (!$active_session['students']): ?><p class="mt-4 rounded-xl border border-dashed border-slate-700 p-3 text-sm text-slate-500">No students have marked attendance yet.</p><?php else: ?><div class="mt-4 overflow-x-auto"><table class="min-w-full text-left text-sm"><thead><tr class="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500"><th class="px-2 py-2">Index number</th><th class="px-2 py-2">Student</th><th class="px-2 py-2">Marked at</th><th class="px-2 py-2">Distance</th></tr></thead><tbody><?php foreach ($active_session['students'] as $marked_student): ?><tr class="border-b border-slate-900 last:border-0"><td class="px-2 py-2 font-semibold text-slate-200"><?= htmlspecialchars($marked_student['index_number']) ?></td><td class="px-2 py-2 text-slate-300"><?= htmlspecialchars($marked_student['full_name']) ?></td><td class="whitespace-nowrap px-2 py-2 text-slate-400"><?= date('h:i:s A', strtotime($marked_student['marked_at'])) ?></td><td class="whitespace-nowrap px-2 py-2 text-slate-400"><?= number_format((float)$marked_student['distance_meters'], 1) ?>m</td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div><?php endforeach; endif; ?></div></section>
<div class="mt-5 flex flex-wrap gap-3"><a href="students.php" class="rounded-xl bg-amber-300 px-4 py-3 text-sm font-bold text-slate-950">Manage students</a><a href="courses.php" class="rounded-xl bg-amber-300 px-4 py-3 text-sm font-bold text-slate-950">Manage courses</a><a href="sessions.php" class="rounded-xl bg-red-500 px-4 py-3 text-sm font-bold text-white">Manage sessions</a><a href="admins.php" class="rounded-xl bg-sky-500 px-4 py-3 text-sm font-bold text-white">Manage admins</a><a href="student_recovery.php" class="rounded-xl bg-sky-400/10 px-4 py-3 text-sm font-bold text-sky-200">Student recovery</a></div>
</div><?php require __DIR__ . '/../includes/system_footer.php'; ?></body></html>
