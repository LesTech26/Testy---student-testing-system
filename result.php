<?php
session_start();
require_once 'config/database.php';
require_once 'config/functions.php';

// Проверяем, что студент авторизован
if (!isset($_SESSION['student']) || empty($_SESSION['student'])) {
    header('Location: index.php');
    exit();
}

$student_id = $_SESSION['student']['id'];

// Получаем ID теста из GET параметра
if (!isset($_GET['test_id']) || empty($_GET['test_id'])) {
    $_SESSION['error'] = 'Тест не выбран';
    header('Location: tests.php');
    exit();
}

$test_id = (int) $_GET['test_id'];

try {
    // Получаем информацию о тесте
    $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ?");
    $stmt->execute([$test_id]);
    $test = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$test) {
        $_SESSION['error'] = 'Тест не найден';
        header('Location: tests.php');
        exit();
    }

    // Получаем результат теста из таблицы test_results
    $stmt = $pdo->prepare("
        SELECT * FROM test_results 
        WHERE student_id = ? AND test_id = ?
        ORDER BY completed_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$student_id, $test_id]);
    $test_result = $stmt->fetch(PDO::FETCH_ASSOC);

    $time_spent = 0; // Переменная для времени
    
    if (!$test_result) {
        // Если результат не найден в БД, проверяем сессию
        if (isset($_SESSION['test_result']) && $_SESSION['test_result']['test_id'] == $test_id) {
            $test_result = $_SESSION['test_result'];
            $time_spent = $test_result['time_spent'] ?? 0;

            // Сохраняем в базу данных с полем time_spent
            try {
                // Проверяем, существует ли поле time_spent в таблице
                $check_column = $pdo->query("SHOW COLUMNS FROM test_results LIKE 'time_spent'")->fetch();
                if (empty($check_column)) {
                    // Добавляем поле, если его нет
                    $pdo->exec("ALTER TABLE test_results ADD COLUMN time_spent INT NULL DEFAULT 0");
                }

                $insert_sql = "INSERT INTO test_results 
                              (student_id, test_id, score, total_questions, grade, is_passed, time_spent, completed_at) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                $insert_stmt = $pdo->prepare($insert_sql);
                $insert_stmt->execute([
                    $student_id,
                    $test_id,
                    $test_result['score'],
                    $test_result['total_questions'],
                    $test_result['grade'],
                    $test_result['is_passed'] ? 1 : 0,
                    $time_spent
                ]);

                // Получаем ID новой записи
                $test_result['id'] = $pdo->lastInsertId();
                $test_result['completed_at'] = date('Y-m-d H:i:s');
                $test_result['time_spent'] = $time_spent;

            } catch (Exception $e) {
                error_log("Ошибка при сохранении результата: " . $e->getMessage());
            }
        } else {
            $_SESSION['error'] = 'Результаты теста не найдены';
            header('Location: tests.php');
            exit();
        }
    } else {
        // Если результат есть в БД, получаем время оттуда
        $time_spent = $test_result['time_spent'] ?? 0;
    }

    // Если время все еще 0, пытаемся получить из сессии теста
    if ($time_spent == 0 && isset($_SESSION['test_session']) && $_SESSION['test_session']['test_id'] == $test_id) {
        $time_spent = time() - $_SESSION['test_session']['start_time'];
        
        // Обновляем в БД, если есть результат
        if (isset($test_result['id'])) {
            try {
                $update_sql = "UPDATE test_results SET time_spent = ? WHERE id = ?";
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([$time_spent, $test_result['id']]);
                $test_result['time_spent'] = $time_spent;
            } catch (Exception $e) {
                error_log("Ошибка при обновлении времени: " . $e->getMessage());
            }
        }
    }

    // Вычисляем процент, если его нет
    $score = $test_result['score'] ?? 0;
    $total_questions = $test_result['total_questions'] ?? 1;
    $percent = round(($score / $total_questions) * 100, 2);

    // Определяем проходной балл
    $passing_score = $test['passing_score'] ?? 60;
    $is_passed = $percent >= $passing_score;

    // Получаем детальные результаты из сессии (если есть)
    $detailed_results = [];
    $processed_results = [];

    if (isset($_SESSION['test_detailed_results'])) {
        $detailed_results = $_SESSION['test_detailed_results'];

        // Получаем тексты вопросов
        $question_ids = array_column($detailed_results, 'question_id');
        if (!empty($question_ids)) {
            $placeholders = implode(',', array_fill(0, count($question_ids), '?'));
            $stmt = $pdo->prepare("SELECT * FROM questions WHERE id IN ($placeholders)");
            $stmt->execute($question_ids);
            $questions_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $questions_by_id = [];
            foreach ($questions_data as $q) {
                $questions_by_id[$q['id']] = $q;
            }

            // Формируем массив для отображения
            foreach ($detailed_results as $detail) {
                $question = $questions_by_id[$detail['question_id']] ?? null;
                if ($question) {
                    // Определяем текст ответа студента
                    $student_answer_text = '';
                    if ($detail['student_answer']) {
                        switch ($detail['student_answer']) {
                            case 'A':
                                $student_answer_text = $question['option_a'];
                                break;
                            case 'B':
                                $student_answer_text = $question['option_b'];
                                break;
                            case 'C':
                                $student_answer_text = $question['option_c'];
                                break;
                            case 'D':
                                $student_answer_text = $question['option_d'];
                                break;
                        }
                    }

                    // Определяем текст правильного ответа - используем correct_answer
                    $correct_answer_text = '';
                    $correct_answer = $question['correct_answer'] ?? '';

                    if ($correct_answer) {
                        switch ($correct_answer) {
                            case 'A':
                                $correct_answer_text = $question['option_a'];
                                break;
                            case 'B':
                                $correct_answer_text = $question['option_b'];
                                break;
                            case 'C':
                                $correct_answer_text = $question['option_c'];
                                break;
                            case 'D':
                                $correct_answer_text = $question['option_d'];
                                break;
                        }
                    }

                    $processed_results[] = [
                        'question_text' => $question['question_text'],
                        'student_answer' => $detail['student_answer'],
                        'student_answer_text' => $student_answer_text,
                        'correct_answer' => $correct_answer,
                        'correct_answer_text' => $correct_answer_text,
                        'is_correct' => $detail['is_correct'],
                        'explanation' => $question['explanation'] ?? ''
                    ];
                }
            }

            // Очищаем сессию
            unset($_SESSION['test_detailed_results']);
        }
    }

    // Если нет детальных результатов из сессии, пробуем получить из базы данных
    if (empty($processed_results)) {
        // Пробуем получить ответы из таблицы student_answers (если такая есть)
        try {
            $stmt = $pdo->prepare("
                SELECT sa.*, q.question_text, q.option_a, q.option_b, q.option_c, q.option_d, q.correct_answer, q.explanation
                FROM student_answers sa
                JOIN questions q ON sa.question_id = q.id
                WHERE sa.student_id = ? AND sa.test_id = ?
                ORDER BY sa.id
            ");
            $stmt->execute([$student_id, $test_id]);
            $saved_answers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($saved_answers)) {
                foreach ($saved_answers as $answer) {
                    // Определяем текст ответа студента
                    $student_answer_text = '';
                    if ($answer['student_answer']) {
                        switch ($answer['student_answer']) {
                            case 'A':
                                $student_answer_text = $answer['option_a'];
                                break;
                            case 'B':
                                $student_answer_text = $answer['option_b'];
                                break;
                            case 'C':
                                $student_answer_text = $answer['option_c'];
                                break;
                            case 'D':
                                $student_answer_text = $answer['option_d'];
                                break;
                        }
                    }

                    // Определяем текст правильного ответа
                    $correct_answer_text = '';
                    $correct_answer = $answer['correct_answer'] ?? '';

                    if ($correct_answer) {
                        switch ($correct_answer) {
                            case 'A':
                                $correct_answer_text = $answer['option_a'];
                                break;
                            case 'B':
                                $correct_answer_text = $answer['option_b'];
                                break;
                            case 'C':
                                $correct_answer_text = $answer['option_c'];
                                break;
                            case 'D':
                                $correct_answer_text = $answer['option_d'];
                                break;
                        }
                    }

                    $processed_results[] = [
                        'question_text' => $answer['question_text'],
                        'student_answer' => $answer['student_answer'],
                        'student_answer_text' => $student_answer_text,
                        'correct_answer' => $correct_answer,
                        'correct_answer_text' => $correct_answer_text,
                        'is_correct' => $answer['is_correct'] ?? ($answer['student_answer'] === $correct_answer),
                        'explanation' => $answer['explanation'] ?? ''
                    ];
                }
            }
        } catch (Exception $e) {
            // Игнорируем ошибку, если таблицы нет
            error_log("Ошибка при получении детальных ответов: " . $e->getMessage());
        }
    }

    // Если все еще нет детальных результатов, создаем их на основе вопросов
    if (empty($processed_results)) {
        // Получаем все вопросы теста
        $stmt = $pdo->prepare("SELECT * FROM questions WHERE test_id = ?");
        $stmt->execute([$test_id]);
        $all_questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($all_questions as $question) {
            // Для каждого вопроса пытаемся найти ответ из сессии test_session
            $student_answer = null;
            $is_correct = false;

            if (isset($_SESSION['test_session']) && $_SESSION['test_session']['test_id'] == $test_id) {
                $student_answer = $_SESSION['test_session']['answers'][$question['id']] ?? null;
                $is_correct = ($student_answer === $question['correct_answer']);
            }

            // Определяем текст ответа студента
            $student_answer_text = '';
            if ($student_answer) {
                switch ($student_answer) {
                    case 'A':
                        $student_answer_text = $question['option_a'];
                        break;
                    case 'B':
                        $student_answer_text = $question['option_b'];
                        break;
                    case 'C':
                        $student_answer_text = $question['option_c'];
                        break;
                    case 'D':
                        $student_answer_text = $question['option_d'];
                        break;
                }
            }

            // Определяем текст правильного ответа
            $correct_answer_text = '';
            $correct_answer = $question['correct_answer'] ?? '';

            if ($correct_answer) {
                switch ($correct_answer) {
                    case 'A':
                        $correct_answer_text = $question['option_a'];
                        break;
                    case 'B':
                        $correct_answer_text = $question['option_b'];
                        break;
                    case 'C':
                        $correct_answer_text = $question['option_c'];
                        break;
                    case 'D':
                        $correct_answer_text = $question['option_d'];
                        break;
                }
            }

            $processed_results[] = [
                'question_text' => $question['question_text'],
                'student_answer' => $student_answer,
                'student_answer_text' => $student_answer_text,
                'correct_answer' => $correct_answer,
                'correct_answer_text' => $correct_answer_text,
                'is_correct' => $is_correct,
                'explanation' => $question['explanation'] ?? ''
            ];
        }
    }

    // Очищаем сессию теста
    if (isset($_SESSION['test_session']) && $_SESSION['test_session']['test_id'] == $test_id) {
        unset($_SESSION['test_session']);
    }

} catch (Exception $e) {
    $_SESSION['error'] = 'Ошибка при получении результатов: ' . $e->getMessage();
    error_log("Ошибка в result.php: " . $e->getMessage());
    header('Location: tests.php');
    exit();
}

