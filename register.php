<?php
require_once 'config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $last_name = trim($_POST['last_name']);
    $first_name = trim($_POST['first_name']);
    $course_number = intval($_POST['course_number']);
    
    // Сохраняем студента в сессию
    $_SESSION['student'] = [
        'last_name' => $last_name,
        'first_name' => $first_name,
        'course_number' => $course_number
    ];
    
    // Сохраняем в базу данных
    $stmt = $pdo->prepare("INSERT INTO students (last_name, first_name, course_number) VALUES (?, ?, ?)");
    $stmt->execute([$last_name, $first_name, $course_number]);
    $_SESSION['student_id'] = $pdo->lastInsertId();
    
    header('Location: tests.php');
    exit();
} else {
    header('Location: index.php');
    exit();
}
?>