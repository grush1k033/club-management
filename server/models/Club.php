<?php
// server/models/Club.php

require_once __DIR__ . '/../utils/Response.php';

class Club {
    private $conn;
    private $table = "clubs";

    // Свойства клуба
    public $id;
    public $name;
    public $status;
    public $description;
    public $category;
    public $email;
    public $phone;
    public $captain_id;
    public $vice_captain_id;
    public $created_at;
    public $updated_at;

    // Дополнительные данные
    public $captain_name;
    public $vice_captain_name;

    // Параметры для фильтрации (пагинации НЕТ)
    public $search = '';
    public $category_filter = '';
    public $status_filter = '';

    public function __construct($db) {
        $this->conn = $db;
    }

    // Получить ВСЕ клубы с фильтрацией
    public function readAll() {
        $query = "SELECT c.*, 
                  CONCAT(u1.first_name, ' ', u1.last_name) as captain_name,
                  CONCAT(u2.first_name, ' ', u2.last_name) as vice_captain_name
                  FROM " . $this->table . " c
                  LEFT JOIN users u1 ON c.captain_id = u1.id
                  LEFT JOIN users u2 ON c.vice_captain_id = u2.id
                  WHERE 1=1";

        $conditions = [];
        $params = [];

        // Поиск
        if (!empty($this->search)) {
            $conditions[] = "(c.name LIKE :search OR c.description LIKE :search_desc)";
            $searchTerm = "%" . $this->search . "%";
            $params[':search'] = $searchTerm;
            $params[':search_desc'] = $searchTerm;
        }

        // Фильтр по категории
        if (!empty($this->category_filter)) {
            $conditions[] = "c.category = :category";
            $params[':category'] = $this->category_filter;
        }

        // Фильтр по статусу
        if (!empty($this->status_filter)) {
            $conditions[] = "c.status = :status";
            $params[':status'] = $this->status_filter;
        }

        if (!empty($conditions)) {
            $query .= " AND " . implode(" AND ", $conditions);
        }

        // Сортировка
        $query .= " ORDER BY c.created_at DESC";

        $stmt = $this->conn->prepare($query);

        // Привязываем параметры
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Получить общее количество
    public function countAll() {
        $query = "SELECT COUNT(*) as total FROM " . $this->table . " WHERE 1=1";

        $conditions = [];
        $params = [];

        if (!empty($this->search)) {
            $conditions[] = "(name LIKE :search OR description LIKE :search_desc)";
            $searchTerm = "%" . $this->search . "%";
            $params[':search'] = $searchTerm;
            $params[':search_desc'] = $searchTerm;
        }

        if (!empty($this->category_filter)) {
            $conditions[] = "category = :category";
            $params[':category'] = $this->category_filter;
        }

        if (!empty($this->status_filter)) {
            $conditions[] = "status = :status";
            $params[':status'] = $this->status_filter;
        }

        if (!empty($conditions)) {
            $query .= " AND " . implode(" AND ", $conditions);
        }

        $stmt = $this->conn->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total'] || 0);
    }

    // Получить клуб по ID
    public function readOne() {
        $query = "SELECT c.*, 
                  CONCAT(u1.first_name, ' ', u1.last_name) as captain_name,
                  CONCAT(u2.first_name, ' ', u2.last_name) as vice_captain_name
                  FROM " . $this->table . " c
                  LEFT JOIN users u1 ON c.captain_id = u1.id
                  LEFT JOIN users u2 ON c.vice_captain_id = u2.id
                  WHERE c.id = :id LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $this->id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->name = $row['name'];
            $this->status = $row['status'];
            $this->description = $row['description'];
            $this->category = $row['category'];
            $this->email = $row['email'];
            $this->phone = $row['phone'];
            $this->captain_id = $row['captain_id'];
            $this->vice_captain_id = $row['vice_captain_id'];
            $this->created_at = $row['created_at'];
            $this->updated_at = $row['updated_at'];
            $this->captain_name = $row['captain_name'];
            $this->vice_captain_name = $row['vice_captain_name'];

            return true;
        }

        return false;
    }

