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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'maintenance_students')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') === 'add_student') {
        $index_number = normalize_index_number(trim($_POST['index_number'] ?? ''));
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $class_name = strtoupper(trim($_POST['class_name'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (!is_valid_index_number($index_number) || $full_name === '' || !is_valid_phone_number($phone) || !in_array($class_name, ['A', 'B'], true) || !is_valid_password($password)) {
            $error = 'Enter a valid index number, name, phone number, class, and password of at least 8 characters.';
        } else {
            try {
                $stmt = $pdo->prepare('
                    INSERT INTO students (index_number, full_name, phone, class_name, password_hash, privacy_policy_accepted_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ');
                $stmt->execute([$index_number, $full_name, $phone, $class_name, password_hash($password, PASSWORD_ARGON2ID)]);
                record_portal_alert($pdo, 'Student added by maintenance', 'Student ' . $index_number . ' was added by the maintenance portal.', 'medium', 'maintenance');
                $message = 'Student account added successfully.';
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = 'That index number is already registered.';
                } else {
                    $error = 'The student could not be added. Please check the database and try again.';
                    record_portal_alert($pdo, 'Maintenance student creation failed', 'Student creation failed for ' . $index_number, 'high', 'maintenance', $e->getMessage());
                }
            }
        }
    } elseif (($_POST['action'] ?? '') === 'delete_student') {
        $student_id = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$student_id || $student_id < 1) {
            $error = 'Invalid student selected.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT index_number FROM students WHERE id = ?');
                $stmt->execute([$student_id]);
                $student = $stmt->fetch();
                if (!$student) {
                    $error = 'Student account was not found.';
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare('DELETE FROM attendance_records WHERE student_id = ?')->execute([$student_id]);
                    $pdo->prepare('DELETE FROM students WHERE id = ?')->execute([$student_id]);
                    $pdo->commit();
                    record_portal_alert($pdo, 'Student deleted by maintenance', 'Student ' . $student['index_number'] . ' and attendance history were deleted.', 'high', 'maintenance');
                    $message = 'Student account and attendance history deleted.';
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'The student could not be deleted. No changes were saved.';
                record_portal_alert($pdo, 'Maintenance student deletion failed', 'Student deletion failed for student ID ' . $student_id, 'high', 'maintenance', $e->getMessage());
            }
        }
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$search = substr($search, 0, 100);
$student_query = 'SELECT id, index_number, full_name, phone, class_name, created_at FROM students';
$student_params = [];
if ($search !== '') {
    $student_query .= ' WHERE index_number LIKE ? OR full_name LIKE ? OR phone LIKE ? OR class_name LIKE ?';
    $search_term = '%' . $search . '%';
    $student_params = [$search_term, $search_term, $search_term, $search_term];
}
$student_query .= ' ORDER BY full_name ASC, index_number ASC';
$student_stmt = $pdo->prepare($student_query);
$student_stmt->execute($student_params);
$students = $student_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <link rel="apple-touch-icon" href="../assets/logo-dashboard.png">
    <title>Manage Students - Maintenance</title>
    <link rel="stylesheet" href="../assets/tailwind.css">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <main class="mx-auto max-w-7xl px-4 py-6">
        <header class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-3xl border border-slate-800 bg-slate-900 p-5">
            <div class="flex items-center gap-3">
                <img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="h-12 w-12 rounded-2xl bg-white p-1.5 object-contain shadow-sm">
                <div>
                    <p class="text-xs uppercase tracking-[0.25em] text-amber-300">System operations</p>
                    <h1 class="mt-1 text-2xl font-black">Manage students</h1>
                    <p class="text-sm text-slate-400">Add or remove student accounts and their attendance records.</p>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="dashboard.php" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold">Dashboard</a>
                <a href="logout.php" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold">Sign out</a>
            </div>
        </header>

        <?php if ($message): ?><div class="mb-4 rounded-xl border border-emerald-400/30 bg-emerald-400/10 p-3 text-sm text-emerald-200"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="mb-4 rounded-xl border border-red-400/30 bg-red-400/10 p-3 text-sm text-red-200"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="grid gap-5 lg:grid-cols-[minmax(280px,0.8fr)_minmax(0,1.6fr)]">
            <section class="rounded-3xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-xs uppercase tracking-[0.2em] text-slate-400">New account</p>
                <h2 class="mt-1 text-xl font-black">Add student</h2>
                <form method="post" class="mt-5 space-y-3">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_students') ?>">
                    <input type="hidden" name="action" value="add_student">
                    <input name="index_number" required maxlength="30" placeholder="Index number" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm">
                    <input name="full_name" required maxlength="100" placeholder="Full name" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm">
                    <input name="phone" required maxlength="20" placeholder="Phone number" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm">
                    <select name="class_name" required class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm">
                        <option value="">Select class</option>
                        <option value="A">Class A</option>
                        <option value="B">Class B</option>
                    </select>
                    <input type="password" name="password" required minlength="8" placeholder="Password (8+ characters)" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm">
                    <button class="w-full rounded-xl bg-amber-300 px-4 py-3 text-sm font-bold text-slate-950">Add student</button>
                </form>
            </section>

            <section class="rounded-3xl border border-slate-800 bg-slate-900 p-5">
                <div class="flex items-center justify-between gap-3">
                    <div><p class="text-xs uppercase tracking-[0.2em] text-slate-400">Student accounts</p><h2 class="mt-1 text-xl font-black">Registered students</h2></div>
                    <span class="rounded-full bg-slate-800 px-3 py-1 text-xs font-bold"><?= count($students) ?> found</span>
                </div>
                <form method="get" class="mt-4 flex flex-col gap-2 sm:flex-row">
                    <label for="student-search" class="sr-only">Search students</label>
                    <input id="student-search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, index number, phone, or class" class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm">
                    <button class="rounded-xl bg-slate-700 px-4 py-3 text-sm font-bold">Search</button>
                    <?php if ($search !== ''): ?><a href="students.php" class="rounded-xl bg-slate-800 px-4 py-3 text-center text-sm font-semibold">Clear</a><?php endif; ?>
                </form>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-[680px] text-left text-sm">
                        <thead class="border-b border-slate-800 text-xs uppercase tracking-wider text-slate-500">
                            <tr><th class="px-3 py-3">Student</th><th class="px-3 py-3">Index number</th><th class="px-3 py-3">Class</th><th class="px-3 py-3">Phone</th><th class="px-3 py-3 text-right">Action</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td class="px-3 py-3 font-semibold"><?= htmlspecialchars($student['full_name']) ?></td>
                                <td class="px-3 py-3 text-slate-300"><?= htmlspecialchars($student['index_number']) ?></td>
                                <td class="px-3 py-3"><?= htmlspecialchars($student['class_name']) ?></td>
                                <td class="px-3 py-3 text-slate-400"><?= htmlspecialchars($student['phone']) ?></td>
                                <td class="px-3 py-3 text-right">
                                    <form method="post" onsubmit="return confirm('Delete this student and all attendance history?');">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_students') ?>">
                                        <input type="hidden" name="action" value="delete_student">
                                        <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                                        <button class="rounded-lg bg-red-400/10 px-3 py-2 text-xs font-bold text-red-200">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$students): ?><tr><td colspan="5" class="px-3 py-8 text-center text-slate-400">No students registered.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>
