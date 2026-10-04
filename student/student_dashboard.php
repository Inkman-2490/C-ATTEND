<?php
// student_dashboard.php
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once '../includes/portal_sessions.php';

ensure_portal_alert_table($pdo);
ensure_class_columns($pdo);
ensure_maintenance_tables($pdo);
ensure_attendance_approval_columns($pdo);
expire_attendance_sessions($pdo);
ensure_portal_only_session_tables($pdo);
finalize_and_expire_portal_only_sessions($pdo);
enforce_portal_maintenance($pdo, 'student portal');

$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];

date_default_timezone_set('Africa/Accra');

if (!isset($_SESSION['student_id'])) {
    header("Location: ../index.php");
    exit;
}

$student_id = $_SESSION['student_id'];
$error = '';
$success = $_SESSION['student_flash_success'] ?? '';
unset($_SESSION['student_flash_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_mark_attendance'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'student_attendance')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $session_id = (int)($_POST['session_id'] ?? 0);
        $gps_reading = parse_attendance_gps_reading($_POST);

        if ($gps_reading === null) {
            $error = 'GPS reading is invalid. Please capture a fresh location and try again.';
        } elseif ($session_id <= 0) {
            $error = 'Invalid attendance session selection.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM attendance_sessions WHERE id = ? AND is_active = 1 AND created_at >= CURRENT_DATE() AND end_time > NOW()");
            $stmt->execute([$session_id]);
            $session = $stmt->fetch();

            if (!$session) {
                $error = 'This attendance session is no longer active.';
            } else {
                $current_time = time();
                $start_time = strtotime($session['start_time']);
                $end_time = strtotime($session['end_time']);

                if ($current_time < $start_time) {
                    $error = 'Session has not started yet. Starts at: ' . date('h:i A', $start_time);
                } elseif ($current_time > $end_time) {
                    $error = 'Session time window has expired. Ended at: ' . date('h:i A', $end_time);
                } else {
                    $stmt = $pdo->prepare('SELECT id FROM attendance_records WHERE session_id = ? AND student_id = ?');
                    $stmt->execute([$session_id, $student_id]);
                    if ($stmt->fetch()) {
                        $error = 'You have already marked attendance for this session.';
                    } else {
                        $distance = calculate_gps_distance_meters(
                            (float)$session['latitude'],
                            (float)$session['longitude'],
                            $gps_reading['latitude'],
                            $gps_reading['longitude']
                        );
                        $accuracy_tolerance = min(max($gps_reading['accuracy'], 25), 200);
                        $effective_distance = max(0, $distance - $accuracy_tolerance);

                        if ($effective_distance <= $session['radius_meters']) {
                            $insert = $pdo->prepare("INSERT INTO attendance_records (session_id, student_id, marked_by, distance_meters) VALUES (?, ?, 'self', ?)");
                            if ($insert->execute([$session_id, $student_id, round($distance, 2)])) {
                                $_SESSION['student_flash_success'] = 'Attendance successfully verified! (Distance: ' . round($distance, 1) . 'm)';
                                header('Location: student_dashboard.php');
                                exit;
                            }
                            $error = 'Failed to record attendance. Please try again.';
                            record_portal_alert($pdo, 'Attendance insertion failed', 'Student attendance record insert failed for student_id ' . $student_id . ' in session_id ' . $session_id, 'high', 'student', 'Database insert error');
                        } else {
                            $error = 'Location Verification Failed: You are ' . round($distance, 1) . 'm away (adjusted: ' . round($effective_distance, 1) . 'm). Must be within ' . $session['radius_meters'] . 'm of Class Rep.';
                            record_portal_alert(
                                $pdo,
                                'Student attendance mismatch',
                                'Student ' . $student_id . ' failed geofencing check by ' . round($distance, 1) . 'm during session ' . $session_id,
                                'medium',
                                'student',
                                'Location verification failed: measured distance ' . round($distance, 1) . 'm (adjusted ' . round($effective_distance, 1) . 'm) exceeded the allowed radius of ' . (int)$session['radius_meters'] . 'm.'
                            );
                        }
                    }
                }
            }
        }
    }
}

