<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
requireAdminLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $last_name = trim($_POST['last_name']);
    $first_name = trim($_POST['first_name']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $city = trim($_POST['city']);

    // Валидация
    $errors = [];

    if (empty($last_name) || empty($first_name)) {
        $errors[] = 'Фамилия и имя обязательны';
    }

    if (empty($username) || strlen($username) < 3) {
        $errors[] = 'Логин должен быть не менее 3 символов';
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'Логин может содержать только латинские буквы, цифры и символ _';
    }

    if (empty($password) || strlen($password) < 6) {
        $errors[] = 'Пароль должен быть не менее 6 символов';
    }

    // Проверяем, существует ли такой логин
    $stmt = $pdo->prepare("SELECT id FROM teachers WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        $errors[] = 'Пользователь с таким логином уже существует';
    }

    if (empty($errors)) {
        try {
            // Сохраняем преподавателя в базу
            $stmt = $pdo->prepare("INSERT INTO teachers (last_name, first_name, username, password, city, is_active) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt->execute([$last_name, $first_name, $username, $password, $city]);

            $teacher_id = $pdo->lastInsertId();

            // Сохраняем данные для отображения на главной
            $_SESSION['teacher_created'] = [
                'id' => $teacher_id,
                'last_name' => $last_name,
                'first_name' => $first_name,
                'username' => $username,
                'password' => $password,
                'city' => $city
            ];

            $_SESSION['success'] = 'Преподаватель успешно создан!';
            header('Location: index.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Ошибка при создании преподавателя: ' . $e->getMessage();
            header('Location: index.php');
            exit();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errors);
        header('Location: index.php');
        exit();
    }
}