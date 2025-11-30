<?php
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class ApiRouter {
    private $db;
    private $authController;

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->authController = new AuthController($this->db);
    }

    public function handleRequest($path, $method) {
        $path = str_replace('/server', '', $path); //если лолально, то убрать строку
        // Маршрутизация
        switch (true) {
            case $path === '/api/auth/register' && $method === 'POST':
                $this->authController->register();
                break;

            case $path === '/api/auth/login' && $method === 'POST':
                $this->authController->login();
                break;

            case $path === '/api/auth/me' && $method === 'GET':
                $this->authController->me();
                break;

            case $path === '/api/auth/refresh' && $method === 'POST':
                $this->authController->refresh();
                break;

            case $path === '/api/test' && $method === 'GET':
                header('Content-Type: application/json');
                echo json_encode(['test' => 'success', 'message' => 'Router works']);
                exit;

            default:
                Response::error('Маршрут не найден', null, 404);
                break;
        }
    }
}
?>