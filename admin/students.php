<?php
// Файл: admin/students.php

// Включаем отображение ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Стартуем сессию если еще не стартовала
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Подключаем конфигурацию базы данных
require_once '../config/database.php';

// Подключаем функции и проверяем авторизацию администратора
require_once '../config/functions.php';
requireAdminLogin(); // Проверка для администратора

// Получаем информацию о текущем администраторе
$currentAdmin = getCurrentAdmin();
$is_super_admin = ($currentAdmin['type'] === 'super');
$admin_city = $currentAdmin['city'] ?? null; // Город администратора (для городских админов)

// Получаем параметры фильтрации
$course_filter = isset($_GET['course']) ? (int)$_GET['course'] : '';
$specialty_filter = isset($_GET['specialty']) ? $_GET['specialty'] : '';
$city_filter = isset($_GET['city']) ? $_GET['city'] : '';

// Проверяем, существует ли столбец city в таблице students
try {
    $checkColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'city'")->fetch();
    $has_city_column = !empty($checkColumn);
} catch (PDOException $e) {
    $has_city_column = false;
}

// Получаем список специальностей для формы редактирования
$specialties_list = [];
try {
    $stmt = $pdo->query("SELECT * FROM specialties ORDER BY name");
    $specialties_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $specialties_list = [];
}

// Обработка редактирования студента
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
    $student_id = (int)$_POST['student_id'];
    $last_name = trim($_POST['last_name']);
    $first_name = trim($_POST['first_name']);
    $course_number = (int)$_POST['course_number'];
    $specialty = trim($_POST['specialty']);
    $city = isset($_POST['city']) ? trim($_POST['city']) : '';
    $password = trim($_POST['password']);
    
    // Проверяем доступ для городского администратора
    if (!$is_super_admin && $admin_city) {
        $stmt = $pdo->prepare("SELECT city FROM students WHERE id = ?");
        $stmt->execute([$student_id]);
        $student_city = $stmt->fetchColumn();
        
        if ($student_city && $student_city !== $admin_city) {
            $_SESSION['error'] = 'У вас нет прав для редактирования студента из другого города';
            header('Location: students.php');
            exit();
        }
    }
    
    // Валидация
    $errors = [];
    
    if (empty($last_name)) {
        $errors[] = 'Фамилия обязательна';
    }
    
    if (empty($first_name)) {
        $errors[] = 'Имя обязательно';
    }
    
    if ($course_number < 1 || $course_number > 5) {
        $errors[] = 'Некорректный номер курса';
    }
    
    if (empty($specialty)) {
        $errors[] = 'Специальность обязательна';
    }
    
    if (!empty($password) && strlen($password) < 6) {
        $errors[] = 'Пароль должен быть не менее 6 символов';
    }
    
    if (empty($errors)) {
        try {
            if (!empty($password)) {
                // Обновляем с паролем
                $sql = "UPDATE students SET last_name = ?, first_name = ?, course_number = ?, specialty = ?";
                $params = [$last_name, $first_name, $course_number, $specialty];
                
                if ($has_city_column) {
                    $sql .= ", city = ?";
                    $params[] = $city ?: null;
                }
                
                $sql .= ", password = ? WHERE id = ?";
                $params[] = $password;
                $params[] = $student_id;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                logAdminAction($pdo, 'edit_student', "Отредактирован студент ID: $student_id (с паролем)");
                $_SESSION['success'] = 'Данные студента обновлены, пароль изменен';
            } else {
                // Обновляем без пароля
                $sql = "UPDATE students SET last_name = ?, first_name = ?, course_number = ?, specialty = ?";
                $params = [$last_name, $first_name, $course_number, $specialty];
                
                if ($has_city_column) {
                    $sql .= ", city = ?";
                    $params[] = $city ?: null;
                }
                
                $sql .= " WHERE id = ?";
                $params[] = $student_id;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                logAdminAction($pdo, 'edit_student', "Отредактирован студент ID: $student_id (без пароля)");
                $_SESSION['success'] = 'Данные студента обновлены';
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Ошибка при обновлении: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errors);
    }
    
    header('Location: students.php');
    exit();
}

