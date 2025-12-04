<?php
// server/controllers/ClubController.php

require_once __DIR__ . '/../models/Club.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Validator.php';

class ClubController {
    private $clubModel;
    private $response;
    private $validator;

    public function __construct($db) {
        $this->clubModel = new Club($db);
        $this->response = new Response();
        $this->validator = new Validator();
    }

    // Получение списка клубов
    public function getAll($payload) {
        // Используем $_GET для получения параметров
        $this->clubModel->page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
        $this->clubModel->limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $this->clubModel->search = isset($_GET['search']) ? $_GET['search'] : '';
        $this->clubModel->category_filter = isset($_GET['category']) ? $_GET['category'] : '';
        $this->clubModel->status_filter = isset($_GET['status']) ? $_GET['status'] : '';

        // Получаем клубы
        $clubs = $this->clubModel->readAll();

        // Получаем общее количество
        $total = $this->clubModel->countAll();

        Response::success('Список клубов', [
            'clubs' => $clubs,
            'pagination' => [
                'page' => $this->clubModel->page,
                'limit' => $this->clubModel->limit,
                'total' => (int)$total,
                'total_pages' => ceil($total / $this->clubModel->limit)
            ]
        ]);
    }

    // Получение клуба по ID (ДОБАВЬТЕ ЭТОТ МЕТОД)
    public function getById($clubId, $payload) {
        // Устанавливаем ID в модели
        $this->clubModel->id = $clubId;

        // Получаем клуб
        $found = $this->clubModel->readOne();

        if (!$found) {
            Response::error('Клуб не найден', [], 404);
        }

        // Возвращаем данные клуба
        $clubData = [
            'id' => $this->clubModel->id,
            'name' => $this->clubModel->name,
            'status' => $this->clubModel->status,
            'description' => $this->clubModel->description,
            'category' => $this->clubModel->category,
            'email' => $this->clubModel->email,
            'phone' => $this->clubModel->phone,
            'captain_id' => $this->clubModel->captain_id,
            'vice_captain_id' => $this->clubModel->vice_captain_id,
            'created_at' => $this->clubModel->created_at,
            'updated_at' => $this->clubModel->updated_at,
            'captain_name' => $this->clubModel->captain_name,
            'vice_captain_name' => $this->clubModel->vice_captain_name
        ];

        Response::success('Информация о клубе', $clubData);
    }

