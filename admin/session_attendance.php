<?php
// admin/session_attendance.php
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
require_once __DIR__ . '/../includes/bootstrap.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

ensure_admin_roles($pdo);
ensure_attendance_approval_columns($pdo);
ensure_maintenance_tables($pdo);
enforce_portal_maintenance($pdo, 'admin portal');
expire_attendance_sessions($pdo);

$session_id = (int)($_GET['session_id'] ?? 0);
$session = null;
$marked_students = [];
$error = '';
$message = $_SESSION['session_attendance_message'] ?? '';
unset($_SESSION['session_attendance_message']);

$admin_role_query = $pdo->prepare('SELECT role FROM admins WHERE id = ?');
$admin_role_query->execute([(int)$_SESSION['admin_id']]);
$is_main_admin = $admin_role_query->fetchColumn() === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_attendance') {
    $posted_session_id = (int)($_POST['session_id'] ?? 0);
    $record_id = (int)($_POST['record_id'] ?? 0);

    if (!$is_main_admin) {
        $error = 'Only the main administrator can delete attendance records.';
    } elseif (!validate_csrf_token($_POST['csrf_token'] ?? null, 'admin_delete_attendance')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } elseif ($posted_session_id <= 0 || $record_id <= 0) {
        $error = 'Invalid attendance record selected.';
    } else {
        $delete_query = $pdo->prepare("
            SELECT r.id, r.session_id, st.index_number, st.full_name
            FROM attendance_records r
            JOIN students st ON st.id = r.student_id
            WHERE r.id = ? AND r.session_id = ?
        ");
        $delete_query->execute([$record_id, $posted_session_id]);
        $record = $delete_query->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            $error = 'Attendance record could not be found.';
        } else {
            $delete = $pdo->prepare('DELETE FROM attendance_records WHERE id = ? AND session_id = ?');
            $delete->execute([$record_id, $posted_session_id]);
            if ($delete->rowCount() !== 1) {
                $error = 'Attendance record could not be deleted.';
            } else {
                record_portal_alert(
                    $pdo,
                    'Attendance deleted by main administrator',
                    'Attendance for ' . $record['index_number'] . ' (' . $record['full_name'] . ') was deleted from session ' . $posted_session_id . '.',
                    'high',
                    'admin',
                    'Manual attendance record removal'
                );
                $_SESSION['session_attendance_message'] = 'Attendance record deleted for ' . $record['index_number'] . '.';
                header('Location: session_attendance.php?session_id=' . $posted_session_id);
                exit;
            }
        }
    }
    $session_id = $posted_session_id > 0 ? $posted_session_id : $session_id;
}

