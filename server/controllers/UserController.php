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
}
