<?php
session_start();
require_once 'config/database.php';
require_once 'config/functions.php';

// Проверяем, что студент авторизован
if (!isset($_SESSION['student']) || empty($_SESSION['student'])) {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: tests.php');
    exit();
}

try {
    // Получаем данные из формы
    $test_id = intval($_POST['test_id']);
    $student_id = $_SESSION['student']['id'];
    $time_spent = intval($_POST['time_spent'] ?? 0);
    $answers = $_POST['answers'] ?? [];
    
    // Проверяем валидность данных
    if (empty($test_id) || empty($student_id)) {
        throw new Exception("Неверные данные теста");
    }
    
    // Получаем информацию о тесте
    $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ?");
    $stmt->execute([$test_id]);
    $test = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$test) {
        throw new Exception("Тест не найден");
    }
    
    // Получаем все вопросы теста
    $stmt = $pdo->prepare("SELECT id, question_text, question_type FROM questions WHERE test_id = ?");
    $stmt->execute([$test_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($questions)) {
        throw new Exception("В тесте нет вопросов");
    }
    
    $total_questions = count($questions);
    $score = 0;
    $results_data = [];
    
    $pdo->beginTransaction();
    
    foreach ($questions as $question) {
        $question_id = $question['id'];
        $student_answer_ids = $answers[$question_id] ?? [];
        
        if (!is_array($student_answer_ids)) {
            $student_answer_ids = [$student_answer_ids];
        }
        
        // Получаем все ответы для этого вопроса
        $stmt = $pdo->prepare("SELECT id, answer_text, is_correct FROM answers WHERE question_id = ?");
        $stmt->execute([$question_id]);
        $all_answers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Определяем, правильно ли ответил студент
        $is_correct = true;
        
        if ($question['question_type'] == 'multiple') {
            // Для множественного выбора - все правильные должны быть выбраны и никакие неправильные
            $correct_answers = array_filter($all_answers, function($a) { return $a['is_correct']; });
            $incorrect_answers = array_filter($all_answers, function($a) { return !$a['is_correct']; });
            
            // Проверяем, что выбраны все правильные ответы
            foreach ($correct_answers as $correct) {
                if (!in_array($correct['id'], $student_answer_ids)) {
                    $is_correct = false;
                    break;
                }
            }
            
            // Проверяем, что не выбраны неправильные ответы
            if ($is_correct) {
                foreach ($incorrect_answers as $incorrect) {
                    if (in_array($incorrect['id'], $student_answer_ids)) {
                        $is_correct = false;
                        break;
                    }
                }
            }
        } else {
            // Для одиночного выбора - должен быть выбран один правильный
            $correct_answer_id = null;
            foreach ($all_answers as $answer) {
                if ($answer['is_correct']) {
                    $correct_answer_id = $answer['id'];
                    break;
                }
            }
            
            $is_correct = !empty($student_answer_ids[0]) && 
                         $student_answer_ids[0] == $correct_answer_id;
        }
        
        if ($is_correct) {
            $score++;
        }
        
        // Получаем тексты выбранных ответов студента
        $student_answer_texts = [];
        foreach ($student_answer_ids as $answer_id) {
            foreach ($all_answers as $answer) {
                if ($answer['id'] == $answer_id) {
                    $student_answer_texts[] = $answer['answer_text'];
                    break;
                }
            }
        }
        $student_answer = implode(', ', $student_answer_texts);
        
        // Получаем тексты правильных ответов
        $correct_answer_texts = [];
        foreach ($all_answers as $answer) {
            if ($answer['is_correct']) {
                $correct_answer_texts[] = $answer['answer_text'];
            }
        }
        $correct_answer = implode(', ', $correct_answer_texts);
        
        // Сохраняем результат в базу данных
        $stmt = $pdo->prepare("
            INSERT INTO results 
            (student_id, test_id, question_id, selected_answers, student_answer, correct_answer, is_correct, score) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $selected_answers_str = implode(',', $student_answer_ids);
        $stmt->execute([
            $student_id,
            $test_id,
            $question_id,
            $selected_answers_str,
            $student_answer,
            $correct_answer,
            $is_correct ? 1 : 0,
            $is_correct ? 1 : 0
        ]);
        
        // Сохраняем данные для отображения результата
        $results_data[] = [
            'question_id' => $question_id,
            'question_text' => $question['question_text'],
            'question_type' => $question['question_type'],
            'student_answer' => $student_answer,
            'correct_answer' => $correct_answer,
            'is_correct' => $is_correct,
            'all_answers' => $all_answers
        ];
    }
    
    // Вычисляем процент правильных ответов
    $percent = round(($score / $total_questions) * 100, 2);
    
    // Определяем, сдан ли тест
    $is_passed = $percent >= $test['passing_score'];
    
    // Получаем оценку
    $grade = calculateGrade($score, $total_questions, $test_id);
    
    // Сохраняем общую статистику теста
    $stmt = $pdo->prepare("
        INSERT INTO test_results 
        (student_id, test_id, score, total_questions, percent, grade, is_passed, time_spent) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
        score = VALUES(score),
        total_questions = VALUES(total_questions),
        percent = VALUES(percent),
        grade = VALUES(grade),
        is_passed = VALUES(is_passed),
        time_spent = VALUES(time_spent),
        completed_at = NOW()
    ");
    
    $stmt->execute([
        $student_id,
        $test_id,
        $score,
        $total_questions,
        $percent,
        $grade,
        $is_passed ? 1 : 0,
        $time_spent
    ]);
    
    $pdo->commit();
    
    // Сохраняем результат в сессии для отображения
    $_SESSION['test_result'] = [
        'test_id' => $test_id,
        'test_title' => $test['title'],
        'score' => $score,
        'total_questions' => $total_questions,
        'percent' => $percent,
        'grade' => $grade,
        'is_passed' => $is_passed,
        'time_spent' => $time_spent,
        'results' => $results_data
    ];
    
    // Перенаправляем на страницу результата
    header('Location: result.php');
    exit();
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $_SESSION['error'] = 'Ошибка при сохранении результатов: ' . $e->getMessage();
    header('Location: test.php?test_id=' . ($test_id ?? ''));
    exit();
}