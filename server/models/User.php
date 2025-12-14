<?php
require_once __DIR__ . '/../utils/Response.php';

class User {
    private $conn;
    private $table = "users";

    // Существующие свойства
    public $id;
    public $email;
    public $password;
    public $first_name;
    public $last_name;
    public $phone;
    public $role;
    public $is_active;
    public $created_at;
    public $updated_at;

    public $balance;
    public $currency;
    public $club_id;

    public function __construct($db) {
        $this->conn = $db;
        // Устанавливаем значения по умолчанию для новых полей
        $this->balance = 0.00;
        $this->currency = 'USD';
        $this->club_id = null;
    }

    public function create() {
        $query = "INSERT INTO " . $this->table . " 
                 SET email = :email, 
                     password = :password, 
                     first_name = :first_name, 
                     last_name = :last_name, 
                     phone = :phone, 
                     role = :role,
                     balance = :balance,
                     currency = :currency,
                     club_id = :club_id";

        $stmt = $this->conn->prepare($query);

        $this->password = password_hash($this->password, PASSWORD_DEFAULT);

        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":password", $this->password);
        $stmt->bindParam(":first_name", $this->first_name);
        $stmt->bindParam(":last_name", $this->last_name);
        $stmt->bindParam(":phone", $this->phone);
        $stmt->bindParam(":role", $this->role);
        $stmt->bindParam(":balance", $this->balance);
        $stmt->bindParam(":currency", $this->currency);
        $stmt->bindParam(":club_id", $this->club_id);

        if($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            return true;
        }
        return false;
    }

    public function findByEmail($email) {
        $query = "SELECT * FROM " . $this->table . " WHERE email = ? LIMIT 0,1";
        $stmt = $this->conn->prepare($query);

        $stmt->bindValue(1, $email, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findById($id) {
        $query = "SELECT id, email, first_name, last_name, phone, role, 
                         balance, currency, club_id, created_at 
                  FROM " . $this->table . " 
                  WHERE id = ? AND is_active = 1 LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $id);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function verifyPassword($password, $hashedPassword) {
        return password_verify($password, $hashedPassword);
    }


    public function getAll() {
        $query = "SELECT id, email, first_name, last_name, phone, role, 
                     balance, currency, club_id, is_active, created_at 
              FROM " . $this->table . " 
              ORDER BY created_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

// Получить общее количество пользователей (можно оставить для статистики)
    public function getTotalCount() {
        $query = "SELECT COUNT(*) as total FROM " . $this->table;
        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'];
    }

    // Обновить пользователя
    // В классе User:

// PUT - Полное обновление (все поля)
    public function update() {
        $query = "UPDATE " . $this->table . " 
             SET first_name = :first_name, 
                 last_name = :last_name, 
                 phone = :phone, 
                 role = :role, 
                 is_active = :is_active,
                 balance = :balance,
                 currency = :currency,
                 club_id = :club_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":first_name", $this->first_name);
        $stmt->bindParam(":last_name", $this->last_name);
        $stmt->bindParam(":phone", $this->phone);
        $stmt->bindParam(":role", $this->role);
        $stmt->bindParam(":is_active", $this->is_active);
        $stmt->bindParam(":balance", $this->balance);
        $stmt->bindParam(":currency", $this->currency);
        $stmt->bindParam(":club_id", $this->club_id);
        $stmt->bindParam(":id", $this->id);

        return $stmt->execute();
    }

// PATCH - Частичное обновление
    public function partialUpdate($data) {
        $fields = [];
        $params = [':id' => $this->id];

        $allowedFields = ['first_name', 'last_name', 'phone', 'role', 'is_active', 'balance', 'currency', 'club_id'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = CURRENT_TIMESTAMP";
        $query = "UPDATE " . $this->table . " SET " . implode(", ", $fields) . " WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        return $stmt->execute($params);
    }

    // Удалить пользователя (мягкое удаление)
    public function delete($id) {
        $query = "UPDATE " . $this->table . " 
                 SET is_active = 0, updated_at = CURRENT_TIMESTAMP 
                 WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id);

        return $stmt->execute();
    }

    // Получить пользователя по ID (для админа - все данные)
    public function getByIdForAdmin($id) {
        $query = "SELECT id, email, first_name, last_name, phone, role, 
                         balance, currency, club_id, is_active, created_at, updated_at 
                  FROM " . $this->table . " 
                  WHERE id = ? LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $id);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ===== НОВЫЕ МЕТОДЫ ДЛЯ РАБОТЫ С БАЛАНСОМ И КЛУБАМИ =====

    // Обновить баланс пользователя
    public function updateBalance($userId, $amount, $transactionType = 'payment') {
        $query = "UPDATE " . $this->table . " 
                 SET balance = balance + :amount,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":amount", $amount);
        $stmt->bindParam(":id", $userId);

        return $stmt->execute();
    }

    // Получить баланс пользователя
    public function getBalance($userId) {
        $query = "SELECT balance, currency FROM " . $this->table . " WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $userId);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Привязать пользователя к клубу
    public function assignToClub($userId, $clubId) {
        $query = "UPDATE " . $this->table . " 
                 SET club_id = :club_id,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":club_id", $clubId);
        $stmt->bindParam(":id", $userId);

        return $stmt->execute();
    }











}
?>