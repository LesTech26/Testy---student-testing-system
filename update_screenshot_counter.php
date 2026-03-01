<?php
session_start();
require_once 'config/database.php';

// Проверяем, что студент авторизован
if (!isset($_SESSION['student']) || empty($_SESSION['student'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Проверяем наличие параметров
if (!isset($_POST['test_id']) || !isset($_POST['attempts'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit();
}

$test_id = (int)$_POST['test_id'];
$attempts = (int)$_POST['attempts'];

// Проверяем наличие сессии теста
if (!isset($_SESSION['test_session']) || $_SESSION['test_session']['test_id'] != $test_id) {
    http_response_code(404);
    echo json_encode(['error' => 'Test session not found']);
    exit();
}

// Обновляем счетчик в сессии
$_SESSION['test_session']['screenshot_attempts'] = $attempts;

// Проверяем, не превышен ли лимит
$max_attempts = 2;
$terminated = false;

if ($attempts >= $max_attempts) {
    $_SESSION['test_session']['test_terminated'] = true;
    $terminated = true;
}

// Возвращаем успешный ответ
echo json_encode([
    'success' => true,
    'attempts' => $attempts,
    'terminated' => $terminated,
    'max_attempts' => $max_attempts
]);
?>