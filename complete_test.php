<?php
session_start();
require_once 'config/database.php';
require_once 'config/functions.php';

// Проверяем, что студент авторизован
if (!isset($_SESSION['student']) || empty($_SESSION['student'])) {
    header('Location: index.php');
    exit();
}

// Проверяем наличие test_id
if (!isset($_GET['test_id']) || empty($_GET['test_id'])) {
    $_SESSION['error'] = 'Тест не выбран';
    header('Location: tests.php');
    exit();
}

$test_id = (int)$_GET['test_id'];
$student_id = $_SESSION['student']['id'];
$timeout = isset($_GET['timeout']) ? 1 : 0;
$finish_early = isset($_GET['finish_early']) ? 1 : 0;
$terminated = isset($_GET['terminated']) ? 1 : 0;

// Проверяем наличие сессии теста
if (!isset($_SESSION['test_session']) || $_SESSION['test_session']['test_id'] != $test_id) {
    $_SESSION['error'] = 'Сессия теста не найдена';
    header('Location: tests.php');
    exit();
}

$test_session = $_SESSION['test_session'];
$answers = $test_session['answers'];
$total_questions = $test_session['total_questions'];
$start_time = $test_session['start_time'];
$time_spent = time() - $start_time;

// Получаем информацию о тесте
try {
    $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ?");
    $stmt->execute([$test_id]);
    $test = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$test) {
        $_SESSION['error'] = 'Тест не найден';
        header('Location: tests.php');
        exit();
    }
    
    // Получаем все вопросы теста с правильными ответами
    $stmt = $pdo->prepare("SELECT id, correct_answer FROM questions WHERE test_id = ?");
    $stmt->execute([$test_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($questions)) {
        $_SESSION['error'] = 'В тесте нет вопросов';
        header('Location: tests.php');
        exit();
    }
    
    // Подсчитываем результаты
    $score = 0;
    $detailed_results = [];
    
    // Если тест завершен из-за нарушений, ставим оценку 2 автоматически
    if ($terminated) {
        $score = 0;
        $grade = 2;
        $is_passed = false;
        $percent = 0;
        
        // Для каждого вопроса помечаем как неправильный
        foreach ($questions as $question) {
            $question_id = $question['id'];
            $student_answer = isset($answers[$question_id]) ? $answers[$question_id] : null;
            
            $detailed_results[] = [
                'question_id' => $question_id,
                'student_answer' => $student_answer,
                'correct_answer' => $question['correct_answer'],
                'is_correct' => 0
            ];
        }
    } else {
        // Обычный подсчет результатов
        $correct_answers = [];
        foreach ($questions as $q) {
            $correct_answers[$q['id']] = $q['correct_answer'];
        }
        
        foreach ($correct_answers as $question_id => $correct_option) {
            $student_answer = isset($answers[$question_id]) ? $answers[$question_id] : null;
            $is_correct = ($student_answer === $correct_option) ? 1 : 0;
            
            if ($is_correct) {
                $score++;
            }
            
            $detailed_results[] = [
                'question_id' => $question_id,
                'student_answer' => $student_answer,
                'correct_answer' => $correct_option,
                'is_correct' => $is_correct
            ];
        }
        
        // Вычисляем процент
        $percent = ($total_questions > 0) ? round(($score / $total_questions) * 100, 1) : 0;
        $passing_score = $test['passing_score'] ?? 60;
        $is_passed = $percent >= $passing_score;
        
        // Определяем оценку в зависимости от системы оценивания
        if ($test['grading_system'] === 'manual' && !empty($test['grading_rules'])) {
            // Используем ручную систему оценивания с правилами из базы
            $grade = calculateGradeFromRules($score, $test['grading_rules']);
        } else {
            // Используем автоматическую систему по умолчанию
            $grade = calculateNumericGrade($score, $total_questions);
        }
    }
    
    // Вычисляем процент для terminated режима
    if ($terminated) {
        $percent = 0;
    }
    
    // Сохраняем результат в БД с полем time_spent
    try {
        // Проверяем, существует ли поле time_spent в таблице
        $check_column = $pdo->query("SHOW COLUMNS FROM test_results LIKE 'time_spent'")->fetch();
        if (empty($check_column)) {
            // Добавляем поле, если его нет
            $pdo->exec("ALTER TABLE test_results ADD COLUMN time_spent INT NULL DEFAULT 0 COMMENT 'Время выполнения теста в секундах'");
        }

        // Проверяем, существует ли поле percent в таблице
        $check_percent_column = $pdo->query("SHOW COLUMNS FROM test_results LIKE 'percent'")->fetch();
        if (empty($check_percent_column)) {
            // Добавляем поле, если его нет
            $pdo->exec("ALTER TABLE test_results ADD COLUMN percent DECIMAL(5,2) NULL");
        }
        
        // Проверяем, есть ли уже результат (на всякий случай)
        $check_stmt = $pdo->prepare("SELECT id FROM test_results WHERE student_id = ? AND test_id = ?");
        $check_stmt->execute([$student_id, $test_id]);
        $existing_result = $check_stmt->fetch();
        
        if ($existing_result) {
            // Обновляем существующий результат с временем
            $sql = "UPDATE test_results SET 
                    score = ?, 
                    total_questions = ?, 
                    percent = ?,
                    grade = ?, 
                    is_passed = ?, 
                    time_spent = ?,
                    completed_at = NOW()
                    WHERE student_id = ? AND test_id = ?";
            
            $params = [$score, $total_questions, $percent, $grade, $is_passed ? 1 : 0, $time_spent, $student_id, $test_id];
        } else {
            // Вставляем новый результат с временем
            $sql = "INSERT INTO test_results 
                    (student_id, test_id, score, total_questions, percent, grade, is_passed, time_spent, completed_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $params = [$student_id, $test_id, $score, $total_questions, $percent, $grade, $is_passed ? 1 : 0, $time_spent];
        }
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($params);
        
        if (!$result) {
            $errorInfo = $stmt->errorInfo();
            throw new PDOException('Ошибка при выполнении запроса: ' . $errorInfo[2]);
        }
        
        // Получаем ID вставленной записи
        if ($existing_result) {
            $result_id = $existing_result['id'];
        } else {
            $result_id = $pdo->lastInsertId();
        }
        
        // Сохраняем детальные результаты в сессию для страницы result.php
        $_SESSION['test_detailed_results'] = $detailed_results;
        
        // Сохраняем результат в сессии для отображения
        $_SESSION['test_result'] = [
            'id' => $result_id,
            'test_id' => $test_id,
            'test_title' => $test['title'],
            'score' => $score,
            'total_questions' => $total_questions,
            'percent' => $percent,
            'grade' => $grade,
            'is_passed' => $is_passed,
            'time_spent' => $time_spent,
            'timeout' => $timeout,
            'finish_early' => $finish_early,
            'terminated' => $terminated
        ];
        
        // Очищаем сессию теста
        unset($_SESSION['test_session']);
        
        // Перенаправляем на страницу результата
        header('Location: result.php?test_id=' . $test_id);
        exit();
        
    } catch (PDOException $e) {
        error_log("SQL Error: " . $e->getMessage());
        $_SESSION['error'] = 'Ошибка при сохранении результатов. Детали: ' . $e->getMessage();
        header('Location: tests.php');
        exit();
    }
    
} catch (PDOException $e) {
    error_log("General Error: " . $e->getMessage());
    $_SESSION['error'] = 'Ошибка при обработке результатов: ' . $e->getMessage();
    header('Location: tests.php');
    exit();
}

// Функция для расчета оценки по правилам из базы данных
function calculateGradeFromRules($score, $grading_rules_json) {
    $rules_data = json_decode($grading_rules_json, true);
    
    if (!isset($rules_data['rules']) || !is_array($rules_data['rules'])) {
        return 'Не оценено';
    }
    
    $rules = $rules_data['rules'];
    
    // Сортируем правила по убыванию min_correct
    usort($rules, function($a, $b) {
        return $b['min_correct'] <=> $a['min_correct'];
    });
    
    // Ищем первое подходящее правило
    foreach ($rules as $rule) {
        if ($score >= $rule['min_correct']) {
            // Формируем оценку с текстовым описанием
            if (isset($rule['label']) && !empty($rule['label'])) {
                return $rule['grade'] . ' (' . $rule['label'] . ')';
            }
            return $rule['grade'];
        }
    }
    
    return 'Не оценено';
}

// Функция для расчета оценки в цифрах (5,4,3,2) - резервная
function calculateNumericGrade($score, $total_questions) {
    if ($total_questions == 0) return 2;
    
    $percentage = ($score / $total_questions) * 100;
    
    if ($percentage >= 90) return 5;
    if ($percentage >= 75) return 4;
    if ($percentage >= 60) return 3;
    return 2;
}
?>