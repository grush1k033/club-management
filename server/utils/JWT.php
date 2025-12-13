<?php

require_once __DIR__ . '/../config/config.php';

class JWT {
    public static function generate($payload) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload['exp'] = time() + JWT_EXPIRE;
        $payload['iat'] = time();

        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256',
            $base64UrlHeader . "." . $base64UrlPayload,
            JWT_SECRET,
            true
        );

        $base64UrlSignature = self::base64UrlEncode($signature);

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    public static function verify($token) {
        try {
            error_log("JWT Verification attempt for token: " . substr($token, 0, 50) . "...");

            $tokenParts = explode('.', $token);
            if (count($tokenParts) != 3) {
                error_log("Invalid token format: " . count($tokenParts) . " parts");
                return false;
            }

            $header = self::base64UrlDecode($tokenParts[0]);
            $payload = self::base64UrlDecode($tokenParts[1]);
            $signatureProvided = $tokenParts[2];

            if ($header === false || $payload === false) {
                error_log("Failed to decode header or payload");
                return false;
            }

            $base64UrlHeader = self::base64UrlEncode($header);
            $base64UrlPayload = self::base64UrlEncode($payload);

            $signature = hash_hmac('sha256',
                $base64UrlHeader . "." . $base64UrlPayload,
                JWT_SECRET,
                true
            );

            $base64UrlSignature = self::base64UrlEncode($signature);

            if ($base64UrlSignature !== $signatureProvided) {
                error_log("Signature mismatch");
                return false;
            }

            $payloadData = json_decode($payload, true);

            if (!$payloadData) {
                error_log("Failed to decode payload JSON");
                return false;
            }

            if (isset($payloadData['exp']) && $payloadData['exp'] < time()) {
                error_log("Token expired");
                return false;
            }

            error_log("JWT Verification successful. Payload: " . print_r($payloadData, true));

            // Проверяем наличие id пользователя
            if (!isset($payloadData['id'])) {
                error_log("WARNING: Token payload doesn't contain 'id' field");
                // Можно добавить проверку на другие возможные ключи
                if (isset($payloadData['user_id'])) {
                    $payloadData['id'] = $payloadData['user_id'];
                } elseif (isset($payloadData['userId'])) {
                    $payloadData['id'] = $payloadData['userId'];
                }
            }

            return $payloadData;

        } catch (Exception $e) {
            error_log("JWT Exception: " . $e->getMessage());
            return false;
        }
    }

    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

?>