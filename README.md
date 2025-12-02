# Club Management System

Система управления клубами и мероприятиями

## 🚀 Быстрый старт

### Предварительные требования
- PHP 7.4 или выше
- MySQL

### Установка

1. **Клонировать репозиторий**
```bash
git clone https://github.com/grush1k033/club-management.git
cd club-management
```
```sql
-- Создать базу данных
CREATE DATABASE club_management;

-- Использовать созданную базу данных
USE club_management;

-- Создать таблицу пользователей
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    role ENUM('admin', 'club_owner', 'member') DEFAULT 'member',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```
2. Укажите данные для подключения к вашей базе данных
(user и password свои напиши и в переменную environment === local)
```sql
if ($environment === 'local') {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'club_management');
    define('DB_USER', 'xxxx');
    define('DB_PASS', 'xxxxxxxxx');

    define('APP_ENV', 'local');
    define('APP_URL', 'http://localhost:8000');
```
3.Запустить локальный сервер
```bash
# Перейти в папку server
cd server

# Запустить PHP встроенный сервер
php -S localhost:8000
```
