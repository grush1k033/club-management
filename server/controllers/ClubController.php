<?php
// server/controllers/ClubController.php

require_once __DIR__ . '/../models/Club.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';

class ClubController {
    private $clubModel;
    private $response;
    private $validator;
    private $db;

    public function __construct($db) {
        $this->db = $db;
        $this->clubModel = new Club($db);
        $this->response = new Response();
        $this->validator = new Validator();
    }

    // Получение ВСЕХ клубов БЕЗ пагинации
    public function getAll($payload) {
        $this->clubModel->search = isset($_GET['search']) ? $_GET['search'] : '';
        $this->clubModel->category_filter = isset($_GET['category']) ? $_GET['category'] : '';
        $this->clubModel->status_filter = isset($_GET['status']) ? $_GET['status'] : '';

        // Получаем ВСЕ клубы
        $clubs = $this->clubModel->readAll();

        // Получаем общее количество
//        $total = $this->clubModel->countAll();

        Response::success('Список клубов', [
            'clubs' => $clubs,
            'total' => count($clubs) + 1
        ]);
    }

    // Получить общее количество (для совместимости, но теперь всегда = количество полученных)
    public function countAll() {
        $query = "SELECT COUNT(*) as total FROM " . $this->table . " WHERE 1=1";

        $conditions = [];
        $params = [];

        if (!empty($this->search)) {
            $conditions[] = "(name LIKE :search OR description LIKE :search_desc)";
            $searchTerm = "%" . $this->search . "%";
            $params[':search'] = $searchTerm;
            $params[':search_desc'] = $searchTerm;
        }

        if (!empty($this->category_filter)) {
            $conditions[] = "category = :category";
            $params[':category'] = $this->category_filter;
        }

        if (!empty($this->status_filter)) {
            $conditions[] = "status = :status";
            $params[':status'] = $this->status_filter;
        }

        if (!empty($conditions)) {
            $query .= " AND " . implode(" AND ", $conditions);
        }

        $stmt = $this->conn->prepare($query);

        // Привязываем параметры
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total'] || 0);
    }

    // Получение клуба по ID (ДОБАВЬТЕ ЭТОТ МЕТОД)
    public function getById($clubId, $payload) {
        // Устанавливаем ID в модели
        $this->clubModel->id = $clubId;

        // Получаем клуб
        $found = $this->clubModel->readOne();

        if (!$found) {
            Response::error('Клуб не найден', [], 404);
        }

        // Возвращаем данные клуба
        $clubData = [
            'id' => $this->clubModel->id,
            'name' => $this->clubModel->name,
            'status' => $this->clubModel->status,
            'description' => $this->clubModel->description,
            'category' => $this->clubModel->category,
            'email' => $this->clubModel->email,
            'phone' => $this->clubModel->phone,
            'captain_id' => $this->clubModel->captain_id,
            'vice_captain_id' => $this->clubModel->vice_captain_id,
            'created_at' => $this->clubModel->created_at,
            'updated_at' => $this->clubModel->updated_at,
            'captain_name' => $this->clubModel->captain_name,
            'vice_captain_name' => $this->clubModel->vice_captain_name
        ];

        Response::success('Информация о клубе', $clubData);
    }

    // Создание клуба
    public function create() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            Response::error('Неверный формат данных', [], 400);
        }

        // Валидация
        $rules = [
            'name' => 'required|min:3|max:100',
            'description' => 'required|min:10|max:500',
            'category' => 'required|min:2|max:50',
            'email' => 'required|email',
            'phone' => 'required|min:10|max:20',
            'captain_id' => 'required|integer'
        ];

        // Опциональные поля
        if (isset($data['vice_captain_id']) && $data['vice_captain_id'] !== '') {
            $rules['vice_captain_id'] = 'integer';
        }
        if (isset($data['status'])) {
            $rules['status'] = 'in:Active,Inactive,Pending';
        }

        $errors = $this->validator->validate($data, $rules);
        if (!empty($errors)) {
            Response::error('Ошибка валидации', $errors, 400);
        }

        // Заполняем модель
        $this->clubModel->name = $data['name'];
        $this->clubModel->description = $data['description'];
        $this->clubModel->category = $data['category'];
        $this->clubModel->email = $data['email'];
        $this->clubModel->phone = $data['phone'];
        $this->clubModel->captain_id = $data['captain_id'];

        // Обработка vice_captain_id - если пусто, то null
        $this->clubModel->vice_captain_id = isset($data['vice_captain_id']) && $data['vice_captain_id'] !== ''
            ? (int)$data['vice_captain_id']
            : null;

        $this->clubModel->status = $data['status'] || 'Active';

        // Создаем клуб
        $created = $this->clubModel->create();

        if ($created) {
            Response::success('Клуб успешно создан', [
                'id' => $this->clubModel->id,
                'name' => $this->clubModel->name
            ], 201);
        } else {
            Response::error('Не удалось создать клуб', [], 500);
        }
    }
