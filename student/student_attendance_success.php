<?php
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
require_once __DIR__ . '/../includes/bootstrap.php';

ensure_maintenance_tables($pdo);
enforce_portal_maintenance($pdo, 'student portal');

if (!isset($_SESSION['student_id'])) {
    header('Location: ../index.php');
    exit;
}

$message = $_SESSION['student_flash_success'] ?? 'Attendance marked successfully.';
unset($_SESSION['student_flash_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="3;url=student_dashboard.php">
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <title>Attendance Successful - C-Attend</title>
    <link rel="stylesheet" href="../assets/tailwind.css">
</head>
<body class="min-h-screen bg-[#f3f6fb] text-slate-800">
    <main class="flex min-h-screen items-center justify-center px-4 py-8">
        <section class="w-full max-w-md rounded-3xl border border-emerald-200 bg-white p-8 text-center shadow-[0_20px_50px_rgba(16,185,129,0.12)]">
            <img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="mx-auto h-20 w-20 rounded-2xl bg-slate-50 p-2 object-contain">
            <div class="mx-auto mt-6 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-3xl text-emerald-700" aria-hidden="true">✓</div>
            <p class="mt-5 text-xs font-semibold uppercase tracking-[0.22em] text-emerald-700">Attendance successful</p>
            <h1 class="mt-2 text-2xl font-black text-slate-900">Your attendance was marked</h1>
            <p class="mt-3 text-sm leading-6 text-slate-600"><?= htmlspecialchars($message) ?></p>
            <p class="mt-6 text-xs text-slate-500">Returning to your dashboard in <span id="countdown">3</span> seconds...</p>
            <a href="student_dashboard.php" class="mt-5 inline-flex rounded-xl bg-[#1d4e89] px-4 py-3 text-sm font-semibold text-white">Go to dashboard now</a>
        </section>
    </main>
    <script>
        let seconds = 3;
        const countdown = document.getElementById('countdown');
        const timer = window.setInterval(function () {
            seconds -= 1;
            countdown.textContent = seconds;
            if (seconds <= 0) {
                window.clearInterval(timer);
                window.location.href = 'student_dashboard.php';
            }
        }, 1000);
    </script>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>