// Обработка изменения города студента
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_city'])) {
    $student_id = (int)$_POST['student_id'];
    $new_city = trim($_POST['city']);
    
    // Проверяем доступ для городского администратора
    if (!$is_super_admin && $admin_city) {
        $stmt = $pdo->prepare("SELECT city FROM students WHERE id = ?");
        $stmt->execute([$student_id]);
        $student_city = $stmt->fetchColumn();
        
        if ($student_city && $student_city !== $admin_city) {
            $_SESSION['error'] = 'У вас нет прав для изменения данных студента из другого города';
            header('Location: students.php');
            exit();
        }
    }
    
    if ($has_city_column) {
        try {
            $stmt = $pdo->prepare("UPDATE students SET city = ? WHERE id = ?");
            $stmt->execute([$new_city ?: null, $student_id]);
            
            logAdminAction($pdo, 'update_student_city', "Обновлен город студента ID: $student_id");
            $_SESSION['success'] = 'Город студента успешно обновлен';
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Ошибка при обновлении города: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = 'Столбец "city" отсутствует в таблице студентов';
    }
    
    header('Location: students.php');
    exit();
}

// Обработка массового изменения городов (только для супер-админа)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update_cities'])) {
    if (!$is_super_admin) {
        $_SESSION['error'] = 'Только супер-администратор может выполнять массовые изменения';
        header('Location: students.php');
        exit();
    }
    
    $selected_students = $_POST['selected_students'] ?? [];
    $bulk_city = trim($_POST['bulk_city']);
    
    if (empty($selected_students)) {
        $_SESSION['error'] = 'Не выбраны студенты для обновления';
    } elseif ($has_city_column) {
        try {
            $updated = 0;
            foreach ($selected_students as $student_id) {
                $student_id = (int)$student_id;
                $stmt = $pdo->prepare("UPDATE students SET city = ? WHERE id = ?");
                $stmt->execute([$bulk_city ?: null, $student_id]);
                $updated++;
            }
            
            logAdminAction($pdo, 'bulk_update_student_cities', "Массовое обновление городов: $updated студентов");
            $_SESSION['success'] = 'Города ' . $updated . ' студентов успешно обновлены';
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Ошибка при массовом обновлении: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = 'Столбец "city" отсутствует в таблице студентов';
    }
    
    header('Location: students.php');
    exit();
}

// Обработка удаления студента
if (isset($_GET['delete'])) {
    $student_id = (int)$_GET['delete'];
    
    // Проверяем доступ для городского администратора
    if (!$is_super_admin && $admin_city) {
        $stmt = $pdo->prepare("SELECT city FROM students WHERE id = ?");
        $stmt->execute([$student_id]);
        $student_city = $stmt->fetchColumn();
        
        if ($student_city && $student_city !== $admin_city) {
            $_SESSION['error'] = 'У вас нет прав для удаления студента из другого города';
            header('Location: students.php');
            exit();
        }
    }
    
    try {
        // Проверяем, есть ли у студента результаты
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM results WHERE student_id = ?");
        $stmt->execute([$student_id]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            // Удаляем сначала результаты студента
            $stmt = $pdo->prepare("DELETE FROM results WHERE student_id = ?");
            $stmt->execute([$student_id]);
        }
        
        // Удаляем студента
        $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
        if ($stmt->execute([$student_id])) {
            logAdminAction($pdo, 'delete_student', "Удален студент ID: $student_id");
            $_SESSION['success'] = 'Студент успешно удален';
        } else {
            $_SESSION['error'] = 'Ошибка при удалении студента';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка: ' . $e->getMessage();
    }
    
    header('Location: students.php');
    exit();
}

// Обработка экспорта в CSV
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    // Строим SQL запрос с учетом фильтров и прав доступа
    $sql = "SELECT * FROM students";
    $params = [];
    $conditions = [];
    
    // Добавляем фильтры
    if ($course_filter !== '') {
        $conditions[] = " course_number = ?";
        $params[] = $course_filter;
    }
    
    if ($specialty_filter !== '') {
        $conditions[] = " specialty = ?";
        $params[] = $specialty_filter;
    }
    
    if ($city_filter !== '') {
        if ($city_filter === 'NULL') {
            $conditions[] = " city IS NULL";
        } else {
            $conditions[] = " city = ?";
            $params[] = $city_filter;
        }
    }
    
    // Добавляем ограничение по городу для городского администратора
    if (!$is_super_admin && $admin_city) {
        $conditions[] = " (city = ? OR city IS NULL OR city = '')";
        $params[] = $admin_city;
    }
    
    if (!empty($conditions)) {
        $sql .= " WHERE" . implode(" AND", $conditions);
    }
    
    $sql .= " ORDER BY last_name, first_name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Устанавливаем заголовки для скачивания файла
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_' . date('Y-m-d') . '.csv"');
    
    // Создаем файловый указатель для вывода
    $output = fopen('php://output', 'w');
    
    // Добавляем BOM для корректного отображения кириллицы в Excel
    fwrite($output, "\xEF\xBB\xBF");
    
    // Заголовки CSV
    $headers = ['Фамилия', 'Имя', 'Курс', 'Специальность', 'Логин', 'Пароль'];
    if ($has_city_column) {
        $headers[] = 'Город';
    }
    fputcsv($output, $headers, ';');
    
    // Данные студентов
    foreach ($students as $student) {
        $row = [
            $student['last_name'],
            $student['first_name'],
            $student['course_number'],
            $student['specialty'],
            $student['username'],
            $student['password']
        ];
        if ($has_city_column) {
            $row[] = $student['city'] ?? '';
        }
        fputcsv($output, $row, ';');
    }
    
    fclose($output);
    exit();
}

