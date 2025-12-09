<?php
// server/routes/api.php

// Сначала подключаем все необходимые файлы
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../controllers/UserController.php';
require_once __DIR__ . '/../controllers/ClubController.php';
require_once __DIR__ . '/../controllers/EventController.php'; // Добавляем EventController

class ApiRouter {
    private $db;
    private $authController;
    private $userController;
    private $clubController;
    private $eventController; // Добавляем EventController

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->authController = new AuthController($this->db);
        $this->userController = new UserController($this->db);
        $this->clubController = new ClubController($this->db);
        $this->eventController = new EventController($this->db); // Инициализируем
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

            // === EVENT ROUTES === (Мероприятия) - НОВЫЕ МАРШРУТЫ
            case $cleanPath === '/api/events' && $method === 'POST':
                // Создать мероприятие - только капитан/заместитель клуба
                // Проверка прав делается внутри контроллера
                $payload = AuthMiddleware::authenticate();
                $this->eventController->create();
                break;

            case preg_match('#^/api/clubs/(\d+)/events$#', $cleanPath, $matches) && $method === 'GET':
                // Получить все мероприятия клуба
                $payload = AuthMiddleware::authenticate();
                $this->eventController->getClubEvents($matches[1]);
                break;

            case preg_match('#^/api/events/(\d+)$#', $cleanPath, $matches) && $method === 'GET':
                // Получить одно мероприятие
                $payload = AuthMiddleware::authenticate();
                $this->eventController->getEvent($matches[1]);
                break;

            case preg_match('#^/api/events/(\d+)$#', $cleanPath, $matches) && $method === 'PUT':
                // Обновить мероприятие - только капитан/заместитель
                $payload = AuthMiddleware::authenticate();
                // Проверка прав делается внутри контроллера
                $this->eventController->update($matches[1]);
                break;

            case preg_match('#^/api/events/(\d+)$#', $cleanPath, $matches) && $method === 'DELETE':
                // Удалить мероприятие - только капитан/заместитель
                $payload = AuthMiddleware::authenticate();
                // Проверка прав делается внутри контроллера
                $this->eventController->delete($matches[1]);
                break;

            case preg_match('#^/api/events/(\d+)/register$#', $cleanPath, $matches) && $method === 'POST':
                // Зарегистрироваться на мероприятие
                $payload = AuthMiddleware::authenticate();
                $this->eventController->register($matches[1]);
                break;

            case preg_match('#^/api/events/(\d+)/cancel$#', $cleanPath, $matches) && $method === 'POST':
                // Отменить регистрацию на мероприятие
                $payload = AuthMiddleware::authenticate();
                $this->eventController->cancelRegistration($matches[1]);
                break;

            case preg_match('#^/api/events/(\d+)/participants$#', $cleanPath, $matches) && $method === 'GET':
                // Получить участников мероприятия (только капитан/заместитель/админ)
                $payload = AuthMiddleware::authenticate();
                $this->eventController->getEventParticipants($matches[1]);
                break;

            case preg_match('#^/api/events/(\d+)/participants/(\d+)$#', $cleanPath, $matches) && $method === 'PUT':
                // Обновить статус участника (отметить посещение) - только капитан/заместитель
                $payload = AuthMiddleware::authenticate();
                $eventId = $matches[1];
                $userId = $matches[2];
                $this->eventController->updateParticipantStatus($eventId, $userId);
                break;

            case $cleanPath === '/api/user/events' && $method === 'GET':
                // Получить все мероприятия текущего пользователя
                $payload = AuthMiddleware::authenticate();
                $this->eventController->getUserEvents();
                break;

            case $cleanPath === '/api/user/events/upcoming' && $method === 'GET':
                // Получить предстоящие мероприятия пользователя
                $payload = AuthMiddleware::authenticate();
                $this->eventController->getUpcomingEvents();
                break;

            case $cleanPath === '/api/debug/input' && $method === 'POST':
                $this->eventController->debugInput();
                break;

            case $cleanPath === '/api/events/test' && $method === 'POST':
                $this->eventController->createSimple();
                break;

            case preg_match('#^/api/user/events/past$#', $cleanPath) && $method === 'GET':
                // Получить прошедшие мероприятия пользователя
                $payload = AuthMiddleware::authenticate();
                $this->eventController->getPastEvents();
                break;

            case preg_match('#^/api/events/(\d+)/toggle-status$#', $cleanPath, $matches) && $method === 'PUT':
                // Изменить статус мероприятия (только капитан/заместитель)
                $payload = AuthMiddleware::authenticate();
                $this->eventController->toggleEventStatus($matches[1]);
                break;

            case preg_match('#^/api/clubs/(\d+)/events/upcoming$#', $cleanPath, $matches) && $method === 'GET':
                // Получить предстоящие мероприятия клуба
                $payload = AuthMiddleware::authenticate();
                $this->eventController->getUpcomingClubEvents($matches[1]);
                break;

            default:
                Response::error('Маршрут не найден: ' . $cleanPath, [], 404);
                break;
        }
    }
}

// Использование роутера
$router = new ApiRouter();
$router->handleRequest($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
?>