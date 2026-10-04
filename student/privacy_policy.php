<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>C-Attend - Privacy Policy</title>
    <link rel="icon" href="../assets/logo-dashboard.png" type="image/png">
    <link rel="apple-touch-icon" href="../assets/logo-dashboard.png">
    <link rel="stylesheet" href="../assets/tailwind.css">
</head>
<body class="min-h-screen bg-[#f3f6fb] px-4 py-6 text-slate-800 antialiased sm:px-6">
    <main class="mx-auto max-w-3xl">
        <header class="mb-6 flex items-center gap-3 rounded-3xl bg-gradient-to-r from-[#071b2f] via-[#102b4d] to-[#1d4e89] px-5 py-5 text-white shadow-lg">
            <img src="../assets/logo-dashboard.png" alt="C-Attend logo" class="h-14 w-14 object-contain">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.28em] text-sky-100/80">C-Attend</p>
                <h1 class="text-2xl font-black">Privacy Policy</h1>
            </div>
        </header>

        <article class="space-y-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
            <div>
                <p class="text-sm text-slate-500">Effective date: 13 September 2026</p>
                <p class="mt-3 text-sm leading-6 text-slate-600">C-Attend is the institution's student attendance portal. This policy describes the information currently processed by the portal when students and administrators create accounts, manage attendance sessions, record attendance, reset passwords, and produce reports.</p>
            </div>

            <section>
                <h2 class="text-lg font-bold text-slate-900">1. Information processed by C-Attend</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">The portal processes the following information:</p>
                <ul class="mt-2 list-disc space-y-2 pl-5 text-sm leading-6 text-slate-600">
                    <li>Student registration details: full name, index number, phone number, Class A or Class B, password hash, and the date and time you accepted this policy.</li>
                    <li>Administrator details: name, email address, phone number, password hash, administrator role, and account creation information.</li>
                    <li>Attendance information: course, target class, session dates and times, attendance status, marking method, marking time, and recorded distance where GPS verification is used.</li>
                    <li>Attendance-session location settings created by administrators, including latitude, longitude, and the configured attendance radius.</li>
                    <li>Password-reset information, including a temporary six-digit reset code, expiry time, use status, and related index number.</li>
                    <li>Operational and security alerts generated when the portal records important failures or security-related events.</li>
                </ul>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900">2. How the information is used</h2>
                <ul class="mt-2 list-disc space-y-2 pl-5 text-sm leading-6 text-slate-600">
                    <li>Create accounts, authenticate users, support password resets, and protect sessions.</li>
                    <li>Show students only attendance sessions that apply to their class and allow administrators to target Class A, Class B, or both classes.</li>
                    <li>Check whether GPS attendance is within the session's configured radius and prevent attendance after a session expires.</li>
                    <li>Maintain Present, Pending, and Absent history, calculate attendance rates and risk indicators, and support attendance review.</li>
                    <li>Allow authorized administrators to create sessions, perform manual attendance overrides, manage students, and review shared attendance history.</li>
                    <li>Generate CSV and PDF reports for academic administration and investigate technical or security problems.</li>
                </ul>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900">3. GPS attendance</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">When you choose to mark attendance, your browser may provide your latitude, longitude, and reported accuracy. The portal validates coordinate ranges and requires reported accuracy to be a finite, nonnegative number, then calculates distance on the server using the Haversine formula. Reported accuracy contributes a bounded tolerance of 25–200 meters against the session radius.</p>
                <p class="mt-2 text-sm leading-6 text-slate-600">Browser-provided coordinates and accuracy can be altered and do not prove a device's physical location. The attendance record stores the calculated distance rather than the submitted student coordinates. GPS permission is required for self-service GPS attendance. An authorized administrator may record a manual attendance entry, which is identified separately from a student-marked entry.</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900">4. Roles and access</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">Students can access their own account and attendance history. Main administrators can manage courses, students, attendance sessions, reports, and sub-administrator accounts. Class sub-administrators are restricted to creating sessions and manually marking students for their assigned class. Attendance records are shared in the same system, so authorized main administrators can see attendance sessions and marks created by sub-administrators.</p>
                <p class="mt-2 text-sm leading-6 text-slate-600">C-Attend does not sell student information or use it for unrelated advertising. Information may be disclosed when required by law or when necessary to protect the portal, its users, or the institution.</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900">5. Storage and retention</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">Information is stored in the institution's C-Attend database. Student and administrator accounts, attendance history, reports, and audit-related records are retained for as long as the institution needs them for academic administration, support, security, dispute handling, or legal obligations. Temporary password-reset codes expire and are marked as used after successful reset or verification.</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900">6. Security</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">The portal uses password hashing, CSRF-protected forms, protected sessions, database access controls, role-based administrator permissions, input validation, and expiry checks. No online service is completely risk-free. Keep your password and reset code private and notify the designated administrator if you suspect unauthorized access.</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900">7. Your choices and responsibilities</h2>
                <ul class="mt-2 list-disc space-y-2 pl-5 text-sm leading-6 text-slate-600">
                    <li>Provide accurate registration information and keep your password and reset code confidential.</li>
                    <li>Use only your own account and do not falsify, duplicate, or interfere with attendance records.</li>
                    <li>Allow browser location access only when you want to use GPS attendance; refusing it means GPS attendance cannot be completed.</li>
                    <li>Ask the institution's C-Attend administrator to correct inaccurate account information or address a privacy concern.</li>
                    <li>Understand that accepting this policy is required to create a student account, and that attendance records may still be retained for institutional purposes.</li>
                </ul>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900">8. Institutional responsibility</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">The institution operating C-Attend determines the lawful basis for processing, attendance and academic retention periods, authorized personnel, and the appropriate contact for privacy requests. Where local law or institutional policy requires additional authorization for a student, the institution is responsible for obtaining it.</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900">9. Changes to this policy</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">This policy may be updated when the portal or its data practices change. The effective date identifies the current version. The registration consent records the version available when an account is created; important changes should be communicated through the institution's normal channels.</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900">10. Contact</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">For questions, corrections, account support, access requests, or privacy concerns, contact the institution's designated C-Attend administrator or data protection contact through the official campus support channel. C-Attend itself does not display a separate privacy-office email address, so use the institution's approved support contact.</p>
            </section>

            <a href="../index.php?action=signup" class="inline-flex rounded-xl bg-[#1d4e89] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#163e6d]">Back to registration</a>
        </article>
    </main>
<?php require __DIR__ . '/../includes/system_footer.php'; ?>
</body>
</html>
