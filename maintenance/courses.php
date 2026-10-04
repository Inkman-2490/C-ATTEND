<?php
session_start(['cookie_httponly' => true, 'use_strict_mode' => true, 'cookie_samesite' => 'Lax']);
require_once __DIR__ . '/../includes/bootstrap.php';

ensure_portal_alert_table($pdo);
ensure_maintenance_tables($pdo);

if (!isset($_SESSION['maintenance_user_id'])) {
    header('Location: login.php');
    exit;
}

$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'maintenance_courses')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') === 'update_course') {
        $course_id = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT);
        $course_code = strtoupper(trim((string)($_POST['course_code'] ?? '')));
        $course_title = trim((string)($_POST['course_title'] ?? ''));

        if (!$course_id || $course_id < 1 || $course_code === '' || $course_title === '') {
            $error = 'Course code and course name are required.';
        } elseif (strlen($course_code) > 20 || strlen($course_title) > 150) {
            $error = 'Course code or course name is too long.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM courses WHERE id = ?');
            $stmt->execute([$course_id]);
            if (!$stmt->fetch()) {
                $error = 'The selected course was not found.';
            } else {
                $duplicate = $pdo->prepare('SELECT id FROM courses WHERE course_code = ? AND id <> ?');
                $duplicate->execute([$course_code, $course_id]);
                if ($duplicate->fetch()) {
                    $error = 'That course code is already in use.';
                } else {
                    try {
                        $update = $pdo->prepare('UPDATE courses SET course_code = ?, course_title = ? WHERE id = ?');
                        $update->execute([$course_code, $course_title, $course_id]);
                        record_portal_alert(
                            $pdo,
                            'Course updated by maintenance',
                            'Course ID ' . $course_id . ' was updated to ' . $course_code . ' - ' . $course_title . '.',
                            'medium',
                            'maintenance'
                        );
                        $message = 'Course updated successfully.';
                    } catch (PDOException $e) {
                        $error = 'The course could not be updated.';
                        record_portal_alert($pdo, 'Maintenance course update failed', 'Course ID ' . $course_id . ' could not be updated.', 'high', 'maintenance', $e->getMessage());
                    }
                }
            }
        }
    } elseif (($_POST['action'] ?? '') === 'merge_duplicate_course') {
        $source_course_id = filter_var($_POST['source_course_id'] ?? null, FILTER_VALIDATE_INT);
        $target_course_id = filter_var($_POST['target_course_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$source_course_id || !$target_course_id || $source_course_id < 1 || $target_course_id < 1 || $source_course_id === $target_course_id) {
            $error = 'Select two different duplicate courses to merge.';
        } else {
            try {
                $pdo->beginTransaction();
                $ids = [$source_course_id, $target_course_id];
                sort($ids, SORT_NUMERIC);
                $lock_stmt = $pdo->prepare('SELECT id, course_code, course_title FROM courses WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
                $lock_stmt->execute($ids);
                $locked_courses = $lock_stmt->fetchAll(PDO::FETCH_ASSOC);
                $course_by_id = [];
                foreach ($locked_courses as $locked_course) {
                    $course_by_id[(int)$locked_course['id']] = $locked_course;
                }

                $source_course = $course_by_id[$source_course_id] ?? null;
                $target_course = $course_by_id[$target_course_id] ?? null;
                $same_duplicate = $source_course && $target_course
                    && strtolower(trim($source_course['course_code'])) === strtolower(trim($target_course['course_code']))
                    && strtolower(trim($source_course['course_title'])) === strtolower(trim($target_course['course_title']));

                if (!$same_duplicate) {
                    $pdo->rollBack();
                    $error = 'Only courses with the same course code and course name can be merged.';
                } else {
                    $session_count_stmt = $pdo->prepare('SELECT COUNT(*) FROM attendance_sessions WHERE course_id = ?');
                    $session_count_stmt->execute([$source_course_id]);
                    $moved_sessions = (int)$session_count_stmt->fetchColumn();

                    $move_stmt = $pdo->prepare('UPDATE attendance_sessions SET course_id = ? WHERE course_id = ?');
                    $move_stmt->execute([$target_course_id, $source_course_id]);
                    $delete_stmt = $pdo->prepare('DELETE FROM courses WHERE id = ?');
                    $delete_stmt->execute([$source_course_id]);

                    if ($delete_stmt->rowCount() !== 1) {
                        throw new RuntimeException('Duplicate course was not deleted after moving its sessions.');
                    }

                    $pdo->commit();
                    $resulting_session_count_stmt = $pdo->prepare('SELECT COUNT(*) FROM attendance_sessions WHERE course_id = ?');
                    $resulting_session_count_stmt->execute([$target_course_id]);
                    $resulting_session_count = (int)$resulting_session_count_stmt->fetchColumn();
                    record_portal_alert(
                        $pdo,
                        'Duplicate courses merged',
                        'Maintenance merged course ID ' . $source_course_id . ' into course ID ' . $target_course_id . ' (' . $target_course['course_code'] . ' - ' . $target_course['course_title'] . ').',
                        'high',
                        'maintenance',
                        $moved_sessions . ' attendance session(s), including their attendance history, were preserved under the retained course. The retained course now has ' . $resulting_session_count . ' session(s).'
                    );
                    $message = 'Duplicate course merged successfully. Its ' . $moved_sessions . ' session(s) and attendance history were preserved. The retained course now has ' . $resulting_session_count . ' total session(s).';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'The duplicate courses could not be merged. No changes were saved.';
                record_portal_alert($pdo, 'Duplicate course merge failed', 'Course IDs ' . $source_course_id . ' and ' . $target_course_id . ' could not be merged.', 'high', 'maintenance', $e->getMessage());
            }
        }
    } elseif (($_POST['action'] ?? '') === 'delete_course') {
        $course_id = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$course_id || $course_id < 1) {
            $error = 'Invalid course selected.';
        } else {
            $course_stmt = $pdo->prepare('SELECT course_code, course_title FROM courses WHERE id = ?');
            $course_stmt->execute([$course_id]);
            $course = $course_stmt->fetch();

            if (!$course) {
                $error = 'The selected course was not found.';
            } else {
                $attendance_stmt = $pdo->prepare('
                    SELECT COUNT(*)
                    FROM attendance_records r
                    JOIN attendance_sessions s ON s.id = r.session_id
                    WHERE s.course_id = ?
                ');
                $attendance_stmt->execute([$course_id]);

                if ((int)$attendance_stmt->fetchColumn() > 0) {
                    $error = 'This course cannot be deleted because it has attendance history.';
                } else {
                    try {
                        $pdo->beginTransaction();
                        $pdo->prepare('DELETE FROM attendance_sessions WHERE course_id = ?')->execute([$course_id]);
                        $pdo->prepare('DELETE FROM courses WHERE id = ?')->execute([$course_id]);
                        $pdo->commit();
                        record_portal_alert(
                            $pdo,
                            'Course deleted by maintenance',
                            'Course ' . $course['course_code'] . ' - ' . $course['course_title'] . ' was deleted because it had no attendance history.',
                            'high',
                            'maintenance'
                        );
                        $message = 'Course deleted successfully.';
                    } catch (PDOException $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = 'The course could not be deleted. No changes were saved.';
                        record_portal_alert($pdo, 'Maintenance course deletion failed', 'Course ID ' . $course_id . ' could not be deleted.', 'high', 'maintenance', $e->getMessage());
                    }
                }
            }
        }
    }
}

$courses = $pdo->query('
    SELECT
        c.id,
        c.course_code,
        c.course_title,
        c.semester,
        c.academic_year,
        EXISTS (
            SELECT 1
            FROM attendance_sessions s
            JOIN attendance_records r ON r.session_id = s.id
            WHERE s.course_id = c.id
        ) AS has_attendance_history,
        (
            SELECT COUNT(*)
            FROM attendance_sessions session_count
            WHERE session_count.course_id = c.id
        ) AS session_count
    FROM courses c
    ORDER BY c.course_code ASC
')->fetchAll();
$duplicate_course_groups = [];
foreach ($courses as $course) {
    $duplicate_key = strtolower(trim($course['course_code'])) . "\0" . strtolower(trim($course['course_title']));
    $duplicate_course_groups[$duplicate_key][] = $course;
}
$duplicate_course_groups = array_values(array_filter($duplicate_course_groups, static function (array $group): bool {
    return count($group) > 1;
}));
$total_session_count = (int)$pdo->query('SELECT COUNT(*) FROM attendance_sessions')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <title>Manage Courses - Maintenance</title>
    <link rel="stylesheet" href="../assets/tailwind.css">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <main class="mx-auto max-w-7xl px-4 py-6">
        <header class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-3xl border border-slate-800 bg-slate-900 p-5">
            <div class="flex items-center gap-3">
                <img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="h-12 w-12 rounded-2xl bg-white p-1.5 object-contain shadow-sm">
                <div>
                    <p class="text-xs uppercase tracking-[0.25em] text-amber-300">System operations</p>
                    <h1 class="mt-1 text-2xl font-black">Manage courses</h1>
                    <p class="text-sm text-slate-400">Update course names and codes used by attendance sessions.</p>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="dashboard.php" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold">Dashboard</a>
                <a href="logout.php" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold">Sign out</a>
            </div>
        </header>

        <?php if ($message): ?><div class="mb-4 rounded-xl border border-emerald-400/30 bg-emerald-400/10 p-3 text-sm text-emerald-200"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="mb-4 rounded-xl border border-red-400/30 bg-red-400/10 p-3 text-sm text-red-200"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if ($duplicate_course_groups): ?>
            <section class="mb-5 rounded-3xl border border-sky-400/30 bg-slate-900 p-5">
                <p class="text-xs uppercase tracking-[0.2em] text-sky-300">Duplicate cleanup</p>
                <h2 class="mt-1 text-xl font-black">Merge duplicate courses</h2>
                <p class="mt-2 text-sm text-slate-400">Choose which course record to keep. Sessions and their existing attendance records will be reassigned to it before the duplicate course is removed.</p>
                <div class="mt-4 space-y-4">
                    <?php foreach ($duplicate_course_groups as $duplicate_group): ?>
                        <form method="post" class="grid gap-3 rounded-2xl border border-slate-700 bg-slate-950 p-4 lg:grid-cols-[1fr_1fr_auto] lg:items-end" onsubmit="return confirm('Merge these duplicate courses? Their sessions and existing attendance records will be retained under the chosen course.');">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_courses') ?>">
                            <input type="hidden" name="action" value="merge_duplicate_course">
                            <label class="block text-sm font-semibold text-slate-300">
                                Keep this course
                                <select name="target_course_id" required class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-sm text-white">
                                    <?php foreach ($duplicate_group as $duplicate_course): ?>
                                        <option value="<?= (int)$duplicate_course['id'] ?>">#<?= (int)$duplicate_course['id'] ?> · <?= htmlspecialchars($duplicate_course['semester'] . ' ' . $duplicate_course['academic_year']) ?> · <?= (int)$duplicate_course['session_count'] ?> session(s)</option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="block text-sm font-semibold text-slate-300">
                                Merge this duplicate into the course above
                                <select name="source_course_id" required class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-sm text-white">
                                    <?php foreach ($duplicate_group as $duplicate_course): ?>
                                        <option value="<?= (int)$duplicate_course['id'] ?>">#<?= (int)$duplicate_course['id'] ?> · <?= htmlspecialchars($duplicate_course['semester'] . ' ' . $duplicate_course['academic_year']) ?> · <?= (int)$duplicate_course['session_count'] ?> session(s)</option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button type="submit" class="rounded-xl bg-sky-500 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-sky-600">Merge courses</button>
                            <p class="text-xs text-slate-500 lg:col-span-3"><?= htmlspecialchars($duplicate_group[0]['course_code']) ?> · <?= htmlspecialchars($duplicate_group[0]['course_title']) ?></p>
                        </form>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="mb-5 rounded-3xl border border-slate-800 bg-slate-900 p-5">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Attendance totals</p>
                    <h2 class="mt-1 text-xl font-black">Sessions by course after merge</h2>
                </div>
                <div class="rounded-xl bg-sky-500/15 px-4 py-2 text-sm font-bold text-sky-200">
                    <?= $total_session_count ?> total session<?= $total_session_count === 1 ? '' : 's' ?> in database
                </div>
            </div>
            <?php if ($courses): ?>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <?php foreach ($courses as $course): ?>
                        <div class="rounded-2xl border border-slate-800 bg-slate-950 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400"><?= htmlspecialchars($course['course_code']) ?></p>
                            <p class="mt-1 truncate font-semibold text-slate-100"><?= htmlspecialchars($course['course_title']) ?></p>
                            <p class="mt-3 text-2xl font-black text-sky-300"><?= (int)$course['session_count'] ?></p>
                            <p class="text-xs text-slate-500">session<?= (int)$course['session_count'] === 1 ? '' : 's' ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-sm text-slate-400">No courses or sessions to summarize.</p>
            <?php endif; ?>
        </section>

        <section class="rounded-3xl border border-slate-800 bg-slate-900 p-5">
            <div class="mb-5">
                <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Course directory</p>
                <h2 class="mt-1 text-xl font-black">Edit course details</h2>
            </div>
            <div class="space-y-4">
                <?php foreach ($courses as $course): ?>
                    <form method="post" class="grid gap-3 rounded-2xl border border-slate-800 bg-slate-950 p-4 lg:grid-cols-[minmax(140px,0.5fr)_minmax(220px,1.5fr)_auto_auto] lg:items-end">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_courses') ?>">
                        <input type="hidden" name="action" value="update_course">
                        <input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>">
                        <label class="block text-sm font-semibold text-slate-300">
                            Course code
                            <input name="course_code" required maxlength="20" value="<?= htmlspecialchars($course['course_code']) ?>" class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-sm text-white">
                        </label>
                        <label class="block text-sm font-semibold text-slate-300">
                            Course name
                            <input name="course_title" required maxlength="150" value="<?= htmlspecialchars($course['course_title']) ?>" class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-sm text-white">
                        </label>
                        <button type="submit" class="rounded-xl bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950">Save changes</button>
                        <?php if (!(bool)$course['has_attendance_history']): ?>
                            <button type="submit" name="action" value="delete_course" formnovalidate onclick="return confirm('Delete this course? This is allowed only because it has no attendance history.');" class="rounded-xl bg-red-400/10 px-4 py-2.5 text-sm font-bold text-red-200 transition hover:bg-red-400/20">Delete course</button>
                        <?php else: ?>
                            <span class="rounded-xl bg-slate-800 px-4 py-2.5 text-center text-xs font-semibold text-slate-500">Attendance history</span>
                        <?php endif; ?>
                        <p class="text-xs text-slate-500 lg:col-span-4"><?= htmlspecialchars($course['semester']) ?> · <?= htmlspecialchars($course['academic_year']) ?></p>
                    </form>
                <?php endforeach; ?>
                <?php if (!$courses): ?><p class="text-sm text-slate-400">No courses found.</p><?php endif; ?>
            </div>
        </section>
    </main>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>
