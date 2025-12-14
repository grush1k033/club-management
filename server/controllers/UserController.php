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
    public function createUser($payload = null) {
        try {
            // Проверяем авторизацию
            if (!$payload || !isset($payload['user_id'])) {
                Response::error('Неавторизованный доступ', [], 401);
            }

            // Проверяем права (админ или владелец клуба)
            $currentUserId = $payload['user_id'];
            $currentUserRole = $payload['role'];

            // Если не админ и не владелец клуба
            if ($currentUserRole !== 'admin' && $currentUserRole !== 'club_owner') {
                Response::error('Недостаточно прав для создания пользователей', [], 403);
            }

            // Получаем данные из запроса
            $input = json_decode(file_get_contents('php://input'), true);

            // Валидация обязательных полей
            $requiredFields = ['email', 'password', 'first_name', 'last_name'];
            foreach ($requiredFields as $field) {
                if (empty($input[$field])) {
                    Response::error("Обязательное поле '$field' не заполнено", [], 400);
                }
            }

            // Проверка email
            if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
                Response::error('Некорректный email адрес', [], 400);
            }

            // Проверка уникальности email
            $checkEmailQuery = "SELECT id FROM users WHERE email = :email";
            $checkEmailStmt = $this->db->prepare($checkEmailQuery);
            $checkEmailStmt->execute(['email' => $input['email']]);

            if ($checkEmailStmt->fetch()) {
                Response::error('Пользователь с таким email уже существует', [], 400);
            }

            // Если указан club_id, проверяем существование клуба
            if (!empty($input['club_id'])) {
                $checkClubQuery = "SELECT id FROM clubs WHERE id = :club_id AND status = 'Active'";
                $checkClubStmt = $this->db->prepare($checkClubQuery);
                $checkClubStmt->execute(['club_id' => $input['club_id']]);

                if (!$checkClubStmt->fetch()) {
                    Response::error('Указанный клуб не существует или неактивен', [], 400);
                }

                // Проверяем, может ли текущий пользователь добавлять участников в этот клуб
                if ($currentUserRole === 'club_owner') {
                    // Для владельца клуба проверяем, что он является капитаном или вице-капитаном этого клуба
                    $checkClubAccessQuery = "
                    SELECT id FROM clubs 
                    WHERE id = :club_id 
                    AND (captain_id = :user_id OR vice_captain_id = :user_id)
                ";
                    $checkClubAccessStmt = $this->db->prepare($checkClubAccessQuery);
                    $checkClubAccessStmt->execute([
                        'club_id' => $input['club_id'],
                        'user_id' => $currentUserId
                    ]);

                    if (!$checkClubAccessStmt->fetch()) {
                        Response::error('У вас нет прав добавлять участников в этот клуб', [], 403);
                    }
                }
            }

            // Определяем роль пользователя
            $role = 'member'; // По умолчанию обычный участник

            // Админ может устанавливать любую роль
            if ($currentUserRole === 'admin' && !empty($input['role'])) {
                $allowedRoles = ['admin', 'club_owner', 'member'];
                if (in_array($input['role'], $allowedRoles)) {
                    $role = $input['role'];
                }
            }
            // Владелец клуба может создавать только участников
            else if ($currentUserRole === 'club_owner') {
                $role = 'member';
            }

            // Хэшируем пароль
            $hashedPassword = password_hash($input['password'], PASSWORD_DEFAULT);

            // Подготавливаем данные для вставки
            $userData = [
                'email' => $input['email'],
                'password' => $hashedPassword,
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'phone' => $input['phone'] || null,
                'role' => $role,
                'is_active' => isset($input['is_active']) ? (bool)$input['is_active'] : true,
                'balance' => $input['balance'] || 0.00,
                'currency' => $input['currency'] || 'USD',
                'club_id' => $input['club_id'] || null,
            ];

            // Создаем SQL запрос
            $fields = [];
            $placeholders = [];
            $values = [];

            foreach ($userData as $field => $value) {
                $fields[] = $field;
                $placeholders[] = ":$field";
                $values[":$field"] = $value;
            }

            $query = "INSERT INTO users (" . implode(', ', $fields) . ") 
                  VALUES (" . implode(', ', $placeholders) . ")";

            $stmt = $this->db->prepare($query);
            $stmt->execute($values);

            $newUserId = $this->db->lastInsertId();

            // Получаем созданного пользователя
            $getUserQuery = "
            SELECT 
                u.*,
                c.name as club_name
            FROM users u
            LEFT JOIN clubs c ON u.club_id = c.id
            WHERE u.id = :id
        ";

            $getUserStmt = $this->db->prepare($getUserQuery);
            $getUserStmt->execute(['id' => $newUserId]);
            $newUser = $getUserStmt->fetch(PDO::FETCH_ASSOC);

            // Скрываем пароль в ответе
            unset($newUser['password']);

            // Форматируем ответ
            $formattedUser = [
                'id' => (int)$newUser['id'],
                'email' => $newUser['email'],
                'first_name' => $newUser['first_name'],
                'last_name' => $newUser['last_name'],
                'full_name' => $newUser['first_name'] . ' ' . $newUser['last_name'],
                'phone' => $newUser['phone'],
                'role' => $newUser['role'],
                'is_active' => (bool)$newUser['is_active'],
                'balance' => (float)$newUser['balance'],
                'currency' => $newUser['currency'],
                'club_id' => $newUser['club_id'] ? (int)$newUser['club_id'] : null,
                'club_name' => $newUser['club_name'],
                'created_at' => $newUser['created_at'],
                'updated_at' => $newUser['updated_at']
            ];

            Response::success('Пользователь успешно создан', [
                'user' => $formattedUser
            ], 201);

        } catch (Exception $e) {
            Response::error('Ошибка при создании пользователя: ' . $e->getMessage(), [], 500);
        }
    }
    public function getUsersDetailedReport($payload = null) {
        try {
            $isAdmin = ($payload && $payload['role'] === 'admin');

            // Запрос точно как в примере
            $query = "
        SELECT 
            u.id AS user_id,
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

            // Форматируем результат
            $formattedResult = [];
            foreach ($result as $row) {
                $formattedResult[] = [
                    'user_id' => (int)$row['user_id'],
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
    public function updateUser($userId, $payload = null) {
        try {
            // Проверяем авторизацию
            if (!$payload || !isset($payload['user_id'])) {
                Response::error('Неавторизованный доступ', [], 401);
            }

            $currentUserId = $payload['user_id'];
            $currentUserRole = $payload['role'];

            // Получаем данные из запроса
            $input = json_decode(file_get_contents('php://input'), true);

            if (empty($input)) {
                Response::error('Нет данных для обновления', [], 400);
            }

            // Получаем информацию о пользователе, которого обновляем
            $getUserQuery = "
        SELECT u.*, c.captain_id, c.vice_captain_id 
        FROM users u
        LEFT JOIN clubs c ON u.club_id = c.id
        WHERE u.id = :user_id
    ";

            $getUserStmt = $this->db->prepare($getUserQuery);
            $getUserStmt->execute(['user_id' => $userId]);
            $userToUpdate = $getUserStmt->fetch(PDO::FETCH_ASSOC);

            if (!$userToUpdate) {
                Response::error('Пользователь не найден', [], 404);
            }

            // Проверяем права доступа
            $canUpdate = false;

            // 1. Админ может обновлять любого пользователя
            if ($currentUserRole === 'admin') {
                $canUpdate = true;
            }
            // 2. Пользователь может обновлять свои данные
            else if ($currentUserId == $userId) {
                $canUpdate = true;
            }
            // 3. Владелец клуба может обновлять участников своего клуба
            else if ($currentUserRole === 'club_owner') {
                // Проверяем, что текущий пользователь - капитан или вице-капитан клуба
                if ($userToUpdate['club_id']) {
                    $checkClubAccessQuery = "
                SELECT id FROM clubs 
                WHERE id = :club_id 
                AND (captain_id = :current_user_id OR vice_captain_id = :current_user_id)
            ";
                    $checkClubAccessStmt = $this->db->prepare($checkClubAccessQuery);
                    $checkClubAccessStmt->execute([
                        'club_id' => $userToUpdate['club_id'],
                        'current_user_id' => $currentUserId
                    ]);

                    if ($checkClubAccessStmt->fetch()) {
                        $canUpdate = true;
                    }
                }
            }

            if (!$canUpdate) {
                Response::error('Недостаточно прав для обновления этого пользователя', [], 403);
            }

            // Определяем доступные поля в зависимости от того, кто обновляет

            // Поля, которые может обновлять сам пользователь для себя
            // ВСЕ поля кроме balance, role (исключение - только admin может менять role)
            $selfUpdateFields = ['email', 'first_name', 'last_name', 'phone', 'password'];

            // Поля, которые может обновлять владелец клуба для участников своего клуба
            $clubOwnerFields = ['first_name', 'last_name', 'phone', 'is_active'];

            // Поля, которые может обновлять только админ для всех
            $adminFields = ['email', 'role', 'balance', 'currency', 'is_active', 'club_id', 'first_name', 'last_name', 'phone'];

            // Определяем доступные поля
            $allowedFields = [];

            if ($currentUserRole === 'admin') {
                // Админ может обновлять все поля
                $allowedFields = $adminFields;
            } else if ($currentUserRole === 'club_owner' && $currentUserId != $userId) {
                // Капитан клуба обновляет участников своего клуба
                $allowedFields = $clubOwnerFields;
            } else if ($currentUserId == $userId) {
                // Пользователь обновляет себя самого - может все кроме balance
                $allowedFields = $selfUpdateFields;

                // Сам пользователь НЕ может менять себе:
                // 1. role (только админ)
                // 2. balance (только админ)
                // 3. club_id (это делается через вступление/выход из клуба)
                // 4. is_active (только админ или капитан клуба)
                // 5. currency (только админ)

                // Удаляем недопустимые поля, если они пришли в запросе
                $invalidSelfFields = ['role', 'balance', 'club_id', 'is_active', 'currency'];
                foreach ($invalidSelfFields as $field) {
                    if (isset($input[$field])) {
                        unset($input[$field]); // Игнорируем эти поля при самообновлении
                    }
                }
            }

            // Фильтруем входные данные, оставляя только разрешенные поля
            $updateData = [];
            foreach ($input as $field => $value) {
                if (in_array($field, $allowedFields)) {
                    // Особые проверки для некоторых полей
                    if ($field === 'email') {
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            Response::error('Некорректный email адрес', [], 400);
                        }

                        // Проверяем уникальность email
                        $checkEmailQuery = "SELECT id FROM users WHERE email = :email AND id != :user_id";
                        $checkEmailStmt = $this->db->prepare($checkEmailQuery);
                        $checkEmailStmt->execute([
                            'email' => $value,
                            'user_id' => $userId
                        ]);

                        if ($checkEmailStmt->fetch()) {
                            Response::error('Пользователь с таким email уже существует', [], 400);
                        }
                    }

                    $updateData[$field] = $value;
                }
            }

            // Обработка пароля отдельно
            if (isset($input['password']) && !empty($input['password'])) {
                if (in_array('password', $allowedFields)) {
                    $hashedPassword = password_hash($input['password'], PASSWORD_DEFAULT);
                    $updateData['password'] = $hashedPassword;
                }
            }

            // Проверка club_id (если обновляется админом)
            if (isset($updateData['club_id'])) {
                if ($updateData['club_id'] !== null) {
                    $checkClubQuery = "SELECT id FROM clubs WHERE id = :club_id AND status = 'Active'";
                    $checkClubStmt = $this->db->prepare($checkClubQuery);
                    $checkClubStmt->execute(['club_id' => $updateData['club_id']]);

                    if (!$checkClubStmt->fetch()) {
                        Response::error('Указанный клуб не существует или неактивен', [], 400);
                    }
                }
            }

            // Если нечего обновлять
            if (empty($updateData)) {
                Response::success('Нет данных для обновления', [
                    'user' => $this->getFormattedUser($userId)
                ]);
            }

            // Строим SQL запрос
            $setParts = [];
            $values = [':user_id' => $userId];

            foreach ($updateData as $field => $value) {
                $setParts[] = "$field = :$field";
                $values[":$field"] = $value;
            }

            $setParts[] = "updated_at = CURRENT_TIMESTAMP";

            $query = "UPDATE users SET " . implode(', ', $setParts) . " WHERE id = :user_id";

            $stmt = $this->db->prepare($query);
            $stmt->execute($values);

            // Получаем обновленного пользователя
            $getUpdatedUserQuery = "
        SELECT 
            u.*,
            c.name as club_name
        FROM users u
        LEFT JOIN clubs c ON u.club_id = c.id
        WHERE u.id = :id
    ";

            $getUpdatedUserStmt = $this->db->prepare($getUpdatedUserQuery);
            $getUpdatedUserStmt->execute(['id' => $userId]);
            $updatedUser = $getUpdatedUserStmt->fetch(PDO::FETCH_ASSOC);

            // Скрываем пароль в ответе
            unset($updatedUser['password']);

            // Форматируем ответ
            $formattedUser = [
                'id' => (int)$updatedUser['id'],
                'email' => $updatedUser['email'],
                'first_name' => $updatedUser['first_name'],
                'last_name' => $updatedUser['last_name'],
                'full_name' => $updatedUser['first_name'] . ' ' . $updatedUser['last_name'],
                'phone' => $updatedUser['phone'],
                'role' => $updatedUser['role'],
                'is_active' => (bool)$updatedUser['is_active'],
                'balance' => (float)$updatedUser['balance'],
                'currency' => $updatedUser['currency'],
                'club_id' => $updatedUser['club_id'] ? (int)$updatedUser['club_id'] : null,
                'club_name' => $updatedUser['club_name'],
                'created_at' => $updatedUser['created_at'],
                'updated_at' => $updatedUser['updated_at']
            ];

            Response::success('Пользователь успешно обновлен', [
                'user' => $formattedUser,
                'updated_fields' => array_keys($updateData)
            ]);

        } catch (Exception $e) {
            Response::error('Ошибка при обновлении пользователя: ' . $e->getMessage(), [], 500);
        }
    }
    public function deleteUser($userId)
    {
        try {
            $user = AuthMiddleware::authenticate();

            if ($user['role'] !== 'admin') {
                Response::error('Недостаточно прав для удаления пользователя', null, 403);
            }

            // Начинаем транзакцию для безопасности
            $this->db->beginTransaction();

            try {
                // Можно явно обновить SET NULL связи для ясности
                $queries = [
                    "UPDATE clubs SET captain_id = NULL WHERE captain_id = ?",
                    "UPDATE clubs SET vice_captain_id = NULL WHERE vice_captain_id = ?",
                    "UPDATE events SET created_by = NULL WHERE created_by = ?",
                    "UPDATE club_join_requests SET processed_by = NULL WHERE processed_by = ?"
                ];

                foreach ($queries as $query) {
                    $stmt = $this->db->prepare($query);
                    $stmt->execute([$userId]);
                }

                // Удаляем пользователя (CASCADE сделает остальное)
                $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);

                if ($stmt->rowCount() === 0) {
                    throw new Exception('Пользователь не найден');
                }

                $this->db->commit();

                Response::success('Пользователь успешно удален', [
                    'user_id' => (int)$userId,
                    'deleted' => true
                ]);

            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage(), null, 500);
        }
    }
    public function deleteMultipleUsers()
    {
        try {
            // Аутентификация
            $user = AuthMiddleware::authenticate();

            // Только админ может удалять
            if ($user['role'] !== 'admin') {
                Response::error('Недостаточно прав для удаления пользователей', null, 403);
            }

            // Получаем данные из тела запроса
            $input = file_get_contents('php://input');
            if (empty($input)) {
                Response::error('Тело запроса пустое', null, 400);
            }

            $data = json_decode($input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Response::error('Неверный JSON формат', null, 400);
            }

            // Проверяем наличие массива ID пользователей
            if (!isset($data['user_ids']) || !is_array($data['user_ids']) || empty($data['user_ids'])) {
                Response::error('Необходим массив user_ids с ID пользователей для удаления', null, 400);
            }

            // Фильтруем и валидируем ID
            $userIds = array_map('intval', $data['user_ids']);
            $userIds = array_filter($userIds, function($id) {
                return $id > 0;
            });

            if (empty($userIds)) {
                Response::error('Некорректные ID пользователей', null, 400);
            }

            // Проверяем, не пытается ли админ удалить себя
            if (in_array($user['id'], $userIds)) {
                Response::error('Вы не можете удалить свой собственный аккаунт', null, 400);
            }

            // Подготавливаем строку с плейсхолдерами для IN()
            $placeholders = str_repeat('?,', count($userIds) - 1) . '?';

            // Начинаем транзакцию
            $this->db->beginTransaction();

            try {
                // 1. Обновляем клубы, где пользователи были капитанами/вице-капитанами
                $updateCaptainQuery = "UPDATE clubs SET captain_id = NULL WHERE captain_id IN ($placeholders)";
                $stmt = $this->db->prepare($updateCaptainQuery);
                $stmt->execute($userIds);
                $updatedCaptainClubs = $stmt->rowCount();

                $updateViceQuery = "UPDATE clubs SET vice_captain_id = NULL WHERE vice_captain_id IN ($placeholders)";
                $stmt = $this->db->prepare($updateViceQuery);
                $stmt->execute($userIds);
                $updatedViceClubs = $stmt->rowCount();

                // 2. Обновляем события, созданные пользователями
                $updateEventsQuery = "UPDATE events SET created_by = NULL WHERE created_by IN ($placeholders)";
                $stmt = $this->db->prepare($updateEventsQuery);
                $stmt->execute($userIds);
                $updatedEvents = $stmt->rowCount();

                // 3. Обновляем обработанные заявки
                $updateRequestsQuery = "UPDATE club_join_requests SET processed_by = NULL WHERE processed_by IN ($placeholders)";
                $stmt = $this->db->prepare($updateRequestsQuery);
                $stmt->execute($userIds);
                $updatedProcessedRequests = $stmt->rowCount();

                // 4. Получаем информацию о пользователях перед удалением
                $selectQuery = "SELECT id, email, first_name, last_name FROM users WHERE id IN ($placeholders)";
                $stmt = $this->db->prepare($selectQuery);
                $stmt->execute($userIds);
                $usersToDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // 5. Удаляем пользователей
                $deleteQuery = "DELETE FROM users WHERE id IN ($placeholders)";
                $stmt = $this->db->prepare($deleteQuery);
                $stmt->execute($userIds);
                $deletedCount = $stmt->rowCount();

                // Фиксируем транзакцию
                $this->db->commit();

                Response::success('Пользователи успешно удалены', [
                    'deleted_count' => $deletedCount,
                    'total_requested' => count($userIds),
                    'deleted_users' => array_map(function($user) {
                        return [
                            'id' => (int)$user['id'],
                            'email' => $user['email'],
                            'name' => $user['first_name'] . ' ' . $user['last_name']
                        ];
                    }, $usersToDelete),
                    'cleanup_statistics' => [
                        'clubs_as_captain_updated' => $updatedCaptainClubs,
                        'clubs_as_vice_captain_updated' => $updatedViceClubs,
                        'events_as_creator_updated' => $updatedEvents,
                        'processed_requests_updated' => $updatedProcessedRequests
                    ]
                ]);

            } catch (Exception $e) {
                // Откатываем транзакцию
                $this->db->rollBack();
                throw $e;
            }

        } catch (PDOException $e) {
            Response::error('Ошибка базы данных: ' . $e->getMessage(), null, 500);
        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage(), null, 500);
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
