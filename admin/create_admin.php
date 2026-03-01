<?php
session_start();
require_once '../config/functions.php';
requireAdminLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $last_name = trim($_POST['last_name']);
    $first_name = trim($_POST['first_name']);
    $city = trim($_POST['city']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']); // Используем оригинальный пароль

    // Проверка уникальности логина
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    
    if ($stmt->fetch()) {
        $_SESSION['error'] = 'Логин уже существует';
        header('Location: index.php');
        exit;
    }


    $stmt = $pdo->prepare("INSERT INTO admins (last_name, first_name, city, username, password, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
    
    try {
        $stmt->execute([$last_name, $first_name, $city, $username, $password]);
        $admin_id = $pdo->lastInsertId();
        
        // Сохраняем данные для отображения на главной странице
        $_SESSION['admin_created'] = [
            'id' => $admin_id,
            'last_name' => $last_name,
            'first_name' => $first_name,
            'city' => $city,
            'username' => $username,
            'password' => $password // Сохраняем оригинальный пароль
        ];
        
        $_SESSION['success'] = 'Администратор успешно создан!';
        header('Location: index.php');
        exit;
        
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка при создании администратора: ' . $e->getMessage();
        header('Location: index.php');
        exit;
    }
} else {
    header('Location: index.php');
    exit;
}
?>