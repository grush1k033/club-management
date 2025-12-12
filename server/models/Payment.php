<?php
class Payment {
    private $db;
    private $table = 'payments';

    public function __construct($db) {
        $this->db = $db;
    }

    // ==============================
    // CREATE METHODS
    // ==============================

    public function create($data) {
        $event_id = isset($data['event_id']) ? $data['event_id'] : null;
        $is_cross_club_event = isset($data['is_cross_club_event']) ? $data['is_cross_club_event'] : false;
        $transaction_id = isset($data['transaction_id']) ? $data['transaction_id'] : null;
        $status = isset($data['status']) ? $data['status'] : 'pending';
        $currency = isset($data['currency']) ? $data['currency'] : 'USD';
        $description = isset($data['description']) ? $data['description'] : '';

        $query = "INSERT INTO {$this->table} 
              (user_id, target_club_id, payment_type, event_id, amount, currency, 
               description, is_cross_club_event, transaction_id, payment_method, status) 
              VALUES (:user_id, :target_club_id, :payment_type, :event_id, :amount, :currency, 
                      :description, :is_cross_club_event, :transaction_id, :payment_method, :status)";

        $stmt = $this->db->prepare($query);

        $params = [
            ':user_id' => $data['user_id'],
            ':target_club_id' => $data['target_club_id'],
            ':payment_type' => $data['payment_type'],
            ':event_id' => $event_id,
            ':amount' => $data['amount'],
            ':currency' => $currency,
            ':description' => $description,
            ':is_cross_club_event' => $is_cross_club_event ? 1 : 0,
            ':transaction_id' => $transaction_id,
            ':payment_method' => $data['payment_method'],
            ':status' => $status
        ];

        $result = $stmt->execute($params);

        if (!$result) {
            return false;
        }

        $lastId = $this->db->lastInsertId();
        return $this->getById($lastId);
    }

