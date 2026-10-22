<?php
// config/db.php
if (!defined('BASE_URL')) {
    if (getenv('BASE_URL') !== false) {
        define('BASE_URL', rtrim(getenv('BASE_URL'), '/'));
    } else {
        // Smart auto-detection: checks if project is in /shreyansir subfolder or root domain
        $reqUri = $_SERVER['REQUEST_URI'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if (strpos($reqUri, '/shreyansir/') === 0 || strpos($scriptName, '/shreyansir/') === 0) {
            define('BASE_URL', '/shreyansir');
        } else {
            define('BASE_URL', '');
        }
    }
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