    public function create() {
        $query = "INSERT INTO " . $this->table . " 
              SET name = :name, status = :status, description = :description, 
                  category = :category, email = :email, phone = :phone, 
                  captain_id = :captain_id, vice_captain_id = :vice_captain_id";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':status', $this->status);
        $stmt->bindParam(':description', $this->description);
        $stmt->bindParam(':category', $this->category);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':phone', $this->phone);
        $stmt->bindParam(':captain_id', $this->captain_id);

        // Для vice_captain_id нужно специально обрабатывать null
        if ($this->vice_captain_id === null) {
            $stmt->bindValue(':vice_captain_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindParam(':vice_captain_id', $this->vice_captain_id);
        }

        if ($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            return true;
        }

        return false;
    }

    // Обновить клуб
    public function update() {
        $query = "UPDATE " . $this->table . " 
                  SET name = :name, status = :status, description = :description, 
                      category = :category, email = :email, phone = :phone, 
                      captain_id = :captain_id, vice_captain_id = :vice_captain_id,
                      updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':status', $this->status);
        $stmt->bindParam(':description', $this->description);
        $stmt->bindParam(':category', $this->category);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':phone', $this->phone);
        $stmt->bindParam(':captain_id', $this->captain_id);
        $stmt->bindParam(':vice_captain_id', $this->vice_captain_id);
        $stmt->bindParam(':id', $this->id);

        return $stmt->execute();
    }

    // Удалить клуб
    public function delete() {
        $query = "DELETE FROM " . $this->table . " WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $this->id);

        return $stmt->execute();
    }

    // Переключить статус
    public function toggleStatus() {
        $query = "UPDATE " . $this->table . " 
                  SET status = CASE 
                      WHEN status = 'Active' THEN 'Inactive' 
                      ELSE 'Active' 
                  END,
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $this->id);

        return $stmt->execute();
    }

    // Поиск клубов
    public function search($searchTerm) {
        $query = "SELECT c.*, 
                  CONCAT(u1.first_name, ' ', u1.last_name) as captain_name,
                  CONCAT(u2.first_name, ' ', u2.last_name) as vice_captain_name
                  FROM " . $this->table . " c
                  LEFT JOIN users u1 ON c.captain_id = u1.id
                  LEFT JOIN users u2 ON c.vice_captain_id = u2.id
                  WHERE c.name LIKE :search OR c.description LIKE :search_desc 
                  OR c.category LIKE :search_cat OR c.email LIKE :search_email
                  ORDER BY c.created_at DESC
                  LIMIT 20";

        $stmt = $this->conn->prepare($query);
        $searchPattern = "%" . $searchTerm . "%";
        $stmt->bindParam(':search', $searchPattern);
        $stmt->bindParam(':search_desc', $searchPattern);
        $stmt->bindParam(':search_cat', $searchPattern);
        $stmt->bindParam(':search_email', $searchPattern);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Получить клубы по категории
    public function getByCategory($category) {
        $query = "SELECT c.*, 
                  CONCAT(u1.first_name, ' ', u1.last_name) as captain_name,
                  CONCAT(u2.first_name, ' ', u2.last_name) as vice_captain_name
                  FROM " . $this->table . " c
                  LEFT JOIN users u1 ON c.captain_id = u1.id
                  LEFT JOIN users u2 ON c.vice_captain_id = u2.id
                  WHERE c.category = :category 
                  ORDER BY c.created_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':category', $category);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Получить уникальные категории
    public function getCategories() {
        $query = "SELECT DISTINCT category, COUNT(*) as club_count 
                  FROM " . $this->table . " 
                  WHERE status = 'Active'
                  GROUP BY category 
                  ORDER BY category";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}