<?php
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

if (!isset($_SESSION['student_id'])) {
    header('Location: ../index.php');
    exit;
}

$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];
date_default_timezone_set('Africa/Accra');
$student_id = (int)$_SESSION['student_id'];
$error = '';
$success = $_SESSION['student_flash_success'] ?? '';
unset($_SESSION['student_flash_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_mark_attendance'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'student_attendance')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $session_id = (int)($_POST['session_id'] ?? 0);
        $attendance_type = ($_POST['attendance_type'] ?? 'standard') === 'portal_only' ? 'portal_only' : 'standard';
        $gps_reading = parse_attendance_gps_reading($_POST);

        if ($gps_reading === null) {
            $error = 'GPS reading is invalid. Please capture a fresh location and try again.';
        } elseif ($session_id <= 0) {
            $error = 'Invalid attendance session selection.';
        } else {
            if ($attendance_type === 'portal_only') {
                $stmt = $pdo->prepare("
                    SELECT s.*
                    FROM portal_only_sessions s
                    WHERE s.id = ?
                      AND s.is_active = 1
                      AND s.start_time <= NOW()
                      AND s.end_time > NOW()
                      AND s.created_at >= CURRENT_DATE()
                      AND (s.target_class = ? OR s.target_class = 'both')
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT s.*
                    FROM attendance_sessions s
                    WHERE s.id = ?
                      AND s.is_active = 1
                      AND s.created_at >= CURRENT_DATE()
                      AND s.end_time > NOW()
                      AND (s.target_class = ? OR s.target_class = 'both')
                ");
            }
            $stmt->execute([$session_id, $_SESSION['student_class'] ?? 'A']);
            $session = $stmt->fetch();

            if (!$session) {
                $error = 'This attendance session is no longer active or is not assigned to your class.';
            } elseif (time() < strtotime($session['start_time'])) {
                $error = 'Session has not started yet. Starts at: ' . date('h:i A', strtotime($session['start_time']));
            } else {
                if ($attendance_type === 'portal_only') {
                    $stmt = $pdo->prepare('SELECT id FROM portal_only_attendance WHERE session_id = ? AND student_id = ?');
                } else {
                    $stmt = $pdo->prepare('SELECT id, approval_status FROM attendance_records WHERE session_id = ? AND student_id = ?');
                }
                $stmt->execute([$session_id, $student_id]);
                $existing_record = $stmt->fetch();
                if ($existing_record && ($attendance_type === 'portal_only' || $existing_record['approval_status'] !== 'rejected')) {
                    $error = 'You have already marked attendance for this session.';
                } else {
                    $raw_distance = calculate_gps_distance_meters(
                        (float)$session['latitude'],
                        (float)$session['longitude'],
                        $gps_reading['latitude'],
                        $gps_reading['longitude']
                    );
                    $accuracy_tolerance = min(max($gps_reading['accuracy'], 25), 200);
                    $effective_distance = max(0, $raw_distance - $accuracy_tolerance);

                    if ($effective_distance <= $session['radius_meters']) {
                        if ($attendance_type === 'portal_only') {
                            $student_stmt = $pdo->prepare('SELECT index_number, full_name FROM students WHERE id = ?');
                            $student_stmt->execute([$student_id]);
                            $student = $student_stmt->fetch();
                            if (!$student) {
                                throw new RuntimeException('Student profile not found while saving portal-only attendance.');
                            }
                            $save = $pdo->prepare('INSERT INTO portal_only_attendance (session_id, student_id, index_number, full_name, marked_at, distance_meters) VALUES (?, ?, ?, ?, NOW(), ?)');
                            $save->execute([$session_id, $student_id, $student['index_number'], $student['full_name'], round($raw_distance, 2)]);
                        } elseif ($existing_record) {
                            $save = $pdo->prepare("UPDATE attendance_records SET marked_at = NOW(), marked_by = 'self', approval_status = 'approved', approved_by = NULL, approved_at = NULL, submitted_by_admin_id = NULL, distance_meters = ? WHERE id = ?");
                            $save->execute([round($raw_distance, 2), (int)$existing_record['id']]);
                        } else {
                            $save = $pdo->prepare("INSERT INTO attendance_records (session_id, student_id, marked_by, distance_meters) VALUES (?, ?, 'self', ?)");
                            $save->execute([$session_id, $student_id, round($raw_distance, 2)]);
                        }
                        $_SESSION['student_flash_success'] = 'Attendance successfully verified! (Distance: ' . round($raw_distance, 1) . 'm)';
                        header('Location: student_attendance_success.php');
                        exit;
                    }
                    $error = 'Location verification failed. You are ' . round($raw_distance, 1) . 'm away; the allowed radius is ' . $session['radius_meters'] . 'm.';
                    record_portal_alert(
                        $pdo,
                        'Student attendance mismatch',
                        'Student ' . $student_id . ' failed geofencing check for session ' . $session_id,
                        'medium',
                        'student',
                        'Location verification failed: measured distance ' . round($raw_distance, 1) . 'm (adjusted ' . round($effective_distance, 1) . 'm) exceeded the allowed radius of ' . (int)$session['radius_meters'] . 'm.'
                    );
                }
            }
        }
    }
}