    public function createWithBalance($data) {
        try {
            $this->db->beginTransaction();

            // 1. Check user balance
            $userQuery = "SELECT balance, currency FROM users WHERE id = :user_id";
            $userStmt = $this->db->prepare($userQuery);
            $userStmt->execute([':user_id' => $data['user_id']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception("User not found");
            }

            if ($user['balance'] < $data['amount']) {
                throw new Exception("Insufficient balance");
            }

            if ($user['currency'] != ($data['currency'] || 'USD')) {
                throw new Exception("Currency mismatch");
            }

            // 2. Create payment
            $data['payment_method'] = 'balance';
            $data['status'] = 'completed';
            $payment = $this->create($data);

            if (!$payment) {
                throw new Exception("Failed to create payment record");
            }

            // 3. Deduct from user balance
            $updateBalanceQuery = "UPDATE users 
                               SET balance = balance - :amount, 
                                   updated_at = CURRENT_TIMESTAMP
                               WHERE id = :user_id";
            $updateStmt = $this->db->prepare($updateBalanceQuery);

            if (!$updateStmt->execute([
                ':amount' => $data['amount'],
                ':user_id' => $data['user_id']
            ])) {
                throw new Exception("Failed to update user balance");
            }

            // 4. Record balance transaction
            $balanceBefore = $user['balance'];
            $balanceAfter = $balanceBefore - $data['amount'];

            $transactionQuery = "INSERT INTO balance_transactions 
                             (user_id, transaction_type, amount, currency, 
                              balance_before, balance_after, payment_id, 
                              club_id, event_id, description)
                             VALUES (:user_id, 'payment', :amount, :currency, 
                                     :balance_before, :balance_after, :payment_id, 
                                     :club_id, :event_id, :description)";

            $transactionStmt = $this->db->prepare($transactionQuery);

            $transactionData = [
                ':user_id' => $data['user_id'],
                ':amount' => $data['amount'],
                ':currency' => isset($data['currency']) ? $data['currency'] : 'USD',
                ':balance_before' => $balanceBefore,
                ':balance_after' => $balanceAfter,
                ':payment_id' => $payment['id'],
                ':club_id' => $data['target_club_id'],
                ':event_id' => isset($data['event_id']) && $data['event_id'] !== '' ? $data['event_id'] : null,
                ':description' => isset($data['description']) ? $data['description'] : "Payment for " . (isset($data['payment_type']) ? $data['payment_type'] : 'unknown')
            ];

            if (!$transactionStmt->execute($transactionData)) {
                throw new Exception("Failed to record balance transaction");
            }

            $this->db->commit();
            return $payment;

        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function createEventPayment($userId, $eventId, $paymentMethod = 'balance') {
        $eventQuery = "SELECT e.*, c.id as club_id 
                       FROM events e 
                       JOIN clubs c ON e.club_id = c.id 
                       WHERE e.id = :event_id";
        $eventStmt = $this->db->prepare($eventQuery);
        $eventStmt->execute([':event_id' => $eventId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            return false;
        }

        if ($event['is_free_for_members']) {
            $checkMembership = "SELECT 1 FROM users 
                                WHERE id = :user_id AND club_id = :club_id";
            $membershipStmt = $this->db->prepare($checkMembership);
            $membershipStmt->execute([
                ':user_id' => $userId,
                ':club_id' => $event['club_id']
            ]);

            if ($membershipStmt->rowCount() > 0) {
                return [
                    'status' => 'free_for_member',
                    'message' => 'Event is free for club members'
                ];
            }
        }

        $paymentData = [
            'user_id' => $userId,
            'target_club_id' => $event['club_id'],
            'payment_type' => 'event_fee',
            'event_id' => $eventId,
            'amount' => $event['external_fee_amount'],
            'currency' => $event['external_fee_currency'],
            'description' => "Payment for event: " . $event['title'],
            'is_cross_club_event' => false,
            'payment_method' => $paymentMethod
        ];

        if ($paymentMethod === 'balance') {
            return $this->createWithBalance($paymentData);
        } else {
            $paymentData['status'] = 'pending';
            $paymentData['transaction_id'] = 'TXN_' . uniqid();
            return $this->create($paymentData);
        }
    }

    // ==============================
    // GET METHODS
    // ==============================

    public function getById($id) {
        $query = "SELECT p.*, 
                         u.email as user_email, 
                         u.first_name, 
                         u.last_name,
                         c.name as club_name,
                         e.title as event_title
                  FROM {$this->table} p
                  LEFT JOIN users u ON p.user_id = u.id
                  LEFT JOIN clubs c ON p.target_club_id = c.id
                  LEFT JOIN events e ON p.event_id = e.id
                  WHERE p.id = :id";

        $stmt = $this->db->prepare($query);
        $stmt->execute([':id' => $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAll($filters = [], $limit = 50, $offset = 0) {
        $where = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = "p.user_id = :user_id";
            $params[':user_id'] = $filters['user_id'];
        }

        if (!empty($filters['club_id'])) {
            $where[] = "p.target_club_id = :club_id";
            $params[':club_id'] = $filters['club_id'];
        }

        if (!empty($filters['event_id'])) {
            $where[] = "p.event_id = :event_id";
            $params[':event_id'] = $filters['event_id'];
        }

        if (!empty($filters['payment_type'])) {
            $where[] = "p.payment_type = :payment_type";
            $params[':payment_type'] = $filters['payment_type'];
        }

        if (!empty($filters['status'])) {
            $where[] = "p.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['payment_method'])) {
            $where[] = "p.payment_method = :payment_method";
            $params[':payment_method'] = $filters['payment_method'];
        }

        $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

        $query = "SELECT p.*, 
                         u.email as user_email, 
                         u.first_name, 
                         u.last_name,
                         c.name as club_name,
                         e.title as event_title
                  FROM {$this->table} p
                  LEFT JOIN users u ON p.user_id = u.id
                  LEFT JOIN clubs c ON p.target_club_id = c.id
                  LEFT JOIN events e ON p.event_id = e.id
                  {$whereClause}
                  ORDER BY p.created_at DESC
                  LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalQuery = "SELECT COUNT(*) as total FROM {$this->table} p {$whereClause}";
        $totalStmt = $this->db->prepare($totalQuery);

        foreach ($params as $key => $value) {
            $totalStmt->bindValue($key, $value);
        }

        $totalStmt->execute();
        $totalResult = $totalStmt->fetch(PDO::FETCH_ASSOC);

        return [
            'payments' => $payments,
            'total' => $totalResult['total'] || 0,
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    public function getUserPayments($userId, $limit = 20) {
        $query = "SELECT p.*, 
                         c.name as club_name,
                         e.title as event_title
                  FROM {$this->table} p
                  LEFT JOIN clubs c ON p.target_club_id = c.id
                  LEFT JOIN events e ON p.event_id = e.id
                  WHERE p.user_id = :user_id
                  ORDER BY p.created_at DESC
                  LIMIT :limit";

        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getClubPayments($clubId, $filters = []) {
        $where = ["p.target_club_id = :club_id"];
        $params = [':club_id' => $clubId];

        if (!empty($filters['payment_type'])) {
            $where[] = "p.payment_type = :payment_type";
            $params[':payment_type'] = $filters['payment_type'];
        }

        if (!empty($filters['status'])) {
            $where[] = "p.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['start_date'])) {
            $where[] = "p.created_at >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $where[] = "p.created_at <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }

        $whereClause = implode(" AND ", $where);

        $query = "SELECT p.*, 
                         u.email as user_email, 
                         u.first_name, 
                         u.last_name,
                         e.title as event_title
                  FROM {$this->table} p
                  LEFT JOIN users u ON p.user_id = u.id
                  LEFT JOIN events e ON p.event_id = e.id
                  WHERE {$whereClause}
                  ORDER BY p.created_at DESC";

        $stmt = $this->db->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStats($clubId = null) {
        $where = $clubId ? "WHERE target_club_id = :club_id" : "";
        $params = $clubId ? [':club_id' => $clubId] : [];

        $query = "SELECT 
                    COUNT(*) as total_payments,
                    SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_revenue,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                    payment_type,
                    currency
                  FROM {$this->table}
                  {$where}
                  GROUP BY payment_type, currency";

        $stmt = $this->db->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ==============================
    // UPDATE METHODS
    // ==============================

    public function updateStatus($id, $status, $transactionId = null) {
        $query = "UPDATE {$this->table} 
                  SET status = :status, 
                      transaction_id = COALESCE(:transaction_id, transaction_id),
                      updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id";

        $stmt = $this->db->prepare($query);

        return $stmt->execute([
            ':status' => $status,
            ':transaction_id' => $transactionId,
            ':id' => $id
        ]);
    }
}