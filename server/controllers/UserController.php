<?php

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';

class UserController
{
    private $user;
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
        $this->user = new User($db);
    }

    // GET /api/users - список пользователей (только админ)
    public function getAllUsers()
    {
        try {
            // Проверяем права администратора
            $payload = AuthMiddleware::requireRole('admin');

            // Получаем параметры пагинации
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

            // Валидация параметров
            if ($page < 1) $page = 1;
            if ($limit < 1 || $limit > 100) $limit = 10;

            // Получаем пользователей
            $users = $this->user->getAll($page, $limit);
            $total = $this->user->getTotalCount();

            Response::success('Users retrieved successfully', [
                'users' => $users,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    // GET /api/users/{id} - данные пользователя
    public function getUser($id)
    {
        try {
            // Аутентификация
            $payload = AuthMiddleware::authenticate();

            // Валидация ID
            if (!Validator::validateNumber($id)) {
                Response::error('Invalid user ID');
            }

            // Пользователь может получить свои данные, админ - любые
            if ($payload['role'] !== 'admin' && $payload['user_id'] != $id) {
                Response::forbidden('Access denied');
            }

            // Получаем данные пользователя
            if ($payload['role'] === 'admin') {
                $userData = $this->user->getByIdForAdmin($id);
            } else {
                $userData = $this->user->findById($id);
            }

            if (!$userData) {
                Response::error('User not found', [], 404);
            }

            Response::success('User retrieved successfully', $userData);

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    // PUT /api/users/{id} - обновление пользователя
    public function updateUser($id)
    {
        try {
            // Аутентификация
            $payload = AuthMiddleware::authenticate();

            // Валидация ID
            if (!Validator::validateNumber($id)) {
                Response::error('Invalid user ID');
            }

            // Пользователь может обновить только свои данные, админ - любые
            if ($payload['role'] !== 'admin' && $payload['user_id'] != $id) {
                Response::forbidden('Access denied');
            }

            // Получаем данные из запроса
            $data = json_decode(file_get_contents("php://input"), true);

            if (!$data) {
                Response::error('Invalid JSON data');
            }

            // Валидация данных
            $validationRules = [
                'first_name' => 'required|string|min:1|max:50',
                'last_name' => 'required|string|min:1|max:50',
                'phone' => 'string|max:20'
            ];

            // Админ может менять роль
            if ($payload['role'] === 'admin') {
                $validationRules['role'] = 'string|in:user,admin,manager';
                $validationRules['is_active'] = 'boolean';
            }

            $errors = Validator::validate($data, $validationRules);
            if ($errors) {
                Response::error('Validation failed', $errors, 400);
            }

            // Проверяем существование пользователя
            $existingUser = $this->user->getByIdForAdmin($id);
            if (!$existingUser) {
                Response::error('User not found', [], 404);
            }

            // Обновляем данные
            $this->user->id = $id;
            $this->user->first_name = $data['first_name'];
            $this->user->last_name = $data['last_name'];
            $this->user->phone = $data['phone'] || $existingUser['phone'];

            // Только админ может менять эти поля
            if ($payload['role'] === 'admin') {
                $this->user->role = $data['role'] || $existingUser['role'];
                $this->user->is_active = $data['is_active'] || $existingUser['is_active'];
            }

            if ($this->user->update()) {
                // Получаем обновленные данные
                $updatedUser = $this->user->getByIdForAdmin($id);
                Response::success('User updated successfully', $updatedUser);
            } else {
                Response::error('Failed to update user');
            }

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }

    // DELETE /api/users/{id} - удаление (админ)
    public function deleteUser($id)
    {
        try {
            // Только админ может удалять
            $payload = AuthMiddleware::requireRole('admin');

            // Валидация ID
            if (!Validator::validateNumber($id)) {
                Response::error('Invalid user ID');
            }

            // Нельзя удалить самого себя
            if ($payload['user_id'] == $id) {
                Response::error('Cannot delete your own account');
            }

            // Проверяем существование пользователя
            $existingUser = $this->user->getByIdForAdmin($id);
            if (!$existingUser) {
                Response::error('User not found', [], 404);
            }

            // Мягкое удаление
            if ($this->user->delete($id)) {
                Response::success('User deleted successfully');
            } else {
                Response::error('Failed to delete user');
            }

        } catch (Exception $e) {
            Response::error($e->getMessage());
        }
    }
}
