<?php
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../controllers/UserController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';

class ApiRouter {
    private $db;
    private $authController;
    private $userController;

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->authController = new AuthController($this->db);
        $this->userController = new UserController($this->db);
    }

    public function handleRequest($path, $method) {
        // ДИАГНОСТИКА
        error_log("=== API ROUTER ===");
        error_log("Path: " . $path);
        error_log("Method: " . $method);

        // Убираем /server если есть
        $cleanPath = str_replace('/server', '', $path);
        error_log("Clean path: " . $cleanPath);

        // Маршрутизация
        switch (true) {
            // === AUTH ROUTES === (эти работают)
            case $cleanPath === '/api/auth/register' && $method === 'POST':
                error_log("Routing to register");
                $this->authController->register();
                break;

            case $cleanPath === '/api/auth/login' && $method === 'POST':
                error_log("Routing to login");
                $this->authController->login();
                break;

            case $cleanPath === '/api/auth/me' && $method === 'GET':
                error_log("Routing to me");
                $this->authController->me();
                break;

            case $cleanPath === '/api/auth/refresh' && $method === 'POST':
                error_log("Routing to refresh");
                $this->authController->refresh();
                break;

            // === USER ROUTES === (добавляем эти)
            case $cleanPath === '/api/users' && $method === 'GET':
                error_log("Routing to getAllUsers");
                $this->userController->getAllUsers();
                break;

            case preg_match('#^/api/users/(\d+)$#', $cleanPath, $matches) && $method === 'GET':
                error_log("Routing to getUser with ID: " . $matches[1]);
                $this->userController->getUser($matches[1]);
                break;

            case preg_match('#^/api/users/(\d+)$#', $cleanPath, $matches) && $method === 'PUT':
                error_log("Routing to updateUser with ID: " . $matches[1]);
                $this->userController->updateUser($matches[1]);
                break;

            case preg_match('#^/api/users/(\d+)$#', $cleanPath, $matches) && $method === 'DELETE':
                error_log("Routing to deleteUser with ID: " . $matches[1]);
                $this->userController->deleteUser($matches[1]);
                break;

            default:
                error_log("ROUTE NOT FOUND");
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Маршрут не найден: ' . $cleanPath,
                    'path' => $cleanPath,
                    'method' => $method
                ]);
                break;
        }
    }
}
?>