<?php

function ensure_portal_only_session_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS portal_only_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            course_id INT NULL,
            course_code VARCHAR(20) NOT NULL,
            course_title VARCHAR(150) NOT NULL,
            created_by_admin_id INT NOT NULL,
            target_class ENUM('A','B','both') NOT NULL,
            latitude DECIMAL(10,8) NOT NULL,
            longitude DECIMAL(11,8) NOT NULL,
            radius_meters INT NOT NULL DEFAULT 300,
            start_time DATETIME NOT NULL,
            end_time DATETIME NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            pdf_data LONGBLOB NULL,
            pdf_generated_at DATETIME NULL,
            INDEX idx_portal_only_sessions_active (is_active, start_time, end_time),
            INDEX idx_portal_only_sessions_expiry (end_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS portal_only_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            student_id INT NOT NULL,
            index_number VARCHAR(30) NOT NULL,
            full_name VARCHAR(150) NOT NULL,
            marked_at DATETIME NOT NULL,
            distance_meters DECIMAL(8,2) NOT NULL,
            UNIQUE KEY uq_portal_only_session_student (session_id, student_id),
            INDEX idx_portal_only_attendance_session (session_id),
            CONSTRAINT portal_only_attendance_session_fk
                FOREIGN KEY (session_id) REFERENCES portal_only_sessions (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $constraint_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'portal_only_attendance'
          AND CONSTRAINT_NAME = 'portal_only_attendance_session_fk'
          AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ");
    $constraint_stmt->execute();
    if ((int)$constraint_stmt->fetchColumn() === 0) {
        $pdo->exec("
            DELETE pa
            FROM portal_only_attendance pa
            LEFT JOIN portal_only_sessions ps ON ps.id = pa.session_id
            WHERE ps.id IS NULL
        ");
        $pdo->exec("
            ALTER TABLE portal_only_attendance
            ADD CONSTRAINT portal_only_attendance_session_fk
                FOREIGN KEY (session_id) REFERENCES portal_only_sessions (id) ON DELETE CASCADE
        ");
    }
}

function expire_previous_day_portal_sessions(PDO $pdo): int {
    $stmt = $pdo->prepare("
        UPDATE portal_only_sessions
        SET is_active = 0
        WHERE is_active = 1
          AND created_at < CURRENT_DATE()
    ");
    $stmt->execute();

    return $stmt->rowCount();
}

function portal_pdf_escape(string $text): string {
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }
    $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function build_portal_session_pdf(array $session, array $attendance): string {
    $lines = [
        'C-Attend - Session Attendance',
        'Session #' . $session['id'] . ' | ' . $session['course_code'] . ' - ' . $session['course_title'],
        'Class: ' . ($session['target_class'] === 'both' ? 'A & B' : 'Class ' . $session['target_class']),
        'Started: ' . $session['start_time'] . '    Ended: ' . $session['end_time'],
        'Radius: ' . $session['radius_meters'] . 'm    Students marked: ' . count($attendance),
        '',
        'No.   Index number          Student name                         Marked at             Distance',
        str_repeat('-', 94)
    ];

    foreach ($attendance as $index => $record) {
        $name = function_exists('iconv') ? (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $record['full_name']) ?: $record['full_name']) : $record['full_name'];
        $lines[] = sprintf(
            '%-5d %-21s %-36s %-21s %sm',
            $index + 1,
            substr($record['index_number'], 0, 21),
            substr($name, 0, 36),
            $record['marked_at'],
            number_format((float)$record['distance_meters'], 1)
        );
    }
    if (!$attendance) {
        $lines[] = 'No students marked attendance for this session.';
    }

    $lines_per_page = 48;
    $line_pages = array_chunk($lines, $lines_per_page);
    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >>';
    $page_ids = [];
    $next_id = 5;

    foreach ($line_pages as $page_index => $page_lines) {
        $page_id = $next_id++;
        $content_id = $next_id++;
        $page_ids[] = $page_id;
        $stream = "BT\n/F2 12 Tf\n50 790 Td\n(" . portal_pdf_escape($lines[0]) . ") Tj\n/F1 8 Tf\n0 -24 Td\n";

        foreach ($page_lines as $line_index => $line) {
            if ($page_index === 0 && $line_index === 0) {
                continue;
            }
            if ($page_index > 0 && $line_index === 0) {
                $stream .= '(' . portal_pdf_escape('Session #' . $session['id'] . ' - continued') . ") Tj\n0 -15 Td\n";
            }
            $stream .= '(' . portal_pdf_escape($line) . ") Tj\n0 -14 Td\n";
        }
        $stream .= "ET\n";
        $objects[$page_id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $content_id . ' 0 R >>';
        $objects[$content_id] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    }

    $kids = implode(' ', array_map(static function (int $id): string { return $id . ' 0 R'; }, $page_ids));
    $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($page_ids) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    $object_count = max(array_keys($objects));
    for ($id = 1; $id <= $object_count; $id++) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . ($objects[$id] ?? '<<>>') . "\nendobj\n";
    }
    $xref_offset = strlen($pdf);
    $pdf .= "xref\n0 " . ($object_count + 1) . "\n0000000000 65535 f \n";
    for ($id = 1; $id <= $object_count; $id++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
    }
    $pdf .= "trailer\n<< /Size " . ($object_count + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref_offset . "\n%%EOF";
    return $pdf;
}

function finalize_and_expire_portal_only_sessions(PDO $pdo): void {
    $expired_session_ids = $pdo->query("
        SELECT id
        FROM portal_only_sessions
        WHERE end_time <= NOW()
          AND pdf_data IS NULL
          AND end_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY id ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($expired_session_ids as $session_id) {
        $pdo->beginTransaction();
        try {
            $session_stmt = $pdo->prepare('SELECT * FROM portal_only_sessions WHERE id = ? FOR UPDATE');
            $session_stmt->execute([(int)$session_id]);
            $session = $session_stmt->fetch(PDO::FETCH_ASSOC);
            if ($session && $session['pdf_data'] === null && strtotime($session['end_time']) <= time()) {
                $attendance_stmt = $pdo->prepare('SELECT index_number, full_name, marked_at, distance_meters FROM portal_only_attendance WHERE session_id = ? ORDER BY marked_at ASC, index_number ASC');
                $attendance_stmt->execute([(int)$session_id]);
                $pdf = build_portal_session_pdf($session, $attendance_stmt->fetchAll(PDO::FETCH_ASSOC));
                $update_stmt = $pdo->prepare('UPDATE portal_only_sessions SET is_active = 0, pdf_data = ?, pdf_generated_at = NOW() WHERE id = ? AND pdf_data IS NULL');
                $update_stmt->execute([$pdf, (int)$session_id]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    $pdo->exec('DELETE FROM portal_only_sessions WHERE end_time <= DATE_SUB(NOW(), INTERVAL 24 HOUR)');
}

function run_daily_geofence_reset(PDO $pdo, bool $manual = false): array {
    $standard_sessions = expire_previous_day_attendance_sessions($pdo);
    $portal_sessions = expire_previous_day_portal_sessions($pdo);
    finalize_and_expire_portal_only_sessions($pdo);

    $setting = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES ('last_daily_geofence_reset_at', NOW())
        ON DUPLICATE KEY UPDATE setting_value = IF(
            ? = 1 OR DATE(setting_value) < CURRENT_DATE(),
            VALUES(setting_value),
            setting_value
        )
    ");
    $setting->execute([$manual ? 1 : 0]);

    return [
        'standard_sessions' => $standard_sessions,
        'portal_sessions' => $portal_sessions
    ];
}
