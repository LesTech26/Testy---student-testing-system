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

$test_id = (int) $_GET['test_id'];
$student_id = $_SESSION['student']['id'];

// ВАЖНО: проверяем, не проходил ли студент этот тест ранее
$check_stmt = $pdo->prepare("SELECT id FROM test_results WHERE student_id = ? AND test_id = ?");
$check_stmt->execute([$student_id, $test_id]);
if ($check_stmt->fetch()) {
    header('Location: result.php?test_id=' . $test_id);
    exit();
}

// Получаем информацию о тесте
try {
    $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ? AND is_active = 1");
    $stmt->execute([$test_id]);
    $test = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$test) {
        $_SESSION['error'] = 'Тест не найден или неактивен';
        header('Location: tests.php');
        exit();
    }

} catch (PDOException $e) {
    $_SESSION['error'] = 'Ошибка при загрузке теста: ' . $e->getMessage();
    header('Location: tests.php');
    exit();
}

// Получаем вопросы для теста
try {
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE test_id = ?");
    $stmt->execute([$test_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($questions)) {
        $_SESSION['error'] = 'В тесте нет вопросов';
        header('Location: tests.php');
        exit();
    }

} catch (PDOException $e) {
    $_SESSION['error'] = 'Ошибка при загрузке вопросов: ' . $e->getMessage();
    header('Location: tests.php');
    exit();
}

// Перемешиваем вопросы для случайного порядка
shuffle($questions);

// Инициализируем сессию для теста если ее нет или начался новый тест
if (!isset($_SESSION['test_session']) || $_SESSION['test_session']['test_id'] != $test_id) {
    $_SESSION['test_session'] = [
        'test_id' => $test_id,
        'questions' => $questions,
        'current_question' => 0,
        'answers' => [],
        'start_time' => time(),
        'total_questions' => count($questions),
        'question_ids' => array_column($questions, 'id'),
        'screenshot_attempts' => 0,
        'test_terminated' => false
    ];
}

// Получаем данные из сессии
$test_session = $_SESSION['test_session'];

// Проверяем, не завершен ли тест из-за нарушений
if ($test_session['test_terminated']) {
    // Если тест завершен, но результат еще не сохранен, завершаем с оценкой 2
    autoFailTest($pdo, $student_id, $test_id, $test_session, $test);
    exit();
}

// Проверяем количество попыток нарушений
if ($test_session['screenshot_attempts'] >= 2) {
    // Автоматически завершаем тест с оценкой 2
    autoFailTest($pdo, $student_id, $test_id, $test_session, $test);
    exit();
}

$questions = $test_session['questions'];
$current_index = $test_session['current_question'];
$total_questions = $test_session['total_questions'];

// Устанавливаем время на тест
$time_limit = $test['time_limit'] ?? 30;
$time_limit_seconds = $time_limit * 60;
$time_elapsed = time() - $test_session['start_time'];
$remaining_time = max(0, $time_limit_seconds - $time_elapsed);

if ($remaining_time <= 0) {
    header('Location: complete_test.php?timeout=1&test_id=' . $test_id);
    exit();
}

// Обработка отправки ответа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['answer']) && $_POST['answer'] !== '') {
        $question_id = (int) $_POST['question_id'];
        $answer = trim($_POST['answer']);

        $_SESSION['test_session']['answers'][$question_id] = $answer;
        $_SESSION['test_session']['current_question'] = $current_index + 1;
        $current_index = $_SESSION['test_session']['current_question'];

        if ($current_index >= $total_questions) {
            header('Location: complete_test.php?test_id=' . $test_id);
            exit();
        } else {
            header('Location: test.php?test_id=' . $test_id);
            exit();
        }
    }
}

if ($current_index >= $total_questions) {
    header('Location: complete_test.php?test_id=' . $test_id);
    exit();
}

$current_question = $questions[$current_index];

