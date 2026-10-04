<?php
// admin/reports.php
session_start();
require_once __DIR__ . '/../includes/bootstrap.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

ensure_maintenance_tables($pdo);
ensure_attendance_approval_columns($pdo);
enforce_portal_maintenance($pdo, 'admin portal');

$filter_period = $_GET['period'] ?? 'weekly'; // daily, weekly, monthly, or custom
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$course_id = (int)($_GET['course_id'] ?? 0);
$class_name = strtoupper(trim($_GET['class_name'] ?? 'BOTH'));
if (!in_array($class_name, ['A', 'B', 'BOTH'], true)) {
    $class_name = 'BOTH';
}
$query_params = [];

if ($filter_period === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to) && $date_from <= $date_to) {
    $date_condition = "r.marked_at >= ? AND r.marked_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $query_params = [$date_from . ' 00:00:00', $date_to . ' 00:00:00'];
    $period_label = date('d M Y', strtotime($date_from)) . ' - ' . date('d M Y', strtotime($date_to));
} elseif ($filter_period === 'daily') {
    $date_condition = "s.start_time >= CURDATE()";
    $period_label = "Today (Daily)";
} elseif ($filter_period === 'monthly') {
    $date_condition = "s.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $period_label = "Past 30 Days (Monthly)";
} else {
    $date_condition = "s.start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $period_label = "Past 7 Days (Weekly)";
}

// Fetch summarized attendance data per course and student.
$report_where = [$date_condition];
$report_params = $query_params;
if ($class_name !== 'BOTH') {
    $report_where[] = 'st.class_name = ?';
    $report_params[] = $class_name;
}
if ($course_id > 0) {
    $report_where[] = 's.course_id = ?';
    $report_params[] = $course_id;
}

$query = "
    SELECT 
        c.course_code,
        c.course_title,
        st.index_number,
        st.full_name,
        st.class_name,
        COUNT(r.id) AS sessions_attended,
        MIN(r.marked_at) AS first_seen,
        MAX(r.marked_at) AS last_seen,
        GROUP_CONCAT(
            DISTINCT DATE_FORMAT(r.marked_at, '%d %b %Y')
            ORDER BY DATE(r.marked_at) ASC
            SEPARATOR ', '
        ) AS attendance_dates
    FROM attendance_records r
    JOIN attendance_sessions s ON r.session_id = s.id
    JOIN courses c ON s.course_id = c.id
    JOIN students st ON r.student_id = st.id
    WHERE r.approval_status = 'approved'
      AND " . implode(' AND ', $report_where) . "
    GROUP BY c.id, st.id
    ORDER BY c.course_code ASC, st.index_number ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute($report_params);
