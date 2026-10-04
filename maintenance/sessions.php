<?php
session_start(['cookie_httponly' => true, 'use_strict_mode' => true, 'cookie_samesite' => 'Lax']);
require_once __DIR__ . '/../includes/bootstrap.php';

ensure_maintenance_tables($pdo);
if (!isset($_SESSION['maintenance_user_id'])) {
    header('Location: login.php');
    exit;
}

$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'maintenance_session_actions')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') !== 'delete_session') {
        $error = 'Invalid session action.';
    } else {
        $session_id = (int)($_POST['session_id'] ?? 0);
        if ($session_id <= 0) {
            $error = 'Invalid session selected.';
        } else {
            try {
                $pdo->beginTransaction();
                $session_stmt = $pdo->prepare("
                    SELECT s.id, s.start_time, s.end_time, c.course_code, c.course_title,
                           COUNT(r.id) AS attendance_count
                    FROM attendance_sessions s
                    LEFT JOIN courses c ON c.id = s.course_id
                    LEFT JOIN attendance_records r ON r.session_id = s.id
                    WHERE s.id = ?
                    GROUP BY s.id, s.start_time, s.end_time, c.course_code, c.course_title
                    FOR UPDATE
                ");
                $session_stmt->execute([$session_id]);
                $session = $session_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$session) {
                    $pdo->rollBack();
                    $error = 'The selected session could not be found.';
                } else {
                    $pdo->prepare('DELETE FROM attendance_records WHERE session_id = ?')->execute([$session_id]);
                    $delete_stmt = $pdo->prepare('DELETE FROM attendance_sessions WHERE id = ?');
                    $delete_stmt->execute([$session_id]);

                    if ($delete_stmt->rowCount() !== 1) {
                        throw new RuntimeException('The session was not deleted.');
                    }

                    $pdo->commit();
                    record_portal_alert(
                        $pdo,
                        'Attendance session deleted',
                        'Maintenance staff deleted session #' . $session_id . ' (' . ($session['course_code'] ?? 'Unknown course') . ').',
                        'high',
                        'maintenance',
                        $session['attendance_count'] . ' linked attendance record(s) were also deleted.'
                    );
                    $message = 'Session #' . $session_id . ' and its linked attendance records were deleted.';
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('C-Attend maintenance session deletion failed: ' . $exception->getMessage());
                $error = 'The session could not be deleted. No changes were saved.';
            }
        }
    }
}

$sessions = $pdo->query("
    SELECT s.id, s.target_class, s.start_time, s.end_time, s.is_active, s.status,
           s.created_at, c.course_code, c.course_title, COUNT(r.id) AS attendance_count
    FROM attendance_sessions s
    LEFT JOIN courses c ON c.id = s.course_id
    LEFT JOIN attendance_records r ON r.session_id = s.id
    GROUP BY s.id, s.target_class, s.start_time, s.end_time, s.is_active, s.status,
             s.created_at, c.course_code, c.course_title
    ORDER BY s.start_time DESC, s.id DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <link rel="stylesheet" href="../assets/tailwind.css">
    <title>Attendance Sessions</title>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
<div class="mx-auto max-w-7xl px-4 py-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-3xl border border-slate-800 bg-slate-900 p-5">
        <div>
            <p class="text-xs uppercase tracking-[0.25em] text-amber-300">Maintenance portal</p>
            <h1 class="mt-1 text-2xl font-black">Attendance sessions</h1>
            <p class="mt-1 text-sm text-slate-400">All sessions created in the database.</p>
        </div>
        <div class="flex gap-2">
            <a href="dashboard.php" class="rounded-xl bg-sky-500 px-4 py-2 text-sm font-bold text-white">Dashboard</a>
            <a href="logout.php" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold">Sign out</a>
        </div>
    </header>

    <?php if ($message): ?><div class="mb-5 rounded-2xl border border-emerald-400/30 bg-emerald-400/10 p-4 text-sm text-emerald-200"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="mb-5 rounded-2xl border border-red-400/30 bg-red-400/10 p-4 text-sm text-red-200"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <section class="rounded-3xl border border-red-400/30 bg-red-950/20 p-4 text-sm text-red-200">
        Deleting a session permanently deletes the session and all attendance records linked to it. This cannot be undone.
    </section>

    <section class="mt-5 overflow-hidden rounded-3xl border border-slate-800 bg-slate-900 p-5">
        <div class="mb-4 flex items-center justify-between gap-3">
            <h2 class="text-xl font-black"><?= count($sessions) ?> session<?= count($sessions) === 1 ? '' : 's' ?></h2>
            <a href="sessions.php" class="rounded-xl border border-slate-700 px-3 py-2 text-xs font-semibold text-slate-300">Refresh</a>
        </div>
        <?php if (!$sessions): ?>
            <p class="rounded-2xl border border-dashed border-slate-700 p-6 text-center text-sm text-slate-400">No attendance sessions have been created.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead><tr class="border-b border-slate-700 text-xs uppercase tracking-wide text-slate-500"><th class="px-3 py-3">Session</th><th class="px-3 py-3">Course</th><th class="px-3 py-3">Class</th><th class="px-3 py-3">Schedule</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Marked</th><th class="px-3 py-3">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($sessions as $session): ?>
                        <tr class="border-b border-slate-800 last:border-0">
                            <td class="px-3 py-4 font-bold text-amber-300">#<?= (int)$session['id'] ?></td>
                            <td class="px-3 py-4"><span class="font-semibold"><?= htmlspecialchars($session['course_code'] ?? 'Deleted course') ?></span><br><span class="text-xs text-slate-400"><?= htmlspecialchars($session['course_title'] ?? '') ?></span></td>
                            <td class="px-3 py-4 text-slate-300"><?= htmlspecialchars($session['target_class'] === 'both' ? 'A & B' : $session['target_class']) ?></td>
                            <td class="whitespace-nowrap px-3 py-4 text-xs text-slate-400"><?= htmlspecialchars($session['start_time']) ?><br><?= htmlspecialchars($session['end_time']) ?></td>
                            <td class="px-3 py-4"><span class="rounded-full bg-slate-800 px-2 py-1 text-xs font-bold"><?= htmlspecialchars($session['is_active'] ? ($session['status'] ?? 'active') : 'closed') ?></span></td>
                            <td class="px-3 py-4 text-slate-300"><?= (int)$session['attendance_count'] ?></td>
                            <td class="px-3 py-4"><form method="post" onsubmit="return confirm('Delete session #<?= (int)$session['id'] ?> and all linked attendance records? This cannot be undone.');"><input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_session_actions') ?>"><input type="hidden" name="action" value="delete_session"><input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>"><button class="rounded-lg bg-red-500 px-3 py-2 text-xs font-bold text-white hover:bg-red-600">Delete</button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>
