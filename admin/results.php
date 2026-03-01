<?php
require_once '../config/database.php';
require_once '../config/functions.php';
requireAdminLogin();

// Обработка удаления результата
if (isset($_GET['delete_result'])) {
    $result_id = intval($_GET['delete_result']);
    
    try {
        // Удаляем результат из базы данных (используем test_results)
        $stmt = $pdo->prepare("DELETE FROM test_results WHERE id = ?");
        $stmt->execute([$result_id]);
        
        $_SESSION['success'] = 'Результат успешно удален';
        header('Location: results.php?success=result_deleted');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка при удалении результата: ' . $e->getMessage();
        header('Location: results.php?error=delete_failed');
        exit();
    }
}

// Обработка массового удаления
if (isset($_POST['delete_selected']) && !empty($_POST['selected_results'])) {
    $selected_results = $_POST['selected_results'];
    $placeholders = implode(',', array_fill(0, count($selected_results), '?'));
    
    try {
        // Удаляем выбранные результаты (используем test_results)
        $stmt = $pdo->prepare("DELETE FROM test_results WHERE id IN ($placeholders)");
        $stmt->execute($selected_results);
        
        $_SESSION['success'] = 'Выбранные результаты успешно удалены';
        header('Location: results.php?success=results_deleted');
        exit();
        
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка при удалении результатов: ' . $e->getMessage();
        header('Location: results.php?error=delete_failed');
        exit();
    }
}

// Обработка редактирования оценки
if (isset($_POST['update_grade'])) {
    $result_id = intval($_POST['result_id']);
    $grade = trim($_POST['grade']);
    
    $stmt = $pdo->prepare("UPDATE test_results SET grade = ? WHERE id = ?");
    $stmt->execute([$grade, $result_id]);
    
    header('Location: results.php?success=grade_updated');
    exit();
}

