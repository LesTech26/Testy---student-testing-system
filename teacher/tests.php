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
$checkColumn = $pdo->query("SHOW COLUMNS FROM tests LIKE 'city'")->fetch();
$has_city_column = !empty($checkColumn);

// Функция для создания правил оценивания по умолчанию
function getDefaultGradingRules($totalQuestions)
{
    $rules = [
        'rules' => [
            ['min_correct' => ceil($totalQuestions * 0.9), 'grade' => 5, 'label' => 'Отлично'],
            ['min_correct' => ceil($totalQuestions * 0.75), 'grade' => 4, 'label' => 'Хорошо'],
            ['min_correct' => ceil($totalQuestions * 0.6), 'grade' => 3, 'label' => 'Удовлетворительно'],
            ['min_correct' => ceil($totalQuestions * 0.5), 'grade' => 2, 'label' => 'Неудовлетворительно'],
            ['min_correct' => 0, 'grade' => 1, 'label' => 'Очень плохо']
        ]
    ];
    return json_encode($rules);
}

// Получение списка специальностей
$stmt = $pdo->prepare("SELECT * FROM specialties ORDER BY name");
$stmt->execute();
$specialties = $stmt->fetchAll();

// Обработка действий
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $title = trim($_POST['title']);
                $description = trim($_POST['description']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $time_limit = isset($_POST['time_limit']) ? intval($_POST['time_limit']) : 30;
                $passing_score = isset($_POST['passing_score']) ? intval($_POST['passing_score']) : 0;
                $grading_system = $_POST['grading_system'] ?? 'manual';
                $course_id = !empty($_POST['course_id']) ? intval($_POST['course_id']) : null;
                $specialty_id = !empty($_POST['specialty_id']) ? intval($_POST['specialty_id']) : null;

                // Сохраняем тест с привязкой к городу преподавателя
                $sql = "INSERT INTO tests (title, description, is_active, time_limit, passing_score, grading_system, course_id, specialty_id";
                $params = [$title, $description, $is_active, $time_limit, $passing_score, $grading_system, $course_id, $specialty_id];
                
                if ($has_city_column) {
                    $sql .= ", city";
                    $params[] = $teacher_city;
                }
                
                $sql .= ") VALUES (?, ?, ?, ?, ?, ?, ?, ?";
                if ($has_city_column) {
                    $sql .= ", ?";
                }
                $sql .= ")";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                $test_id = $pdo->lastInsertId();

                // Если ручная система оценивания, создаем правила по умолчанию
                if ($grading_system === 'manual') {
                    $defaultRules = getDefaultGradingRules(10); // По умолчанию 10 вопросов
                    $stmt = $pdo->prepare("UPDATE tests SET grading_rules = ? WHERE id = ?");
                    $stmt->execute([$defaultRules, $test_id]);
                }

                header('Location: tests.php?success=test_created');
                exit();
            }
            break;

        case 'edit':
            if (!isset($_GET['id'])) {
                header('Location: tests.php');
                exit();
            }

            $id = intval($_GET['id']);

            // Проверяем, принадлежит ли тест преподавателю (по городу)
            $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ?" . ($has_city_column && $teacher_city ? " AND (city IS NULL OR city = ?)" : ""));
            if ($has_city_column && $teacher_city) {
                $stmt->execute([$id, $teacher_city]);
            } else {
                $stmt->execute([$id]);
            }
            $test = $stmt->fetch();

            if (!$test) {
                header('Location: tests.php?error=access_denied');
                exit();
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $title = trim($_POST['title']);
                $description = trim($_POST['description']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $time_limit = isset($_POST['time_limit']) ? intval($_POST['time_limit']) : 30;
                $passing_score = isset($_POST['passing_score']) ? intval($_POST['passing_score']) : 0;
                $grading_system = $_POST['grading_system'] ?? 'manual';
                $course_id = !empty($_POST['course_id']) ? intval($_POST['course_id']) : null;
                $specialty_id = !empty($_POST['specialty_id']) ? intval($_POST['specialty_id']) : null;

                $sql = "UPDATE tests SET title = ?, description = ?, is_active = ?, time_limit = ?, passing_score = ?, grading_system = ?, course_id = ?, specialty_id = ? WHERE id = ?";
                $params = [$title, $description, $is_active, $time_limit, $passing_score, $grading_system, $course_id, $specialty_id, $id];

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                // Если система изменилась на автоматическую, удаляем правила
                if ($grading_system === 'auto') {
                    $stmt = $pdo->prepare("UPDATE tests SET grading_rules = NULL WHERE id = ?");
                    $stmt->execute([$id]);
                }

                header('Location: tests.php?success=test_updated');
                exit();
            }
            break;

        case 'grading_rules':
            if (!isset($_GET['id'])) {
                header('Location: tests.php');
                exit();
            }

            $id = intval($_GET['id']);

            // Проверяем, принадлежит ли тест преподавателю (по городу)
            $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ?" . ($has_city_column && $teacher_city ? " AND (city IS NULL OR city = ?)" : ""));
            if ($has_city_column && $teacher_city) {
                $stmt->execute([$id, $teacher_city]);
            } else {
                $stmt->execute([$id]);
            }
            $test = $stmt->fetch();

            if (!$test) {
                header('Location: tests.php?error=access_denied');
                exit();
            }

            // Получаем количество вопросов
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM questions WHERE test_id = ?");
            $stmt->execute([$id]);
            $questionCount = $stmt->fetch()['count'];

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $rules = [];
                $rules['rules'] = [];

                // Собираем правила из формы
                if (isset($_POST['min_correct']) && isset($_POST['grade']) && isset($_POST['label'])) {
                    $count = count($_POST['min_correct']);
                    for ($i = 0; $i < $count; $i++) {
                        $min_correct = intval($_POST['min_correct'][$i]);
                        $grade = intval($_POST['grade'][$i]);
                        $label = trim($_POST['label'][$i]);

                        if ($min_correct >= 0 && $grade >= 1 && $grade <= 5) {
                            $rules['rules'][] = [
                                'min_correct' => $min_correct,
                                'grade' => $grade,
                                'label' => $label
                            ];
                        }
                    }
                }

                // Сортируем по min_correct (по убыванию)
                usort($rules['rules'], function ($a, $b) {
                    return $b['min_correct'] <=> $a['min_correct'];
                });

                $grading_rules = json_encode($rules, JSON_UNESCAPED_UNICODE);

                $stmt = $pdo->prepare("UPDATE tests SET grading_rules = ?, grading_system = 'manual' WHERE id = ?");
                if ($stmt->execute([$grading_rules, $id])) {
                    header("Location: tests.php?action=edit&id=$id&success=grading_rules_updated");
                    exit();
                }
            }

            // Загружаем существующие правила или создаем по умолчанию
            if (!empty($test['grading_rules'])) {
                $rules = json_decode($test['grading_rules'], true);
            } else {
                $rules = json_decode(getDefaultGradingRules($questionCount), true);
            }
            break;

        case 'delete':
            if (isset($_GET['id'])) {
                $id = intval($_GET['id']);

                // Проверяем, принадлежит ли тест преподавателю (по городу)
                $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ?" . ($has_city_column && $teacher_city ? " AND (city IS NULL OR city = ?)" : ""));
                if ($has_city_column && $teacher_city) {
                    $stmt->execute([$id, $teacher_city]);
                } else {
                    $stmt->execute([$id]);
                }
                $test = $stmt->fetch();

                if (!$test) {
                    header('Location: tests.php?error=access_denied');
                    exit();
                }

                // Проверяем, есть ли результаты
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM results WHERE test_id = ?");
                $stmt->execute([$id]);
                $resultsCount = $stmt->fetch()['count'];

                if ($resultsCount > 0) {
                    header('Location: tests.php?error=test_has_results');
                    exit();
                }

                $stmt = $pdo->prepare("DELETE FROM tests WHERE id = ?");
                $stmt->execute([$id]);

                header('Location: tests.php?success=test_deleted');
                exit();
            }
            break;

        case 'toggle':
            if (isset($_GET['id'])) {
                $id = intval($_GET['id']);

                // Проверяем, принадлежит ли тест преподавателю (по городу)
                $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ?" . ($has_city_column && $teacher_city ? " AND (city IS NULL OR city = ?)" : ""));
                if ($has_city_column && $teacher_city) {
                    $stmt->execute([$id, $teacher_city]);
                } else {
                    $stmt->execute([$id]);
                }
                $test = $stmt->fetch();

                if (!$test) {
                    header('Location: tests.php?error=access_denied');
                    exit();
                }

                $stmt = $pdo->prepare("UPDATE tests SET is_active = NOT is_active WHERE id = ?");
                $stmt->execute([$id]);

                header('Location: tests.php');
                exit();
            }
            break;
    }
}

