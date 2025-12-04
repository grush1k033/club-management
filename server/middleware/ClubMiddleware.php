<?php
// server/middleware/ClubMiddleware.php

require_once __DIR__ . '/../utils/Response.php';

class ClubMiddleware {
    private $response;
    private $clubModel;

    public function __construct($db) {
        $this->response = new Response();
        require_once __DIR__ . '/../models/Club.php';
        $this->clubModel = new Club($db);
    }

    public function handle($req, $options = []) {
        $userId = $req['user']['id'] || null;
        $clubId = $req['params']['id'] || null;

        if (!$clubId || !$userId) {
            $this->response->error('Доступ запрещен', [], 403)->send();
            exit;
        }

        // Получаем информацию о клубе
        $club = $this->clubModel->getById($clubId);

        if (!$club) {
            $this->response->error('Клуб не найден', [], 404)->send();
            exit;
        }

        $isOwner = $club['owner_id'] == $userId;
        $isAdmin = $req['user']['role'] == 'admin';

        // Проверяем права в зависимости от опций
        if (isset($options['owner']) && $options['owner'] && !$isOwner && !$isAdmin) {
            $this->response->error('Только владелец клуба может выполнить это действие', [], 403)->send();
            exit;
        }

        if (isset($options['admin']) && $options['admin'] && !$isAdmin) {
            $this->response->error('Требуются права администратора', [], 403)->send();
            exit;
        }

        // Добавляем информацию о клубе в запрос для дальнейшего использования
        $req['club'] = $club;
        $req['user']['is_club_owner'] = $isOwner;

        return $req;
    }
}