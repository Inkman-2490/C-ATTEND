<?php
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/portal_sessions.php';

ensure_admin_roles($pdo);
ensure_maintenance_tables($pdo);
ensure_portal_only_session_tables($pdo);
expire_attendance_sessions($pdo);
finalize_and_expire_portal_only_sessions($pdo);
enforce_portal_maintenance($pdo, 'admin portal');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_stmt = $pdo->prepare('SELECT role, assigned_class FROM admins WHERE id = ?');
$admin_stmt->execute([(int)$_SESSION['admin_id']]);
$admin = $admin_stmt->fetch(PDO::FETCH_ASSOC);
if (!$admin) {
    header('Location: logout.php');
    exit;
}
$is_sub_admin = $admin['role'] === 'sub_admin';
$admin_id = (int)$_SESSION['admin_id'];
$admin_class = $admin['assigned_class'] ?? 'A';
$default_geofence_radius = get_default_geofence_radius($pdo);
$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];
$message = $_SESSION['portal_session_flash'] ?? '';
unset($_SESSION['portal_session_flash']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'portal_only_sessions')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $course_id = (int)($_POST['course_id'] ?? 0);
            $target_class = $is_sub_admin ? $admin_class : (string)($_POST['target_class'] ?? '');
            $latitude_value = trim((string)($_POST['latitude'] ?? ''));
            $longitude_value = trim((string)($_POST['longitude'] ?? ''));
            $latitude = filter_var($latitude_value, FILTER_VALIDATE_FLOAT);
            $longitude = filter_var($longitude_value, FILTER_VALIDATE_FLOAT);
            $radius = filter_var($_POST['radius_meters'] ?? null, FILTER_VALIDATE_INT);
            $start_input = trim((string)($_POST['start_time'] ?? ''));
            $end_input = trim((string)($_POST['end_time'] ?? ''));
            $start = DateTime::createFromFormat('Y-m-d\TH:i', $start_input);
            $end = DateTime::createFromFormat('Y-m-d\TH:i', $end_input);

            if ($course_id < 1 || $latitude === false || $longitude === false
                || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180
                || $radius === false || $radius < 50 || $radius > 2000
                || !in_array($target_class, ['A', 'B', 'both'], true)
                || !$start || !$end || $end <= $start) {
                $error = 'Enter a course, valid coordinates, radius between 50 and 2000 meters, class, and valid start/end times.';
            } elseif ($is_sub_admin && !in_array($target_class, [$admin_class, 'both'], true)) {
                $error = 'Sub-admins can only create sessions for their assigned class.';
            } else {
                $course_stmt = $pdo->prepare('SELECT id, course_code, course_title FROM courses WHERE id = ?');
                $course_stmt->execute([$course_id]);
                $course = $course_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$course) {
                    $error = 'The selected course could not be found.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO portal_only_sessions
                            (course_id, course_code, course_title, created_by_admin_id, target_class, latitude, longitude, radius_meters, start_time, end_time)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $course_id,
                        $course['course_code'],
                        $course['course_title'],
                        $admin_id,
                        $target_class,
                        $latitude,
                        $longitude,
                        $radius,
                        $start->format('Y-m-d H:i:s'),
                        $end->format('Y-m-d H:i:s')
                    ]);
                    $new_session_id = (int)$pdo->lastInsertId();
                    record_portal_alert(
                        $pdo,
                        'Portal-only attendance session created',
                        'Admin ID ' . $admin_id . ' created portal-only session #' . $new_session_id . ' for ' . $course['course_code'] . '.',
                        'medium',
                        'admin',
                        'This session and its attendance are excluded from normal attendance reports.'
                    );
                    $_SESSION['portal_session_flash'] = 'Portal-only attendance session created. It will not appear in standard reports.';
                    header('Location: portal_sessions.php');
                    exit;
                }
            }
        } elseif (in_array($action, ['end', 'delete'], true)) {
            $session_id = (int)($_POST['session_id'] ?? 0);
            if ($session_id < 1) {
                $error = 'Invalid session selected.';
            } else {
                $scope = $is_sub_admin ? ' AND created_by_admin_id = ?' : '';
                $params = [$session_id];
                if ($is_sub_admin) {
                    $params[] = $admin_id;
                }
                $find = $pdo->prepare('SELECT id, course_code, course_title FROM portal_only_sessions WHERE id = ?' . $scope);
                $find->execute($params);
                $session = $find->fetch(PDO::FETCH_ASSOC);
                if (!$session) {
                    $error = 'The session could not be found or is outside your permissions.';
                } elseif ($action === 'end') {
                    $update = $pdo->prepare('UPDATE portal_only_sessions SET end_time = NOW(), is_active = 0 WHERE id = ? AND end_time > NOW()');
                    $update->execute([$session_id]);
                    if ($update->rowCount() !== 1) {
                        $error = 'This session is already ended.';
                    } else {
                        finalize_and_expire_portal_only_sessions($pdo);
                        $_SESSION['portal_session_flash'] = 'Session ended. Its PDF attendance record is now available below and will be retained until 24 hours after session end.';
                        header('Location: portal_sessions.php');
                        exit;
                    }
                } else {
                    $delete = $pdo->prepare('DELETE FROM portal_only_sessions WHERE id = ?');
                    $delete->execute([$session_id]);
                    record_portal_alert(
                        $pdo,
                        'Portal-only attendance session deleted',
                        'Admin ID ' . $admin_id . ' deleted portal-only session #' . $session_id . ' (' . $session['course_code'] . ').',
                        'high',
                        'admin',
                        'Its saved PDF and attendance records were permanently removed.'
                    );
                    $_SESSION['portal_session_flash'] = 'Session and its PDF were permanently deleted.';
                    header('Location: portal_sessions.php');
                    exit;
                }
            }
        } else {
            $error = 'Invalid session action.';
        }
    }
}

