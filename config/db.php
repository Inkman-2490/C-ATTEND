<?php
// config/db.php

date_default_timezone_set('Africa/Accra');

$app_env = strtolower(trim((string)getenv('APP_ENV'))) ?: 'development';
$host = trim((string)getenv('DB_HOST'));
$db   = trim((string)getenv('DB_NAME'));
$user = trim((string)getenv('DB_USER'));
$pass = (string)getenv('DB_PASS');

if ($app_env !== 'production' && $host === '' && $db === '' && $user === '' && $pass === '') {
    $host = 'localhost';
    $db = 'c_attend';
    $user = 'root';
    $pass = '';
}

if ($host === '' || $db === '' || $user === '' || ($app_env === 'production' && $pass === '') || ($app_env === 'production' && strtolower($user) === 'root')) {
    throw new RuntimeException('Database configuration is incomplete or unsafe for this environment. Set APP_ENV, DB_HOST, DB_NAME, DB_USER, and DB_PASS before starting the application.');
}
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->exec("SET time_zone = '+00:00'");
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
