<?php
require_once 'config.php';

class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    public $conn;

    public function getConnection() {
        $this->conn = null;

        error_log("=== DATABASE CONNECTION ===");
        error_log("Host: " . $this->host);
        error_log("Database: " . $this->db_name);
        error_log("User: " . $this->username);

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            error_log("Database connected: SUCCESS");
        } catch(PDOException $exception) {
            error_log("Database connection: FAILED - " . $exception->getMessage());
            // В development показываем ошибку, в production - общую
            if (APP_ENV === 'local' || APP_ENV === 'development') {
                throw $exception;
            } else {
                throw new Exception('Database connection failed');
            }
        }
        return $this->conn;
    }
}
?>