// В классе ClubController добавьте этот метод:

    public function update($clubId, $payload) {
        // Получаем данные запроса
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        if (!$data) {
            Response::error('Некорректные данные');
        }

        // Валидация
        $errors = [];

        if (isset($data['name']) && empty(trim($data['name']))) {
            $errors['name'] = 'Название клуба не может быть пустым';
        }

        if (isset($data['category']) && empty(trim($data['category']))) {
            $errors['category'] = 'Категория не может быть пустой';
        }

        if (isset($data['status']) && !in_array($data['status'], ['Active', 'Inactive'])) {
            $errors['status'] = 'Статус должен быть Active или Inactive';
        }

        if (isset($data['captain_id']) && !is_numeric($data['captain_id'])) {
            $errors['captain_id'] = 'ID капитана должен быть числом';
        }

        if (isset($data['vice_captain_id']) && !empty($data['vice_captain_id']) && !is_numeric($data['vice_captain_id'])) {
            $errors['vice_captain_id'] = 'ID заместителя должен быть числом';
        }

        if (!empty($errors)) {
            Response::error('Ошибки валидации', $errors, 422);
        }

        // Обновляем клуб
        $club = new Club($this->db);
        $club->id = $clubId;

        // Устанавливаем только переданные поля
        if (isset($data['name'])) $club->name = $data['name'];
        if (isset($data['description'])) $club->description = $data['description'];
        if (isset($data['category'])) $club->category = $data['category'];
        if (isset($data['email'])) $club->email = $data['email'];
        if (isset($data['phone'])) $club->phone = $data['phone'];
        if (isset($data['status'])) $club->status = $data['status'];
        if (isset($data['captain_id'])) $club->captain_id = $data['captain_id'];
        if (isset($data['vice_captain_id'])) $club->vice_captain_id = $data['vice_captain_id'];

        if ($club->update()) {
            Response::success('Клуб обновлен');
        } else {
            Response::error('Ошибка при обновлении клуба');
        }
    }
    public function updateClub($clubId, $payload) {
        // Получаем информацию о клубе
        $club = new Club($this->db);
        $club->id = $clubId;
        $clubData = $club->readOne();

        if (!$clubData) {
            Response::error('Клуб не найден', [], 404);
        }

        // Проверяем права: капитан клуба или админ
        // Проблема была здесь: $clubData может быть false или не содержать 'captain_id'
        $isCaptain = isset($clubData['captain_id']) && ($payload['user_id'] == $clubData['captain_id']);
        $isAdmin = ($payload['role'] === 'admin');

        if (!$isAdmin && !$isCaptain) {
            Response::forbidden('Только капитан клуба или администратор могут обновлять информацию о клубе');
        }

        // Получаем данные запроса
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        if (!$data) {
            Response::error('Некорректные данные');
        }

        // Валидация
        $errors = [];

        if (isset($data['name']) && empty(trim($data['name']))) {
            $errors['name'] = 'Название клуба не может быть пустым';
        }

        if (isset($data['category']) && empty(trim($data['category']))) {
            $errors['category'] = 'Категория не может быть пустой';
        }

        if (isset($data['status']) && !in_array($data['status'], ['Active', 'Inactive'])) {
            $errors['status'] = 'Статус должен быть Active или Inactive';
        }

        if (!empty($errors)) {
            Response::error('Ошибки валидации', $errors, 422);
        }

        // Обновляем клуб
        $club = new Club($this->db);
        $club->id = $clubId;

        if (isset($data['name'])) $club->name = $data['name'];
        if (isset($data['description'])) $club->description = $data['description'];
        if (isset($data['category'])) $club->category = $data['category'];
        if (isset($data['email'])) $club->email = $data['email'];
        if (isset($data['phone'])) $club->phone = $data['phone'];
        if (isset($data['status'])) $club->status = $data['status'];

        if ($club->update()) {
            Response::success('Клуб обновлен');
        } else {
            Response::error('Ошибка при обновлении клуба');
        }
    }

    // Удаление клуба (ДОБАВЬТЕ ЭТОТ МЕТОД, если его нет)
    public function delete($clubId) {
        // Проверяем существование клуба
        $this->clubModel->id = $clubId;
        $found = $this->clubModel->readOne();

        if (!$found) {
            Response::error('Клуб не найден', [], 404);
        }

        // Удаляем клуб
        $deleted = $this->clubModel->delete();

        if ($deleted) {
            Response::success('Клуб успешно удален', [], 200);
        } else {
            Response::error('Не удалось удалить клуб', [], 500);
        }
    }

    // Поиск клубов (ДОБАВЬТЕ ЭТОТ МЕТОД)
    public function search($query, $payload) {
        $searchTerm = urldecode($query);
        $clubs = $this->clubModel->search($searchTerm);

        Response::success('Результаты поиска', [
            'query' => $searchTerm,
            'clubs' => $clubs,
            'total' => count($clubs)
        ]);
    }

    // Получение клубов по категории (ДОБАВЬТЕ ЭТОТ МЕТОД)
    public function getByCategory($category, $payload) {
        $clubs = $this->clubModel->getByCategory($category);

        Response::success('Клубы по категории', [
            'category' => $category,
            'clubs' => $clubs,
            'total' => count($clubs)
        ]);
    }

    // Получение всех категорий (ДОБАВЬТЕ ЭТОТ МЕТОД)
    public function getCategories() {
        $categories = $this->clubModel->getCategories();

        Response::success('Категории клубов', [
            'categories' => $categories
        ]);
    }

    // Переключение статуса клуба (ДОБАВЬТЕ ЭТОТ МЕТОД)
    public function toggleStatus($clubId) {
        // Проверяем существование клуба
        $this->clubModel->id = $clubId;
        $found = $this->clubModel->readOne();

        if (!$found) {
            Response::error('Клуб не найден', [], 404);
        }

        // Переключаем статус
        $toggled = $this->clubModel->toggleStatus();

        if ($toggled) {
            Response::success('Статус клуба успешно изменен', [], 200);
        } else {
            Response::error('Не удалось изменить статус клуба', [], 500);
        }
    }

    // server/controllers/ClubController.php

