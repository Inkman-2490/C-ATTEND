<?php
session_start(['cookie_httponly' => true, 'use_strict_mode' => true, 'cookie_samesite' => 'Lax']);
require_once __DIR__ . '/../includes/bootstrap.php';

ensure_class_columns($pdo);
ensure_privacy_acceptance_column($pdo);
ensure_maintenance_tables($pdo);

if (!isset($_SESSION['maintenance_user_id'])) {
    header('Location: login.php');
    exit;
}

$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];
$message = '';
$error = '';
$selected_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$search = trim((string)($_GET['search'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'maintenance_recovery')) {
        $error = 'Security validation failed. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'update_student') {
            $student_id = (int)($_POST['student_id'] ?? 0);
            $index_number = normalize_index_number(trim($_POST['index_number'] ?? ''));
            $full_name = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $class_name = strtoupper(trim($_POST['class_name'] ?? ''));
            $new_password = trim((string)($_POST['new_password'] ?? ''));

            if ($student_id <= 0) {
                $error = 'Select a valid student record.';
            } elseif (!is_valid_index_number($index_number) || $full_name === '' || !is_valid_phone_number($phone) || !in_array($class_name, ['A', 'B'], true)) {
                $error = 'Please enter a valid index number, name, phone number, and class.';
            } else {
                $stmt = $pdo->prepare('SELECT * FROM students WHERE id = ?');
                $stmt->execute([$student_id]);
                $student = $stmt->fetch();
                if (!$student) {
                    $error = 'Student record was not found.';
                } else {
                    $password_hash = $student['password_hash'];
                    if ($new_password !== '') {
                        if (!is_valid_password($new_password)) {
                            $error = 'Temporary reset password must be at least 8 characters.';
                        } else {
                            $password_hash = password_hash($new_password, PASSWORD_ARGON2ID);
                        }
                    }

                    if ($error === '') {
                        try {
                            $pdo->prepare('UPDATE students SET index_number = ?, full_name = ?, phone = ?, class_name = ?, password_hash = ?, privacy_policy_accepted_at = COALESCE(privacy_policy_accepted_at, NOW()) WHERE id = ?')
                                ->execute([$index_number, $full_name, $phone, $class_name, $password_hash, $student_id]);
                            $selected_id = $student_id;
                            $message = 'Student account updated successfully.';
                            record_portal_alert($pdo, 'Student account recovered', 'Recovery updated for student ' . $index_number, 'medium', 'maintenance');
                        } catch (PDOException $e) {
                            $error = 'The student record could not be updated.';
                            record_portal_alert($pdo, 'Student recovery failed', 'Student recovery failed for student ID ' . $student_id, 'high', 'maintenance', $e->getMessage());
                        }
                    }
                }
            }
        } elseif ($action === 'restore_access') {
            $student_id = (int)($_POST['student_id'] ?? 0);
            if ($student_id <= 0) {
                $error = 'Select a valid student account to restore.';
            } else {
                $stmt = $pdo->prepare('SELECT * FROM students WHERE id = ?');
                $stmt->execute([$student_id]);
                $student = $stmt->fetch();
                if (!$student) {
                    $error = 'Student account was not found.';
                } else {
                    $temp_password = rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=');
                    $temp_password = substr($temp_password, 0, 12) . 'a1!';
                    $pdo->prepare('UPDATE students SET password_hash = ?, privacy_policy_accepted_at = COALESCE(privacy_policy_accepted_at, NOW()) WHERE id = ?')
                        ->execute([password_hash($temp_password, PASSWORD_ARGON2ID), $student_id]);
                    $selected_id = $student_id;
                    $message = 'Student access restored. Temporary password: ' . $temp_password;
                    record_portal_alert($pdo, 'Student access restored', 'Student ' . $student['index_number'] . ' was restored with a temporary password.', 'high', 'maintenance');
                }
            }
        }
    }
}

