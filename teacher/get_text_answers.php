<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';

// Проверка авторизации преподавателя
if (!isset($_SESSION['teacher'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ запрещен']);
    exit();
}

$result_id = isset($_GET['result_id']) ? intval($_GET['result_id']) : 0;

if (!$result_id) {
    echo json_encode(['error' => 'Не указан ID результата']);
    exit();
}

// Получаем ответы
$sql = "SELECT ta.*, q.question_text, q.correct_text_answer 
        FROM text_answers ta
        JOIN questions q ON ta.question_id = q.id
        WHERE ta.result_id = ?
        ORDER BY ta.id";

$stmt = $pdo->prepare($sql);
$stmt->execute([$result_id]);
$answers = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['answers' => $answers]);