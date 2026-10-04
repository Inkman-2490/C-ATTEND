<?php
session_start(['cookie_httponly' => true, 'use_strict_mode' => true, 'cookie_samesite' => 'Lax']);
require_once __DIR__ . '/../includes/bootstrap.php';

ensure_admin_roles($pdo);
ensure_maintenance_tables($pdo);
ensure_portal_alert_table($pdo);

if (!isset($_SESSION['maintenance_user_id'])) {
    header('Location: login.php');
    exit;
}

$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];
$error = '';
$message = $_SESSION['maintenance_admin_flash'] ?? '';
unset($_SESSION['maintenance_admin_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'maintenance_admins')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'create_admin') {
            $full_name = trim((string)($_POST['full_name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $role = (string)($_POST['role'] ?? '');
            $assigned_class = strtoupper(trim((string)($_POST['assigned_class'] ?? '')));

            if ($full_name === '' || strlen($full_name) > 100
                || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100
                || !is_valid_phone_number($phone) || strlen($phone) > 20
                || !is_valid_password($password)
                || !in_array($role, ['admin', 'sub_admin'], true)
                || ($role === 'sub_admin' && !in_array($assigned_class, ['A', 'B'], true))) {
                $error = 'Enter a valid name, email, phone, password (at least 8 characters), role, and class assignment when creating a sub-admin.';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO admins (full_name, email, phone, password_hash, role, assigned_class, is_setup_complete)
                        VALUES (?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([
                        $full_name,
                        $email,
                        $phone,
                        password_hash($password, PASSWORD_ARGON2ID),
                        $role,
                        $role === 'sub_admin' ? $assigned_class : null
                    ]);
                    record_portal_alert(
                        $pdo,
                        'Admin account created by maintenance',
                        'Maintenance created ' . $role . ' account ' . $email . '.',
                        'high',
                        'maintenance'
                    );
                    $_SESSION['maintenance_admin_flash'] = ucfirst(str_replace('_', '-', $role)) . ' account created successfully.';
                    header('Location: admins.php');
                    exit;
                } catch (PDOException $exception) {
                    if ($exception->getCode() === '23000') {
                        $error = 'That email address is already assigned to an account.';
                    } else {
                        error_log('C-Attend maintenance admin creation failed: ' . $exception->getMessage());
                        $error = 'The admin account could not be created.';
                    }
                }
            }
        } elseif ($action === 'update_admin') {
            $admin_id = filter_var($_POST['admin_id'] ?? null, FILTER_VALIDATE_INT);
            $full_name = trim((string)($_POST['full_name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            if (!$admin_id || $admin_id < 1 || $full_name === '' || strlen($full_name) > 100
                || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100
                || !is_valid_phone_number($phone) || strlen($phone) > 20
                || ($password !== '' && !is_valid_password($password))) {
                $error = 'Enter a valid name, email, phone, and either leave the password blank or enter at least 8 characters.';
            } else {
                try {
                    $current_stmt = $pdo->prepare('SELECT id, email, role FROM admins WHERE id = ?');
                    $current_stmt->execute([$admin_id]);
                    $current_admin = $current_stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$current_admin) {
                        $error = 'The selected admin account was not found.';
                    } else {
                        if ($password === '') {
                            $update = $pdo->prepare('UPDATE admins SET full_name = ?, email = ?, phone = ? WHERE id = ?');
                            $update->execute([$full_name, $email, $phone, $admin_id]);
                        } else {
                            $update = $pdo->prepare('UPDATE admins SET full_name = ?, email = ?, phone = ?, password_hash = ? WHERE id = ?');
                            $update->execute([$full_name, $email, $phone, password_hash($password, PASSWORD_ARGON2ID), $admin_id]);
                        }

                        record_portal_alert(
                            $pdo,
                            'Admin account updated by maintenance',
                            'Maintenance updated ' . $current_admin['role'] . ' account ID ' . $admin_id . ' (' . $current_admin['email'] . ').',
                            'high',
                            'maintenance',
                            $password === '' ? 'Name, email, and phone were updated.' : 'Name, email, phone, and password were updated.'
                        );
                        $_SESSION['maintenance_admin_flash'] = 'Admin account updated successfully.';
                        header('Location: admins.php');
                        exit;
                    }
                } catch (PDOException $exception) {
                    if ($exception->getCode() === '23000') {
                        $error = 'That email address is already assigned to another account.';
                    } else {
                        error_log('C-Attend maintenance admin update failed: ' . $exception->getMessage());
                        $error = 'The admin account could not be updated.';
                    }
                }
            }
        } elseif ($action === 'delete_admin') {
            $admin_id = filter_var($_POST['admin_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$admin_id || $admin_id < 1) {
                $error = 'Invalid admin account selected.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->query('SELECT id FROM admins ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
                    $admin_stmt = $pdo->prepare('SELECT id, full_name, email, role FROM admins WHERE id = ?');
                    $admin_stmt->execute([$admin_id]);
                    $admin = $admin_stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$admin) {
                        $pdo->rollBack();
                        $error = 'The selected admin account was not found.';
                    } elseif ($admin['role'] === 'admin') {
                        $main_admin_count = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'admin'")->fetchColumn();
                        if ($main_admin_count <= 1) {
                            $pdo->rollBack();
                            $error = 'The final main-admin account cannot be deleted.';
                        } else {
                            $delete = $pdo->prepare('DELETE FROM admins WHERE id = ?');
                            $delete->execute([$admin_id]);
                            $pdo->commit();
                            record_portal_alert(
                                $pdo,
                                'Admin account deleted by maintenance',
                                'Maintenance deleted ' . $admin['role'] . ' account ' . $admin['email'] . '.',
                                'high',
                                'maintenance',
                                'Account name: ' . $admin['full_name']
                            );
                            $_SESSION['maintenance_admin_flash'] = 'Admin account deleted successfully.';
                            header('Location: admins.php');
                            exit;
                        }
                    } else {
                        $delete = $pdo->prepare('DELETE FROM admins WHERE id = ?');
                        $delete->execute([$admin_id]);
                        $pdo->commit();
                        record_portal_alert(
                            $pdo,
                            'Admin account deleted by maintenance',
                            'Maintenance deleted ' . $admin['role'] . ' account ' . $admin['email'] . '.',
                            'high',
                            'maintenance',
                            'Account name: ' . $admin['full_name']
                        );
                        $_SESSION['maintenance_admin_flash'] = 'Admin account deleted successfully.';
                        header('Location: admins.php');
                        exit;
                    }
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('C-Attend maintenance admin deletion failed: ' . $exception->getMessage());
                    $error = 'The admin account could not be deleted.';
                }
            }
        } else {
            $error = 'Invalid admin account action.';
        }
    }
}

$admins = $pdo->query("
    SELECT id, full_name, email, phone, role, assigned_class, created_at
    FROM admins
    ORDER BY CASE role WHEN 'admin' THEN 0 ELSE 1 END, full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);
$main_admin_count = count(array_filter($admins, static function (array $admin): bool {
    return $admin['role'] === 'admin';
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <link rel="stylesheet" href="../assets/tailwind.css">
    <title>Manage Admin Accounts</title>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
<main class="mx-auto max-w-7xl px-4 py-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-3xl border border-slate-800 bg-slate-900 p-5">
        <div>
            <p class="text-xs uppercase tracking-[0.25em] text-amber-300">Maintenance portal</p>
            <h1 class="mt-1 text-2xl font-black">Manage admin accounts</h1>
            <p class="mt-1 text-sm text-slate-400">Create, edit, reset passwords, and delete main-admin or sub-admin accounts.</p>
        </div>
        <div class="flex gap-2">
            <a href="dashboard.php" class="rounded-xl bg-sky-500 px-4 py-2 text-sm font-bold text-white">Dashboard</a>
            <a href="logout.php" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold">Sign out</a>
        </div>
    </header>

    <?php if ($message): ?><div class="mb-5 rounded-2xl border border-emerald-400/30 bg-emerald-400/10 p-4 text-sm text-emerald-200"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="mb-5 rounded-2xl border border-red-400/30 bg-red-400/10 p-4 text-sm text-red-200"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <section class="mb-6 rounded-3xl border border-amber-300/30 bg-amber-300/10 p-4 text-sm text-amber-100">
        Admin account changes take effect on the next login. The final main-admin account cannot be deleted. There are currently <?= $main_admin_count ?> main-admin account<?= $main_admin_count === 1 ? '' : 's' ?>.
    </section>

    <section class="mb-6 rounded-3xl border border-slate-800 bg-slate-900 p-5">
        <h2 class="text-xl font-black">Create admin account</h2>
        <form method="post" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_admins') ?>">
            <input type="hidden" name="action" value="create_admin">
            <label class="text-sm font-semibold text-slate-300">Full name<input name="full_name" maxlength="100" required class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-white"></label>
            <label class="text-sm font-semibold text-slate-300">Email<input type="email" name="email" maxlength="100" required class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-white"></label>
            <label class="text-sm font-semibold text-slate-300">Phone<input name="phone" maxlength="20" required class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-white"></label>
            <label class="text-sm font-semibold text-slate-300">Role<select name="role" id="new-admin-role" required class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-white"><option value="admin">Main admin</option><option value="sub_admin">Sub-admin</option></select></label>
            <label class="text-sm font-semibold text-slate-300" id="new-admin-class-wrap">Assigned class<select name="assigned_class" id="new-admin-class" class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-white"><option value="">Not applicable for main admin</option><option value="A">Class A</option><option value="B">Class B</option></select></label>
            <label class="text-sm font-semibold text-slate-300">Initial password<input type="password" name="password" minlength="8" required autocomplete="new-password" class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-white"></label>
            <button class="rounded-xl bg-sky-500 px-4 py-3 text-sm font-bold text-white hover:bg-sky-600 sm:col-span-2 lg:col-span-3">Create account</button>
        </form>
    </section>

    <section class="space-y-4">
        <?php foreach ($admins as $admin): ?>
            <article class="rounded-3xl border border-slate-800 bg-slate-900 p-5">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-lg font-bold"><?= htmlspecialchars($admin['full_name']) ?></h2>
                        <p class="text-xs text-slate-400"><?= $admin['role'] === 'admin' ? 'Main admin' : 'Sub-admin · Class ' . htmlspecialchars($admin['assigned_class'] ?? 'unassigned') ?> · Created <?= htmlspecialchars($admin['created_at']) ?></p>
                    </div>
                    <span class="rounded-full bg-slate-800 px-3 py-1 text-xs font-bold text-sky-200"><?= htmlspecialchars($admin['role']) ?></span>
                </div>
                <form method="post" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_admins') ?>">
                    <input type="hidden" name="action" value="update_admin">
                    <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                    <label class="text-sm font-semibold text-slate-300">Full name<input name="full_name" maxlength="100" required value="<?= htmlspecialchars($admin['full_name']) ?>" class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-white"></label>
                    <label class="text-sm font-semibold text-slate-300">Email<input type="email" name="email" maxlength="100" required value="<?= htmlspecialchars($admin['email']) ?>" class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-white"></label>
                    <label class="text-sm font-semibold text-slate-300">Phone<input name="phone" maxlength="20" required value="<?= htmlspecialchars($admin['phone']) ?>" class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-white"></label>
                    <label class="text-sm font-semibold text-slate-300">New password <span class="font-normal text-slate-500">(leave blank to keep current)</span><input type="password" name="password" minlength="8" autocomplete="new-password" class="mt-1 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-white"></label>
                    <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-4">
                        <button class="rounded-xl bg-sky-500 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-600">Save account changes</button>
                    </div>
                </form>
                <?php if ($admin['role'] !== 'admin' || $main_admin_count > 1): ?>
                    <form method="post" class="mt-3" onsubmit="return confirm('Permanently delete this admin account?');">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_admins') ?>">
                        <input type="hidden" name="action" value="delete_admin">
                        <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                        <button class="rounded-xl bg-red-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-red-700">Delete account</button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</main>
<script>
    const newAdminRole = document.getElementById('new-admin-role');
    const newAdminClass = document.getElementById('new-admin-class');
    const newAdminClassWrap = document.getElementById('new-admin-class-wrap');
    function updateClassRequirement() {
        const isSubAdmin = newAdminRole.value === 'sub_admin';
        newAdminClass.required = isSubAdmin;
        newAdminClassWrap.classList.toggle('opacity-50', !isSubAdmin);
    }
    newAdminRole.addEventListener('change', updateClassRequirement);
    updateClassRequirement();
</script>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>