// Получаем параметры фильтрации
$course_filter = isset($_GET['course']) ? intval($_GET['course']) : '';
$test_filter = isset($_GET['test']) ? intval($_GET['test']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$specialty_filter = isset($_GET['specialty']) ? intval($_GET['specialty']) : '';

// Подготовка SQL с учетом фильтров
$sql = "SELECT r.*, 
               s.last_name, s.first_name, s.course_number, s.city as student_city,
               s.specialty as specialty_name,
               t.title as test_title, t.id as test_id
        FROM test_results r
        JOIN students s ON r.student_id = s.id
        JOIN tests t ON r.test_id = t.id
        WHERE 1=1";

$params = [];

// Фильтр по курсу
if (!empty($course_filter)) {
    $sql .= " AND s.course_number = ?";
    $params[] = $course_filter;
}

// Фильтр по тесту
if (!empty($test_filter)) {
    $sql .= " AND t.id = ?";
    $params[] = $test_filter;
}

// Фильтр по статусу (сдал/не сдал)
if (!empty($status_filter)) {
    if ($status_filter === 'passed') {
        $sql .= " AND r.is_passed = 1";
    } elseif ($status_filter === 'failed') {
        $sql .= " AND r.is_passed = 0";
    }
}

// Фильтр по специальности
if (!empty($specialty_filter)) {
    // Получаем название специальности по ID
    $spec_stmt = $pdo->prepare("SELECT name FROM specialties WHERE id = ?");
    $spec_stmt->execute([$specialty_filter]);
    $spec_name = $spec_stmt->fetchColumn();
    
    if ($spec_name) {
        $sql .= " AND s.specialty = ?";
        $params[] = $spec_name;
    }
}

$sql .= " ORDER BY r.completed_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

// Получаем уникальные курсы для фильтра
$courses_sql = "SELECT DISTINCT course_number FROM students WHERE course_number IS NOT NULL ORDER BY course_number";
$courses_stmt = $pdo->query($courses_sql);
$courses = $courses_stmt->fetchAll(PDO::FETCH_COLUMN);

// Получаем уникальные тесты для фильтра
$tests_sql = "SELECT id, title FROM tests ORDER BY title";
$tests_stmt = $pdo->query($tests_sql);
$tests = $tests_stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем все специальности
$all_specialties_sql = "SELECT id, name FROM specialties ORDER BY name";
$all_specialties_stmt = $pdo->query($all_specialties_sql);
$all_specialties = $all_specialties_stmt->fetchAll(PDO::FETCH_ASSOC);

// Подсчет статистики
$total_results = count($results);
$passed_count = 0;
$failed_count = 0;
$total_score = 0;
$total_questions = 0;

// Сбор статистики по специальностям
$specialty_stats = [];
foreach ($all_specialties as $spec) {
    $specialty_stats[$spec['id']] = [
        'name' => $spec['name'],
        'total' => 0,
        'passed' => 0,
        'failed' => 0
    ];
}

foreach ($results as $result) {
    if (isset($result['is_passed']) && $result['is_passed'] == 1) {
        $passed_count++;
    } else {
        $failed_count++;
    }
    $total_score += $result['score'] ?? 0;
    $total_questions += $result['total_questions'] ?? 1;
    
    // Статистика по специальностям (по названию)
    if (!empty($result['specialty_name'])) {
        // Ищем ID специальности по названию
        foreach ($all_specialties as $spec) {
            if ($spec['name'] == $result['specialty_name']) {
                $spec_id = $spec['id'];
                if (isset($specialty_stats[$spec_id])) {
                    $specialty_stats[$spec_id]['total']++;
                    if ($result['is_passed'] == 1) {
                        $specialty_stats[$spec_id]['passed']++;
                    } else {
                        $specialty_stats[$spec_id]['failed']++;
                    }
                }
                break;
            }
        }
    }
}

// Удаляем специальности без результатов
$specialty_stats = array_filter($specialty_stats, function($stat) {
    return $stat['total'] > 0;
});

$average = ($total_questions > 0) ? round(($total_score / $total_questions) * 100, 1) : 0;

// Функция escape уже определена в functions.php, поэтому здесь не объявляем
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Результаты тестирования</title>
    <link rel="icon" href="/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 20px;
        }
        .header {
            background: linear-gradient(45deg, #8e44ad, #9b59b6);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .progress {
            height: 20px;
        }
        .badge-grade {
            font-size: 0.9em;
            padding: 5px 10px;
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
            background-color: #0d6efd !important;
        }
        .reset-filters {
            margin-top: 10px;
        }
        .specialty-badge-result {
            background: linear-gradient(45deg, #6f42c1, #9b6bff);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-left: 5px;
        }
        .stats-card {
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-2px);
        }
        .specialty-filter-container {
            max-height: 150px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 10px;
            margin-top: 5px;
        }
        .specialty-filter-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 3px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .specialty-filter-item:last-child {
            border-bottom: none;
        }
        .specialty-count {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .select-all-checkbox {
            font-size: 0.9rem;
            margin-right: 10px;
        }
        .bulk-actions {
            display: none;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .bulk-actions.show {
            display: block;
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
                    <p class="mb-0">Администратор: <?php echo $_SESSION['admin_username']; ?></p>
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

        <!-- Сообщения -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['success']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['error']; ?>
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
                    
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Тест:</label>
                        <select class="form-select" name="test">
                            <option value="">Все тесты</option>
                            <?php foreach ($tests as $test): ?>
                                <option value="<?php echo $test['id']; ?>" 
                                    <?php echo $test_filter == $test['id'] ? 'selected' : ''; ?>>
                                    <?php 
                                    $short_title = htmlspecialchars($test['title']);
                                    if (strlen($short_title) > 30) {
                                        $short_title = substr($short_title, 0, 30) . '...';
                                    }
                                    echo $short_title;
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Специальность:</label>
                        <select class="form-select" name="specialty">
                            <option value="">Все специальности</option>
                            <?php foreach ($all_specialties as $spec): ?>
                                <option value="<?php echo $spec['id']; ?>" 
                                    <?php echo $specialty_filter == $spec['id'] ? 'selected' : ''; ?>>
                                    <?php 
                                    $short_name = htmlspecialchars($spec['name']);
                                    if (strlen($short_name) > 25) {
                                        $short_name = substr($short_name, 0, 25) . '...';
                                    }
                                    echo $short_name;
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($all_specialties)): ?>
                            <div class="specialty-filter-container mt-2">
                                <small class="text-muted">Быстрый выбор:</small>
                                <?php foreach ($all_specialties as $spec): ?>
                                    <div class="specialty-filter-item">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" 
                                                   name="specialty" 
                                                   id="specialty_<?php echo $spec['id']; ?>" 
                                                   value="<?php echo $spec['id']; ?>"
                                                   <?php echo $specialty_filter == $spec['id'] ? 'checked' : ''; ?>
                                                   onchange="this.form.submit()">
                                            <label class="form-check-label" for="specialty_<?php echo $spec['id']; ?>" 
                                                   style="font-size: 0.85rem; cursor: pointer;">
                                                <?php 
                                                $short_name = htmlspecialchars($spec['name']);
                                                if (strlen($short_name) > 20) {
                                                    $short_name = substr($short_name, 0, 20) . '...';
                                                }
                                                echo $short_name;
                                                ?>
                                            </label>
                                        </div>
                                        <?php if (isset($specialty_stats[$spec['id']])): ?>
                                            <span class="specialty-count badge bg-secondary">
                                                <?php echo $specialty_stats[$spec['id']]['total']; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Статус:</label>
                        <select class="form-select" name="status">
                            <option value="">Все результаты</option>
                            <option value="passed" <?php echo $status_filter === 'passed' ? 'selected' : ''; ?>>Сдавшие</option>
                            <option value="failed" <?php echo $status_filter === 'failed' ? 'selected' : ''; ?>>Не сдавшие</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2 mb-3 d-flex align-items-end">
                        <div class="d-grid gap-2 w-100">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-filter"></i> Применить
                            </button>
                            <a href="results.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle"></i> Сбросить
                            </a>
                        </div>
                    </div>
                </div>
            </form>
            
            <!-- Быстрые фильтры -->
            <div class="mt-3">
                <small class="text-muted">Быстрые фильтры:</small><br>
                
                <!-- Фильтр по курсам -->
                <?php if (!empty($courses)): ?>
                    <div class="mt-2">
                        <small>Курсы:</small><br>
                        <?php foreach ($courses as $course): ?>
                            <a href="results.php?course=<?php echo $course; ?><?php echo !empty($test_filter) ? '&test=' . $test_filter : ''; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?><?php echo !empty($specialty_filter) ? '&specialty=' . $specialty_filter : ''; ?>" 
                               class="badge filter-badge <?php echo $course_filter == $course ? 'bg-primary active' : 'bg-secondary'; ?>">
                                <?php echo $course; ?> курс
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Фильтр по специальностям -->
                <?php if (!empty($all_specialties)): ?>
                    <div class="mt-2">
                        <small>Специальности:</small><br>
                        <a href="results.php<?php 
                            echo !empty($course_filter) ? '?course=' . $course_filter : '?'; 
                            echo !empty($test_filter) ? (strpos($_SERVER['REQUEST_URI'], '?') ? '&' : '?') . 'test=' . $test_filter : '';
                            echo !empty($status_filter) ? (strpos($_SERVER['REQUEST_URI'], '?') ? '&' : '?') . 'status=' . $status_filter : '';
                        ?>" 
                           class="badge filter-badge <?php echo empty($specialty_filter) ? 'bg-primary active' : 'bg-secondary'; ?>">
                            Все
                        </a>
                        <?php foreach ($all_specialties as $spec): ?>
                            <?php 
                            $has_results = isset($specialty_stats[$spec['id']]) && $specialty_stats[$spec['id']]['total'] > 0;
                            $badge_class = 'bg-secondary';
                            if ($has_results) {
                                $badge_class = 'bg-purple';
                                if ($specialty_filter == $spec['id']) {
                                    $badge_class = 'bg-purple active';
                                }
                            }
                            ?>
                            <a href="results.php?specialty=<?php echo $spec['id']; ?><?php echo !empty($course_filter) ? '&course=' . $course_filter : ''; ?><?php echo !empty($test_filter) ? '&test=' . $test_filter : ''; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?>" 
                               class="badge filter-badge <?php echo $badge_class; ?> <?php echo !$has_results ? 'disabled' : ''; ?>"
                               <?php echo !$has_results ? 'style="opacity: 0.5; cursor: not-allowed;" title="Нет результатов для этой специальности"' : ''; ?>>
                                <?php 
                                $short_name = htmlspecialchars($spec['name']);
                                if (strlen($short_name) > 15) {
                                    $short_name = substr($short_name, 0, 15) . '...';
                                }
                                echo $short_name;
                                ?>
                                <?php if ($has_results): ?>
                                    <span class="badge bg-light text-dark"><?php echo $specialty_stats[$spec['id']]['total']; ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Фильтр по статусу -->
                <div class="mt-2">
                    <small>Статус:</small><br>
                    <a href="results.php<?php 
                        echo !empty($course_filter) ? '?course=' . $course_filter : '?'; 
                        echo !empty($test_filter) ? (strpos($_SERVER['REQUEST_URI'], '?') ? '&' : '?') . 'test=' . $test_filter : '';
                        echo !empty($specialty_filter) ? (strpos($_SERVER['REQUEST_URI'], '?') ? '&' : '?') . 'specialty=' . $specialty_filter : '';
                    ?>" 
                       class="badge filter-badge <?php echo empty($status_filter) ? 'bg-primary active' : 'bg-secondary'; ?>">
                        Все
                    </a>
                    <a href="results.php?status=passed<?php echo !empty($course_filter) ? '&course=' . $course_filter : ''; ?><?php echo !empty($test_filter) ? '&test=' . $test_filter : ''; ?><?php echo !empty($specialty_filter) ? '&specialty=' . $specialty_filter : ''; ?>" 
                       class="badge filter-badge <?php echo $status_filter === 'passed' ? 'bg-success active' : 'bg-secondary'; ?>">
                        <i class="bi bi-check-circle"></i> Сдавшие
                    </a>
                    <a href="results.php?status=failed<?php echo !empty($course_filter) ? '&course=' . $course_filter : ''; ?><?php echo !empty($test_filter) ? '&test=' . $test_filter : ''; ?><?php echo !empty($specialty_filter) ? '&specialty=' . $specialty_filter : ''; ?>" 
                       class="badge filter-badge <?php echo $status_filter === 'failed' ? 'bg-danger active' : 'bg-secondary'; ?>">
                        <i class="bi bi-x-circle"></i> Не сдавшие
                    </a>
                </div>
            </div>
            
            <!-- Информация о фильтрах -->
            <?php if (!empty($course_filter) || !empty($test_filter) || !empty($status_filter) || !empty($specialty_filter)): ?>
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
                        if (!empty($specialty_filter)) {
                            foreach ($all_specialties as $spec) {
                                if ($spec['id'] == $specialty_filter) {
                                    $active_filters[] = "Специальность: " . htmlspecialchars($spec['name']);
                                    break;
                                }
                            }
                        }
                        if (!empty($status_filter)) {
                            $active_filters[] = $status_filter === 'passed' ? "Статус: Сдавшие" : "Статус: Не сдавшие";
                        }
                        echo implode(', ', $active_filters);
                        ?>
                        | <a href="results.php" class="text-decoration-none">Сбросить все</a>
                    </small>
                </div>
            <?php endif; ?>
        </div>

        <!-- Навигация -->
        <div class="mb-4">
            <div class="d-flex justify-content-between">
                <div>
                    <a href="index.php" class="btn btn-outline-primary me-2">
                        <i class="bi bi-house"></i> Главная
                    </a>
                    <a href="tests.php" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-journal-text"></i> Тесты
                    </a>
                    <a href="questions.php" class="btn btn-outline-success">
                        <i class="bi bi-question-circle"></i> Вопросы
                    </a>
                </div>
                <div>
                    <button type="button" class="btn btn-outline-info me-2" onclick="printResults()">
                        <i class="bi bi-printer"></i> Печать
                    </button>
                    <button type="button" class="btn btn-outline-success" onclick="exportToCSV()">
                        <i class="bi bi-file-earmark-excel"></i> Экспорт
                    </button>
                </div>
            </div>
        </div>

        <!-- Массовые действия -->
        <div id="bulkActions" class="bulk-actions">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span id="selectedCount">0 результатов выбрано</span>
                </div>
                <div>
                    <button type="button" class="btn btn-danger btn-sm" onclick="confirmBulkDelete()">
                        <i class="bi bi-trash"></i> Удалить выбранные
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="clearSelection()">
                        <i class="bi bi-x-circle"></i> Отменить выбор
                    </button>
                </div>
            </div>
        </div>

        <!-- Таблица результатов -->
        <form id="resultsForm" method="POST" action="">
            <input type="hidden" name="delete_selected" value="1">
            <div id="resultsTable">
                <?php if (empty($results)): ?>
                    <div class="alert alert-info text-center">
                        <i class="bi bi-info-circle fs-4"></i>
                        <h4 class="mt-2">Нет данных о результатах</h4>
                        <p>
                            <?php if (!empty($course_filter) || !empty($test_filter) || !empty($status_filter) || !empty($specialty_filter)): ?>
                                По выбранным фильтрам результатов не найдено.
                                <a href="results.php" class="alert-link">Показать все результаты</a>
                            <?php else: ?>
                                Студенты еще не проходили тестирование.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th width="40">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                                        </div>
                                    </th>
                                    <th>#</th>
                                    <th>Студент</th>
                                    <th>Курс</th>
                                    <th>Город</th>
                                    <th>Специальность</th>
                                    <th>Тест</th>
                                    <th>Результат</th>
                                    <th>Оценка</th>
                                    <th>Дата</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $index => $result): ?>
                                    <tr class="result-row">
                                        <td>
                                            <div class="form-check">
                                                <input class="form-check-input result-checkbox" 
                                                       type="checkbox" 
                                                       name="selected_results[]" 
                                                       value="<?php echo $result['id']; ?>"
                                                       onchange="updateSelection()">
                                            </div>
                                        </td>
                                        <td><?php echo $index + 1; ?></td>
                                        <td>
                                            <strong><?php echo escape($result['last_name'] . ' ' . $result['first_name']); ?></strong>
                                        </td>
                                        <td>
                                            <a href="results.php?course=<?php echo $result['course_number']; ?><?php echo !empty($test_filter) ? '&test=' . $test_filter : ''; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?><?php echo !empty($specialty_filter) ? '&specialty=' . $specialty_filter : ''; ?>" 
                                               class="text-decoration-none" title="Показать только этот курс">
                                                <?php echo $result['course_number']; ?> курс
                                            </a>
                                        </td>
                                        <td>
                                            <?php if (!empty($result['student_city'])): ?>
                                                <span class="badge bg-info">
                                                    <?php echo escape($result['student_city']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Не указан</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($result['specialty_name'])): ?>
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
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Не указана</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="results.php?test=<?php echo $result['test_id']; ?><?php echo !empty($course_filter) ? '&course=' . $course_filter : ''; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?><?php echo !empty($specialty_filter) ? '&specialty=' . $specialty_filter : ''; ?>" 
                                               class="text-decoration-none" title="Показать только этот тест">
                                                <?php 
                                                $short_title = escape($result['test_title']);
                                                if (strlen($short_title) > 30) {
                                                    $short_title = substr($short_title, 0, 30) . '...';
                                                }
                                                echo $short_title;
                                                ?>
                                            </a>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1 me-2" style="width: 100px;">
                                                    <?php
                                                    // Безопасный расчет процента (защита от деления на ноль)
                                                    $total_questions = $result['total_questions'] ?? 1;
                                                    $percentage = ($total_questions > 0) ? ($result['score'] / $total_questions) * 100 : 0;
                                                    
                                                    // Определение цвета для прогресс-бара
                                                    if ($total_questions == 0) {
                                                        $color = 'bg-secondary';
                                                    } else {
                                                        $color = $percentage >= 80 ? 'bg-success' : 
                                                                 ($percentage >= 60 ? 'bg-primary' : 
                                                                 ($percentage >= 40 ? 'bg-warning' : 'bg-danger'));
                                                    }
                                                    ?>
                                                    <div class="progress-bar <?php echo $color; ?>" 
                                                         role="progressbar" 
                                                         style="width: <?php echo $percentage; ?>%"
                                                         aria-valuenow="<?php echo $percentage; ?>" 
                                                         aria-valuemin="0" 
                                                         aria-valuemax="100">
                                                    </div>
                                                </div>
                                                <span class="badge bg-secondary">
                                                    <?php echo $result['score']; ?>/<?php echo $total_questions; ?>
                                                </span>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo round($percentage, 1); ?>%
                                            </small>
                                        </td>
                                        <td>
                                            <?php
                                            $grade = $result['grade'] ?? '';
                                            $grade_class = 'bg-secondary';
                                            
                                            if (strpos($grade, 'Зачет') !== false || $grade == '5' || $grade == '5 (Отлично)') {
                                                $grade_class = 'bg-success';
                                            } elseif ($grade == '4' || $grade == '4 (Хорошо)') {
                                                $grade_class = 'bg-primary';
                                            } elseif ($grade == '3' || $grade == '3 (Удовлетворительно)') {
                                                $grade_class = 'bg-info';
                                            } elseif (strpos($grade, 'Незачет') !== false || $grade == '2' || $grade == '2 (Неудовлетворительно)') {
                                                $grade_class = 'bg-danger';
                                            }
                                            ?>
                                            <span class="badge badge-grade <?php echo $grade_class; ?>">
                                                <?php echo !empty($grade) ? $grade : 'Не оценено'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?>
                                        </td>
                                        <td>
                                            <div class="btn-group-vertical btn-group-sm" role="group">
                                                <button type="button" class="btn btn-sm btn-outline-primary mb-1" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editGradeModal"
                                                        onclick="editGrade(<?php echo $result['id']; ?>, '<?php echo escape($grade); ?>')"
                                                        title="Изменить оценку">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        onclick="confirmDelete(<?php echo $result['id']; ?>, '<?php echo escape($result['last_name'] . ' ' . $result['first_name']); ?>', '<?php echo escape($result['test_title']); ?>')"
                                                        title="Удалить результат">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Пагинация (простая версия) -->
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div>
                            <small class="text-muted">
                                Показано <?php echo count($results); ?> из <?php echo $total_results; ?> результатов
                            </small>
                        </div>
                        <div>
                            <small class="text-muted">
                                <?php if (!empty($course_filter) || !empty($test_filter) || !empty($status_filter) || !empty($specialty_filter)): ?>
                                    <a href="results.php" class="text-decoration-none">
                                        <i class="bi bi-eye"></i> Показать все результаты
                                    </a>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </form>

        <!-- Статистика по специальностям -->
        <?php if (!empty($specialty_stats)): ?>
            <div class="row mt-4">
                <h5><i class="bi bi-briefcase"></i> Статистика по специальностям</h5>
                <?php foreach ($specialty_stats as $spec_id => $stat): ?>
                    <div class="col-md-4 mb-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h6 class="card-title"><?php echo htmlspecialchars($stat['name']); ?></h6>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Всего:</span>
                                    <strong><?php echo $stat['total']; ?></strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-success">Сдавшие:</span>
                                    <strong class="text-success"><?php echo $stat['passed']; ?></strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-danger">Не сдавшие:</span>
                                    <strong class="text-danger"><?php echo $stat['failed']; ?></strong>
                                </div>
                                <div class="progress mt-2">
                                    <?php 
                                    $passed_percent = $stat['total'] > 0 ? round(($stat['passed'] / $stat['total']) * 100, 1) : 0;
                                    $failed_percent = $stat['total'] > 0 ? round(($stat['failed'] / $stat['total']) * 100, 1) : 0;
                                    ?>
                                    <div class="progress-bar bg-success" style="width: <?php echo $passed_percent; ?>%">
                                        <?php echo $passed_percent; ?>%
                                    </div>
                                    <div class="progress-bar bg-danger" style="width: <?php echo $failed_percent; ?>%">
                                        <?php echo $failed_percent; ?>%
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

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
                <div class="card">
                    <div class="card-body text-center">
                        <h6>Всего</h6>
                        <h4><?php echo $total_results; ?></h4>
                        <small class="text-muted">результатов</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
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
                <div class="card">
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
                <div class="card">
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
                Администратор: <?php echo $_SESSION['admin_username']; ?> | 
                <a href="logout.php" class="text-decoration-none">Выйти</a>
            </small>
        </div>
    </div>

    <!-- Модальное окно редактирования оценки -->
    <div class="modal fade" id="editGradeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Изменение оценки</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="result_id" id="resultId">
                        
                        <div class="mb-3">
                            <label for="grade" class="form-label">Выберите оценку:</label>
                            <select class="form-select" id="grade" name="grade" required>
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
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle text-danger"></i> Подтверждение удаления</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="deleteMessage">Вы уверены, что хотите удалить этот результат тестирования?</p>
                    <div class="alert alert-warning">
                        <i class="bi bi-info-circle"></i>
                        Это действие нельзя отменить. Результат будет удален безвозвратно.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <a id="confirmDeleteBtn" href="#" class="btn btn-danger">Удалить</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно массового удаления -->
    <div class="modal fade" id="bulkDeleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle text-danger"></i> Подтверждение удаления</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="bulkDeleteMessage"></p>
                    <div class="alert alert-warning">
                        <i class="bi bi-info-circle"></i>
                        Это действие нельзя отменить. Выбранные результаты будут удалены безвозвратно.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-danger" onclick="submitBulkDelete()">Удалить выбранные</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Редактирование оценки
        function editGrade(resultId, currentGrade) {
            document.getElementById('resultId').value = resultId;
            document.getElementById('grade').value = currentGrade;
        }
        
        // Подтверждение удаления одного результата
        function confirmDelete(resultId, studentName, testTitle) {
            const message = `Вы уверены, что хотите удалить результат тестирования студента <strong>${studentName}</strong> по тесту <strong>"${testTitle}"</strong>?`;
            document.getElementById('deleteMessage').innerHTML = message;
            
            const deleteUrl = `results.php?delete_result=${resultId}`;
            document.getElementById('confirmDeleteBtn').href = deleteUrl;
            
            const modal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
            modal.show();
        }
        
        // Массовый выбор
        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.result-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
            updateSelection();
        }
        
        // Обновление счетчика выбранных
        function updateSelection() {
            const checkboxes = document.querySelectorAll('.result-checkbox');
            const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            const bulkActions = document.getElementById('bulkActions');
            const selectAll = document.getElementById('selectAll');
            
            document.getElementById('selectedCount').textContent = `${selectedCount} результатов выбрано`;
            
            if (selectedCount > 0) {
                bulkActions.classList.add('show');
            } else {
                bulkActions.classList.remove('show');
            }
            
            // Обновление чекбокса "Выбрать все"
            if (selectedCount === 0) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            } else if (selectedCount === checkboxes.length) {
                selectAll.checked = true;
                selectAll.indeterminate = false;
            } else {
                selectAll.checked = false;
                selectAll.indeterminate = true;
            }
        }
        
        // Очистка выбора
        function clearSelection() {
            const checkboxes = document.querySelectorAll('.result-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = false;
            });
            updateSelection();
        }
        
        // Подтверждение массового удаления
        function confirmBulkDelete() {
            const selectedCount = document.querySelectorAll('.result-checkbox:checked').length;
            const message = `Вы уверены, что хотите удалить ${selectedCount} выбранных результатов?`;
            document.getElementById('bulkDeleteMessage').textContent = message;
            
            const modal = new bootstrap.Modal(document.getElementById('bulkDeleteModal'));
            modal.show();
        }
        
        // Отправка формы массового удаления
        function submitBulkDelete() {
            document.getElementById('resultsForm').submit();
        }
        
        // Печать результатов
        function printResults() {
            const printContent = document.getElementById('resultsTable').innerHTML;
            const originalContent = document.body.innerHTML;
            
            let filtersInfo = '';
            <?php if (!empty($course_filter) || !empty($test_filter) || !empty($status_filter) || !empty($specialty_filter)): ?>
                filtersInfo = '<div class="alert alert-info mb-3"><strong>Фильтры:</strong> ';
                <?php 
                $filterText = [];
                if (!empty($course_filter)) $filterText[] = "Курс: $course_filter";
                if (!empty($test_filter)) {
                    foreach ($tests as $test) {
                        if ($test['id'] == $test_filter) {
                            $filterText[] = "Тест: " . htmlspecialchars($test['title']);
                            break;
                        }
                    }
                }
                if (!empty($specialty_filter)) {
                    foreach ($all_specialties as $spec) {
                        if ($spec['id'] == $specialty_filter) {
                            $filterText[] = "Специальность: " . htmlspecialchars($spec['name']);
                            break;
                        }
                    }
                }
                if (!empty($status_filter)) {
                    $filterText[] = $status_filter === 'passed' ? "Только сдавшие" : "Только не сдавшие";
                }
                ?>
                filtersInfo += '<?php echo implode(', ', $filterText); ?>';
                filtersInfo += '</div>';
            <?php endif; ?>
            
            document.body.innerHTML = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Результаты тестирования - Печать</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { padding: 20px; }
                        .table { font-size: 12px; }
                        h1 { text-align: center; margin-bottom: 20px; }
                        .print-info { margin-bottom: 20px; text-align: center; }
                        @media print {
                            .no-print { display: none; }
                            .table th { background-color: #343a40 !important; color: white !important; }
                        }
                    </style>
                </head>
                <body>
                    <h1>Результаты тестирования студентов</h1>
                    <div class="print-info">
                        <p>Дата печати: ${new Date().toLocaleDateString()}</p>
                        <p>Администратор: <?php echo $_SESSION['admin_username']; ?></p>
                    </div>
                    ${filtersInfo}
                    ${printContent}
                    <div class="no-print mt-4 text-center">
                        <button onclick="window.print()" class="btn btn-primary">Печать</button>
                        <button onclick="window.close()" class="btn btn-secondary">Закрыть</button>
                    </div>
                </body>
                </html>
            `;
            
            // Удаляем колонку с чекбоксами для печати
            document.querySelectorAll('table tr th:first-child, table tr td:first-child').forEach(el => {
                el.style.display = 'none';
            });
            
            window.print();
            document.body.innerHTML = originalContent;
        }
        
        // Экспорт в CSV
        function exportToCSV() {
            let csv = [];
            let rows = document.querySelectorAll('table tr');
            
            // Добавляем информацию о фильтрах в CSV
            <?php if (!empty($course_filter) || !empty($test_filter) || !empty($status_filter) || !empty($specialty_filter)): ?>
                csv.push(['Фильтры:', '']);
                <?php 
                if (!empty($course_filter)) echo "csv.push(['Курс:', '$course_filter']);";
                if (!empty($test_filter)) {
                    foreach ($tests as $test) {
                        if ($test['id'] == $test_filter) {
                            echo "csv.push(['Тест:', '" . addslashes($test['title']) . "']);";
                            break;
                        }
                    }
                }
                if (!empty($specialty_filter)) {
                    foreach ($all_specialties as $spec) {
                        if ($spec['id'] == $specialty_filter) {
                            echo "csv.push(['Специальность:', '" . addslashes($spec['name']) . "']);";
                            break;
                        }
                    }
                }
                if (!empty($status_filter)) {
                    echo "csv.push(['Статус:', '" . ($status_filter === 'passed' ? 'Сдавшие' : 'Не сдавшие') . "']);";
                }
                ?>
                csv.push(['', '']); // Пустая строка
            <?php endif; ?>
            
            // Заголовки таблицы (пропускаем первый столбец с чекбоксами)
            let headers = ['№', 'Студент', 'Курс', 'Город', 'Специальность', 'Тест', 'Баллы', 'Процент', 'Оценка', 'Статус', 'Дата'];
            csv.push(headers);
            
            // Данные таблицы
            let rowIndex = 0;
            rows.forEach(function(row) {
                if (rowIndex > 0) { // Пропускаем заголовок
                    let cols = row.querySelectorAll('td');
                    if (cols.length > 0) {
                        let rowData = [];
                        
                        // Номер (пропускаем чекбокс в первом столбце)
                        rowData.push(rowIndex);
                        
                        // ФИО
                        rowData.push(cols[2].innerText.replace(/,/g, '').replace(/\n/g, ' '));
                        
                        // Курс
                        rowData.push(cols[3].innerText.replace(/,/g, '').replace(/\n/g, ' '));
                        
                        // Город
                        rowData.push(cols[4].innerText.replace(/,/g, '').replace(/\n/g, ' '));
                        
                        // Специальность
                        rowData.push(cols[5].innerText.replace(/,/g, '').replace(/\n/g, ' '));
                        
                        // Тест
                        rowData.push(cols[6].innerText.replace(/,/g, '').replace(/\n/g, ' '));
                        
                        // Баллы
                        let scoreText = cols[7].querySelector('.badge').innerText;
                        rowData.push(scoreText);
                        
                        // Процент
                        let percentage = cols[7].querySelector('small') ? cols[7].querySelector('small').innerText : '0%';
                        rowData.push(percentage);
                        
                        // Оценка
                        rowData.push(cols[8].innerText.replace(/,/g, '').replace(/\n/g, ' '));
                        
                        // Статус
                        let gradeClass = cols[8].querySelector('.badge').className;
                        let status = 'Неизвестно';
                        if (gradeClass.includes('bg-success')) status = 'Сдал';
                        else if (gradeClass.includes('bg-danger')) status = 'Не сдал';
                        rowData.push(status);
                        
                        // Дата
                        rowData.push(cols[9].innerText.replace(/,/g, '').replace(/\n/g, ' '));
                        
                        csv.push(rowData);
                    }
                }
                rowIndex++;
            });
            
            let csvContent = csv.map(row => row.join(',')).join('\n');
            let blob = new Blob(["\uFEFF" + csvContent], { type: 'text/csv;charset=utf-8;' });
            let url = window.URL.createObjectURL(blob);
            let a = document.createElement('a');
            a.href = url;
            
            let filename = 'results_<?php echo date('Y-m-d'); ?>';
            <?php if (!empty($course_filter)): ?>filename += '_course<?php echo $course_filter; ?>';<?php endif; ?>
            <?php if (!empty($test_filter)): ?>filename += '_test<?php echo $test_filter; ?>';<?php endif; ?>
            <?php if (!empty($specialty_filter)): ?>filename += '_specialty<?php echo $specialty_filter; ?>';<?php endif; ?>
            <?php if (!empty($status_filter)): ?>filename += '_<?php echo $status_filter; ?>';<?php endif; ?>
            filename += '.csv';
            
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
        
        // Добавление стиля для кнопки специальностей
        const style = document.createElement('style');
        style.textContent = `
            .bg-purple {
                background-color: #8e44ad !important;
            }
            .bg-purple.active {
                background-color: #6c3483 !important;
            }
            .text-purple {
                color: #8e44ad !important;
            }
        `;
        document.head.appendChild(style);
        
        // Инициализация при загрузке страницы
        document.addEventListener('DOMContentLoaded', function() {
            updateSelection();
        });
    </script>
</body>
</html>