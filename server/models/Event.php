<?php
require_once __DIR__ . '/../utils/Response.php';

class Event
{
    private $db;
    private $table = 'events';

    public function __construct($db)
    {
        $this->db = $db;
    }


    /**
     * Получить мероприятия клуба
     */
    public function getClubEvents($club_id)
    {
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
    public function getById($id)
    {
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
    public function isUserParticipant($event_id, $user_id)
    {
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
    public function getUserEvents($user_id, $status = null)
    {
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
    public function updateStatus($id, $status)
    {
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
    public function update($id, $data)
    {
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
    public function delete($id)
    {
        $query = "DELETE FROM events WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);

        return $stmt->execute();
    }

    // В models/Event.php добавьте:

    public function getEventsByUserId($userId)
    {
        $query = "SELECT e.*, 
                         c.name as club_name,
                         c.id as club_id,
                         ep.status as participation_status,
                         ep.registration_date,
                         ep.attended_at
                  FROM events e
                  INNER JOIN event_participants ep ON e.id = ep.event_id
                  INNER JOIN clubs c ON e.club_id = c.id
                  WHERE ep.user_id = :user_id
                  ORDER BY e.event_date DESC";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Получить предстоящие мероприятия по ID пользователя
    public function getUpcomingEventsByUserId($userId)
    {
        $query = "SELECT e.*, 
                         c.name as club_name,
                         c.id as club_id,
                         ep.status as participation_status,
                         ep.registration_date
                  FROM events e
                  INNER JOIN event_participants ep ON e.id = ep.event_id
                  INNER JOIN clubs c ON e.club_id = c.id
                  WHERE ep.user_id = :user_id 
                    AND e.event_date >= NOW()
                    AND e.status IN ('scheduled', 'ongoing')
                    AND ep.status != 'cancelled'
                  ORDER BY e.event_date ASC";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Получить прошедшие мероприятия по ID пользователя
    public function getPastEventsByUserId($userId)
    {
        $query = "SELECT e.*, 
                         c.name as club_name,
                         c.id as club_id,
                         ep.status as participation_status,
                         ep.registration_date,
                         ep.attended_at
                  FROM events e
                  INNER JOIN event_participants ep ON e.id = ep.event_id
                  INNER JOIN clubs c ON e.club_id = c.id
                  WHERE ep.user_id = :user_id 
                    AND (e.event_date < NOW() OR e.status IN ('completed', 'cancelled'))
                  ORDER BY e.event_date DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

?>