// Подать заявку на вступление в клуб (с оплатой)
    public function requestToJoin($clubId) {
        $payload = AuthMiddleware::authenticate();
        $userId = $payload['user_id'];
        $data = json_decode(file_get_contents('php://input'), true);

        // Проверяем, что пользователь не в клубе
        $userQuery = "SELECT club_id FROM users WHERE id = :user_id";
        $userStmt = $this->db->prepare($userQuery);
        $userStmt->execute([':user_id' => $userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if ($user['club_id'] !== null) {
            return Response::error("You already belong to a club. Leave current club first.", 400);
        }

        // Проверяем существование клуба
        $clubQuery = "SELECT id FROM clubs WHERE id = :club_id AND status = 'Active'";
        $clubStmt = $this->db->prepare($clubQuery);
        $clubStmt->execute([':club_id' => $clubId]);
        $club = $clubStmt->fetch(PDO::FETCH_ASSOC);

        if (!$club) {
            return Response::error("Club not found or inactive", 404);
        }

        // Проверяем, нет ли активной заявки
        $activeRequestQuery = "SELECT id FROM club_join_requests 
                          WHERE user_id = :user_id 
                          AND club_id = :club_id 
                          AND status = 'pending'";
        $activeStmt = $this->db->prepare($activeRequestQuery);
        $activeStmt->execute([
            ':user_id' => $userId,
            ':club_id' => $clubId
        ]);

        if ($activeStmt->rowCount() > 0) {
            return Response::error("You already have a pending request to this club", 400);
        }

        // Создаем заявку
        $query = "INSERT INTO club_join_requests 
              (user_id, club_id, message) 
              VALUES (:user_id, :club_id, :message)";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':user_id' => $userId,
            ':club_id' => $clubId,
            ':message' => $data['message'] || 'Request to join club'
        ]);

        $requestId = $this->db->lastInsertId();

        return Response::success([
            'request_id' => $requestId,
            'message' => 'Join request submitted. Please pay the joining fee to complete.',
            'next_step' => 'pay_joining_fee'
        ], "Join request created", 201);
    }

    // server/controllers/ClubController.php
    public function leaveClub($clubId) {
        $payload = AuthMiddleware::authenticate();
        $userId = $payload['user_id'];

        // Проверяем, что пользователь состоит в этом клубе
        $checkQuery = "SELECT id FROM users WHERE id = :user_id AND club_id = :club_id";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->execute([
            ':user_id' => $userId,
            ':club_id' => $clubId
        ]);

        if ($checkStmt->rowCount() === 0) {
            return Response::error("You are not a member of this club", 400);
        }

        // Обновляем club_id пользователя на NULL
        $updateQuery = "UPDATE users SET club_id = NULL, updated_at = NOW() WHERE id = :user_id";
        $updateStmt = $this->db->prepare($updateQuery);
        $updateStmt->execute([':user_id' => $userId]);

        return Response::success(null, "Successfully left the club");
    }

    public function getClubsSummaryReport($payload = null) {
        try {
            $isAdmin = ($payload && $payload['role'] === 'admin');

            $query = "
            SELECT 
                c.id AS club_id,
                c.name AS club_name,
                c.category AS category,
                c.status AS club_status,
                COUNT(DISTINCT u.id) AS members_count,
                COUNT(DISTINCT e.id) AS events_count,
                CONCAT(captain.first_name, ' ', captain.last_name) AS captain_name,
                captain.email AS captain_email,
                captain.phone AS captain_phone,
                CONCAT(vice_captain.first_name, ' ', vice_captain.last_name) AS vice_captain_name,
                c.created_at,
                c.updated_at
            FROM clubs c
            LEFT JOIN users u ON u.club_id = c.id
            LEFT JOIN events e ON e.club_id = c.id
            LEFT JOIN users captain ON captain.id = c.captain_id
            LEFT JOIN users vice_captain ON vice_captain.id = c.vice_captain_id
            WHERE 1=1
        ";

            // Если не админ, показываем только активные клубы
            if (!$isAdmin) {
                $query .= " AND c.status = 'Active'";
            }

            $query .= "
            GROUP BY c.id, c.name, c.category, c.status, captain.first_name, captain.last_name, 
                     captain.email, captain.phone, vice_captain.first_name, vice_captain.last_name
            ORDER BY c.name ASC
        ";

            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Форматируем результат
            $formattedResult = [];
            foreach ($result as $row) {
                $formattedResult[] = [
                    'club_id' => $row['club_id'],
                    'club_name' => $row['club_name'],
                    'category' => $row['category'],
                    'status' => $row['club_status'],
                    'members' => (int)$row['members_count'],
                    'events' => (int)$row['events_count'],
                    'captain' => $row['captain_name'],
                    'captain_email' => $row['captain_email'],
                    'captain_phone' => $row['captain_phone'],
                    'vice_captain' => $row['vice_captain_name'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at']
                ];
            }

            Response::success('Отчет по клубам получен', [
                'total_clubs' => count($formattedResult),
                'clubs' => $formattedResult,
                'view_all' => $isAdmin  // Информация о том, видит ли пользователь все клубы
            ]);
        } catch (Exception $e) {
            Response::error('Ошибка при получении отчета: ' . $e->getMessage(), [], 500);
        }
    }

    // server/controllers/ClubController.php
// Добавьте этот метод в класс ClubController

    public function getPlatformStats($payload = null) {
        try {
            $query = "
        SELECT 
            COUNT(DISTINCT c.id) AS total_clubs,
            COUNT(DISTINCT CASE WHEN c.status = 'Active' THEN c.id END) AS active_clubs,
            COUNT(DISTINCT e.id) AS total_events,
            COUNT(DISTINCT CASE WHEN YEARWEEK(e.event_date, 1) = YEARWEEK(CURDATE(), 1) THEN e.id END) AS events_this_week,
            COUNT(DISTINCT u.id) AS total_members,
            COUNT(DISTINCT CASE WHEN YEARWEEK(e.event_date, 1) = YEARWEEK(CURDATE() + INTERVAL 7 DAY, 1) THEN e.id END) AS events_next_week,
            COUNT(DISTINCT CASE WHEN e.event_date > NOW() THEN e.id END) AS upcoming_events,
            COUNT(DISTINCT CASE WHEN e.event_date < NOW() THEN e.id END) AS past_events
        FROM clubs c
        LEFT JOIN events e ON e.club_id = c.id
        LEFT JOIN users u ON u.club_id = c.id
        ";

            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            // Преобразуем числовые значения
            $stats = [
                'total_clubs' => (int)$result['total_clubs'],
                'active_clubs' => (int)$result['active_clubs'],
                'inactive_clubs' => (int)$result['total_clubs'] - (int)$result['active_clubs'],
                'total_events' => (int)$result['total_events'],
                'events_this_week' => (int)$result['events_this_week'],
                'events_next_week' => isset($result['events_next_week']) ? (int)$result['events_next_week'] : 0,
                'upcoming_events' => isset($result['upcoming_events']) ? (int)$result['upcoming_events'] : 0,
                'past_events' => isset($result['past_events']) ? (int)$result['past_events'] : 0,
                'total_members' => (int)$result['total_members'],
                'current_week' => date('W, Y'),
                'timestamp' => time()
            ];

            Response::success('Статистика платформы получена', $stats);
        } catch (Exception $e) {
            Response::error('Ошибка при получении статистики: ' . $e->getMessage(), [], 500);
        }
    }

    // server/controllers/ClubController.php
    public function searchClubs($searchTerm, $payload) {
        try {
            $query = "
        SELECT 
            c.id AS club_id,
            c.name AS club_name,
            c.status AS club_status,
            c.category AS club_category,
            c.description AS club_description,
            c.email AS club_email,
            c.phone AS club_phone,
            CONCAT(captain.first_name, ' ', captain.last_name) AS captain_name,
            captain.email AS captain_email,
            captain.phone AS captain_phone,
            CONCAT(vice_captain.first_name, ' ', vice_captain.last_name) AS vice_captain_name,
            (SELECT COUNT(*) FROM users WHERE club_id = c.id) AS members_count,
            (SELECT COUNT(*) FROM events WHERE club_id = c.id) AS events_count,
            c.created_at,
            c.updated_at
        FROM clubs c
        LEFT JOIN users captain ON captain.id = c.captain_id
        LEFT JOIN users vice_captain ON vice_captain.id = c.vice_captain_id
        WHERE (:search_term IS NULL OR 
               c.name LIKE CONCAT('%', :search_term, '%') OR
               c.description LIKE CONCAT('%', :search_term, '%') OR
               c.category LIKE CONCAT('%', :search_term, '%') OR
               captain.first_name LIKE CONCAT('%', :search_term, '%') OR
               captain.last_name LIKE CONCAT('%', :search_term, '%'))
        ORDER BY c.name ASC
        LIMIT 20
        ";

            $stmt = $this->db->prepare($query);
            $searchParam = empty($searchTerm) ? null : '%' . $searchTerm . '%';
            $stmt->bindParam(':search_term', $searchParam);
            $stmt->execute();

            $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Форматируем результат
            $formattedClubs = array_map(function($club) {
                return [
                    'club_id' => (int)$club['club_id'],
                    'club_name' => $club['club_name'],
                    'club_status' => $club['club_status'],
                    'club_category' => $club['club_category'],
                    'club_description' => $club['club_description'],
                    'club_email' => $club['club_email'],
                    'club_phone' => $club['club_phone'],
                    'captain_name' => $club['captain_name'],
                    'captain_email' => $club['captain_email'],
                    'captain_phone' => $club['captain_phone'],
                    'vice_captain_name' => $club['vice_captain_name'],
                    'members_count' => (int)$club['members_count'],
                    'events_count' => (int)$club['events_count'],
                    'created_at' => $club['created_at'],
                    'updated_at' => $club['updated_at']
                ];
            }, $clubs);

            Response::success('Результаты поиска клубов', [
                'search_term' => $searchTerm,
                'clubs' => $formattedClubs,
                'total' => count($formattedClubs)
            ]);

        } catch (Exception $e) {
            Response::error('Ошибка при поиске клубов: ' . $e->getMessage(), [], 500);
        }
    }
}