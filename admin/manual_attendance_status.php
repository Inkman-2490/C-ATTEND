<?php
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
require_once __DIR__ . '/../includes/bootstrap.php';

ensure_admin_roles($pdo);
ensure_attendance_approval_columns($pdo);
ensure_maintenance_tables($pdo);
enforce_portal_maintenance($pdo, 'admin portal');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_stmt = $pdo->prepare('SELECT role, assigned_class FROM admins WHERE id = ?');
$admin_stmt->execute([(int)$_SESSION['admin_id']]);
$admin = $admin_stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin || $admin['role'] !== 'sub_admin') {
    http_response_code(403);
    exit('Access denied.');
}

$submissions_stmt = $pdo->prepare("
    SELECT
        r.id,
        r.session_id,
        r.marked_at,
        r.approval_status,
        r.approved_at,
        st.index_number,
        st.full_name,
        s.start_time,
        s.end_time,
        c.course_code,
        c.course_title
    FROM attendance_records r
    JOIN students st ON st.id = r.student_id
    LEFT JOIN attendance_sessions s ON s.id = r.session_id
    LEFT JOIN courses c ON c.id = s.course_id
    WHERE r.submitted_by_admin_id = ?
      AND r.marked_by = 'admin'
    ORDER BY r.marked_at DESC, r.id DESC
");
$submissions_stmt->execute([(int)$_SESSION['admin_id']]);
$submissions = $submissions_stmt->fetchAll(PDO::FETCH_ASSOC);

$status_classes = [
    'pending' => 'bg-amber-100 text-amber-800',
    'approved' => 'bg-emerald-100 text-emerald-800',
    'rejected' => 'bg-rose-100 text-rose-800'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <link rel="stylesheet" href="../assets/tailwind.css">
    <link rel="stylesheet" href="../includes/mobile.css">
    <title>My Manual Attendance Submissions</title>
</head>
<body class="mobile-page min-h-screen bg-[#f3f6fb] text-slate-800 antialiased">
<?php render_scheduled_maintenance_popup($pdo); ?>
<main class="mx-auto max-w-6xl px-4 py-5 sm:px-6 lg:px-8">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-[30px] bg-gradient-to-r from-[#071b2f] via-[#102b4d] to-[#1d4e89] p-5 text-white shadow-[0_18px_50px_rgba(10,31,64,0.28)]">
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.32em] text-sky-100/80">Class <?= htmlspecialchars($admin['assigned_class'] ?? '') ?> sub-admin</p>
            <h1 class="mt-1 text-2xl font-black">My manual attendance submissions</h1>
            <p class="mt-1 text-sm text-sky-100/80">Track the main administrator’s decision for each student you submitted.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="manual_attendance.php" class="rounded-xl bg-white/10 px-4 py-2 text-sm font-semibold text-white">Mark attendance</a>
            <a href="dashboard.php" class="rounded-xl bg-white/10 px-4 py-2 text-sm font-semibold text-white">Dashboard</a>
        </div>
    </header>

    <section class="mb-5 grid gap-3 sm:grid-cols-3">
        <?php foreach (['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $status => $label): ?>
            <?php $status_count = count(array_filter($submissions, static function (array $submission) use ($status): bool { return $submission['approval_status'] === $status; })); ?>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500"><?= htmlspecialchars($label) ?></p>
                <p class="mt-1 text-2xl font-black text-slate-900"><?= $status_count ?></p>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-sky-700">Submission history</p>
                <h2 class="mt-1 text-xl font-black text-slate-900">Student attendance status</h2>
            </div>
            <span class="text-sm text-slate-500"><?= count($submissions) ?> total</span>
        </div>
        <p class="mb-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">Pending means the main administrator has not decided yet. Approved students are marked present. Rejected submissions remain absent and are shown here for your reference.</p>

        <?php if (!$submissions): ?>
            <p class="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">You have not submitted any manual attendance yet.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                    <tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <th class="px-3 py-3">Student</th>
                        <th class="px-3 py-3">Session</th>
                        <th class="px-3 py-3">Submitted</th>
                        <th class="px-3 py-3">Decision</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($submissions as $submission): ?>
                        <?php $status = $submission['approval_status']; ?>
                        <tr class="border-b border-slate-100 last:border-0">
                            <td class="px-3 py-4 font-semibold text-slate-900">
                                <?= htmlspecialchars($submission['full_name']) ?>
                                <span class="block text-xs font-normal text-slate-500"><?= htmlspecialchars($submission['index_number']) ?></span>
                            </td>
                            <td class="px-3 py-4 text-slate-700">
                                <?= htmlspecialchars(trim(($submission['course_code'] ?? '') . ' - ' . ($submission['course_title'] ?? 'Session unavailable'), ' -')) ?>
                                <span class="block text-xs text-slate-500">Session #<?= (int)$submission['session_id'] ?><?php if ($submission['start_time']): ?> · <?= date('d M Y, h:i A', strtotime($submission['start_time'])) ?><?php endif; ?></span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-xs text-slate-500"><?= date('d M Y, h:i A', strtotime($submission['marked_at'])) ?></td>
                            <td class="px-3 py-4">
                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold <?= $status_classes[$status] ?? 'bg-slate-100 text-slate-700' ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
                                <?php if ($status !== 'pending' && $submission['approved_at']): ?>
                                    <span class="mt-1 block text-xs text-slate-500">Decided <?= date('d M Y, h:i A', strtotime($submission['approved_at'])) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>
