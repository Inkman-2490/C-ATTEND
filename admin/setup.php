<?php
// admin/setup.php
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
require_once __DIR__ . '/../includes/bootstrap.php';

ensure_portal_alert_table($pdo);
ensure_admin_roles($pdo);
ensure_maintenance_tables($pdo);
enforce_portal_maintenance($pdo, 'admin portal');

$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];

$stmt = $pdo->query("SELECT COUNT(*) FROM admins");
if ($stmt->fetchColumn() > 0) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'admin_setup')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $full_name = sanitize_input($_POST['full_name'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');
        $phone = sanitize_input($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($full_name) || empty($email) || empty($phone) || empty($password)) {
            $error = 'Please fill in all fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please provide a valid email address.';
        } elseif (!is_valid_phone_number($phone)) {
            $error = 'Please provide a valid phone number.';
        } elseif (!is_valid_password($password)) {
            $error = 'Password must be at least 8 characters long.';
        } else {
            $password_hash = password_hash($password, PASSWORD_ARGON2ID);

            $stmt = $pdo->prepare("INSERT INTO admins (full_name, email, phone, password_hash, is_setup_complete) VALUES (?, ?, ?, ?, 1)");
            if ($stmt->execute([$full_name, strtolower($email), $phone, $password_hash])) {
                $_SESSION['admin_success'] = 'Class Rep Admin account created! Please log in.';
                header('Location: login.php');
                exit;
            } else {
                $error = 'Failed to complete setup.';
                record_portal_alert($pdo, 'Admin setup failed', 'System attempted to create the first admin account but the insert failed for email ' . $email, 'critical', 'admin', 'Database insert error');
            }
        }
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
    <title>C-Attend - Initial Class Rep Setup</title>
    <link rel="stylesheet" href="../assets/tailwind.css">
    <link rel="stylesheet" href="../includes/mobile.css">
    <style>
        @media (max-width: 640px) {
            .mobile-auth,
            .mobile-auth * { box-sizing: border-box; }
            .mobile-auth { overflow-x: hidden; }
            .mobile-auth > div {
                width: 100%;
                max-width: 100%;
                padding: max(0.75rem, env(safe-area-inset-top)) max(0.75rem, env(safe-area-inset-right)) max(0.75rem, env(safe-area-inset-bottom)) max(0.75rem, env(safe-area-inset-left));
            }
            .mobile-auth > div > div { min-width: 0; max-width: 100%; }
            .mobile-auth .bg-slate-50\/80 { padding: 1rem; }
            .mobile-auth h1,
            .mobile-auth h2,
            .mobile-auth p { overflow-wrap: anywhere; }
            .mobile-auth h1 { font-size: 1.25rem; }
            .mobile-auth form,
            .mobile-auth form > div { min-width: 0; }
            .mobile-auth form input,
            .mobile-auth form button {
                width: 100%;
                max-width: 100%;
                min-width: 0;
                min-height: 2.75rem;
            }
            .mobile-auth input { font-size: 16px; }
        }
    </style>
    </head>
    <body class="mobile-auth min-h-screen bg-[#eef4ff] text-slate-800 antialiased">
    <div class="mx-auto flex min-h-screen w-full max-w-7xl items-center justify-center px-4 py-8 sm:px-6 lg:px-8">
        <div class="grid w-full max-w-6xl overflow-hidden rounded-[32px] border border-slate-200 bg-white shadow-[0_30px_80px_rgba(10,31,64,0.12)] lg:grid-cols-[1.1fr_0.9fr]">
            <div class="hidden bg-gradient-to-br from-[#071b2f] via-[#102b4d] to-[#1d4e89] p-8 text-white lg:flex lg:flex-col lg:justify-between">
                <div>
                    <div class="mb-8 flex items-center gap-4">
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white p-1.5 shadow-sm">
                            <img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="h-full w-full object-contain" />
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.32em] text-sky-100/80">Campus setup</p>
                            <h1 class="text-3xl font-black tracking-tight">C-Attend</h1>
                        </div>
                    </div>

                    <div class="max-w-md">
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-sky-100/80">New administrator</p>
                        <h2 class="mt-4 text-4xl font-black leading-tight">Create the system owner for your campus portal.</h2>
                        <p class="mt-4 text-base text-sky-100/80">Set up secure access for attendance monitoring, reports, and class coordination.</p>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm">
                        <p class="text-[10px] uppercase tracking-[0.2em] text-sky-100/80">Portal</p>
                        <p class="mt-3 text-3xl font-black">Ready</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm">
                        <p class="text-[10px] uppercase tracking-[0.2em] text-sky-100/80">Access</p>
                        <p class="mt-3 text-3xl font-black">Secure</p>
                    </div>
                </div>
            </div>

            <div class="bg-slate-50/80 p-6 sm:p-8">
                <div class="mb-5 flex items-center justify-center gap-3 lg:hidden">
                    <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white p-1.5 shadow-sm">
                        <img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="h-full w-full object-contain" />
                    </div>
                </div>
                <div class="mb-6 text-center lg:text-left">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-sky-700">Setup account</p>
                    <h1 class="mt-2 text-2xl font-black text-slate-900">Create admin account</h1>
                </div>

                <?php if ($error): ?>
                    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2.5 text-sm text-red-700"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('admin_setup') ?>">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Full name</label>
                        <input type="text" name="full_name" required class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-800 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Email address</label>
                        <input type="email" name="email" required class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-800 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Phone number</label>
                        <input type="text" name="phone" required class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-800 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Password</label>
                        <input type="password" name="password" required class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-800 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-[#1d4e89] to-[#3b82f6] px-4 py-3 font-semibold text-white shadow-[0_12px_20px_rgba(29,78,137,0.2)] transition hover:opacity-95">Initialize admin account</button>
                </form>
            </div>
        </div>
    </div>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>