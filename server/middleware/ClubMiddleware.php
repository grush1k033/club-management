<?php
// server/middleware/ClubMiddleware.php

require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../models/Club.php';

class ClubMiddleware {
    private $db;
    private $clubModel;

    public function __construct($db) {
        $this->db = $db;
        $this->clubModel = new Club($db);
    }

    public function handle($req, $options = []) {
        // Используем более надежный способ
        $userId = isset($req['user']['id']) ? $req['user']['id'] :
            (isset($req['user']['user_id']) ? $req['user']['user_id'] : null);
        $clubId = isset($req['params']['id']) ? $req['params']['id'] : null;

        if (!$clubId) {
            throw new Exception('Не указан ID клуба', 400);
        }

        if (!$userId) {
            throw new Exception('Пользователь не идентифицирован', 401);
        }

        // Получаем клуб - используем метод, который есть в вашей модели
        // Если есть метод getById:
        if (method_exists($this->clubModel, 'getById')) {
            $club = $this->clubModel->getById($clubId);
        }
        // Или если есть readOne():
        elseif (method_exists($this->clubModel, 'readOne')) {
            $this->clubModel->id = $clubId;
            $club = $this->clubModel->readOne();
        }
        // Или прямой запрос
        else {
            $query = "SELECT * FROM clubs WHERE id = :id LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':id', $clubId);
            $stmt->execute();
            $club = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // ПРОВЕРЯЕМ, что $club - массив, а не false/boolean
        if (!$club || !is_array($club)) {
            throw new Exception('Клуб не найден', 404);
        }

        // ПРОВЕРЯЕМ наличие ключей в массиве $club
        $isOwner = isset($club['captain_id']) && $club['captain_id'] == $userId;
        $isViceCaptain = isset($club['vice_captain_id']) && $club['vice_captain_id'] == $userId;
        $isAdmin = isset($req['user']['role']) && $req['user']['role'] == 'admin';

        // Проверяем права в зависимости от опций
        if (isset($options['owner']) && $options['owner'] && !$isOwner && !$isAdmin) {
            throw new Exception('Только капитан клуба или администратор может выполнить это действие', 403);
        }

        // Для мероприятий - капитан ИЛИ заместитель ИЛИ админ
        if (isset($options['captain_or_vice']) && $options['captain_or_vice']) {
            if (!$isOwner && !$isViceCaptain && !$isAdmin) {
                throw new Exception('Только капитан, заместитель клуба или администратор может выполнить это действие', 403);
            }
        }

        if (isset($options['admin']) && $options['admin'] && !$isAdmin) {
            throw new Exception('Требуются права администратора', 403);
        }

        // Добавляем информацию о клубе в запрос
        $req['club'] = $club;
        $req['user']['is_club_owner'] = $isOwner;
        $req['user']['is_club_vice_captain'] = $isViceCaptain;

        return $req;
    }
}