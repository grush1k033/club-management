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
    public function getEventsReport()
    {
        $query = "
        SELECT 
            e.id AS event_id,
            e.title AS event_name,
            c.id AS club_id,
            c.name AS club_name,
            e.created_by AS created_by,
            e.event_date AS event_datetime,
            e.max_participants,
            e.status AS event_status,
            e.external_fee_amount AS ticket_price,
            e.external_fee_currency AS currency,

            COUNT(ep.id) AS registered_count,

            COALESCE(
                JSON_ARRAYAGG(u.id),
                JSON_ARRAY()
            ) AS participants

        FROM events e
        JOIN clubs c 
            ON e.club_id = c.id

        LEFT JOIN event_participants ep 
            ON e.id = ep.event_id 
            AND ep.status = 'registered'

        LEFT JOIN users u 
            ON u.id = ep.user_id

        GROUP BY 
            e.id,
            e.title,
            c.id,
            c.name,
            e.created_by,
            e.event_date,
            e.max_participants,
            e.status,
            e.external_fee_amount,
            e.external_fee_currency

        ORDER BY e.event_date DESC
    ";

        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($events as &$event) {
                $event['event_id'] = (int)$event['event_id'];
                $event['club_id'] = (int)$event['club_id'];
                $event['created_by'] = $event['created_by'] !== null
                    ? (int)$event['created_by']
                    : null;
                $event['event_datetime'] = date('Y-m-d H:i:s', strtotime($event['event_datetime']));
                $event['max_participants'] = $event['max_participants'] !== null
                    ? (int)$event['max_participants']
                    : null;
                $event['ticket_price'] = (float)$event['ticket_price'];
                $event['registered_count'] = (int)$event['registered_count'];

                // participants → массив int
                $event['participants'] = $event['participants']
                    ? array_map('intval', json_decode($event['participants'], true))
                    : [];
            }

            Response::success('Данные о событиях успешно получены', $events);

        } catch (PDOException $e) {
            Response::error(
                'Ошибка при получении данных о событиях: ' . $e->getMessage(),
                null,
                500
            );
        }
    }


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
    // В EventController.php
    public function registerForEvent($eventId = null)
    {
        try {
            // Если eventId не передан через роут, пытаемся получить из POST данных
            if (!$eventId) {
                $input = json_decode(file_get_contents('php://input'), true);
                if (isset($input['event_id'])) {
                    $eventId = (int)$input['event_id'];
                }
            }

            if (!$eventId) {
                Response::error('Не указан ID события', null, 400);
            }

            // Получаем токен из заголовка
            $token = '';
            if (isset($_SERVER['HTTP_X_AUTH_TOKEN'])) {
                $token = $_SERVER['HTTP_X_AUTH_TOKEN'];
            } elseif (isset($_SERVER['HTTP_X_AUTHORIZATION'])) {
                $token = $_SERVER['HTTP_X_AUTHORIZATION'];
            }

            if (empty($token)) {
                Response::error('Токен не предоставлен', [], 401);
            }

            $payload = JWT::verify($token);
            if (!$payload || !isset($payload['user_id'])) {
                Response::error('Неверный токен', [], 401);
            }

            $userId = $payload['user_id'];

            // Получаем данные пользователя
            $stmt = $this->db->prepare("
            SELECT id, club_id, balance, currency FROM users WHERE id = ?
        ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                Response::error('Пользователь не найден', null, 404);
            }

            // Проверяем, существует ли событие
            $stmt = $this->db->prepare("
            SELECT e.*, c.name as club_name 
            FROM events e 
            LEFT JOIN clubs c ON e.club_id = c.id 
            WHERE e.id = ?
        ");
            $stmt->execute([$eventId]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$event) {
                Response::error('Событие не найдено', null, 404);
            }

            // Проверяем статус события
            if ($event['status'] !== 'scheduled') {
                Response::error('Регистрация на это событие недоступна. Статус: ' . $event['status'], null, 400);
            }

            // Проверяем, не превышено ли максимальное количество участников
            if ($event['max_participants'] > 0) {
                $stmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM event_participants 
                WHERE event_id = ? AND status = 'registered'
            ");
                $stmt->execute([$eventId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($result['count'] >= $event['max_participants']) {
                    Response::error('Мест больше нет. Достигнуто максимальное количество участников', null, 400);
                }
            }

            // Проверяем, не зарегистрирован ли пользователь уже на это событие
            $stmt = $this->db->prepare("
            SELECT id, status 
            FROM event_participants 
            WHERE event_id = ? AND user_id = ?
        ");
            $stmt->execute([$eventId, $userId]);
            $existingRegistration = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingRegistration) {
                if ($existingRegistration['status'] === 'cancelled') {
                    $action = 're-registered';
                    $registrationId = $existingRegistration['id'];
                } elseif ($existingRegistration['status'] === 'registered') {
                    Response::error('Вы уже зарегистрированы на это событие', null, 400);
                } elseif ($existingRegistration['status'] === 'attended') {
                    Response::error('Вы уже посетили это событие', null, 400);
                }
            } else {
                $action = 'registered';
                $registrationId = null;
            }

            // Проверяем, требуется ли оплата
            $needsPayment = false;
            $paymentId = null;

            if ($event['external_fee_amount'] > 0) {
                // Проверяем условия бесплатности для членов клуба
                if (!($user['club_id'] == $event['club_id'] && $event['is_free_for_members'] == 1)) {
                    $needsPayment = true;

                    // Проверяем достаточность баланса
                    if ($user['balance'] < $event['external_fee_amount']) {
                        Response::error('Недостаточно средств на балансе', [
                            'required' => $event['external_fee_amount'],
                            'current_balance' => $user['balance'],
                            'currency' => $user['currency']
                        ], 400);
                    }

                    // Подготавливаем данные для платежа
                    $transactionId = 'EVT_' . time() . '_' . $eventId . '_' . $userId;
                    $description = "Registration fee for event: " . $event['title'];
                    $isCrossClub = ($user['club_id'] != $event['club_id']) ? 1 : 0;
                }
            }

            // ========== НАЧИНАЕМ ТРАНЗАКЦИЮ ==========
            $this->db->beginTransaction();

            try {
                // 1. Создаем/обновляем регистрацию
                if ($action === 're-registered' && $registrationId) {
                    // Обновляем существующую отмененную регистрацию
                    $stmt = $this->db->prepare("
                    UPDATE event_participants 
                    SET status = 'registered', 
                        registration_date = NOW(),
                        attended_at = NULL,
                        notes = NULL
                    WHERE id = ?
                ");
                    $stmt->execute([$registrationId]);
                } else {
                    // Создаем новую регистрацию
                    $stmt = $this->db->prepare("
                    INSERT INTO event_participants (event_id, user_id, status, registration_date)
                    VALUES (?, ?, 'registered', NOW())
                ");
                    $stmt->execute([$eventId, $userId]);
                    $registrationId = $this->db->lastInsertId();
                }

                // 2. Обрабатываем оплату (если требуется)
                if ($needsPayment) {
                    // Создаем запись о платеже
                    $stmt = $this->db->prepare("
                    INSERT INTO payments (
                        user_id, target_club_id, payment_type, event_id, 
                        amount, currency, status, description, 
                        is_cross_club_event, transaction_id, payment_method
                    ) VALUES (?, ?, 'event_fee', ?, ?, ?, 'pending', ?, ?, ?, 'balance')
                ");

                    $stmt->execute([
                        $userId,
                        $event['club_id'],
                        $eventId,
                        $event['external_fee_amount'],
                        $event['external_fee_currency'],
                        $description,
                        $isCrossClub,
                        $transactionId
                    ]);

                    $paymentId = $this->db->lastInsertId();

                    // Обновляем баланс пользователя
                    $stmt = $this->db->prepare("
                    UPDATE users 
                    SET balance = balance - ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                    $stmt->execute([$event['external_fee_amount'], $userId]);

                    // Записываем транзакцию в историю
                    $newBalance = $user['balance'] - $event['external_fee_amount'];
                    $stmt = $this->db->prepare("
                    INSERT INTO balance_transactions (
                        user_id, transaction_type, amount, currency,
                        balance_before, balance_after, payment_id,
                        club_id, event_id, description
                    ) VALUES (?, 'payment', ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                    $stmt->execute([
                        $userId,
                        $event['external_fee_amount'],
                        $event['external_fee_currency'],
                        $user['balance'],
                        $newBalance,
                        $paymentId,
                        $event['club_id'],
                        $eventId,
                        "Оплата регистрации на событие: " . $event['title']
                    ]);

                    // Обновляем статус платежа на completed
                    $stmt = $this->db->prepare("
                    UPDATE payments 
                    SET status = 'completed',
                        updated_at = NOW()
                    WHERE id = ?
                ");
                    $stmt->execute([$paymentId]);
                }

                // 3. Подтверждаем транзакцию
                $this->db->commit();

            } catch (Exception $e) {
                // Откатываем транзакцию при ошибке
                $this->db->rollBack();
                throw $e;
            }

            Response::success('Регистрация на событие успешна', [
                'event_id' => $eventId,
                'user_id' => $userId,
                'event_title' => $event['title'],
                'club_name' => $event['club_name'],
                'action' => $action,
                'registration_id' => $registrationId,
                'needs_payment' => $needsPayment,
                'payment_id' => $paymentId,
                'fee_amount' => $event['external_fee_amount'],
                'is_free_for_members' => $event['is_free_for_members'] == 1,
                'event_date' => $event['event_date'],
                'location' => $event['location'],
                'max_participants' => $event['max_participants'],
                'current_participants' => isset($result['count']) ? $result['count'] + 1 : 1
            ], 201);

        } catch (Exception $e) {
            Response::error('Ошибка при регистрации: ' . $e->getMessage());
        }
    }

    public function cancelEventRegistration($eventId, $userId = null)
    {
        try {
            // Если userId не передан, используем текущего пользователя из JWT
            if (!$userId) {
                $token = '';
                if (isset($_SERVER['HTTP_X_AUTH_TOKEN'])) {
                    $token = $_SERVER['HTTP_X_AUTH_TOKEN'];
                }

                if (empty($token)) {
                    Response::error('Токен не предоставлен', [], 401);
                }

                $payload = JWT::verify($token);
                if (!$payload || !isset($payload['user_id'])) {
                    Response::error('Неверный токен', [], 401);
                }

                $userId = $payload['user_id'];
            }

            // Проверяем существующую регистрацию
            $stmt = $this->db->prepare("
            SELECT ep.*, e.title as event_title, e.event_date
            FROM event_participants ep
            JOIN events e ON ep.event_id = e.id
            WHERE ep.event_id = ? AND ep.user_id = ? AND ep.status = 'registered'
        ");
            $stmt->execute([$eventId, $userId]);
            $registration = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$registration) {
                Response::error('Регистрация на событие не найдена', null, 404);
            }

            // Проверяем, что событие еще не началось
            if (strtotime($registration['event_date']) <= time()) {
                Response::error('Невозможно отменить регистрацию: событие уже началось или завершилось', null, 400);
            }

            // Отменяем регистрацию
            $stmt = $this->db->prepare("
            UPDATE event_participants 
            SET status = 'cancelled', 
                notes = CONCAT(IFNULL(notes, ''), 'Cancelled on ', NOW())
            WHERE id = ?
        ");
            $stmt->execute([$registration['id']]);

            // Если была оплата, можем также обновить статус платежа (опционально)
            $stmt = $this->db->prepare("
            UPDATE payments 
            SET status = 'cancelled' 
            WHERE user_id = ? AND event_id = ? AND status = 'pending'
        ");
            $stmt->execute([$userId, $eventId]);

            Response::success('Регистрация на событие отменена', [
                'event_id' => $eventId,
                'event_title' => $registration['event_title'],
                'cancelled_at' => date('Y-m-d H:i:s')
            ]);

        } catch (Exception $e) {
            Response::error('Ошибка при отмене регистрации: ' . $e->getMessage());
        }
    }
    public function deleteEvent($eventId)
    {
        try {
            // Аутентифицируем пользователя
            $user = AuthMiddleware::authenticate();

            // Проверяем права доступа
            if (!in_array($user['role'], ['admin', 'club_owner'])) {
                Response::error('Недостаточно прав для удаления события', null, 403);
            }

            // Проверяем существование события и права
            $checkQuery = "SELECT e.*, c.captain_id, c.vice_captain_id 
                      FROM events e 
                      JOIN clubs c ON e.club_id = c.id 
                      WHERE e.id = ?";
            $stmt = $this->db->prepare($checkQuery);
            $stmt->execute([$eventId]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$event) {
                Response::error('Событие не найдено', null, 404);
            }

            // Проверяем права club_owner
            if ($user['role'] === 'club_owner') {
                if ($user['id'] != $event['captain_id'] && $user['id'] != $event['vice_captain_id']) {
                    Response::error('Вы не можете удалять события этого клуба', null, 403);
                }
            }

            // Просто удаляем событие - CASCADE сделает остальное
            $deleteQuery = "DELETE FROM events WHERE id = ?";
            $stmt = $this->db->prepare($deleteQuery);
            $stmt->execute([$eventId]);

            if ($stmt->rowCount() > 0) {
                Response::success('Событие успешно удалено', [
                    'event_id' => (int)$eventId,
                    'event_title' => $event['title'],
                    'deleted' => true,
                    'note' => 'Участники события были автоматически удалены (CASCADE)'
                ]);
            } else {
                Response::error('Событие не было удалено', null, 500);
            }

        } catch (PDOException $e) {
            Response::error('Ошибка базы данных: ' . $e->getMessage(), null, 500);
        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage(), null, 500);
        }
    }
    public function deleteMultipleEvents()
    {
        try {
            // Аутентификация
            $user = AuthMiddleware::authenticate();

            // Проверяем права
            if (!in_array($user['role'], ['admin', 'club_owner'])) {
                Response::error('Недостаточно прав для удаления событий', null, 403);
            }

            // Получаем данные из запроса
            $input = file_get_contents('php://input');
            if (empty($input)) {
                Response::error('Тело запроса пустое', null, 400);
            }

            $data = json_decode($input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Response::error('Неверный JSON формат', null, 400);
            }

            // Проверяем наличие массива ID событий
            if (!isset($data['event_ids']) || !is_array($data['event_ids']) || empty($data['event_ids'])) {
                Response::error('Необходим массив event_ids с ID событий для удаления', null, 400);
            }

            // Фильтруем и валидируем ID
            $eventIds = array_map('intval', $data['event_ids']);
            $eventIds = array_filter($eventIds, function($id) {
                return $id > 0;
            });

            if (empty($eventIds)) {
                Response::error('Некорректные ID событий', null, 400);
            }

            // Получаем информацию о событиях для проверки прав
            $placeholders = str_repeat('?,', count($eventIds) - 1) . '?';
            $eventsQuery = "SELECT e.*, c.captain_id, c.vice_captain_id 
                       FROM events e 
                       JOIN clubs c ON e.club_id = c.id 
                       WHERE e.id IN ($placeholders)";

            $stmt = $this->db->prepare($eventsQuery);
            $stmt->execute($eventIds);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($events) !== count($eventIds)) {
                Response::error('Некоторые события не найдены', null, 404);
            }

            // Проверяем права для каждого события (если пользователь не админ)
            if ($user['role'] === 'club_owner') {
                foreach ($events as $event) {
                    if ($user['id'] != $event['captain_id'] && $user['id'] != $event['vice_captain_id']) {
                        Response::error('У вас нет прав для удаления события: ' . $event['title'], null, 403);
                    }
                }
            }

            // Удаляем события
            $deleteQuery = "DELETE FROM events WHERE id IN ($placeholders)";
            $stmt = $this->db->prepare($deleteQuery);
            $stmt->execute($eventIds);

            $deletedCount = $stmt->rowCount();

            Response::success('События успешно удалены', [
                'deleted_count' => $deletedCount,
                'total_requested' => count($eventIds),
                'deleted_event_ids' => $eventIds,
                'events_info' => array_map(function($event) {
                    return [
                        'id' => (int)$event['id'],
                        'title' => $event['title'],
                        'club_id' => (int)$event['club_id'],
                        'event_date' => $event['event_date']
                    ];
                }, $events)
            ]);

        } catch (PDOException $e) {
            Response::error('Ошибка базы данных: ' . $e->getMessage(), null, 500);
        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage(), null, 500);
        }
    }

    public function updateEvent($eventId)
    {
        try {
            // Аутентификация
            $user = AuthMiddleware::authenticate();

            // Проверяем права
            if (!in_array($user['role'], ['admin', 'club_owner'])) {
                Response::error('Недостаточно прав для изменения события', null, 403);
            }

            // Получаем данные из запроса
            $input = file_get_contents('php://input');
            if (empty($input)) {
                Response::error('Тело запроса пустое', null, 400);
            }

            $data = json_decode($input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Response::error('Неверный JSON формат', null, 400);
            }

            // Проверяем существование события
            $eventQuery = "SELECT e.*, c.captain_id, c.vice_captain_id 
                      FROM events e 
                      JOIN clubs c ON e.club_id = c.id 
                      WHERE e.id = ?";

            $stmt = $this->db->prepare($eventQuery);
            $stmt->execute([$eventId]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$event) {
                Response::error('Событие не найдено', null, 404);
            }

            // Проверяем права club_owner
            if ($user['role'] === 'club_owner') {
                if ($user['id'] != $event['captain_id'] && $user['id'] != $event['vice_captain_id']) {
                    Response::error('Вы не можете изменять события этого клуба', null, 403);
                }
            }



            // Подготавливаем данные для обновления
            $updateFields = [];
            $updateParams = [];

            // Поля, которые можно обновлять
            $allowedFields = [
                'title', 'description', 'event_date', 'location',
                'max_participants', 'external_fee_amount', 'external_fee_currency',
                'is_free_for_members', 'status'
            ];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    // Валидация для конкретных полей
                    if ($field === 'event_date') {
                        $dateTime = strtotime($data[$field]);
                        if ($dateTime === false) {
                            Response::error('Неверный формат даты', null, 400);
                        }
                        $updateFields[] = "$field = ?";
                        $updateParams[] = date('Y-m-d H:i:s', $dateTime);
                    } elseif ($field === 'max_participants') {
                        $value = (int)$data[$field];
                        if ($value < 0) {
                            Response::error('Количество участников не может быть отрицательным', null, 400);
                        }
                        $updateFields[] = "$field = ?";
                        $updateParams[] = $value > 0 ? $value : null;
                    } elseif ($field === 'external_fee_amount') {
                        $value = (float)$data[$field];
                        if ($value < 0) {
                            Response::error('Стоимость не может быть отрицательной', null, 400);
                        }
                        $updateFields[] = "$field = ?";
                        $updateParams[] = $value;
                    } elseif ($field === 'status' && !in_array($data[$field], ['scheduled', 'ongoing', 'completed', 'cancelled'])) {
                        Response::error('Некорректный статус события', null, 400);
                    } else {
                        $updateFields[] = "$field = ?";
                        $updateParams[] = $data[$field];
                    }
                }
            }

            // Если нет полей для обновления
            if (empty($updateFields)) {
                Response::error('Нет данных для обновления', null, 400);
            }

            // Добавляем обновление времени и ID события
            $updateFields[] = "updated_at = NOW()";

            // Выполняем обновление
            $updateQuery = "UPDATE events SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $updateParams[] = $eventId;

            $stmt = $this->db->prepare($updateQuery);
            $stmt->execute($updateParams);

            // Получаем обновленные данные события
            $updatedEventQuery = "SELECT 
                e.id AS event_id,
                e.title AS event_name,
                c.id AS club_id,
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
                e.updated_at
            FROM events e
            JOIN clubs c ON e.club_id = c.id
            WHERE e.id = ?";

            $stmt = $this->db->prepare($updatedEventQuery);
            $stmt->execute([$eventId]);
            $updatedEvent = $stmt->fetch(PDO::FETCH_ASSOC);

            // Форматируем ответ
            if ($updatedEvent) {
                $updatedEvent['event_id'] = (int)$updatedEvent['event_id'];
                $updatedEvent['club_id'] = (int)$updatedEvent['club_id'];
                $updatedEvent['max_participants'] = $updatedEvent['max_participants'] ? (int)$updatedEvent['max_participants'] : null;
                $updatedEvent['ticket_price'] = (float)$updatedEvent['ticket_price'];
                $updatedEvent['is_free_for_members'] = (bool)$updatedEvent['is_free_for_members'];
            }

            Response::success('Событие успешно обновлено', $updatedEvent);

        } catch (PDOException $e) {
            Response::error('Ошибка базы данных: ' . $e->getMessage(), null, 500);
        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage(), null, 500);
        }
    }

}
?>