$active_sessions_stmt = $pdo->prepare("
    SELECT s.*, c.course_code, c.course_title 
    FROM attendance_sessions s 
    JOIN courses c ON s.course_id = c.id 
    WHERE s.is_active = 1
      AND s.end_time >= NOW()
      AND s.created_at >= CURRENT_DATE()
      AND (s.target_class = ? OR s.target_class = 'both')
      AND NOT EXISTS (
          SELECT 1
          FROM attendance_records marked
          WHERE marked.session_id = s.id
            AND marked.student_id = ?
      )
    ORDER BY s.id DESC
");
$active_sessions_stmt->execute([$_SESSION['student_class'] ?? 'A', $student_id]);
$active_sessions = $active_sessions_stmt->fetchAll();

$attendance_rate_stmt = $pdo->prepare("
    SELECT
        COUNT(s.id) AS completed_sessions,
        COUNT(r.id) AS attended_sessions
    FROM attendance_sessions s
    LEFT JOIN attendance_records r
        ON r.session_id = s.id
       AND r.student_id = ?
       AND r.approval_status = 'approved'
    WHERE s.start_time <= NOW()
      AND s.end_time <= NOW()
      AND (s.target_class = ? OR s.target_class = 'both')
");
$attendance_rate_stmt->execute([$student_id, $_SESSION['student_class'] ?? 'A']);
$attendance_rate = $attendance_rate_stmt->fetch() ?: ['completed_sessions' => 0, 'attended_sessions' => 0];
$completed_sessions = (int)$attendance_rate['completed_sessions'];
$attended_sessions = (int)$attendance_rate['attended_sessions'];
$attendance_percentage = $completed_sessions > 0
    ? (int)round(($attended_sessions / $completed_sessions) * 100)
    : 0;

$history_stmt = $pdo->prepare("
    SELECT r.marked_at, r.distance_meters, r.marked_by, c.course_code, c.course_title, s.start_time
    FROM attendance_records r
    JOIN attendance_sessions s ON r.session_id = s.id
    JOIN courses c ON s.course_id = c.id
    WHERE r.student_id = ?
      AND r.approval_status = 'approved'
    UNION ALL
    SELECT NULL AS marked_at, NULL AS distance_meters, 'absent' AS marked_by,
           c.course_code, c.course_title, s.start_time
    FROM attendance_sessions s
    JOIN courses c ON s.course_id = c.id
    WHERE s.start_time <= NOW()
      AND s.end_time <= NOW()
      AND (s.target_class = ? OR s.target_class = 'both')
      AND NOT EXISTS (
          SELECT 1
          FROM attendance_records missed
          WHERE missed.session_id = s.id
            AND missed.student_id = ?
            AND missed.approval_status = 'approved'
      )
    ORDER BY start_time DESC, marked_at DESC
");
$history_stmt->execute([$student_id, $_SESSION['student_class'] ?? 'A', $student_id]);
$attendance_history = $history_stmt->fetchAll();
$current_hour = (int)date('G');
$greeting = $current_hour < 12 ? 'Good morning' : ($current_hour < 18 ? 'Good afternoon' : 'Good evening');
$student_name = $_SESSION['student_name'] ?? 'Student';
$scheduled_maintenance = get_scheduled_maintenance($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <link rel="apple-touch-icon" href="../assets/logo-dashboard.png">
    <title>C-Attend - Student Dashboard</title>
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
            .mobile-page header .items-center.gap-3 { width: 100%; flex-wrap: wrap; }
            .mobile-page header .items-center.gap-3 > div { min-width: 0; flex: 1 1 10rem; }
            .mobile-page header .items-center.gap-3 a { flex: 0 0 auto; }
            .mobile-page section,
            .mobile-page aside > div { border-radius: 1.25rem; padding: 1rem; }
            .mobile-page h1 { font-size: 1.35rem; }
            .mobile-page h2 { font-size: 1.15rem; }
            .mobile-page h3 { font-size: 1rem; }
            .mobile-page table { min-width: 580px; }
            .mobile-page .overflow-x-auto { margin-left: -0.25rem; margin-right: -0.25rem; }
            .mobile-page input,
            .mobile-page select,
            .mobile-page button { max-width: 100%; min-width: 0; }
            .mobile-page .grid { min-width: 0; }
        }
    </style>
    </head>
    <body class="mobile-page min-h-screen bg-[#f3f6fb] text-slate-800 antialiased">
<div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
    <header class="mb-6 overflow-hidden rounded-[30px] bg-gradient-to-r from-[#071b2f] via-[#102b4d] to-[#1d4e89] text-white shadow-[0_18px_50px_rgba(10,31,64,0.28)]">
        <div class="flex flex-col gap-5 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-7">
            <div class="flex items-center gap-4">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white p-1.5 shadow-sm">
                    <img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="h-full w-full object-contain" />
                </div>
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.32em] text-sky-100/80">Campus portal</p>
                    <h1 class="text-2xl font-black tracking-tight">C-Attend</h1>
                </div>
            </div>

            <div class="flex min-w-0 items-center gap-3">
                <div class="min-w-0 max-w-[min(18rem,65vw)] rounded-2xl border border-white/15 bg-white/8 px-3 py-2 text-left backdrop-blur-sm">
                    <p class="text-[10px] uppercase tracking-[0.22em] text-sky-100/80">Student</p>
                    <p class="break-words text-sm font-semibold leading-5 text-white"><?= htmlspecialchars($_SESSION['student_name'] ?? 'Student') ?></p>
                </div>
                <a href="logout.php" class="inline-flex shrink-0 items-center justify-center rounded-xl border border-white/15 bg-white/8 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/12">Logout</a>
            </div>
        </div>
    </header>

    <div class="grid gap-6 lg:grid-cols-[290px_minmax(0,1fr)]">
        <aside class="space-y-5">
            <div class="rounded-[28px] border border-slate-200 bg-white p-5 shadow-[0_10px_24px_rgba(15,23,42,0.06)]">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-slate-400">Profile</p>
                        <h2 class="mt-1 break-words text-lg font-bold leading-6 text-slate-900"><?= htmlspecialchars($_SESSION['student_name'] ?? 'Student') ?></h2>
                    </div>
                    <span class="shrink-0 rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.08em] text-emerald-700">Online</span>
                </div>

                <div class="mb-4 rounded-2xl bg-gradient-to-br from-slate-100 to-sky-50 p-4">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Student ID</p>
                    <p class="mt-2 text-lg font-black text-slate-900"><?= htmlspecialchars($_SESSION['student_index'] ?? 'N/A') ?></p>
                </div>

                <div class="space-y-3 text-sm text-slate-600">
                    <div class="flex items-start justify-between gap-3 rounded-2xl bg-slate-50 px-3 py-2.5">
                        <span>Index number</span>
                        <span class="break-all text-right font-semibold text-slate-900"><?= htmlspecialchars($_SESSION['index_number'] ?? $_SESSION['student_index'] ?? 'N/A') ?></span>
                    </div>
                    <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-3 py-2.5">
                        <span>Class</span>
                        <span class="font-semibold text-slate-900">Class <?= htmlspecialchars($_SESSION['student_class'] ?? 'A') ?></span>
                    </div>
                    <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-3 py-2.5">
                        <span>Courses</span>
                        <span class="font-semibold text-slate-900"><?= count($active_sessions) ?: 0 ?></span>
                    </div>
                </div>
            </div>

            <div class="rounded-[28px] border border-slate-200 bg-white p-5 shadow-[0_10px_24px_rgba(15,23,42,0.06)]">
                <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-slate-400">Quick menu</p>
                <nav class="mt-4 space-y-2 text-sm font-medium text-slate-600">
                    <a href="#overview" class="flex items-center justify-between rounded-2xl bg-[#edf4ff] px-3 py-2.5 text-[#123b6d]">
                        <span>Overview</span>
                        <span>→</span>
                    </a>
                    <a href="student_mark_attendance.php" class="flex items-center justify-between rounded-2xl px-3 py-2.5 transition hover:bg-slate-50">
                        <span>Attendance</span>
                        <span>→</span>
                    </a>
                    <a href="student_history.php" class="flex items-center justify-between rounded-2xl px-3 py-2.5 transition hover:bg-slate-50">
                        <span>History</span>
                        <span>→</span>
                    </a>
                </nav>
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

            <section class="rounded-[30px] border border-[#cfe0f5] bg-gradient-to-r from-[#dbeafe] via-[#f8fbff] to-[#dff7f2] p-5 shadow-[0_14px_30px_rgba(15,23,42,0.08)] sm:p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-700"><?= $greeting ?></p>
                        <h2 class="mt-2 text-2xl font-black text-slate-900">Welcome back, <?= htmlspecialchars($student_name) ?>.</h2>
                        <p class="mt-1 text-lg font-semibold text-[#1d4e89]">Your academic dashboard is ready.</p>
                        <p class="mt-2 max-w-xl text-sm text-slate-600">Keep your attendance on track and mark your live class sessions with a quick location check.</p>
                    </div>
                    <div class="rounded-2xl border border-[#cfe0f5] bg-white/85 px-4 py-3 shadow-sm">
                        <p class="text-[10px] uppercase tracking-[0.18em] text-slate-500">Today</p>
                        <p class="mt-1 text-xl font-black text-slate-900"><?= !empty($active_sessions) ? htmlspecialchars($active_sessions[0]['course_code']) : 'No class' ?></p>
                        <p class="text-xs text-slate-500"><?= !empty($active_sessions) ? date('h:i A', strtotime($active_sessions[0]['start_time'])) : 'Open schedule' ?></p>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-[26px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.05)]">
                    <div class="flex items-center justify-between">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Active</p>
                        <span class="rounded-full bg-indigo-100 px-2 py-1 text-[10px] font-semibold text-indigo-700">Live</span>
                    </div>
                    <div class="mt-4 flex items-end justify-between gap-3">
                        <span class="text-3xl font-black text-slate-900"><?= count($active_sessions) ?></span>
                        <span class="text-xs text-slate-500">sessions</span>
                    </div>
                </div>

                <div class="rounded-[26px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.05)]">
                    <div class="flex items-center justify-between">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Marked</p>
                        <span class="rounded-full bg-emerald-100 px-2 py-1 text-[10px] font-semibold text-emerald-700">Today</span>
                    </div>
                    <div class="mt-4 flex items-end justify-between gap-3">
                        <span class="text-3xl font-black text-slate-900"><?= count($attendance_history) ?></span>
                        <span class="text-xs text-slate-500">records</span>
                    </div>
                </div>

                <div class="rounded-[26px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.05)]">
                    <div class="flex items-center justify-between">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Rate</p>
                        <span class="rounded-full bg-amber-100 px-2 py-1 text-[10px] font-semibold text-amber-700">Target</span>
                    </div>
                    <div class="mt-4 flex items-end justify-between gap-3">
                        <span class="text-3xl font-black text-slate-900"><?= $attendance_percentage ?>%</span>
                        <span class="text-right text-xs text-slate-500"><?= $attended_sessions ?>/<?= $completed_sessions ?> attended</span>
                    </div>
                </div>

                <div class="rounded-[26px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.05)]">
                    <div class="flex items-center justify-between">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Next</p>
                        <span class="rounded-full bg-sky-100 px-2 py-1 text-[10px] font-semibold text-sky-700">Class</span>
                    </div>
                    <div class="mt-4">
                        <p class="text-lg font-black text-slate-900"><?= !empty($active_sessions) ? htmlspecialchars($active_sessions[0]['course_code']) : 'None' ?></p>
                        <p class="text-xs text-slate-500"><?= !empty($active_sessions) ? date('h:i A', strtotime($active_sessions[0]['start_time'])) : 'No session scheduled' ?></p>
                    </div>
                </div>
            </section>

            <section id="sessions" class="rounded-[30px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.06)] sm:p-5">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-sky-700">Live</p>
                        <h2 class="text-xl font-black text-slate-900">Attendance sessions</h2>
                    </div>
                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Open</span>
                </div>

                <?php if (empty($active_sessions)): ?>
                    <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-500">
                        No active attendance sessions right now. Check back later.
                    </div>
                <?php else: ?>
                    <div class="grid gap-4">
                        <?php foreach ($active_sessions as $sess): ?>
                            <div class="rounded-[24px] border border-slate-200 bg-gradient-to-r from-slate-50 via-white to-indigo-50 p-4 shadow-sm">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                    <div class="space-y-2">
                                        <div class="flex items-center gap-2">
                                            <span class="inline-block rounded-lg bg-[#eaf0ff] px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.12em] text-[#1d4e89]"><?= htmlspecialchars($sess['course_code']) ?></span>
                                            <span class="text-xs font-medium text-slate-500">Live class session</span>
                                        </div>
                                        <h3 class="text-lg font-bold text-slate-900"><?= htmlspecialchars($sess['course_title']) ?></h3>
                                        <p class="text-sm text-slate-600">
                                            <span class="font-medium">Time:</span> <?= date('h:i A', strtotime($sess['start_time'])) ?> - <?= date('h:i A', strtotime($sess['end_time'])) ?>
                                            <span class="mx-2 text-slate-300">|</span>
                                            <span class="font-medium">Location radius:</span> <span class="font-semibold text-[#1d4e89]"><?= $sess['radius_meters'] ?>m</span>
                                        </p>
                                    </div>

                                    <a href="student_mark_attendance.php" class="inline-flex w-full items-center justify-center rounded-xl bg-gradient-to-r from-[#1d4e89] to-[#3b82f6] px-5 py-2.5 text-sm font-semibold text-white shadow-[0_12px_20px_rgba(29,78,137,0.2)] transition hover:opacity-95 lg:w-auto">
                                        Open attendance page
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

        </main>
    </div>
</div>

<script>
function captureLocation(sessionId) {
    const btn = document.getElementById('btn_' + sessionId);
    document.getElementById('lat_' + sessionId).value = '';
    document.getElementById('lng_' + sessionId).value = '';
    document.getElementById('acc_' + sessionId).value = '';
    btn.disabled = true;
    btn.innerText = "Acquiring GPS...";

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (position) => {
                const {latitude, longitude, accuracy} = position.coords;
                const readingAge = Date.now() - position.timestamp;
                if (!Number.isFinite(latitude) || latitude < -90 || latitude > 90
                    || !Number.isFinite(longitude) || longitude < -180 || longitude > 180
                    || !Number.isFinite(accuracy) || accuracy < 0
                    || !Number.isFinite(readingAge) || readingAge < -5000 || readingAge > 30000) {
                    alert('GPS reading is invalid or not fresh enough. Enable location services and try again.');
                    btn.disabled = false;
                    btn.innerText = 'Verify location & mark attendance';
                    return;
                }

                document.getElementById('lat_' + sessionId).value = position.coords.latitude;
                document.getElementById('lng_' + sessionId).value = position.coords.longitude;
                document.getElementById('acc_' + sessionId).value = position.coords.accuracy;

                btn.innerText = "Submitting...";
                document.getElementById('attendanceForm_' + sessionId).submit();
            },
            (error) => {
                let msg = "GPS Error: ";
                switch(error.code) {
                    case error.PERMISSION_DENIED:
                        msg += "Location permission denied. Please enable location access in your browser settings.";
                        break;
                    case error.POSITION_UNAVAILABLE:
                        msg += "Location information unavailable.";
                        break;
                    case error.TIMEOUT:
                        msg += "GPS request timed out. Please try again.";
                        break;
                    default:
                        msg += error.message;
                }
                alert(msg);
                btn.disabled = false;
                btn.innerText = " Verify location & mark attendance";
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
    } else {
        alert("Geolocation is not supported by your browser.");
        btn.disabled = false;
        btn.innerText = " Verify location & mark attendance";
    }
}
</script>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>