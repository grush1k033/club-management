<?php
require_once __DIR__ . '/../utils/JWT.php';
require_once __DIR__ . '/../utils/Response.php';

class AuthMiddleware {
    public static function authenticate() {
        // Простой способ получить заголовок
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] || '';

        // Если не нашли, пробуем другой вариант
        if (empty($authHeader)) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] || $headers['authorization'] || '';
        }

        // ВРЕМЕННО: всегда показываем какие заголовки пришли
        if (empty($authHeader)) {
            $allHeaders = getallheaders();
            Response::error('Токен доступа не предоставлен', [
                'debug_info' => [
                    'all_headers' => $allHeaders,
                    'server_auth' => $_SERVER['HTTP_AUTHORIZATION'] || 'NOT_FOUND',
                    'received_auth' => $authHeader
                ]
            ], 401);
        }

        // Извлекаем токен из заголовка
        $token = str_replace('Bearer ', '', $authHeader);

        $payload = JWT::verify($token);
        if (!$payload) {
            Response::unauthorized('Недействительный токен');
        }

        return $payload;
    }

    public static function requireRole($requiredRole) {
        $payload = self::authenticate();

        if ($payload['role'] !== $requiredRole && $payload['role'] !== 'admin') {
            Response::forbidden('Недостаточно прав');
        }

        return $payload;
    }

    public static function requireRoles($requiredRoles) {
        $payload = self::authenticate();

        if (!in_array($payload['role'], $requiredRoles) && $payload['role'] !== 'admin') {
            Response::forbidden('Недостаточно прав');
        }

        return $payload;
    }
}
?>