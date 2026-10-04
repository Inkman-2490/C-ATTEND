<?php
// admin/dashboard.php
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once '../includes/portal_sessions.php';

ensure_portal_alert_table($pdo);
ensure_class_columns($pdo);
ensure_admin_roles($pdo);
ensure_attendance_approval_columns($pdo);
ensure_portal_only_session_tables($pdo);
finalize_and_expire_portal_only_sessions($pdo);
expire_attendance_sessions($pdo);
ensure_maintenance_tables($pdo);
enforce_portal_maintenance($pdo, 'admin portal');

$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];

date_default_timezone_set('Africa/Accra');

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$admin_role = $_SESSION['admin_role'] ?? 'admin';
$is_sub_admin = $admin_role === 'sub_admin';
$admin_class = $_SESSION['admin_class'] ?? 'A';
$current_hour = (int)date('G');
$greeting = $current_hour < 12 ? 'Good morning' : ($current_hour < 18 ? 'Good afternoon' : 'Good evening');

$error = '';
$success = $_SESSION['admin_flash_success'] ?? '';
unset($_SESSION['admin_flash_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_add_course'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'admin_add_course')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $course_code = trim($_POST['course_code'] ?? '');
        $course_title = trim($_POST['course_title'] ?? '');
        $semester = trim($_POST['semester'] ?? 'Not Set');
        $academic_year = trim($_POST['academic_year'] ?? date('Y') . '/' . (date('Y') + 1));

        if (!empty($course_code) && !empty($course_title)) {
            $stmt = $pdo->prepare("INSERT INTO courses (course_code, course_title, semester, academic_year) VALUES (?, ?, ?, ?)");
            try {
                $stmt->execute([$course_code, $course_title, $semester, $academic_year]);
                $_SESSION['admin_flash_success'] = 'Course added successfully!';
                header('Location: dashboard.php');
                exit;
            } catch (\PDOException $e) {
                $error = 'Course code already exists.';
                record_portal_alert($pdo, 'Course creation failed', 'Admin attempted to create course code ' . $course_code . ' but the database rejected it.', 'high', 'admin', $e->getMessage());
            }
        } else {
            $error = 'All course fields are required.';
            record_portal_alert($pdo, 'Course form validation failed', 'Admin did not submit all required course fields.', 'medium', 'admin', 'Missing required course values');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_start_session'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'admin_start_session')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $target_class = strtolower(trim($_POST['target_class'] ?? 'both'));
        if ($is_sub_admin) {
            $target_class = strtolower($admin_class);
        }
        $lat = (float)($_POST['latitude'] ?? 0);
        $lng = (float)($_POST['longitude'] ?? 0);
        $radius = (int)($_POST['radius_meters'] ?? 0);
        $radius = max(50, min(2000, $radius));
        $start_time = date('Y-m-d H:i:s', strtotime($_POST['start_time'] ?? 'now'));
        $end_time = date('Y-m-d H:i:s', strtotime($_POST['end_time'] ?? '+2 hours'));

        if (!in_array($target_class, ['a', 'b', 'both'], true)) {
            $target_class = 'both';
        }
        $target_class = $target_class === 'both' ? 'both' : strtoupper($target_class);

        if ($course_id && $lat && $lng && $radius && $start_time && $end_time) {
            if (strtotime($end_time) <= strtotime($start_time)) {
                $error = 'End time must be after the start time.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO attendance_sessions (course_id, target_class, latitude, longitude, radius_meters, start_time, end_time, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                if ($stmt->execute([$course_id, $target_class, $lat, $lng, $radius, $start_time, $end_time])) {
                    $_SESSION['admin_flash_success'] = 'Attendance session launched successfully!';
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = 'Failed to launch attendance session.';
                    record_portal_alert($pdo, 'Attendance session creation failed', 'Admin failed to create a session for course_id ' . $course_id, 'high', 'admin', 'Database insert error');
                }
            }
        } else {
            $error = 'Please fill in all session fields including valid GPS coordinates.';
            record_portal_alert($pdo, 'Session form validation failed', 'Admin submitted an incomplete attendance-session form.', 'medium', 'admin', 'Missing required GPS or time values');
        }
    }
}