$student_params = [];
$students_query = 'SELECT id, index_number, full_name, phone, class_name, privacy_policy_accepted_at, created_at FROM students';
$student_where = [];
if ($search !== '') {
    $search_like = '%' . $search . '%';
    $student_where[] = '(index_number LIKE ? OR full_name LIKE ? OR phone LIKE ? OR class_name LIKE ?)';
    $student_params = [$search_like, $search_like, $search_like, $search_like];
}
if (!empty($student_where)) {
    $students_query .= ' WHERE ' . implode(' AND ', $student_where);
}
$students_query .= ' ORDER BY full_name ASC, index_number ASC';
$student_stmt = $pdo->prepare($students_query);
$student_stmt->execute($student_params);
$students = $student_stmt->fetchAll();

if ($selected_id > 0) {
    $selected_stmt = $pdo->prepare('SELECT * FROM students WHERE id = ?');
    $selected_stmt->execute([$selected_id]);
    $selected_student = $selected_stmt->fetch();
    if (!$selected_student) {
        $selected_student = null;
    }
    if ($selected_student) {
        $history_stmt = $pdo->prepare('SELECT r.marked_at, r.distance_meters, r.marked_by, c.course_code, c.course_title, s.start_time FROM attendance_records r JOIN attendance_sessions s ON s.id = r.session_id JOIN courses c ON c.id = s.course_id WHERE r.student_id = ? ORDER BY r.marked_at DESC LIMIT 20');
        $history_stmt->execute([$selected_id]);
        $history = $history_stmt->fetchAll();
    }
} else {
    $selected_student = null;
    $history = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <link rel="apple-touch-icon" href="../assets/logo-dashboard.png">
    <title>Student Recovery - Maintenance</title>
    <link rel="stylesheet" href="../assets/tailwind.css">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <main class="mx-auto max-w-7xl px-4 py-6">
        <header class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-3xl border border-slate-800 bg-slate-900 p-5">
            <div class="flex items-center gap-3">
                <img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="h-12 w-12 rounded-2xl bg-white p-1.5 object-contain shadow-sm">
                <div>
                    <p class="text-xs uppercase tracking-[0.25em] text-amber-300">System operations</p>
                    <h1 class="mt-1 text-2xl font-black">Student recovery</h1>
                    <p class="text-sm text-slate-400">Reset access and restore student details safely.</p>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="dashboard.php" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold">Dashboard</a>
                <a href="students.php" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold">Manage students</a>
                <a href="logout.php" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold">Sign out</a>
            </div>
        </header>

        <?php if ($message): ?><div class="mb-4 rounded-xl border border-emerald-400/30 bg-emerald-400/10 p-3 text-sm text-emerald-200"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="mb-4 rounded-xl border border-red-400/30 bg-red-400/10 p-3 text-sm text-red-200"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
            <section class="rounded-3xl border border-slate-800 bg-slate-900 p-5">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Student lookup</p>
                        <h2 class="mt-1 text-xl font-black">Find a student</h2>
                    </div>
                    <span class="rounded-full bg-slate-800 px-3 py-1 text-xs font-bold"><?= count($students) ?> found</span>
                </div>
                <form method="get" class="mt-4 flex flex-col gap-2 sm:flex-row">
                    <input name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name, index, phone or class" class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm">
                    <button class="rounded-xl bg-amber-300 px-4 py-3 text-sm font-bold text-slate-950">Search</button>
                    <?php if ($search !== ''): ?><a href="student_recovery.php" class="rounded-xl bg-slate-800 px-4 py-3 text-center text-sm font-semibold">Clear</a><?php endif; ?>
                </form>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-[620px] text-left text-sm">
                        <thead class="border-b border-slate-800 text-xs uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-3 py-3">Student</th>
                                <th class="px-3 py-3">Index</th>
                                <th class="px-3 py-3">Class</th>
                                <th class="px-3 py-3">Phone</th>
                                <th class="px-3 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td class="px-3 py-3 font-semibold"><?= htmlspecialchars($student['full_name']) ?></td>
                                    <td class="px-3 py-3 text-slate-300"><?= htmlspecialchars($student['index_number']) ?></td>
                                    <td class="px-3 py-3"><?= htmlspecialchars($student['class_name']) ?></td>
                                    <td class="px-3 py-3 text-slate-400"><?= htmlspecialchars($student['phone']) ?></td>
                                    <td class="px-3 py-3 text-right">
                                        <a href="student_recovery.php?search=<?= urlencode($search) ?>&student_id=<?= (int)$student['id'] ?>" class="rounded-lg bg-sky-400/10 px-3 py-2 text-xs font-bold text-sky-200">Select</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$students): ?><tr><td colspan="5" class="px-3 py-8 text-center text-slate-400">No matching students found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-3xl border border-slate-800 bg-slate-900 p-5">
                <?php if ($selected_student): ?>
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Recovery form</p>
                    <h2 class="mt-1 text-xl font-black">Recover student access</h2>
                    <form method="post" class="mt-5 space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_recovery') ?>">
                        <input type="hidden" name="action" value="update_student">
                        <input type="hidden" name="student_id" value="<?= (int)$selected_student['id'] ?>">
                        <input name="index_number" value="<?= htmlspecialchars($selected_student['index_number']) ?>" required maxlength="30" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm">
                        <input name="full_name" value="<?= htmlspecialchars($selected_student['full_name']) ?>" required maxlength="100" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm">
                        <input name="phone" value="<?= htmlspecialchars($selected_student['phone']) ?>" required maxlength="20" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm">
                        <select name="class_name" required class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm">
                            <option value="A" <?= $selected_student['class_name'] === 'A' ? 'selected' : '' ?>>Class A</option>
                            <option value="B" <?= $selected_student['class_name'] === 'B' ? 'selected' : '' ?>>Class B</option>
                        </select>
                        <input type="password" name="new_password" minlength="8" placeholder="New password (optional)" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm">
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <button class="flex-1 rounded-xl bg-amber-300 px-4 py-3 text-sm font-bold text-slate-950">Save changes</button>
                            <button type="submit" formaction="student_recovery.php" formmethod="post" name="action" value="restore_access" class="flex-1 rounded-xl bg-red-400/10 px-4 py-3 text-sm font-bold text-red-200">Restore access</button>
                        </div>
                        <input type="hidden" name="student_id" value="<?= (int)$selected_student['id'] ?>">
                    </form>
                    <div class="mt-6 rounded-2xl border border-slate-800 bg-slate-950 p-4">
                        <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Attendance history</p>
                        <?php if (!$history): ?><p class="mt-3 text-sm text-slate-400">No attendance records for this student.</p><?php else: ?><div class="mt-3 space-y-2"><?php foreach ($history as $record): ?><div class="rounded-xl border border-slate-800 bg-slate-900 p-3"><div class="flex justify-between gap-3"><span class="font-semibold"><?= htmlspecialchars($record['course_code']) ?></span><span class="text-xs text-slate-400"><?= htmlspecialchars($record['marked_by']) ?></span></div><p class="mt-1 text-xs text-slate-400"><?= htmlspecialchars($record['course_title']) ?></p><p class="mt-2 text-xs text-slate-500"><?= htmlspecialchars(date('d M Y h:i A', strtotime($record['marked_at']))) ?> · <?= $record['distance_meters'] !== null ? htmlspecialchars((string)$record['distance_meters']) . 'm' : 'No distance' ?></p></div><?php endforeach; ?></div><?php endif; ?>
                    </div>
                <?php else: ?>
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Recovery form</p>
                    <h2 class="mt-1 text-xl font-black">No student selected</h2>
                    <p class="mt-3 text-sm text-slate-400">Use the search panel to choose a student account, then update details or restore access.</p>
                <?php endif; ?>
            </section>
        </div>
    </main>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>
