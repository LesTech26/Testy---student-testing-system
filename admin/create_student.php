<?php
require_once '../config/functions.php';
requireAdminLogin();

// Обработка POST запроса
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Получаем данные из формы
    $last_name = trim($_POST['last_name']);
    $first_name = trim($_POST['first_name']);
    $city = isset($_POST['city']) ? trim($_POST['city']) : null; // Добавляем город
    $course_number = (int)$_POST['course_number'];
    $specialty = isset($_POST['specialty']) ? trim($_POST['specialty']) : null; // Добавляем специальность
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // Проверяем данные
    $errors = [];
    
    if (empty($last_name)) {
        $errors[] = 'Фамилия обязательна для заполнения';
    } elseif (strlen($last_name) > 100) {
        $errors[] = 'Фамилия не должна превышать 100 символов';
    }
    
    if (empty($first_name)) {
        $errors[] = 'Имя обязательно для заполнения';
    } elseif (strlen($first_name) > 100) {
        $errors[] = 'Имя не должно превышать 100 символов';
    }
    
    if ($course_number < 1 || $course_number > 5) {
        $errors[] = 'Номер курса должен быть от 1 до 5';
    }
    
    if (empty($username)) {
        $errors[] = 'Логин обязателен для заполнения';
    } elseif (strlen($username) > 50) {
        $errors[] = 'Логин не должен превышать 50 символов';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'Логин может содержать только буквы (латиница), цифры и символ подчеркивания';
    }
    
    if (empty($password)) {
        $errors[] = 'Пароль обязателен для заполнения';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Пароль должен содержать минимум 6 символов';
    } elseif (strlen($password) > 100) {
        $errors[] = 'Пароль не должен превышать 100 символов';
    }
    
    // Если есть ошибки, сохраняем их в сессию
    if (!empty($errors)) {
        $_SESSION['error'] = implode('<br>', $errors);
        header('Location: index.php');
        exit();
    }
    
    try {
        // Проверяем, существует ли логин
        $stmt = $pdo->prepare("SELECT id FROM students WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $_SESSION['error'] = 'Пользователь с таким логином уже существует';
            header('Location: index.php');
            exit();
        }
        
        // Сохраняем пароль в открытом виде с учетом города и специальности
        $sql = "INSERT INTO students 
                (last_name, first_name, city, course_number, specialty, username, password, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute([$last_name, $first_name, $city, $course_number, $specialty, $username, $password])) {
            $student_id = $pdo->lastInsertId();
            
            $_SESSION['success'] = 'Студент успешно создан!';
            $_SESSION['student_created'] = [
                'id' => $student_id,
                'last_name' => $last_name,
                'first_name' => $first_name,
                'city' => $city,
                'course_number' => $course_number,
                'specialty' => $specialty,
                'username' => $username,
                'password' => $password,
                'created_at' => date('Y-m-d H:i:s')
            ];
        } else {
            $_SESSION['error'] = 'Ошибка при создании студента';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка базы данных: ' . $e->getMessage();
    }
    
    header('Location: index.php');
    exit();
}

// Если не POST запрос, перенаправляем на главную
header('Location: index.php');
exit();
?>