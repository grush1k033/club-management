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