$stmt = $pdo->prepare("
    SELECT s.*, c.course_code, c.course_title
    FROM attendance_sessions s
    JOIN courses c ON c.id = s.course_id
    WHERE s.is_active = 1
      AND s.end_time >= NOW()
      AND s.created_at >= CURRENT_DATE()
      AND (s.target_class = ? OR s.target_class = 'both')
      AND NOT EXISTS (
          SELECT 1 FROM attendance_records r
          WHERE r.session_id = s.id AND r.student_id = ?
            AND r.approval_status <> 'rejected'
      )
    ORDER BY s.start_time ASC
");
$stmt->execute([$_SESSION['student_class'] ?? 'A', $student_id]);
$active_sessions = $stmt->fetchAll();
foreach ($active_sessions as &$active_session) {
    $active_session['attendance_type'] = 'standard';
    $active_session['attendance_key'] = 'standard-' . (int)$active_session['id'];
}
unset($active_session);
$portal_sessions_stmt = $pdo->prepare("
    SELECT s.id, s.course_code, s.course_title, s.target_class, s.latitude, s.longitude,
           s.radius_meters, s.start_time, s.end_time, 0 AS total_attended,
           'portal_only' AS attendance_type, CONCAT('portal-', s.id) AS attendance_key
    FROM portal_only_sessions s
    WHERE s.is_active = 1
      AND s.start_time <= NOW()
      AND s.end_time > NOW()
      AND s.created_at >= CURRENT_DATE()
      AND (s.target_class = ? OR s.target_class = 'both')
      AND NOT EXISTS (
          SELECT 1 FROM portal_only_attendance pa
          WHERE pa.session_id = s.id AND pa.student_id = ?
      )
    ORDER BY s.start_time ASC
");
$portal_sessions_stmt->execute([$_SESSION['student_class'] ?? 'A', $student_id]);
$active_sessions = array_merge($active_sessions, $portal_sessions_stmt->fetchAll(PDO::FETCH_ASSOC));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <title>Mark Attendance - C-Attend</title>
    <link rel="stylesheet" href="../assets/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-[#0f172a]/5 bg-gradient-to-b from-slate-100 to-slate-200/60 text-slate-800 antialiased">

    <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
        
        <!-- NAVBAR (PRESERVED EXACTLY AS PROVIDED) -->
        <header class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-3xl bg-gradient-to-r from-[#071b2f] via-[#102b4d] to-[#1d4e89] p-5 text-white shadow-lg">
            <div class="flex items-center gap-3">
                <img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="h-12 w-12 rounded-xl bg-white p-1 object-contain">
                <div><p class="text-xs uppercase tracking-[0.2em] text-sky-100/80">Student portal</p><h1 class="text-2xl font-black">Mark attendance</h1></div>
            </div>
            <a href="student_dashboard.php" class="rounded-xl bg-white/10 px-4 py-2 text-sm font-semibold hover:bg-white/20">Back to dashboard</a>
        </header>

        <!-- Flash Messages -->
        <?php if ($success): ?>
            <div class="mb-6 flex items-center justify-between rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-emerald-900 backdrop-blur-md">
                <div class="flex items-center gap-3">
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-500 text-white shadow-sm">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    </span>
                    <p class="text-sm font-semibold"><?= htmlspecialchars($success) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-6 flex items-center justify-between rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4 text-rose-900 backdrop-blur-md">
                <div class="flex items-center gap-3">
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-rose-500 text-white shadow-sm">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </span>
                    <p class="text-sm font-semibold"><?= htmlspecialchars($error) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Split Layout Grid -->
        <div class="grid gap-6 lg:grid-cols-12">
            
            <!-- Main Content Area: Active Sessions -->
            <div class="space-y-4 lg:col-span-8">
                
                <!-- Section Header Banner -->
                <div class="flex items-center justify-between rounded-2xl bg-white/80 p-4 shadow-sm backdrop-blur-sm border border-slate-200/60">
                    <div>
                        <span class="text-[11px] font-extrabold uppercase tracking-widest text-sky-600">Session Directory</span>
                        <h2 class="text-lg font-bold text-slate-900">Active Check-ins</h2>
                    </div>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-900 px-3 py-1 text-xs font-bold text-white shadow-sm">
                        <span class="h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        Class <?= htmlspecialchars($_SESSION['student_class'] ?? 'A') ?>
                    </span>
                </div>

                <!-- Session Cards Stream -->
                <div class="space-y-3">
                    <?php foreach ($active_sessions as $session): ?>
                        <?php $attendance_key = $session['attendance_key']; ?>
                        <div class="group overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm transition-all duration-200 hover:shadow-md hover:border-slate-300">
                            
                            <!-- Card Header Strip -->
                            <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="rounded-md bg-slate-900 px-2 py-0.5 text-[11px] font-black uppercase text-white tracking-wider">
                                        <?= htmlspecialchars($session['course_code']) ?>
                                    </span>
                                    <?php if ($session['attendance_type'] === 'portal_only'): ?>
                                        <span class="rounded-md bg-violet-100 px-2 py-0.5 text-[10px] font-bold text-violet-800">Portal session · not in reports</span>
                                    <?php endif; ?>
                                    <span class="text-xs font-semibold text-slate-500">
                                        Radius limit: <span class="font-bold text-slate-800"><?= (int)$session['radius_meters'] ?>m</span>
                                    </span>
                                </div>
                                <span class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200/60">
                                    <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3"/></svg>
                                    Live Now
                                </span>
                            </div>

                            <!-- Card Main Content -->
                            <div class="p-5 sm:p-6">
                                <h3 class="text-xl font-extrabold text-slate-900 group-hover:text-sky-800 transition-colors">
                                    <?= htmlspecialchars($session['course_title']) ?>
                                </h3>

                                <div class="mt-3 flex flex-wrap items-center gap-4 text-xs font-medium text-slate-500">
                                    <div class="flex items-center gap-1.5">
                                        <svg class="h-4 w-4 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        <span><?= date('h:i A', strtotime($session['start_time'])) ?> – <?= date('h:i A', strtotime($session['end_time'])) ?></span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <svg class="h-4 w-4 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
                                        <span>GPS Distance Check Required</span>
                                    </div>
                                </div>

                                <!-- Action Form -->
                                <div class="mt-6 pt-4 border-t border-slate-100 flex items-center justify-end">
                                    <form method="post" id="attendance-form-<?= htmlspecialchars($attendance_key) ?>" class="w-full">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('student_attendance') ?>">
                                        <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                                        <input type="hidden" name="attendance_type" value="<?= htmlspecialchars($session['attendance_type']) ?>">
                                        <input type="hidden" name="latitude" id="lat-<?= htmlspecialchars($attendance_key) ?>">
                                        <input type="hidden" name="longitude" id="lng-<?= htmlspecialchars($attendance_key) ?>">
                                        <input type="hidden" name="accuracy" id="acc-<?= htmlspecialchars($attendance_key) ?>">
                                        <input type="hidden" name="btn_mark_attendance" value="1">
                                        
                                        <button type="button" id="button-<?= htmlspecialchars($attendance_key) ?>" onclick="captureLocation('<?= htmlspecialchars($attendance_key, ENT_QUOTES) ?>')" class="w-full flex items-center justify-center gap-2 rounded-xl bg-slate-900 hover:bg-sky-700 px-5 py-3 text-sm font-bold text-white transition-all shadow-sm active:scale-[0.99]">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
                                            <span>Verify Location & Mark</span>
                                        </button>
                                    </form>
                                </div>
                            </div>

                        </div>
                    <?php endforeach; ?>

                    <?php if (!$active_sessions): ?>
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/50 p-12 text-center backdrop-blur-sm">
                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400 mb-3">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <h3 class="text-base font-bold text-slate-900">No active sessions available</h3>
                            <p class="mt-1 text-xs text-slate-500">There are currently no attendance sessions open for your class section.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Side Panel: Instructions & Geofencing Info -->
            <div class="space-y-4 lg:col-span-4">
                <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm space-y-4">
                    <div class="flex items-center gap-2 text-slate-900 font-extrabold text-sm border-b border-slate-100 pb-3">
                        <svg class="h-5 w-5 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span>How Verification Works</span>
                    </div>

                    <ol class="space-y-3 text-xs text-slate-600 font-medium">
                        <li class="flex gap-2.5">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-slate-100 font-bold text-slate-800 text-[10px]">1</span>
                            <span>Click <strong>Verify Location & Mark</strong> on your active course session.</span>
                        </li>
                        <li class="flex gap-2.5">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-slate-100 font-bold text-slate-800 text-[10px]">2</span>
                            <span>Allow the browser prompt to share high-accuracy GPS location data.</span>
                        </li>
                        <li class="flex gap-2.5">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-slate-100 font-bold text-slate-800 text-[10px]">3</span>
                            <span>The server verifies your proximity against the assigned classroom radius.</span>
                        </li>
                    </ol>

                    <div class="rounded-xl bg-sky-50/70 p-3.5 border border-sky-100 text-[11px] text-sky-900 font-medium leading-relaxed">
                        <strong class="font-bold block mb-0.5">Having trouble?</strong>
                        Ensure Wi-Fi or mobile data is active alongside GPS for best positioning precision.
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script>
        function captureLocation(sessionKey) {
            const button = document.getElementById('button-' + sessionKey);
            document.getElementById('lat-' + sessionKey).value = '';
            document.getElementById('lng-' + sessionKey).value = '';
            document.getElementById('acc-' + sessionKey).value = '';
            button.disabled = true;
            button.classList.add('opacity-75');
            button.innerHTML = `
                <svg class="h-4 w-4 animate-spin text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>Acquiring GPS Signal...</span>
            `;

            if (!navigator.geolocation) {
                button.disabled = false;
                button.classList.remove('opacity-75');
                button.innerHTML = `<span>Location not supported</span>`;
                return;
            }

            navigator.geolocation.getCurrentPosition(function (position) {
                const {latitude, longitude, accuracy} = position.coords;
                const readingAge = Date.now() - position.timestamp;
                if (!Number.isFinite(latitude) || latitude < -90 || latitude > 90
                    || !Number.isFinite(longitude) || longitude < -180 || longitude > 180
                    || !Number.isFinite(accuracy) || accuracy < 0
                    || !Number.isFinite(readingAge) || readingAge < -5000 || readingAge > 30000) {
                    button.disabled = false;
                    button.classList.remove('opacity-75');
                    button.innerHTML = '<span>Verify Location & Mark</span>';
                    alert('GPS reading is invalid or not fresh enough. Enable location services and try again.');
                    return;
                }

                document.getElementById('lat-' + sessionKey).value = position.coords.latitude;
                document.getElementById('lng-' + sessionKey).value = position.coords.longitude;
                document.getElementById('acc-' + sessionKey).value = position.coords.accuracy;
                
                button.innerHTML = `
                    <svg class="h-4 w-4 animate-spin text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>Verifying Proximity...</span>
                `;
                document.getElementById('attendance-form-' + sessionKey).submit();
            }, function (error) {
                button.disabled = false;
                button.classList.remove('opacity-75');
                button.innerHTML = `
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <span>Location Denied - Try Again</span>
                `;
                const messages = {
                    1: 'Location permission was denied. Allow location access in browser settings and try again.',
                    2: 'Your device could not determine its location. Enable GPS and Wi-Fi or mobile data, then try again.',
                    3: 'GPS request timed out. Move to an area with a clearer signal and try again.'
                };
                alert(messages[error.code] || 'Could not obtain your location. Check your device location settings and try again.');
            }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
        }
    </script>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>