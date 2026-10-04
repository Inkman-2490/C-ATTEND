<?php
// admin/admin_export_report.php
session_start();

// Use absolute directory pathing to prevent XAMPP include errors
require_once __DIR__ . '/../includes/bootstrap.php';

date_default_timezone_set('Africa/Accra');

// Authorization check for Admin / Lecturer
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

ensure_maintenance_tables($pdo);
ensure_attendance_approval_columns($pdo);
enforce_portal_maintenance($pdo, 'admin portal');

$type       = $_GET['type'] ?? 'weekly';      // 'daily', 'weekly', 'monthly', 'custom', or 'all'
$date_from  = trim($_GET['date_from'] ?? '');
$date_to    = trim($_GET['date_to'] ?? '');
$course_id  = (int)($_GET['course_id'] ?? 0);  // 0 = all courses
$session_id = (int)($_GET['session_id'] ?? 0); // 0 = all sessions
$class_name = strtoupper(trim($_GET['class_name'] ?? 'both'));
if (!in_array($class_name, ['A', 'B', 'BOTH'], true)) {
    $class_name = 'BOTH';
}
$class_label = $class_name === 'BOTH' ? 'Class A + B' : 'Class ' . $class_name;
$selected_course_label = 'All courses';
if ($course_id > 0) {
    $course_stmt = $pdo->prepare('SELECT course_code, course_title FROM courses WHERE id = ?');
    $course_stmt->execute([$course_id]);
    $selected_course = $course_stmt->fetch();
    if ($selected_course) {
        $selected_course_label = $selected_course['course_code'] . ' - ' . $selected_course['course_title'];
    } else {
        $course_id = 0;
    }
}

// 1. Build Date Filters
$date_condition = "";
$date_params = [];

if ($type === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to) && $date_from <= $date_to) {
    $date_condition = ">= ? AND s.start_time < ?";
    $date_params = [
        $date_from . ' 00:00:00',
        date('Y-m-d 00:00:00', strtotime($date_to . ' +1 day'))
    ];
    $file_period = "custom_{$date_from}_to_{$date_to}";
} elseif ($type === 'daily') {
    $date_condition = ">= ?";
    $date_params = [date('Y-m-d 00:00:00')];
    $file_period = "daily";
} elseif ($type === 'weekly') {
    $date_condition = ">= ?";
    $date_params = [date('Y-m-d 00:00:00', strtotime('-7 days'))];
    $file_period = "weekly";
} elseif ($type === 'monthly') {
    $date_condition = ">= ?";
    $date_params = [date('Y-m-d 00:00:00', strtotime('-30 days'))];
    $file_period = "monthly";
} else {
    $file_period = "all_time";
}

// 2. Query Total Attendance Sessions Held in the Selected Window
$sess_where = [];
$sess_params = [];

if ($date_condition !== '') {
    $sess_where[] = "s.start_time " . $date_condition;
    $sess_params = array_merge($sess_params, $date_params);
}
if ($course_id > 0) {
    $sess_where[] = "s.course_id = ?";
    $sess_params[] = $course_id;
}
if ($session_id > 0) {
    $sess_where[] = "s.id = ?";
    $sess_params[] = $session_id;
}
if ($class_name !== 'BOTH') {
    $sess_where[] = "s.target_class IN (?, 'both')";
    $sess_params[] = $class_name;
}

$sess_sql = "SELECT COUNT(DISTINCT s.id) AS total_sessions FROM attendance_sessions s";
if (!empty($sess_where)) {
    $sess_sql .= " WHERE " . implode(" AND ", $sess_where);
}

$stmt_total = $pdo->prepare($sess_sql);
$stmt_total->execute($sess_params);
$total_sessions_held = (int)$stmt_total->fetchColumn();


// 3. Fetch Per-Student Summary Statistics
$summary_where = [];
$summary_params = [];

if ($date_condition !== '') {
    $summary_where[] = "s.start_time " . $date_condition;
    $summary_params = array_merge($summary_params, $date_params);
}
if ($course_id > 0) {
    $summary_where[] = "s.course_id = ?";
    $summary_params[] = $course_id;
}
if ($session_id > 0) {
    $summary_where[] = "s.id = ?";
    $summary_params[] = $session_id;
}
if ($class_name !== 'BOTH') {
    $summary_where[] = "st.class_name = ?";
    $summary_params[] = $class_name;
    $summary_where[] = "s.target_class IN (?, 'both')";
    $summary_params[] = $class_name;
}

$summary_sql_where = !empty($summary_where) ? "WHERE " . implode(" AND ", $summary_where) : "";

$summary_query = "
    SELECT 
        GROUP_CONCAT(DISTINCT c.course_code ORDER BY c.course_code ASC SEPARATOR ', ') AS course_code,
        GROUP_CONCAT(DISTINCT c.course_title ORDER BY c.course_title ASC SEPARATOR ', ') AS course_name,
        st.index_number,
        st.full_name AS student_name,
        COUNT(DISTINCT r.session_id) AS total_attended
    FROM students st
    CROSS JOIN attendance_sessions s
    JOIN courses c ON c.id = s.course_id
    LEFT JOIN attendance_records r ON r.student_id = st.id AND r.session_id = s.id AND r.approval_status = 'approved'
    {$summary_sql_where}
    GROUP BY st.id, st.index_number, st.full_name, st.class_name
    ORDER BY st.index_number ASC
";

$stmt_summary = $pdo->prepare($summary_query);
$stmt_summary->execute($summary_params);
$student_summaries = $stmt_summary->fetchAll(PDO::FETCH_ASSOC);

usort($student_summaries, static function (array $left, array $right) use ($total_sessions_held): int {
    $left_attended = (int)$left['total_attended'];
    $right_attended = (int)$right['total_attended'];
    $left_missed = $left_attended < $total_sessions_held ? 1 : 0;
    $right_missed = $right_attended < $total_sessions_held ? 1 : 0;

    if ($left_missed !== $right_missed) {
        return $left_missed <=> $right_missed;
    }

    return strcasecmp((string)$left['index_number'], (string)$right['index_number']);
});


// 4. Generate and output the CSV summary.
$filename = "admin_attendance_report_{$file_period}_class_" . strtolower($class_name) . "_" . date('Y_m_d_H%i') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");

// SECTION 1: SUMMARY STATISTICS
fputcsv($output, ['=== STUDENT ATTENDANCE SUMMARY STATISTICS ===']);
fputcsv($output, ['Report Type:', ucfirst($type)]);
fputcsv($output, ['Course:', $selected_course_label]);
fputcsv($output, ['Student Class:', $class_label]);
fputcsv($output, ['Total Sessions Held:', $total_sessions_held]);
fputcsv($output, []); // Blank line separator

fputcsv($output, [
    'Course Code',
    'Course Name',
    'Student Index No',
    'Student Name',
    'Sessions Held',
    'Present',
    'Missed'
]);

$missed_section_written = false;
foreach ($student_summaries as $sum) {
    $attended = (int)$sum['total_attended'];
    $missed   = max(0, $total_sessions_held - $attended);

    if (!$missed_section_written && $total_sessions_held > 0 && $attended < $total_sessions_held) {
        fputcsv($output, []);
        fputcsv($output, ['----- MISSED STUDENTS BELOW THIS LINE -----']);
        $missed_section_written = true;
    }

    fputcsv($output, [
        $sum['course_code'] ?? 'N/A',
        $sum['course_name'] ?? 'N/A',
        $sum['index_number'] ?? 'N/A',
        $sum['student_name'] ?? 'N/A',
        $total_sessions_held,
        $attended,
        $missed
    ]);
}

fclose($output);
exit;