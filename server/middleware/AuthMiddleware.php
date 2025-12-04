<?php
require_once __DIR__ . '/../utils/JWT.php';
require_once __DIR__ . '/../utils/Response.php';

class AuthMiddleware {

    private static function getHeaders() {
        $headers = [];

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        }

        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) == 'HTTP_') {
                $headerKey = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$headerKey] = $value;
            }
        }

        return $headers;
    }

    public static function authenticate() {
        $headers = self::getHeaders();
        $token = '';

        if (isset($headers['X-Auth-Token'])) {
            $token = $headers['X-Auth-Token'];
        } elseif (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
            $token = str_replace('Bearer ', '', $authHeader);
        } elseif (isset($headers['authorization'])) {
            $authHeader = $headers['authorization'];
            $token = str_replace('Bearer ', '', $authHeader);
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
            $token = str_replace('Bearer ', '', $authHeader);
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            $token = str_replace('Bearer ', '', $authHeader);
        }

        if (empty($token)) {
            Response::error('Токен доступа не предоставлен', [], 401);
        }

        $payload = JWT::verify($token);
        if (!$payload) {
            Response::unauthorized('Недействительный токен');
        }

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