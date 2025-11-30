<?php
class Validator {
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function validatePassword($password) {
        return strlen($password) >= 6;
    }

    public static function validateName($name) {
        return !empty(trim($name)) && strlen(trim($name)) >= 2;
    }

    public static function validatePhone($phone) {
        return preg_match('/^\+?[0-9\s\-\(\)]{10,}$/', $phone);
    }

    public static function validateRegistration($data) {
        $errors = [];

        if (!isset($data['email']) || !self::validateEmail($data['email'])) {
            $errors['email'] = 'Введите корректный email';
        }

        if (!isset($data['password']) || !self::validatePassword($data['password'])) {
            $errors['password'] = 'Пароль должен содержать минимум 6 символов';
        }

        if (!isset($data['first_name']) || !self::validateName($data['first_name'])) {
            $errors['first_name'] = 'Имя должно содержать минимум 2 символа';
        }

        if (!isset($data['last_name']) || !self::validateName($data['last_name'])) {
            $errors['last_name'] = 'Фамилия должна содержать минимум 2 символа';
        }

        if (isset($data['phone']) && !empty($data['phone']) && !self::validatePhone($data['phone'])) {
            $errors['phone'] = 'Введите корректный номер телефона';
        }

        return $errors;
    }

    public static function validateLogin($data) {
        $errors = [];

        if (!isset($data['email']) || empty($data['email'])) {
            $errors['email'] = 'Email обязателен для заполнения';
        }

        if (!isset($data['password']) || empty($data['password'])) {
            $errors['password'] = 'Пароль обязателен для заполнения';
        }

        return $errors;
    }
}
?>