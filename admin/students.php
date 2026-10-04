<?php
// admin/students.php
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
require_once __DIR__ . '/../includes/bootstrap.php';

ensure_class_columns($pdo);
ensure_admin_roles($pdo);
ensure_maintenance_tables($pdo);
enforce_portal_maintenance($pdo, 'admin portal');
$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$is_main_admin = ($_SESSION['admin_role'] ?? 'admin') === 'admin';
$error = '';
$success = $_SESSION['admin_student_flash'] ?? '';
unset($_SESSION['admin_student_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_delete_duplicate'])) {
    if (!$is_main_admin) {
        $error = 'Only the main administrator can delete duplicate student records.';
    } elseif (!validate_csrf_token($_POST['csrf_token'] ?? null, 'admin_delete_duplicate')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $student_stmt = $pdo->prepare('SELECT index_number FROM students WHERE id = ?');
        $student_stmt->execute([$student_id]);
        $student = $student_stmt->fetch();

        if (!$student) {
            $error = 'The selected student record no longer exists.';
        } else {
            $duplicate_stmt = $pdo->prepare('
                SELECT COUNT(*)
                FROM students
                WHERE LOWER(TRIM(full_name)) = LOWER(TRIM((SELECT full_name FROM students WHERE id = ?)))
                   OR index_number = ?
            ');
            $duplicate_stmt->execute([$student_id, $student['index_number']]);

            if ((int)$duplicate_stmt->fetchColumn() < 2) {
                $error = 'This record is no longer a duplicate by name or index number and was not deleted.';
            } else {
                $delete_stmt = $pdo->prepare('DELETE FROM students WHERE id = ?');
                if ($delete_stmt->execute([$student_id])) {
                    $_SESSION['admin_student_flash'] = 'Duplicate student record deleted successfully.';
                    header('Location: students.php');
                    exit;
                }
                $error = 'The duplicate student record could not be deleted.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_update_student'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'admin_update_student')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $class_name = strtoupper(trim($_POST['class_name'] ?? ''));

        if ($student_id <= 0 || $full_name === '' || !in_array($class_name, ['A', 'B'], true)) {
            $error = 'Please provide a valid student name and class.';
        } elseif (!is_valid_phone_number($phone)) {
            $error = 'Phone number format is invalid.';
        } else {
            $stmt = $pdo->prepare('
                UPDATE students
                SET full_name = ?, phone = ?, class_name = ?
                WHERE id = ?
            ');
            if ($stmt->execute([$full_name, $phone, $class_name, $student_id])) {
                $_SESSION['admin_student_flash'] = 'Student profile updated successfully.';
                header('Location: students.php');
                exit;
            }
            $error = 'Student profile could not be updated.';
        }
    }
}

$search = trim($_GET['search'] ?? '');
$class_filter = strtoupper(trim($_GET['class'] ?? ''));
$selected_student_id = (int)($_GET['student_id'] ?? 0);
$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(st.index_number LIKE ? OR st.full_name LIKE ? OR st.phone LIKE ?)';
    $search_value = '%' . $search . '%';
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
}
if (in_array($class_filter, ['A', 'B'], true)) {
    $where[] = 'st.class_name = ?';
    $params[] = $class_filter;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("
    SELECT
        st.id,
        st.index_number,
        st.full_name,
        st.phone,
        st.class_name,
        st.created_at,
        (SELECT COUNT(*) FROM students duplicate_st WHERE duplicate_st.index_number = st.index_number) AS index_count,
        (SELECT COUNT(*) FROM students duplicate_st WHERE LOWER(TRIM(duplicate_st.full_name)) = LOWER(TRIM(st.full_name))) AS name_count,
        COUNT(DISTINCT s.id) AS completed_sessions,
        COUNT(DISTINCT r.id) AS attended_sessions
    FROM students st
    LEFT JOIN attendance_sessions s
        ON s.start_time <= NOW()
       AND s.end_time <= NOW()
       AND (s.target_class = st.class_name OR s.target_class = 'both')
    LEFT JOIN attendance_records r
        ON r.session_id = s.id
       AND r.student_id = st.id
    $where_sql
    GROUP BY st.id, st.index_number, st.full_name, st.phone, st.class_name, st.created_at
    ORDER BY st.full_name ASC, st.index_number ASC
");
$stmt->execute($params);
$students = $stmt->fetchAll();

$duplicate_index_groups = [];
$duplicate_name_groups = [];
if ($is_main_admin) {
    $duplicate_index_stmt = $pdo->query("
        SELECT index_number, COUNT(*) AS record_count
        FROM students
        GROUP BY index_number
        HAVING COUNT(*) > 1
        ORDER BY index_number ASC
    ");
    foreach ($duplicate_index_stmt->fetchAll() as $group) {
        $member_stmt = $pdo->prepare('
            SELECT id, index_number, full_name, class_name, phone
            FROM students
            WHERE index_number = ?
            ORDER BY id ASC
        ');
        $member_stmt->execute([$group['index_number']]);
        $duplicate_index_groups[] = [
            'label' => $group['index_number'],
            'members' => $member_stmt->fetchAll()
        ];
    }

    $duplicate_name_stmt = $pdo->query("
        SELECT LOWER(TRIM(full_name)) AS normalized_name, MIN(full_name) AS display_name, COUNT(*) AS record_count
        FROM students
        GROUP BY LOWER(TRIM(full_name))
        HAVING COUNT(*) > 1
        ORDER BY display_name ASC
    ");
    foreach ($duplicate_name_stmt->fetchAll() as $group) {
        $member_stmt = $pdo->prepare('
            SELECT id, index_number, full_name, class_name, phone
            FROM students
            WHERE LOWER(TRIM(full_name)) = ?
            ORDER BY id ASC
        ');
        $member_stmt->execute([$group['normalized_name']]);
        $duplicate_name_groups[] = [
            'label' => $group['display_name'],
            'members' => $member_stmt->fetchAll()
        ];
    }
}

$selected_student = null;
$history = [];
if ($selected_student_id > 0) {
    $student_stmt = $pdo->prepare('SELECT id, index_number, full_name, class_name FROM students WHERE id = ?');
    $student_stmt->execute([$selected_student_id]);
    $selected_student = $student_stmt->fetch();

    if ($selected_student) {
        $history_where = [
            's.start_time <= NOW()',
            '(s.target_class = ? OR s.target_class = \'both\')'
        ];
        $history_params = [$selected_student['class_name']];

        $history_stmt = $pdo->prepare("
            SELECT
                s.start_time,
                s.end_time,
                c.course_code,
                c.course_title,
                r.marked_at,
                r.marked_by,
                r.distance_meters,
                CASE
                    WHEN r.id IS NOT NULL THEN 'present'
                    WHEN s.end_time <= NOW() THEN 'absent'
                    ELSE 'pending'
                END AS attendance_status
            FROM attendance_sessions s
            JOIN courses c ON c.id = s.course_id
            LEFT JOIN attendance_records r
                ON r.session_id = s.id
               AND r.student_id = ?
            WHERE " . implode(' AND ', $history_where) . "
            ORDER BY s.start_time DESC
        ");
        $history_stmt->execute(array_merge([$selected_student_id], $history_params));
        $history = $history_stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <link rel="apple-touch-icon" href="../assets/logo-dashboard.png">
    <title>C-Attend - Student Management</title>
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
            .mobile-page table { min-width: 900px; }
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
                    <h1 class="text-2xl font-black tracking-tight">Student management</h1>
                </div>
            </div>
            <div class="admin-nav flex flex-wrap items-center gap-2 text-sm font-medium">
                <a href="dashboard.php" class="rounded-xl border border-white/15 bg-white/10 px-3 py-2 text-white transition hover:bg-white/20">Dashboard</a>
                <a href="reports.php" class="rounded-xl border border-white/15 bg-white/10 px-3 py-2 text-white transition hover:bg-white/20">Reports</a>
                <a href="logout.php" class="rounded-xl bg-white/10 px-3 py-2 text-xs font-semibold text-white transition hover:bg-white/20">Logout</a>
            </div>
        </div>
    </header>

    <?php if ($error): ?>
        <div class="mb-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($is_main_admin): ?>
        <section class="mb-6 rounded-[30px] border border-amber-200 bg-amber-50/60 p-4 shadow-[0_10px_24px_rgba(15,23,42,0.04)] sm:p-5">
            <div class="mb-5">
                <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-amber-700">Duplicate review</p>
                <h2 class="mt-1 text-xl font-black text-slate-900">Review duplicate records separately</h2>
                <p class="mt-1 text-sm text-slate-600">Index-number duplicates and name duplicates are grouped independently. Delete only the record you have confirmed is incorrect.</p>
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                <div class="rounded-2xl border border-amber-200 bg-white p-4">
                    <h3 class="font-bold text-slate-900">Duplicate index numbers</h3>
                    <p class="mt-1 text-xs text-slate-500"><?= count($duplicate_index_groups) ?> group<?= count($duplicate_index_groups) === 1 ? '' : 's' ?></p>
                    <?php if (!$duplicate_index_groups): ?>
                        <p class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-700">No duplicate index numbers found.</p>
                    <?php else: ?>
                        <div class="mt-4 space-y-3">
                            <?php foreach ($duplicate_index_groups as $group): ?>
                                <div class="rounded-xl border border-slate-200 p-3">
                                    <p class="font-mono text-sm font-bold text-slate-900"><?= htmlspecialchars($group['label']) ?></p>
                                    <div class="mt-2 space-y-2">
                                        <?php foreach ($group['members'] as $member): ?>
                                            <div class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2">
                                                <div class="min-w-0">
                                                    <p class="truncate text-sm font-semibold text-slate-800"><?= htmlspecialchars($member['full_name']) ?></p>
                                                    <p class="text-xs text-slate-500">Class <?= htmlspecialchars($member['class_name']) ?> · <?= htmlspecialchars($member['phone']) ?></p>
                                                </div>
                                                <form method="post" class="shrink-0" onsubmit="return confirm('Delete this duplicate student record? Its attendance records will also be removed.');">
                                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('admin_delete_duplicate') ?>">
                                                    <input type="hidden" name="student_id" value="<?= (int)$member['id'] ?>">
                                                    <button type="submit" name="btn_delete_duplicate" class="rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-xs font-semibold text-rose-700">Delete</button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="rounded-2xl border border-amber-200 bg-white p-4">
                    <h3 class="font-bold text-slate-900">Duplicate names</h3>
                    <p class="mt-1 text-xs text-slate-500"><?= count($duplicate_name_groups) ?> group<?= count($duplicate_name_groups) === 1 ? '' : 's' ?></p>
                    <?php if (!$duplicate_name_groups): ?>
                        <p class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-700">No duplicate names found.</p>
                    <?php else: ?>
                        <div class="mt-4 space-y-3">
                            <?php foreach ($duplicate_name_groups as $group): ?>
                                <div class="rounded-xl border border-slate-200 p-3">
                                    <p class="text-sm font-bold text-slate-900"><?= htmlspecialchars($group['label']) ?></p>
                                    <div class="mt-2 space-y-2">
                                        <?php foreach ($group['members'] as $member): ?>
                                            <div class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2">
                                                <div class="min-w-0">
                                                    <p class="truncate font-mono text-sm font-semibold text-slate-800"><?= htmlspecialchars($member['index_number']) ?></p>
                                                    <p class="text-xs text-slate-500">Class <?= htmlspecialchars($member['class_name']) ?> · <?= htmlspecialchars($member['phone']) ?></p>
                                                </div>
                                                <form method="post" class="shrink-0" onsubmit="return confirm('Delete this duplicate student record? Its attendance records will also be removed.');">
                                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('admin_delete_duplicate') ?>">
                                                    <input type="hidden" name="student_id" value="<?= (int)$member['id'] ?>">
                                                    <button type="submit" name="btn_delete_duplicate" class="rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-xs font-semibold text-rose-700">Delete</button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="rounded-[30px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.06)] sm:p-5">
        <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-400">Directory</p>
                <h2 class="text-xl font-black text-slate-900">Registered students</h2>
                <p class="mt-1 text-sm text-slate-500"><?= count($students) ?> student<?= count($students) === 1 ? '' : 's' ?> shown</p>
            </div>
            <form method="get" class="grid gap-2 sm:grid-cols-[minmax(220px,1fr)_130px_auto]">
                <input type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, index, or phone" class="rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                <select name="class" class="rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                    <option value="">All classes</option>
                    <option value="A" <?= $class_filter === 'A' ? 'selected' : '' ?>>Class A</option>
                    <option value="B" <?= $class_filter === 'B' ? 'selected' : '' ?>>Class B</option>
                </select>
                <button class="rounded-xl bg-[#1d4e89] px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-[#163d6d]">Filter</button>
            </form>
        </div>

        <?php if (!$students): ?>
            <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm text-slate-500">No students match the selected filters.</div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-50 text-[10px] uppercase tracking-[0.14em] text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Student</th>
                        <th class="px-4 py-3">Class</th>
                        <th class="px-4 py-3">Contact</th>
                        <th class="px-4 py-3">Attendance</th>
                        <th class="px-4 py-3">Profile</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($students as $student): ?>
                        <?php
                        $completed = (int)$student['completed_sessions'];
                        $attended = (int)$student['attended_sessions'];
                        $rate = $completed > 0 ? (int)round(($attended / $completed) * 100) : 0;
                        ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-4">
                                <p class="font-semibold text-slate-900"><?= htmlspecialchars($student['full_name']) ?></p>
                                <p class="mt-1 font-mono text-xs text-slate-500"><?= htmlspecialchars($student['index_number']) ?></p>
                            </td>
                            <td class="px-4 py-4"><span class="rounded-full bg-sky-100 px-2.5 py-1 text-xs font-semibold text-sky-700">Class <?= htmlspecialchars($student['class_name']) ?></span></td>
                            <td class="px-4 py-4 text-xs"><?= htmlspecialchars($student['phone']) ?></td>
                            <td class="px-4 py-4">
                                <p class="font-semibold <?= $rate < 75 && $completed > 0 ? 'text-rose-600' : 'text-emerald-700' ?>"><?= $rate ?>%</p>
                                <p class="text-xs text-slate-500"><?= $attended ?>/<?= $completed ?> attended</p>
                            </td>
                            <td class="px-4 py-4">
                                <div class="flex min-w-[230px] flex-col gap-2">
                                    <a href="?student_id=<?= (int)$student['id'] ?>" class="text-xs font-semibold text-[#1d4e89]">View history</a>
                                <details>
                                    <summary class="cursor-pointer text-xs font-semibold text-[#1d4e89]">Edit profile</summary>
                                    <form method="post" class="mt-3 space-y-2">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('admin_update_student') ?>">
                                        <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                                        <input type="text" name="full_name" value="<?= htmlspecialchars($student['full_name']) ?>" required class="w-full rounded-lg border border-slate-300 px-2.5 py-2 text-xs">
                                        <input type="text" name="phone" value="<?= htmlspecialchars($student['phone']) ?>" required class="w-full rounded-lg border border-slate-300 px-2.5 py-2 text-xs">
                                        <select name="class_name" class="w-full rounded-lg border border-slate-300 px-2.5 py-2 text-xs">
                                            <option value="A" <?= $student['class_name'] === 'A' ? 'selected' : '' ?>>Class A</option>
                                            <option value="B" <?= $student['class_name'] === 'B' ? 'selected' : '' ?>>Class B</option>
                                        </select>
                                        <button type="submit" name="btn_update_student" class="w-full rounded-lg bg-[#1d4e89] px-3 py-2 text-xs font-semibold text-white">Save changes</button>
                                    </form>
                                    <?php if ($is_main_admin && ((int)$student['index_count'] > 1 || (int)$student['name_count'] > 1)): ?>
                                        <form method="post" class="mt-2" onsubmit="return confirm('Delete this duplicate student record? Its attendance records will also be removed.');">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('admin_delete_duplicate') ?>">
                                            <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                                            <button type="submit" name="btn_delete_duplicate" class="w-full rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">Delete duplicate name/index record</button>
                                        </form>
                                    <?php endif; ?>
                                </details>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($selected_student): ?>
        <section class="mt-6 rounded-[30px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.06)] sm:p-5">
            <div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-400">Attendance history</p>
                    <h2 class="text-xl font-black text-slate-900"><?= htmlspecialchars($selected_student['full_name']) ?></h2>
                    <p class="mt-1 font-mono text-xs text-slate-500"><?= htmlspecialchars($selected_student['index_number']) ?> · Class <?= htmlspecialchars($selected_student['class_name']) ?></p>
                </div>
            </div>

            <?php if (!$history): ?>
                <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm text-slate-500">No completed sessions match these filters.</div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-[760px] text-left text-sm text-slate-700">
                        <thead class="bg-slate-50 text-[10px] uppercase tracking-[0.14em] text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Course</th>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Session time</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Marked at</th>
                            <th class="px-4 py-3">Distance</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        <?php foreach ($history as $record): ?>
                            <?php
                            $attendance_status = $record['attendance_status'];
                            $status_label = ucfirst($attendance_status);
                            $status_class = $attendance_status === 'present'
                                ? 'bg-emerald-100 text-emerald-700'
                                : ($attendance_status === 'absent' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700');
                            ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-slate-900"><?= htmlspecialchars($record['course_code']) ?></p>
                                    <p class="text-xs text-slate-500"><?= htmlspecialchars($record['course_title']) ?></p>
                                </td>
                                <td class="px-4 py-3 text-xs"><?= date('d M Y', strtotime($record['start_time'])) ?></td>
                                <td class="px-4 py-3 text-xs"><?= date('h:i A', strtotime($record['start_time'])) ?> - <?= date('h:i A', strtotime($record['end_time'])) ?></td>
                                <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-[10px] font-semibold <?= $status_class ?>"><?= $status_label ?></span></td>
                                <td class="px-4 py-3 text-xs"><?= $record['marked_at'] ? date('h:i A', strtotime($record['marked_at'])) : ($attendance_status === 'absent' ? 'Missed' : 'Not yet marked') ?></td>
                                <td class="px-4 py-3 text-xs"><?= $record['distance_meters'] !== null ? htmlspecialchars($record['distance_meters']) . 'm' : '-' ?></td>
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
