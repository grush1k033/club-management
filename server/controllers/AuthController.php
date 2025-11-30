<?php
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../utils/JWT.php';
require_once __DIR__ . '/../utils/Validator.php';
require_once __DIR__ . '/../utils/Response.php';

class AuthController
{
    private $userModel;

    public function __construct($db)
    {
        $this->userModel = new User($db);
    }

    public function register()
    {
        $rawInput = file_get_contents('php://input');

        // Парсим данные вручную
        $email = '';
        $password = '';
        $firstName = '';
        $lastName = '';
        $phone = '';
        $role = 'member';

        if (preg_match('/"email"\s*:\s*"([^"]+)"/', $rawInput, $matches)) {
            $email = $matches[1];
        }
        if (preg_match('/"password"\s*:\s*"([^"]+)"/', $rawInput, $matches)) {
            $password = $matches[1];
        }
        if (preg_match('/"first_name"\s*:\s*"([^"]+)"/', $rawInput, $matches)) {
            $firstName = $matches[1];
        }
        if (preg_match('/"last_name"\s*:\s*"([^"]+)"/', $rawInput, $matches)) {
            $lastName = $matches[1];
        }
        if (preg_match('/"phone"\s*:\s*"([^"]+)"/', $rawInput, $matches)) {
            $phone = $matches[1];
        }
        if (preg_match('/"role"\s*:\s*"([^"]+)"/', $rawInput, $matches)) {
            $role = $matches[1];
        }

        // Валидация
        $errors = [];

        if (empty($email)) {
            $errors['email'] = 'Email обязателен';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Введите корректный email';
        }

        if (empty($password)) {
            $errors['password'] = 'Пароль обязателен';
        } elseif (strlen($password) < 6) {
            $errors['password'] = 'Пароль должен содержать минимум 6 символов';
        }

        if (empty($firstName)) {
            $errors['first_name'] = 'Имя обязательно';
        }

        if (empty($lastName)) {
            $errors['last_name'] = 'Фамилия обязательна';
        }

        if (!empty($errors)) {
            Response::error('Ошибки валидации', $errors, 422);
        }

        // Проверяем существование пользователя
        if ($this->userModel->findByEmail($email)) {
            Response::error('Пользователь с таким email уже существует', null, 409);
        }

        // Создаем пользователя
        $this->userModel->email = $email;
        $this->userModel->password = $password;
        $this->userModel->first_name = $firstName;
        $this->userModel->last_name = $lastName;
        $this->userModel->phone = $phone;
        $this->userModel->role = $role;

        if ($this->userModel->create()) {
            $user = $this->userModel->findById($this->userModel->id);

            // Генерируем JWT токен
            $token = JWT::generate([
                'user_id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role']
            ]);

            Response::success('Пользователь успешно зарегистрирован', [
                'user' => $user,
                'token' => $token
            ], 201);
        }

        Response::error('Ошибка при регистрации пользователя');
    }

    public function login() {
        $rawInput = file_get_contents('php://input');

        // Парсим данные вручную (оставляем из-за бага с json_decode)
        $email = '';
        $password = '';

        if (preg_match('/"email"\s*:\s*"([^"]+)"/', $rawInput, $matches)) {
            $email = $matches[1];
        }

        if (preg_match('/"password"\s*:\s*"([^"]+)"/', $rawInput, $matches)) {
            $password = $matches[1];
        }

        // Валидация
        $errors = [];

        if (empty($email)) {
            $errors['email'] = 'Email обязателен';
        }

        if (empty($password)) {
            $errors['password'] = 'Пароль обязателен';
        }

        if (!empty($errors)) {
            Response::error('Ошибки валидации', $errors, 422);
        }

        // Ищем пользователя
        $user = $this->userModel->findByEmail($email);
        if (!$user) {
            Response::error('Неверный email или пароль', null, 401);
        }

        // Проверяем пароль
        if (!$this->userModel->verifyPassword($password, $user['password'])) {
            Response::error('Неверный email или пароль', null, 401);
        }

        // Генерируем JWT токен
        $token = JWT::generate([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role']
        ]);

        // Возвращаем данные пользователя (без пароля)
        unset($user['password']);

        Response::success('Успешный вход в систему', [
            'user' => $user,
            'token' => $token
        ]);
    }

    public function me()
    {
        try {
            // Получаем токен из кастомного заголовка
            $token = '';

            if (isset($_SERVER['HTTP_X_AUTH_TOKEN'])) {
                $token = $_SERVER['HTTP_X_AUTH_TOKEN'];
            } elseif (isset($_SERVER['HTTP_X_AUTHORIZATION'])) {
                $token = $_SERVER['HTTP_X_AUTHORIZATION'];
            }

            if (empty($token)) {
                Response::error('Токен не предоставлен', [], 401);
            }

            // Проверяем токен
            $payload = JWT::verify($token);

            if (!$payload) {
                Response::error('Неверный токен', [], 401);
            }

            // Ищем пользователя
            $user = $this->userModel->findById($payload['user_id']);

            if (!$user) {
                Response::error('Пользователь не найден', [], 404);
            }

            // Возвращаем данные
            unset($user['password']);
            Response::success('Данные пользователя', ['user' => $user]);

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage(), [], 500);
        }
    }
    public function refresh()
    {
        try {
            // Получаем токен из кастомного заголовка
            $token = '';

            if (isset($_SERVER['HTTP_X_AUTH_TOKEN'])) {
                $token = $_SERVER['HTTP_X_AUTH_TOKEN'];
            } elseif (isset($_SERVER['HTTP_X_AUTHORIZATION'])) {
                $token = $_SERVER['HTTP_X_AUTHORIZATION'];
            }

            if (empty($token)) {
                Response::error('Токен не предоставлен', [], 401);
            }

            // Проверяем токен
            $payload = JWT::verify($token);

            if (!$payload) {
                Response::error('Недействительный токен', [], 401);
            }

            // Генерируем новый токен
            $newToken = JWT::generate([
                'user_id' => $payload['user_id'],
                'email' => $payload['email'],
                'role' => $payload['role']
            ]);

            Response::success('Токен обновлен', ['token' => $newToken]);

        } catch (Exception $e) {
            Response::error('Ошибка: ' . $e->getMessage(), [], 500);
        }
    }
}
?>