<?php
// server/routes/api.php

// Сначала подключаем все необходимые файлы
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../controllers/UserController.php';
require_once __DIR__ . '/../controllers/ClubController.php';
require_once __DIR__ . '/../controllers/EventController.php';
require_once __DIR__ . '/../controllers/PaymentController.php';

class ApiRouter {
    private $db;
    private $authController;
    private $userController;
    private $clubController;
    private $eventController;
    private $paymentController;

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->authController = new AuthController($this->db);
        $this->userController = new UserController($this->db);
        $this->clubController = new ClubController($this->db);
        $this->eventController = new EventController($this->db);
        $this->paymentController = new PaymentController($this->db);
    }

    public function handleRequest($path, $method) {
        // Убираем /server если есть
        $cleanPath = str_replace('/server', '', $path);

        // Маршрутизация
        switch (true) {
            // ================================
            // AUTH ROUTES (публичные, без middleware)
            // ================================
            case $cleanPath === '/api/auth/register' && $method === 'POST':
                $this->authController->register();
                break;

            case $cleanPath === '/api/auth/login' && $method === 'POST':
                $this->authController->login();
                break;

            case $cleanPath === '/api/auth/me' && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->authController->me($payload);
                break;

            case $cleanPath === '/api/auth/refresh' && $method === 'POST':
                $this->authController->refresh();
                break;

            // ================================
            // USER ROUTES
            // ================================
            case $cleanPath === '/api/users' && $method === 'GET':
                AuthMiddleware::requireRole('admin');
                $this->userController->getAllUsers();
                break;

            case $cleanPath === '/api/reports/users-detailed' && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->userController->getUsersDetailedReport($payload);
                break;

            case preg_match('#^/api/members/search(?:\?.*)?$#', $cleanPath) && $method === 'GET':
                $payload = AuthMiddleware::authenticate();

                $searchTerm = isset($_GET['query']) ? trim($_GET['query']) : '';

                $this->userController->searchMembers($searchTerm, $payload);
                break;


            case preg_match('#^/api/users/(\d+)$#', $cleanPath, $matches) && $method === 'GET':
                $userId = $matches[1];
                $payload = AuthMiddleware::authenticate();
                if ($payload['user_id'] != $userId && $payload['role'] !== 'admin') {
                    Response::forbidden('Недостаточно прав');
                }
                $this->userController->getUser($userId);
                break;

            case preg_match('#^/api/users/(\d+)$#', $cleanPath, $matches) && $method === 'PUT':
                $userId = $matches[1];
                $payload = AuthMiddleware::authenticate();
                if ($payload['user_id'] != $userId && $payload['role'] !== 'admin') {
                    Response::forbidden('Недостаточно прав');
                }
                $this->userController->updateUser($userId);
                break;

            case preg_match('#^/api/users/(\d+)$#', $cleanPath, $matches) && $method === 'PATCH':
                $userId = $matches[1];
                $payload = AuthMiddleware::authenticate();
                if ($payload['user_id'] != $userId && $payload['role'] !== 'admin') {
                    Response::forbidden('Недостаточно прав');
                }
                $this->userController->patchUser($userId);
                break;

            case preg_match('#^/api/users/(\d+)$#', $cleanPath, $matches) && $method === 'DELETE':
                AuthMiddleware::requireRole('admin');
                $this->userController->deleteUser($matches[1]);
                break;

            // ================================
            // CLUB ROUTES
            // ================================
            case $cleanPath === '/api/clubs' && $method === 'POST':
                AuthMiddleware::requireRole('admin');
                $this->clubController->create();
                break;

            case $cleanPath === '/api/clubs' && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->clubController->getAll($payload);
                break;

            case $cleanPath === '/api/reports/clubs-summary' && $method === 'GET':
                $payload = AuthMiddleware::authenticate(); // Только проверка авторизации
                $this->clubController->getClubsSummaryReport($payload);
                break;

            case $cleanPath === '/api/stats/platform' && $method === 'GET':
                $payload = AuthMiddleware::authenticate(); // Проверка авторизации
                $this->clubController->getPlatformStats($payload);
                break;

            // Поиск клубов
            case preg_match('#^/api/clubs/search(?:\?.*)?$#', $cleanPath) && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $searchTerm = isset($_GET['query']) ? trim($_GET['query']) : '';
                $this->clubController->searchClubs($searchTerm, $payload);
                break;

            case preg_match('#^/api/clubs/(\d+)$#', $cleanPath, $matches) && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->clubController->getById($matches[1], $payload);
                break;

            case preg_match('#^/api/clubs/(\d+)$#', $cleanPath, $matches) && $method === 'PUT':
                $clubId = $matches[1];
                $payload = AuthMiddleware::authenticate();
                $this->clubController->updateClub($clubId, $payload);
                break;

            case preg_match('#^/api/clubs/(\d+)$#', $cleanPath, $matches) && $method === 'DELETE':
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
                AuthMiddleware::requireRole('admin');
                $this->clubController->toggleStatus($matches[1]);
                break;

            case preg_match('#^/api/clubs/(\d+)/join$#', $cleanPath, $matches) && $method === 'POST':
                $clubId = $matches[1];
                $payload = AuthMiddleware::authenticate();
                $this->clubController->requestToJoin($clubId);
                break;

            case preg_match('#^/api/clubs/(\d+)/leave$#', $cleanPath, $matches) && $method === 'POST':
                $clubId = $matches[1];
                AuthMiddleware::authenticate();
                $this->clubController->leaveClub($clubId);
                break;

            case preg_match('#^/api/clubs/(\d+)/events$#', $cleanPath, $matches) && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->eventController->getClubEvents($matches[1]);
                break;

            case preg_match('#^/api/clubs/(\d+)/events/upcoming$#', $cleanPath, $matches) && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->eventController->getUpcomingClubEvents($matches[1]);
                break;

            // ================================
            // EVENT ROUTES
            // ================================
            case $cleanPath === '/api/events' && $method === 'POST':
                $payload = AuthMiddleware::authenticate();
                $this->eventController->create();
                break;

            case $cleanPath === '/api/events/report' && $method === 'GET':
                // Доступно для всех авторизованных пользователей
                $payload = AuthMiddleware::authenticate();
                $this->eventController->getEventsReport();
                break;

            // Поиск ивентов
            case preg_match('#^/api/events/search(?:\?.*)?$#', $cleanPath) && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $searchTerm = isset($_GET['query']) ? trim($_GET['query']) : '';
                $this->eventController->searchEvents($searchTerm, $payload);
                break;

            case preg_match('#^/api/events/(\d+)$#', $cleanPath, $matches) && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->eventController->getEvent($matches[1]);
                break;

            case preg_match('#^/api/events/(\d+)$#', $cleanPath, $matches) && $method === 'PUT':
                $payload = AuthMiddleware::authenticate();
                $this->eventController->update($matches[1]);
                break;

            case preg_match('#^/api/events/(\d+)$#', $cleanPath, $matches) && $method === 'DELETE':
                $payload = AuthMiddleware::authenticate();
                $this->eventController->delete($matches[1]);
                break;

            case preg_match('#^/api/events/(\d+)/register$#', $cleanPath, $matches) && $method === 'POST':
                $payload = AuthMiddleware::authenticate();
                $this->eventController->register($matches[1]);
                break;

            case preg_match('#^/api/events/(\d+)/cancel$#', $cleanPath, $matches) && $method === 'POST':
                $payload = AuthMiddleware::authenticate();
                $this->eventController->cancelRegistration($matches[1]);
                break;

            case preg_match('#^/api/events/(\d+)/participants$#', $cleanPath, $matches) && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->eventController->getEventParticipants($matches[1]);
                break;

            case preg_match('#^/api/events/(\d+)/participants/(\d+)$#', $cleanPath, $matches) && $method === 'PUT':
                $payload = AuthMiddleware::authenticate();
                $eventId = $matches[1];
                $userId = $matches[2];
                $this->eventController->updateParticipantStatus($eventId, $userId);
                break;

            case preg_match('#^/api/events/(\d+)/toggle-status$#', $cleanPath, $matches) && $method === 'PUT':
                $payload = AuthMiddleware::authenticate();
                $this->eventController->toggleEventStatus($matches[1]);
                break;

            // ================================
            // USER EVENTS ROUTES
            // ================================
            case $cleanPath === '/api/user/events' && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->eventController->getUserEvents();
                break;

            case $cleanPath === '/api/user/events/upcoming' && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->eventController->getUpcomingEvents();
                break;

            case $cleanPath === '/api/user/events/past' && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->eventController->getPastEvents();
                break;

            case preg_match('#^/api/users/(\d+)/events$#', $cleanPath, $matches) && $method === 'GET':
                $userId = $matches[1];
                $payload = AuthMiddleware::authenticate();
                if ($payload['role'] !== 'admin') {
                    Response::forbidden('Только администратор может просматривать мероприятия других пользователей');
                }
                $this->eventController->getUserEventsAdmin($userId);
                break;

            case preg_match('#^/api/users/(\d+)/events/upcoming$#', $cleanPath, $matches) && $method === 'GET':
                $userId = $matches[1];
                $payload = AuthMiddleware::authenticate();
                if ($payload['role'] !== 'admin') {
                    Response::forbidden('Только администратор может просматривать мероприятия других пользователей');
                }
                $this->eventController->getUpcomingEventsAdmin($userId);
                break;

            case preg_match('#^/api/users/(\d+)/events/past$#', $cleanPath, $matches) && $method === 'GET':
                $userId = $matches[1];
                $payload = AuthMiddleware::authenticate();
                if ($payload['role'] !== 'admin') {
                    Response::forbidden('Только администратор может просматривать мероприятия других пользователей');
                }
                $this->eventController->getPastEventsAdmin($userId);
                break;

            // ================================
            // PAYMENT ROUTES
            // ================================
            case $cleanPath === '/api/payments' && $method === 'POST':
                $payload = AuthMiddleware::authenticate();
                $this->paymentController->create();
                break;

            case $cleanPath === '/api/payments' && $method === 'GET':
                AuthMiddleware::requireRole('admin');
                $this->paymentController->getAll();
                break;

            case $cleanPath === '/api/payments/my' && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->paymentController->getMyPayments();
                break;

            case preg_match('#^/api/payments/(\d+)$#', $cleanPath, $matches) && $method === 'GET':
                $paymentId = $matches[1];
                $payload = AuthMiddleware::authenticate();
                $this->paymentController->getById($paymentId);
                break;

            case preg_match('#^/api/payments/(\d+)/status$#', $cleanPath, $matches) && $method === 'PATCH':
                $paymentId = $matches[1];
                $payload = AuthMiddleware::authenticate();
                $this->paymentController->updateStatus($paymentId);
                break;

            case $cleanPath === '/api/payments/event' && $method === 'POST':
                $payload = AuthMiddleware::authenticate();
                $this->paymentController->payForEvent();
                break;

            case $cleanPath === '/api/payments/club-fee' && $method === 'POST':
                $payload = AuthMiddleware::authenticate();
                $this->paymentController->payClubFee();
                break;

            case preg_match('#^/api/clubs/(\d+)/payments$#', $cleanPath, $matches) && $method === 'GET':
                $clubId = $matches[1];
                $payload = AuthMiddleware::authenticate();
                $this->paymentController->getClubPayments($clubId);
                break;

            case $cleanPath === '/api/payments/stats' && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->paymentController->getStats();
                break;

            // ================================
            // BALANCE ROUTES
            // ================================
            case $cleanPath === '/api/user/balance' && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->paymentController->getUserBalance();
                break;

            case $cleanPath === '/api/user/balance/transactions' && $method === 'GET':
                $payload = AuthMiddleware::authenticate();
                $this->paymentController->getBalanceTransactions();
                break;

            // ================================
            // JOINING FEE ROUTES
            // ================================
            case preg_match('#^/api/clubs/(\d+)/joining-fee$#', $cleanPath, $matches) && $method === 'GET':
                $clubId = $matches[1];
                $payload = AuthMiddleware::authenticate();
                $this->paymentController->getJoiningFee($clubId);
                break;

            case preg_match('#^/api/clubs/(\d+)/pay-joining-fee$#', $cleanPath, $matches) && $method === 'POST':
                $clubId = $matches[1];
                $payload = AuthMiddleware::authenticate();
                $this->paymentController->payJoiningFee($clubId);
                break;

            default:
                Response::error('Маршрут не найден: ' . $cleanPath, [], 404);
                break;
        }
    }
}

$router = new ApiRouter();
$router->handleRequest($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
?>