if (isset($_GET['end_session_id'])) {
    $close_id = (int)($_GET['end_session_id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE attendance_sessions SET is_active = 0 WHERE id = ?");
    $stmt->execute([$close_id]);
    header("Location: dashboard.php");
    exit;
}

$courses = $pdo->query("SELECT * FROM courses ORDER BY course_code ASC")->fetchAll();
$default_geofence_radius = get_default_geofence_radius($pdo);
$active_sessions = $pdo->query("
    SELECT s.*, c.course_code, c.course_title,
    (SELECT COUNT(*) FROM attendance_records r WHERE r.session_id = s.id AND r.approval_status = 'approved') AS total_attended
    FROM attendance_sessions s
    JOIN courses c ON s.course_id = c.id
    WHERE s.is_active = 1
      AND s.end_time > NOW()
      AND s.created_at >= CURRENT_DATE()
    ORDER BY s.id DESC
")->fetchAll();
$pending_manual_attendance = 0;
if (!$is_sub_admin) {
    $pending_manual_attendance = (int)$pdo->query("SELECT COUNT(*) FROM attendance_records WHERE approval_status = 'pending'")->fetchColumn();
}
$attendance_risk = $pdo->query("
    SELECT
        COUNT(*) AS tracked_students,
        SUM(CASE WHEN absent_sessions / eligible_sessions >= 0.25 THEN 1 ELSE 0 END) AS high_risk_students
    FROM (
        SELECT
            st.id,
            COUNT(s.id) AS eligible_sessions,
            COUNT(s.id) - COUNT(r.id) AS absent_sessions
        FROM students st
        LEFT JOIN attendance_sessions s
            ON s.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
           AND s.end_time <= NOW()
           AND (s.target_class = st.class_name OR s.target_class = 'both')
        LEFT JOIN attendance_records r
            ON r.session_id = s.id
           AND r.student_id = st.id
          AND r.approval_status = 'approved'
        GROUP BY st.id
        HAVING eligible_sessions > 0
    ) AS student_attendance
")->fetch() ?: ['tracked_students' => 0, 'high_risk_students' => 0];
$tracked_students = (int)$attendance_risk['tracked_students'];
$high_risk_students = (int)$attendance_risk['high_risk_students'];
$risk_rate = $tracked_students > 0 ? (int)round(($high_risk_students / $tracked_students) * 100) : 0;
$risk_label = $risk_rate >= 25 ? 'High' : ($risk_rate >= 10 ? 'Watch' : 'Low');
$risk_badge_class = $risk_rate >= 25
    ? 'bg-rose-100 text-rose-700'
    : ($risk_rate >= 10 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700');
$admin_alerts = get_recent_portal_alerts($pdo, 5);
$scheduled_maintenance = get_scheduled_maintenance($pdo);

$default_start = date('Y-m-d\TH:i');
$default_end = date('Y-m-d\TH:i', strtotime('+2 hours'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <link rel="apple-touch-icon" href="../assets/logo-dashboard.png">
    <title>C-Attend - Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/tailwind.css">
    <link rel="stylesheet" href="../includes/mobile.css">
    <style>
        @media (max-width: 640px) {
            .mobile-page,
            .mobile-page * { box-sizing: border-box; }
            .mobile-page { overflow-x: hidden; }
            .mobile-page > div { width: 100%; max-width: 100%; padding: 0.75rem; }
            .mobile-page > div > * { min-width: 0; max-width: 100%; }
            .mobile-page header { border-radius: 1.25rem; }
            .mobile-page header > div { padding: 1rem; }
            .mobile-page header nav,
            .mobile-page header .admin-nav { width: 100%; }
            .mobile-page .admin-nav-group { width: 100%; }
            .mobile-page .admin-nav-group a { flex: 1 1 calc(50% - 0.5rem); min-width: 0; text-align: center; }
            .mobile-page .admin-nav-account { width: 100%; margin-left: 0; }
            .mobile-page .admin-nav-account a { display: block; width: 100%; text-align: center; }
            .mobile-page section,
            .mobile-page aside > div { border-radius: 1.25rem; padding: 1rem; }
            .mobile-page h1 { font-size: 1.35rem; }
            .mobile-page h2 { font-size: 1.15rem; }
            .mobile-page h3 { font-size: 1rem; }
            .mobile-page form input,
            .mobile-page form select,
            .mobile-page form button { min-height: 2.75rem; max-width: 100%; min-width: 0; }
            .mobile-page input,
            .mobile-page select { max-width: 100%; min-width: 0; }
            .mobile-page .grid { min-width: 0; }
            .dashboard-hero { padding: 1.25rem !important; }
            .dashboard-quick-actions { grid-template-columns: 1fr; }
        }
        .dashboard-card {
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
        }
        .dashboard-card:hover {
            transform: translateY(-2px);
            border-color: #bfdbfe;
            box-shadow: 0 16px 30px rgba(15, 23, 42, .09);
        }
        .dashboard-profile {
            border-color: #bfdbfe !important;
            background: linear-gradient(145deg, #eff6ff, #ffffff) !important;
        }
        .dashboard-menu {
            border-color: #c4b5fd !important;
            background: linear-gradient(145deg, #f5f3ff, #ffffff) !important;
        }
        .dashboard-alerts {
            border-color: #fecaca !important;
            background: linear-gradient(145deg, #fff1f2, #ffffff) !important;
        }
        .dashboard-stat-course {
            border-color: #bfdbfe !important;
            background: linear-gradient(145deg, #eff6ff, #ffffff) !important;
        }
        .dashboard-stat-session {
            border-color: #99f6e4 !important;
            background: linear-gradient(145deg, #ecfdf5, #ffffff) !important;
        }
        .dashboard-stat-risk {
            border-color: #fde68a !important;
            background: linear-gradient(145deg, #fffbeb, #ffffff) !important;
        }
        .dashboard-stat-system {
            border-color: #ddd6fe !important;
            background: linear-gradient(145deg, #f5f3ff, #ffffff) !important;
        }
        .dashboard-form-course {
            border-color: #bfdbfe !important;
            background: linear-gradient(145deg, #eff6ff, #ffffff) !important;
        }
        .dashboard-form-session {
            border-color: #99f6e4 !important;
            background: linear-gradient(145deg, #ecfdf5, #ffffff) !important;
        }
        .dashboard-sessions {
            border-color: #c4b5fd !important;
            background: linear-gradient(145deg, #f5f3ff, #ffffff) !important;
        }
        .dashboard-maintenance {
            border-color: #fed7aa !important;
            background: linear-gradient(145deg, #fff7ed, #fff1f2) !important;
        }
        .dashboard-session-item {
            border-color: #c7d2fe !important;
            background: linear-gradient(100deg, #eef2ff, #ffffff, #ecfeff) !important;
        }
        .dashboard-empty {
            border-color: #cbd5e1 !important;
            background: linear-gradient(145deg, #f8fafc, #eff6ff) !important;
        }
        .dashboard-action {
            display: flex;
            align-items: center;
            gap: .75rem;
            border-radius: 1rem;
            padding: .75rem 1rem;
            font-size: .875rem;
            font-weight: 600;
            text-decoration: none;
            box-shadow: 0 4px 10px rgba(15, 23, 42, .08);
            transition: filter .2s ease, transform .2s ease;
        }
        .dashboard-action:hover {
            filter: brightness(.96);
            transform: translateY(-1px);
        }
        .dashboard-action-course {
            background: #1d4ed8 !important;
            color: #ffffff !important;
        }
        .dashboard-action-session {
            background: #059669 !important;
            color: #ffffff !important;
        }
        .dashboard-action-reports {
            border: 1px solid #fcd34d;
            background: #fffbeb !important;
            color: #92400e !important;
        }
        .dashboard-hero::after {
            content: '';
            position: absolute;
            width: 16rem;
            height: 16rem;
            right: -5rem;
            top: -6rem;
            border-radius: 9999px;
            background: rgba(125, 211, 252, .18);
            pointer-events: none;
        }
        .dashboard-stat {
            position: relative;
            overflow: hidden;
        }
        .dashboard-stat::after {
            content: '';
            position: absolute;
            width: 5rem;
            height: 5rem;
            right: -1.5rem;
            bottom: -2rem;
            border-radius: 9999px;
            background: rgba(219, 234, 254, .65);
        }
        .admin-nav {
            display: flex !important;
            align-items: center;
            gap: .5rem;
            min-height: 2.75rem;
        }
        .admin-nav-group {
            display: flex;
            flex-wrap: wrap;
            gap: .35rem;
        }
        .admin-nav-group a,
        .admin-nav-account a {
            display: inline-flex;
            align-items: center;
            min-height: 2.25rem;
        }
    </style>
    </head>
    <body class="mobile-page min-h-screen bg-[#f3f6fb] text-slate-800 antialiased">
    <?php render_scheduled_maintenance_popup($pdo); ?>
    <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
        <header class="mb-6 overflow-hidden rounded-[30px] bg-gradient-to-r from-[#071b2f] via-[#102b4d] to-[#1d4e89] text-white shadow-[0_18px_50px_rgba(10,31,64,0.28)]">
            <div class="flex flex-col gap-5 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-7">
                <div class="flex items-center gap-4">
                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white p-1.5 shadow-sm">
                        <img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="h-full w-full object-contain" />
                    </div>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.32em] text-sky-100/80">Campus admin</p>
                        <h1 class="text-2xl font-black tracking-tight">C-Attend</h1>
                        <p class="mt-1 text-xs text-sky-100/70"><?= date('l, F j, Y') ?></p>
                    </div>
                </div>

                <?php
                $current_page = basename($_SERVER['PHP_SELF']);
                function _nav_class($page, $current) {
                    if ($page === $current) return 'rounded-xl border border-white/25 bg-white/12 px-3 py-2 text-white font-semibold';
                    return 'rounded-xl border border-white/15 bg-white/8 px-3 py-2 text-white transition hover:bg-white/12';
                }
                ?>
                <nav class="admin-nav flex flex-wrap items-center gap-2 text-sm font-medium" aria-label="Admin navigation">
                    <div class="admin-nav-group flex flex-wrap gap-2">
                        <a href="students.php" class="<?= _nav_class('students.php', $current_page) ?>">Students</a>
                        <?php if (!$is_sub_admin): ?>
                            <a href="manage_admins.php" class="<?= _nav_class('manage_admins.php', $current_page) ?>">Sub-admins</a>
                        <?php endif; ?>
                        <a href="manual_attendance.php" class="<?= _nav_class('manual_attendance.php', $current_page) ?>">Manual Override</a>
                        <a href="portal_sessions.php" class="<?= _nav_class('portal_sessions.php', $current_page) ?>">Portal Sessions</a>
                        <?php if ($is_sub_admin): ?>
                            <a href="manual_attendance_status.php" class="<?= _nav_class('manual_attendance_status.php', $current_page) ?>">My submissions</a>
                        <?php endif; ?>
                        <a href="reports.php" class="<?= _nav_class('reports.php', $current_page) ?>">Reports</a>
                    </div>
                    <div class="admin-nav-account ml-auto">
                        <a href="logout.php" class="rounded-xl bg-white/10 px-3 py-2 text-xs font-semibold text-white transition hover:bg-white/20">Logout</a>
                    </div>
                </nav>
            </div>
        </header>

        <div class="grid gap-6 lg:grid-cols-[290px_minmax(0,1fr)]">
            <aside class="space-y-5">
                <div class="dashboard-card dashboard-profile rounded-[28px] border border-slate-200 bg-white p-5 shadow-[0_10px_24px_rgba(15,23,42,0.06)]">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-slate-400">Profile</p>
                            <h2 class="mt-1 text-lg font-bold text-slate-900"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Administrator') ?></h2>
                        </div>
                        <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.08em] text-emerald-700">Online</span>
                    </div>

                    <div class="mb-4 rounded-2xl bg-gradient-to-br from-slate-100 to-sky-50 p-4">
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Role</p>
                        <p class="mt-2 text-lg font-black text-slate-900">Class Rep</p>
                    </div>

                    <div class="space-y-3 text-sm text-slate-600">
                        <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-3 py-2.5">
                            <span>Courses</span>
                            <span class="font-semibold text-slate-900"><?= count($courses) ?></span>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-3 py-2.5">
                            <span>Sessions</span>
                            <span class="font-semibold text-slate-900"><?= count($active_sessions) ?></span>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-3 py-2.5">
                            <span>Alerts</span>
                            <span class="font-semibold text-slate-900"><?= count($admin_alerts) ?></span>
                        </div>
                    </div>
                </div>

                <div class="dashboard-card dashboard-menu rounded-[28px] border border-slate-200 bg-white p-5 shadow-[0_10px_24px_rgba(15,23,42,0.06)]">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-slate-400">Quick menu</p>
                    <nav class="mt-4 space-y-2 text-sm font-medium text-slate-600">
                        <a href="#overview" class="flex items-center justify-between rounded-2xl bg-[#edf4ff] px-3 py-2.5 text-[#123b6d]">
                            <span>Overview</span>
                            <span>→</span>
                        </a>
                        <a href="#add-course" class="flex items-center justify-between rounded-2xl px-3 py-2.5 transition hover:bg-slate-50">
                            <span>Add course</span>
                            <span>→</span>
                        </a>
                        <a href="#sessions" class="flex items-center justify-between rounded-2xl px-3 py-2.5 transition hover:bg-slate-50">
                            <span>Sessions</span>
                            <span>→</span>
                        </a>
                        <a href="reports.php" class="flex items-center justify-between rounded-2xl px-3 py-2.5 transition hover:bg-slate-50">
                            <span>Reports</span>
                            <span>→</span>
                        </a>
                    </nav>
                </div>

                <div class="dashboard-card dashboard-alerts rounded-[28px] border border-slate-200 bg-white p-5 shadow-[0_10px_24px_rgba(15,23,42,0.06)]">
                    <div class="mb-4 flex items-center justify-between">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-slate-400">System alerts</p>
                        <span class="rounded-full bg-red-100 px-2 py-1 text-[10px] font-semibold text-red-700"><?= count($admin_alerts) ?></span>
                    </div>

                    <?php if (empty($admin_alerts)): ?>
                        <p class="text-sm text-slate-500">No active issues detected.</p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($admin_alerts as $alert): ?>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500"><?= htmlspecialchars($alert['source']) ?></span>
                                        <span class="rounded-full bg-<?= $alert['severity'] === 'critical' ? 'red' : ($alert['severity'] === 'high' ? 'amber' : 'sky') ?>-100 px-2 py-1 text-[10px] font-semibold text-<?= $alert['severity'] === 'critical' ? 'red' : ($alert['severity'] === 'high' ? 'amber' : 'sky') ?>-700"><?= htmlspecialchars($alert['severity']) ?></span>
                                    </div>
                                    <p class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars($alert['title']) ?></p>
                                    <p class="mt-1 text-xs text-slate-600"><?= htmlspecialchars($alert['message']) ?></p>
                                    <p class="mt-2 text-[10px] uppercase tracking-[0.12em] text-slate-400"><?= date('M d, Y h:i A', strtotime($alert['created_at'])) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </aside>

            <main id="overview" class="space-y-6">
                    <?php if ($scheduled_maintenance): ?>
                        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 shadow-sm">
                            <p class="font-bold">Scheduled maintenance</p>
                            <p class="mt-1">The portal will be temporarily unavailable from <?= htmlspecialchars(date('M j, Y h:i A', strtotime($scheduled_maintenance['start']))) ?><?= $scheduled_maintenance['end'] ? ' to ' . htmlspecialchars(date('M j, Y h:i A', strtotime($scheduled_maintenance['end']))) : '' ?>.</p>
                        </div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                    <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-700 shadow-sm"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800 shadow-sm"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                        <section class="dashboard-hero relative overflow-hidden rounded-[30px] border border-[#cfe0f5] bg-gradient-to-r from-[#dbeafe] via-[#f8fbff] to-[#dff7f2] p-5 text-slate-800 shadow-[0_14px_30px_rgba(15,23,42,0.08)] sm:p-8">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-sky-700">Academic operations</p>
                                    <h2 class="mt-2 text-3xl font-black tracking-tight text-slate-900"><?= $greeting ?>, <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Administrator') ?></h2>
                                    <p class="mt-2 text-lg font-semibold text-[#1d4e89]">Campus attendance overview</p>
                                    <p class="mt-2 max-w-xl text-sm leading-6 text-slate-600">Manage courses, launch class sessions, and monitor live attendance from one focused workspace.</p>
                        </div>
                                <div class="relative z-10 min-w-[190px] rounded-2xl border border-[#cfe0f5] bg-white/85 px-5 py-4 shadow-sm">
                                    <p class="text-[10px] uppercase tracking-[0.18em] text-slate-500">Today</p>
                                    <p class="mt-1 text-xl font-black text-slate-900"><?= date('M j, Y') ?></p>
                                    <div class="mt-3 flex items-center gap-2 text-xs text-slate-600">
                                        <span class="h-2 w-2 rounded-full <?= count($active_sessions) > 0 ? 'bg-emerald-500' : 'bg-slate-400' ?>"></span>
                                        <?= count($active_sessions) > 0 ? 'Attendance is live' : 'No live session' ?>
                                    </div>
                                </div>
                    </div>
                </section>

                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="dashboard-card dashboard-stat dashboard-stat-course rounded-[26px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.05)]">
                        <div class="flex items-center justify-between">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Courses</p>
                            <span class="rounded-full bg-indigo-100 px-2 py-1 text-[10px] font-semibold text-indigo-700">Catalog</span>
                        </div>
                        <div class="mt-4 flex items-end justify-between gap-3">
                            <span class="text-3xl font-black text-slate-900"><?= count($courses) ?></span>
                            <span class="text-xs text-slate-500">total</span>
                        </div>
                    </div>

                    <div class="dashboard-card dashboard-stat dashboard-stat-session rounded-[26px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.05)]">
                        <div class="flex items-center justify-between">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Live sessions</p>
                            <span class="rounded-full bg-emerald-100 px-2 py-1 text-[10px] font-semibold text-emerald-700">Open</span>
                        </div>
                        <div class="mt-4 flex items-end justify-between gap-3">
                            <span class="text-3xl font-black text-slate-900"><?= count($active_sessions) ?></span>
                            <span class="text-xs text-slate-500">active</span>
                        </div>
                    </div>

                    <div class="dashboard-card dashboard-stat dashboard-stat-risk rounded-[26px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.05)]">
                        <div class="flex items-center justify-between">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Attendance risk</p>
                            <span class="rounded-full px-2 py-1 text-[10px] font-semibold <?= $risk_badge_class ?>"><?= $risk_label ?></span>
                        </div>
                        <div class="mt-4 flex items-end justify-between gap-3">
                            <span class="text-3xl font-black text-slate-900"><?= $high_risk_students ?></span>
                            <span class="text-right text-xs text-slate-500"><?= $risk_rate ?>% of <?= $tracked_students ?> tracked</span>
                        </div>
                    </div>

                    <div class="dashboard-card dashboard-stat dashboard-stat-system rounded-[26px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.05)]">
                        <div class="flex items-center justify-between">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">System</p>
                            <span class="rounded-full bg-sky-100 px-2 py-1 text-[10px] font-semibold text-sky-700">Healthy</span>
                        </div>
                        <div class="mt-4">
                            <p class="text-lg font-black text-slate-900">24/7</p>
                            <p class="text-xs text-slate-500">Monitoring active</p>
                        </div>
                    </div>
                </section>

                <div class="grid gap-6 xl:grid-cols-[1fr_1.3fr]">
                    <section id="add-course" class="dashboard-form-course rounded-[30px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.06)] sm:p-5">
                        <div class="mb-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-sky-700">Catalog</p>
                            <h2 class="mt-1 text-xl font-black text-slate-900">Add new course</h2>
                        </div>
                        <form method="POST" class="space-y-3">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('admin_add_course') ?>">
                            <input type="text" name="course_code" placeholder="Course code (e.g. BIT 236)" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm uppercase text-slate-700 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                            <input type="text" name="course_title" placeholder="Course title" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                            <div class="grid grid-cols-2 gap-2">
                                <input type="text" name="semester" placeholder="Semester" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm uppercase text-slate-700 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                                <input type="text" name="academic_year" placeholder="2026/2027" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                            </div>
                            <button type="submit" name="btn_add_course" class="w-full rounded-xl bg-gradient-to-r from-[#1d4e89] to-[#3b82f6] px-4 py-3 text-sm font-semibold text-white shadow-[0_12px_20px_rgba(29,78,137,0.2)] transition hover:opacity-95">Add course</button>
                        </form>
                    </section>

                    <section id="launch-session" class="dashboard-form-session rounded-[30px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.06)] sm:p-5">
                        <div class="mb-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-sky-700">Session</p>
                            <h2 class="mt-1 text-xl font-black text-slate-900">Launch attendance session</h2>
                        </div>
                        <form method="POST" id="admin-session-form" class="space-y-3" onsubmit="return validateAdminLocationCapture()">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('admin_start_session') ?>">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">Select course</label>
                                <select name="course_id" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                                    <option value="">-- Select course --</option>
                                    <?php foreach ($courses as $c): ?>
                                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['course_code']) ?> - <?= htmlspecialchars($c['course_title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">Attendance class</label>
                                <select name="target_class" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                                    <?php if ($is_sub_admin): ?>
                                        <option value="<?= htmlspecialchars($admin_class) ?>">Class <?= htmlspecialchars($admin_class) ?> only</option>
                                    <?php else: ?>
                                        <option value="both">Class A and Class B</option>
                                        <option value="A">Class A only</option>
                                        <option value="B">Class B only</option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="grid gap-2 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">Latitude</label>
                                    <input type="text" name="latitude" id="admin_lat" readonly required placeholder="Lat" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-xs font-mono text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">Longitude</label>
                                    <input type="text" name="longitude" id="admin_lng" readonly required placeholder="Lng" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-xs font-mono text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                                </div>
                            </div>

                            <button type="button" onclick="getAdminLocation()" class="w-full rounded-xl border border-sky-200 bg-sky-50 px-4 py-2.5 text-xs font-semibold text-sky-700 transition hover:bg-sky-100">
                                 Capture fresh location
                            </button>
                            <p id="admin-location-status" class="text-xs text-slate-500" role="status">Capture your current GPS location before starting a session.</p>

                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">Allowed radius (meters)</label>
                                <p class="mb-2 text-xs text-slate-500">Use 300m for a typical classroom block; increase it for larger campus areas.</p>
                                <input type="number" name="radius_meters" value="<?= $default_geofence_radius ?>" min="50" max="2000" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                            </div>

                            <div class="grid gap-2 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">Start time</label>
                                    <input type="datetime-local" name="start_time" value="<?= $default_start ?>" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-2 py-2.5 text-xs text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">End time</label>
                                    <input type="datetime-local" name="end_time" value="<?= $default_end ?>" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-2 py-2.5 text-xs text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                                </div>
                            </div>

                            <button type="submit" name="btn_start_session" class="w-full rounded-xl bg-gradient-to-r from-[#1d4e89] to-[#3b82f6] px-4 py-3 text-sm font-semibold text-white shadow-[0_12px_20px_rgba(29,78,137,0.2)] transition hover:opacity-95">
                                 Start geofenced session
                            </button>
                        </form>
                    </section>
                </div>

                <section id="sessions" class="dashboard-sessions rounded-[30px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.06)] sm:p-5">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-sky-700">Operations</p>
                            <h2 class="text-xl font-black text-slate-900">Active sessions</h2>
                        </div>
                    </div>

                    <?php if (empty($active_sessions)): ?>
                        <div class="dashboard-empty rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-500">
                            No active session is running right now.
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($active_sessions as $as): ?>
                                <div class="dashboard-session-item flex flex-col gap-4 rounded-[24px] border border-slate-200 bg-gradient-to-r from-slate-50 via-white to-indigo-50 p-4 md:flex-row md:items-center md:justify-between">
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-bold text-[#1d4e89] bg-[#eaf0ff] px-2 py-0.5 rounded-md"><?= htmlspecialchars($as['course_code']) ?></span>
                                            <span class="text-xs font-semibold text-emerald-700 bg-emerald-100 px-2 py-0.5 rounded-md animate-pulse">Live</span>
                                        </div>
                                        <h3 class="font-bold text-slate-800 text-base"><?= htmlspecialchars($as['course_title']) ?></h3>
                                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-sky-700">Class <?= htmlspecialchars($as['target_class'] === 'both' ? 'A & B' : $as['target_class']) ?></p>
                                        <p class="text-xs text-slate-500 font-mono">GPS: <?= $as['latitude'] ?>, <?= $as['longitude'] ?> (<?= $as['radius_meters'] ?>m radius)</p>
                                        <p class="text-xs text-slate-500">
                                            Time: <?= date('h:i A', strtotime($as['start_time'])) ?> — <?= date('h:i A', strtotime($as['end_time'])) ?>
                                        </p>
                                    </div>
                                    <div class="flex flex-col items-start gap-2 md:items-end">
                                        <span id="attendance_count_<?= $as['id'] ?>" class="text-xs font-bold text-slate-600 bg-white px-3 py-1 rounded-full border border-slate-200">
                                            <?= $as['total_attended'] ?> student(s) marked
                                        </span>
                                        <a href="session_attendance.php?session_id=<?= (int)$as['id'] ?>" class="text-xs font-semibold text-[#1d4e89] hover:text-blue-700">
                                            View marked students
                                        </a>
                                        <a href="dashboard.php?end_session_id=<?= $as['id'] ?>" onclick="return confirm('End this session now?')" class="text-xs bg-red-600 hover:bg-red-700 text-white font-semibold px-3 py-1.5 rounded-lg transition-all">
                                            End session
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="dashboard-maintenance rounded-[30px] border border-red-100 bg-gradient-to-r from-red-50 to-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.06)] sm:p-5">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-red-100 pb-3">
                        <div>
                            <h2 class="text-lg font-bold text-red-600">🧹 End of semester maintenance</h2>
                            <p class="text-xs text-slate-500">Archive or wipe course rosters to prepare for a new academic term</p>
                        </div>
                    </div>

                    <form method="POST" action="clear_courses.php" onsubmit="return confirmSemesterClear(event)" class="mt-4 space-y-4">
                        <input type="hidden" name="action_clear_courses" value="1">

                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 hover:border-indigo-300">
                                <input type="radio" name="clear_type" value="archive" checked class="mt-1 text-indigo-600 focus:ring-indigo-500">
                                <div>
                                    <span class="block text-xs font-bold text-slate-800">Archive current courses (recommended)</span>
                                    <span class="mt-1 block text-xs text-slate-500">Deactivates all courses and active sessions. Historical logs remain available in reports.</span>
                                </div>
                            </label>

                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-red-200 bg-red-50/50 p-4 hover:border-red-300">
                                <input type="radio" name="clear_type" value="purge" class="mt-1 text-red-600 focus:ring-red-500">
                                <div>
                                    <span class="block text-xs font-bold text-red-700">Wipe all courses & attendance logs</span>
                                    <span class="mt-1 block text-xs text-red-600/80">Permanently deletes all courses, sessions, and student attendance records. Cannot be undone.</span>
                                </div>
                            </label>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="rounded-lg bg-red-600 px-5 py-2.5 text-xs font-semibold text-white shadow-sm transition hover:bg-red-700">
                                Execute semester reset
                            </button>
                        </div>
                    </form>
                </section>
            </main>
        </div>
    </div>

    <script>
    function confirmSemesterClear(e) {
        const selectedType = document.querySelector('input[name="clear_type"]:checked').value;
        if (selectedType === 'purge') {
            return confirm("WARNING: You are about to PERMANENTLY DELETE all courses and attendance records!\n\nAre you sure you want to proceed?");
        }
        return confirm("Are you sure you want to archive all courses for the new semester?");
    }

    function getAdminLocation() {
        const latitude = document.getElementById('admin_lat');
        const longitude = document.getElementById('admin_lng');
        const status = document.getElementById('admin-location-status');
        const button = document.querySelector('#admin-session-form button[onclick="getAdminLocation()"]');
        window.adminLocationCaptured = false;
        latitude.value = '';
        longitude.value = '';
        if (status) {
            status.textContent = 'Getting a fresh GPS location...';
            status.className = 'text-xs text-sky-700';
        }
        if (button) {
            button.disabled = true;
            button.textContent = 'Getting fresh location...';
        }
        const resetButton = () => {
            if (button) {
                button.disabled = false;
                button.textContent = 'Capture fresh location';
            }
        };

        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const readingAge = Date.now() - position.timestamp;
                    if (!Number.isFinite(readingAge) || readingAge < -5000 || readingAge > 30000) {
                        resetButton();
                        if (status) {
                            status.textContent = 'GPS reading was not fresh. Please capture your location again.';
                            status.className = 'text-xs text-red-600';
                        }
                        return;
                    }
                    latitude.value = position.coords.latitude;
                    longitude.value = position.coords.longitude;
                    window.adminLocationCaptured = true;
                    if (status) {
                        status.textContent = 'Fresh location captured. You can start the session.';
                        status.className = 'text-xs text-emerald-700';
                    }
                    resetButton();
                },
                (error) => {
                    if (status) {
                        status.textContent = 'Location capture failed. Allow GPS access and try again.';
                        status.className = 'text-xs text-red-600';
                    }
                    alert("GPS Error: " + error.message);
                    resetButton();
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
            );
        } else {
            if (status) {
                status.textContent = 'This browser does not support GPS location.';
                status.className = 'text-xs text-red-600';
            }
            alert("Geolocation is not supported by your browser.");
            resetButton();
        }
    }

    function validateAdminLocationCapture() {
        if (!window.adminLocationCaptured) {
            alert('Capture a fresh GPS location before starting this attendance session.');
            return false;
        }
        return true;
    }

    window.addEventListener('pageshow', () => {
        window.adminLocationCaptured = false;
        document.getElementById('admin_lat').value = '';
        document.getElementById('admin_lng').value = '';
    });

    async function refreshAttendanceCounts() {
        try {
            const response = await fetch('live_attendance.php', {
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            Object.entries(data.sessions || {}).forEach(([sessionId, count]) => {
                const counter = document.getElementById('attendance_count_' + sessionId);
                if (counter) {
                    counter.textContent = count + ' student(s) marked';
                }
            });
        } catch (error) {
            console.error('Live attendance refresh failed:', error);
        }
    }

    refreshAttendanceCounts();
    window.setInterval(refreshAttendanceCounts, 5000);
    </script>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>