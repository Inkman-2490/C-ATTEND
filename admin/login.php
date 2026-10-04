<?php
// admin/login.php
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
if ($stmt->fetchColumn() == 0) {
    header("Location: setup.php");
    exit;
}

if (isset($_SESSION['admin_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'admin_login')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ?");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_name'] = $admin['full_name'];
            $_SESSION['admin_role'] = $admin['role'] ?? 'admin';
            $_SESSION['admin_class'] = $admin['assigned_class'] ?? null;
            header("Location: dashboard.php");
            exit;
        } else {
            $error = "Invalid email or password credentials.";
            record_portal_alert($pdo, 'Failed admin login', 'Invalid admin login attempt for email ' . $email, 'high', 'admin', 'Authentication failure');
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
    <title>C-Attend - Admin Login</title>
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
    <style>
        @keyframes student-phone {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-5px) rotate(-1deg); }
        }

        @keyframes phone-glow {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-4px); }
        }

        .student-phone {
            animation: student-phone 4s ease-in-out infinite;
        }

        .phone-glow {
            animation: phone-glow 3s ease-in-out infinite;
        }

        @media (prefers-reduced-motion: reduce) {
            .student-phone,
            .phone-glow {
                animation: none;
            }
        }
    </style>
    </head>
    <body class="mobile-auth relative min-h-screen overflow-hidden bg-[#eef4ff] text-slate-800 antialiased">
    <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
        <div class="absolute -left-24 -top-24 h-72 w-72 rounded-full bg-sky-300/35 blur-3xl"></div>
        <div class="absolute -bottom-28 -right-20 h-96 w-96 rounded-full bg-indigo-300/30 blur-3xl"></div>
        <div class="absolute bottom-8 left-1/2 hidden -translate-x-1/2 text-[#1d4e89]/20 sm:block">
            <div class="student-phone relative h-40 w-56">
                <div class="absolute bottom-4 left-12 h-3 w-32 rounded-full bg-current"></div>
                <div class="absolute bottom-7 left-[4.5rem] h-16 w-20 rounded-t-[2rem] bg-current"></div>
                <div class="absolute bottom-24 left-[5.8rem] h-9 w-9 rounded-full bg-current"></div>
                <div class="absolute bottom-14 left-[4.2rem] h-3 w-16 rotate-[28deg] rounded-full bg-current"></div>
                <div class="absolute bottom-14 right-[4.2rem] h-3 w-16 rotate-[-28deg] rounded-full bg-current"></div>
                <div class="phone-glow absolute bottom-[4.7rem] left-[6.8rem] h-10 w-5 rotate-[-10deg] rounded-md border-2 border-[#d8a324] bg-[#102b4d] shadow-[0_0_14px_rgba(216,163,36,0.65)]"></div>
            </div>
        </div>
    </div>
    <div class="relative z-10 mx-auto flex min-h-screen w-full max-w-7xl items-center justify-center px-4 py-8 sm:px-6 lg:px-8">
        <div class="grid w-full max-w-6xl overflow-hidden rounded-[32px] border border-slate-200 bg-white shadow-[0_30px_80px_rgba(10,31,64,0.12)] lg:grid-cols-[1.1fr_0.9fr]">
            <div class="hidden bg-gradient-to-br from-[#071b2f] via-[#102b4d] to-[#1d4e89] p-8 text-white lg:flex lg:flex-col lg:justify-between">
                <div>
                    <div class="mb-8 flex items-center gap-4">
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white p-1.5 shadow-sm">
                            <img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="h-full w-full object-contain" />
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.32em] text-sky-100/80">Academic operations</p>
                            <h1 class="text-3xl font-black tracking-tight">C-Attend</h1>
                        </div>
                    </div>

                    <div class="max-w-md">
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-sky-100/80">Admin access</p>
                        <h2 class="mt-4 text-4xl font-black leading-tight">Campus control, attendance oversight, and class management.</h2>
                        <p class="mt-4 text-base text-sky-100/80">Manage sessions, track student attendance, and keep the academic environment running smoothly.</p>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm">
                        <p class="text-[10px] uppercase tracking-[0.2em] text-sky-100/80">Students</p>
                        <p class="mt-3 text-3xl font-black">Live</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm">
                        <p class="text-[10px] uppercase tracking-[0.2em] text-sky-100/80">Control</p>
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
                    <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-sky-700">Class rep portal</p>
                    <h1 class="mt-2 text-2xl font-black text-slate-900">Sign in to your dashboard</h1>
                </div>

                <?php if (isset($_SESSION['admin_success'])): ?>
                    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-700">
                        <?= htmlspecialchars($_SESSION['admin_success']); unset($_SESSION['admin_success']); ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2.5 text-sm text-red-700"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('admin_login') ?>">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Email address</label>
                        <input type="email" name="email" required class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-800 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Password</label>
                        <input type="password" name="password" required class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-800 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-[#1d4e89] to-[#3b82f6] px-4 py-3 font-semibold text-white shadow-[0_12px_20px_rgba(29,78,137,0.2)] transition hover:opacity-95">Sign in to dashboard</button>
                </form>
            </div>
        </div>
    </div>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>