// Получение всех тестов преподавателя (из его города или общих)
$sql = "SELECT t.*, 
           s.name as specialty_name,
           (SELECT COUNT(*) FROM questions q WHERE q.test_id = t.id) as question_count 
    FROM tests t 
    LEFT JOIN specialties s ON t.specialty_id = s.id";

if ($has_city_column && $teacher_city) {
    $sql .= " WHERE t.city IS NULL OR t.city = ?";
    $params = [$teacher_city];
} else {
    $params = [];
}

$sql .= " ORDER BY t.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tests = $stmt->fetchAll();

// Определяем текущее действие
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление тестами - Преподаватель</title>
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
            background: linear-gradient(45deg, #2c3e50, #3498db);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .btn-action-group {
            display: flex;
            gap: 5px;
            flex-wrap: nowrap;
        }

        .btn-action-group .btn {
            padding: 5px 10px;
            font-size: 0.85rem;
        }

        .table-responsive {
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            padding: 20px;
            overflow-x: auto;
        }

        .action-buttons {
            margin-bottom: 20px;
        }

        .card {
            border: none;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }

        .grading-rule-row {
            background: #f8f9fa;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 8px;
            border-left: 4px solid #3498db;
            transition: all 0.3s;
        }

        .grading-rule-row:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }

        .grade-badge {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
            color: white;
            margin-right: 10px;
        }

        .grade-5 {
            background: linear-gradient(45deg, #28a745, #20c997);
        }

        .grade-4 {
            background: linear-gradient(45deg, #17a2b8, #0dcaf0);
        }

        .grade-3 {
            background: linear-gradient(45deg, #ffc107, #ffca2c);
        }

        .grade-2 {
            background: linear-gradient(45deg, #fd7e14, #ff922b);
        }

        .grade-1 {
            background: linear-gradient(45deg, #dc3545, #e35d6a);
        }

        .time-badge {
            background: linear-gradient(45deg, #6f42c1, #9b6bff);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .course-badge {
            background: linear-gradient(45deg, #ffc107, #fd7e14);
            color: #212529;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
            margin-left: 5px;
            white-space: nowrap;
        }

        .specialty-badge {
            background: linear-gradient(45deg, #6f42c1, #9b6bff);
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
            margin-left: 5px;
            white-space: nowrap;
        }

        .city-badge {
            background: linear-gradient(45deg, #20c997, #17a2b8);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-left: 5px;
        }

        .no-city-badge {
            background-color: #6c757d;
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            margin-left: 5px;
        }

        .preview-box {
            background: white;
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }

        .test-title {
            font-weight: bold;
            font-size: 1rem;
            margin-bottom: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 250px;
        }

        .test-description {
            font-size: 0.85rem;
            color: #6c757d;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
            display: block;
        }

        .table th {
            white-space: nowrap;
            vertical-align: middle;
            font-weight: 600;
        }

        .table td {
            vertical-align: middle;
        }

        .status-badge {
            white-space: nowrap;
        }

        .grading-badge {
            white-space: nowrap;
        }

        .action-column {
            min-width: 160px;
            white-space: nowrap;
        }

        .badge-container {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 5px;
        }

        .ellipsis-text {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 150px;
        }

        .full-width-btn {
            width: 100%;
            white-space: nowrap;
            margin-bottom: 10px;
        }

        .city-info {
            background: #e7f3ff;
            border-left: 4px solid #3498db;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        .access-alert {
            border-left: 4px solid #ffc107;
            padding: 10px;
            background: #fff3cd;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        @media (max-width: 1200px) {
            .test-title {
                max-width: 200px;
            }
            
            .test-description {
                max-width: 150px;
            }
            
            .btn-action-group {
                flex-direction: column;
            }
            
            .btn-action-group .btn {
                width: 100%;
                margin-bottom: 2px;
            }
        }

        @media (max-width: 992px) {
            .table-responsive {
                padding: 10px;
            }
            
            .test-title {
                max-width: 150px;
            }
            
            .test-description {
                display: none;
            }
            
            .action-column {
                min-width: 140px;
            }
        }

        @media (max-width: 768px) {
            .table th, .table td {
                padding: 8px 5px;
                font-size: 0.85rem;
            }
            
            .test-title {
                max-width: 100px;
                font-size: 0.9rem;
            }
            
            .action-column {
                min-width: 120px;
            }
            
            .btn-action-group .btn {
                padding: 3px 6px;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 576px) {
            .table-responsive {
                padding: 5px;
            }
            
            .test-title {
                max-width: 80px;
            }
            
            .action-column {
                min-width: 100px;
            }
            
            .badge {
                font-size: 0.75rem;
                padding: 3px 6px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Заголовок с навигацией -->
        <div class="header mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-journal-text"></i> Управление тестами</h1>
                    <p class="mb-0">Преподаватель: <?php echo $_SESSION['teacher']['last_name'] . ' ' . $_SESSION['teacher']['first_name']; ?>
                    <?php if ($teacher_city): ?>
                        <span class="city-badge">Город: <?php echo htmlspecialchars($teacher_city); ?></span>
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
        <?php if (!$has_city_column): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Внимание!</strong> Столбец "city" отсутствует в таблице тестов. 
                Для работы с городами добавьте столбец:
                <code>ALTER TABLE tests ADD COLUMN city VARCHAR(100) NULL;</code>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php elseif ($teacher_city): ?>
            <div class="city-info">
                <i class="bi bi-info-circle"></i>
                <strong>Ваш город:</strong> <?php echo htmlspecialchars($teacher_city); ?>
                <br>
                <small>Вы можете видеть только тесты из вашего города и общие тесты (без привязки к городу).</small>
            </div>
        <?php else: ?>
            <div class="access-alert">
                <i class="bi bi-globe"></i>
                <strong>Нет привязки к городу</strong>
                <br>
                <small>Вы можете видеть все тесты в системе.</small>
            </div>
        <?php endif; ?>

        <!-- Навигационные кнопки -->
        <div class="action-buttons mb-4">
            <div class="d-flex justify-content-between">
                <div>
                    <a href="index.php" class="btn btn-outline-primary me-2">
                        <i class="bi bi-house"></i> Главная
                    </a>
                    <a href="questions.php" class="btn btn-outline-success me-2">
                        <i class="bi bi-question-circle"></i> Вопросы
                    </a>
                    <a href="results.php" class="btn btn-outline-info">
                        <i class="bi bi-bar-chart"></i> Результаты
                    </a>
                </div>
                <div>
                    <a href="?action=create" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Создать новый тест
                    </a>
                </div>
            </div>
        </div>

        <!-- Сообщения об успехе/ошибке -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php
                switch ($_GET['success']) {
                    case 'test_created':
                        echo 'Тест успешно создан!';
                        break;
                    case 'test_updated':
                        echo 'Тест успешно обновлен!';
                        break;
                    case 'test_deleted':
                        echo 'Тест успешно удален!';
                        break;
                    case 'grading_rules_updated':
                        echo 'Правила оценивания успешно обновлены!';
                        break;
                }
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php
                switch ($_GET['error']) {
                    case 'test_has_results':
                        echo 'Нельзя удалить тест, по которому уже есть результаты!';
                        break;
                    case 'access_denied':
                        echo 'У вас нет прав для выполнения этого действия!';
                        break;
                }
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Основное содержимое -->
        <?php if ($action === 'list'): ?>
            <?php if (empty($tests)): ?>
                <div class="alert alert-info text-center">
                    <i class="bi bi-info-circle fs-4"></i>
                    <h4 class="mt-2">Нет созданных тестов</h4>
                    <p>Начните с создания первого теста</p>
                    <a href="?action=create" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Создать тест
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Название теста</th>
                                <th>Курс</th>
                                <th>Специальность</th>
                                <th>Город</th>
                                <th>Вопросов</th>
                                <th>Время</th>
                                <th>Оценивание</th>
                                <th>Статус</th>
                                <th class="action-column">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tests as $test): ?>
                                <tr>
                                    <td><?php echo $test['id']; ?></td>
                                    <td>
                                        <div class="test-title" title="<?php echo htmlspecialchars($test['title']); ?>">
                                            <?php 
                                            $short_title = escape($test['title']);
                                            if (strlen($short_title) > 30) {
                                                $short_title = substr($short_title, 0, 30) . '...';
                                            }
                                            echo $short_title;
                                            ?>
                                        </div>
                                        <div class="test-description" title="<?php echo htmlspecialchars($test['description']); ?>">
                                            <?php 
                                            $short_desc = escape($test['description']);
                                            if (strlen($short_desc) > 40) {
                                                $short_desc = substr($short_desc, 0, 40) . '...';
                                            }
                                            echo $short_desc;
                                            ?>
                                        </div>
                                        <div class="badge-container">
                                            <?php if (!empty($test['course_id'])): ?>
                                                <span class="course-badge">
                                                    <i class="bi bi-mortarboard"></i> <?php echo $test['course_id']; ?> курс
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($test['specialty_name'])): ?>
                                                <span class="specialty-badge">
                                                    <i class="bi bi-briefcase"></i> 
                                                    <?php 
                                                    $short_specialty = escape($test['specialty_name']);
                                                    if (strlen($short_specialty) > 20) {
                                                        $short_specialty = substr($short_specialty, 0, 20) . '...';
                                                    }
                                                    echo $short_specialty;
                                                    ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($test['course_id'])): ?>
                                            <span class="badge bg-warning text-dark"><?php echo $test['course_id']; ?> курс</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Все</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($test['specialty_name'])): ?>
                                            <div class="ellipsis-text" title="<?php echo htmlspecialchars($test['specialty_name']); ?>">
                                                <?php 
                                                $short_specialty = escape($test['specialty_name']);
                                                if (strlen($short_specialty) > 20) {
                                                    $short_specialty = substr($short_specialty, 0, 20) . '...';
                                                }
                                                echo $short_specialty;
                                                ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Все</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($has_city_column): ?>
                                            <?php if (!empty($test['city'])): ?>
                                                <span class="city-badge"><?php echo htmlspecialchars($test['city']); ?></span>
                                            <?php else: ?>
                                                <span class="no-city-badge">Общий</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo $test['question_count']; ?></span>
                                    </td>
                                    <td>
                                        <?php if ($test['time_limit'] > 0): ?>
                                            <span class="time-badge"><?php echo $test['time_limit']; ?> мин</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">∞</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($test['grading_system'] == 'manual'): ?>
                                            <span class="badge bg-primary grading-badge" title="Ручная настройка оценок">
                                                <i class="bi bi-star"></i> Ручная
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary grading-badge" title="Автоматическая система">
                                                <i class="bi bi-stars"></i> Авто
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge status-badge bg-<?php echo $test['is_active'] ? 'success' : 'danger'; ?>">
                                            <?php echo $test['is_active'] ? 'Активен' : 'Неакт'; ?>
                                        </span>
                                    </td>
                                    <td class="action-column">
                                        <div class="btn-action-group">
                                            <a href="?action=edit&id=<?php echo $test['id']; ?>"
                                                class="btn btn-sm btn-outline-primary" title="Редактировать">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="questions.php?test_id=<?php echo $test['id']; ?>"
                                                class="btn btn-sm btn-outline-success" title="Управление вопросами">
                                                <i class="bi bi-question-circle"></i>
                                            </a>
                                            <?php if ($test['grading_system'] == 'manual'): ?>
                                                <a href="?action=grading_rules&id=<?php echo $test['id']; ?>"
                                                    class="btn btn-sm btn-outline-info" title="Настройка оценок">
                                                    <i class="bi bi-star"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="?action=toggle&id=<?php echo $test['id']; ?>"
                                                class="btn btn-sm btn-outline-warning"
                                                title="<?php echo $test['is_active'] ? 'Деактивировать' : 'Активировать'; ?>">
                                                <i class="bi bi-power"></i>
                                            </a>
                                            <a href="?action=delete&id=<?php echo $test['id']; ?>"
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Вы уверены, что хотите удалить этот тест?')"
                                                title="Удалить">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php elseif ($action === 'create' || $action === 'edit'): ?>
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h3 class="mb-0">
                                <i class="bi bi-journal-text"></i>
                                <?php echo $action === 'create' ? 'Создание нового теста' : 'Редактирование теста'; ?>
                            </h3>
                            <?php if ($has_city_column && $teacher_city && $action === 'create'): ?>
                                <small class="d-block mt-1">Тест будет создан для города: <?php echo htmlspecialchars($teacher_city); ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="title" class="form-label">Название теста:</label>
                                    <input type="text" class="form-control" id="title" name="title" required
                                        placeholder="Например: Тест по математике"
                                        value="<?php echo isset($test) ? escape($test['title']) : ''; ?>">
                                </div>

                                <div class="mb-3">
                                    <label for="description" class="form-label">Описание теста:</label>
                                    <textarea class="form-control" id="description" name="description" rows="3"
                                        placeholder="Опишите тест..."><?php echo isset($test) ? escape($test['description']) : ''; ?></textarea>
                                </div>

                                <!-- Выбор курса -->
                                <div class="mb-3">
                                    <label for="course_id" class="form-label">Курс:</label>
                                    <select class="form-select" id="course_id" name="course_id">
                                        <option value="">-- Без привязки к курсу (все студенты) --</option>
                                        <option value="1" <?php echo (isset($test) && $test['course_id'] == 1) ? 'selected' : ''; ?>>1 курс</option>
                                        <option value="2" <?php echo (isset($test) && $test['course_id'] == 2) ? 'selected' : ''; ?>>2 курс</option>
                                        <option value="3" <?php echo (isset($test) && $test['course_id'] == 3) ? 'selected' : ''; ?>>3 курс</option>
                                        <option value="4" <?php echo (isset($test) && $test['course_id'] == 4) ? 'selected' : ''; ?>>4 курс</option>
                                    </select>
                                    <small class="text-muted">Если выбрать курс, тест будет доступен только студентам этого курса</small>
                                </div>

                                <!-- Выбор специальности -->
                                <div class="mb-3">
                                    <label for="specialty_id" class="form-label">Специальность:</label>
                                    <select class="form-select" id="specialty_id" name="specialty_id">
                                        <option value="">-- Без привязки к специальности (все специальности) --</option>
                                        <?php foreach ($specialties as $specialty): ?>
                                            <option value="<?php echo $specialty['id']; ?>" 
                                                <?php echo (isset($test) && $test['specialty_id'] == $specialty['id']) ? 'selected' : ''; ?>>
                                                <?php echo escape($specialty['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Если выбрать специальность, тест будет доступен только студентам этой специальности</small>
                                </div>

                                <!-- Информация о городе -->
                                <?php if ($has_city_column && $teacher_city): ?>
                                    <div class="mb-3 alert alert-info">
                                        <i class="bi bi-info-circle"></i>
                                        <strong>Город:</strong> <?php echo htmlspecialchars($teacher_city); ?>
                                        <br>
                                        <small>Тест будет доступен студентам из вашего города и общим студентам (без привязки к городу).</small>
                                    </div>
                                <?php endif; ?>

                                <!-- Настройка времени -->
                                <div class="mb-4">
                                    <label for="time_limit" class="form-label">
                                        <i class="bi bi-clock"></i> Время на прохождение теста (минут):
                                    </label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="time_limit" name="time_limit" min="1"
                                            max="180" required
                                            value="<?php echo isset($test) ? $test['time_limit'] : '30'; ?>">
                                        <span class="input-group-text">минут</span>
                                    </div>
                                    <small class="text-muted">Установите ограничение по времени от 1 до 180 минут</small>
                                </div>

                                <!-- Система оценивания -->
                                <div class="mb-4">
                                    <label for="grading_system" class="form-label">
                                        <i class="bi bi-star"></i> Система оценивания:
                                    </label>
                                    <select class="form-select" id="grading_system" name="grading_system">
                                        <option value="manual" <?php echo (isset($test) && $test['grading_system'] == 'manual') || !isset($test) ? 'selected' : ''; ?>>
                                            Ручная настройка (оценки от 1 до 5)
                                        </option>
                                        <option value="auto" <?php echo isset($test) && $test['grading_system'] == 'auto' ? 'selected' : ''; ?>>
                                            Автоматическая
                                        </option>
                                    </select>
                                    <div class="form-text">
                                        <strong>Ручная система:</strong> Вы сами настраиваете сколько правильных ответов нужно для каждой оценки (1-5)<br>
                                        <strong>Автоматическая:</strong> Система рассчитывает оценку автоматически
                                    </div>
                                </div>

                                <!-- Минимальный балл для зачета -->
                                <div class="mb-4">
                                    <label for="passing_score" class="form-label">
                                        <i class="bi bi-check-circle"></i> Минимальный балл для зачета:
                                    </label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="passing_score" name="passing_score"
                                            min="0" max="100" placeholder="Например: 60"
                                            value="<?php echo isset($test) ? $test['passing_score'] : '0'; ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                    <small class="text-muted">0 = тест всегда засчитывается</small>
                                </div>

                                <div class="mb-3 form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="is_active" name="is_active"
                                        value="1" <?php echo (isset($test) && $test['is_active']) || !isset($test) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_active">
                                        Тест активен (доступен для прохождения)
                                    </label>
                                </div>

                                <div class="d-flex justify-content-between mt-4">
                                    <a href="tests.php" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Назад к списку
                                    </a>
                                    <button type="submit" class="btn btn-primary px-4">
                                        <?php if ($action === 'create'): ?>
                                            <i class="bi bi-plus-circle"></i> Создать тест
                                        <?php else: ?>
                                            <i class="bi bi-check-circle"></i> Сохранить изменения
                                        <?php endif; ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($action === 'grading_rules'): ?>
            <div class="row justify-content-center">
                <div class="col-md-10">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3 class="mb-0">
                                        <i class="bi bi-star"></i>
                                        Настройка системы оценивания
                                    </h3>
                                    <p class="mb-0">Тест: <?php echo escape($test['title']); ?></p>
                                    <?php if ($has_city_column && !empty($test['city'])): ?>
                                        <small class="d-block">Город: <?php echo htmlspecialchars($test['city']); ?></small>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <span class="badge bg-light text-dark">
                                        <i class="bi bi-clock"></i> <?php echo $test['time_limit']; ?> мин
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Информация о тесте -->
                            <div class="alert alert-info mb-4">
                                <div class="row">
                                    <div class="col-md-6">
                                        <p class="mb-1"><i class="bi bi-journal-text"></i> <strong>Тест:</strong>
                                            <?php echo escape($test['title']); ?></p>
                                        <p class="mb-0"><i class="bi bi-question-circle"></i> <strong>Всего вопросов:</strong> <span class="badge bg-primary" id="totalQuestions"><?php echo $questionCount; ?></span></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-1"><i class="bi bi-clock"></i> <strong>Время на тест:</strong> <?php echo $test['time_limit']; ?> минут</p>
                                        <p class="mb-0"><i class="bi bi-info-circle"></i> Настройте правила оценивания от 1 до 5 баллов</p>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <h5><i class="bi bi-lightbulb"></i> Как работает система оценивания:</h5>
                                <p class="text-muted">
                                    Система проверяет правила <strong>сверху вниз</strong>.
                                    Первое правило, для которого количество правильных ответов ≥ минимального значения,
                                    определяет итоговую оценку.
                                    Например, если у вас есть правило "Оценка 5: 8-9 правильных ответов", то студент получит 5, если ответит правильно на 8 или 9 вопросов.
                                </p>
                            </div>

                            <form method="POST" id="gradingRulesForm">
                                <div id="gradingRulesContainer">
                                    <?php if (isset($rules['rules']) && is_array($rules['rules'])):
                                        foreach ($rules['rules'] as $index => $rule): ?>
                                            <div class="grading-rule-row" data-index="<?php echo $index; ?>">
                                                <div class="row align-items-center">
                                                    <div class="col-md-1 text-center">
                                                        <div class="grade-badge grade-<?php echo $rule['grade']; ?>">
                                                            <?php echo $rule['grade']; ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-5">
                                                        <label class="form-label">Минимальное количество правильных ответов:</label>
                                                        <div class="input-group">
                                                            <input type="number" class="form-control min-correct-input"
                                                                name="min_correct[]" min="0" max="<?php echo $questionCount; ?>"
                                                                value="<?php echo $rule['min_correct']; ?>"
                                                                onchange="updateRulePreview(this)" required>
                                                            <span class="input-group-text">из <?php echo $questionCount; ?></span>
                                                        </div>
                                                        <small class="text-muted">Студент получит эту оценку, если наберет указанное количество правильных ответов или больше</small>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">Описание оценки:</label>
                                                        <input type="text" class="form-control" name="label[]"
                                                            placeholder="Например: Отлично, Хорошо, и т.д."
                                                            value="<?php echo escape($rule['label']); ?>" required>
                                                        <small class="text-muted">Текстовое описание оценки</small>
                                                    </div>
                                                    <div class="col-md-2 d-flex align-items-end">
                                                        <input type="hidden" name="grade[]" value="<?php echo $rule['grade']; ?>">
                                                        <?php if ($index > 0): ?>
                                                            <button type="button" class="btn btn-danger btn-sm w-100"
                                                                onclick="removeRule(this)">
                                                                <i class="bi bi-trash"></i> Удалить
                                                            </button>
                                                        <?php else: ?>
                                                            <div class="text-muted small">Базовое правило</div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="rule-preview mt-2 small text-muted" id="preview_<?php echo $index; ?>">
                                                    <?php
                                                    $prev_min = isset($rules['rules'][$index - 1]) ? $rules['rules'][$index - 1]['min_correct'] : $questionCount + 1;
                                                    $current_min = $rule['min_correct'];
                                                    if ($prev_min > $current_min) {
                                                        $max = $prev_min - 1;
                                                        echo "Оценка {$rule['grade']} ({$rule['label']}) за правильных ответов: {$current_min} - {$max}";
                                                    } else {
                                                        echo "Оценка {$rule['grade']} ({$rule['label']}) за {$current_min} или более правильных ответов";
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-3 mb-4">
                                    <button type="button" class="btn btn-success" onclick="addNewRule()">
                                        <i class="bi bi-plus-circle"></i> Добавить правило оценивания
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="resetToDefault()">
                                        <i class="bi bi-arrow-clockwise"></i> Сбросить к значениям по умолчанию
                                    </button>
                                </div>

                                <!-- Предпросмотр системы оценивания -->
                                <div class="preview-box">
                                    <h5><i class="bi bi-eye"></i> Предпросмотр системы оценивания:</h5>
                                    <div id="gradingPreview">
                                        <?php if (isset($rules['rules'])): ?>
                                            <div class="row">
                                                <?php
                                                $prev_min = $questionCount + 1;
                                                foreach ($rules['rules'] as $index => $rule):
                                                    $current_min = $rule['min_correct'];
                                                    if ($prev_min > $current_min) {
                                                        $max = $prev_min - 1;
                                                        $range = "{$current_min} - {$max}";
                                                    } else {
                                                        $range = "{$current_min}+";
                                                    }
                                                    $prev_min = $current_min;
                                                    ?>
                                                    <div class="col-md-2 mb-2">
                                                        <div class="card text-center">
                                                            <div class="card-body p-2">
                                                                <div class="grade-badge grade-<?php echo $rule['grade']; ?> mx-auto mb-2">
                                                                    <?php echo $rule['grade']; ?>
                                                                </div>
                                                                <h6 class="mb-1"><?php echo $rule['label']; ?></h6>
                                                                <small class="text-muted"><?php echo $range; ?> правильных</small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="alert alert-warning mt-4">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    <strong>Важно:</strong>
                                    <ul class="mb-0">
                                        <li>Правила проверяются сверху вниз - первое подходящее правило определяет оценку</li>
                                        <li>Рекомендуется располагать правила от большего к меньшему количеству правильных ответов</li>
                                        <li>Последнее правило должно иметь минимальное значение 0 для студентов, не прошедших другие критерии</li>
                                    </ul>
                                </div>

                                <div class="d-flex justify-content-between mt-4">
                                    <a href="?action=edit&id=<?php echo $test['id']; ?>" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Назад к редактированию
                                    </a>
                                    <button type="submit" class="btn btn-primary px-4">
                                        <i class="bi bi-check-circle"></i> Сохранить систему оценивания
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                let ruleCount = <?php echo isset($rules['rules']) ? count($rules['rules']) : 0; ?>;
                const totalQuestions = <?php echo $questionCount; ?>;
                let currentGrades = new Set(<?php echo isset($rules['rules']) ? json_encode(array_column($rules['rules'], 'grade')) : '[]'; ?>);

                // Доступные оценки
                const availableGrades = [
                    { value: 5, label: "Отлично", color: "grade-5" },
                    { value: 4, label: "Хорошо", color: "grade-4" },
                    { value: 3, label: "Удовлетворительно", color: "grade-3" },
                    { value: 2, label: "Неудовлетворительно", color: "grade-2" },
                    { value: 1, label: "Очень плохо", color: "grade-1" }
                ];

                // Получить следующую доступную оценку
                function getNextAvailableGrade() {
                    for (let grade of availableGrades) {
                        if (!currentGrades.has(grade.value)) {
                            return grade;
                        }
                    }
                    return availableGrades[availableGrades.length - 1];
                }

                // Обновить предпросмотр правила
                function updateRulePreview(input) {
                    const row = input.closest('.grading-rule-row');
                    const index = Array.from(row.parentNode.children).indexOf(row);
                    const minCorrect = parseInt(input.value) || 0;

                    // Найти предыдущее правило
                    const allRules = Array.from(document.querySelectorAll('.min-correct-input'));
                    const currentIndex = allRules.indexOf(input);
                    let prevMin = totalQuestions + 1;

                    if (currentIndex > 0) {
                        prevMin = parseInt(allRules[currentIndex - 1].value) || 0;
                    }

                    // Обновить текст предпросмотра
                    const previewElement = row.querySelector('.rule-preview');
                    if (prevMin > minCorrect) {
                        const max = prevMin - 1;
                        previewElement.innerHTML = `Оценка ${row.querySelector('[name="grade[]"]').value} (${row.querySelector('[name="label[]"]').value}) за правильных ответов: ${minCorrect} - ${max}`;
                    } else {
                        previewElement.innerHTML = `Оценка ${row.querySelector('[name="grade[]"]').value} (${row.querySelector('[name="label[]"]').value}) за ${minCorrect} или более правильных ответов`;
                    }

                    updatePreview();
                }

                // Обновить общий предпросмотр
                function updatePreview() {
                    const rules = [];
                    const ruleElements = document.querySelectorAll('.grading-rule-row');

                    ruleElements.forEach((element, index) => {
                        const minCorrect = parseInt(element.querySelector('[name="min_correct[]"]').value) || 0;
                        const grade = parseInt(element.querySelector('[name="grade[]"]').value) || 1;
                        const label = element.querySelector('[name="label[]"]').value;
                        rules.push({ min_correct: minCorrect, grade: grade, label: label });
                    });

                    // Сортировка по убыванию min_correct
                    rules.sort((a, b) => b.min_correct - a.min_correct);

                    // Генерация HTML предпросмотра
                    let previewHTML = '<div class="row">';
                    let prevMin = totalQuestions + 1;

                    rules.forEach(rule => {
                        const currentMin = rule.min_correct;
                        let range;

                        if (prevMin > currentMin) {
                            const max = prevMin - 1;
                            range = `${currentMin} - ${max}`;
                        } else {
                            range = `${currentMin}+`;
                        }
                        prevMin = currentMin;

                        previewHTML += `
                            <div class="col-md-2 mb-2">
                                <div class="card text-center">
                                    <div class="card-body p-2">
                                        <div class="grade-badge grade-${rule.grade} mx-auto mb-2">
                                            ${rule.grade}
                                        </div>
                                        <h6 class="mb-1">${rule.label}</h6>
                                        <small class="text-muted">${range} правильных</small>
                                    </div>
                                </div>
                            </div>
                        `;
                    });

                    previewHTML += '</div>';
                    document.getElementById('gradingPreview').innerHTML = previewHTML;
                }

                // Добавить новое правило
                function addNewRule() {
                    if (ruleCount >= 5) {
                        alert('Максимальное количество правил - 5 (оценки от 1 до 5)');
                        return;
                    }

                    const nextGrade = getNextAvailableGrade();
                    currentGrades.add(nextGrade.value);

                    const container = document.getElementById('gradingRulesContainer');
                    const newRule = document.createElement('div');
                    newRule.className = 'grading-rule-row';
                    newRule.innerHTML = `
                        <div class="row align-items-center">
                            <div class="col-md-1 text-center">
                                <div class="grade-badge ${nextGrade.color}">
                                    ${nextGrade.value}
                                </div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Минимальное количество правильных ответов:</label>
                                <div class="input-group">
                                    <input type="number" class="form-control min-correct-input" 
                                           name="min_correct[]" min="0" max="${totalQuestions}"
                                           value="0" 
                                           onchange="updateRulePreview(this)"
                                           required>
                                    <span class="input-group-text">из ${totalQuestions}</span>
                                </div>
                                <small class="text-muted">Студент получит эту оценку, если наберет указанное количество правильных ответов или больше</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Описание оценки:</label>
                                <input type="text" class="form-control" 
                                       name="label[]" 
                                       placeholder="${nextGrade.label}"
                                       value="${nextGrade.label}"
                                       required>
                                <small class="text-muted">Текстовое описание оценки</small>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <input type="hidden" name="grade[]" value="${nextGrade.value}">
                                <button type="button" class="btn btn-danger btn-sm w-100" onclick="removeRule(this)">
                                    <i class="bi bi-trash"></i> Удалить
                                </button>
                            </div>
                        </div>
                        <div class="rule-preview mt-2 small text-muted">
                            Оценка ${nextGrade.value} (${nextGrade.label}) за 0 или более правильных ответов
                        </div>
                    `;
                    container.appendChild(newRule);
                    ruleCount++;
                    updatePreview();
                }

                // Удалить правило
                function removeRule(button) {
                    const ruleRow = button.closest('.grading-rule-row');
                    const gradeInput = ruleRow.querySelector('[name="grade[]"]');
                    const gradeValue = parseInt(gradeInput.value);

                    // Нельзя удалить все правила
                    const allRules = document.querySelectorAll('.grading-rule-row');
                    if (allRules.length <= 1) {
                        alert('Должно быть хотя бы одно правило!');
                        return;
                    }

                    // Удаляем оценку из текущих
                    currentGrades.delete(gradeValue);
                    ruleRow.remove();
                    ruleCount--;
                    updatePreview();
                }

                // Сбросить к значениям по умолчанию
                function resetToDefault() {
                    if (confirm('Сбросить все правила к значениям по умолчанию?')) {
                        const container = document.getElementById('gradingRulesContainer');
                        container.innerHTML = '';
                        currentGrades.clear();
                        ruleCount = 0;

                        // Создаем правила по умолчанию
                        const defaultRules = [
                            { min_correct: Math.ceil(totalQuestions * 0.9), grade: 5, label: "Отлично" },
                            { min_correct: Math.ceil(totalQuestions * 0.75), grade: 4, label: "Хорошо" },
                            { min_correct: Math.ceil(totalQuestions * 0.6), grade: 3, label: "Удовлетворительно" },
                            { min_correct: Math.ceil(totalQuestions * 0.5), grade: 2, label: "Неудовлетворительно" },
                            { min_correct: 0, grade: 1, label: "Очень плохо" }
                        ];

                        defaultRules.forEach(rule => {
                            const nextGrade = availableGrades.find(g => g.value === rule.grade);
                            currentGrades.add(rule.grade);

                            const newRule = document.createElement('div');
                            newRule.className = 'grading-rule-row';
                            newRule.innerHTML = `
                                <div class="row align-items-center">
                                    <div class="col-md-1 text-center">
                                        <div class="grade-badge ${nextGrade.color}">
                                            ${nextGrade.value}
                                        </div>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">Минимальное количество правильных ответов:</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control min-correct-input" 
                                                   name="min_correct[]" min="0" max="${totalQuestions}"
                                                   value="${rule.min_correct}" 
                                                   onchange="updateRulePreview(this)"
                                                   required>
                                            <span class="input-group-text">из ${totalQuestions}</span>
                                        </div>
                                        <small class="text-muted">Студент получит эту оценку, если наберет указанное количество правильных ответов или больше</small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Описание оценки:</label>
                                        <input type="text" class="form-control" 
                                               name="label[]" 
                                               value="${rule.label}"
                                               required>
                                        <small class="text-muted">Текстовое описание оценки</small>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <input type="hidden" name="grade[]" value="${nextGrade.value}">
                                        ${ruleCount > 0 ? '<button type="button" class="btn btn-danger btn-sm w-100" onclick="removeRule(this)"><i class="bi bi-trash"></i> Удалить</button>' : '<div class="text-muted small">Базовое правило</div>'}
                                    </div>
                                </div>
                                <div class="rule-preview mt-2 small text-muted" id="preview_${ruleCount}">
                                    ${rule.min_correct > 0 ? `Оценка ${nextGrade.value} (${rule.label}) за ${rule.min_correct} или более правильных ответов` : `Оценка ${nextGrade.value} (${rule.label}) за любые результаты`}
                                </div>
                            `;
                            container.appendChild(newRule);
                            ruleCount++;
                        });

                        updatePreview();
                    }
                }

                // Валидация формы
                document.getElementById('gradingRulesForm').addEventListener('submit', function (e) {
                    const minCorrectInputs = document.querySelectorAll('[name="min_correct[]"]');
                    const gradeInputs = document.querySelectorAll('[name="grade[]"]');
                    const labelInputs = document.querySelectorAll('[name="label[]"]');

                    let isValid = true;
                    let errorMessage = '';

                    // Проверка уникальности оценок
                    const grades = new Set();
                    gradeInputs.forEach(input => {
                        const grade = parseInt(input.value);
                        if (grades.has(grade)) {
                            isValid = false;
                            errorMessage = 'Оценки должны быть уникальными (1, 2, 3, 4, 5)';
                        }
                        grades.add(grade);
                    });

                    // Проверка диапазонов
                    const minValues = Array.from(minCorrectInputs).map(input => parseInt(input.value));
                    const sorted = [...minValues].sort((a, b) => b - a);

                    if (JSON.stringify(minValues) !== JSON.stringify(sorted)) {
                        isValid = false;
                        errorMessage = 'Значения должны быть расположены по убыванию (от большего к меньшему)';
                    }

                    // Последнее правило должно быть 0
                    if (minValues[minValues.length - 1] !== 0) {
                        isValid = false;
                        errorMessage = 'Последнее правило должно иметь минимальное значение 0';
                    }

                    if (!isValid) {
                        e.preventDefault();
                        alert('Ошибка: ' + errorMessage); 
                    }
                });

                // Инициализация
                document.addEventListener('DOMContentLoaded', function () {
                    updatePreview();
                });
            </script>
        <?php endif; ?>

        <!-- Навигация внизу -->
        <div class="mt-4 pt-3 border-top">
            <div class="row">
                <div class="col-md-3 mb-2">
                    <a href="index.php" class="btn full-width-btn btn-outline-primary">
                        <i class="bi bi-house"></i> Главная
                    </a>
                </div>
                <div class="col-md-3 mb-2">
                    <a href="tests.php" class="btn full-width-btn btn-primary">
                        <i class="bi bi-journal-text"></i> Тесты
                    </a>
                </div>
                <div class="col-md-3 mb-2">
                    <a href="questions.php" class="btn full-width-btn btn-outline-success">
                        <i class="bi bi-question-circle"></i> Вопросы
                    </a>
                </div>
                <div class="col-md-3 mb-2">
                    <a href="results.php" class="btn full-width-btn btn-outline-info">
                        <i class="bi bi-bar-chart"></i> Результаты
                    </a>
                </div>
            </div>
        </div>

        <!-- Информация о системе -->
        <div class="mt-4 text-center text-muted">
            <small>
                <i class="bi bi-info-circle"></i> Всего тестов: <?php echo count($tests); ?> |
                Преподаватель: <?php echo $_SESSION['teacher']['last_name'] . ' ' . $_SESSION['teacher']['first_name']; ?> |
                <?php if ($teacher_city): ?>Город: <?php echo htmlspecialchars($teacher_city); ?> | <?php endif; ?>
                <a href="logout.php" class="text-decoration-none">Выйти</a>
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Подтверждение удаления
        document.addEventListener('DOMContentLoaded', function () {
            const deleteButtons = document.querySelectorAll('a[href*="action=delete"]');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function (e) {
                    if (!confirm('Вы уверены, что хотите удалить этот тест?\nВсе связанные вопросы также будут удалены!')) {
                        e.preventDefault();
                    }
                });
            });

            // Копирование SQL для добавления столбца city
            const cityWarning = document.querySelector('.alert-warning code');
            if (cityWarning) {
                cityWarning.addEventListener('click', function() {
                    if (confirm('Скопировать SQL-запрос для добавления столбца city?')) {
                        navigator.clipboard.writeText(this.textContent)
                            .then(() => alert('SQL-запрос скопирован в буфер обмена!'))
                            .catch(err => console.error('Ошибка копирования:', err));
                    }
                });
            }
        });
    </script>
</body>

</html>