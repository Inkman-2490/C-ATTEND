<?php
// admin/admin_export_pdf.php
session_start([
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);
require_once __DIR__ . '/../includes/bootstrap.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

ensure_maintenance_tables($pdo);
ensure_attendance_approval_columns($pdo);
enforce_portal_maintenance($pdo, 'admin portal');

date_default_timezone_set('Africa/Accra');
$type = $_GET['type'] ?? 'weekly';
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$course_id = (int)($_GET['course_id'] ?? 0);
$class_name = strtoupper(trim($_GET['class_name'] ?? 'both'));
if (!in_array($class_name, ['A', 'B', 'BOTH'], true)) {
    $class_name = 'BOTH';
}
$class_label = $class_name === 'BOTH' ? 'Class A + B' : 'Class ' . $class_name;
$selected_course_label = 'All courses';
if ($course_id > 0) {
    $course_stmt = $pdo->prepare('SELECT course_title FROM courses WHERE id = ?');
    $course_stmt->execute([$course_id]);
    $selected_course = $course_stmt->fetch();
    if ($selected_course) {
        $selected_course_label = $selected_course['course_title'];
    } else {
        $course_id = 0;
    }
}

if ($type === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to) && $date_from <= $date_to) {
    $date_param = [
        $date_from . ' 00:00:00',
        date('Y-m-d 00:00:00', strtotime($date_to . ' +1 day'))
    ];
    $date_condition = '>= ? AND s.start_time < ?';
    $period_label = date('d M Y', strtotime($date_from)) . ' - ' . date('d M Y', strtotime($date_to));
    $file_period = "custom_{$date_from}_to_{$date_to}";
} elseif ($type === 'daily') {
    $date_param = date('Y-m-d 00:00:00');
    $date_condition = '>= ?';
    $period_label = 'Today';
    $file_period = 'daily';
} elseif ($type === 'monthly') {
    $date_param = date('Y-m-d 00:00:00', strtotime('-30 days'));
    $date_condition = '>= ?';
    $period_label = 'Past 30 Days';
    $file_period = 'monthly';
} elseif ($type === 'all') {
    $date_param = null;
    $period_label = 'All Time';
    $file_period = 'all_time';
} else {
    $date_param = date('Y-m-d 00:00:00', strtotime('-7 days'));
    $date_condition = '>= ?';
    $period_label = 'Past 7 Days';
    $file_period = 'weekly';
}