$courses = $pdo->query('SELECT id, course_code, course_title FROM courses ORDER BY course_code')->fetchAll(PDO::FETCH_ASSOC);
$where = $is_sub_admin ? 'WHERE s.created_by_admin_id = ?' : '';
$list = $pdo->prepare("
    SELECT s.*,
           a.full_name AS creator_name,
           (SELECT COUNT(*) FROM portal_only_attendance pa WHERE pa.session_id = s.id) AS attendance_count
    FROM portal_only_sessions s
    LEFT JOIN admins a ON a.id = s.created_by_admin_id
    $where
    ORDER BY s.start_time DESC, s.id DESC
");
$list->execute($is_sub_admin ? [$admin_id] : []);
$sessions = $list->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <link rel="stylesheet" href="../assets/tailwind.css">
    <link rel="stylesheet" href="../includes/mobile.css">
    <title>Portal-only Attendance Sessions</title>
</head>
<body class="mobile-page min-h-screen bg-[#f3f6fb] text-slate-800">
<?php render_scheduled_maintenance_popup($pdo); ?>
<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-3xl bg-gradient-to-r from-[#071b2f] via-[#102b4d] to-[#1d4e89] p-5 text-white shadow-lg">
        <div><p class="text-xs uppercase tracking-[0.2em] text-sky-100">Admin portal</p><h1 class="mt-1 text-2xl font-black">Portal-only attendance sessions</h1><p class="mt-1 text-sm text-sky-100/80">GPS check-in sessions with separate attendance and saved PDF records. Excluded from normal reports.</p></div>
        <a href="dashboard.php" class="rounded-xl bg-white/10 px-4 py-2 text-sm font-semibold">Back to dashboard</a>
    </header>
    <?php if ($message): ?><p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <section class="mb-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-xl font-black">Create a portal-only session</h2>
        <p class="mt-1 text-sm text-slate-500">When the session ends, the attendance PDF is generated and stays here until you delete it or 24 hours after the session ends.</p>
        <?php if (!$courses): ?>
            <p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">Create a course before starting a session.</p>
        <?php else: ?>
            <form method="post" id="portal-session-form" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" onsubmit="return validatePortalLocationCapture()">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('portal_only_sessions') ?>">
                <input type="hidden" name="action" value="create">
                <label class="text-sm font-semibold text-slate-700">Course<select name="course_id" required class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5"><option value="">Choose course</option><?php foreach ($courses as $course): ?><option value="<?= (int)$course['id'] ?>"><?= htmlspecialchars($course['course_code'] . ' - ' . $course['course_title']) ?></option><?php endforeach; ?></select></label>
                <label class="text-sm font-semibold text-slate-700">Target class<select name="target_class" <?= $is_sub_admin ? 'disabled' : 'required' ?> class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5"><?php if (!$is_sub_admin): ?><option value="both">Class A & B</option><?php endif; ?><option value="A" <?= $is_sub_admin && $admin_class === 'A' ? 'selected' : '' ?>>Class A</option><option value="B" <?= $is_sub_admin && $admin_class === 'B' ? 'selected' : '' ?>>Class B</option></select><?php if ($is_sub_admin): ?><input type="hidden" name="target_class" value="<?= htmlspecialchars($admin_class) ?>"><?php endif; ?></label>
                <label class="text-sm font-semibold text-slate-700">Radius (meters)<input type="number" name="radius_meters" value="<?= $default_geofence_radius ?>" min="50" max="2000" required class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5"></label>
                <label class="text-sm font-semibold text-slate-700">Latitude<input type="number" step="any" name="latitude" id="portal-session-latitude" readonly required class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5"></label>
                <label class="text-sm font-semibold text-slate-700">Longitude<input type="number" step="any" name="longitude" id="portal-session-longitude" readonly required class="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5"></label>
                <div class="flex items-end"><button id="portal-capture-location" type="button" onclick="capturePortalLocation()" class="w-full rounded-xl border border-sky-300 bg-sky-50 px-3 py-2.5 text-sm font-bold text-sky-800">Capture fresh location</button></div>
                <p id="portal-location-status" class="text-xs text-slate-500 sm:col-span-2 lg:col-span-3" role="status">Capture a fresh GPS location before starting a session.</p>
                <label class="text-sm font-semibold text-slate-700">Start time<input type="datetime-local" name="start_time" required value="<?= date('Y-m-d\TH:i') ?>" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5"></label>
                <label class="text-sm font-semibold text-slate-700">End time<input type="datetime-local" name="end_time" required value="<?= date('Y-m-d\TH:i', strtotime('+2 hours')) ?>" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5"></label>
                <button class="rounded-xl bg-[#1d4e89] px-4 py-3 text-sm font-bold text-white hover:bg-blue-800 sm:col-span-2 lg:col-span-3">Start portal-only session</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex items-center justify-between"><h2 class="text-xl font-black">Sessions and saved PDFs</h2><span class="text-sm text-slate-500"><?= count($sessions) ?> shown</span></div>
        <?php if (!$sessions): ?>
            <p class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No portal-only sessions have been created.</p>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($sessions as $session): ?>
                    <?php $ended = strtotime($session['end_time']) <= time(); ?>
                    <article class="rounded-2xl border border-slate-200 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div><p class="text-xs font-bold uppercase tracking-wide text-sky-700">Session #<?= (int)$session['id'] ?> · Class <?= htmlspecialchars($session['target_class'] === 'both' ? 'A & B' : $session['target_class']) ?></p><h3 class="mt-1 font-bold text-slate-900"><?= htmlspecialchars($session['course_code'] . ' - ' . $session['course_title']) ?></h3><p class="mt-1 text-xs text-slate-500"><?= htmlspecialchars($session['start_time']) ?> to <?= htmlspecialchars($session['end_time']) ?> · <?= (int)$session['attendance_count'] ?> marked<?php if (!$is_sub_admin): ?> · Created by <?= htmlspecialchars($session['creator_name'] ?? 'Unknown admin') ?><?php endif; ?></p></div>
                            <span class="rounded-full px-3 py-1 text-xs font-bold <?= $ended ? 'bg-slate-100 text-slate-700' : 'bg-emerald-100 text-emerald-800' ?>"><?= $ended ? 'Ended' : 'Scheduled / live' ?></span>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <?php if ($session['pdf_data'] !== null): ?>
                                <a href="portal_session_pdf.php?id=<?= (int)$session['id'] ?>&download=0" class="rounded-lg bg-sky-600 px-3 py-2 text-xs font-bold text-white">View PDF</a>
                                <a href="portal_session_pdf.php?id=<?= (int)$session['id'] ?>&download=1" class="rounded-lg bg-slate-800 px-3 py-2 text-xs font-bold text-white">Download PDF</a>
                                <span class="self-center text-xs text-slate-500">PDF remains saved after download.</span>
                            <?php elseif (!$ended): ?>
                                <form method="post" onsubmit="return confirm('End this session now? This generates its attendance PDF.');"><input type="hidden" name="csrf_token" value="<?= generate_csrf_token('portal_only_sessions') ?>"><input type="hidden" name="action" value="end"><input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>"><button class="rounded-lg bg-amber-500 px-3 py-2 text-xs font-bold text-white">End session & generate PDF</button></form>
                            <?php else: ?>
                                <span class="self-center text-xs text-amber-700">The PDF will be generated on refresh.</span>
                            <?php endif; ?>
                            <form method="post" onsubmit="return confirm('Permanently delete this session, attendance data, and saved PDF?');"><input type="hidden" name="csrf_token" value="<?= generate_csrf_token('portal_only_sessions') ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>"><button class="rounded-lg bg-red-600 px-3 py-2 text-xs font-bold text-white">Delete session & PDF</button></form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<script>
    function capturePortalLocation() {
        const latitude = document.getElementById('portal-session-latitude');
        const longitude = document.getElementById('portal-session-longitude');
        const status = document.getElementById('portal-location-status');
        const button = document.getElementById('portal-capture-location');
        window.portalLocationCaptured = false;
        latitude.value = '';
        longitude.value = '';
        status.textContent = 'Getting a fresh GPS location...';
        status.className = 'text-xs text-sky-700 sm:col-span-2 lg:col-span-3';
        if (!navigator.geolocation) {
            status.textContent = 'This browser does not support GPS location.';
            status.className = 'text-xs text-red-600 sm:col-span-2 lg:col-span-3';
            alert('Location services are not supported by this browser.');
            return;
        }
        button.disabled = true;
        button.textContent = 'Getting fresh location...';
        navigator.geolocation.getCurrentPosition(function (position) {
            const readingAge = Date.now() - position.timestamp;
            if (!Number.isFinite(readingAge) || readingAge < -5000 || readingAge > 30000) {
                status.textContent = 'GPS reading was not fresh. Please capture your location again.';
                status.className = 'text-xs text-red-600 sm:col-span-2 lg:col-span-3';
                button.disabled = false;
                button.textContent = 'Capture fresh location';
                return;
            }
            latitude.value = position.coords.latitude;
            longitude.value = position.coords.longitude;
            window.portalLocationCaptured = true;
            status.textContent = 'Fresh location captured. You can start the session.';
            status.className = 'text-xs text-emerald-700 sm:col-span-2 lg:col-span-3';
            button.disabled = false;
            button.textContent = 'Capture fresh location';
        }, function (error) {
            status.textContent = 'Location capture failed. Allow GPS access and try again.';
            status.className = 'text-xs text-red-600 sm:col-span-2 lg:col-span-3';
            button.disabled = false;
            button.textContent = 'Capture fresh location';
            alert('Could not get your location: ' + error.message + '. Allow location access and try again.');
        }, {enableHighAccuracy: true, timeout: 10000, maximumAge: 0});
    }

    function validatePortalLocationCapture() {
        if (!window.portalLocationCaptured) {
            alert('Capture a fresh GPS location before starting this session.');
            return false;
        }
        return true;
    }

    window.addEventListener('pageshow', function () {
        window.portalLocationCaptured = false;
        document.getElementById('portal-session-latitude').value = '';
        document.getElementById('portal-session-longitude').value = '';
    });
</script>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>
