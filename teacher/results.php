<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';

// Проверка авторизации преподавателя
if (!isset($_SESSION['teacher'])) {
    header('Location: login.php');
    exit();
}

$teacher_id = $_SESSION['teacher']['id'];
$teacher_city = $_SESSION['teacher']['city'] ?? null;

// Проверяем, существует ли столбец city в таблице tests
$checkTestsColumn = $pdo->query("SHOW COLUMNS FROM tests LIKE 'city'")->fetch();
$has_city_in_tests = !empty($checkTestsColumn);

// Проверяем, существует ли столбец city в таблице students
$checkStudentsColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'city'")->fetch();
$has_city_in_students = !empty($checkStudentsColumn);

// Обработка редактирования оценки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_grade'])) {
    $result_id = intval($_POST['result_id']);
    $grade = trim($_POST['grade']);
    
    // Проверяем доступ к результату через тест
    $check_sql = "SELECT r.*, t.city 
                 FROM test_results r 
                 JOIN tests t ON r.test_id = t.id 
                 WHERE r.id = ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$result_id]);
    $result_to_update = $check_stmt->fetch();
    
    if (!$result_to_update) {
        $_SESSION['error'] = 'Результат не найден.';
    } elseif ($has_city_in_tests && $teacher_city && $result_to_update['city'] !== null && $result_to_update['city'] !== $teacher_city) {
        $_SESSION['error'] = 'У вас нет прав на редактирование этого результата.';
    } else {
        $stmt = $pdo->prepare("UPDATE test_results SET grade = ? WHERE id = ?");
        $stmt->execute([$grade, $result_id]);
        $_SESSION['success'] = 'Оценка успешно обновлена.';
    }
    
    header('Location: results.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit();
}

// Обработка удаления результата
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_result'])) {
    // Проверка CSRF-токена
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = 'Ошибка безопасности. Попробуйте еще раз.';
        header('Location: results.php');
        exit();
    }
    
    $result_id = intval($_POST['result_id']);
    
    try {
        // Проверяем, принадлежит ли результат преподавателю (через город)
        $check_sql = "SELECT r.*, t.city 
                     FROM test_results r 
                     JOIN tests t ON r.test_id = t.id 
                     WHERE r.id = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$result_id]);
        $result_to_delete = $check_stmt->fetch();
        
        if (!$result_to_delete) {
            $_SESSION['error'] = 'Результат не найден.';
        } elseif ($has_city_in_tests && $teacher_city && $result_to_delete['city'] !== null && $result_to_delete['city'] !== $teacher_city) {
            $_SESSION['error'] = 'У вас нет прав на удаление этого результата.';
        } else {
            // Удаляем результат
            $delete_sql = "DELETE FROM test_results WHERE id = ?";
            $delete_stmt = $pdo->prepare($delete_sql);
            $delete_stmt->execute([$result_id]);
            
            $_SESSION['success'] = 'Результат успешно удален.';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка при удалении результата: ' . $e->getMessage();
    }
    
    header('Location: results.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit();
}

