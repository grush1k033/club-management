<?php
// server/routes/api.php

// Сначала подключаем все необходимые файлы
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../controllers/UserController.php';
require_once __DIR__ . '/../controllers/ClubController.php';

class ApiRouter {
    private $db;
    private $authController;
    private $userController;
    private $clubController;

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->authController = new AuthController($this->db);
        $this->userController = new UserController($this->db);
        $this->clubController = new ClubController($this->db);
    }

    public function handleRequest($path, $method) {
        // Убираем /server если есть
        $cleanPath = str_replace('/server', '', $path);

        // Маршрутизация
        switch (true) {
            // === AUTH ROUTES === (публичные, без middleware)
            case $cleanPath === '/api/auth/register' && $method === 'POST':
                $this->authController->register();
                break;

            case $cleanPath === '/api/auth/login' && $method === 'POST':
                $this->authController->login();
                break;

            case $cleanPath === '/api/auth/me' && $method === 'GET':
                // Требуется аутентификация
                $payload = AuthMiddleware::authenticate();
                $this->authController->me($payload);
                break;

            case $cleanPath === '/api/auth/refresh' && $method === 'POST':
                $this->authController->refresh();
                break;

            // === USER ROUTES ===
            case $cleanPath === '/api/users' && $method === 'GET':
                // Только админ
                AuthMiddleware::requireRole('admin');
                $this->userController->getAllUsers();
                break;

            case preg_match('#^/api/users/(\d+)$#', $cleanPath, $matches) && $method === 'GET':
                $userId = $matches[1];
                $payload = AuthMiddleware::authenticate();
                // Пользователь может смотреть свой профиль, админ - любой
                if ($payload['id'] != $userId && $payload['role'] !== 'admin') {
                    Response::forbidden('Недостаточно прав');
                }
                $this->userController->getUser($userId);
                break;

            case preg_match('#^/api/users/(\d+)$#', $cleanPath, $matches) && $method === 'PUT':
                $userId = $matches[1];
                $payload = AuthMiddleware::authenticate();
                // Пользователь может редактировать свой профиль, админ - любой
                if ($payload['id'] != $userId && $payload['role'] !== 'admin') {
                    Response::forbidden('Недостаточно прав');
                }
                $this->userController->updateUser($userId);
                break;

            case preg_match('#^/api/users/(\d+)$#', $cleanPath, $matches) && $method === 'DELETE':
                // Только админ может удалять пользователей
                AuthMiddleware::requireRole('admin');
                $this->userController->deleteUser($matches[1]);
                break;

            // === CLUB ROUTES ===
            case $cleanPath === '/api/clubs' && $method === 'POST':
                // Только админ может создавать клубы
                AuthMiddleware::requireRole('admin');
                $this->clubController->create();
                break;

            case $cleanPath === '/api/clubs' && $method === 'GET':
                // Доступно всем авторизованным пользователям
                $payload = AuthMiddleware::authenticate();
                $this->clubController->getAll($payload);
                break;

            case preg_match('#^/api/clubs/(\d+)$#', $cleanPath, $matches) && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->clubController->getById($matches[1], $payload);
                break;

            case preg_match('#^/api/clubs/(\d+)$#', $cleanPath, $matches) && $method === 'PUT':
                $clubId = $matches[1];
                $payload = AuthMiddleware::authenticate();

                // Проверяем, что пользователь - владелец клуба (captain) или админ
                // Сначала получаем информацию о клубе
                $database = new Database();
                $db = $database->getConnection();

                require_once __DIR__ . '/../models/Club.php';
                $clubModel = new Club($db);
                $clubModel->id = $clubId;
                $clubFound = $clubModel->readOne();

                if (!$clubFound) {
                    Response::error('Клуб не найден', [], 404);
                }

                // Проверяем права: капитан клуба или админ
                if ($payload['role'] !== 'admin' && $payload['id'] != $clubModel->captain_id) {
                    Response::forbidden('Только капитан клуба или администратор могут обновлять информацию о клубе');
                }

                $this->clubController->update($clubId, $payload);
                break;

            case preg_match('#^/api/clubs/(\d+)$#', $cleanPath, $matches) && $method === 'DELETE':
                // Только админ может удалять клубы
                AuthMiddleware::requireRole('admin');
                $this->clubController->delete($matches[1]);
                break;

            case preg_match('#^/api/clubs/search/(.+)$#', $cleanPath, $matches) && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $searchQuery = urldecode($matches[1]);
                $this->clubController->search($searchQuery, $payload);
                break;

            case preg_match('#^/api/clubs/category/(.+)$#', $cleanPath, $matches) && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $category = urldecode($matches[1]);
                $this->clubController->getByCategory($category, $payload);
                break;

            case $cleanPath === '/api/clubs/categories' && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->clubController->getCategories();
                break;

            case preg_match('#^/api/clubs/(\d+)/toggle-status$#', $cleanPath, $matches) && $method === 'PUT':
                // Только админ может менять статус
                AuthMiddleware::requireRole('admin');
                $this->clubController->toggleStatus($matches[1]);
                break;

            default:
                Response::error('Маршрут не найден: ' . $cleanPath, [], 404);
                break;
        }
    }
}