// Функция для расчета оценки теперь берется из functions.php
// Удаляем дублирующую функцию
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Результат теста</title>
    <link rel="icon" href="logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 20px;
            padding-bottom: 40px;
        }

        .result-container {
            max-width: 900px;
            margin: 0 auto;
        }

        .result-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .score-circle {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2.5rem;
            font-weight: bold;
            color: white;
            background: linear-gradient(45deg, #3498db, #2c3e50);
        }

        .details {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 30px;
        }

        .action-buttons {
            margin-top: 30px;
        }

        .result-badge {
            font-size: 1.2rem;
            padding: 8px 20px;
            border-radius: 20px;
            margin-bottom: 15px;
        }

        .passed {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
        }

        .failed {
            background: linear-gradient(45deg, #dc3545, #e35d6a);
            color: white;
        }

        /* Стили для детальных результатов */
        .details-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .question-item {
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
            border-left: 6px solid #dee2e6;
        }

        .question-item.correct {
            border-left-color: #28a745;
        }

        .question-item.incorrect {
            border-left-color: #dc3545;
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }

        .question-number {
            font-weight: bold;
            color: #2c3e50;
            font-size: 1.3rem;
        }

        .status-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9rem;
        }

        .badge-correct {
            background: #28a745;
            color: white;
        }

        .badge-incorrect {
            background: #dc3545;
            color: white;
        }

        .question-text {
            font-size: 1.1rem;
            margin-bottom: 20px;
            color: #495057;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .answer-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .answer-row {
                flex-direction: column;
            }
        }

        .answer-box {
            flex: 1;
            padding: 15px;
            border-radius: 8px;
            background: #f8f9fa;
        }

        .answer-box.student {
            background: #fff3cd;
            border: 2px solid #ffc107;
        }

        .answer-box.correct {
            background: #d4edda;
            border: 2px solid #28a745;
        }

        .answer-title {
            font-weight: bold;
            margin-bottom: 10px;
        }

        .answer-content {
            font-size: 1rem;
        }

        .explanation {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin-top: 15px;
            border-radius: 0 8px 8px 0;
        }

        .no-details {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }

        .statistics {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .stat-item {
            text-align: center;
            padding: 15px;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #2c3e50;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin-top: 5px;
        }
    </style>
</head>

<body>
    <div class="container result-container">
        <!-- Сообщения -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Основные результаты -->
        <div class="result-card">
            <h1 class="text-center mb-4"><i class="bi bi-award"></i> Результат теста</h1>
            <p class="text-center text-muted mb-4"><?php echo htmlspecialchars($test['title']); ?></p>

            <div class="score-circle">
                <?php echo $percent; ?>%
            </div>

            <div class="text-center mb-4">
                <span class="result-badge <?php echo $is_passed ? 'passed' : 'failed'; ?>">
                    <?php echo $is_passed ? 'Тест сдан' : 'Тест не сдан'; ?>
                </span>
            </div>

            <h3 class="text-center mb-4">Оценка:
                <?php
                $grade = $test_result['grade'] ?? calculateGrade($score, $total_questions, $test_id);
                echo $grade;
                ?>
            </h3>

            <div class="details">
                <div class="row text-center">
                    <div class="col-md-4">
                        <h4><?php echo $score; ?> / <?php echo $total_questions; ?></h4>
                        <p class="text-muted">Правильных ответов</p>
                    </div>
                    <div class="col-md-4">
                        <h4><?php echo $percent; ?>%</h4>
                        <p class="text-muted">Процент выполнения</p>
                    </div>
                    <div class="col-md-4">
                        <?php
                        $minutes = floor($time_spent / 60);
                        $seconds = $time_spent % 60;
                        ?>
                        <h4><?php echo sprintf("%02d:%02d", $minutes, $seconds); ?></h4>
                        <p class="text-muted">Затраченное время</p>
                    </div>
                </div>

                <hr>

                <div class="student-info">
                    <p><strong>Студент:</strong>
                        <?php echo htmlspecialchars($_SESSION['student']['last_name'] . ' ' . $_SESSION['student']['first_name']); ?>
                    </p>
                    <p><strong>Курс:</strong> <?php echo $_SESSION['student']['course_number']; ?></p>
                    <p><strong>Дата:</strong>
                        <?php echo isset($test_result['completed_at']) ? date('d.m.Y H:i', strtotime($test_result['completed_at'])) : date('d.m.Y H:i'); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Статистика -->
        <div class="statistics">
            <div class="row">
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-value text-success"><?php echo $score; ?></div>
                        <div class="stat-label">Правильные</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-value text-danger"><?php echo $total_questions - $score; ?></div>
                        <div class="stat-label">Неправильные</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-value text-primary"><?php echo $total_questions; ?></div>
                        <div class="stat-label">Всего вопросов</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-value" style="color: #6f42c1;"><?php echo $percent; ?>%</div>
                        <div class="stat-label">Результат</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Детальные результаты 
        <?php if (!empty($processed_results)): ?>
            <div class="details-section">
                <h3 class="mb-4"><i class="bi bi-list-check"></i> Детальный разбор вопросов</h3>

                <?php foreach ($processed_results as $index => $result): ?>
                    <div class="question-item <?php echo $result['is_correct'] ? 'correct' : 'incorrect'; ?>">
                        <div class="question-header">
                            <div class="question-number">
                                Вопрос <?php echo $index + 1; ?>
                            </div>
                            <div
                                class="status-badge <?php echo $result['is_correct'] ? 'badge-correct' : 'badge-incorrect'; ?>">
                                <i class="bi <?php echo $result['is_correct'] ? 'bi-check-circle' : 'bi-x-circle'; ?>"></i>
                                <?php echo $result['is_correct'] ? 'Правильно' : 'Неправильно'; ?>
                            </div>
                        </div>

                        <div class="question-text">
                            <?php echo htmlspecialchars($result['question_text']); ?>
                        </div>

                        <div class="answer-row">
                            <div class="answer-box student">
                                <div class="answer-title">Ваш ответ:</div>
                                <div class="answer-content">
                                    <?php if (!empty($result['student_answer_text'])): ?>
                                        <strong><?php echo $result['student_answer']; ?>.</strong>
                                        <?php echo htmlspecialchars($result['student_answer_text']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Нет ответа</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="answer-box correct">
                                <div class="answer-title">Правильный ответ:</div>
                                <div class="answer-content">
                                    <?php if (!empty($result['correct_answer_text'])): ?>
                                        <strong><?php echo $result['correct_answer']; ?>.</strong>
                                        <?php echo htmlspecialchars($result['correct_answer_text']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Информация отсутствует</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($result['explanation'])): ?>
                            <div class="explanation">
                                <i class="bi bi-lightbulb"></i>
                                <strong>Объяснение:</strong> <?php echo htmlspecialchars($result['explanation']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
        -->
            <div class="details-section">
                <div class="no-details">
                    <i class="bi bi-info-circle" style="font-size: 3rem;"></i>
                    <h4 class="mt-3">Детальная информация отсутствует</h4>
                    <p class="text-muted">Подробный разбор ответов будет доступен в ближайшее время.</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Кнопки действий -->
        <div class="action-buttons">
            <div class="d-grid gap-2">
                <a href="tests.php" class="btn btn-primary btn-lg">
                    <i class="bi bi-list-ul"></i> К списку тестов
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>