    // Создание клуба
    public function create() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            Response::error('Неверный формат данных', [], 400);
        }

        // Валидация
        $rules = [
            'name' => 'required|min:3|max:100',
            'description' => 'required|min:10|max:500',
            'category' => 'required|min:2|max:50',
            'email' => 'required|email',
            'phone' => 'required|min:10|max:20',
            'captain_id' => 'required|integer'
        ];

        // Опциональные поля
        if (isset($data['vice_captain_id']) && $data['vice_captain_id'] !== '') {
            $rules['vice_captain_id'] = 'integer';
        }
        if (isset($data['status'])) {
            $rules['status'] = 'in:Active,Inactive,Pending';
        }

        $errors = $this->validator->validate($data, $rules);
        if (!empty($errors)) {
            Response::error('Ошибка валидации', $errors, 400);
        }

        // Заполняем модель
        $this->clubModel->name = $data['name'];
        $this->clubModel->description = $data['description'];
        $this->clubModel->category = $data['category'];
        $this->clubModel->email = $data['email'];
        $this->clubModel->phone = $data['phone'];
        $this->clubModel->captain_id = $data['captain_id'];

        // Обработка vice_captain_id - если пусто, то null
        $this->clubModel->vice_captain_id = isset($data['vice_captain_id']) && $data['vice_captain_id'] !== ''
            ? (int)$data['vice_captain_id']
            : null;

        $this->clubModel->status = $data['status'] || 'Active';

        // Создаем клуб
        $created = $this->clubModel->create();

        if ($created) {
            Response::success('Клуб успешно создан', [
                'id' => $this->clubModel->id,
                'name' => $this->clubModel->name
            ], 201);
        } else {
            Response::error('Не удалось создать клуб', [], 500);
        }
    }

    // Обновление клуба (ДОБАВЬТЕ ЭТОТ МЕТОД, если его нет)
    public function update($clubId, $payload) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            Response::error('Неверный формат данных', [], 400);
        }

        // Проверяем существование клуба
        $this->clubModel->id = $clubId;
        $found = $this->clubModel->readOne();

        if (!$found) {
            Response::error('Клуб не найден', [], 404);
        }

        // Валидация (только для полей, которые переданы)
        $rules = [];
        if (isset($data['name'])) $rules['name'] = 'min:3|max:100';
        if (isset($data['description'])) $rules['description'] = 'min:10|max:500';
        if (isset($data['category'])) $rules['category'] = 'min:2|max:50';
        if (isset($data['email'])) $rules['email'] = 'email';
        if (isset($data['phone'])) $rules['phone'] = 'min:10|max:20';
        if (isset($data['captain_id'])) $rules['captain_id'] = 'integer';
        if (isset($data['vice_captain_id'])) $rules['vice_captain_id'] = 'integer';
        if (isset($data['status'])) $rules['status'] = 'in:Active,Inactive,Pending';

        $errors = $this->validator->validate($data, $rules);
        if (!empty($errors)) {
            Response::error('Ошибка валидации', $errors, 400);
        }

        // Обновляем только переданные поля
        if (isset($data['name'])) $this->clubModel->name = $data['name'];
        if (isset($data['description'])) $this->clubModel->description = $data['description'];
        if (isset($data['category'])) $this->clubModel->category = $data['category'];
        if (isset($data['email'])) $this->clubModel->email = $data['email'];
        if (isset($data['phone'])) $this->clubModel->phone = $data['phone'];
        if (isset($data['captain_id'])) $this->clubModel->captain_id = $data['captain_id'];
        if (isset($data['vice_captain_id'])) $this->clubModel->vice_captain_id = $data['vice_captain_id'];
        if (isset($data['status'])) $this->clubModel->status = $data['status'];

        // Обновляем клуб
        $updated = $this->clubModel->update();

        if ($updated) {
            Response::success('Клуб успешно обновлен', [], 200);
        } else {
            Response::error('Не удалось обновить клуб', [], 500);
        }
    }

    // Удаление клуба (ДОБАВЬТЕ ЭТОТ МЕТОД, если его нет)
    public function delete($clubId) {
        // Проверяем существование клуба
        $this->clubModel->id = $clubId;
        $found = $this->clubModel->readOne();

        if (!$found) {
            Response::error('Клуб не найден', [], 404);
        }

        // Удаляем клуб
        $deleted = $this->clubModel->delete();

        if ($deleted) {
            Response::success('Клуб успешно удален', [], 200);
        } else {
            Response::error('Не удалось удалить клуб', [], 500);
        }
    }

    // Поиск клубов (ДОБАВЬТЕ ЭТОТ МЕТОД)
    public function search($query, $payload) {
        $searchTerm = urldecode($query);
        $clubs = $this->clubModel->search($searchTerm);

        Response::success('Результаты поиска', [
            'query' => $searchTerm,
            'clubs' => $clubs,
            'total' => count($clubs)
        ]);
    }

    // Получение клубов по категории (ДОБАВЬТЕ ЭТОТ МЕТОД)
    public function getByCategory($category, $payload) {
        $clubs = $this->clubModel->getByCategory($category);

        Response::success('Клубы по категории', [
            'category' => $category,
            'clubs' => $clubs,
            'total' => count($clubs)
        ]);
    }

    // Получение всех категорий (ДОБАВЬТЕ ЭТОТ МЕТОД)
    public function getCategories() {
        $categories = $this->clubModel->getCategories();

        Response::success('Категории клубов', [
            'categories' => $categories
        ]);
    }

    // Переключение статуса клуба (ДОБАВЬТЕ ЭТОТ МЕТОД)
    public function toggleStatus($clubId) {
        // Проверяем существование клуба
        $this->clubModel->id = $clubId;
        $found = $this->clubModel->readOne();

        if (!$found) {
            Response::error('Клуб не найден', [], 404);
        }

        // Переключаем статус
        $toggled = $this->clubModel->toggleStatus();

        if ($toggled) {
            Response::success('Статус клуба успешно изменен', [], 200);
        } else {
            Response::error('Не удалось изменить статус клуба', [], 500);
        }
    }
}