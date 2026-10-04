<?php
// admin/manage_admins.php
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
require_once __DIR__ . '/../includes/bootstrap.php';

ensure_admin_roles($pdo);
ensure_maintenance_tables($pdo);
enforce_portal_maintenance($pdo, 'admin portal');
$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->prepare("SELECT role FROM admins WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
if ($stmt->fetchColumn() !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}

$error = '';
$success = $_SESSION['admin_manage_flash'] ?? '';
unset($_SESSION['admin_manage_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_create_sub_admin'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'admin_create_sub_admin')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $assigned_class = strtoupper(trim($_POST['assigned_class'] ?? ''));

        if ($full_name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_valid_phone_number($phone) || !is_valid_password($password) || !in_array($assigned_class, ['A', 'B'], true)) {
            $error = 'Enter valid account details, a Class A or Class B assignment, and a password of at least 8 characters.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO admins (full_name, email, phone, password_hash, role, assigned_class, is_setup_complete) VALUES (?, ?, ?, ?, 'sub_admin', ?, 1)");
                $stmt->execute([$full_name, $email, $phone, password_hash($password, PASSWORD_ARGON2ID), $assigned_class]);
                $_SESSION['admin_manage_flash'] = 'Class ' . $assigned_class . ' sub-admin created successfully.';
                header('Location: manage_admins.php');
                exit;
            } catch (PDOException $e) {
                $error = $e->getCode() === '23000' ? 'That email address is already registered.' : 'Sub-admin could not be created.';
                record_portal_alert($pdo, 'Sub-admin creation failed', 'Main admin could not create sub-admin ' . $email, 'high', 'admin', $e->getMessage());
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_delete_sub_admin'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'admin_delete_sub_admin')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $sub_admin_id = (int)($_POST['sub_admin_id'] ?? 0);
        if ($sub_admin_id <= 0 || $sub_admin_id === (int)$_SESSION['admin_id']) {
            $error = 'Invalid sub-admin account selected.';
        } else {
            $stmt = $pdo->prepare("SELECT id, full_name, email, assigned_class FROM admins WHERE id = ? AND role = 'sub_admin'");
            $stmt->execute([$sub_admin_id]);
            $sub_admin = $stmt->fetch();

            if (!$sub_admin) {
                $error = 'The selected sub-admin account could not be found.';
            } else {
                $delete_stmt = $pdo->prepare("DELETE FROM admins WHERE id = ? AND role = 'sub_admin'");
                $delete_stmt->execute([$sub_admin_id]);
                if ($delete_stmt->rowCount() !== 1) {
                    $error = 'The sub-admin account could not be deleted.';
                } else {
                    record_portal_alert(
                        $pdo,
                        'Sub-admin account deleted',
                        'The main administrator deleted sub-admin ' . $sub_admin['email'] . ' assigned to Class ' . ($sub_admin['assigned_class'] ?? 'A') . '.',
                        'high',
                        'admin',
                        'Account name: ' . $sub_admin['full_name']
                    );
                    $_SESSION['admin_manage_flash'] = 'Class ' . ($sub_admin['assigned_class'] ?? 'A') . ' sub-admin deleted successfully.';
                    header('Location: manage_admins.php');
                    exit;
                }
            }
        }
    }
}

$sub_admins = $pdo->query("SELECT id, full_name, email, phone, assigned_class, created_at FROM admins WHERE role = 'sub_admin' ORDER BY full_name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <link rel="apple-touch-icon" href="../assets/logo-dashboard.png">
    <title>C-Attend - Sub-admins</title>
    <link rel="stylesheet" href="../assets/tailwind.css">
    <link rel="stylesheet" href="../includes/mobile.css">
</head>
<body class="mobile-page min-h-screen bg-[#f3f6fb] text-slate-800 antialiased">
    <?php render_scheduled_maintenance_popup($pdo); ?>
<div class="mx-auto max-w-5xl px-4 py-5 sm:px-6 lg:px-8">
    <header class="mb-6 rounded-[30px] bg-gradient-to-r from-[#071b2f] via-[#102b4d] to-[#1d4e89] px-5 py-5 text-white shadow-[0_18px_50px_rgba(10,31,64,0.28)]">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="h-12 w-12 shrink-0 object-contain" />
                <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.32em] text-sky-100/80">Main admin</p>
                <h1 class="text-2xl font-black">Class sub-admins</h1>
                </div>
            </div>
            <a href="dashboard.php" class="rounded-xl bg-white/10 px-3 py-2 text-sm font-semibold">Back to dashboard</a>
        </div>
    </header>

    <?php if ($error): ?><div class="mb-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="grid gap-6 lg:grid-cols-[0.85fr_1.15fr]">
        <section class="rounded-[28px] border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-slate-400">Create account</p>
            <h2 class="mt-1 text-xl font-black text-slate-900">New class sub-admin</h2>
            <p class="mt-2 text-sm text-slate-500">This account can only launch attendance and manually mark students for its assigned class.</p>
            <form method="post" class="mt-5 space-y-3">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('admin_create_sub_admin') ?>">
                <input type="text" name="full_name" required placeholder="Full name" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                <input type="email" name="email" required placeholder="Email address" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                <input type="text" name="phone" required placeholder="Phone number" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                <select name="assigned_class" required class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                    <option value="">Assign class</option>
                    <option value="A">Class A</option>
                    <option value="B">Class B</option>
                </select>
                <input type="password" name="password" required minlength="8" placeholder="Password (8+ characters)" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                <button type="submit" name="btn_create_sub_admin" class="w-full rounded-xl bg-[#1d4e89] px-4 py-3 text-sm font-semibold text-white">Create sub-admin</button>
            </form>
        </section>

        <section class="rounded-[28px] border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-slate-400">Accounts</p>
            <h2 class="mt-1 text-xl font-black text-slate-900">Existing sub-admins</h2>
            <div class="mt-5 space-y-3">
                <?php if (!$sub_admins): ?>
                    <p class="rounded-2xl bg-slate-50 p-4 text-sm text-slate-500">No sub-admin accounts have been created.</p>
                <?php else: foreach ($sub_admins as $sub_admin): ?>
                    <div class="rounded-2xl border border-slate-200 p-4">
                        <p class="font-semibold text-slate-900"><?= htmlspecialchars($sub_admin['full_name']) ?></p>
                        <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($sub_admin['email']) ?> · <?= htmlspecialchars($sub_admin['phone']) ?></p>
                        <p class="mt-2 text-xs font-semibold text-sky-700">Class <?= htmlspecialchars($sub_admin['assigned_class'] ?? 'A') ?> attendance only</p>
                        <form method="post" class="mt-4" onsubmit="return confirm('Delete this sub-admin account? This cannot be undone.');">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('admin_delete_sub_admin') ?>">
                            <input type="hidden" name="sub_admin_id" value="<?= (int)$sub_admin['id'] ?>">
                            <button type="submit" name="btn_delete_sub_admin" class="mt-1 block rounded-xl px-3 py-2 text-xs font-bold text-white transition hover:opacity-90" style="background-color:#2563eb !important; color:#ffffff !important; display:block !important;">Delete account</button>
                        </form>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </section>
    </div>
</div>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>
