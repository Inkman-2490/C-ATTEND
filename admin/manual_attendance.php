<?php
// admin/manual_attendance.php
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
expire_attendance_sessions($pdo);

$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$admin_role = $_SESSION['admin_role'] ?? 'admin';
$is_sub_admin = $admin_role === 'sub_admin';
$admin_class = $_SESSION['admin_class'] ?? 'A';
$pending_approvals = [];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_manual_mark'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'admin_manual_mark')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $session_id = (int)($_POST['session_id'] ?? 0);
        $student_id = (int)($_POST['student_id'] ?? 0);

        if ($session_id && $student_id) {
            $stmt = $pdo->prepare("
                SELECT id, target_class
                FROM attendance_sessions
                WHERE id = ?
                  AND start_time <= NOW()
                  AND (
                      (is_active = 1 AND end_time > NOW())
                      OR (end_time <= NOW() AND NOW() <= DATE_ADD(end_time, INTERVAL 24 HOUR))
                  )
            ");
            $stmt->execute([$session_id]);
            $session = $stmt->fetch();
            if (!$session) {
                $error = 'Manual attendance is available while a session is active and for up to 24 hours after it ends.';
            } elseif ($is_sub_admin && $session['target_class'] !== $admin_class) {
                $error = 'Sub-admins can only mark attendance for their assigned class.';
            } else {
                if ($is_sub_admin) {
                    $stmt = $pdo->prepare("SELECT id FROM students WHERE id = ? AND class_name = ?");
                    $stmt->execute([$student_id, $admin_class]);
                    if (!$stmt->fetch()) {
                        $error = 'Sub-admins can only mark attendance for students in their assigned class.';
                        $student_id = 0;
                    }
                }
            }
            if ($student_id > 0 && $error === '') {
                $stmt = $pdo->prepare("SELECT id, approval_status FROM attendance_records WHERE session_id = ? AND student_id = ?");
                $stmt->execute([$session_id, $student_id]);
                $existing_record = $stmt->fetch();
                if ($existing_record && $existing_record['approval_status'] !== 'rejected') {
                    $error = 'Attendance already marked for this student in this session.';
                } else {
                    $approval_status = $is_sub_admin ? 'pending' : 'approved';
                    if ($existing_record) {
                        $stmt = $pdo->prepare("UPDATE attendance_records SET marked_by = 'admin', approval_status = ?, approved_by = ?, approved_at = ?, submitted_by_admin_id = ?, marked_at = NOW(), distance_meters = 0.00 WHERE id = ?");
                        $saved = $stmt->execute([$approval_status, $is_sub_admin ? null : (int)$_SESSION['admin_id'], $is_sub_admin ? null : date('Y-m-d H:i:s'), (int)$_SESSION['admin_id'], (int)$existing_record['id']]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO attendance_records (session_id, student_id, marked_by, approval_status, approved_by, approved_at, submitted_by_admin_id, distance_meters) VALUES (?, ?, 'admin', ?, ?, ?, ?, 0.00)");
                        $saved = $stmt->execute([$session_id, $student_id, $approval_status, $is_sub_admin ? null : (int)$_SESSION['admin_id'], $is_sub_admin ? null : date('Y-m-d H:i:s'), (int)$_SESSION['admin_id']]);
                    }
                    if ($saved) {
                        $success = $is_sub_admin ? 'Manual attendance submitted for main administrator approval.' : 'Manual attendance recorded successfully!';
                    } else {
                        $error = 'Failed to record manual attendance.';
                    }
                }
            }
        } else {
            $error = 'Please select both a session and a student.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_search_past_sessions'])) {
    if (!validate_csrf_token($_POST['csrf_token_past'] ?? null, 'admin_manual_mark_past')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $past_search_date = $_POST['past_search_date'] ?? '';
        $past_search_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $past_search_date) ? $past_search_date : '';

        if ($past_search_date === '') {
            $error = 'Please select a valid date.';
        } else {
            $stmt = $pdo->prepare("
                SELECT s.id, s.start_time, s.end_time, c.course_code, c.course_title
                FROM attendance_sessions s
                JOIN courses c ON c.id = s.course_id
                WHERE DATE(s.start_time) = ?
                  AND s.end_time <= NOW()
                  " . ($is_sub_admin ? "AND (s.target_class = ? OR s.target_class = 'both')" : '') . "
                ORDER BY s.start_time ASC
            ");
            $params = [$past_search_date];
            if ($is_sub_admin) {
                $params[] = $admin_class;
            }
            $stmt->execute($params);
            $past_date_sessions = $stmt->fetchAll();

            if (empty($past_date_sessions)) {
                $error = 'No attendance sessions were found for that date.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_mark_past_attendance'])) {
    if (!validate_csrf_token($_POST['csrf_token_past'] ?? null, 'admin_manual_mark_past')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $student_id = (int)($_POST['past_student_id'] ?? 0);
        $session_id = (int)($_POST['past_session_id'] ?? 0);

        if ($student_id <= 0 || $session_id <= 0) {
            $error = 'Please choose a student and a session for the selected date.';
        } else {
            $stmt = $pdo->prepare("SELECT id, target_class FROM attendance_sessions WHERE id = ?");
            $stmt->execute([$session_id]);
            $session = $stmt->fetch();

            if (!$session) {
                $error = 'The selected past session was not found.';
            } elseif ($is_sub_admin && !($session['target_class'] === $admin_class || $session['target_class'] === 'both')) {
                $error = 'Sub-admins can only mark attendance for sessions in their assigned class.';
            } else {
                if ($is_sub_admin) {
                    $stmt = $pdo->prepare("SELECT id FROM students WHERE id = ? AND class_name = ?");
                    $stmt->execute([$student_id, $admin_class]);
                    if (!$stmt->fetch()) {
                        $error = 'Sub-admins can only mark attendance for students in their assigned class.';
                    }
                }
            }

            if ($error === '') {
                $stmt = $pdo->prepare("SELECT id, approval_status FROM attendance_records WHERE session_id = ? AND student_id = ?");
                $stmt->execute([$session_id, $student_id]);
                $existing_record = $stmt->fetch();
                if ($existing_record && $existing_record['approval_status'] !== 'rejected') {
                    $error = 'Attendance has already been marked for this student in that session.';
                } else {
                    $approval_status = $is_sub_admin ? 'pending' : 'approved';
                    if ($existing_record) {
                        $stmt = $pdo->prepare("UPDATE attendance_records SET marked_by = 'admin', approval_status = ?, approved_by = ?, approved_at = ?, submitted_by_admin_id = ?, marked_at = NOW(), distance_meters = 0.00 WHERE id = ?");
                        $saved = $stmt->execute([$approval_status, $is_sub_admin ? null : (int)$_SESSION['admin_id'], $is_sub_admin ? null : date('Y-m-d H:i:s'), (int)$_SESSION['admin_id'], (int)$existing_record['id']]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO attendance_records (session_id, student_id, marked_by, approval_status, approved_by, approved_at, submitted_by_admin_id, distance_meters) VALUES (?, ?, 'admin', ?, ?, ?, ?, 0.00)");
                        $saved = $stmt->execute([$session_id, $student_id, $approval_status, $is_sub_admin ? null : (int)$_SESSION['admin_id'], $is_sub_admin ? null : date('Y-m-d H:i:s'), (int)$_SESSION['admin_id']]);
                    }
                    if ($saved) {
                        $success = $is_sub_admin ? 'Past attendance submitted for main administrator approval.' : 'Past attendance recorded successfully.';
                    } else {
                        $error = 'Failed to record past attendance.';
                    }

                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approval_action'])) {
    if ($is_sub_admin || !validate_csrf_token($_POST['csrf_token_approval'] ?? null, 'admin_attendance_approval')) {
        $error = 'Only the main administrator can process attendance approvals.';
    } else {
        $record_id = (int)($_POST['record_id'] ?? 0);
        $approval_action = $_POST['approval_action'];
        if (!in_array($approval_action, ['approve', 'reject'], true) || $record_id <= 0) {
            $error = 'Invalid approval request.';
        } else {
            $stmt = $pdo->prepare("
                SELECT r.id, r.session_id, st.index_number, st.full_name
                FROM attendance_records r
                JOIN students st ON st.id = r.student_id
                WHERE r.id = ? AND r.approval_status = 'pending'
            ");
            $stmt->execute([$record_id]);
            $pending_record = $stmt->fetch();
            if (!$pending_record) {
                $error = 'This approval request is no longer pending.';
            } elseif ($approval_action === 'approve') {
                $stmt = $pdo->prepare("UPDATE attendance_records SET approval_status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND approval_status = 'pending'");
                $stmt->execute([(int)$_SESSION['admin_id'], $record_id]);
                record_portal_alert($pdo, 'Manual attendance approved', 'Manual attendance for ' . $pending_record['index_number'] . ' (' . $pending_record['full_name'] . ') was approved by the main administrator.', 'high', 'admin', 'The student is now counted as marked attendance.');
                $success = 'Attendance approved. The student is now marked present.';
            } else {
                $stmt = $pdo->prepare("UPDATE attendance_records SET approval_status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ? AND approval_status = 'pending'");
                $stmt->execute([(int)$_SESSION['admin_id'], $record_id]);
                record_portal_alert($pdo, 'Manual attendance rejected', 'Manual attendance for ' . $pending_record['index_number'] . ' (' . $pending_record['full_name'] . ') was rejected by the main administrator.', 'high', 'admin', 'The attendance record remains saved as rejected for status history; the student remains absent.');
                $success = 'Attendance rejected. The student remains absent.';
            }
        }
    }
}

$session_scope = $is_sub_admin ? " AND s.target_class = " . $pdo->quote($admin_class) : '';
$sessions = $pdo->query("
    SELECT s.id, s.start_time, s.end_time, c.course_code, c.course_title 
    FROM attendance_sessions s 
    JOIN courses c ON s.course_id = c.id 
    WHERE s.start_time <= NOW()
      AND (
          (s.is_active = 1 AND s.end_time > NOW())
          OR (s.end_time <= NOW() AND NOW() <= DATE_ADD(s.end_time, INTERVAL 24 HOUR))
      )
      $session_scope
    ORDER BY s.id DESC LIMIT 20
")->fetchAll();

$students = $pdo->query("
    SELECT id, index_number, full_name
    FROM students
    " . ($is_sub_admin ? "WHERE class_name = " . $pdo->quote($admin_class) : '') . "
    ORDER BY index_number ASC
")->fetchAll();

$past_search_date = $past_search_date ?? '';
$past_date_sessions = $past_date_sessions ?? [];
if (!$is_sub_admin) {
    $pending_approvals = $pdo->query("
        SELECT r.id, r.session_id, st.index_number, st.full_name, c.course_code, c.course_title, s.start_time, r.marked_at
        FROM attendance_records r
        JOIN students st ON st.id = r.student_id
        JOIN attendance_sessions s ON s.id = r.session_id
        JOIN courses c ON c.id = s.course_id
        WHERE r.approval_status = 'pending'
        ORDER BY r.marked_at ASC
    ")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <link rel="apple-touch-icon" href="../assets/logo-dashboard.png">
    <title>C-Attend - Manual Attendance Override</title>
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
            .mobile-page main section { border-radius: 1.25rem; padding: 1rem; }
            .mobile-page h1 { font-size: 1.35rem; }
            .mobile-page h2 { font-size: 1.15rem; }
            .mobile-page form input,
            .mobile-page form select,
            .mobile-page form button { min-height: 2.75rem; max-width: 100%; min-width: 0; }
            .mobile-page input,
            .mobile-page select { max-width: 100%; min-width: 0; }
        }
    </style>
    </head>
    <body class="mobile-page min-h-screen bg-[#f3f6fb] text-slate-800 antialiased">
    <?php render_scheduled_maintenance_popup($pdo); ?>
    <div class="mx-auto max-w-6xl px-4 py-5 sm:px-6 lg:px-8">
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
                    <a href="reports.php" class="rounded-xl border border-white/15 bg-white/8 px-3 py-2 text-white transition hover:bg-white/12">Reports</a>
                    <a href="logout.php" class="rounded-xl bg-white/10 px-3 py-2 text-xs font-semibold text-white transition hover:bg-white/20">Logout</a>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-2xl">
            <section class="rounded-[30px] border border-slate-200 bg-white p-5 shadow-[0_10px_24px_rgba(15,23,42,0.06)] sm:p-8">
                <div class="mb-6">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.24em] text-sky-700">Override</p>
                    <h2 class="mt-1 text-2xl font-black text-slate-900">Mark manual attendance</h2>
                    <p class="mt-2 text-sm text-slate-500">Override attendance for students experiencing technical issues or offline phones. Manual marking remains available for 24 hours after a session ends.</p>
                </div>

                <?php if ($error): ?>
                    <div class="mb-4 rounded-2xl border border-red-200 bg-red-50 p-3 text-sm text-red-700"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <?php if (!$is_sub_admin): ?>
                    <div id="approval-queue" class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-amber-700">Approval queue</p>
                        <h3 class="mt-1 text-lg font-black text-slate-900">Sub-admin manual attendance</h3>
                        <?php if (!$pending_approvals): ?>
                            <p class="mt-2 text-sm text-amber-800">No attendance requests are waiting for approval.</p>
                        <?php else: ?>
                            <div class="mt-3 space-y-3">
                                <?php foreach ($pending_approvals as $pending): ?>
                                    <div class="rounded-xl border border-amber-200 bg-white p-3">
                                        <div class="text-sm font-semibold text-slate-900">
                                            <?= htmlspecialchars($pending['index_number']) ?> - <?= htmlspecialchars($pending['full_name']) ?>
                                        </div>
                                        <p class="mt-1 text-xs text-slate-500">
                                            <?= htmlspecialchars($pending['course_code'] . ' - ' . $pending['course_title']) ?>
                                            · <?= date('d M Y, h:i A', strtotime($pending['start_time'])) ?>
                                        </p>
                                        <div class="mt-4 grid grid-cols-1 gap-2 rounded-xl border border-slate-300 p-3 sm:grid-cols-[minmax(0,1fr)_auto]" style="background-color:#f1f5f9;">
                                            <form method="POST" class="min-w-0">
                                                <input type="hidden" name="csrf_token_approval" value="<?= generate_csrf_token('admin_attendance_approval') ?>">
                                                <input type="hidden" name="record_id" value="<?= (int)$pending['id'] ?>">
                                                <input type="hidden" name="approval_action" value="approve">
                                                <button type="submit" class="block w-full rounded-lg px-4 py-3 text-center text-sm font-extrabold shadow-md transition focus:outline-none focus:ring-2 focus:ring-offset-2" style="background-color:#059669;color:#ffffff;border:2px solid #047857;box-shadow:0 4px 6px rgba(6,78,59,.2);">Approve attendance</button>
                                            </form>
                                            <form method="POST" class="min-w-0" onsubmit="return confirm('Reject this manual attendance request?');">
                                                <input type="hidden" name="csrf_token_approval" value="<?= generate_csrf_token('admin_attendance_approval') ?>">
                                                <input type="hidden" name="record_id" value="<?= (int)$pending['id'] ?>">
                                                <input type="hidden" name="approval_action" value="reject">
                                                <button type="submit" class="block w-full rounded-lg px-4 py-3 text-center text-sm font-extrabold shadow-md transition focus:outline-none focus:ring-2 focus:ring-offset-2 sm:w-auto" style="background-color:#dc2626;color:#ffffff;border:2px solid #b91c1c;box-shadow:0 4px 6px rgba(127,29,29,.2);">Reject</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('admin_manual_mark') ?>">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Select session</label>
                        <select name="session_id" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                            <option value="">-- Choose class session --</option>
                            <?php foreach ($sessions as $s): ?>
                                <option value="<?= $s['id'] ?>">
                                    <?= htmlspecialchars($s['course_code']) ?> (<?= date('d M Y, h:i A', strtotime($s['start_time'])) ?> — <?= date('h:i A', strtotime($s['end_time'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Select student</label>
                        <select name="student_id" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                            <option value="">-- Choose student --</option>
                            <?php foreach ($students as $st): ?>
                                <option value="<?= $st['id'] ?>">
                                    <?= htmlspecialchars($st['index_number']) ?> - <?= htmlspecialchars($st['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" name="btn_manual_mark" class="w-full rounded-xl bg-gradient-to-r from-[#1d4e89] to-[#f8d67a] px-4 py-3 text-sm font-semibold text-white shadow-[0_12px_20px_rgba(29,78,137,0.2)] transition hover:opacity-95">
                        Grant manual attendance
                    </button>
                </form>

                <div class="mt-5 border-t border-slate-200 pt-5">
                    <div class="mb-4">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.24em] text-sky-700">Past attendance</p>
                        <h3 class="mt-1 text-xl font-black text-slate-900">Mark attendance using a date</h3>
                        <p class="mt-2 text-sm text-slate-500">Select a date to find completed sessions and mark a student as present retroactively.</p>
                    </div>

                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="csrf_token_past" value="<?= generate_csrf_token('admin_manual_mark_past') ?>">

                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Attendance date</label>
                            <input type="date" name="past_search_date" value="<?= htmlspecialchars($past_search_date) ?>" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                        </div>

                        <button type="submit" name="btn_search_past_sessions" class="w-full rounded-xl border border-slate-200 bg-slate-100 px-4 py-3 text-sm font-semibold text-slate-700 transition hover:border-sky-300 hover:text-sky-700">
                            Search sessions for this date
                        </button>
                    </form>

                    <?php if (!empty($past_search_date) && !empty($past_date_sessions)): ?>
                        <form method="POST" class="mt-4 space-y-4">
                            <input type="hidden" name="csrf_token_past" value="<?= generate_csrf_token('admin_manual_mark_past') ?>">
                            <input type="hidden" name="past_search_date" value="<?= htmlspecialchars($past_search_date) ?>">

                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Session on <?= htmlspecialchars(date('d M Y', strtotime($past_search_date))) ?></label>
                                <select name="past_session_id" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                                    <option value="">-- Choose session --</option>
                                    <?php foreach ($past_date_sessions as $session_item): ?>
                                        <option value="<?= $session_item['id'] ?>">
                                            <?= htmlspecialchars($session_item['course_code']) ?> - <?= htmlspecialchars($session_item['course_title']) ?>
                                            (<?= date('h:i A', strtotime($session_item['start_time'])) ?> to <?= date('h:i A', strtotime($session_item['end_time'])) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Student</label>
                                <select name="past_student_id" required class="w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                                    <option value="">-- Choose student --</option>
                                    <?php foreach ($students as $st): ?>
                                        <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['index_number']) ?> - <?= htmlspecialchars($st['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <button type="submit" name="btn_mark_past_attendance" class="w-full rounded-xl bg-gradient-to-r from-[#1d4e89] to-[#f8d67a] px-4 py-3 text-sm font-semibold text-white shadow-[0_12px_20px_rgba(29,78,137,0.2)] transition hover:opacity-95">
                                Mark past attendance for this student
                            </button>
                        </form>
                    <?php elseif (!empty($past_search_date)): ?>
                        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-700">
                            No attendance sessions were found for this date.
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>