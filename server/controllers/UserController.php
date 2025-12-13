<?php

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';

class UserController
{
    private $user;
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
        $this->user = new User($db);
    }

    // GET /api/users - список пользователей (только админ)
    public function getAllUsers() {
        $user = new User($this->db);

        // Получаем ВСЕХ пользователей
        $users = $user->getAll();
        $total = $user->getTotalCount();

        Response::success("Все пользователи получены", [
            'users' => $users,
            'count' => $total
        ]);
    }

    // GET /api/users/{id} - данные пользователя
    public function getUser($id)
    {
        try {
            // Аутентификация
            $payload = AuthMiddleware::authenticate();

            // Валидация ID
            if (!Validator::validateNumber($id)) {
                Response::error('Invalid user ID');
            }

            // Пользователь может получить свои данные, админ - любые
            if ($payload['role'] !== 'admin' && $payload['user_id'] != $id) {
                Response::forbidden('Access denied');
            }

            // Получаем данные пользователя
            if ($payload['role'] === 'admin') {
                $userData = $this->user->getByIdForAdmin($id);
            } else {
                $userData = $this->user->findById($id);
            }

            if (!$userData) {
                Response::error('User not found', [], 404);
            }

            Response::success('User retrieved successfully', $userData);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    // PUT /api/users/{id} - полное обновление
    public function updateUser($id) {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        if (!$data) {
            Response::error('Некорректные данные');
        }

        // Валидация для PUT - все обязательные поля
        $errors = [];

        if (!isset($data['first_name']) || empty(trim($data['first_name']))) {
            $errors['first_name'] = 'Имя обязательно для заполнения';
        }

        if (!isset($data['last_name']) || empty(trim($data['last_name']))) {
            $errors['last_name'] = 'Фамилия обязательна для заполнения';
        }

        if (!isset($data['role'])) {
            $errors['role'] = 'Роль обязательна для заполнения';
        } else {
            $allowedRoles = ['admin', 'club_owner', 'member'];
            if (!in_array($data['role'], $allowedRoles)) {
                $errors['role'] = 'Роль должна быть одной из: ' . implode(', ', $allowedRoles);
            }
        }

        // phone может быть не обязательным
        if (isset($data['phone']) && !empty($data['phone']) && !preg_match('/^\+?[0-9\s\-\(\)]{10,}$/', $data['phone'])) {
            $errors['phone'] = 'Введите корректный номер телефона';
        }

        if (isset($data['balance']) && (!is_numeric($data['balance']) || $data['balance'] < 0)) {
            $errors['balance'] = 'Баланс должен быть положительным числом';
        }

        if (!empty($errors)) {
            Response::error('Ошибки валидации', $errors, 422);
        }

        // Обновляем пользователя (полное обновление)
        $user = new User($this->db);
        $user->id = $id;
        $user->first_name = $data['first_name'];
        $user->last_name = $data['last_name'];
        $user->phone = $data['phone'] || null;
        $user->role = $data['role'];
        $user->is_active = $data['is_active'] || true;
        $user->balance = $data['balance'] || 0.00;
        $user->currency = $data['currency'] || 'USD';
        $user->club_id = $data['club_id'] || null;

        if ($user->update()) {
            Response::success('Пользователь обновлен (полное обновление)');
        } else {
            Response::error('Ошибка при обновлении пользователя');
        }
    }

// PATCH /api/users/{id} - частичное обновление
    public function patchUser($id) {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        if (!$data) {
            Response::error('Некорректные данные');
        }

        // Валидация для PATCH - только переданные поля
        $errors = [];

        if (isset($data['first_name']) && empty(trim($data['first_name']))) {
            $errors['first_name'] = 'Имя не может быть пустым';
        }

        if (isset($data['last_name']) && empty(trim($data['last_name']))) {
            $errors['last_name'] = 'Фамилия не может быть пустой';
        }

        if (isset($data['role'])) {
            $allowedRoles = ['admin', 'club_owner', 'member'];
            if (!in_array($data['role'], $allowedRoles)) {
                $errors['role'] = 'Роль должна быть одной из: ' . implode(', ', $allowedRoles);
            }
        }

        if (isset($data['phone']) && !empty($data['phone']) && !preg_match('/^\+?[0-9\s\-\(\)]{10,}$/', $data['phone'])) {
            $errors['phone'] = 'Введите корректный номер телефона';
        }

        if (isset($data['balance']) && (!is_numeric($data['balance']) || $data['balance'] < 0)) {
            $errors['balance'] = 'Баланс должен быть положительным числом';
        }

        if (!empty($errors)) {
            Response::error('Ошибки валидации', $errors, 422);
        }

        // Частичное обновление
        $user = new User($this->db);
        $user->id = $id;

        if ($user->partialUpdate($data)) {
            Response::success('Пользователь обновлен (частичное обновление)');
        } else {
            Response::error('Ошибка при обновлении пользователя');
        }
    }

    // DELETE /api/users/{id} - удаление (админ)
    public function deleteUser($id)
    {
        try {
            // Только админ может удалять
            $payload = AuthMiddleware::requireRole('admin');

            // Валидация ID
            if (!Validator::validateNumber($id)) {
                Response::error('Invalid user ID');
            }

            // Нельзя удалить самого себя
            if ($payload['user_id'] == $id) {
                Response::error('Cannot delete your own account');
            }

            // Проверяем существование пользователя
            $existingUser = $this->user->getByIdForAdmin($id);
            if (!$existingUser) {
                Response::error('User not found', [], 404);
            }

            // Мягкое удаление
            if ($this->user->delete($id)) {
                Response::success('User deleted successfully');
            } else {
                Response::error('Failed to delete user');
            }

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    // server/controllers/UserController.php

    public function getUsersDetailedReport($payload = null) {
        try {
            $isAdmin = ($payload && $payload['role'] === 'admin');

            // Запрос точно как в примере
            $query = "
            SELECT 
                CONCAT(u.first_name, ' ', u.last_name) AS user_full_name,
                u.email AS user_email,
                u.phone AS user_phone,
                COALESCE(c.name, 'Не состоит в клубе') AS club_name,
                COUNT(DISTINCT ep.event_id) AS events_registered_count,
                u.role AS user_role,
                CASE 
                    WHEN u.is_active = TRUE THEN 'Active'
                    ELSE 'Inactive'
                END AS user_status,
                u.balance AS user_balance,
                u.created_at AS user_created_at
            FROM users u
            LEFT JOIN clubs c ON u.club_id = c.id
            LEFT JOIN event_participants ep ON u.id = ep.user_id AND ep.status IN ('registered', 'attended')
            WHERE 1=1
        ";

            // Если не админ, показываем только активных пользователей
            if (!$isAdmin) {
                $query .= " AND u.is_active = TRUE";
            }

            $query .= "
            GROUP BY u.id, u.first_name, u.last_name, u.email, u.phone, c.name, u.role, u.is_active, u.balance, u.created_at
            ORDER BY u.created_at DESC, u.last_name, u.first_name
        ";

            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Форматируем результат (аналогично getClubsSummaryReport)
            $formattedResult = [];
            foreach ($result as $row) {
                $formattedResult[] = [
                    'user_full_name' => $row['user_full_name'],
                    'user_email' => $row['user_email'],
                    'user_phone' => $row['user_phone'],
                    'club_name' => $row['club_name'],
                    'events_registered_count' => (int)$row['events_registered_count'],
                    'user_role' => $row['user_role'],
                    'user_status' => $row['user_status'],
                    'user_balance' => (float)$row['user_balance'],
                    'user_created_at' => $row['user_created_at']
                ];
            }

            Response::success('Детальный отчет по пользователям получен', [
                'total_users' => count($formattedResult),
                'users' => $formattedResult,
                'view_all' => $isAdmin  // Информация о том, видит ли пользователь всех пользователей
            ]);

        } catch (Exception $e) {
            Response::error('Ошибка при получении отчета по пользователям: ' . $e->getMessage(), [], 500);
        }
    }

    public function searchMembers($searchTerm, $payload) {
        try {
            $query = "
        SELECT 
            CONCAT(u.first_name, ' ', u.last_name) AS user_full_name,
            u.email AS user_email,
            u.phone AS user_phone,
            COALESCE(c.name, 'Не состоит в клубе') AS club_name,
            COUNT(DISTINCT ep.event_id) AS events_registered_count,
            u.role AS user_role,
            CASE 
                WHEN u.is_active = TRUE THEN 'Active'
                ELSE 'Inactive'
            END AS user_status,
            u.balance AS user_balance,
            u.created_at AS user_created_at
        FROM users u
        LEFT JOIN clubs c ON u.club_id = c.id
        LEFT JOIN event_participants ep ON u.id = ep.user_id AND ep.status IN ('registered', 'attended')
        WHERE (:search_term IS NULL OR 
               CONCAT(u.first_name, ' ', u.last_name) LIKE CONCAT('%', :search_term, '%') OR
               u.first_name LIKE CONCAT('%', :search_term, '%') OR
               u.last_name LIKE CONCAT('%', :search_term, '%') OR
               u.email LIKE CONCAT('%', :search_term, '%'))
        GROUP BY u.id, u.first_name, u.last_name, u.email, u.phone, c.name, u.role, u.is_active, u.balance, u.created_at
        ORDER BY u.created_at DESC, u.last_name, u.first_name
        LIMIT 20
        ";

            $stmt = $this->db->prepare($query);
            $searchParam = empty($searchTerm) ? null : '%' . $searchTerm . '%';
            $stmt->bindParam(':search_term', $searchParam);
            $stmt->execute();

            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success('Результаты поиска участников', [
                'search_term' => $searchTerm,
                'users' => $users,
                'total' => count($users)
            ]);

        } catch (Exception $e) {
            Response::error('Ошибка при поиске участников: ' . $e->getMessage(), [], 500);
        }
    }
}