$where = [];
$params = [];
if ($date_param !== null) {
    $where[] = 's.start_time ' . $date_condition;
    if (is_array($date_param)) {
        $params = array_merge($params, $date_param);
    } else {
        $params[] = $date_param;
    }
}
if ($course_id > 0) {
    $where[] = 's.course_id = ?';
    $params[] = $course_id;
}
if ($class_name !== 'BOTH') {
    $where[] = 'st.class_name = ?';
    $params[] = $class_name;
    $where[] = "s.target_class IN (?, 'both')";
    $params[] = $class_name;
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT
        c.course_code,
        c.course_title,
        st.index_number,
        st.full_name,
        COUNT(r.id) AS sessions_attended,
        COUNT(DISTINCT s.id) AS sessions_held
    FROM attendance_sessions s
    JOIN courses c ON c.id = s.course_id
    CROSS JOIN students st
    LEFT JOIN attendance_records r
        ON r.approval_status = 'approved'
       AND r.session_id = s.id
       AND r.student_id = st.id
    $where_sql
    GROUP BY c.id, st.id
    ORDER BY c.course_code ASC, st.index_number ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

usort($rows, static function (array $left, array $right): int {
    $left_missed = (int)$left['sessions_attended'] < (int)$left['sessions_held'] ? 1 : 0;
    $right_missed = (int)$right['sessions_attended'] < (int)$right['sessions_held'] ? 1 : 0;

    if ($left_missed !== $right_missed) {
        return $left_missed <=> $right_missed;
    }

    $course_compare = strcasecmp((string)$left['course_code'], (string)$right['course_code']);
    if ($course_compare !== 0) {
        return $course_compare;
    }

    return strcasecmp((string)$left['index_number'], (string)$right['index_number']);
});

$total_rows = count($rows);
$course_count = count(array_unique(array_column($rows, 'course_code')));
$total_held = 0;
$total_attended = 0;
foreach ($rows as $row) {
    $total_held += (int)$row['sessions_held'];
    $total_attended += (int)$row['sessions_attended'];
}
$full_attendance_rows = 0;
foreach ($rows as $row) {
    if ((int)$row['sessions_attended'] >= (int)$row['sessions_held']) {
        $full_attendance_rows++;
    }
}

function pdf_escape(string $value): string {
    $value = function_exists('iconv')
        ? (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: '')
        : $value;
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function pdf_text(float $x, float $y, string $text, float $size = 9, string $color = '0.12 0.18 0.25'): string {
    return sprintf(
        "BT\n/F1 %.1f Tf\n%s rg\n1 0 0 1 %.1f %.1f Tm\n(%s) Tj\nET\n",
        $size,
        $color,
        $x,
        $y,
        pdf_escape($text)
    );
}

function pdf_bold_text(float $x, float $y, string $text, float $size = 9, string $color = '0.12 0.18 0.25'): string {
    return sprintf(
        "BT\n/F2 %.1f Tf\n%s rg\n1 0 0 1 %.1f %.1f Tm\n(%s) Tj\nET\n",
        $size,
        $color,
        $x,
        $y,
        pdf_escape($text)
    );
}

function pdf_rect(float $x, float $y, float $width, float $height, string $color, bool $fill = true): string {
    return sprintf(
        "%.2f %.2f %.2f %.2f re\n%s\n",
        $x,
        $y,
        $width,
        $height,
        $fill ? $color . " rg\nf" : $color . " RG\nS"
    );
}

function pdf_rounded_rect(float $x, float $y, float $width, float $height, float $radius, string $color): string {
    $control = $radius * 0.5522848;
    return sprintf(
        "%.2f %.2f m\n" .
        "%.2f %.2f l\n" .
        "%.2f %.2f %.2f %.2f %.2f %.2f c\n" .
        "%.2f %.2f l\n" .
        "%.2f %.2f %.2f %.2f %.2f %.2f c\n" .
        "%.2f %.2f l\n" .
        "%.2f %.2f %.2f %.2f %.2f %.2f c\n" .
        "%.2f %.2f l\n" .
        "%.2f %.2f %.2f %.2f %.2f %.2f c\n" .
        "%s rg\nf\n",
        $x + $radius, $y,
        $x + $width - $radius, $y,
        $x + $width - $radius + $control, $y,
        $x + $width, $y + $radius - $control,
        $x + $width, $y + $radius,
        $x + $width, $y + $height - $radius,
        $x + $width, $y + $height - $radius + $control,
        $x + $width - $radius + $control, $y + $height,
        $x + $width - $radius, $y + $height,
        $x + $radius, $y + $height,
        $x + $radius - $control, $y + $height,
        $x, $y + $height - $radius + $control,
        $x, $y + $height - $radius,
        $x, $y + $radius,
        $x, $y + $radius - $control,
        $x + $radius - $control, $y,
        $x + $radius, $y,
        $color
    );
}

function pdf_line(float $x1, float $y1, float $x2, float $y2, string $color = '0.86 0.89 0.93'): string {
    return sprintf("%.2f %.2f m %.2f %.2f l %s RG\nS\n", $x1, $y1, $x2, $y2, $color);
}

function pdf_bold_line(float $x1, float $y1, float $x2, float $y2, string $color = '0.86 0.89 0.93'): string {
    return sprintf("2 w\n%.2f %.2f m %.2f %.2f l %s RG\nS\n", $x1, $y1, $x2, $y2, $color);
}

function pdf_png_image(string $path): array {
    $data = file_get_contents($path);
    if ($data === false || substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        throw new RuntimeException('The report logo could not be read.');
    }

    $offset = 8;
    $width = 0;
    $height = 0;
    $idat = '';
    while ($offset < strlen($data)) {
        $length = unpack('N', substr($data, $offset, 4))[1];
        $type = substr($data, $offset + 4, 4);
        $chunk = substr($data, $offset + 8, $length);
        $offset += 12 + $length;
        if ($type === 'IHDR') {
            $header = unpack('Nwidth/Nheight/Cbit_depth/Ccolor_type', $chunk);
            $width = $header['width'];
            $height = $header['height'];
            if ($header['bit_depth'] !== 8 || $header['color_type'] !== 6) {
                throw new RuntimeException('The report logo must be an 8-bit RGBA PNG.');
            }
        } elseif ($type === 'IDAT') {
            $idat .= $chunk;
        } elseif ($type === 'IEND') {
            break;
        }
    }

    $decoded = gzuncompress($idat);
    if ($decoded === false || $width <= 0 || $height <= 0) {
        throw new RuntimeException('The report logo data is invalid.');
    }

    $stride = $width * 4;
    $previous = array_fill(0, $stride, 0);
    $rgb = '';
    $alpha = '';
    $position = 0;
    for ($row = 0; $row < $height; $row++) {
        $filter = ord($decoded[$position++]);
        $current = array_values(unpack('C*', substr($decoded, $position, $stride)));
        $position += $stride;
        for ($index = 0; $index < $stride; $index++) {
            $left = $index >= 4 ? $current[$index - 4] : 0;
            $up = $previous[$index];
            $up_left = $index >= 4 ? $previous[$index - 4] : 0;
            if ($filter === 1) {
                $current[$index] = ($current[$index] + $left) & 255;
            } elseif ($filter === 2) {
                $current[$index] = ($current[$index] + $up) & 255;
            } elseif ($filter === 3) {
                $current[$index] = ($current[$index] + intdiv($left + $up, 2)) & 255;
            } elseif ($filter === 4) {
                $estimate = $left + $up - $up_left;
                $distance_left = abs($estimate - $left);
                $distance_up = abs($estimate - $up);
                $distance_up_left = abs($estimate - $up_left);
                $predictor = $distance_left <= $distance_up && $distance_left <= $distance_up_left
                    ? $left
                    : ($distance_up <= $distance_up_left ? $up : $up_left);
                $current[$index] = ($current[$index] + $predictor) & 255;
            } elseif ($filter !== 0) {
                throw new RuntimeException('The report logo uses an unsupported PNG filter.');
            }
        }
        $rgb .= "\x00";
        $alpha .= "\x00";
        for ($pixel = 0; $pixel < $width; $pixel++) {
            $base = $pixel * 4;
            $rgb .= chr($current[$base]) . chr($current[$base + 1]) . chr($current[$base + 2]);
            $alpha .= chr($current[$base + 3]);
        }
        $previous = $current;
    }

    return [
        'width' => $width,
        'height' => $height,
        'rgb' => gzcompress($rgb),
        'alpha' => gzcompress($alpha)
    ];
}

function pdf_page(array $page_rows, string $period_label, string $class_label, string $course_label, int $page_number, int $page_total, int $total_rows, int $course_count, int $full_attendance_rows): string {
    $content = '';
    $content .= pdf_rect(0, 0, 842, 595, '0.97 0.98 1.00');
    $content .= pdf_rect(0, 515, 842, 80, '0.04 0.11 0.19');
    $content .= pdf_rounded_rect(42, 524, 46, 46, 9, '1 1 1');
    $content .= "q\n46 0 0 46 42 524 cm\n/Im1 Do\nQ\n";
    $content .= pdf_text(90, 552, 'C-Attend', 20, '1 1 1');
    $content .= pdf_text(90, 534, 'Campus attendance management report', 9, '0.72 0.84 0.95');
    $content .= pdf_text(650, 552, strtoupper($period_label), 9, '0.72 0.84 0.95');
    $content .= pdf_text(650, 534, $class_label . ' | ' . date('d M Y, H:i'), 8, '0.72 0.84 0.95');
    $card_x = [42, 196, 350, 504, 658];
    foreach ($card_x as $x) {
        $content .= pdf_rect($x, 452, 142, 43, '1 1 1');
    }
    $content .= pdf_text(56, 477, 'COURSE', 7, '0.38 0.45 0.54');
    $content .= pdf_bold_text(56, 460, substr($course_label, 0, 20), 10, '0.04 0.30 0.55');
    $content .= pdf_text(210, 477, 'STUDENT ROWS', 7, '0.38 0.45 0.54');
    $content .= pdf_text(210, 460, (string)$total_rows, 16, '0.04 0.30 0.55');
    $content .= pdf_text(364, 477, 'COURSES', 7, '0.38 0.45 0.54');
    $content .= pdf_text(364, 460, (string)$course_count, 16, '0.04 0.30 0.55');
    $content .= pdf_text(518, 477, 'FULL ATTENDANCE', 7, '0.38 0.45 0.54');
    $content .= pdf_text(518, 460, (string)$full_attendance_rows, 16, '0.04 0.30 0.55');
    $content .= pdf_text(672, 477, 'REPORT PAGE', 7, '0.38 0.45 0.54');
    $content .= pdf_text(672, 460, $page_number . ' of ' . $page_total, 16, '0.04 0.30 0.55');

    $table_x = 42;
    $table_top = 426;
    $row_height = 22;
    $columns = [
        ['label' => 'COURSE CODE', 'x' => 56, 'width' => 85],
        ['label' => 'INDEX NUMBER', 'x' => 141, 'width' => 150],
        ['label' => 'STUDENT NAME', 'x' => 291, 'width' => 315],
        ['label' => 'HELD', 'x' => 666, 'width' => 46],
        ['label' => 'PRESENT', 'x' => 712, 'width' => 46],
        ['label' => 'MISSED', 'x' => 758, 'width' => 46]
    ];
    $content .= pdf_rect($table_x, $table_top - $row_height, 758, $row_height, '0.08 0.30 0.52');
    foreach ($columns as $column) {
        $content .= pdf_text($column['x'], $table_top - 14, $column['label'], 7, '1 1 1');
    }

    foreach ($page_rows as $index => $row) {
        $y = $table_top - ($index + 2) * $row_height;
        $attended = (int)$row['sessions_attended'];
        $held = (int)$row['sessions_held'];
        $is_missed = $attended < $held;
        $previous_row = $index > 0 ? $page_rows[$index - 1] : null;
        $previous_attended = $previous_row ? (int)$previous_row['sessions_attended'] : null;
        $previous_held = $previous_row ? (int)$previous_row['sessions_held'] : null;
        $starts_missed_section = $is_missed
            && (($previous_attended !== null && $previous_held !== null && $previous_attended >= $previous_held)
                || ($index === 0 && $page_number > 1));
        if ($starts_missed_section) {
            $content .= pdf_bold_line($table_x, $y + $row_height, 800, $y + $row_height, '0.85 0.10 0.10');
            $content .= pdf_bold_text($table_x + 5, $y + $row_height + 6, 'MISSED STUDENTS', 6, '0.75 0.05 0.05');
        }
        if ($index % 2 === 0) {
            $content .= pdf_rect($table_x, $y, 758, $row_height, '0.93 0.97 1.00');
        }
        $missed = max(0, $held - $attended);
        $values = [
            substr((string)$row['course_code'], 0, 16),
            substr((string)$row['index_number'], 0, 20),
            substr((string)$row['full_name'], 0, 45),
            (string)$held,
            (string)$attended,
            (string)$missed
        ];
        foreach ($values as $value_index => $value) {
            $content .= pdf_text($columns[$value_index]['x'], $y + 7, $value, 8, '0.12 0.18 0.25');
        }
        $content .= pdf_line($table_x, $y, 800, $y);
    }

    $content .= pdf_text(42, 24, 'Confidential academic record - generated by C-Attend', 8, '0.42 0.48 0.56');
    $content .= pdf_text(735, 24, 'Page ' . $page_number, 8, '0.42 0.48 0.56');
    return $content;
}

$rows_per_page = 14;
$row_pages = array_chunk($rows, $rows_per_page);
if (!$row_pages) {
    $row_pages = [[]];
}
$pages = [];
$page_total = count($row_pages);
foreach ($row_pages as $page_number => $page_rows) {
    $pages[] = pdf_page($page_rows, $period_label, $class_label, $selected_course_label, $page_number + 1, $page_total, $total_rows, $course_count, $full_attendance_rows);
}

$logo = pdf_png_image(__DIR__ . '/../assets/logo-dashboard.png');
$objects = [];
$objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
$page_references = [];
for ($page_index = 0; $page_index < count($pages); $page_index++) {
    $page_references[] = (7 + $page_index) . ' 0 R';
}
$objects[] = '<< /Type /Pages /Kids [' . implode(' ', $page_references) . '] /Count ' . count($pages) . ' >>';
$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
$objects[] = '<< /Type /XObject /Subtype /Image /Width ' . $logo['width'] . ' /Height ' . $logo['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 3 /BitsPerComponent 8 /Columns ' . $logo['width'] . ' >> /SMask 5 0 R /Length ' . strlen($logo['rgb']) . " >>\nstream\n" . $logo['rgb'] . "\nendstream";
$objects[] = '<< /Type /XObject /Subtype /Image /Width ' . $logo['width'] . ' /Height ' . $logo['height'] . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns ' . $logo['width'] . ' >> /Length ' . strlen($logo['alpha']) . " >>\nstream\n" . $logo['alpha'] . "\nendstream";
foreach ($pages as $index => $page_content) {
    $page_object = 7 + $index;
    $content_object = 7 + count($pages) + $index;
    $objects[] = '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << /Im1 5 0 R >> >> /MediaBox [0 0 842 595] /Contents ' . $content_object . ' 0 R >>';
}
foreach ($pages as $page_content) {
    $objects[] = '<< /Length ' . strlen($page_content) . " >>\nstream\n" . $page_content . "\nendstream";
}

$pdf = "%PDF-1.4\n";
$offsets = [0];
foreach ($objects as $number => $object) {
    $object_number = $number + 1;
    $offsets[$object_number] = strlen($pdf);
    $pdf .= $object_number . " 0 obj\n" . $object . "\nendobj\n";
}
$xref_offset = strlen($pdf);
$pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
for ($i = 1; $i <= count($objects); $i++) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
}
$pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref_offset . "\n%%EOF";

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="admin_attendance_report_' . $file_period . '_' . date('Y_m_d_Hi') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
