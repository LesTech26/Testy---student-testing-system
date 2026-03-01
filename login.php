<?php
session_start();
require_once 'config/database.php';
require_once 'config/functions.php';

// Проверяем, если студент уже авторизован, перенаправляем на выбор теста
if (isset($_SESSION['student']) && !empty($_SESSION['student'])) {
    header('Location: tests.php');
    exit();
}

// Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // Проверяем обязательные поля
    if (empty($username) || empty($password)) {
        $_SESSION['login_error'] = 'Заполните все поля';
        header('Location: index.php');
        exit();
    }
    
    try {
        // Проверяем студента (пароль в открытом виде)
        $stmt = $pdo->prepare("SELECT * FROM students WHERE username = ?");
        $stmt->execute([$username]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student) {
            // Проверяем пароль (в открытом виде)
            if ($password === $student['password']) {
                // Успешный вход
                $_SESSION['student'] = [
                    'id' => $student['id'],
                    'last_name' => $student['last_name'],
                    'first_name' => $student['first_name'],
                    'course_number' => $student['course_number'],
                    'username' => $student['username']
                ];
                
                // Обновляем время последнего входа
                $stmt = $pdo->prepare("UPDATE students SET last_login = NOW() WHERE id = ?");
                $stmt->execute([$student['id']]);
                
                // Перенаправляем на выбор теста
                header('Location: tests.php');
                exit();
            } else {
                $_SESSION['login_error'] = 'Неверный пароль';
            }
        } else {
            $_SESSION['login_error'] = 'Пользователь не найден';
        }
    } catch (PDOException $e) {
        $_SESSION['login_error'] = 'Ошибка при входе в систему';
    }
    
    header('Location: index.php');
    exit();
}

// Если не POST запрос, перенаправляем на главную
header('Location: index.php');
exit();
?>