<?php
session_start(['cookie_httponly' => true, 'use_strict_mode' => true, 'cookie_samesite' => 'Lax']);
require_once __DIR__ . '/../includes/bootstrap.php';
ensure_maintenance_tables($pdo);
$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];

if ((int)$pdo->query('SELECT COUNT(*) FROM maintenance_users')->fetchColumn() > 0) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'maintenance_setup')) {
        $error = 'Security validation failed.';
    } else {
        $name = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_valid_password($password)) {
            $error = 'Enter a name, valid email, and password of at least 8 characters.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO maintenance_users (full_name, email, password_hash) VALUES (?, ?, ?)');
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_ARGON2ID)]);
            header('Location: login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="icon" href="../assets/logo-dashboard.png" type="image/png"><link rel="apple-touch-icon" href="../assets/logo-dashboard.png"><title>Maintenance Setup</title><link rel="stylesheet" href="../assets/tailwind.css"></head>
<body class="min-h-screen bg-slate-950 text-slate-100"><main class="mx-auto flex min-h-screen max-w-md items-center px-5"><section class="w-full rounded-3xl border border-slate-800 bg-slate-900 p-6"><div class="mb-4 flex justify-center"><img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="h-16 w-16 rounded-2xl bg-white p-2 object-contain shadow-lg"></div><p class="text-xs uppercase tracking-[0.25em] text-amber-300">First-time setup</p><h1 class="mt-3 text-3xl font-black">Create maintenance account</h1><?php if ($error): ?><p class="mt-4 rounded-xl bg-red-400/10 p-3 text-sm text-red-200"><?= htmlspecialchars($error) ?></p><?php endif; ?><form method="post" class="mt-6 space-y-4"><input type="hidden" name="csrf_token" value="<?= generate_csrf_token('maintenance_setup') ?>"><input name="full_name" required placeholder="Full name" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3"><input type="email" name="email" required placeholder="Email" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3"><input type="password" name="password" required minlength="8" placeholder="Password (8+ characters)" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3"><button class="w-full rounded-xl bg-amber-300 px-4 py-3 font-bold text-slate-950">Create account</button></form></section></main><?php require __DIR__ . '/../includes/system_footer.php'; ?></body></html>
