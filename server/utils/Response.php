<?php
class Response {
    public static function success($message = '', $data = null, $code = 200) {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ]);
        exit;
    }

    public static function error($message = '', $errors = null, $code = 400) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'timestamp' => time()
        ]);
        exit;
    }

    public static function unauthorized($message = 'Неавторизованный доступ') {
        self::error($message, null, 401);
    }

    public static function forbidden($message = 'Доступ запрещен') {
        self::error($message, null, 403);
    }

    public static function notFound($message = 'Ресурс не найден') {
        self::error($message, null, 404);
    }
}
?>