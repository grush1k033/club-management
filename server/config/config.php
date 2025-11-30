<?php
// CORS headers
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Max-Age: 3600');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}
$environment = 'production'; // 'local' или 'production'

if ($environment === 'local') {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'club_management');
    define('DB_USER', 'root');
    define('DB_PASS', 'unypyrebe');

    define('APP_ENV', 'local');
    define('APP_URL', 'http://localhost:8000');

    error_log("=== LOCAL DEVELOPMENT MODE ===");

} elseif ($environment === 'production') {
    define('DB_HOST', 'sql103.infinityfree.com');
    define('DB_NAME', 'if0_40561099_club_management');
    define('DB_USER', 'if0_40561099');
    define('DB_PASS', 'VkvJ33ubp1yk');

    define('APP_ENV', 'production');
    define('APP_URL', 'https://unitypay.wuaze.com');

    error_log("=== PRODUCTION MODE ===");

} else {
    die('Invalid environment setting');
}

define('JWT_SECRET', 'd15f1a3e8c7b9a4d2f6e8c0b3a9d7e1f4c2a8b5d9e3f7a1c6b8d4e2f9a5c7b3');
define('JWT_EXPIRE', 3600);

// Логируем информацию о среде
error_log("Environment: " . APP_ENV);
error_log("Database: " . DB_HOST);
error_log("App URL: " . APP_URL);
?>