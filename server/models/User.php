<?php
require_once __DIR__ . '/../utils/Response.php';

class User {
    private $conn;
    private $table = "users";

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

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create() {
        $query = "INSERT INTO " . $this->table . " 
                 SET email = :email, password = :password, first_name = :first_name, 
                     last_name = :last_name, phone = :phone, role = :role";

        $stmt = $this->conn->prepare($query);

        $this->password = password_hash($this->password, PASSWORD_DEFAULT);

        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":password", $this->password);
        $stmt->bindParam(":first_name", $this->first_name);
        $stmt->bindParam(":last_name", $this->last_name);
        $stmt->bindParam(":phone", $this->phone);
        $stmt->bindParam(":role", $this->role);

        if($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            return true;
        }
        return false;
    }

    public function findByEmail($email) {
        error_log("=== FIND BY EMAIL ===");
        error_log("Received email parameter: " . $email); // ДОБАВЬ ЭТУ СТРОКУ

        $query = "SELECT * FROM " . $this->table . " WHERE email = ? LIMIT 0,1";
        $stmt = $this->conn->prepare($query);

        // ИСПРАВЬ bindParam на bindValue
        $stmt->bindValue(1, $email, PDO::PARAM_STR);

        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        error_log("Query: " . $query);
        error_log("Email parameter: " . $email);
        error_log("Row count: " . $stmt->rowCount());
        error_log("Result: " . json_encode($result));

        return $result;
    }

    public function findById($id) {
        $query = "SELECT id, email, first_name, last_name, phone, role, created_at 
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

    public function toArray() {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'role' => $this->role,
            'created_at' => $this->created_at
        ];
    }
}
?>