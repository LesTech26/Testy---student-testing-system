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
$student_course = $_SESSION['student']['course_number'] ?? 1;
$student_city = $_SESSION['student']['city'] ?? null;
$student_specialty = $_SESSION['student']['specialty'] ?? null;

try {
    // ДОПОЛНИТЕЛЬНО: Получаем актуальные данные студента из базы данных
    $stmt = $pdo->prepare("SELECT city, specialty, course_number FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $db_student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($db_student) {
        // Обновляем данные из базы, если они есть
        $student_city = $db_student['city'] ?: $student_city;
        $student_specialty = $db_student['specialty'] ?: $student_specialty;
        $student_course = $db_student['course_number'] ?: $student_course;
        
        // Обновляем сессию актуальными данными
        $_SESSION['student']['city'] = $student_city;
        $_SESSION['student']['specialty'] = $student_specialty;
        $_SESSION['student']['course_number'] = $student_course;
    }
    
    // Проверяем существование столбца city в таблице tests
    $checkCityColumn = $pdo->query("SHOW COLUMNS FROM tests LIKE 'city'")->fetch();
    $hasCityColumn = !empty($checkCityColumn);
    
    // Проверяем существование столбца specialty_id в таблице tests
    $checkSpecialtyColumn = $pdo->query("SHOW COLUMNS FROM tests LIKE 'specialty_id'")->fetch();
    $hasSpecialtyColumn = !empty($checkSpecialtyColumn);

    // Базовый SQL запрос
    $sql = "SELECT t.* FROM tests t WHERE t.is_active = 1";
    $params = [];
    
    // Условия для фильтрации
    $conditions = [];
    
    // Фильтр по курсу
    $conditions[] = "(t.course_id IS NULL OR t.course_id = ?)";
    $params[] = $student_course;
    
    // Фильтр по городу
    if ($hasCityColumn) {
        if (!empty($student_city)) {
            // Если у студента есть город, показываем тесты для его города ИЛИ тесты без города
            $conditions[] = "(t.city IS NULL OR t.city = '' OR t.city = ?)";
            $params[] = $student_city;
        }
        // Если города нет - не фильтруем по городу, показываем все тесты
    }
    
    // Фильтр по специальности
    if ($hasSpecialtyColumn && !empty($student_specialty)) {
        $conditions[] = "(t.specialty_id IS NULL OR t.specialty_id = (SELECT id FROM specialties WHERE name = ?))";
        $params[] = $student_specialty;
    }
    
    // Добавляем все условия в запрос
    if (!empty($conditions)) {
        $sql .= " AND " . implode(" AND ", $conditions);
    }
    
    $sql .= " ORDER BY t.title";
    
    // Выполняем запрос
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Для каждого теста получаем результат студента
    foreach ($tests as &$test) {
        $test['has_result'] = false;
        $test['student_score'] = 0;
        $test['student_total_questions'] = 0;
        $test['student_percent'] = 0;
        $test['student_grade'] = '';
        $test['student_passed'] = false;
        $test['completed_date'] = null;

        // Ищем результат в test_results
        $stmt = $pdo->prepare("
            SELECT score, grade, is_passed, completed_at, total_questions
            FROM test_results
            WHERE student_id = ? AND test_id = ?
            ORDER BY completed_at DESC
            LIMIT 1
        ");
        $stmt->execute([$student_id, $test['id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $test['has_result'] = true;
            $test['student_score'] = (int)($result['score'] ?? 0);
            $test['student_total_questions'] = (int)($result['total_questions'] ?? 1);
            $test['student_percent'] = ($test['student_total_questions'] > 0)
                ? round(($test['student_score'] / $test['student_total_questions']) * 100, 2)
                : 0;
            $test['student_grade'] = $result['grade'] ?? calculateGrade($test['student_score'], $test['student_total_questions']);
            $test['student_passed'] = (bool)($result['is_passed'] ?? false);
            $test['completed_date'] = $result['completed_at'] ?? null;
        }
    }
    unset($test);

    // Считаем пройденные тесты
    $passed_tests_count = array_reduce($tests, function($carry, $test) {
        return $carry + ($test['has_result'] && $test['student_passed'] ? 1 : 0);
    }, 0);

    // Получаем название специальности для отображения
    $specialty_name = '';
    if (!empty($student_specialty)) {
        $stmt = $pdo->prepare("SELECT name FROM specialties WHERE name = ?");
        $stmt->execute([$student_specialty]);
        $specialty = $stmt->fetch(PDO::FETCH_ASSOC);
        $specialty_name = $specialty ? $specialty['name'] : $student_specialty;
    }

} catch (PDOException $e) {
    $_SESSION['error'] = 'Ошибка при загрузке тестов.';
    error_log("Ошибка в tests.php: " . $e->getMessage());
    $tests = [];
    $passed_tests_count = 0;
    $specialty_name = '';
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Выбор теста</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="icon" href="logo.png">
    <style>
        body { background-color: #f8f9fa; padding-top: 20px; }
        .header {
            background: linear-gradient(45deg, #2c3e50, #3498db);
            color: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .card {
            transition: transform 0.3s, box-shadow 0.3s;
            border: none;
            border-radius: 10px;
            height: 100%;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        .test-status { position: absolute; top: 15px; right: 15px; }
        .result-badge {
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9rem;
        }
        .result-passed { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .result-failed { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .course-badge {
            display: inline-block;
            background-color: rgba(255, 193, 7, 0.9);
            color: #212529;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.85rem;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .city-badge {
            display: inline-block;
            background-color: rgba(32, 201, 151, 0.9);
            color: white;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.85rem;
            font-weight: bold;
            margin-left: 5px;
        }
        .specialty-badge {
            display: inline-block;
            background-color: rgba(111, 66, 193, 0.9);
            color: white;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.85rem;
            font-weight: bold;
            margin-left: 5px;
        }
        .grade-display {
            font-size: 2rem;
            font-weight: bold;
            text-align: center;
            margin: 15px 0;
            color: #2c3e50;
        }
        .test-disabled { opacity: 0.7; }
        .stats-box {
            background: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .stats-value {
            font-size: 2rem;
            font-weight: bold;
            color: #2c3e50;
        }
        .stats-label { color: #6c757d; font-size: 0.9rem; }
        .rules-box {
            background: #e8f4fd;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #17a2b8;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }
        .rules-title {
            font-weight: bold;
            color: #17a2b8;
            margin-bottom: 15px;
            font-size: 1.2rem;
        }
        .rules-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .rules-list li {
            margin-bottom: 10px;
            padding-left: 25px;
            position: relative;
        }
        .rules-list li i {
            position: absolute;
            left: 0;
            top: 3px;
            color: #17a2b8;
        }
        .rules-list li.text-danger i {
            color: #dc3545;
        }
        .info-box {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
        }
        .score-info {
            background-color: #f8f9fa;
            padding: 8px;
            border-radius: 5px;
            text-align: center;
            margin: 10px 0;
        }
        .student-info {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .debug-info {
            background: #e8f4f8;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-family: monospace;
            font-size: 12px;
            border-left: 4px solid #3498db;
        }
        .badge-danger {
            background-color: #dc3545 !important;
            color: white !important;
        }
        .grade-2 {
            color: #dc3545 !important;
            font-weight: bold;
        }
    </style>
</head>
<body>
<div class="container">

    <div class="header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-journal-text"></i> Выбор теста</h1>
                <p class="mb-0">
                    Студент: <?php echo htmlspecialchars($_SESSION['student']['last_name'] . ' ' . $_SESSION['student']['first_name']); ?>
                </p>
                <div class="student-info">
                    <span class="badge bg-light text-dark">
                        <i class="bi bi-mortarboard"></i> Курс: <?php echo $student_course; ?>
                    </span>
                    <?php if (!empty($student_city)): ?>
                        <span class="badge bg-info">
                            <i class="bi bi-geo-alt"></i> Город: <?php echo htmlspecialchars($student_city); ?>
                        </span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">
                            <i class="bi bi-exclamation-triangle"></i> Город не указан в базе данных
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($specialty_name)): ?>
                        <span class="badge bg-purple" style="background-color: #6f42c1;">
                            <i class="bi bi-briefcase"></i> Специальность: <?php echo htmlspecialchars($specialty_name); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <a href="logout.php" class="btn btn-light"
                   onclick="return confirm('Вы уверены, что хотите выйти?');">
                    <i class="bi bi-box-arrow-right"></i> Выйти
                </a>
            </div>
        </div>
    </div>

    <!-- Сообщения -->
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <!-- Статистика -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stats-box">
                <div class="stats-value"><?php echo count($tests); ?></div>
                <div class="stats-label">Доступно тестов</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-box">
                <div class="stats-value"><?php echo $passed_tests_count; ?></div>
                <div class="stats-label">Пройдено</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-box">
                <div class="stats-value"><?php echo max(0, count($tests) - $passed_tests_count); ?></div>
                <div class="stats-label">Осталось</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-box">
                <div class="stats-value"><?php echo $student_course; ?></div>
                <div class="stats-label">Ваш курс</div>
            </div>
        </div>
    </div>

    <!-- Правила прохождения тестирования -->
    <div class="rules-box">
        <div class="rules-title">
            <i class="bi bi-shield-lock"></i> Правила прохождения тестирования
        </div>
        <ul class="rules-list">
            <li>
                <i class="bi bi-check-circle"></i>
                <strong>Ограничение по времени:</strong> На каждый тест отводится фиксированное время (указано в карточке теста). По истечении времени тест автоматически завершается.
            </li>
            <li>
                <i class="bi bi-check-circle"></i>
                <strong>Проходной балл:</strong> Для успешной сдачи теста необходимо набрать указанный процент правильных ответов.
            </li>
            <li class="text-danger">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Запрещено:</strong> Переключение на другие вкладки или окна браузера во время тестирования.
            </li>
            <li class="text-danger">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Запрещено:</strong> Использование клавиш Print Screen, Win+Shift+S или любых других комбинаций для создания скриншотов.
            </li>
            <li class="text-danger">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Запрещено:</strong> Копирование, вырезание или вставка текста во время тестирования.
            </li>
            <li class="text-danger">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Запрещено:</strong> Использование инструментов разработчика (F12, Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+Shift+C).
            </li>
            <li class="text-danger">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Запрещено:</strong> Попытка закрыть вкладку или окно браузера до завершения теста.
            </li>
            <li>
                <i class="bi bi-info-circle"></i>
                <strong>Система безопасности:</strong> При обнаружении любого нарушения (скриншот, переключение вкладок, открытие инструментов разработчика) начисляется штрафной балл.
            </li>
            <li>
                <i class="bi bi-exclamation-diamond"></i>
                <strong>Последствия нарушений:</strong>
                <ul class="mt-2">
                    <li><span class="badge bg-warning text-dark">1-е нарушение</span> — предупреждение</li>
                    <li><span class="badge bg-danger">2-е нарушение</span> — тест автоматически завершается с оценкой <strong class="grade-2">2</strong></li>
                </ul>
            </li>
            <li>
                <i class="bi bi-check-circle"></i>
                <strong>Результат:</strong> После завершения теста (успешного или досрочного) вы увидите подробный разбор ответов и полученную оценку.
            </li>
            <li>
                <i class="bi bi-check-circle"></i>
                <strong>Повторное прохождение:</strong> Тест можно пройти только один раз. После получения результата повторный вход в тест невозможен.
            </li>
        </ul>
    </div>

    <!-- Информация о фильтрации -->
    <div class="info-box">
        <p class="mb-0">
            <i class="bi bi-info-circle text-primary"></i>
            <?php if (empty($student_city)): ?>
                <strong>Внимание!</strong> В вашем профиле не указан город. Показываются ВСЕ тесты для вашего курса.
                <br><small>Чтобы видеть тесты для вашего города, <a href="edit_profile.php" class="alert-link">укажите город в профиле</a>.</small>
            <?php else: ?>
                Показаны тесты для <strong><?php echo $student_course; ?> курса</strong>
                и города <strong><?php echo htmlspecialchars($student_city); ?></strong>
                (включая общие тесты без привязки)
            <?php endif; ?>
        </p>
    </div>

    <div class="row">
        <?php if (empty($tests)): ?>
            <div class="col-12">
                <div class="alert alert-warning text-center">
                    <i class="bi bi-exclamation-triangle fs-4"></i>
                    <h4 class="mt-2">Нет доступных тестов</h4>
                    <p>Для вашего курса пока нет доступных тестов.</p>
                    <hr>
                    <p class="text-muted small">
                        <i class="bi bi-gear"></i> 
                        Если вы считаете, что это ошибка, обратитесь к администратору.
                    </p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($tests as $test):
                $is_completed = $test['has_result'];
                $passed = $test['student_passed'];
                $grade = $test['student_grade'] ?? '';
                ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100 shadow <?php echo $is_completed ? 'test-disabled' : ''; ?>">
                        <div class="card-body">
                            <div class="d-flex flex-wrap gap-1 mb-2">
                                <?php if (!empty($test['course_id'])): ?>
                                    <span class="course-badge">
                                        <i class="bi bi-mortarboard"></i> <?php echo $test['course_id']; ?> курс
                                    </span>
                                <?php else: ?>
                                    <span class="course-badge" style="background-color: rgba(108, 117, 125, 0.9);">
                                        <i class="bi bi-globe"></i> Все курсы
                                    </span>
                                <?php endif; ?>
                                
                                <?php if (!empty($test['city'])): ?>
                                    <span class="city-badge">
                                        <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($test['city']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if ($is_completed): ?>
                                <div class="test-status">
                                    <span class="result-badge <?php echo $passed ? 'result-passed' : 'result-failed'; ?>">
                                        <i class="bi <?php echo $passed ? 'bi-check-circle' : 'bi-x-circle'; ?>"></i>
                                        <?php echo $passed ? 'Сдан' : 'Не сдан'; ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <h5 class="card-title text-primary mt-3"><?php echo htmlspecialchars($test['title']); ?></h5>
                            <p class="card-text text-muted"><?php echo htmlspecialchars($test['description']); ?></p>

                            <?php if ($is_completed): ?>
                                <div class="grade-display <?php echo ($grade == 2) ? 'grade-2' : ''; ?>">
                                    <?php echo htmlspecialchars($grade); ?>
                                </div>
                                <div class="score-info">
                                    <div class="text-muted">
                                        <?php echo $test['student_score']; ?> из <?php echo $test['student_total_questions']; ?> баллов
                                        (<?php echo $test['student_percent']; ?>%)
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="mt-3">
                                    <p class="card-text mb-1">
                                        <small class="text-muted">
                                            <i class="bi bi-clock"></i> Время: <?php echo $test['time_limit'] ?? 30; ?> мин.
                                        </small>
                                    </p>
                                    <p class="card-text mb-0">
                                        <small class="text-muted">
                                            <i class="bi bi-flag"></i> Проходной балл: <?php echo $test['passing_score'] ?? 60; ?>%
                                        </small>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer bg-transparent border-0 pt-0">
                            <?php if ($is_completed): ?>
                                <div class="d-grid">
                                    <a href="result.php?test_id=<?php echo $test['id']; ?>" class="btn btn-outline-info">
                                        <i class="bi bi-eye"></i> Результат
                                    </a>
                                </div>
                            <?php else: ?>
                                <?php
                                // Проверяем, может ли студент проходить этот тест (дополнительная фильтрация)
                                $can_take_test = true;
                                
                                // Проверяем по городу (если тест привязан к конкретному городу)
                                if (!empty($test['city']) && !empty($student_city) && $test['city'] != $student_city) {
                                    $can_take_test = false;
                                }
                                
                                // Проверяем по специальности (если тест привязан к конкретной специальности)
                                if ($hasSpecialtyColumn && !empty($test['specialty_id']) && !empty($student_specialty)) {
                                    $spec_stmt = $pdo->prepare("SELECT id FROM specialties WHERE name = ?");
                                    $spec_stmt->execute([$student_specialty]);
                                    $spec_id = $spec_stmt->fetchColumn();
                                    if ($spec_id && $test['specialty_id'] != $spec_id) {
                                        $can_take_test = false;
                                    }
                                }
                                ?>
                                
                                <?php if ($can_take_test): ?>
                                    <div class="d-grid">
                                        <a href="test.php?test_id=<?php echo $test['id']; ?>" class="btn btn-primary">
                                            <i class="bi bi-play-circle"></i> Пройти тест
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="d-grid">
                                        <button class="btn btn-secondary" disabled>
                                            <i class="bi bi-lock"></i> Недоступен
                                        </button>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="text-center mt-4">
        <a href="logout.php" class="btn btn-secondary"
           onclick="return confirm('Вы уверены, что хотите выйти?');">
            <i class="bi bi-arrow-left"></i> Выйти
        </a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>