// Генерация CSRF-токена
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Получаем параметры фильтрации
$course_filter = isset($_GET['course']) ? intval($_GET['course']) : '';
$test_filter = isset($_GET['test']) ? intval($_GET['test']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$specialty_filter = isset($_GET['specialty']) ? intval($_GET['specialty']) : '';
$city_filter = isset($_GET['city']) ? $_GET['city'] : '';

// Подготовка SQL с учетом фильтров и города преподавателя
$sql = "SELECT r.*, 
               s.last_name, s.first_name, s.course_number, s.specialty,
               t.title as test_title, t.id as test_id, t.city as test_city,
               sp.name as specialty_name";

if ($has_city_in_students) {
    $sql .= ", s.city as student_city";
}

$sql .= " FROM test_results r
        JOIN students s ON r.student_id = s.id
        JOIN tests t ON r.test_id = t.id
        LEFT JOIN specialties sp ON s.specialty = sp.name";

// Условия по городу
$where_conditions = [];
$params = [];

if ($has_city_in_tests && $teacher_city) {
    $where_conditions[] = "(t.city IS NULL OR t.city = ?)";
    $params[] = $teacher_city;
}

// Фильтр по курсу
if (!empty($course_filter)) {
    $where_conditions[] = "s.course_number = ?";
    $params[] = $course_filter;
}

// Фильтр по тесту
if (!empty($test_filter)) {
    $where_conditions[] = "t.id = ?";
    $params[] = $test_filter;
}

// Фильтр по статусу (сдал/не сдал)
if (!empty($status_filter)) {
    if ($status_filter === 'passed') {
        $where_conditions[] = "r.is_passed = 1";
    } elseif ($status_filter === 'failed') {
        $where_conditions[] = "r.is_passed = 0";
    }
}

// Фильтр по специальности
if (!empty($specialty_filter)) {
    $where_conditions[] = "sp.id = ?";
    $params[] = $specialty_filter;
}

// Фильтр по городу студента
if ($has_city_in_students && !empty($city_filter)) {
    if ($city_filter === 'NULL') {
        $where_conditions[] = "s.city IS NULL";
    } else {
        $where_conditions[] = "s.city = ?";
        $params[] = $city_filter;
    }
}

// Если есть условия WHERE
if (!empty($where_conditions)) {
    $sql .= " WHERE " . implode(" AND ", $where_conditions);
}

$sql .= " ORDER BY r.completed_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

// Получаем уникальные курсы для фильтра (с учетом города преподавателя)
$courses_sql = "SELECT DISTINCT s.course_number 
                FROM students s
                JOIN test_results r ON s.id = r.student_id
                JOIN tests t ON r.test_id = t.id";
                
if ($has_city_in_tests && $teacher_city) {
    $courses_sql .= " WHERE t.city IS NULL OR t.city = ?";
    $courses_params = [$teacher_city];
} else {
    $courses_params = [];
}

$courses_sql .= " ORDER BY s.course_number";
$courses_stmt = $pdo->prepare($courses_sql);
$courses_stmt->execute($courses_params);
$courses = $courses_stmt->fetchAll(PDO::FETCH_COLUMN);

// Получаем уникальные тесты преподавателя для фильтра
$tests_sql = "SELECT id, title FROM tests";
if ($has_city_in_tests && $teacher_city) {
    $tests_sql .= " WHERE city IS NULL OR city = ?";
    $tests_params = [$teacher_city];
} else {
    $tests_params = [];
}
$tests_sql .= " ORDER BY title";

$tests_stmt = $pdo->prepare($tests_sql);
$tests_stmt->execute($tests_params);
$tests = $tests_stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем уникальные специальности для фильтра
$specialties_sql = "SELECT DISTINCT sp.id, sp.name 
                    FROM students s 
                    JOIN test_results r ON s.id = r.student_id
                    JOIN tests t ON r.test_id = t.id
                    LEFT JOIN specialties sp ON s.specialty = sp.name";

if ($has_city_in_tests && $teacher_city) {
    $specialties_sql .= " WHERE sp.id IS NOT NULL AND (t.city IS NULL OR t.city = ?)";
    $specialties_params = [$teacher_city];
} else {
    $specialties_sql .= " WHERE sp.id IS NOT NULL";
    $specialties_params = [];
}

$specialties_sql .= " ORDER BY sp.name";
$specialties_stmt = $pdo->prepare($specialties_sql);
$specialties_stmt->execute($specialties_params);
$specialties = $specialties_stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем уникальные города студентов для фильтра
$cities = [];
if ($has_city_in_students) {
    $cities_sql = "SELECT DISTINCT s.city 
                   FROM students s
                   JOIN test_results r ON s.id = r.student_id
                   JOIN tests t ON r.test_id = t.id";
    
    if ($has_city_in_tests && $teacher_city) {
        $cities_sql .= " WHERE (t.city IS NULL OR t.city = ?) AND s.city IS NOT NULL";
        $cities_params = [$teacher_city];
    } else {
        $cities_sql .= " WHERE s.city IS NOT NULL";
        $cities_params = [];
    }
    
    $cities_sql .= " ORDER BY s.city";
    $cities_stmt = $pdo->prepare($cities_sql);
    $cities_stmt->execute($cities_params);
    $cities = $cities_stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Подсчет статистики
$total_results = count($results);
$passed_count = 0;
$failed_count = 0;
$total_score = 0;
$total_questions = 0;

foreach ($results as $result) {
    if (isset($result['is_passed']) && $result['is_passed'] == 1) {
        $passed_count++;
    } else {
        $failed_count++;
    }
    $total_score += $result['score'] ?? 0;
    $total_questions += $result['total_questions'] ?? 1;
}

$average = ($total_questions > 0) ? round(($total_score / $total_questions) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Результаты тестирования - Преподаватель</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="icon" href="/logo.png">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 20px;
        }
        .header {
            background: linear-gradient(45deg, #0dcaf0, #17a2b8);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .progress {
            height: 20px;
        }
        .badge-grade {
            font-size: 0.9em;
            padding: 5px 10px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .badge-grade:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .result-row:hover {
            background-color: #f8f9fa;
        }
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }
        .filter-badge {
            cursor: pointer;
            margin-right: 5px;
            margin-bottom: 5px;
        }
        .filter-badge.active {
            background-color: #0dcaf0 !important;
        }
        .specialty-badge-result {
            background: linear-gradient(45deg, #6f42c1, #9b6bff);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-left: 5px;
        }
        .student-city-badge {
            background: linear-gradient(45deg, #fd7e14, #ff922b);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-left: 5px;
        }
        .delete-btn {
            opacity: 0.7;
            transition: opacity 0.2s;
        }
        .delete-btn:hover {
            opacity: 1;
        }
        .modal-confirm {
            color: #636363;
        }
        .modal-confirm .modal-content {
            border-radius: 8px;
            border: none;
        }
        .modal-confirm .modal-header {
            border-bottom: none;
            position: relative;
        }
        .modal-confirm .modal-body {
            padding-top: 0;
        }
        .modal-confirm h4 {
            text-align: center;
            font-size: 26px;
            margin: 30px 0 -10px;
        }
        .modal-confirm .modal-footer {
            border: none;
            text-align: center;
            border-radius: 5px;
            font-size: 13px;
        }
        .modal-confirm .icon-box {
            color: #fff;
            position: absolute;
            margin: 0 auto;
            left: 0;
            right: 0;
            top: -70px;
            width: 95px;
            height: 95px;
            border-radius: 50%;
            z-index: 9;
            background: #f15e5e;
            padding: 15px;
            text-align: center;
            box-shadow: 0px 2px 2px rgba(0, 0, 0, 0.1);
        }
        .modal-confirm .icon-box i {
            font-size: 58px;
            position: relative;
            top: 3px;
        }
        .modal-confirm.modal-dialog {
            margin-top: 80px;
        }

        .access-info {
            background: #e7f3ff;
            border-left: 4px solid #0d6efd;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .warning-info {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .teacher-city-badge {
            background: linear-gradient(45deg, #dc3545, #e35d6a);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-left: 5px;
        }
        .no-city-badge {
            background: linear-gradient(45deg, #6c757d, #adb5bd);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-left: 5px;
        }
        .table th {
            font-weight: 600;
            color: white;
        }
        .stats-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            transition: transform 0.3s;
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .edit-grade-btn {
            background: none;
            border: none;
            color: #6c757d;
            font-size: 0.8rem;
            margin-left: 5px;
            transition: color 0.3s;
        }
        .edit-grade-btn:hover {
            color: #0d6efd;
        }
        .grade-cell {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Заголовок -->
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-bar-chart"></i> Результаты тестирования</h1>
                    <p class="mb-0">Преподаватель: <?php echo $_SESSION['teacher']['last_name'] . ' ' . $_SESSION['teacher']['first_name']; ?> 
                    <?php if ($teacher_city): ?>
                        <span class="teacher-city-badge">Город: <?php echo htmlspecialchars($teacher_city); ?></span>
                    <?php endif; ?>
                    </p>
                </div>
                <div>
                    <a href="index.php" class="btn btn-light me-2">
                        <i class="bi bi-house"></i> Главная
                    </a>
                    <a href="logout.php" class="btn btn-outline-light">
                        <i class="bi bi-box-arrow-right"></i> Выйти
                    </a>
                </div>
            </div>
        </div>

        <!-- Информация о доступе -->
        <?php if (!$has_city_in_tests || !$has_city_in_students): ?>
            <div class="warning-info">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Внимание!</strong> Для полной работы с городами добавьте столбцы:
                <?php if (!$has_city_in_tests): ?>
                    <br><small><code>ALTER TABLE tests ADD COLUMN city VARCHAR(100) NULL;</code></small>
                <?php endif; ?>
                <?php if (!$has_city_in_students): ?>
                    <br><small><code>ALTER TABLE students ADD COLUMN city VARCHAR(100) NULL;</code></small>
                <?php endif; ?>
            </div>
        <?php elseif ($teacher_city): ?>
            <div class="access-info">
                <i class="bi bi-info-circle"></i>
                <strong>Доступ ограничен:</strong> Вы видите только результаты тестов из города 
                <strong><?php echo htmlspecialchars($teacher_city); ?></strong> и общих тестов (без привязки к городу).
            </div>
        <?php else: ?>
            <div class="access-info">
                <i class="bi bi-globe"></i>
                <strong>Доступ ко всем результатам:</strong> У вас нет привязки к городу, поэтому вы видите все результаты в системе.
            </div>
        <?php endif; ?>

        <!-- Сообщения об успехе/ошибке -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?php echo $_SESSION['success']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $_SESSION['error']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Панель фильтров -->
        <div class="filter-card">
            <h5><i class="bi bi-funnel"></i> Фильтры</h5>
            <form method="GET" action="" id="filterForm">
                <div class="row">
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Курс:</label>
                        <select class="form-select" name="course">
                            <option value="">Все курсы</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?php echo $course; ?>" 
                                    <?php echo $course_filter == $course ? 'selected' : ''; ?>>
                                    <?php echo $course; ?> курс
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Тест:</label>
                        <select class="form-select" name="test">
                            <option value="">Все тесты</option>
                            <?php foreach ($tests as $test): ?>
                                <option value="<?php echo $test['id']; ?>" 
                                    <?php echo $test_filter == $test['id'] ? 'selected' : ''; ?>>
                                    <?php 
                                    $short_title = htmlspecialchars($test['title']);
                                    if (strlen($short_title) > 20) {
                                        $short_title = substr($short_title, 0, 20) . '...';
                                    }
                                    echo $short_title;
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Статус:</label>
                        <select class="form-select" name="status">
                            <option value="">Все результаты</option>
                            <option value="passed" <?php echo $status_filter === 'passed' ? 'selected' : ''; ?>>Сдавшие</option>
                            <option value="failed" <?php echo $status_filter === 'failed' ? 'selected' : ''; ?>>Не сдавшие</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Специальность:</label>
                        <select class="form-select" name="specialty">
                            <option value="">Все специальности</option>
                            <?php foreach ($specialties as $spec): ?>
                                <option value="<?php echo $spec['id']; ?>" 
                                    <?php echo $specialty_filter == $spec['id'] ? 'selected' : ''; ?>>
                                    <?php 
                                    $short_name = htmlspecialchars($spec['name']);
                                    if (strlen($short_name) > 15) {
                                        $short_name = substr($short_name, 0, 15) . '...';
                                    }
                                    echo $short_name;
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if ($has_city_in_students): ?>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Город студента:</label>
                            <select class="form-select" name="city">
                                <option value="">Все города</option>
                                <?php if ($teacher_city): ?>
                                    <option value="NULL" <?php echo $city_filter === 'NULL' ? 'selected' : ''; ?>>Без города</option>
                                <?php endif; ?>
                                <?php foreach ($cities as $city): ?>
                                    <option value="<?php echo htmlspecialchars($city); ?>" 
                                        <?php echo $city_filter == $city ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($city); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    
                    <div class="col-md-1 mb-3 d-flex align-items-end">
                        <div class="d-grid gap-2 w-100">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-filter"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
            
            <!-- Информация о фильтрах -->
            <?php if (!empty($course_filter) || !empty($test_filter) || !empty($status_filter) || !empty($specialty_filter) || !empty($city_filter)): ?>
                <div class="alert alert-info mt-3 p-2">
                    <small>
                        <i class="bi bi-info-circle"></i> 
                        Активные фильтры:
                        <?php 
                        $active_filters = [];
                        if (!empty($course_filter)) $active_filters[] = "Курс: $course_filter";
                        if (!empty($test_filter)) {
                            foreach ($tests as $test) {
                                if ($test['id'] == $test_filter) {
                                    $active_filters[] = "Тест: " . htmlspecialchars($test['title']);
                                    break;
                                }
                            }
                        }
                        if (!empty($status_filter)) {
                            $active_filters[] = $status_filter === 'passed' ? "Статус: Сдавшие" : "Статус: Не сдавшие";
                        }
                        if (!empty($specialty_filter)) {
                            foreach ($specialties as $spec) {
                                if ($spec['id'] == $specialty_filter) {
                                    $active_filters[] = "Специальность: " . htmlspecialchars($spec['name']);
                                    break;
                                }
                            }
                        }
                        if (!empty($city_filter)) {
                            if ($city_filter === 'NULL') {
                                $active_filters[] = "Город студента: Без города";
                            } else {
                                $active_filters[] = "Город студента: " . htmlspecialchars($city_filter);
                            }
                        }
                        echo implode(', ', $active_filters);
                        ?>
                        | <a href="results.php" class="text-decoration-none">Сбросить все</a>
                    </small>
                </div>
            <?php endif; ?>
            
            <!-- Информация о городе преподавателя -->
            <?php if ($teacher_city && !$city_filter): ?>
                <div class="mt-2 pt-2 border-top">
                    <small class="text-muted">
                        <i class="bi bi-geo-alt"></i>
                        По умолчанию отображаются результаты тестов из города 
                        <strong><?php echo htmlspecialchars($teacher_city); ?></strong> и общих тестов.
                    </small>
                </div>
            <?php endif; ?>
        </div>

        <!-- Таблица результатов -->
        <div id="resultsTable">
            <?php if (empty($results)): ?>
                <div class="alert alert-info text-center">
                    <i class="bi bi-info-circle fs-4"></i>
                    <h4 class="mt-2">Нет данных о результатах</h4>
                    <p>
                        <?php if (!empty($course_filter) || !empty($test_filter) || !empty($status_filter) || !empty($specialty_filter) || !empty($city_filter)): ?>
                            По выбранным фильтрам результатов не найдено.
                            <a href="results.php" class="alert-link">Показать все результаты</a>
                        <?php else: ?>
                            Студенты еще не проходили тестирование.
                            <?php if ($teacher_city): ?>
                                <br>Или нет результатов по тестам из вашего города 
                                <strong><?php echo htmlspecialchars($teacher_city); ?></strong>.
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Студент</th>
                                <th>Курс</th>
                                <th>Специальность</th>
                                <?php if ($has_city_in_students): ?>
                                    <th>Город студента</th>
                                <?php endif; ?>
                                <th>Тест</th>
                                <th>Результат</th>
                                <th>Оценка</th>
                                <th>Статус</th>
                                <th>Дата</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $index => $result): ?>
                                <tr class="result-row">
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <strong><?php echo escape($result['last_name'] . ' ' . $result['first_name']); ?></strong>
                                    </td>
                                    <td>
                                        <a href="results.php?course=<?php echo $result['course_number']; ?><?php echo !empty($test_filter) ? '&test=' . $test_filter : ''; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?><?php echo !empty($specialty_filter) ? '&specialty=' . $specialty_filter : ''; ?><?php echo !empty($city_filter) ? '&city=' . urlencode($city_filter) : ''; ?>" 
                                           class="text-decoration-none" title="Показать только этот курс">
                                            <span class="badge bg-primary"><?php echo $result['course_number']; ?> курс</span>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if (!empty($result['specialty_name'])): ?>
                                            <a href="results.php?specialty=<?php 
                                                // Находим ID специальности по названию
                                                $specialty_id = '';
                                                foreach ($specialties as $spec) {
                                                    if ($spec['name'] == $result['specialty_name']) {
                                                        $specialty_id = $spec['id'];
                                                        break;
                                                    }
                                                }
                                                echo $specialty_id;
                                            ?><?php echo !empty($course_filter) ? '&course=' . $course_filter : ''; ?><?php echo !empty($test_filter) ? '&test=' . $test_filter : ''; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?><?php echo !empty($city_filter) ? '&city=' . urlencode($city_filter) : ''; ?>" 
                                               class="text-decoration-none" title="Показать только эту специальность">
                                                <span class="specialty-badge-result">
                                                    <i class="bi bi-briefcase"></i> 
                                                    <?php 
                                                    $short_specialty = escape($result['specialty_name']);
                                                    if (strlen($short_specialty) > 15) {
                                                        $short_specialty = substr($short_specialty, 0, 15) . '...';
                                                    }
                                                    echo $short_specialty;
                                                    ?>
                                                </span>
                                            </a>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Не указана</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <?php if ($has_city_in_students): ?>
                                        <td>
                                            <?php if (!empty($result['student_city'])): ?>
                                                <a href="results.php?city=<?php echo urlencode($result['student_city']); ?><?php echo !empty($course_filter) ? '&course=' . $course_filter : ''; ?><?php echo !empty($test_filter) ? '&test=' . $test_filter : ''; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?><?php echo !empty($specialty_filter) ? '&specialty=' . $specialty_filter : ''; ?>" 
                                                   class="text-decoration-none" title="Показать студентов из города <?php echo htmlspecialchars($result['student_city']); ?>">
                                                    <span class="student-city-badge">
                                                        <?php echo htmlspecialchars($result['student_city']); ?>
                                                    </span>
                                                </a>
                                            <?php else: ?>
                                                <span class="no-city-badge">Не указан</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    
                                    <td>
                                        <a href="results.php?test=<?php echo $result['test_id']; ?><?php echo !empty($course_filter) ? '&course=' . $course_filter : ''; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?><?php echo !empty($specialty_filter) ? '&specialty=' . $specialty_filter : ''; ?><?php echo !empty($city_filter) ? '&city=' . urlencode($city_filter) : ''; ?>" 
                                           class="text-decoration-none" title="Показать только этот тест">
                                            <?php 
                                            $short_title = escape($result['test_title']);
                                            if (strlen($short_title) > 25) {
                                                $short_title = substr($short_title, 0, 25) . '...';
                                            }
                                            echo $short_title;
                                            ?>
                                        </a>
                                        <?php if ($has_city_in_tests && !empty($result['test_city'])): ?>
                                            <br>
                                            <small class="text-muted">
                                                <i class="bi bi-geo-alt"></i> 
                                                <?php echo htmlspecialchars($result['test_city']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="progress flex-grow-1 me-2" style="min-width: 80px;">
                                                <?php
                                                $total_questions = $result['total_questions'] ?? 1;
                                                $percentage = ($total_questions > 0) ? ($result['score'] / $total_questions) * 100 : 0;
                                                
                                                $color = $percentage >= 80 ? 'bg-success' : 
                                                         ($percentage >= 60 ? 'bg-primary' : 
                                                         ($percentage >= 40 ? 'bg-warning' : 'bg-danger'));
                                                ?>
                                                <div class="progress-bar <?php echo $color; ?>" 
                                                     role="progressbar" 
                                                     style="width: <?php echo $percentage; ?>%"
                                                     aria-valuenow="<?php echo $percentage; ?>" 
                                                     aria-valuemin="0" 
                                                     aria-valuemax="100">
                                                </div>
                                            </div>
                                            <span class="badge bg-secondary ms-1">
                                                <?php echo $result['score']; ?>/<?php echo $total_questions; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="grade-cell">
                                            <span class="badge badge-grade 
                                                <?php 
                                                    $grade_class = 'bg-secondary';
                                                    $grade = $result['grade'] ?? '';
                                                    
                                                    if (strpos($grade, 'Зачет') !== false || $grade == '5' || $grade == '5 (Отлично)') {
                                                        $grade_class = 'bg-success';
                                                    } elseif ($grade == '4' || $grade == '4 (Хорошо)') {
                                                        $grade_class = 'bg-primary';
                                                    } elseif ($grade == '3' || $grade == '3 (Удовлетворительно)') {
                                                        $grade_class = 'bg-info';
                                                    } elseif (strpos($grade, 'Незачет') !== false || $grade == '2' || $grade == '2 (Неудовлетворительно)') {
                                                        $grade_class = 'bg-danger';
                                                    }
                                                    
                                                    echo $grade_class; 
                                                ?>"
                                                onclick="editGrade(<?php echo $result['id']; ?>, '<?php echo escape($grade); ?>')"
                                                title="Нажмите для изменения оценки">
                                                <?php echo !empty($grade) ? $grade : 'Не оценено'; ?>
                                            </span>
                                            <button type="button" class="edit-grade-btn" 
                                                    onclick="editGrade(<?php echo $result['id']; ?>, '<?php echo escape($grade); ?>')"
                                                    title="Изменить оценку">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($result['is_passed'] == 1): ?>
                                            <span class="badge bg-success">
                                                <i class="bi bi-check-circle"></i> Сдано
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">
                                                <i class="bi bi-x-circle"></i> Не сдано
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-outline-danger btn-sm delete-btn" 
                                                data-bs-toggle="modal" data-bs-target="#deleteModal"
                                                data-result-id="<?php echo $result['id']; ?>"
                                                data-student-name="<?php echo escape($result['last_name'] . ' ' . $result['first_name']); ?>"
                                                data-test-title="<?php echo escape($result['test_title']); ?>"
                                                data-test-date="<?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?>"
                                                title="Удалить результат">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Пагинация -->
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div>
                        <small class="text-muted">
                            Показано <?php echo count($results); ?> из <?php echo $total_results; ?> результатов
                        </small>
                    </div>
                    <div>
                        <small class="text-muted">
                            <?php if (!empty($course_filter) || !empty($test_filter) || !empty($status_filter) || !empty($specialty_filter) || !empty($city_filter)): ?>
                                <a href="results.php" class="text-decoration-none">
                                    <i class="bi bi-eye"></i> Показать все результаты
                                </a>
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Модальное окно редактирования оценки -->
        <div class="modal fade" id="editGradeModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title"><i class="bi bi-pencil"></i> Изменение оценки</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="result_id" id="editResultId">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            
                            <div class="mb-3">
                                <label for="editGrade" class="form-label">Выберите оценку:</label>
                                <select class="form-select" id="editGrade" name="grade" required>
                                    <option value="">-- Выберите оценку --</option>
                                    <option value="Зачет">Зачет</option>
                                    <option value="Незачет">Незачет</option>
                                    <option value="5">5 (Отлично)</option>
                                    <option value="4">4 (Хорошо)</option>
                                    <option value="3">3 (Удовлетворительно)</option>
                                    <option value="2">2 (Неудовлетворительно)</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                            <button type="submit" name="update_grade" class="btn btn-primary">Сохранить</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Модальное окно подтверждения удаления -->
        <div class="modal fade modal-confirm" id="deleteModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header border-0">
                        <div class="icon-box">
                            <i class="bi bi-trash"></i>
                        </div>
                    </div>
                    <div class="modal-body text-center pt-0">
                        <h4 class="modal-title">Удаление результата</h4>
                        <p>Вы действительно хотите удалить результат тестирования?</p>
                        <p class="font-weight-bold" id="studentInfo"></p>
                        <p class="text-muted small" id="testInfo"></p>
                        <p class="text-danger"><i class="bi bi-exclamation-triangle"></i> Это действие нельзя отменить!</p>
                    </div>
                    <div class="modal-footer border-0 justify-content-center">
                        <form method="POST" action="" class="w-100">
                            <input type="hidden" name="result_id" id="deleteResultId">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="delete_result" value="1">
                            <button type="button" class="btn btn-secondary w-45" data-bs-dismiss="modal">
                                <i class="bi bi-x-circle"></i> Отмена
                            </button>
                            <button type="submit" class="btn btn-danger w-45">
                                <i class="bi bi-trash"></i> Удалить
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Навигация внизу -->
        <div class="mt-4 pt-3 border-top">
            <div class="row">
                <div class="col-md-3 mb-2">
                    <a href="index.php" class="btn btn-outline-primary w-100">
                        <i class="bi bi-house"></i> Главная
                    </a>
                </div>
                <div class="col-md-3 mb-2">
                    <a href="tests.php" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-journal-text"></i> Тесты
                    </a>
                </div>
                <div class="col-md-3 mb-2">
                    <a href="questions.php" class="btn btn-outline-success w-100">
                        <i class="bi bi-question-circle"></i> Вопросы
                    </a>
                </div>
                <div class="col-md-3 mb-2">
                    <a href="results.php" class="btn btn-info w-100">
                        <i class="bi bi-bar-chart"></i> Результаты
                    </a>
                </div>
            </div>
        </div>

        <!-- Общая статистика -->
        <div class="row mt-4">
            <div class="col-md-3">
                <div class="card stats-card">
                    <div class="card-body text-center">
                        <h6><i class="bi bi-list-check"></i> Всего</h6>
                        <h4><?php echo $total_results; ?></h4>
                        <small class="text-muted">результатов</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card">
                    <div class="card-body text-center">
                        <h6><i class="bi bi-check-circle text-success"></i> Сдавшие</h6>
                        <h4><?php echo $passed_count; ?></h4>
                        <small class="text-muted">
                            <?php echo $total_results > 0 ? round(($passed_count / $total_results) * 100, 1) : 0; ?>%
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card">
                    <div class="card-body text-center">
                        <h6><i class="bi bi-x-circle text-danger"></i> Не сдавшие</h6>
                        <h4><?php echo $failed_count; ?></h4>
                        <small class="text-muted">
                            <?php echo $total_results > 0 ? round(($failed_count / $total_results) * 100, 1) : 0; ?>%
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card">
                    <div class="card-body text-center">
                        <h6><i class="bi bi-graph-up text-primary"></i> Средний балл</h6>
                        <h4><?php echo $average; ?>%</h4>
                        <small class="text-muted">от общего количества</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Информация -->
        <div class="mt-4 text-center text-muted">
            <small>
                <i class="bi bi-info-circle"></i> 
                Всего результатов: <?php echo $total_results; ?> | 
                Средний процент: <?php echo $average; ?>% | 
                Преподаватель: <?php echo $_SESSION['teacher']['last_name'] . ' ' . $_SESSION['teacher']['first_name']; ?> |
                <?php if ($teacher_city): ?>Город: <?php echo htmlspecialchars($teacher_city); ?> | <?php endif; ?>
                <a href="logout.php" class="text-decoration-none">Выйти</a>
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Редактирование оценки
        function editGrade(resultId, currentGrade) {
            document.getElementById('editResultId').value = resultId;
            document.getElementById('editGrade').value = currentGrade;
            
            const modal = new bootstrap.Modal(document.getElementById('editGradeModal'));
            modal.show();
        }
        
        // Обработка модального окна удаления
        document.addEventListener('DOMContentLoaded', function() {
            const deleteModal = document.getElementById('deleteModal');
            if (deleteModal) {
                deleteModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    const resultId = button.getAttribute('data-result-id');
                    const studentName = button.getAttribute('data-student-name');
                    const testTitle = button.getAttribute('data-test-title');
                    const testDate = button.getAttribute('data-test-date');
                    
                    document.getElementById('deleteResultId').value = resultId;
                    document.getElementById('studentInfo').textContent = studentName;
                    document.getElementById('testInfo').textContent = testTitle + ' (' + testDate + ')';
                });
            }
            
            // Автоматическое скрытие alert через 5 секунд
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
            
            // Копирование SQL для добавления столбцов города
            const sqlElements = document.querySelectorAll('code');
            sqlElements.forEach(element => {
                element.addEventListener('click', function() {
                    if (confirm('Скопировать SQL-запрос?')) {
                        navigator.clipboard.writeText(this.textContent)
                            .then(() => alert('SQL-запрос скопирован в буфер обмена!'))
                            .catch(err => console.error('Ошибка копирования:', err));
                    }
                });
            });
        });
        
    </script>
</body>
</html>