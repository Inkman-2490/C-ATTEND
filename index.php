<?php
// index.php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
require_once __DIR__ . '/includes/bootstrap.php';
ensure_student_auth_events_table($pdo);

ensure_portal_alert_table($pdo);
ensure_class_columns($pdo);
ensure_privacy_acceptance_column($pdo);
ensure_student_device_bindings_table($pdo);
ensure_maintenance_tables($pdo);
enforce_portal_maintenance($pdo, 'student portal');
$student_index_unique = $pdo->query("
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'students'
      AND COLUMN_NAME = 'index_number'
      AND NON_UNIQUE = 0
")->fetchColumn();
if (!$student_index_unique) {
    try {
        $pdo->exec("ALTER TABLE students ADD UNIQUE KEY unique_student_index_number (index_number)");
    } catch (PDOException $e) {
        error_log('C-Attend could not enforce unique student index numbers: ' . $e->getMessage());
    }
}

if (isset($_SESSION['student_id'])) {
    header("Location: student/student_dashboard.php");
    exit;
}

$_SESSION['csrf_tokens'] = $_SESSION['csrf_tokens'] ?? [];
$view = $_GET['action'] ?? 'login';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_signup'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'student_signup')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
        $view = 'signup';
    } else {
        $index_number = normalize_index_number(sanitize_input($_POST['index_number'] ?? ''));
        $full_name    = sanitize_input($_POST['full_name'] ?? '');
        $phone        = sanitize_input($_POST['phone'] ?? '');
        $class_name   = strtoupper(sanitize_input($_POST['class_name'] ?? ''));
        $password     = $_POST['password'] ?? '';
        $privacy_accepted = isset($_POST['privacy_policy']);

        if (empty($index_number) || empty($full_name) || empty($phone) || empty($class_name) || empty($password)) {
            $error = 'Please fill in all fields.';
            $view = 'signup';
        } elseif (!$privacy_accepted) {
            $error = 'You must accept the Privacy Policy before creating an account.';
            $view = 'signup';
        } elseif (!is_valid_index_number($index_number)) {
            $error = 'Index number format is invalid.';
            $view = 'signup';
        } elseif (!is_valid_phone_number($phone)) {
            $error = 'Phone number format is invalid.';
            $view = 'signup';
        } elseif (!is_valid_password($password)) {
            $error = 'Password must be at least 8 characters long.';
            $view = 'signup';
        } elseif (!in_array($class_name, ['A', 'B'], true)) {
            $error = 'Please select Class A or Class B.';
            $view = 'signup';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM students WHERE index_number = ?");
            $stmt->execute([$index_number]);
            if ($stmt->fetch()) {
                $error = 'Index Number already registered.';
                $view = 'signup';
            } else {
                $password_hash = password_hash($password, PASSWORD_ARGON2ID);
                try {
                    $stmt = $pdo->prepare("INSERT INTO students (index_number, full_name, phone, class_name, password_hash, privacy_policy_accepted_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$index_number, $full_name, $phone, $class_name, $password_hash]);
                    $success = 'Registration successful! You can now log in.';
                    $view = 'login';
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        $error = 'Index Number already registered.';
                    } else {
                        $error = 'Registration failed. Please try again.';
                        record_portal_alert($pdo, 'Student registration failed', 'A student registration attempt failed for index number ' . $index_number, 'high', 'student', $e->getMessage());
                    }
                    $view = 'signup';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_login'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'student_login')) {
        $_SESSION['csrf_tokens']['student_login'] = bin2hex(random_bytes(32));
        $error = 'Security validation failed. Please refresh the page and try again.';
        $view = 'login';
    } else {
        $index_number = normalize_index_number(sanitize_input($_POST['index_number'] ?? ''));
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM students WHERE index_number = ?");
        $stmt->execute([$index_number]);
        $student = $stmt->fetch();

        if ($student && password_verify($password, $student['password_hash'])) {
            try {
                if (!bind_student_login_to_device($pdo, (int)$student['id'])) {
                    $error = 'This phone is already linked to another student account. You cannot use a different index number on this device.';
                    record_portal_alert($pdo, 'Student device account switch blocked', 'A login using index number ' . $index_number . ' was blocked because this device is linked to another student account.', 'high', 'student', 'Persistent student device binding mismatch');
                    $view = 'login';
                } else {
                    session_regenerate_id(true);
                    record_student_auth_event($pdo, (int)$student['id'], 'login');
                    $_SESSION['student_id'] = $student['id'];
                    $_SESSION['student_name'] = $student['full_name'];
                    $_SESSION['index_number'] = $student['index_number'];
                    $_SESSION['student_index'] = $student['index_number'];
                    $_SESSION['student_class'] = $student['class_name'];
                    header("Location: student/student_dashboard.php");
                    exit;
                }
            } catch (Throwable $e) {
                $error = 'We could not verify this device. Please try again or contact an administrator.';
                record_portal_alert($pdo, 'Student device binding failed', 'Device binding failed during login for index number ' . $index_number, 'high', 'student', $e->getMessage());
                $view = 'login';
            }
        } else {
            $error = 'Invalid Index Number or Password.';
            record_portal_alert($pdo, 'Failed student login', 'Invalid login attempt for index number ' . $index_number, 'medium', 'student', 'Authentication failure');
            $view = 'login';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_request_reset'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'student_forgot')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
        $view = 'forgot';
    } else {
        $index_number = normalize_index_number(sanitize_input($_POST['index_number'] ?? ''));

        $stmt = $pdo->prepare("SELECT * FROM students WHERE index_number = ?");
        $stmt->execute([$index_number]);
        $student = $stmt->fetch();

        if ($student) {
            $token = generate_reset_token();
            $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE password_resets SET is_used = 1 WHERE index_number = ? AND is_used = 0")->execute([$index_number]);
                $stmt = $pdo->prepare("INSERT INTO password_resets (index_number, token, expires_at) VALUES (?, ?, ?)");
                $stmt->execute([$index_number, $token, $expires]);
                $pdo->commit();

                $_SESSION['temp_reset_index'] = $index_number;
                $_SESSION['reset_notice'] = 'Your password reset code is ' . $token . '. It expires in 15 minutes.';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'We could not start the password reset. Please try again.';
                record_portal_alert($pdo, 'Password reset creation failed', 'Could not create a reset request for index number ' . $index_number, 'high', 'portal', $e->getMessage());
                $view = 'forgot';
            }

            if (!$error) {
                header('Location: index.php?action=verify_reset');
                exit;
            }
        } else {
            $error = 'Index Number not found in system.';
            $view = 'forgot';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_confirm_reset'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null, 'student_reset')) {
        $error = 'Security validation failed. Please refresh the page and try again.';
        $view = 'verify_reset';
    } else {
        $index_number = $_SESSION['temp_reset_index'] ?? normalize_index_number(sanitize_input($_POST['index_number'] ?? ''));
        $token = sanitize_input($_POST['token'] ?? '');
        $new_password = $_POST['new_password'] ?? '';

        if (!preg_match('/^\d{6}$/', $token)) {
            $error = 'Enter the 6-digit reset code displayed on this page.';
            $view = 'verify_reset';
        } elseif (!is_valid_password($new_password)) {
            $error = 'New password must be at least 8 characters long.';
            $view = 'verify_reset';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE index_number = ? AND token = ? AND is_used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
            $stmt->execute([$index_number, $token]);
            $reset = $stmt->fetch();

            if ($reset) {
                $new_hash = password_hash($new_password, PASSWORD_ARGON2ID);
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("UPDATE students SET password_hash = ? WHERE index_number = ?");
                    $stmt->execute([$new_hash, $index_number]);
                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException('Student password update affected an unexpected number of rows.');
                    }

                    $stmt = $pdo->prepare("UPDATE password_resets SET is_used = 1 WHERE id = ?");
                    $stmt->execute([$reset['id']]);
                    $pdo->commit();

                    unset($_SESSION['temp_reset_index']);
                    $success = 'Password successfully reset! You can now log in.';
                    $view = 'login';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Your password could not be updated. Please request a new code and try again.';
                    record_portal_alert($pdo, 'Password update failed', 'Could not update the password for index number ' . $index_number, 'critical', 'student', $e->getMessage());
                    $view = 'verify_reset';
                }
            } else {
                $error = 'Invalid or expired reset token.';
                record_portal_alert($pdo, 'Reset token rejected', 'An invalid or expired password reset token was submitted for index number ' . $index_number, 'medium', 'student', 'Password reset verification failed');
                $view = 'verify_reset';
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
    <link rel="icon" href="assets/logo-dashboard.png" type="image/png">
    <link rel="apple-touch-icon" href="assets/logo-dashboard.png">
    <title>C-Attend - Student Portal</title>
    <link rel="stylesheet" href="assets/tailwind.css">
    <link rel="stylesheet" href="includes/mobile.css">
    <style>
        @media (max-width: 640px) {
            .mobile-auth,
            .mobile-auth * { box-sizing: border-box; }
            .mobile-auth { overflow-x: hidden; background: #f5f5f5; }
            .mobile-auth > .relative {
                width: 100%;
                max-width: 100%;
                padding: max(0.5rem, env(safe-area-inset-top)) max(0.5rem, env(safe-area-inset-right)) max(0.5rem, env(safe-area-inset-bottom)) max(0.5rem, env(safe-area-inset-left));
            }
            .mobile-auth > .relative > div { min-width: 0; max-width: 100%; }
            .mobile-auth .bg-slate-50\/80 { padding: 1rem; }
            .mobile-auth h1,
            .mobile-auth h2,
            .mobile-auth p { overflow-wrap: anywhere; }
            .mobile-auth h1 { font-size: 1.25rem; }
            .mobile-auth form,
            .mobile-auth form > div { min-width: 0; }
            .mobile-auth form input,
            .mobile-auth form select,
            .mobile-auth form button {
                width: 100%;
                max-width: 100%;
                min-width: 0;
                min-height: 2.75rem;
            }
            .mobile-auth input,
            .mobile-auth select { font-size: 16px; }
            .mobile-auth .privacy-consent {
                width: 100%;
                min-height: 2.75rem;
                align-items: flex-start;
                gap: 0.65rem;
                padding: 0.35rem 0;
            }
            .mobile-auth .privacy-consent input[type="checkbox"] {
                width: 1.25rem;
                min-width: 1.25rem;
                height: 1.25rem;
                min-height: 1.25rem;
                margin-top: 0.1rem;
                flex: 0 0 auto;
            }
            .mobile-auth .privacy-consent span {
                min-width: 0;
                flex: 1 1 auto;
                overflow-wrap: anywhere;
            }
        }
        @media (max-width: 1023px) {
            .mobile-login-panel {
                position: relative;
                overflow: hidden;
                min-height: 100vh;
                width: 100%;
                max-width: 100%;
                padding: clamp(1rem, 4vw, 1.75rem) !important;
                background: #ffffff;
            }
            .mobile-login-panel::before {
                content: "";
                position: absolute;
                inset: 0 0 auto;
                height: clamp(190px, 34vh, 245px);
                background: linear-gradient(145deg, #071b2f 0%, #102b4d 55%, #1d4e89 100%);
                border-radius: 0 0 42% 42% / 0 0 16% 16%;
            }
            .mobile-login-panel > * {
                position: relative;
                z-index: 1;
            }
            .mobile-login-panel > .mb-5 {
                padding-top: clamp(0.25rem, 2vh, 1.25rem);
            }
            .mobile-login-panel > .mb-5 > div {
                background: rgba(255,255,255,0.96);
                border-radius: 1.25rem;
            }
            .mobile-login-panel > .mb-6 {
                color: #ffffff;
            }
            .mobile-login-panel > .mb-6 p {
                color: rgba(255,255,255,0.8);
            }
            .mobile-login-panel > .mb-6 h1 {
                color: #ffffff;
                font-size: clamp(1.2rem, 5vw, 1.6rem);
            }
            .mobile-login-panel form {
                width: 100%;
                max-width: 32rem;
                margin: clamp(1rem, 5vh, 2rem) auto 0;
                padding: clamp(0.9rem, 4vw, 1.25rem);
                border-radius: 1.25rem;
                background: #ffffff;
                box-shadow: 0 16px 35px rgba(10, 31, 64, 0.12);
            }
            .mobile-login-panel form button[type="submit"] {
                background: linear-gradient(to right, #1d4e89, #3b82f6) !important;
                box-shadow: 0 10px 18px rgba(29, 78, 137, 0.18);
            }
            .mobile-login-panel form a {
                color: #1d4e89;
            }
            .mobile-login-panel form input {
                border-color: #e5e7eb;
                background: #f7f7f7;
            }
        }
        @media (max-width: 380px) {
            .mobile-login-panel > .mb-6 h1 { font-size: 1.15rem; }
            .mobile-login-panel form { padding: 0.8rem; }
            .mobile-login-panel form .space-y-4 > :not([hidden]) ~ :not([hidden]) { margin-top: 0.7rem; }
        }
        @media (max-height: 650px) and (max-width: 1023px) {
            .mobile-auth {
                overflow-y: auto;
            }
            .mobile-auth > .relative {
                align-items: flex-start;
                min-height: 100svh;
                padding-top: 0.5rem;
                padding-bottom: 0.75rem;
            }
            .mobile-auth > .relative > div {
                align-self: flex-start;
            }
            .mobile-login-panel {
                min-height: auto;
                padding: 0.75rem !important;
            }
            .mobile-login-panel::before { height: 155px; }
            .mobile-login-panel > .mb-5 {
                margin-bottom: 0.35rem;
                padding-top: 0;
            }
            .mobile-login-panel > .mb-5 > div {
                height: 2.75rem;
                width: 2.75rem;
                border-radius: 0.8rem;
            }
            .mobile-login-panel > .mb-6 {
                margin-bottom: 0.5rem;
            }
            .mobile-login-panel > .mb-6 h1 {
                margin-top: 0.25rem;
                font-size: 1.15rem;
            }
            .mobile-login-panel > .mb-6 p {
                font-size: 0.65rem;
            }
            .mobile-login-panel form {
                margin-top: 0.65rem;
                padding: 0.75rem;
            }
            .mobile-login-panel form .space-y-4 > :not([hidden]) ~ :not([hidden]) {
                margin-top: 0.65rem;
            }
            .mobile-login-panel form input,
            .mobile-login-panel form button {
                min-height: 2.5rem;
                padding-top: 0.6rem;
                padding-bottom: 0.6rem;
            }
        }
    </style>
    </head>
    <body class="mobile-auth relative min-h-screen overflow-hidden bg-[#eef4ff] text-slate-800 antialiased">
    <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
        <div class="absolute -left-24 -top-24 h-72 w-72 rounded-full bg-sky-300/35 blur-3xl"></div>
        <div class="absolute -bottom-28 -right-20 h-96 w-96 rounded-full bg-indigo-300/30 blur-3xl"></div>
    </div>
    <div class="relative z-10 mx-auto flex min-h-screen w-full max-w-7xl items-center justify-center px-4 py-8 sm:px-6 lg:px-8">
        <div class="grid w-full max-w-6xl overflow-hidden rounded-[32px] border border-slate-200 bg-white shadow-[0_30px_80px_rgba(10,31,64,0.12)] lg:grid-cols-[1.1fr_0.9fr]">
            <div class="relative hidden overflow-hidden bg-gradient-to-br from-[#071b2f] via-[#102b4d] to-[#1d4e89] p-8 text-white lg:flex lg:flex-col lg:justify-between">
                <img src="https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=1200&q=80" alt="" aria-hidden="true" class="absolute inset-0 h-full w-full object-cover opacity-20 mix-blend-screen">
                <div class="absolute inset-0 bg-gradient-to-br from-[#071b2f]/85 via-[#102b4d]/75 to-[#1d4e89]/60"></div>
                <div class="relative z-10">
                    <div class="mb-8 flex items-center gap-4">
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white p-1.5 shadow-sm">
                            <img src="assets/logo-dashboard.png" alt="C-Attend logo" class="h-full w-full object-contain" />
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.32em] text-sky-100/80">University platform</p>
                            <h1 class="text-3xl font-black tracking-tight">C-Attend</h1>
                        </div>
                    </div>

                    <div class="max-w-md">
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-sky-100/80">Student attendance</p>
                        <h2 class="mt-4 text-4xl font-black leading-tight">Modern campus attendance, built for everyday learning.</h2>
                        <p class="mt-4 text-base text-sky-100/80">Track classes, verify presence securely, and keep academic records accurate across the entire campus experience.</p>
                    </div>
                </div>

                <div class="relative z-10 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm">
                        <p class="text-[10px] uppercase tracking-[0.2em] text-sky-100/80">Live sessions</p>
                        <p class="mt-3 text-3xl font-black">24/7</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm">
                        <p class="text-[10px] uppercase tracking-[0.2em] text-sky-100/80">Campus ready</p>
                        <p class="mt-3 text-3xl font-black">Secure</p>
                    </div>
                    <div class="sm:col-span-2 flex items-center gap-3 rounded-2xl border border-[#f8d67a]/30 bg-[#f8d67a]/10 p-4 backdrop-blur-sm">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#f8d67a] text-lg text-[#102b4d]">⌖</span>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[#f8d67a]">Mobile check-in</p>
                            <p class="mt-1 text-sm font-medium text-white">GPS and location verification for trusted attendance.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mobile-login-panel bg-slate-50/80 p-6 sm:p-8">
                <div class="mb-5 flex items-center justify-center gap-3 lg:hidden">
                    <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white p-1.5 shadow-sm">
                        <img src="assets/logo-dashboard.png" alt="C-Attend logo" class="h-full w-full object-contain" />
                    </div>
                </div>
                <div class="mb-6 text-center lg:text-left">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-sky-700">Student portal</p>
                    <h1 class="mt-2 text-2xl font-black text-slate-900">Access your campus account</h1>
                </div>

                <?php if ($error): ?>
                    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2.5 text-sm text-red-700"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-700"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <?php if ($view === 'verify_reset' && isset($_SESSION['reset_notice'])): ?>
                    <div class="mb-4 rounded-xl border border-sky-200 bg-sky-50 px-3 py-2.5 text-sm font-medium text-sky-800">
                        <?= htmlspecialchars($_SESSION['reset_notice']); unset($_SESSION['reset_notice']); ?>
                    </div>
                <?php endif; ?>

                <?php if ($view === 'login'): ?>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('student_login') ?>">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Index number</label>
                            <input type="text" name="index_number" required placeholder="e.g. 04/2023/102" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-800 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Password</label>
                            <input type="password" name="password" required class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-800 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                        </div>
                        <div class="flex justify-end">
                            <a href="index.php?action=forgot" class="text-xs text-[#1d4e89] hover:underline">Forgot password?</a>
                        </div>
                        <button type="submit" name="btn_login" class="w-full rounded-xl bg-gradient-to-r from-[#1d4e89] to-[#3b82f6] px-4 py-3 font-semibold text-white shadow-[0_12px_20px_rgba(29,78,137,0.2)] transition hover:opacity-95">Sign in</button>
                    </form>
                    <div class="mt-6 text-center text-sm text-slate-600">
                        Don't have an account? <a href="index.php?action=signup" class="font-medium text-[#1d4e89] hover:underline">Register here</a>
                    </div>

                <?php elseif ($view === 'signup'): ?>
                    <form method="POST" class="grid gap-3 sm:grid-cols-2">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('student_signup') ?>">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Full name</label>
                            <input type="text" name="full_name" required placeholder="John Doe" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-800 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Index number</label>
                            <input type="text" name="index_number" required placeholder="e.g. 04/2023/102" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-800 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Phone number</label>
                            <input type="text" name="phone" required placeholder="0500000000" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-800 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Class</label>
                            <select name="class_name" required class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-800 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                                <option value="">Select your class</option>
                                <option value="A">Class A</option>
                                <option value="B">Class B</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Password</label>
                            <input type="password" name="password" required class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-800 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                        </div>
                        <label class="privacy-consent flex w-full items-start gap-2 text-xs leading-5 text-slate-600 sm:col-span-2">
                            <input type="checkbox" name="privacy_policy" value="1" required class="mt-1 h-4 w-4 rounded border-slate-300 text-[#1d4e89] focus:ring-sky-200">
                            <span>I have read and accept the <a href="student/privacy_policy.php" target="_blank" rel="noopener" class="font-semibold text-[#1d4e89] hover:underline">Privacy Policy</a>.</span>
                        </label>
                        <button type="submit" name="btn_signup" class="w-full rounded-xl bg-gradient-to-r from-[#1d4e89] to-[#3b82f6] px-4 py-2.5 text-sm font-semibold text-white shadow-[0_12px_20px_rgba(29,78,137,0.2)] transition hover:opacity-95 sm:col-span-2">Create student account</button>
                    </form>
                    <div class="mt-6 text-center text-sm text-slate-600">
                        Already registered? <a href="index.php?action=login" class="font-medium text-[#1d4e89] hover:underline">Log in</a>
                    </div>

                <?php elseif ($view === 'forgot'): ?>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('student_forgot') ?>">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Enter your index number</label>
                            <input type="text" name="index_number" required placeholder="e.g. 04/2023/102" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-800 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                        </div>
                        <button type="submit" name="btn_request_reset" class="w-full rounded-xl bg-gradient-to-r from-[#1d4e89] to-[#3b82f6] px-4 py-3 font-semibold text-white shadow-[0_12px_20px_rgba(29,78,137,0.2)] transition hover:opacity-95">Generate reset code</button>
                    </form>
                    <div class="mt-6 text-center text-sm text-slate-600">
                        <a href="index.php?action=login" class="text-[#1d4e89] hover:underline">← Back to login</a>
                    </div>

                <?php elseif ($view === 'verify_reset'): ?>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token('student_reset') ?>">
                        <?php if (!isset($_SESSION['temp_reset_index'])): ?>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">Index number</label>
                            <input type="text" name="index_number" required class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-800 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                        </div>
                        <?php endif; ?>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">6-digit reset code</label>
                            <input type="text" name="token" maxlength="6" required placeholder="123456" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-center text-lg font-mono tracking-[0.35em] text-slate-800 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">New password</label>
                            <input type="password" name="new_password" required class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-800 placeholder:text-slate-400 focus:border-[#1d4e89] focus:outline-none focus:ring-2 focus:ring-sky-200">
                        </div>
                        <button type="submit" name="btn_confirm_reset" class="w-full rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 px-4 py-3 font-semibold text-white shadow-[0_12px_20px_rgba(16,185,129,0.2)] transition hover:opacity-95">Update password</button>
                    </form>
                    <div class="mt-6 text-center text-sm text-slate-600">
                        <a href="index.php?action=login" class="text-[#1d4e89] hover:underline">← Cancel & return to login</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php require __DIR__ . '/includes/system_footer.php'; ?>
</body>
</html>