$reports = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <link rel="apple-touch-icon" href="../assets/logo-dashboard.png">
    <title>C-Attend - Reports & Analytics</title>
    <link rel="stylesheet" href="../assets/tailwind.css">
    <link rel="stylesheet" href="../includes/mobile.css">
    <style>
        @media (max-width: 640px) {
            .mobile-page,
            .mobile-page * { box-sizing: border-box; }
            .mobile-page { overflow-x: hidden; }
            .mobile-page > div {
                width: 100%;
                max-width: 100%;
                padding: 0.75rem;
            }
            .mobile-page > div > * { min-width: 0; max-width: 100%; }
            .mobile-page header { border-radius: 1.25rem; }
            .mobile-page header > div { padding: 1rem; }
            .mobile-page header .flex.flex-wrap { width: 100%; }
            .mobile-page header .flex.flex-wrap a { flex: 1 1 auto; text-align: center; }
            .mobile-page section { border-radius: 1.25rem; padding: 1rem; }
            .mobile-page h1 { font-size: 1.35rem; }
            .mobile-page h2 { font-size: 1.15rem; }
            .mobile-page table { min-width: 680px; }
            .mobile-page .overflow-x-auto { margin-left: -0.25rem; margin-right: -0.25rem; }
            .mobile-page input,
            .mobile-page select,
            .mobile-page button { max-width: 100%; min-width: 0; }
        }
    </style>
    </head>
    <body class="mobile-page min-h-screen bg-[#f3f6fb] text-slate-800 antialiased">
    <?php render_scheduled_maintenance_popup($pdo); ?>
    <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
        <header class="mb-6 overflow-hidden rounded-[30px] bg-gradient-to-r from-[#071b2f] via-[#102b4d] to-[#1d4e89] text-white shadow-[0_18px_50px_rgba(10,31,64,0.28)]">
            <div class="flex flex-col gap-5 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-7">
                <div class="flex items-center gap-4">
                    <img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="h-12 w-12 shrink-0 object-contain" />
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.32em] text-sky-100/80">Campus admin</p>
                        <h1 class="text-2xl font-black tracking-tight">C-Attend</h1>
                    </div>
                </div>

                <div class="admin-nav flex flex-wrap items-center gap-2 text-sm font-medium">
                    <a href="dashboard.php" class="rounded-xl border border-white/15 bg-white/8 px-3 py-2 text-white transition hover:bg-white/12">Dashboard</a>
                    <a href="manual_attendance.php" class="rounded-xl border border-white/15 bg-white/8 px-3 py-2 text-white transition hover:bg-white/12">Manual Override</a>
                    <a href="logout.php" class="rounded-xl bg-white/10 px-3 py-2 text-xs font-semibold text-white transition hover:bg-white/20">Logout</a>
                </div>
            </div>
        </header>

        <main class="space-y-6">
            <section class="rounded-[30px] border border-slate-200 bg-gradient-to-r from-[#f5f7ff] via-white to-[#eefbff] p-5 shadow-[0_10px_24px_rgba(15,23,42,0.06)] sm:p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-700">Reports</p>
                        <h2 class="mt-2 text-2xl font-black text-slate-900">Academic attendance insights</h2>
                        <p class="mt-2 max-w-xl text-sm text-slate-600">Monitor attendance trends, verify verification summaries, and export report data for academic review.</p>
                    </div>
                    <div class="rounded-2xl border border-[#dbeafe] bg-white px-4 py-3 shadow-sm">
                        <p class="text-[10px] uppercase tracking-[0.18em] text-slate-500">Period</p>
                        <p class="mt-1 text-xl font-black text-slate-900"><?= htmlspecialchars($period_label) ?></p>
                    </div>
                </div>
            </section>

            <section class="rounded-[30px] border border-slate-200 bg-white p-5 shadow-[0_10px_24px_rgba(15,23,42,0.06)] sm:p-6">
                <div class="mb-5">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-sky-700">Build a report</p>
                    <h2 class="mt-1 text-xl font-black text-slate-900">Choose a period, then filter the results</h2>
                    <p class="mt-1 text-sm text-slate-500">Use a preset for a fast report or select exact attendance dates.</p>
                </div>

                <div class="mb-5 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <a href="reports.php?period=daily" class="rounded-2xl border px-3 py-3 text-center transition <?= $filter_period === 'daily' ? 'border-[#1d4e89] bg-[#edf4ff] text-[#123b6d]' : 'border-slate-200 bg-slate-50 text-slate-600 hover:border-sky-200' ?>">
                        <span class="block text-sm font-bold">Daily</span>
                        <span class="mt-1 block text-[11px]">Today</span>
                    </a>
                    <a href="reports.php?period=weekly" class="rounded-2xl border px-3 py-3 text-center transition <?= $filter_period === 'weekly' ? 'border-[#1d4e89] bg-[#edf4ff] text-[#123b6d]' : 'border-slate-200 bg-slate-50 text-slate-600 hover:border-sky-200' ?>">
                        <span class="block text-sm font-bold">Weekly</span>
                        <span class="mt-1 block text-[11px]">Past 7 days</span>
                    </a>
                    <a href="reports.php?period=monthly" class="rounded-2xl border px-3 py-3 text-center transition <?= $filter_period === 'monthly' ? 'border-[#1d4e89] bg-[#edf4ff] text-[#123b6d]' : 'border-slate-200 bg-slate-50 text-slate-600 hover:border-sky-200' ?>">
                        <span class="block text-sm font-bold">Monthly</span>
                        <span class="mt-1 block text-[11px]">Past 30 days</span>
                    </a>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3 text-center text-slate-600">
                        <span class="block text-sm font-bold">Custom</span>
                        <span class="mt-1 block text-[11px]">Choose dates below</span>
                    </div>
                </div>

                <form method="GET" action="reports.php" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <input type="hidden" name="period" value="custom">
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">Course</label>
                        <select name="course_id" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                            <option value="0" <?= $course_id === 0 ? 'selected' : '' ?>>All courses</option>
                            <?php
                            $courses = $pdo->query("SELECT id, course_code, course_title FROM courses ORDER BY course_code ASC")->fetchAll();
                            foreach ($courses as $c):
                            ?>
                                <option value="<?= $c['id'] ?>" <?= $course_id === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['course_code'] . ' - ' . $c['course_title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">Student class</label>
                        <select name="class_name" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                            <option value="both" <?= $class_name === 'BOTH' ? 'selected' : '' ?>>Class A + B</option>
                            <option value="A" <?= $class_name === 'A' ? 'selected' : '' ?>>Class A only</option>
                            <option value="B" <?= $class_name === 'B' ? 'selected' : '' ?>>Class B only</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">From date</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">To date</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row md:col-span-2">
                        <button type="submit" class="w-full rounded-xl bg-[#102b4d] px-4 py-3 text-sm font-semibold text-white transition hover:bg-[#1d4e89]">View custom report</button>
                        <a href="reports.php?period=weekly" class="inline-flex w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Clear filters</a>
                    </div>
                </form>

                <div class="mt-5 border-t border-slate-100 pt-5">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Export current preset</p>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <button type="button" onclick="downloadReport('daily', 'csv')" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-sky-200 hover:bg-sky-50">Daily CSV</button>
                        <button type="button" onclick="downloadReport('daily', 'pdf')" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-sky-200 hover:bg-sky-50">Daily PDF</button>
                        <button type="button" onclick="downloadReport('weekly', 'csv')" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-sky-200 hover:bg-sky-50">Weekly CSV</button>
                        <button type="button" onclick="downloadReport('weekly', 'pdf')" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-sky-200 hover:bg-sky-50">Weekly PDF</button>
                        <button type="button" onclick="downloadReport('monthly', 'csv')" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-sky-200 hover:bg-sky-50">Monthly CSV</button>
                        <button type="button" onclick="downloadReport('monthly', 'pdf')" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-sky-200 hover:bg-sky-50">Monthly PDF</button>
                        <button type="button" onclick="downloadReport('custom', 'csv')" class="rounded-xl bg-[#edf4ff] px-3 py-2.5 text-sm font-semibold text-[#123b6d] transition hover:bg-[#dbeafe]">Selected dates CSV</button>
                        <button type="button" onclick="downloadReport('custom', 'pdf')" class="rounded-xl bg-[#edf4ff] px-3 py-2.5 text-sm font-semibold text-[#123b6d] transition hover:bg-[#dbeafe]">Selected dates PDF</button>
                    </div>
                </div>
            </section>

            <section class="rounded-[30px] border border-slate-200 bg-white p-5 shadow-[0_10px_24px_rgba(15,23,42,0.06)] sm:p-6">
                <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-xl font-black text-slate-900">Attendance report</h2>
                        <p class="text-sm text-slate-500">Showing record summary for: <strong><?= $period_label ?></strong></p>
                    </div>
                    <div class="flex gap-2">
                        <a href="reports.php?period=daily" class="rounded-xl px-4 py-2 text-sm font-semibold <?= $filter_period === 'daily' ? 'bg-[#1d4e89] text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">Daily</a>
                        <a href="reports.php?period=weekly" class="rounded-xl px-4 py-2 text-sm font-semibold <?= $filter_period === 'weekly' ? 'bg-[#1d4e89] text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">Weekly</a>
                        <a href="reports.php?period=monthly" class="rounded-xl px-4 py-2 text-sm font-semibold <?= $filter_period === 'monthly' ? 'bg-[#1d4e89] text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">Monthly</a>
                        <?php if ($filter_period === 'custom'): ?>
                            <span class="rounded-xl bg-[#1d4e89] px-4 py-2 text-sm font-semibold text-white">Selected dates</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="overflow-hidden rounded-2xl border border-slate-200">
                    <?php if (empty($reports)): ?>
                        <div class="p-8 text-center text-sm text-slate-500">No attendance data logged for this timeframe.</div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-left text-sm text-slate-700">
                                <thead class="bg-slate-50 text-[10px] uppercase tracking-[0.16em] text-slate-500">
                                    <tr>
                                        <th class="px-6 py-3">Course</th>
                                        <th class="px-6 py-3">Index number</th>
                                        <th class="px-6 py-3">Student name</th>
                                        <th class="px-6 py-3">Class</th>
                                        <th class="px-6 py-3">Sessions attended</th>
                                        <th class="px-6 py-3">Attendance dates</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($reports as $row): ?>
                                        <tr class="hover:bg-slate-50">
                                            <td class="px-6 py-4 font-semibold text-slate-900"><?= htmlspecialchars($row['course_code']) ?></td>
                                            <td class="px-6 py-4 font-mono text-xs font-bold text-[#1d4e89]"><?= htmlspecialchars($row['index_number']) ?></td>
                                            <td class="px-6 py-4 font-medium"><?= htmlspecialchars($row['full_name']) ?></td>
                                            <td class="px-6 py-4"><?= htmlspecialchars($row['class_name']) ?></td>
                                            <td class="px-6 py-4">
                                                <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800"><?= $row['sessions_attended'] ?> class(es)</span>
                                            </td>
                                            <td class="px-6 py-4 text-xs text-slate-500"><?= htmlspecialchars($row['attendance_dates'] ?? '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
    <script>
        function downloadReport(type, format) {
            const params = new URLSearchParams();
            params.set('type', type);

            if (type === 'custom') {
                const from = document.querySelector('input[name="date_from"]').value;
                const to = document.querySelector('input[name="date_to"]').value;
                if (!from || !to || from > to) {
                    alert('Please choose a valid start and end date.');
                    return;
                }
                params.set('date_from', from);
                params.set('date_to', to);
            }

            const course = document.querySelector('select[name="course_id"]').value;
            const studentClass = document.querySelector('select[name="class_name"]').value;
            params.set('course_id', course);
            params.set('class_name', studentClass);

            window.location.href = (format === 'pdf' ? 'admin_export_pdf.php' : 'admin_export_report.php') + '?' + params.toString();
        }
    </script>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>