<?php
require_once __DIR__ . '/../utils/JWT.php';
require_once __DIR__ . '/../utils/Response.php';

class AuthMiddleware {

    private static function getHeaders() {
        // Универсальный способ получить заголовки
        $headers = [];

        // Если функция getallheaders доступна
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        }

        // Альтернативный способ для Apache
        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) == 'HTTP_') {
                $headerKey = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$headerKey] = $value;
            }
        }

        return $headers;
    }

    public static function authenticate() {
        // Получаем заголовки
        $headers = self::getHeaders();

        error_log("=== AUTH MIDDLEWARE DEBUG ===");
        error_log("All headers: " . json_encode($headers));

        // Проверяем разные варианты заголовка с токеном
        $token = '';

        // 1. Проверяем X-Auth-Token (ваш текущий формат)
        if (isset($headers['X-Auth-Token'])) {
            $token = $headers['X-Auth-Token'];
            error_log("Found token in X-Auth-Token");
        }
        // 2. Проверяем Authorization header (стандартный)
        elseif (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
            $token = str_replace('Bearer ', '', $authHeader);
            error_log("Found token in Authorization");
        }
        // 3. Проверяем authorization (нижний регистр)
        elseif (isset($headers['authorization'])) {
            $authHeader = $headers['authorization'];
            $token = str_replace('Bearer ', '', $authHeader);
            error_log("Found token in authorization");
        }
        // 4. Проверяем HTTP_AUTHORIZATION (серверная переменная)
        elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
            $token = str_replace('Bearer ', '', $authHeader);
            error_log("Found token in HTTP_AUTHORIZATION");
        }
        // 5. Проверяем REDIRECT_HTTP_AUTHORIZATION
        elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            $token = str_replace('Bearer ', '', $authHeader);
            error_log("Found token in REDIRECT_HTTP_AUTHORIZATION");
        }

        error_log("Extracted token: " . ($token ? substr($token, 0, 20) . "..." : "EMPTY"));

        if (empty($token)) {
            Response::error('Токен доступа не предоставлен', [
                'debug_info' => [
                    'all_headers' => $headers,
                    'server_keys' => array_keys($_SERVER),
                    'received_token' => $token
                ]
            ], 401);
        }

        $payload = JWT::verify($token);
        if (!$payload) {
            Response::unauthorized('Недействительный токен');
        }

        error_log("Token verified for user: " . $payload['email']);
        return $payload;
    }

    public static function requireRole($requiredRole) {
        $payload = self::authenticate();

        if ($payload['role'] !== $requiredRole && $payload['role'] !== 'admin') {
            Response::forbidden('Недостаточно прав. Требуется роль: ' . $requiredRole);
        }

        return $payload;
    }

    public static function requireRoles($requiredRoles) {
        $payload = self::authenticate();

        if (!in_array($payload['role'], $requiredRoles) && $payload['role'] !== 'admin') {
            Response::forbidden('Недостаточно прав. Требуемые роли: ' . implode(', ', $requiredRoles));
        }

        return $payload;
    }
}
?>