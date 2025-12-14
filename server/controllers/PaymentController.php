<?php
require_once __DIR__ . '/../models/Payment.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class PaymentController {
    private $db;
    private $paymentModel;

    public function __construct($db) {
        $this->db = $db;
        $this->paymentModel = new Payment($db);
    }


    // Создать новый платеж (без параметров)
    public function create() {
        // Проверка аутентификации
        $payload = AuthMiddleware::authenticate();

        $data = json_decode(file_get_contents('php://input'), true);

        // Валидация
        $errors = Validator::validatePaymentData($data);
        if (!empty($errors)) {
            return Response::error($errors, 400);
        }

        // Проверка прав доступа
        if ($payload['user_id'] != $data['user_id'] && $payload['role'] !== 'admin') {
            return Response::error("Unauthorized to create payment for this user", 403);
        }

        // Создание платежа
        if (isset($data['payment_method']) && $data['payment_method'] === 'balance') {
            $result = $this->paymentModel->createWithBalance($data);
        } else {
            $result = $this->paymentModel->create($data);
        }

        if ($result) {
            return Response::success($result, "Payment created successfully", 201);
        }

        return Response::error("Failed to create payment", 500);
    }

    public function getPayments()
    {
        $query = "
        SELECT
            p.id         AS payment_id,
            u.id         AS user_id,
            u.first_name AS user_first_name,
            u.last_name  AS user_last_name,
            c.id         AS club_id,
            c.name       AS club_name,
            p.payment_type,
            e.id         AS event_id,
            e.title      AS event_title,
            p.amount,
            p.currency,
            p.status,
            p.description,
            p.created_at AS payment_date
        FROM payments p
        INNER JOIN users u
            ON u.id = p.user_id
        INNER JOIN clubs c
            ON c.id = p.target_club_id
        LEFT JOIN events e
            ON e.id = p.event_id
        ORDER BY p.created_at DESC
    ";

        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($payments as &$payment) {
                $payment['payment_id'] = (int)$payment['payment_id'];
                $payment['user_id'] = (int)$payment['user_id'];
                $payment['club_id'] = (int)$payment['club_id'];
                $payment['event_id'] = $payment['event_id'] !== null
                    ? (int)$payment['event_id']
                    : null;

                $payment['amount'] = (float)$payment['amount'];
                $payment['payment_date'] = date(
                    'Y-m-d H:i:s',
                    strtotime($payment['payment_date'])
                );
            }

            Response::success('Платежи успешно получены', $payments);

        } catch (PDOException $e) {
            Response::error(
                'Ошибка при получении платежей: ' . $e->getMessage(),
                null,
                500
            );
        }
    }

    public function getMyPayments() {
        $payload = AuthMiddleware::authenticate();
        $userId = (int)$payload['user_id'];

        $query = "
        SELECT
            p.id                AS payment_id,
            u.id                AS user_id,
            u.first_name        AS user_first_name,
            u.last_name         AS user_last_name,
            c.id                AS club_id,
            c.name              AS club_name,
            p.payment_type,
            e.id                AS event_id,
            e.title             AS event_title,
            p.amount,
            p.currency,
            p.status,
            p.description,
            p.payment_method,
            p.transaction_id,
            p.created_at        AS payment_date
        FROM payments p
        INNER JOIN users u
            ON u.id = p.user_id
        INNER JOIN clubs c
            ON c.id = p.target_club_id
        LEFT JOIN events e
            ON e.id = p.event_id
        WHERE p.user_id = :user_id
        ORDER BY p.created_at DESC
    ";

        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([':user_id' => $userId]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Преобразуем типы данных
            foreach ($payments as &$payment) {
                $payment['payment_id'] = (int)$payment['payment_id'];
                $payment['user_id'] = (int)$payment['user_id'];
                $payment['club_id'] = (int)$payment['club_id'];
                $payment['event_id'] = $payment['event_id'] !== null
                    ? (int)$payment['event_id']
                    : null;
                $payment['amount'] = (float)$payment['amount'];
                $payment['payment_date'] = date(
                    'Y-m-d H:i:s',
                    strtotime($payment['payment_date'])
                );
            }

            Response::success('Платежи пользователя успешно получены', $payments);

        } catch (PDOException $e) {
            Response::error(
                'Ошибка при получении платежей пользователя: ' . $e->getMessage(),
                null,
                500
            );
        }
    }

    // Получить платеж по ID (с параметром)
    public function getById($paymentId) {
        $payload = AuthMiddleware::authenticate();

        $payment = $this->paymentModel->getById($paymentId);

        if (!$payment) {
            return Response::error("Payment not found", 404);
        }

        // Проверка прав доступа
        if ($payment['user_id'] != $payload['user_id'] &&
            $payload['role'] !== 'admin') {
            // Проверяем, является ли пользователь капитаном клуба
            $isClubCaptain = $this->checkClubLeadership($payload['user_id'], $payment['target_club_id']);
            if (!$isClubCaptain) {
                return Response::error("Unauthorized to view this payment", 403);
            }
        }

        return Response::success($payment);
    }

    // Обновить статус платежа (с параметром)
    public function updateStatus($paymentId) {
        $payload = AuthMiddleware::authenticate();
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['status'])) {
            return Response::error("Status is required", 400);
        }

        // Получаем платеж для проверки прав
        $payment = $this->paymentModel->getById($paymentId);
        if (!$payment) {
            return Response::error("Payment not found", 404);
        }

        // Проверяем права: админ или капитан клуба
        if ($payload['role'] !== 'admin') {
            $isClubCaptain = $this->checkClubLeadership($payload['user_id'], $payment['target_club_id']);
            if (!$isClubCaptain) {
                return Response::error("Unauthorized to update this payment", 403);
            }
        }

        $success = $this->paymentModel->updateStatus(
            $paymentId,
            $data['status'],
            isset($data['transaction_id']) ? $data['transaction_id'] : null
        );

        if ($success) {
            return Response::success(null, "Payment status updated successfully");
        }

        return Response::error("Failed to update payment status", 500);
    }

    // Создать платеж за мероприятие (без параметров)
    public function payForEvent() {
        $payload = AuthMiddleware::authenticate();
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['event_id'])) {
            return Response::error("Event ID is required", 400);
        }

        $userId = $payload['user_id'];
        $paymentMethod = isset($data['payment_method']) ? $data['payment_method'] : 'balance';

        $result = $this->paymentModel->createEventPayment($userId, $data['event_id'], $paymentMethod);

        if ($result) {
            return Response::success($result, "Event payment processed");
        }

        return Response::error("Failed to process event payment", 500);
    }

    // Получить платежи клуба (с параметром)
    public function getClubPayments($clubId) {
        $payload = AuthMiddleware::authenticate();

        // Проверка прав доступа к клубу
        if ($payload['role'] !== 'admin') {
            $isClubCaptain = $this->checkClubLeadership($payload['user_id'], $clubId);
            if (!$isClubCaptain) {
                return Response::error("Unauthorized to view club payments", 403);
            }
        }

        $filters = [
            'payment_type' => isset($_GET['payment_type']) ? $_GET['payment_type'] : null,
            'status' => isset($_GET['status']) ? $_GET['status'] : null,
            'start_date' => isset($_GET['start_date']) ? $_GET['start_date'] : null,
            'end_date' => isset($_GET['end_date']) ? $_GET['end_date'] : null
        ];

        // Удаляем null значения
        $filters = array_filter($filters, function($value) {
            return $value !== null && $value !== '';
        });

        $payments = $this->paymentModel->getClubPayments($clubId, $filters);

        return Response::success($payments);
    }
    // server/controllers/PaymentController.php (добавить в класс)

    /**
     * Получить все транзакции (для админа)
     * GET /api/transactions
     */
    public function getAllTransactions() {
        AuthMiddleware::requireRole('admin');

        $query = "
        SELECT 
            bt.id AS transaction_id,
            bt.user_id AS user_id,
            bt.created_at AS transaction_date,
            u.first_name AS user_first_name,
            u.last_name AS user_last_name,
            u.email AS user_email,
            bt.transaction_type AS transaction_type,
            bt.amount AS amount,
            bt.currency AS currency,
            bt.payment_id AS payment_id,
            bt.description AS description,
            c.id AS club_id,
            c.name AS club_name,
            e.id AS event_id,
            e.title AS event_title,
            bt.balance_before,
            bt.balance_after,
            bt.metadata
        FROM balance_transactions bt
        INNER JOIN users u 
            ON u.id = bt.user_id
        LEFT JOIN clubs c 
            ON c.id = bt.club_id
        LEFT JOIN events e 
            ON e.id = bt.event_id
        ORDER BY bt.created_at DESC
    ";

        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Преобразование типов данных
            foreach ($transactions as &$transaction) {
                $transaction['transaction_id'] = (int)$transaction['transaction_id'];
                $transaction['user_id'] = (int)$transaction['user_id'];
                $transaction['amount'] = (float)$transaction['amount'];
                $transaction['balance_before'] = (float)$transaction['balance_before'];
                $transaction['balance_after'] = (float)$transaction['balance_after'];

                // Опциональные поля
                $transaction['payment_id'] = $transaction['payment_id'] !== null
                    ? (int)$transaction['payment_id']
                    : null;
                $transaction['club_id'] = $transaction['club_id'] !== null
                    ? (int)$transaction['club_id']
                    : null;
                $transaction['event_id'] = $transaction['event_id'] !== null
                    ? (int)$transaction['event_id']
                    : null;

                $transaction['transaction_date'] = date(
                    'Y-m-d H:i:s',
                    strtotime($transaction['transaction_date'])
                );

                // Парсинг JSON metadata если есть
                if ($transaction['metadata'] && $transaction['metadata'] !== 'null') {
                    $transaction['metadata'] = json_decode($transaction['metadata'], true);
                } else {
                    $transaction['metadata'] = [];
                }
            }

            Response::success('Все транзакции успешно получены', $transactions);

        } catch (PDOException $e) {
            Response::error(
                'Ошибка при получении транзакций: ' . $e->getMessage(),
                null,
                500
            );
        }
    }




    // Оплатить клубный взнос (без параметров)
    public function payClubFee() {
        $payload = AuthMiddleware::authenticate();
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['club_fee_id'])) {
            return Response::error("Club fee ID is required", 400);
        }

        // Получаем информацию о взносе
        $feeQuery = "SELECT cf.*, c.id as club_id 
                     FROM club_fees cf 
                     JOIN clubs c ON cf.club_id = c.id 
                     WHERE cf.id = :club_fee_id AND cf.is_active = 1";

        $stmt = $this->db->prepare($feeQuery);
        $stmt->execute([':club_fee_id' => $data['club_fee_id']]);
        $fee = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fee) {
            return Response::error("Club fee not found or inactive", 404);
        }

        $userId = $payload['user_id'];
        $paymentMethod = isset($data['payment_method']) ? $data['payment_method'] : 'balance';

        $paymentData = [
            'user_id' => $userId,
            'target_club_id' => $fee['club_id'],
            'payment_type' => 'club_fee',
            'amount' => $fee['amount'],
            'currency' => $fee['currency'],
            'description' => isset($fee['description']) ? $fee['description'] : "Club fee: " . $fee['fee_type'],
            'is_cross_club_event' => false,
            'payment_method' => $paymentMethod
        ];

        if ($paymentMethod === 'balance') {
            $result = $this->paymentModel->createWithBalance($paymentData);
        } else {
            $result = $this->paymentModel->create($paymentData);
        }

        if ($result) {
            return Response::success($result, "Club fee payment processed successfully");
        }

        return Response::error("Failed to process club fee payment", 500);
    }

    public function getUserBalance() {
        $payload = AuthMiddleware::authenticate();
        $userId = $payload['user_id'];

        $query = "SELECT balance, currency FROM users WHERE id = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            Response::error("User not found", null, 404);
            return;
        }

        $balanceData = [
            'balance' => (float)$user['balance'],
            'currency' => $user['currency']
        ];

        Response::success('Баланс успешно получен', $balanceData);
    }

    // Получить историю транзакций баланса (без параметров)


    // server/controllers/PaymentController.php

