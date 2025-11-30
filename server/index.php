<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Max-Age: 3600');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit(0);
}require_once 'config/config.php';
require_once 'config/database.php';
require_once 'routes/api.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
// ДИАГНОСТИКА
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Убираем базовый путь если есть
$basePath = '/~username'; // если есть подпапка
if (strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}

$apiRouter = new ApiRouter();
$apiRouter->handleRequest($path, $_SERVER['REQUEST_METHOD']);
?>