<?php
// server/middleware/AdminMiddleware.php

require_once __DIR__ . '/../utils/JWT.php';
require_once __DIR__ . '/../utils/Response.php';

class AdminMiddleware {
    private $response;

    public function __construct($db) {
        $this->response = new Response();
    }

    public function handle($req) {
        // Используем статический метод из AuthMiddleware
        $payload = AuthMiddleware::requireRole('admin');

        // Добавляем информацию о пользователе в запрос
        $req['user'] = $payload;
        return $req;
    }
}