// Получаем всех студентов для отображения с учетом фильтров и прав доступа
$sql = "SELECT * FROM students";
$params = [];
$conditions = [];

// Добавляем фильтры
if ($course_filter !== '') {
    $conditions[] = " course_number = ?";
    $params[] = $course_filter;
}

if ($specialty_filter !== '') {
    $conditions[] = " specialty = ?";
    $params[] = $specialty_filter;
}

if ($city_filter !== '') {
    if ($city_filter === 'NULL') {
        $conditions[] = " city IS NULL";
    } else {
        $conditions[] = " city = ?";
        $params[] = $city_filter;
    }
}

// Добавляем ограничение по городу для городского администратора
if (!$is_super_admin && $admin_city) {
    $conditions[] = " (city = ? OR city IS NULL OR city = '')";
    $params[] = $admin_city;
}

if (!empty($conditions)) {
    $sql .= " WHERE" . implode(" AND", $conditions);
}

$sql .= " ORDER BY last_name, first_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем список уникальных курсов для фильтра
try {
    if ($is_super_admin) {
        $courses = $pdo->query("SELECT DISTINCT course_number FROM students ORDER BY course_number")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $stmt = $pdo->prepare("SELECT DISTINCT course_number FROM students WHERE city = ? OR city IS NULL OR city = '' ORDER BY course_number");
        $stmt->execute([$admin_city]);
        $courses = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    $courses = [];
}

// Получаем список уникальных специальностей для фильтра
try {
    if ($is_super_admin) {
        $specialties = $pdo->query("SELECT DISTINCT specialty FROM students WHERE specialty IS NOT NULL AND specialty != '' ORDER BY specialty")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $stmt = $pdo->prepare("SELECT DISTINCT specialty FROM students WHERE (city = ? OR city IS NULL OR city = '') AND specialty IS NOT NULL AND specialty != '' ORDER BY specialty");
        $stmt->execute([$admin_city]);
        $specialties = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    $specialties = [];
}

// Получаем список уникальных городов для фильтра
$cities = [];
if ($has_city_column) {
    try {
        if ($is_super_admin) {
            $cities = $pdo->query("SELECT DISTINCT city FROM students WHERE city IS NOT NULL AND city != '' ORDER BY city")->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $stmt = $pdo->prepare("SELECT DISTINCT city FROM students WHERE city IS NOT NULL AND city != '' AND city = ? ORDER BY city");
            $stmt->execute([$admin_city]);
            $cities = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (PDOException $e) {
        $cities = [];
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление студентами</title>
    <link rel="icon" href="/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
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
        .table-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .password-cell {
            font-family: monospace;
            font-size: 0.9rem;
            color: #666;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }
        .password-cell:hover {
            color: #333;
        }
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .specialty-badge {
            background-color: #6f42c1;
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            display: inline-block;
        }
        .course-badge {
            background-color: #0d6efd;
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            display: inline-block;
        }
        .city-badge {
            background-color: #20c997;
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            display: inline-block;
            text-decoration: none;
        }
        .city-badge:hover {
            opacity: 0.9;
            color: white;
        }
        .no-city-badge {
            background-color: #6c757d;
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            display: inline-block;
        }
        .bulk-actions {
            background: #e7f1ff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .filter-badge {
            background-color: #e9ecef;
            padding: 5px 10px;
            border-radius: 20px;
            margin-right: 5px;
            display: inline-block;
            font-size: 0.9rem;
        }
        .btn-group-sm .btn {
            padding: 0.25rem 0.5rem;
        }
        .export-btn {
            background-color: #28a745;
            color: white;
            border: none;
        }
        .export-btn:hover {
            background-color: #218838;
            color: white;
        }
        .modal-xl {
            max-width: 800px;
        }
        .password-field {
            position: relative;
        }
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            z-index: 10;
        }
        .password-toggle:hover {
            color: #333;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Заголовок -->
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-people"></i> Управление студентами</h1>
                    <p class="mb-0">
                        Найдено студентов: <?php echo count($students); ?>
                        <?php if (!$is_super_admin && $admin_city): ?>
                            <span class="badge bg-light text-dark ms-2">
                                <i class="bi bi-geo-alt"></i> Город: <?php echo htmlspecialchars($admin_city); ?>
                            </span>
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <a href="index.php" class="btn btn-light me-2">
                        <i class="bi bi-arrow-left"></i> Назад
                    </a>
                    <a href="index.php#createStudentForm" class="btn btn-success">
                        <i class="bi bi-person-plus"></i> Добавить студента
                    </a>
                </div>
            </div>
        </div>

        <!-- Сообщения об успехе/ошибке -->
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

        <?php if (!$has_city_column): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Внимание!</strong> Столбец "city" отсутствует в таблице студентов. 
                Для работы с городами добавьте столбец:
                <code>ALTER TABLE students ADD COLUMN city VARCHAR(100) NULL;</code>
            </div>
        <?php endif; ?>

        <!-- Фильтры -->
        <div class="filter-card">
            <h5 class="mb-3"><i class="bi bi-funnel"></i> Фильтры</h5>
            <form method="GET" action="" class="row g-3">
                <div class="col-md-3">
                    <label for="course" class="form-label">Курс</label>
                    <select class="form-select" id="course" name="course">
                        <option value="">Все курсы</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?php echo $course; ?>" <?php echo $course_filter == $course ? 'selected' : ''; ?>>
                                <?php echo $course; ?> курс
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="specialty" class="form-label">Специальность</label>
                    <select class="form-select" id="specialty" name="specialty">
                        <option value="">Все специальности</option>
                        <?php foreach ($specialties as $specialty): ?>
                            <option value="<?php echo htmlspecialchars($specialty); ?>" 
                                <?php echo $specialty_filter == $specialty ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($specialty); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($has_city_column): ?>
                    <div class="col-md-3">
                        <label for="city" class="form-label">Город</label>
                        <select class="form-select" id="city" name="city">
                            <option value="">Все города</option>
                            <option value="NULL" <?php echo $city_filter === 'NULL' ? 'selected' : ''; ?>>Не указан</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?php echo htmlspecialchars($city); ?>" 
                                    <?php echo $city_filter == $city ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($city); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="col-md-<?php echo $has_city_column ? '3' : '6'; ?> d-flex align-items-end">
                    <div class="d-flex gap-2 w-100">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="bi bi-funnel"></i> Применить фильтры
                        </button>
                        <?php if ($course_filter !== '' || $specialty_filter !== '' || $city_filter !== ''): ?>
                            <a href="students.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle"></i> Сбросить
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
            
            <!-- Активные фильтры -->
            <?php if ($course_filter !== '' || $specialty_filter !== '' || $city_filter !== ''): ?>
                <div class="mt-3 pt-3 border-top">
                    <p class="mb-2">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Активные фильтры:</strong>
                    </p>
                    <div>
                        <?php if ($course_filter !== ''): ?>
                            <span class="filter-badge">
                                <i class="bi bi-mortarboard"></i> <?php echo $course_filter; ?> курс
                                <a href="?<?php 
                                    $params = $_GET;
                                    unset($params['course']);
                                    echo http_build_query($params);
                                ?>" class="ms-1 text-danger" style="text-decoration: none;">×</a>
                            </span>
                        <?php endif; ?>
                        <?php if ($specialty_filter !== ''): ?>
                            <span class="filter-badge">
                                <i class="bi bi-briefcase"></i> <?php echo htmlspecialchars($specialty_filter); ?>
                                <a href="?<?php 
                                    $params = $_GET;
                                    unset($params['specialty']);
                                    echo http_build_query($params);
                                ?>" class="ms-1 text-danger" style="text-decoration: none;">×</a>
                            </span>
                        <?php endif; ?>
                        <?php if ($city_filter !== ''): ?>
                            <span class="filter-badge">
                                <i class="bi bi-geo-alt"></i> 
                                <?php if ($city_filter === 'NULL'): ?>
                                    Не указан
                                <?php else: ?>
                                    <?php echo htmlspecialchars($city_filter); ?>
                                <?php endif; ?>
                                <a href="?<?php 
                                    $params = $_GET;
                                    unset($params['city']);
                                    echo http_build_query($params);
                                ?>" class="ms-1 text-danger" style="text-decoration: none;">×</a>
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="mb-0 text-muted mt-2">
                        Найдено студентов: <?php echo count($students); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Массовые действия (только для супер-админа) -->
        <?php if ($is_super_admin && $has_city_column && !empty($students)): ?>
            <div class="bulk-actions">
                <h6><i class="bi bi-gear"></i> Массовые действия с городами</h6>
                <form method="POST" action="" class="row g-3 align-items-center" id="bulkForm">
                    <div class="col-md-8">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="selectAll">
                            <label class="form-check-label" for="selectAll">
                                Выбрать всех отображенных студентов (<?php echo count($students); ?>)
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="input-group">
                            <input type="text" class="form-control" name="bulk_city" placeholder="Новый город">
                            <button type="submit" name="bulk_update_cities" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Применить
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Таблица студентов -->
        <div class="table-container">
            <?php if (empty($students)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-people display-1 text-muted"></i>
                    <h3 class="mt-3">Студенты не найдены</h3>
                    <p class="text-muted">
                        <?php if ($course_filter !== '' || $specialty_filter !== '' || $city_filter !== ''): ?>
                            По вашим фильтрам студентов не найдено.
                        <?php else: ?>
                            Добавьте первого студента через форму на главной странице.
                        <?php endif; ?>
                    </p>
                    <a href="index.php#createStudentForm" class="btn btn-primary">
                        <i class="bi bi-person-plus"></i> Добавить студента
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-dark">
                            <tr>
                                <?php if ($is_super_admin && $has_city_column): ?>
                                    <th width="30">
                                        <input class="form-check-input" type="checkbox" id="selectAllTable">
                                    </th>
                                <?php endif; ?>
                                <th>ID</th>
                                <th>Фамилия</th>
                                <th>Имя</th>
                                <th>Курс</th>
                                <th>Специальность</th>
                                <?php if ($has_city_column): ?>
                                    <th>Город</th>
                                <?php endif; ?>
                                <th>Логин</th>
                                <th>Пароль</th>
                                <th>Дата регистрации</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <?php if ($is_super_admin && $has_city_column): ?>
                                        <td>
                                            <input class="form-check-input student-checkbox" 
                                                   type="checkbox" 
                                                   name="selected_students[]" 
                                                   value="<?php echo $student['id']; ?>">
                                        </td>
                                    <?php endif; ?>
                                    <td><?php echo $student['id']; ?></td>
                                    <td><?php echo htmlspecialchars($student['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['first_name']); ?></td>
                                    <td>
                                        <span class="course-badge"><?php echo $student['course_number']; ?> курс</span>
                                    </td>
                                    <td>
                                        <span class="specialty-badge"><?php echo htmlspecialchars($student['specialty']); ?></span>
                                    </td>
                                    <?php if ($has_city_column): ?>
                                        <td>
                                            <?php if (!empty($student['city'])): ?>
                                                <a href="?city=<?php echo urlencode($student['city']); ?>" 
                                                   class="city-badge" 
                                                   title="Показать студентов из города <?php echo htmlspecialchars($student['city']); ?>">
                                                    <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($student['city']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="no-city-badge">
                                                    <i class="bi bi-question-circle"></i> Не указан
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <code><?php echo htmlspecialchars($student['username']); ?></code>
                                    </td>
                                    <td>
                                        <div class="password-cell" 
                                             title="<?php echo htmlspecialchars($student['password']); ?>"
                                             onclick="copyToClipboard('<?php echo htmlspecialchars($student['password']); ?>')">
                                            <?php echo htmlspecialchars($student['password']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($student['created_at'])); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="student_results.php?id=<?php echo $student['id']; ?>" 
                                               class="btn btn-outline-info" 
                                               title="Результаты тестов">
                                                <i class="bi bi-bar-chart"></i>
                                            </a>
                                            <button type="button" 
                                                    class="btn btn-outline-warning" 
                                                    title="Редактировать"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editModal<?php echo $student['id']; ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php if ($has_city_column): ?>
                                                <button type="button" 
                                                        class="btn btn-outline-success" 
                                                        title="Изменить город"
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#cityModal<?php echo $student['id']; ?>">
                                                    <i class="bi bi-geo-alt"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" 
                                                    class="btn btn-outline-danger" 
                                                    title="Удалить"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#deleteModal<?php echo $student['id']; ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                        
                                        <!-- Модальное окно редактирования студента -->
                                        <div class="modal fade" id="editModal<?php echo $student['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-xl">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            <i class="bi bi-pencil"></i> Редактирование студента
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST" action="">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                            <input type="hidden" name="edit_student" value="1">
                                                            
                                                            <div class="row mb-3">
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="last_name_<?php echo $student['id']; ?>" class="form-label">Фамилия:</label>
                                                                        <input type="text" class="form-control" 
                                                                               id="last_name_<?php echo $student['id']; ?>" 
                                                                               name="last_name" 
                                                                               value="<?php echo htmlspecialchars($student['last_name']); ?>"
                                                                               required>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="first_name_<?php echo $student['id']; ?>" class="form-label">Имя:</label>
                                                                        <input type="text" class="form-control" 
                                                                               id="first_name_<?php echo $student['id']; ?>" 
                                                                               name="first_name" 
                                                                               value="<?php echo htmlspecialchars($student['first_name']); ?>"
                                                                               required>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="row mb-3">
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="course_number_<?php echo $student['id']; ?>" class="form-label">Курс:</label>
                                                                        <select class="form-select" id="course_number_<?php echo $student['id']; ?>" name="course_number" required>
                                                                            <option value="1" <?php echo $student['course_number'] == 1 ? 'selected' : ''; ?>>1 курс</option>
                                                                            <option value="2" <?php echo $student['course_number'] == 2 ? 'selected' : ''; ?>>2 курс</option>
                                                                            <option value="3" <?php echo $student['course_number'] == 3 ? 'selected' : ''; ?>>3 курс</option>
                                                                            <option value="4" <?php echo $student['course_number'] == 4 ? 'selected' : ''; ?>>4 курс</option>
                                                                            <option value="5" <?php echo $student['course_number'] == 5 ? 'selected' : ''; ?>>5 курс</option>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="specialty_<?php echo $student['id']; ?>" class="form-label">Специальность:</label>
                                                                        <select class="form-select" id="specialty_<?php echo $student['id']; ?>" name="specialty" required>
                                                                            <option value="">Выберите специальность</option>
                                                                            <?php foreach ($specialties_list as $specialty_item): ?>
                                                                                <option value="<?php echo htmlspecialchars($specialty_item['name']); ?>" 
                                                                                    <?php echo $student['specialty'] == $specialty_item['name'] ? 'selected' : ''; ?>>
                                                                                    <?php echo htmlspecialchars($specialty_item['name']); ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <?php if ($has_city_column): ?>
                                                                <div class="row mb-3">
                                                                    <div class="col-md-6">
                                                                        <div class="mb-3">
                                                                            <label for="city_<?php echo $student['id']; ?>" class="form-label">Город:</label>
                                                                            <input type="text" class="form-control" 
                                                                                   id="city_<?php echo $student['id']; ?>" 
                                                                                   name="city" 
                                                                                   value="<?php echo htmlspecialchars($student['city'] ?? ''); ?>"
                                                                                   placeholder="Введите город">
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <div class="mb-3">
                                                                            <label for="username_<?php echo $student['id']; ?>" class="form-label">Логин (неизменяемый):</label>
                                                                            <input type="text" class="form-control" 
                                                                                   id="username_<?php echo $student['id']; ?>" 
                                                                                   value="<?php echo htmlspecialchars($student['username']); ?>"
                                                                                   disabled>
                                                                            <small class="text-muted">Логин нельзя изменить</small>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="row mb-3">
                                                                    <div class="col-md-12">
                                                                        <div class="mb-3">
                                                                            <label for="username_<?php echo $student['id']; ?>" class="form-label">Логин (неизменяемый):</label>
                                                                            <input type="text" class="form-control" 
                                                                                   id="username_<?php echo $student['id']; ?>" 
                                                                                   value="<?php echo htmlspecialchars($student['username']); ?>"
                                                                                   disabled>
                                                                            <small class="text-muted">Логин нельзя изменить</small>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>
                                                            
                                                            <div class="row mb-3">
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="current_password_<?php echo $student['id']; ?>" class="form-label">Текущий пароль:</label>
                                                                        <div class="input-group">
                                                                            <input type="text" class="form-control" 
                                                                                   id="current_password_<?php echo $student['id']; ?>" 
                                                                                   value="<?php echo htmlspecialchars($student['password']); ?>"
                                                                                   disabled>
                                                                            <button type="button" class="btn btn-outline-secondary" 
                                                                                    onclick="copyToClipboard('<?php echo htmlspecialchars($student['password']); ?>')">
                                                                                <i class="bi bi-clipboard"></i>
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="password_<?php echo $student['id']; ?>" class="form-label">Новый пароль:</label>
                                                                        <div class="password-field">
                                                                            <div class="input-group">
                                                                                <input type="password" class="form-control" 
                                                                                       id="password_<?php echo $student['id']; ?>" 
                                                                                       name="password"
                                                                                       placeholder="Оставьте пустым, чтобы не менять">
                                                                                <button type="button" class="btn btn-outline-secondary" 
                                                                                        onclick="togglePasswordVisibility('password_<?php echo $student['id']; ?>', this)">
                                                                                    <i class="bi bi-eye"></i>
                                                                                </button>
                                                                                <button type="button" class="btn btn-outline-secondary" 
                                                                                        onclick="generatePassword('password_<?php echo $student['id']; ?>')">
                                                                                    <i class="bi bi-shuffle"></i>
                                                                                </button>
                                                                            </div>
                                                                        </div>
                                                                        <small class="text-muted">Минимум 6 символов. Оставьте пустым, если не нужно менять</small>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="row">
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label class="form-label">Дата регистрации:</label>
                                                                        <div>
                                                                            <?php echo date('d.m.Y H:i', strtotime($student['created_at'])); ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                                                            <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Модальное окно изменения города -->
                                        <?php if ($has_city_column): ?>
                                            <div class="modal fade" id="cityModal<?php echo $student['id']; ?>" tabindex="-1">
                                                <div class="modal-dialog modal-sm">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Изменить город</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <form method="POST" action="">
                                                            <div class="modal-body">
                                                                <p>Студент: <strong><?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name']); ?></strong></p>
                                                                <div class="mb-3">
                                                                    <label class="form-label">Город:</label>
                                                                    <input type="text" 
                                                                           class="form-control" 
                                                                           name="city" 
                                                                           value="<?php echo htmlspecialchars($student['city'] ?? ''); ?>"
                                                                           placeholder="Название города">
                                                                    <div class="form-text">
                                                                        Оставьте пустым, чтобы убрать город
                                                                    </div>
                                                                </div>
                                                                <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                                                                <button type="submit" name="update_city" class="btn btn-primary">Сохранить</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Модальное окно подтверждения удаления -->
                                        <div class="modal fade" id="deleteModal<?php echo $student['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Подтверждение удаления</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p>Вы уверены, что хотите удалить студента?</p>
                                                        <div class="alert alert-warning">
                                                            <strong><?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name']); ?></strong><br>
                                                            Курс: <?php echo $student['course_number']; ?><br>
                                                            Специальность: <?php echo htmlspecialchars($student['specialty']); ?><br>
                                                            <?php if ($has_city_column && !empty($student['city'])): ?>
                                                                Город: <?php echo htmlspecialchars($student['city']); ?><br>
                                                            <?php endif; ?>
                                                            Логин: <?php echo htmlspecialchars($student['username']); ?>
                                                        </div>
                                                        <div class="alert alert-danger">
                                                            <i class="bi bi-exclamation-triangle"></i>
                                                            Будут также удалены все результаты тестов этого студента!
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                                                        <a href="students.php?delete=<?php echo $student['id']; ?>" 
                                                           class="btn btn-danger">Удалить</a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Экспорт данных -->
                <div class="mt-4 d-flex justify-content-end">
                    <a href="students.php?export=csv<?php 
                        $params = [];
                        if ($course_filter !== '') $params[] = 'course=' . $course_filter;
                        if ($specialty_filter !== '') $params[] = 'specialty=' . urlencode($specialty_filter);
                        if ($city_filter !== '') $params[] = 'city=' . urlencode($city_filter);
                        echo !empty($params) ? '&' . implode('&', $params) : '';
                    ?>" 
                       class="btn btn-success">
                        <i class="bi bi-download"></i> Экспорт в CSV
                    </a>
                </div>
                
                <!-- Информация о количестве -->
                <div class="mt-3">
                    <p class="text-muted mb-0">
                        Показано студентов: <?php echo count($students); ?>
                        <?php if ($course_filter !== '' || $specialty_filter !== '' || $city_filter !== ''): ?>
                            <br><small>При экспорте будут выгружены только отфильтрованные записи.</small>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Кнопка назад -->
        <div class="mt-4 text-center">
            <a href="index.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Вернуться в админ-панель
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Функция для копирования текста в буфер обмена
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                // Визуальная обратная связь
                alert('Пароль скопирован в буфер обмена');
            }).catch(err => {
                console.error('Ошибка при копировании: ', err);
                // Fallback для старых браузеров
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    alert('Пароль скопирован в буфер обмена');
                } catch (err) {
                    console.error('Fallback ошибка: ', err);
                    alert('Не удалось скопировать пароль');
                }
                document.body.removeChild(textArea);
            });
        }
        
        // Показать/скрыть пароль
        function togglePasswordVisibility(inputId, button) {
            const input = document.getElementById(inputId);
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }
        
        // Генерация случайного пароля
        function generatePassword(inputId) {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            let password = '';
            const length = 10;
            
            for (let i = 0; i < length; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            
            const input = document.getElementById(inputId);
            input.value = password;
            input.type = 'text';
            
            // Обновляем иконку рядом, если есть
            const button = input.closest('.input-group').querySelector('button[onclick*="togglePasswordVisibility"]');
            if (button) {
                const icon = button.querySelector('i');
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            }
            
            // Выделяем пароль
            input.focus();
            input.select();
            
            alert('Сгенерирован новый пароль: ' + password);
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Выбор всех студентов (только для супер-админа)
            const selectAllCheckbox = document.getElementById('selectAll');
            const selectAllTableCheckbox = document.getElementById('selectAllTable');
            const studentCheckboxes = document.querySelectorAll('.student-checkbox');
            
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    studentCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    if (selectAllTableCheckbox) {
                        selectAllTableCheckbox.checked = this.checked;
                    }
                });
            }
            
            if (selectAllTableCheckbox) {
                selectAllTableCheckbox.addEventListener('change', function() {
                    studentCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    if (selectAllCheckbox) {
                        selectAllCheckbox.checked = this.checked;
                    }
                });
            }
            
            // Показ полного пароля при наведении
            const passwordCells = document.querySelectorAll('.password-cell');
            passwordCells.forEach(cell => {
                cell.addEventListener('mouseenter', function() {
                    const password = this.getAttribute('title');
                    const originalText = this.innerText;
                    
                    if (originalText.length < password.length) {
                        this.setAttribute('data-original-text', originalText);
                        this.innerText = password;
                    }
                });
                
                cell.addEventListener('mouseleave', function() {
                    const originalText = this.getAttribute('data-original-text');
                    if (originalText) {
                        this.innerText = originalText;
                        this.removeAttribute('data-original-text');
                    }
                });
            });
            
            // Валидация формы редактирования
            document.querySelectorAll('form').forEach(form => {
                if (form.querySelector('input[name="edit_student"]')) {
                    form.addEventListener('submit', function(e) {
                        const lastName = form.querySelector('input[name="last_name"]').value.trim();
                        const firstName = form.querySelector('input[name="first_name"]').value.trim();
                        const password = form.querySelector('input[name="password"]').value;
                        
                        let errors = [];
                        
                        if (!lastName) errors.push('Фамилия обязательна');
                        if (!firstName) errors.push('Имя обязательно');
                        
                        if (password && password.length < 6) {
                            errors.push('Пароль должен быть не менее 6 символов');
                        }
                        
                        if (errors.length > 0) {
                            e.preventDefault();
                            alert('Ошибки в форме:\n' + errors.join('\n'));
                            return false;
                        }
                        
                        return true;
                    });
                }
            });
        });
    </script>
</body>
</html>