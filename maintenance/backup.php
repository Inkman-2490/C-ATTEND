<?php
session_start(['cookie_httponly' => true, 'use_strict_mode' => true, 'cookie_samesite' => 'Lax']);
require_once __DIR__ . '/../includes/bootstrap.php';

ensure_maintenance_tables($pdo);
if (!isset($_SESSION['maintenance_user_id'])) {
    header('Location: login.php');
    exit;
}

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$backup = "-- C-Attend maintenance backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $table) {
    $quoted_table = '`' . str_replace('`', '``', $table) . '`';
    $create = $pdo->query("SHOW CREATE TABLE $quoted_table")->fetch(PDO::FETCH_ASSOC);
    $create_sql = $create['Create Table'] ?? $create['Create View'] ?? '';
    $backup .= "DROP TABLE IF EXISTS $quoted_table;\n$create_sql;\n\n";

    $rows = $pdo->query("SELECT * FROM $quoted_table")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $columns = array_map(static function ($column): string {
            return '`' . str_replace('`', '``', $column) . '`';
        }, array_keys($row));
        $values = array_map(static function ($value) use ($pdo): string {
            return $value === null ? 'NULL' : $pdo->quote((string)$value);
        }, array_values($row));
        $backup .= 'INSERT INTO ' . $quoted_table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
    }
    if ($rows) {
        $backup .= "\n";
    }
}

$backup .= "SET FOREIGN_KEY_CHECKS=1;\n";
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="c_attend_maintenance_backup_' . date('Y_m_d_His') . '.sql"');
echo $backup;
