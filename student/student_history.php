<?php
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
require_once __DIR__ . '/../includes/bootstrap.php';
ensure_attendance_approval_columns($pdo);

ensure_portal_alert_table($pdo);
ensure_class_columns($pdo);
ensure_maintenance_tables($pdo);
enforce_portal_maintenance($pdo, 'student portal');

if (!isset($_SESSION['student_id'])) {
    header('Location: ../index.php');
    exit;
}

date_default_timezone_set('Africa/Accra');
$student_id = (int)$_SESSION['student_id'];
$student_class = $_SESSION['student_class'] ?? 'A';

$history_stmt = $pdo->prepare("
    SELECT r.marked_at, r.distance_meters, r.marked_by, c.course_code, c.course_title, s.start_time,
           'present' AS attendance_status
    FROM attendance_records r
    JOIN attendance_sessions s ON r.session_id = s.id
    JOIN courses c ON s.course_id = c.id
    WHERE r.student_id = ?
      AND r.approval_status = 'approved'
    UNION ALL
    SELECT NULL AS marked_at, NULL AS distance_meters, 'absent' AS marked_by,
           c.course_code, c.course_title, s.start_time,
           'missed' AS attendance_status
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
$history_stmt->execute([$student_id, $student_class, $student_id]);
$attendance_history = $history_stmt->fetchAll();
$present_count = count(array_filter($attendance_history, static fn(array $row): bool => $row['attendance_status'] === 'present'));
$missed_count = count($attendance_history) - $present_count;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <title>Attendance History - C-Attend</title>
    <link rel="stylesheet" href="../assets/tailwind.css">
    <link rel="stylesheet" href="../includes/mobile.css">
</head>
<body class="mobile-page min-h-screen bg-[#f3f6fb] text-slate-800 antialiased">
<div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
    <header class="mb-6 overflow-hidden rounded-[30px] bg-gradient-to-r from-[#071b2f] via-[#102b4d] to-[#1d4e89] text-white shadow-[0_18px_50px_rgba(10,31,64,0.28)]">
        <div class="flex flex-col gap-5 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-7">
            <div class="flex items-center gap-4">
                <img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="h-12 w-12 shrink-0 object-contain">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.32em] text-sky-100/80">Student portal</p>
                    <h1 class="text-2xl font-black tracking-tight">Attendance history</h1>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="student_dashboard.php" class="rounded-xl border border-white/15 bg-white/10 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/20">Dashboard</a>
                <a href="logout.php" class="rounded-xl bg-white/10 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/20">Logout</a>
            </div>
        </div>
    </header>

    <main class="space-y-6">
        <section class="rounded-[30px] border border-[#cfe0f5] bg-gradient-to-r from-[#dbeafe] via-[#f8fbff] to-[#dff7f2] p-5 shadow-[0_14px_30px_rgba(15,23,42,0.08)] sm:p-7">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-700">Academic record</p>
            <h2 class="mt-2 text-3xl font-black tracking-tight text-slate-900"><?= htmlspecialchars($_SESSION['student_name'] ?? 'Student') ?></h2>
            <p class="mt-2 text-sm text-slate-600">Review every completed attendance session for Class <?= htmlspecialchars($student_class) ?>.</p>
        </section>

        <section class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-[26px] border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Total sessions</p>
                <p class="mt-3 text-3xl font-black text-slate-900"><?= count($attendance_history) ?></p>
            </div>
            <div class="rounded-[26px] border border-emerald-200 bg-gradient-to-br from-emerald-50 to-white p-4 shadow-sm">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-700">Present</p>
                <p class="mt-3 text-3xl font-black text-emerald-800"><?= $present_count ?></p>
            </div>
            <div class="rounded-[26px] border border-rose-200 bg-gradient-to-br from-rose-50 to-white p-4 shadow-sm">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-rose-700">Missed</p>
                <p class="mt-3 text-3xl font-black text-rose-800"><?= $missed_count ?></p>
            </div>
        </section>

        <section class="rounded-[30px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.06)] sm:p-6">
            <div class="mb-5">
                <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-sky-700">Records</p>
                <h2 class="mt-1 text-xl font-black text-slate-900">Attendance history</h2>
            </div>
            <?php if (empty($attendance_history)): ?>
                <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm text-slate-500">
                    No completed attendance records yet.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[680px] text-left text-sm">
                        <thead class="border-b border-slate-200 text-xs uppercase tracking-[0.12em] text-slate-500">
                            <tr>
                                <th class="px-3 py-3">Date</th>
                                <th class="px-3 py-3">Course</th>
                                <th class="px-3 py-3">Status</th>
                                <th class="px-3 py-3">Marked at</th>
                                <th class="px-3 py-3">Distance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($attendance_history as $row): ?>
                                <?php $is_present = $row['attendance_status'] === 'present'; ?>
                                <tr class="<?= $is_present ? 'bg-white' : 'bg-rose-50/60' ?>">
                                    <td class="px-3 py-4 font-medium text-slate-700"><?= date('M d, Y', strtotime($row['start_time'])) ?></td>
                                    <td class="px-3 py-4">
                                        <span class="font-bold text-slate-900"><?= htmlspecialchars($row['course_code']) ?></span>
                                        <span class="block text-xs text-slate-500"><?= htmlspecialchars($row['course_title']) ?></span>
                                    </td>
                                    <td class="px-3 py-4">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold <?= $is_present ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>">
                                            <?= $is_present ? 'Present' : 'Missed' ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-4 text-slate-600"><?= $is_present && $row['marked_at'] ? date('h:i A', strtotime($row['marked_at'])) : '—' ?></td>
                                    <td class="px-3 py-4 text-slate-600"><?= $is_present && $row['distance_meters'] !== null ? htmlspecialchars((string)$row['distance_meters']) . 'm' : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>