if ($session_id > 0) {
    $session_query = $pdo->prepare("
        SELECT s.id, s.start_time, s.end_time, s.target_class, c.course_code, c.course_title
        FROM attendance_sessions s
        JOIN courses c ON c.id = s.course_id
        WHERE s.id = ?
          AND s.is_active = 1
          AND s.end_time > NOW()
    ");
    $session_query->execute([$session_id]);
    $session = $session_query->fetch(PDO::FETCH_ASSOC);

    if ($session) {
        $student_query = $pdo->prepare("
            SELECT r.id AS record_id, st.index_number, st.full_name, st.class_name,
                   r.marked_at, r.marked_by, r.distance_meters, r.approval_status
            FROM attendance_records r
            JOIN students st ON st.id = r.student_id
            WHERE r.session_id = ?
              AND r.approval_status = 'approved'
            ORDER BY r.marked_at ASC, st.index_number ASC
        ");
        $student_query->execute([$session_id]);
        $marked_students = $student_query->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $error = 'This session is no longer active or could not be found.';
    }
} else {
    $error = 'Select an active session to view marked students.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <link rel="stylesheet" href="../assets/tailwind.css">
    <link rel="stylesheet" href="../includes/mobile.css">
    <title>C-Attend - Marked Students</title>
</head>
<body class="mobile-page min-h-screen bg-slate-100 text-slate-800">
    <?php render_scheduled_maintenance_popup($pdo); ?>
    <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6">
        <header class="overflow-hidden rounded-[30px] bg-gradient-to-r from-[#071b2f] via-[#102b4d] to-[#1d4e89] p-5 text-white shadow-[0_18px_50px_rgba(10,31,64,0.28)] sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.32em] text-sky-100/80">Attendance monitoring</p>
                    <h1 class="mt-2 text-2xl font-black tracking-tight">Marked students</h1>
                    <?php if ($session): ?>
                        <p class="mt-1 text-sm text-sky-100/80">
                            <?= htmlspecialchars($session['course_code'] . ' - ' . $session['course_title']) ?>
                        </p>
                    <?php endif; ?>
                </div>
                <a href="dashboard.php#sessions" class="rounded-xl border border-white/15 bg-white/10 px-4 py-2.5 text-center text-sm font-semibold text-white transition hover:bg-white/18">
                    Back to dashboard
                </a>
            </div>
        </header>

            <?php if ($message !== ''): ?>
                <section class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm font-semibold text-emerald-800">
                    <?= htmlspecialchars($message) ?>
            </section>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <section class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm font-semibold text-amber-800">
                    <?= htmlspecialchars($error) ?>
                </section>
            <?php endif; ?>

            <?php if ($session): ?>
            <section class="mt-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex flex-col gap-3 border-b border-slate-100 pb-5 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-700">Live session</p>
                        <h2 class="mt-1 text-xl font-black text-slate-900">
                            <?= count($marked_students) ?> student<?= count($marked_students) === 1 ? '' : 's' ?> marked
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Class <?= htmlspecialchars($session['target_class'] === 'both' ? 'A & B' : $session['target_class']) ?>
                            · <?= date('h:i A', strtotime($session['start_time'])) ?> — <?= date('h:i A', strtotime($session['end_time'])) ?>
                        </p>
                    </div>
                    <a href="session_attendance.php?session_id=<?= $session_id ?>" class="rounded-xl border border-slate-200 px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:border-sky-300 hover:text-sky-700">
                        Refresh list
                    </a>
                </div>

                <?php if (empty($marked_students)): ?>
                    <p class="py-10 text-center text-sm text-slate-500">No students have marked attendance yet.</p>
                <?php else: ?>
                    <div class="mt-5 overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                                    <th class="px-3 py-3">#</th>
                                    <th class="px-3 py-3">Index number</th>
                                    <th class="px-3 py-3">Student name</th>
                                    <th class="px-3 py-3">Class</th>
                                    <th class="px-3 py-3">Marked at</th>
                                    <th class="px-3 py-3">Distance</th>
                                    <th class="px-3 py-3">Marked by</th>
                                    <?php if ($is_main_admin): ?><th class="px-3 py-3">Action</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($marked_students as $index => $student): ?>
                                    <tr class="border-b border-slate-100 last:border-0">
                                        <td class="px-3 py-3 font-semibold text-slate-500"><?= $index + 1 ?></td>
                                        <td class="px-3 py-3 font-semibold text-slate-900"><?= htmlspecialchars($student['index_number']) ?></td>
                                        <td class="px-3 py-3 font-semibold text-slate-900"><?= htmlspecialchars($student['full_name']) ?></td>
                                        <td class="px-3 py-3"><?= htmlspecialchars($student['class_name']) ?></td>
                                        <td class="whitespace-nowrap px-3 py-3 text-slate-600"><?= date('d M Y, h:i:s A', strtotime($student['marked_at'])) ?></td>
                                        <td class="whitespace-nowrap px-3 py-3 text-slate-600"><?= number_format((float)$student['distance_meters'], 1) ?> m</td>
                                        <td class="px-3 py-3 text-slate-600"><?= htmlspecialchars($student['marked_by'] ?? 'student') ?></td>
                                        <?php if ($is_main_admin): ?>
                                            <td class="px-3 py-3">
                                                <form method="post" onsubmit="return confirm('Delete this attendance record for <?= htmlspecialchars($student['index_number'], ENT_QUOTES, 'UTF-8') ?>?');">
                                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('admin_delete_attendance') ?>">
                                                    <input type="hidden" name="action" value="delete_attendance">
                                                    <input type="hidden" name="session_id" value="<?= $session_id ?>">
                                                    <input type="hidden" name="record_id" value="<?= (int)$student['record_id'] ?>">
                                                    <button type="submit" class="rounded-lg bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-100">Delete</button>
                                                </form>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>
