<?php
// config/db.php
if (!defined('BASE_URL')) {
    define('BASE_URL', getenv('BASE_URL') !== false ? getenv('BASE_URL') : '/shreyansir');
}
$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3308';
$db   = getenv('DB_NAME') ?: 'online_exam';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     date_default_timezone_set('Asia/Kolkata');
     $pdo->exec("SET time_zone = '+05:30'");
} catch (\PDOException $e) {
     die("Database connection failed: " . $e->getMessage());
}

return $pdo;