// Функция для автоматического завершения теста с оценкой 2
function autoFailTest($pdo, $student_id, $test_id, $test_session, $test) {
    $total_questions = $test_session['total_questions'];
    $time_spent = time() - $test_session['start_time'];
    
    // Получаем все вопросы теста
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE test_id = ?");
    $stmt->execute([$test_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $detailed_results = [];
    $wrong_answers = ['A', 'B', 'C', 'D'];
    
    // Для каждого вопроса выбираем неправильный ответ
    foreach ($questions as $question) {
        $correct_answer = $question['correct_answer'];
        
        // Выбираем неправильный ответ (не равный правильному)
        $wrong_answer = $correct_answer;
        foreach ($wrong_answers as $ans) {
            if ($ans != $correct_answer) {
                $wrong_answer = $ans;
                break;
            }
        }
        
        $detailed_results[] = [
            'question_id' => $question['id'],
            'student_answer' => $wrong_answer,
            'correct_answer' => $correct_answer,
            'is_correct' => 0
        ];
    }
    
    // Сохраняем результат в БД
    try {
        $sql = "INSERT INTO test_results 
                (student_id, test_id, score, total_questions, percent, grade, is_passed, completed_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $params = [$student_id, $test_id, 0, $total_questions, 0, 2, 0];
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $result_id = $pdo->lastInsertId();
        
        // Сохраняем детальные результаты в сессию
        $_SESSION['test_detailed_results'] = $detailed_results;
        
        // Сохраняем результат в сессии
        $_SESSION['test_result'] = [
            'id' => $result_id,
            'test_id' => $test_id,
            'test_title' => $test['title'],
            'score' => 0,
            'total_questions' => $total_questions,
            'percent' => 0,
            'grade' => 2,
            'is_passed' => false,
            'time_spent' => $time_spent,
            'terminated' => true
        ];
        
        // Очищаем сессию теста
        unset($_SESSION['test_session']);
        
        // Перенаправляем на страницу результата
        header('Location: result.php?test_id=' . $test_id);
        exit();
        
    } catch (PDOException $e) {
        error_log("Error in autoFailTest: " . $e->getMessage());
        $_SESSION['error'] = 'Ошибка при сохранении результата.';
        header('Location: tests.php');
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($test['title']); ?> - Вопрос <?php echo $current_index + 1; ?> из
        <?php echo $total_questions; ?>
    </title>
    <link rel="icon" href="logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
        }

        .test-container {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .test-header {
            background: linear-gradient(45deg, #2c3e50, #3498db);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .question-card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            border-left: 5px solid #3498db;
            flex: 1;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            transition: background-color 0.3s;
        }

        .question-card.warning {
            background-color: #fff3cd;
            border-left-color: #ffc107;
        }

        /* Стили для изображения вопроса */
        .question-image-container {
            text-align: center;
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #dee2e6;
        }

        .question-image {
            max-width: 100%;
            max-height: 400px;
            object-fit: contain;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.3s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .question-image:hover {
            transform: scale(1.02);
        }

        .image-caption {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 10px;
        }

        .answer-option {
            padding: 15px 20px;
            margin-bottom: 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        .answer-option:hover {
            background-color: #f8f9fa;
            border-color: #3498db;
        }

        .answer-option.selected {
            background-color: #e3f2fd;
            border-color: #2196f3;
        }

        .progress-container {
            background: white;
            padding: 15px;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            margin-top: 20px;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        .timer {
            font-size: 1.5rem;
            font-weight: bold;
            color: #dc3545;
            text-align: center;
            background: white;
            padding: 10px;
            border-radius: 8px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        .question-dots {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        .question-dot {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            cursor: default;
            transition: all 0.3s;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        .question-dot.current {
            background-color: #3498db;
            color: white;
            transform: scale(1.1);
        }

        .question-dot.answered {
            background-color: #28a745;
            color: white;
        }

        .question-dot.unanswered {
            background-color: #e9ecef;
            color: #6c757d;
        }

        .question-counter {
            font-size: 1.2rem;
            font-weight: bold;
            color: #2c3e50;
            text-align: center;
            margin-bottom: 20px;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        .question-counter span {
            background-color: #3498db;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
        }

        .protected-text {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            cursor: default;
        }

        .context-menu-block {
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            -khtml-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        ::selection {
            background-color: transparent;
            color: inherit;
        }

        ::-moz-selection {
            background-color: transparent;
            color: inherit;
        }

        img {
            -webkit-user-drag: none;
            -khtml-user-drag: none;
            -moz-user-drag: none;
            -o-user-drag: none;
            user-drag: none;
            pointer-events: auto;
        }

        @media print {
            * {
                display: none !important;
            }
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            font-weight: bold;
            z-index: 10000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            animation: slideIn 0.3s ease;
            max-width: 350px;
        }

        .notification.warning {
            background: #ffc107;
            color: #212529;
        }

        .notification.danger {
            background: #dc3545;
        }

        .notification.info {
            background: #17a2b8;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Стили для кастомного модального окна предупреждений */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 99999;
            animation: fadeIn 0.3s;
        }

        .modal-overlay .modal-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            max-width: 500px;
            text-align: center;
            animation: scaleIn 0.3s;
        }

        .modal-overlay .modal-content.warning {
            border-top: 5px solid #ffc107;
        }

        .modal-overlay .modal-content.danger {
            border-top: 5px solid #dc3545;
        }

        .modal-overlay .modal-icon {
            font-size: 4rem;
            margin-bottom: 20px;
        }

        .modal-overlay .modal-title {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 15px;
        }

        .modal-overlay .modal-text {
            color: #6c757d;
            margin-bottom: 20px;
        }

        .modal-overlay .modal-counter {
            font-size: 1.2rem;
            font-weight: bold;
            margin: 15px 0;
        }

        /* Стили для Bootstrap модального окна с изображением */
        .modal-body {
            min-height: 300px;
            background-color: #f8f9fa;
            display: flex !important;
            justify-content: center !important;
            align-items: center !important;
        }

        #modalImage {
            max-width: 100%;
            max-height: 80vh;
            width: auto !important;
            height: auto !important;
            object-fit: contain;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .modal-dialog.modal-lg {
            max-width: 90vw;
            margin: 1.75rem auto;
        }

        @media (min-width: 992px) {
            .modal-dialog.modal-lg {
                max-width: 800px;
            }
        }

        .modal-content {
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }

        .modal-header {
            border-bottom: 1px solid #dee2e6;
            background-color: #f8f9fa;
        }

        .modal-footer {
            border-top: 1px solid #dee2e6;
            background-color: #f8f9fa;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes scaleIn {
            from {
                transform: scale(0.9);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .progress {
            height: 10px;
            margin-top: 10px;
        }

        @keyframes pulse {
            0% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }

            100% {
                opacity: 1;
            }
        }

        .timer-warning {
            animation: pulse 1s infinite;
        }

        .attempts-indicator {
            font-size: 0.9rem;
            margin: 10px 0;
            padding: 8px 15px;
            background: #f8f9fa;
            border-radius: 5px;
            text-align: center;
        }

        .attempts-indicator span {
            font-weight: bold;
            color:
                <?php echo $test_session['screenshot_attempts'] >= 1 ? '#dc3545' : '#28a745'; ?>
            ;
        }

        /* Стили для текстового ответа */
        .text-answer-container {
            margin-top: 20px;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }

        .text-answer-container textarea {
            border: 2px solid #dee2e6;
            border-radius: 8px;
            transition: border-color 0.3s;
        }

        .text-answer-container textarea:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

        .btn-submit-answer {
            background-color: #3498db;
            border: none;
            padding: 10px 30px;
            font-weight: bold;
            transition: all 0.3s;
        }

        .btn-submit-answer:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
    </style>
</head>

<body class="context-menu-block">
    <canvas id="screenshotCanvas" style="display: none; width: 100%; height: 100%; position: fixed; top: 0; left: 0; pointer-events: none;"></canvas>

    <div class="container test-container">
        <div class="test-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-journal-text"></i> <?php echo htmlspecialchars($test['title']); ?></h1>
                    <p class="mb-0"><?php echo htmlspecialchars($test['description']); ?></p>
                    <div class="student-info">
                        <i class="bi bi-person"></i>
                        <?php echo htmlspecialchars($_SESSION['student']['last_name'] . ' ' . $_SESSION['student']['first_name']); ?>
                    </div>
                </div>
                <div class="text-end">
                    <div class="timer" id="timer">
                        <i class="bi bi-clock"></i>
                        <span id="timer-minutes">00</span>:<span id="timer-seconds">00</span>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['error']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="question-counter">
            <span>Вопрос <?php echo $current_index + 1; ?> из <?php echo $total_questions; ?></span>
        </div>

        <div class="attempts-indicator">
            <i class="bi bi-shield-shaded"></i> Попытки нарушения:
            <span id="attemptsDisplay"><?php echo $test_session['screenshot_attempts']; ?></span> из 2
            <?php if ($test_session['screenshot_attempts'] >= 1): ?>
                <span class="badge bg-warning text-dark ms-2">Осталось
                    <?php echo 2 - $test_session['screenshot_attempts']; ?></span>
            <?php endif; ?>
        </div>

        <div class="question-dots">
            <?php
            foreach ($questions as $index => $question):
                $question_id = $question['id'];
                $is_answered = isset($test_session['answers'][$question_id]);
                $is_current = ($index === $current_index);

                $class = 'question-dot ';
                if ($is_current) {
                    $class .= 'current';
                } elseif ($is_answered) {
                    $class .= 'answered';
                } else {
                    $class .= 'unanswered';
                }
                ?>
                <div class="<?php echo $class; ?>" title="Вопрос <?php echo $index + 1; ?>">
                    <?php echo $index + 1; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="POST" id="testForm">
            <input type="hidden" name="question_id" value="<?php echo $current_question['id']; ?>">
            <input type="hidden" name="test_id" value="<?php echo $test_id; ?>">
            <input type="hidden" name="answer" id="selectedAnswer" value="">

            <div class="question-card" id="questionCard">
                <div class="question-header mb-4">
                    <h3>Вопрос #<?php echo $current_index + 1; ?></h3>
                    <p class="lead protected-text" id="questionText">
                        <?php echo htmlspecialchars($current_question['question_text']); ?>
                    </p>
                    
                    <!-- ===== БЛОК ДЛЯ ОТОБРАЖЕНИЯ ИЗОБРАЖЕНИЯ ===== -->
                    <?php if (!empty($current_question['question_image'])): ?>
                        <div class="question-image-container">
                            <img src="../<?php echo htmlspecialchars($current_question['question_image']); ?>" 
                                 alt="Изображение к вопросу" 
                                 class="question-image"
                                 onclick="openImageModal(this.src)"
                                 title="Нажмите для увеличения">
                            <div class="image-caption">
                                <i class="bi bi-image"></i> Изображение к вопросу (нажмите для увеличения)
                            </div>
                        </div>
                    <?php endif; ?>
                    <!-- ===== КОНЕЦ БЛОКА ДЛЯ ИЗОБРАЖЕНИЯ ===== -->
                </div>

                <?php
                // ОТЛАДКА: выводим тип вопроса (можно удалить после исправления)
                $question_type = $current_question['question_type'] ?? '';
                
                // Проверяем несколько вариантов типа текстового вопроса
                $is_text_question = (
                    $question_type === 'text_input' || 
                    $question_type === 'text' || 
                    $question_type === 'open' ||
                    empty($current_question['option_a']) && empty($current_question['option_b']) && 
                    empty($current_question['option_c']) && empty($current_question['option_d'])
                );
                
                if (!$is_text_question):
                    // Вопрос с вариантами ответов
                    $options = [
                        'A' => $current_question['option_a'] ?? null,
                        'B' => $current_question['option_b'] ?? null,
                        'C' => $current_question['option_c'] ?? null,
                        'D' => $current_question['option_d'] ?? null
                    ];

                    $options = array_filter($options, function ($value) {
                        return !empty($value);
                    });

                    $saved_answer = isset($test_session['answers'][$current_question['id']])
                        ? $test_session['answers'][$current_question['id']]
                        : '';
                    ?>

                    <div class="answers-container">
                        <?php foreach ($options as $letter => $option_text): ?>
                            <div class="answer-option protected-text <?php echo $saved_answer === $letter ? 'selected' : ''; ?>"
                                onclick="selectAnswer('<?php echo $letter; ?>', this)" data-letter="<?php echo $letter; ?>">
                                <div class="d-flex align-items-center">
                                    <div class="answer-letter me-3">
                                        <strong><?php echo $letter; ?>.</strong>
                                    </div>
                                    <div class="answer-text flex-grow-1">
                                        <?php echo htmlspecialchars($option_text); ?>
                                    </div>
                                    <?php if ($saved_answer === $letter): ?>
                                        <div class="answer-check ms-2">
                                            <i class="bi bi-check-circle-fill text-success"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <!-- Текстовый вопрос -->
                    <div class="text-answer-container">
                        <div class="mb-3">
                            <label for="text_answer" class="form-label fw-bold">
                                <i class="bi bi-pencil-square"></i> Ваш ответ:
                            </label>
                            <textarea class="form-control form-control-lg" 
                                      id="text_answer" 
                                      name="text_answer" 
                                      rows="4" 
                                      placeholder="Введите ваш ответ здесь..."
                                      style="font-size: 1.1rem;"><?php 
                                        echo isset($test_session['answers'][$current_question['id']]) 
                                            ? htmlspecialchars($test_session['answers'][$current_question['id']]) 
                                            : ''; 
                                      ?></textarea>
                            <div class="form-text mt-2">
                                <i class="bi bi-info-circle"></i> 
                                Введите ваш ответ в поле выше. После ввода нажмите кнопку "Отправить ответ".
                            </div>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-primary btn-submit-answer btn-lg" onclick="submitTextAnswer()">
                                <i class="bi bi-check-circle"></i> Отправить ответ
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </form>

        <div class="progress-container">
            <div class="container">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <div class="progress">
                            <div class="progress-bar bg-success" role="progressbar"
                                style="width: <?php echo (count(array_filter($test_session['answers'])) / $total_questions) * 100; ?>%"
                                aria-valuenow="<?php echo count(array_filter($test_session['answers'])); ?>"
                                aria-valuemin="0" aria-valuemax="<?php echo $total_questions; ?>">
                            </div>
                        </div>
                        <div class="text-center mt-2">
                            <i class="bi bi-check-circle text-success"></i>
                            <span id="answeredCount">
                                <?php echo count(array_filter($test_session['answers'])); ?>
                            </span> из <?php echo $total_questions; ?> отвечено
                        </div>
                    </div>
                    <div class="col-md-6 text-end">
                        <small class="text-muted">
                            <i class="bi bi-shield-lock"></i>
                            Защищенный режим тестирования
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно для увеличения изображения -->
    <div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="imageModalLabel">
                        <i class="bi bi-image"></i> Изображение к вопросу
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body d-flex justify-content-center align-items-center p-3">
                    <img src="" id="modalImage" class="img-fluid" alt="Увеличенное изображение">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Таймер
        let totalSeconds = <?php echo $remaining_time; ?>;
        const minutesElement = document.getElementById('timer-minutes');
        const secondsElement = document.getElementById('timer-seconds');

        // Максимальное количество попыток нарушения (2 попытки)
        const MAX_VIOLATION_ATTEMPTS = 2;
        let violationAttempts = <?php echo $test_session['screenshot_attempts']; ?>;
        const attemptsDisplay = document.getElementById('attemptsDisplay');

        // Проходной балл из настроек теста
        const passingScore = <?php echo $test['passing_score'] ?? 60; ?>;
        
        // Флаг блокировки повторных предупреждений
        let isWarningShown = false;
        let processingAttempt = false;
        let lastAttemptTime = 0;
        const ATTEMPT_COOLDOWN = 2000; // 2 секунды между попытками
        
        // Для отслеживания переключения вкладок
        let lastVisibilityChange = Date.now();
        let isTestActive = true;

        // Функция для открытия модального окна с изображением
        function openImageModal(imageSrc) {
            const modal = new bootstrap.Modal(document.getElementById('imageModal'));
            document.getElementById('modalImage').src = imageSrc;
            modal.show();
        }

        function showNotification(message, type = 'warning') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class="bi ${type === 'danger' ? 'bi-exclamation-triangle' : type === 'warning' ? 'bi-exclamation-circle' : 'bi-info-circle'}"></i>
                ${message}
            `;
            document.body.appendChild(notification);

            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        function showModal(title, message, type = 'warning', autoClose = true) {
            return new Promise((resolve) => {
                if (isWarningShown) return resolve();

                isWarningShown = true;

                const overlay = document.createElement('div');
                overlay.className = 'modal-overlay';

                const modal = document.createElement('div');
                modal.className = `modal-content ${type}`;

                let icon = type === 'danger'
                    ? '<i class="bi bi-shield-exclamation modal-icon" style="color: #dc3545;"></i>'
                    : '<i class="bi bi-exclamation-triangle modal-icon" style="color: #ffc107;"></i>';

                modal.innerHTML = `
                    ${icon}
                    <div class="modal-title">${title}</div>
                    <div class="modal-text">${message}</div>
                    <div class="modal-counter">Попытка ${violationAttempts} из ${MAX_VIOLATION_ATTEMPTS}</div>
                    <button class="btn ${type === 'danger' ? 'btn-danger' : 'btn-warning'}" onclick="this.closest('.modal-overlay').remove(); isWarningShown = false; resolve();">
                        Понятно
                    </button>
                `;

                overlay.appendChild(modal);
                document.body.appendChild(overlay);

                if (autoClose && type !== 'danger') {
                    setTimeout(() => {
                        if (document.body.contains(overlay)) {
                            overlay.remove();
                            isWarningShown = false;
                            resolve();
                        }
                    }, 5000);
                }
            });
        }

        async function handleViolationAttempt(reason = 'violation') {
            // Проверяем кулдаун
            const now = Date.now();
            if (now - lastAttemptTime < ATTEMPT_COOLDOWN) {
                console.log('Attempt ignored - cooldown');
                return;
            }
            
            if (processingAttempt || violationAttempts >= MAX_VIOLATION_ATTEMPTS) {
                console.log('Attempt ignored - processing or max attempts');
                return;
            }
            
            processingAttempt = true;
            lastAttemptTime = now;

            violationAttempts++;
            attemptsDisplay.textContent = violationAttempts;

            try {
                await fetch('update_screenshot_counter.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'test_id=<?php echo $test_id; ?>&attempts=' + violationAttempts
                });
            } catch (error) {
                console.error('Error:', error);
            }

            document.getElementById('questionCard').classList.add('warning');

            if (violationAttempts >= MAX_VIOLATION_ATTEMPTS) {
                // При достижении лимита показываем сообщение и перезагружаем страницу
                await showModal('Тест завершен', 'Превышен лимит нарушений безопасности. Тест будет завершен с оценкой 2.', 'danger', false);
                window.location.reload();
            } else {
                // При первом нарушении показываем предупреждение
                let message = 'Обнаружена попытка скриншота! Это нарушение безопасности. Осталось попыток: ' + (MAX_VIOLATION_ATTEMPTS - violationAttempts);
                if (reason === 'tab_switch') {
                    message = 'Обнаружено переключение на другую вкладку! Это нарушение безопасности. Осталось попыток: ' + (MAX_VIOLATION_ATTEMPTS - violationAttempts);
                } else if (reason === 'window_switch') {
                    message = 'Обнаружено переключение на другое окно! Это нарушение безопасности. Осталось попыток: ' + (MAX_VIOLATION_ATTEMPTS - violationAttempts);
                }
                
                await showModal('Обнаружено нарушение', message, 'warning');
                document.getElementById('questionCard').classList.remove('warning');
            }
            
            processingAttempt = false;
        }

        // ===== ЗАЩИТА ОТ ПЕРЕКЛЮЧЕНИЯ ВКЛАДОК =====
        document.addEventListener('visibilitychange', function() {
            if (!isTestActive) return;
            
            const now = Date.now();
            
            if (document.hidden) {
                // Страница скрыта (переключение на другую вкладку)
                lastVisibilityChange = now;
                console.log('Tab switched away');
            } else {
                // Страница снова видима
                const timeHidden = now - lastVisibilityChange;
                console.log('Tab back, was hidden for:', timeHidden, 'ms');
                
                // Если вкладка была скрыта более 1 секунды, считаем это нарушением
                if (timeHidden > 1000) {
                    handleViolationAttempt('tab_switch');
                }
            }
        });

        // ===== ЗАЩИТА ОТ ПЕРЕКЛЮЧЕНИЯ ОКОН =====
        let windowBlurTime = 0;
        
        window.addEventListener('blur', function() {
            if (!isTestActive) return;
            windowBlurTime = Date.now();
            console.log('Window blurred');
        });

        window.addEventListener('focus', function() {
            if (!isTestActive) return;
            
            const focusTime = Date.now();
            const timeBlurred = focusTime - windowBlurTime;
            
            // Если окно было не в фокусе более 1 секунды, считаем это нарушением
            if (timeBlurred > 1000 && windowBlurTime > 0) {
                console.log('Window was out of focus for:', timeBlurred, 'ms');
                handleViolationAttempt('window_switch');
            }
        });

        // ===== ЗАЩИТА ОТ СКРЫТИЯ СТРАНИЦЫ =====
        let pageHideTime = 0;
        
        window.addEventListener('pagehide', function() {
            pageHideTime = Date.now();
        });

        window.addEventListener('pageshow', function() {
            if (pageHideTime > 0 && (Date.now() - pageHideTime) > 1000) {
                handleViolationAttempt('tab_switch');
            }
        });

        // ===== ЗАЩИТА ОТ PRINT SCREEN =====
        document.addEventListener('keyup', function(e) {
            if (e.key === 'PrintScreen' || e.keyCode === 44) {
                handleViolationAttempt('screen_capture');
            }
        });

        // ===== ЗАЩИТА ОТ WIN+SHIFT+S =====
        document.addEventListener('keydown', function(e) {
            // Проверяем комбинацию Win+Shift+S
            if (e.shiftKey && e.metaKey && (e.code === 'KeyS' || e.key === 's' || e.key === 'ы')) {
                e.preventDefault();
                handleViolationAttempt('screen_capture');
                return false;
            }

            // Альтернативная проверка Win+Shift+S
            if (e.shiftKey && (e.metaKey || e.ctrlKey) && (e.keyCode === 83 || e.key === 's' || e.key === 'S' || e.key === 'ы' || e.key === 'Ы')) {
                e.preventDefault();
                handleViolationAttempt('screen_capture');
                return false;
            }

            // Защита от всех комбинаций с PrintScreen
            if (e.key === 'PrintScreen' || e.key === 'Snapshot' || e.keyCode === 44) {
                e.preventDefault();
                handleViolationAttempt('screen_capture');
                return false;
            }

            // Защита от Alt+PrintScreen
            if (e.altKey && (e.key === 'PrintScreen' || e.keyCode === 44)) {
                e.preventDefault();
                handleViolationAttempt('screen_capture');
                return false;
            }

            // Защита от Ctrl+PrintScreen
            if (e.ctrlKey && (e.key === 'PrintScreen' || e.keyCode === 44)) {
                e.preventDefault();
                handleViolationAttempt('screen_capture');
                return false;
            }

            // Защита от комбинаций для Mac
            if (e.key === '4' && e.shiftKey && e.metaKey) {
                e.preventDefault();
                handleViolationAttempt('screen_capture');
                return false;
            }

            if (e.key === '3' && e.shiftKey && e.metaKey) {
                e.preventDefault();
                handleViolationAttempt('screen_capture');
                return false;
            }

            if (e.key === '5' && e.shiftKey && e.metaKey) {
                e.preventDefault();
                handleViolationAttempt('screen_capture');
                return false;
            }

            // Защита от Ctrl+P для печати
            if (e.ctrlKey && (e.key === 'p' || e.key === 'P' || e.key === 'з' || e.key === 'З')) {
                e.preventDefault();
                showModal('Печать запрещена', 'Печать страницы во время тестирования невозможна.', 'warning');
                return false;
            }

            // Защита от Alt+F4
            if (e.altKey && (e.key === 'F4' || e.keyCode === 115)) {
                e.preventDefault();
                showModal('Закрытие окна', 'Закрытие окна во время тестирования запрещено.', 'warning');
                return false;
            }
        });

        // Защита от контекстного меню
        document.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            showNotification('Контекстное меню заблокировано', 'warning');
            return false;
        });

        // Защита от копирования
        document.addEventListener('copy', function(e) {
            e.preventDefault();
            showNotification('Копирование текста запрещено', 'warning');
            return false;
        });

        document.addEventListener('cut', function(e) {
            e.preventDefault();
            return false;
        });

        document.addEventListener('paste', function(e) {
            e.preventDefault();
            return false;
        });

        // Защита от F12
        document.addEventListener('keydown', function(e) {
            if (e.key === 'F12' || 
                (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'J' || e.key === 'C'))) {
                e.preventDefault();
                handleViolationAttempt('screen_capture');
                return false;
            }
        });

        // Таймер
        function updateTimer() {
            if (totalSeconds <= 0) {
                clearInterval(timerInterval);
                window.location.href = 'complete_test.php?timeout=1&test_id=<?php echo $test_id; ?>';
                return;
            }

            const minutes = Math.floor(totalSeconds / 60);
            const seconds = totalSeconds % 60;

            minutesElement.textContent = minutes.toString().padStart(2, '0');
            secondsElement.textContent = seconds.toString().padStart(2, '0');

            if (totalSeconds <= 300) {
                document.getElementById('timer').style.color = '#dc3545';
                if (totalSeconds <= 60) {
                    document.getElementById('timer').classList.add('timer-warning');
                }
            }

            totalSeconds--;
        }

        const timerInterval = setInterval(updateTimer, 1000);
        updateTimer();

        function selectAnswer(value, element) {
            document.querySelectorAll('.answer-option').forEach(option => {
                option.classList.remove('selected');
            });

            element.classList.add('selected');
            document.getElementById('selectedAnswer').value = value;
            document.getElementById('testForm').submit();
        }

        function submitTextAnswer() {
            const textAnswer = document.getElementById('text_answer').value;
            if (textAnswer.trim() === '') {
                showNotification('Пожалуйста, введите ответ', 'warning');
                return;
            }
            document.getElementById('selectedAnswer').value = textAnswer;
            document.getElementById('testForm').submit();
        }

        document.addEventListener('DOMContentLoaded', function() {
            const savedAnswer = '<?php echo isset($test_session['answers'][$current_question['id']]) ? $test_session['answers'][$current_question['id']] : ''; ?>';
            if (savedAnswer) {
                // Для текстовых вопросов
                const textArea = document.getElementById('text_answer');
                if (textArea) {
                    textArea.value = savedAnswer;
                }
                
                // Для вопросов с вариантами
                const answerElement = document.querySelector(`.answer-option[data-letter="${savedAnswer}"]`);
                if (answerElement) {
                    answerElement.classList.add('selected');
                }
            }
        });

        setTimeout(function() {
            if (totalSeconds <= 300 && totalSeconds > 60) {
                showNotification(`Осталось ${Math.floor(totalSeconds / 60)} минут`, 'info');
            }
        }, 1000);

        document.addEventListener('keydown', function(e) {
            if (!e.target.matches('input, textarea, select')) {
                if (e.key >= '1' && e.key <= '4') {
                    const index = parseInt(e.key) - 1;
                    const options = document.querySelectorAll('.answer-option');
                    if (options[index]) {
                        const letter = options[index].getAttribute('data-letter');
                        selectAnswer(letter, options[index]);
                    }
                }
            }
        });
    </script>
</body>

</html>