// Получить вступительный взнос клуба
    public function getJoiningFee($clubId) {
        $query = "SELECT * FROM club_fees 
              WHERE club_id = :club_id 
              AND fee_type = 'joining' 
              AND is_active = 1
              LIMIT 1";

        $stmt = $this->db->prepare($query);
        $stmt->execute([':club_id' => $clubId]);
        $fee = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fee) {
            return Response::error("Joining fee not found for this club", 404);
        }

        return Response::success($fee);
    }
    public function payJoiningFee($clubId) {
        $payload = AuthMiddleware::authenticate();
        $userId = $payload['user_id'];

        error_log("=== PAY JOINING FEE START ===");
        error_log("User ID: " . $userId . ", Club ID: " . $clubId);
        try {
            // 1. Проверяем пользователя
            $userQuery = "SELECT id, club_id, balance, currency FROM users WHERE id = :user_id";
            $userStmt = $this->db->prepare($userQuery);
            $userStmt->execute([':user_id' => $userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return Response::error("User not found", 404);
            }

            // 2. Проверяем, не состоит ли уже в клубе
            if ($user['club_id'] !== null) {
                return Response::error("User already belongs to a club. Leave current club first.", 400);
            }

            // 3. Проверяем клуб
            $clubQuery = "SELECT id, name FROM clubs WHERE id = :club_id AND status = 'Active'";
            $clubStmt = $this->db->prepare($clubQuery);
            $clubStmt->execute([':club_id' => $clubId]);
            $club = $clubStmt->fetch(PDO::FETCH_ASSOC);

            if (!$club) {
                return Response::error("Club not found or inactive", 404);
            }

            // 4. Получаем вступительный взнос
            $feeQuery = "SELECT * FROM club_fees 
                     WHERE club_id = :club_id 
                     AND fee_type = 'joining' 
                     AND is_active = 1
                     LIMIT 1";

            $feeStmt = $this->db->prepare($feeQuery);
            $feeStmt->execute([':club_id' => $clubId]);
            $fee = $feeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$fee) {
                return Response::error("This club does not have a joining fee configured", 404);
            }

            // 5. Проверяем баланс
            $requiredAmount = $fee['amount'];
            $userBalance = $user['balance'];

            if ($userBalance < $requiredAmount) {
                return Response::error(
                    "Insufficient balance. Required: " . $requiredAmount . " " . $fee['currency'] .
                    ", Available: " . $userBalance . " " . $user['currency'],
                    400
                );
            }

            // 6. Проверяем существующие заявки
            $requestQuery = "SELECT id, status FROM club_join_requests 
                         WHERE user_id = :user_id 
                         AND club_id = :club_id 
                         AND status = 'approved'
                         LIMIT 1";

            $requestStmt = $this->db->prepare($requestQuery);
            $requestStmt->execute([
                ':user_id' => $userId,
                ':club_id' => $clubId
            ]);
            $existingRequest = $requestStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingRequest && $existingRequest['status'] === 'approved') {
                return Response::error("You are already a member of this club", 400);
            }

            // 7. Создаем или используем pending заявку
            $requestId = null;
            $pendingQuery = "SELECT id FROM club_join_requests 
                         WHERE user_id = :user_id 
                         AND club_id = :club_id 
                         AND status = 'pending'
                         LIMIT 1";

            $pendingStmt = $this->db->prepare($pendingQuery);
            $pendingStmt->execute([
                ':user_id' => $userId,
                ':club_id' => $clubId
            ]);
            $pendingRequest = $pendingStmt->fetch(PDO::FETCH_ASSOC);

            if ($pendingRequest) {
                $requestId = $pendingRequest['id'];
            } else {
                $createQuery = "INSERT INTO club_join_requests 
                            (user_id, club_id, status, message) 
                            VALUES (:user_id, :club_id, 'pending', 'Joining fee payment in progress')";

                $createStmt = $this->db->prepare($createQuery);
                $createStmt->execute([
                    ':user_id' => $userId,
                    ':club_id' => $clubId
                ]);
                $requestId = $this->db->lastInsertId();
            }

            // 8. Создаем платеж с ВСЕМИ полями
            $paymentData = [
                'user_id' => $userId,
                'target_club_id' => $clubId,
                'payment_type' => 'club_fee',
                'event_id' => null,
                'amount' => $requiredAmount,
                'currency' => $fee['currency'],
                'description' => 'Joining fee for ' . $club['name'] . ': ' . ($fee['description'] || ''),
                'is_cross_club_event' => false,
                'transaction_id' => 'JOIN_' . time() . '_' . $userId . '_' . $clubId,
                'payment_method' => 'balance',
                'status' => 'completed'
            ];

            $result = $this->paymentModel->createWithBalance($paymentData);

            if (!$result) {
                // Отменяем заявку
                $cancelQuery = "UPDATE club_join_requests 
                           SET status = 'cancelled', 
                               response_message = 'Payment failed',
                               processed_at = NOW()
                           WHERE id = :request_id";
                $cancelStmt = $this->db->prepare($cancelQuery);
                $cancelStmt->execute([':request_id' => $requestId]);

                return Response::error("Payment processing failed", 500);
            }

            // 9. Обновляем заявку как approved
            $updateRequestQuery = "UPDATE club_join_requests 
                               SET status = 'approved', 
                                   payment_id = :payment_id,
                                   processed_by = :user_id,
                                   processed_at = NOW(),
                                   response_message = 'Joining fee paid successfully'
                               WHERE id = :request_id";

            $updateStmt = $this->db->prepare($updateRequestQuery);
            $updateStmt->execute([
                ':payment_id' => $result['id'],
                ':user_id' => $userId,
                ':request_id' => $requestId
            ]);

            // 10. Обновляем club_id пользователя
            $updateUserQuery = "UPDATE users 
                            SET club_id = :club_id, 
                                updated_at = NOW()
                            WHERE id = :user_id";

            $updateUserStmt = $this->db->prepare($updateUserQuery);
            $updateUserStmt->execute([
                ':club_id' => $clubId,
                ':user_id' => $userId
            ]);

            // 11. Получаем обновленную информацию
            $finalQuery = "SELECT u.*, c.name as club_name 
                       FROM users u 
                       LEFT JOIN clubs c ON u.club_id = c.id 
                       WHERE u.id = :user_id";

            $finalStmt = $this->db->prepare($finalQuery);
            $finalStmt->execute([':user_id' => $userId]);
            $finalUser = $finalStmt->fetch(PDO::FETCH_ASSOC);

            return Response::success([
                'payment' => $result,
                'user' => [
                    'id' => $finalUser['id'],
                    'club_id' => $finalUser['club_id'],
                    'club_name' => $finalUser['club_name'],
                    'balance' => $finalUser['balance'],
                    'currency' => $finalUser['currency']
                ],
                'message' => 'Successfully joined the club'
            ], "Club joined successfully", 201);

        } catch (Exception $e) {
            error_log("payJoiningFee error: " . $e->getMessage());
            return Response::error("Internal server error", 500);
        }
    }


}