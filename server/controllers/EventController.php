<?php
require_once __DIR__ . '/../models/Event.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/ClubMiddleware.php';

class EventController {
    private $db;
    private $eventModel;

    public function __construct($db) {
        $this->db = $db;
        $this->eventModel = new Event($db);
    }

    // === ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ ===

    /**
     * Получить данные из запроса (JSON или POST)
     */
    /**
     * Получить данные из запроса (JSON или POST)
     */
    /**
     * Получить данные из запроса (JSON или POST)
     */
    private function getRequestData() {
        // Получаем сырые данные
        $rawInput = file_get_contents('php://input');

        // ОТЛАДКА
        error_log("Raw input: " . $rawInput);

        // Пробуем декодировать JSON
        $jsonData = json_decode($rawInput, true);

        if (json_last_error() === JSON_ERROR_NONE && $jsonData !== null) {
            error_log("JSON decoded successfully");
            return $jsonData;
        }

        error_log("JSON decode error: " . json_last_error_msg());

        // Если не JSON, пробуем $_POST (для form-data)
        if (!empty($_POST)) {
            error_log("Using POST data");
            return $_POST;
        }

        // Если ничего нет, возвращаем пустой массив
        error_log("No data found");
        return [];
    }

    /**
     * Проверить права на мероприятие (капитан/заместитель или админ)
     */
    private function checkEventPermissions($event, $user) {
        // Админ имеет все права
        if ($user['role'] === 'admin') {
            return true;
        }

        // Проверяем, является ли пользователь капитаном/заместителем клуба
        $clubMiddleware = new ClubMiddleware($this->db);
        $clubRequest = [
            'user' => $user,
            'params' => ['id' => $event['club_id']]
        ];

        try {
            $clubMiddleware->handle($clubRequest, ['owner' => true]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // === ОСНОВНЫЕ МЕТОДЫ ===

    /**
     * Создать новое мероприятие
     * POST /api/events
     */
    /**
     * Создать новое мероприятие
     * POST /api/events
     */
    public function create()
    {
        try {
            // Аутентифицируем пользователя и получаем его данные
            $user = AuthMiddleware::authenticate();

            // Проверяем права доступа - только admin и club_owner могут создавать события
            if (!in_array($user['role'], ['admin', 'club_owner'])) {
                Response::error('Недостаточно прав для создания события', null, 403);
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

            // Валидация обязательных полей
            $requiredFields = ['title', 'event_date', 'club_id'];
            $missingFields = [];

            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                    $missingFields[] = $field;
                }
            }

            if (!empty($missingFields)) {
                Response::error('Отсутствуют обязательные поля: ' . implode(', ', $missingFields), null, 400);
            }

            // Проверяем, что пользователь имеет доступ к клубу (если не админ)
            if ($user['role'] === 'club_owner') {
                // Для club_owner проверяем, что он является капитаном или вице-капитаном клуба
                $checkQuery = "SELECT * FROM clubs WHERE id = ? AND (captain_id = ? OR vice_captain_id = ?)";
                $stmt = $this->db->prepare($checkQuery);
                $stmt->execute([$data['club_id'], $user['id'], $user['id']]);

                if ($stmt->rowCount() === 0) {
                    Response::error('Вы не можете создавать события для этого клуба', null, 403);
                }
            }

            // Проверяем, что клуб существует и активен
            $clubCheckQuery = "SELECT * FROM clubs WHERE id = ? AND status = 'Active'";
            $stmt = $this->db->prepare($clubCheckQuery);
            $stmt->execute([$data['club_id']]);

            if ($stmt->rowCount() === 0) {
                Response::error('Клуб не найден или неактивен', null, 404);
            }

            // Валидация даты
            $eventDateTime = strtotime($data['event_date']);
            if ($eventDateTime === false) {
                Response::error('Неверный формат даты', null, 400);
            }

            // Проверяем, что дата в будущем (или можно разрешить прошедшие даты для тестирования)
            // if ($eventDateTime < time()) {
            //     Response::error('Дата события должна быть в будущем', null, 400);
            // }

            // Подготавливаем данные для вставки
            $eventData = [
                'club_id' => (int)$data['club_id'],
                'title' => trim($data['title']),
                'description' => isset($data['description']) ? trim($data['description']) : null,
                'event_date' => date('Y-m-d H:i:s', $eventDateTime),
                'location' => isset($data['location']) ? trim($data['location']) : null,
                'max_participants' => isset($data['max_participants']) ? (int)$data['max_participants'] : null,
                'external_fee_amount' => isset($data['external_fee_amount']) ? (float)$data['external_fee_amount'] : 0.00,
                'external_fee_currency' => isset($data['external_fee_currency']) ? trim($data['external_fee_currency']) : 'USD',
                'is_free_for_members' => isset($data['is_free_for_members']) ? (int)$data['is_free_for_members'] : 1,
                'status' => 'scheduled',
                'created_by' => $user['user_id'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // Начинаем транзакцию
            $this->db->beginTransaction();

            // SQL запрос для создания события
            $query = "INSERT INTO events 
                  (club_id, title, description, event_date, location, max_participants, 
                   external_fee_amount, external_fee_currency, is_free_for_members, 
                   status, created_by, created_at, updated_at)
                  VALUES 
                  (:club_id, :title, :description, :event_date, :location, :max_participants,
                   :external_fee_amount, :external_fee_currency, :is_free_for_members,
                   :status, :created_by, :created_at, :updated_at)";

            $stmt = $this->db->prepare($query);
            $stmt->execute($eventData);

            // Получаем ID созданного события
            $eventId = $this->db->lastInsertId();

            // Получаем полные данные созданного события
            $selectQuery = "SELECT 
                e.id,
                e.title AS event_name,
                c.name AS club_name,
                e.event_date AS event_datetime,
                e.max_participants,
                e.status AS event_status,
                e.external_fee_amount AS ticket_price,
                e.external_fee_currency AS currency,
                e.description,
                e.location,
                e.is_free_for_members,
                e.created_at,
                e.created_by,
                CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
            FROM events e
            JOIN clubs c ON e.club_id = c.id
            LEFT JOIN users u ON e.created_by = u.id
            WHERE e.id = ?";

            $stmt = $this->db->prepare($selectQuery);
            $stmt->execute([$eventId]);
            $createdEvent = $stmt->fetch(PDO::FETCH_ASSOC);

            // Фиксируем транзакцию
            $this->db->commit();

            Response::success('Событие успешно создано', $createdEvent, 201);
        } catch (PDOException $e) {
            // Откатываем транзакцию в случае ошибки
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            Response::error('Ошибка при создании события: ' . $e->getMessage(), null, 500);
        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Получить все мероприятия клуба
     * GET /api/clubs/{id}/events
     */
    public function getClubEvents($clubId) {
        try {
            AuthMiddleware::authenticate();

            if (!is_numeric($clubId)) {
                Response::error('Некорректный ID клуба');
            }

            $events = $this->eventModel->getClubEvents($clubId);
            Response::success('Список мероприятий', $events);

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Получить одно мероприятие
     * GET /api/events/{id}
     */
    public function getEvent($eventId) {
        try {
            AuthMiddleware::authenticate();

            if (!is_numeric($eventId)) {
                Response::error('Некорректный ID мероприятия');
            }

            $event = $this->eventModel->getById($eventId);

            if (!$event) {
                Response::notFound('Мероприятие не найдено');
            }

            Response::success('Данные мероприятия', $event);

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Зарегистрироваться на мероприятие
     * POST /api/events/{id}/register
     */
    public function register($eventId) {
        try {
            $user = AuthMiddleware::authenticate();

            if (!is_numeric($eventId)) {
                Response::error('Некорректный ID мероприятия');
            }

            // Получаем правильный user_id из JWT payload
            $userId = null;
            if (isset($user['user_id']) && !empty($user['user_id'])) {
                $userId = $user['user_id'];
            } elseif (isset($user['id']) && !empty($user['id'])) {
                $userId = $user['id'];
            }

            if (!$userId) {
                Response::error('Не удалось определить ID пользователя', null, 400);
            }

            // Проверяем существует ли мероприятие
            $event = $this->eventModel->getById($eventId);
            if (!$event) {
                Response::notFound('Мероприятие не найдено');
            }

            // Проверяем максимальное количество участников
            $query = "SELECT COUNT(*) as count FROM event_participants 
                  WHERE event_id = :event_id AND status != 'cancelled'";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':event_id', $eventId);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($event['max_participants'] && $result['count'] >= $event['max_participants']) {
                Response::error('Достигнуто максимальное количество участников');
            }

            // Проверяем, не зарегистрирован ли уже пользователь
            if ($this->eventModel->isUserParticipant($eventId, $userId)) {
                Response::error('Вы уже зарегистрированы на это мероприятие');
            }

            // Регистрируем пользователя
            $query = "INSERT INTO event_participants (event_id, user_id, status) 
                  VALUES (:event_id, :user_id, 'registered')";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':event_id', $eventId);
            $stmt->bindParam(':user_id', $userId);

            if ($stmt->execute()) {
                Response::success('Вы успешно зарегистрированы на мероприятие');
            }

            Response::error('Ошибка регистрации');

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Отменить регистрацию на мероприятие
     * POST /api/events/{id}/cancel
     */
    public function cancelRegistration($eventId) {
        try {
            $user = AuthMiddleware::authenticate();

            if (!is_numeric($eventId)) {
                Response::error('Некорректный ID мероприятия');
            }

            $userId = $user['user_id'] || $user['id'] || null;
            if (!$userId) {
                Response::error('Не удалось определить ID пользователя', null, 400);
            }

            // Отменяем регистрацию
            $query = "UPDATE event_participants 
                  SET status = 'cancelled', 
                      attended_at = NULL 
                  WHERE event_id = :event_id 
                  AND user_id = :user_id 
                  AND status = 'registered'";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':event_id', $eventId);
            $stmt->bindParam(':user_id', $userId);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                Response::success('Регистрация на мероприятие отменена');
            }

            Response::error('Регистрация на мероприятие не найдена');

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Получить мероприятия пользователя
     * GET /api/user/events
     */
    public function getUserEvents() {
        try {
            $user = AuthMiddleware::authenticate();

            $userId = $user['user_id'] || $user['id'] || null;
            if (!$userId) {
                Response::error('Не удалось определить ID пользователя', null, 400);
            }

            $events = $this->eventModel->getUserEvents($userId);
            Response::success('Ваши мероприятия', $events);

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Получить предстоящие мероприятия пользователя
     * GET /api/user/events/upcoming
     */
    public function getUpcomingEvents() {
        try {
            $user = AuthMiddleware::authenticate();

            $userId = $user['user_id'] || $user['id'] || null;
            if (!$userId) {
                Response::error('Не удалось определить ID пользователя', null, 400);
            }

            $query = "SELECT e.*, c.name as club_name, ep.status as participation_status
                  FROM events e
                  JOIN event_participants ep ON e.id = ep.event_id
                  JOIN clubs c ON e.club_id = c.id
                  WHERE ep.user_id = :user_id 
                  AND ep.status = 'registered'
                  AND e.status IN ('scheduled', 'ongoing')
                  AND e.event_date >= NOW()
                  ORDER BY e.event_date ASC";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->execute();

            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
            Response::success('Предстоящие мероприятия', $events);

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Получить участников мероприятия
     * GET /api/events/{id}/participants
     */
    public function getEventParticipants($eventId) {
        try {
            $user = AuthMiddleware::authenticate();

            if (!is_numeric($eventId)) {
                Response::error('Некорректный ID мероприятия');
            }

            $event = $this->eventModel->getById($eventId);
            if (!$event) {
                Response::notFound('Мероприятие не найдено');
            }

            // Проверяем права
            if (!$this->checkEventPermissions($event, $user)) {
                Response::forbidden('Недостаточно прав для просмотра участников');
            }

            // Получаем участников
            $query = "SELECT ep.*, u.first_name, u.last_name, u.email, u.phone 
                      FROM event_participants ep
                      JOIN users u ON ep.user_id = u.id
                      WHERE ep.event_id = :event_id
                      ORDER BY ep.registration_date DESC";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':event_id', $eventId);
            $stmt->execute();

            $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            Response::success('Участники мероприятия', $participants);

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Обновить мероприятие
     * PUT /api/events/{id}
     */
    public function update($eventId) {
        try {
            $user = AuthMiddleware::authenticate();

            if (!is_numeric($eventId)) {
                Response::error('Некорректный ID мероприятия');
            }

            $data = $this->getRequestData();

            $event = $this->eventModel->getById($eventId);
            if (!$event) {
                Response::notFound('Мероприятие не найдено');
            }

            // Проверяем права
            if (!$this->checkEventPermissions($event, $user)) {
                Response::forbidden('Только капитан или заместитель клуба могут обновлять мероприятия');
            }

            // Валидация
            $rules = [
                'title' => 'string|min:3|max:255',
                'description' => 'string|max:2000',
                'event_date' => 'string',
                'location' => 'string|max:255',
                'status' => 'in:scheduled,ongoing,completed,cancelled',
                'max_participants' => 'numeric'
            ];

            if ($errors = Validator::validate($data, $rules)) {
                Response::error('Ошибка валидации', $errors, 422);
            }

            // Обновляем мероприятие
            if ($this->eventModel->update($eventId, $data)) {
                Response::success('Мероприятие обновлено');
            }

            Response::error('Ошибка при обновлении мероприятия');

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Удалить мероприятие
     * DELETE /api/events/{id}
     */
    public function delete($eventId) {
        try {
            $user = AuthMiddleware::authenticate();

            if (!is_numeric($eventId)) {
                Response::error('Некорректный ID мероприятия');
            }

            $event = $this->eventModel->getById($eventId);
            if (!$event) {
                Response::notFound('Мероприятие не найдено');
            }

            // Проверяем права
            if (!$this->checkEventPermissions($event, $user)) {
                Response::forbidden('Только капитан или заместитель клуба могут удалять мероприятия');
            }

            // Удаляем мероприятие
            if ($this->eventModel->delete($eventId)) {
                Response::success('Мероприятие удалено');
            }

            Response::error('Ошибка при удалении мероприятия');

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Обновить статус участника
     * PUT /api/events/{id}/participants/{userId}
     */
    public function updateParticipantStatus($eventId, $userId) {
        try {
            $user = AuthMiddleware::authenticate();

            if (!is_numeric($eventId) || !is_numeric($userId)) {
                Response::error('Некорректные ID');
            }

            $event = $this->eventModel->getById($eventId);
            if (!$event) {
                Response::notFound('Мероприятие не найдено');
            }

            // Проверяем права
            if (!$this->checkEventPermissions($event, $user)) {
                Response::forbidden('Только капитан или заместитель клуба могут обновлять статус участников');
            }

            $data = $this->getRequestData();

            // Валидация
            $rules = [
                'status' => 'required|in:registered,attended,cancelled,no_show'
            ];

            if ($errors = Validator::validate($data, $rules)) {
                Response::error('Ошибка валидации', $errors, 422);
            }

            // Обновляем статус участника
            $query = "UPDATE event_participants 
                      SET status = :status, 
                          attended_at = CASE 
                              WHEN :status = 'attended' THEN CURRENT_TIMESTAMP 
                              ELSE NULL 
                          END
                      WHERE event_id = :event_id AND user_id = :user_id";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':status', $data['status']);
            $stmt->bindParam(':event_id', $eventId);
            $stmt->bindParam(':user_id', $userId);

            if ($stmt->execute()) {
                Response::success('Статус участника обновлен');
            }

            Response::error('Ошибка обновления статуса участника');

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Получить прошедшие мероприятия пользователя
     * GET /api/user/events/past
     */
    public function getPastEvents() {
        try {
            $user = AuthMiddleware::authenticate();

            $userId = $user['user_id'] || $user['id'] || null;
            if (!$userId) {
                Response::error('Не удалось определить ID пользователя', null, 400);
            }

            $query = "SELECT e.*, c.name as club_name, ep.status as participation_status
                  FROM events e
                  JOIN event_participants ep ON e.id = ep.event_id
                  JOIN clubs c ON e.club_id = c.id
                  WHERE ep.user_id = :user_id 
                  AND e.status = 'completed'
                  ORDER BY e.event_date DESC";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->execute();

            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
            Response::success('Прошедшие мероприятия', $events);

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Изменить статус мероприятия
     * PUT /api/events/{id}/toggle-status
     */
    public function toggleEventStatus($eventId) {
        try {
            $user = AuthMiddleware::authenticate();

            if (!is_numeric($eventId)) {
                Response::error('Некорректный ID мероприятия');
            }

            $event = $this->eventModel->getById($eventId);
            if (!$event) {
                Response::notFound('Мероприятие не найдено');
            }

            // Проверяем права
            if (!$this->checkEventPermissions($event, $user)) {
                Response::forbidden('Только капитан или заместитель клуба могут менять статус мероприятий');
            }

            $data = $this->getRequestData();

            // Валидация
            $rules = [
                'status' => 'required|in:scheduled,ongoing,completed,cancelled'
            ];

            if ($errors = Validator::validate($data, $rules)) {
                Response::error('Ошибка валидации', $errors, 422);
            }

            // Обновляем статус мероприятия
            if ($this->eventModel->updateStatus($eventId, $data['status'])) {
                Response::success('Статус мероприятия изменен');
            }

            Response::error('Ошибка изменения статуса мероприятия');

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Получить предстоящие мероприятия клуба
     * GET /api/clubs/{id}/events/upcoming
     */
    public function getUpcomingClubEvents($clubId) {
        try {
            AuthMiddleware::authenticate();

            if (!is_numeric($clubId)) {
                Response::error('Некорректный ID клуба');
            }

            $query = "SELECT e.*, 
                      COUNT(ep.id) as participants_count,
                      (SELECT COUNT(*) FROM event_participants 
                       WHERE event_id = e.id AND status = 'attended') as attended_count
                      FROM events e
                      LEFT JOIN event_participants ep ON e.id = ep.event_id
                      WHERE e.club_id = :club_id 
                      AND e.status IN ('scheduled', 'ongoing')
                      AND e.event_date >= NOW()
                      GROUP BY e.id
                      ORDER BY e.event_date ASC";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':club_id', $clubId);
            $stmt->execute();

            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
            Response::success('Предстоящие мероприятия клуба', $events);

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage());
        }
    }

    public function createSimple() {
        try {
            // Получаем сырые данные
            $raw = file_get_contents('php://input');
            error_log("RAW INPUT: " . $raw);

            // Декодируем JSON
            $data = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Response::error('Некорректный JSON: ' . json_last_error_msg(), null, 400);
            }

            if (!$data) {
                Response::error('Пустой запрос', null, 400);
            }

            error_log("DECODED DATA: " . print_r($data, true));

            // Проверяем обязательные поля
            $required = ['club_id', 'title', 'event_date'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    Response::error("Отсутствует обязательное поле: $field", null, 422);
                }
            }

            // Проверяем формат даты
            $event_date = $data['event_date'];
            if (!strtotime($event_date)) {
                Response::error("Некорректный формат даты: $event_date", null, 422);
            }

            // Форматируем дату для MySQL
            $formatted_date = date('Y-m-d H:i:s', strtotime($event_date));
            error_log("Formatted date: $formatted_date");

            // Создаем мероприятие
            $query = "INSERT INTO events (club_id, title, description, event_date, location, status, max_participants, created_by) 
                  VALUES (:club_id, :title, :description, :event_date, :location, :status, :max_participants, :created_by)";

            $stmt = $this->db->prepare($query);

            // Подготавливаем значения
            $club_id = (int)$data['club_id'];
            $title = trim($data['title']);
            $description = trim($data['description'] || '');
            $location = trim($data['location'] || '');
            $max_participants = !empty($data['max_participants']) ? (int)$data['max_participants'] : null;

            // Привязываем параметры
            $stmt->bindParam(':club_id', $club_id, PDO::PARAM_INT);
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':event_date', $formatted_date);
            $stmt->bindParam(':location', $location);
            $stmt->bindValue(':status', 'scheduled');

            if ($max_participants !== null) {
                $stmt->bindParam(':max_participants', $max_participants, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':max_participants', null, PDO::PARAM_NULL);
            }

            // Временно статический ID пользователя
            $created_by = 1;
            $stmt->bindParam(':created_by', $created_by, PDO::PARAM_INT);

            if ($stmt->execute()) {
                $lastId = $this->db->lastInsertId();
                Response::success('Мероприятие успешно создано', ['id' => $lastId]);
            } else {
                $errorInfo = $stmt->errorInfo();
                Response::error('Ошибка базы данных: ' . $errorInfo[2], null, 500);
            }

        } catch (Exception $e) {
            error_log("Exception: " . $e->getMessage());
            Response::error('Ошибка: ' . $e->getMessage());
        }
    }

// В EventController добавьте эти методы:

// Получить мероприятия конкретного пользователя (для админа)
    public function getUserEventsAdmin($userId) {
        // Проверяем существование пользователя
        $userModel = new User($this->db);
        $user = $userModel->getByIdForAdmin($userId);

        if (!$user) {
            Response::error('Пользователь не найден', [], 404);
        }

        // Получаем мероприятия пользователя
        $eventModel = new Event($this->db);
        $events = $eventModel->getEventsByUserId($userId);

        Response::success('Мероприятия пользователя', [
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name']
            ],
            'events' => $events,
            'count' => count($events)
        ]);
    }

// Получить предстоящие мероприятия пользователя (для админа)
    public function getUpcomingEventsAdmin($userId) {
        $userModel = new User($this->db);
        $user = $userModel->getByIdForAdmin($userId);

        if (!$user) {
            Response::error('Пользователь не найден', [], 404);
        }

        $eventModel = new Event($this->db);
        $events = $eventModel->getUpcomingEventsByUserId($userId);

        Response::success('Предстоящие мероприятия пользователя', [
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name']
            ],
            'events' => $events,
            'count' => count($events)
        ]);
    }

// Получить прошедшие мероприятия пользователя (для админа)
    public function getPastEventsAdmin($userId) {
        $userModel = new User($this->db);
        $user = $userModel->getByIdForAdmin($userId);

        if (!$user) {
            Response::error('Пользователь не найден', [], 404);
        }

        $eventModel = new Event($this->db);
        $events = $eventModel->getPastEventsByUserId($userId);

        Response::success('Прошедшие мероприятия пользователя', [
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name']
            ],
            'events' => $events,
            'count' => count($events)
        ]);
    }

    public function getEventsReport()
    {
        $query = "SELECT 
                e.title AS event_name,
                c.name AS club_name,
                e.event_date AS event_datetime,
                e.max_participants,
                e.status AS event_status,
                e.external_fee_amount AS ticket_price,
                e.external_fee_currency AS currency,
                COUNT(ep.id) AS registered_count
              FROM events e
              JOIN clubs c ON e.club_id = c.id
              LEFT JOIN event_participants ep ON e.id = ep.event_id AND ep.status = 'registered'
              GROUP BY e.id
              ORDER BY e.event_date DESC";

        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Форматируем дату
            foreach ($events as &$event) {
                $event['event_datetime'] = date('Y-m-d H:i:s', strtotime($event['event_datetime']));
            }

            Response::success('Данные о событиях успешно получены', $events);
        } catch (PDOException $e) {
            Response::error('Ошибка при получении данных о событиях: ' . $e->getMessage(), null, 500);
        }
    }

    // server/controllers/EventController.php
    public function searchEvents($searchTerm, $payload) {
        try {
            $query = "
        SELECT 
            e.id AS event_id,
            e.title AS event_name,
            c.name AS club_name,
            c.id AS club_id,
            e.event_date AS event_datetime,
            e.max_participants,
            e.status AS event_status,
            e.external_fee_amount AS ticket_price,
            e.external_fee_currency AS currency,
            e.location,
            e.description AS event_description,
            e.is_free_for_members AS free_for_members,
            (SELECT COUNT(*) FROM event_participants ep WHERE ep.event_id = e.id AND ep.status = 'registered') AS registered_count
        FROM events e
        JOIN clubs c ON e.club_id = c.id
        WHERE (:search_term IS NULL OR 
               e.title LIKE CONCAT('%', :search_term, '%') OR
               e.description LIKE CONCAT('%', :search_term, '%') OR
               e.location LIKE CONCAT('%', :search_term, '%') OR
               c.name LIKE CONCAT('%', :search_term, '%'))
        ORDER BY e.event_date DESC
        LIMIT 20
        ";

            $stmt = $this->db->prepare($query);
            $searchParam = empty($searchTerm) ? null : '%' . $searchTerm . '%';
            $stmt->bindParam(':search_term', $searchParam);
            $stmt->execute();

            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Форматируем результат
            $formattedEvents = array_map(function($event) {
                return [
                    'event_id' => (int)$event['event_id'],
                    'event_name' => $event['event_name'],
                    'club_name' => $event['club_name'],
                    'club_id' => (int)$event['club_id'],
                    'event_datetime' => $event['event_datetime'],
                    'max_participants' => $event['max_participants'] ? (int)$event['max_participants'] : null,
                    'event_status' => $event['event_status'],
                    'ticket_price' => (float)$event['ticket_price'],
                    'currency' => $event['currency'],
                    'location' => $event['location'],
                    'free_for_members' => (bool)$event['free_for_members'],
                    'registered_count' => (int)$event['registered_count'],
                    'occupancy_rate' => $event['max_participants']
                        ? round(($event['registered_count'] * 100.0) / $event['max_participants'], 1) . '%'
                        : 'N/A'
                ];
            }, $events);

            Response::success('Результаты поиска ивентов', [
                'search_term' => $searchTerm,
                'events' => $formattedEvents,
                'total' => count($formattedEvents)
            ]);

        } catch (Exception $e) {
            Response::error('Ошибка при поиске ивентов: ' . $e->getMessage(), [], 500);
        }
    }

}
?>