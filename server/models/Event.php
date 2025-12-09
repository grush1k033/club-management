<?php
class Event {
    private $db;
    private $table = 'events';

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Создать мероприятие
     */
    /**
     * Создать мероприятие
     */
    public function create($data) {
        // Подготавливаем значения перед bindParam
        $club_id = $data['club_id'] || null;
        $title = $data['title'] || '';
        $description = $data['description'] || '';
        $event_date = $data['event_date'] || null;
        $location = $data['location'] || '';
        $status = $data['status'] || 'scheduled';
        $max_participants = $data['max_participants'] || null;
        $created_by = $data['created_by'] || null;

        $query = "INSERT INTO " . $this->table . " 
              SET club_id = :club_id, 
                  title = :title, 
                  description = :description, 
                  event_date = :event_date, 
                  location = :location, 
                  status = :status,
                  max_participants = :max_participants,
                  created_by = :created_by";

        $stmt = $this->db->prepare($query);

        // Привязка параметров (важно: передаем переменные, а не выражения)
        $stmt->bindParam(':club_id', $club_id);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':event_date', $event_date);
        $stmt->bindParam(':location', $location);
        $stmt->bindParam(':status', $status);

        if ($max_participants !== null) {
            $stmt->bindParam(':max_participants', $max_participants, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':max_participants', null, PDO::PARAM_NULL);
        }

        $stmt->bindParam(':created_by', $created_by);

        return $stmt->execute();
    }

    /**
     * Получить мероприятия клуба
     */
    public function getClubEvents($club_id) {
        $query = "SELECT e.*, 
                  COUNT(ep.id) as participants_count,
                  (SELECT COUNT(*) FROM event_participants WHERE event_id = e.id AND status = 'attended') as attended_count
                  FROM " . $this->table . " e
                  LEFT JOIN event_participants ep ON e.id = ep.event_id
                  WHERE e.club_id = :club_id
                  GROUP BY e.id
                  ORDER BY e.event_date DESC";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':club_id', $club_id);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить мероприятие по ID
     */
    public function getById($id) {
        $query = "SELECT e.*, c.name as club_name, 
                  u.first_name as creator_first_name, u.last_name as creator_last_name
                  FROM " . $this->table . " e
                  LEFT JOIN clubs c ON e.club_id = c.id
                  LEFT JOIN users u ON e.created_by = u.id
                  WHERE e.id = :id";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Проверить, является ли пользователь участником мероприятия
     */
    public function isUserParticipant($event_id, $user_id) {
        $query = "SELECT id FROM event_participants 
                  WHERE event_id = :event_id AND user_id = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':event_id', $event_id);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Получить мероприятия пользователя
     */
    public function getUserEvents($user_id, $status = null) {
        $query = "SELECT e.*, c.name as club_name, ep.status as participation_status
                  FROM events e
                  JOIN event_participants ep ON e.id = ep.event_id
                  JOIN clubs c ON e.club_id = c.id
                  WHERE ep.user_id = :user_id";

        if ($status) {
            $query .= " AND ep.status = :status";
        }

        $query .= " ORDER BY e.event_date DESC";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);

        if ($status) {
            $stmt->bindParam(':status', $status);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Обновить статус мероприятия
     */
    public function updateStatus($id, $status) {
        $allowedStatuses = ['scheduled', 'ongoing', 'completed', 'cancelled'];

        if (!in_array($status, $allowedStatuses)) {
            return false;
        }

        $query = "UPDATE events SET status = :status, updated_at = CURRENT_TIMESTAMP 
                  WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $id);

        return $stmt->execute();
    }

    /**
     * Обновить мероприятие
     */
    public function update($id, $data) {
        $fields = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            if (in_array($key, ['title', 'description', 'event_date', 'location', 'status', 'max_participants'])) {
                $fields[] = "$key = :$key";
                $params[":$key"] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = CURRENT_TIMESTAMP";
        $query = "UPDATE events SET " . implode(', ', $fields) . " WHERE id = :id";

        $stmt = $this->db->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        return $stmt->execute();
    }

    /**
     * Удалить мероприятие
     */
    public function delete($id) {
        $query = "DELETE FROM events WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);

        return $stmt->execute();
    }
}
?>