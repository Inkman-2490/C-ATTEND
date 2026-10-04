<?php
session_start(['cookie_httponly' => true, 'use_strict_mode' => true, 'cookie_samesite' => 'Lax']);
require_once __DIR__ . '/../includes/bootstrap.php';

ensure_portal_alert_table($pdo);
ensure_maintenance_tables($pdo);
$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];

if (isset($_SESSION['maintenance_user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'maintenance_login')) {
        $error = 'Security validation failed. Please refresh and try again.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $stmt = $pdo->prepare('SELECT * FROM maintenance_users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
            $_SESSION['maintenance_user_id'] = $user['id'];
            $_SESSION['maintenance_user_name'] = $user['full_name'];
            header('Location: dashboard.php');
            exit;
        }
        $error = 'Invalid maintenance credentials.';
        record_portal_alert($pdo, 'Failed maintenance login', 'Invalid maintenance login attempt for ' . $email, 'high', 'maintenance');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <link rel="apple-touch-icon" href="../assets/logo-dashboard.png">
    <title>C-Attend Maintenance Portal</title>
    <link rel="stylesheet" href="../assets/tailwind.css">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
<main class="mx-auto flex min-h-screen max-w-md items-center px-5">
    <section class="w-full rounded-3xl border border-slate-800 bg-slate-900 p-6 shadow-2xl">
        <div class="mb-4 flex justify-center">
            <img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="h-16 w-16 rounded-2xl bg-white p-2 object-contain shadow-lg">
        </div>
        <p class="text-xs font-semibold uppercase tracking-[0.25em] text-amber-300">System operations</p>
        <h1 class="mt-3 text-3xl font-black">Maintenance portal</h1>
        <p class="mt-2 text-sm text-slate-400">Restricted tools for keeping C-Attend healthy and recoverable.</p>
        <?php if ($error): ?><div class="mt-5 rounded-xl border border-red-400/30 bg-red-400/10 p-3 text-sm text-red-200"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" class="mt-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_login') ?>">
            <input type="email" name="email" required placeholder="Maintenance email" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white">
            <input type="password" name="password" required placeholder="Password" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white">
            <button class="w-full rounded-xl bg-amber-300 px-4 py-3 font-bold text-slate-950">Sign in</button>
        </form>
    </section>
</main>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>
