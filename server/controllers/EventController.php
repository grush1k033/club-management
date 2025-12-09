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
    public function create() {
        try {
            // 1. Проверяем авторизацию
            $user = AuthMiddleware::authenticate();

            // 2. Получаем данные напрямую (как в тестовом методе)
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE || !$data) {
                Response::error('Некорректный JSON', null, 400);
            }

            // 3. Проверяем обязательные поля
            $required = ['club_id', 'title', 'event_date'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    Response::error("Отсутствует обязательное поле: $field", null, 422);
                }
            }

            // 4. Проверяем формат даты
            $event_date = $data['event_date'];
            if (!strtotime($event_date)) {
                Response::error("Некорректный формат даты: $event_date", null, 422);
            }

            // 5. Форматируем дату для MySQL
            $formatted_date = date('Y-m-d H:i:s', strtotime($event_date));

            // 6. Получаем user_id (используем правильный ключ)
            $userId = $user['user_id'] || $user['id'] || null;
            if (!$userId) {
                Response::error('Не удалось определить ID пользователя', null, 400);
            }

            // 7. Проверяем права через прямой SQL запрос (временно, пока не исправим ClubMiddleware)
            $club_id = (int)$data['club_id'];
            $query = "SELECT * FROM clubs 
                  WHERE id = :club_id 
                  AND (captain_id = :user_id OR vice_captain_id = :user_id)";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':club_id', $club_id, PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            $isCaptainOrVice = $stmt->rowCount() > 0;
            $isAdmin = ($user['role'] || '') == 'admin';

            if (!$isCaptainOrVice && !$isAdmin) {
                Response::forbidden('Только капитан, заместитель клуба или администратор может создавать мероприятия');
            }

            // 8. Создаем мероприятие через прямой SQL запрос (временно, пока не исправим Event.php)
            $query = "INSERT INTO events (club_id, title, description, event_date, location, status, max_participants, created_by) 
                  VALUES (:club_id, :title, :description, :event_date, :location, :status, :max_participants, :created_by)";

            $stmt = $this->db->prepare($query);

            // Подготавливаем значения
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

            $stmt->bindParam(':created_by', $userId, PDO::PARAM_INT);

            if ($stmt->execute()) {
                $lastId = $this->db->lastInsertId();
                Response::success('Мероприятие успешно создано', ['id' => $lastId]);
            } else {
                $errorInfo = $stmt->errorInfo();
                Response::error('Ошибка базы данных: ' . $errorInfo[2], null, 500);
            }

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage());
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
            if ($this->eventModel->isUserParticipant($eventId, $user['id'])) {
                Response::error('Вы уже зарегистрированы на это мероприятие');
            }

            // Регистрируем пользователя
            $query = "INSERT INTO event_participants (event_id, user_id, status) 
                      VALUES (:event_id, :user_id, 'registered')";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':event_id', $eventId);
            $stmt->bindParam(':user_id', $user['id']);

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

            // Отменяем регистрацию
            $query = "UPDATE event_participants 
                      SET status = 'cancelled', 
                          attended_at = NULL 
                      WHERE event_id = :event_id 
                      AND user_id = :user_id 
                      AND status = 'registered'";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':event_id', $eventId);
            $stmt->bindParam(':user_id', $user['id']);
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

            $events = $this->eventModel->getUserEvents($user['id']);
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
            $stmt->bindParam(':user_id', $user['id']);
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

            $query = "SELECT e.*, c.name as club_name, ep.status as participation_status
                      FROM events e
                      JOIN event_participants ep ON e.id = ep.event_id
                      JOIN clubs c ON e.club_id = c.id
                      WHERE ep.user_id = :user_id 
                      AND e.status = 'completed'
                      ORDER BY e.event_date DESC";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $user['id']);
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

    public function debugInput() {
        $raw = file_get_contents('php://input');

        echo "=== DEBUG INPUT ===<br>";
        echo "Raw length: " . strlen($raw) . "<br>";
        echo "Raw content: " . htmlspecialchars($raw) . "<br><br>";

        $json = json_decode($raw, true);
        echo "JSON decode error: " . json_last_error_msg() . "<br>";
        echo "JSON result: ";
        print_r($json);
        echo "<br><br>";

        echo "POST array: ";
        print_r($_POST);
        echo "<br><br>";

        echo "SERVER CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] || 'not set');
    }
}
?>