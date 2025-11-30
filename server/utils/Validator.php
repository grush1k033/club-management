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

    public static function validate($data, $rules) {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $rulesList = explode('|', $ruleString);

            foreach ($rulesList as $rule) {
                // Проверка required
                if ($rule === 'required') {
                    if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                        $errors[$field][] = "Поле $field обязательно для заполнения";
                    }
                }

                // Проверка string
                if ($rule === 'string') {
                    if (isset($data[$field]) && !is_string($data[$field])) {
                        $errors[$field][] = "Поле $field должно быть строкой";
                    }
                }

                // Проверка min
                if (strpos($rule, 'min:') === 0) {
                    $min = (int) str_replace('min:', '', $rule);
                    if (isset($data[$field]) && strlen($data[$field]) < $min) {
                        $errors[$field][] = "Поле $field должно содержать минимум $min символов";
                    }
                }

                // Проверка max
                if (strpos($rule, 'max:') === 0) {
                    $max = (int) str_replace('max:', '', $rule);
                    if (isset($data[$field]) && strlen($data[$field]) > $max) {
                        $errors[$field][] = "Поле $field должно содержать максимум $max символов";
                    }
                }

                // Проверка in (допустимые значения)
                if (strpos($rule, 'in:') === 0) {
                    $allowed = explode(',', str_replace('in:', '', $rule));
                    if (isset($data[$field]) && !in_array($data[$field], $allowed)) {
                        $errors[$field][] = "Поле $field должно быть одним из: " . implode(', ', $allowed);
                    }
                }

                // Проверка boolean
                if ($rule === 'boolean') {
                    if (isset($data[$field]) && !self::validateBoolean($data[$field])) {
                        $errors[$field][] = "Поле $field должно быть логическим значением";
                    }
                }
            }
        }

        return empty($errors) ? false : $errors;
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

    public static function validateNumber($value) {
        return is_numeric($value) && $value > 0;
    }

    public static function validateBoolean($value) {
        return is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false']);
    }
}
?>