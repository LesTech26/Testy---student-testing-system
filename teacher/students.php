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

// Проверяем, существует ли столбец city в таблице students
$checkColumn = $pdo->query("SHOW COLUMNS FROM students LIKE 'city'")->fetch();
$has_city_column = !empty($checkColumn);

// Получаем параметры фильтрации
$course_filter = isset($_GET['course']) ? (int)$_GET['course'] : '';
$specialty_filter = isset($_GET['specialty']) ? $_GET['specialty'] : '';
$city_filter = isset($_GET['city']) ? $_GET['city'] : '';

// Строим SQL запрос с учетом города преподавателя
$sql = "SELECT * FROM students";
$where_conditions = [];
$params = [];

// Добавляем условие по городу преподавателя
if ($has_city_column && $teacher_city) {
    $where_conditions[] = "(city IS NULL OR city = ?)";
    $params[] = $teacher_city;
} elseif (!$has_city_column && $teacher_city) {

}

// Добавляем дополнительные фильтры
if ($course_filter !== '' || $specialty_filter !== '' || $city_filter !== '') {
    if (!empty($where_conditions)) {
        $sql .= " WHERE " . implode(" AND ", $where_conditions);
    }
    
    // Добавляем остальные условия
    $additional_conditions = [];
    
    if ($course_filter !== '') {
        $additional_conditions[] = "course_number = ?";
        $params[] = $course_filter;
    }
    
    if ($specialty_filter !== '') {
        $additional_conditions[] = "specialty = ?";
        $params[] = $specialty_filter;
    }
    
    if ($city_filter !== '' && $has_city_column) {
        if ($city_filter === 'NULL') {
            $additional_conditions[] = "city IS NULL";
        } else {
            $additional_conditions[] = "city = ?";
            $params[] = $city_filter;
        }
    }
    
    if (!empty($additional_conditions)) {
        if (empty($where_conditions)) {
            $sql .= " WHERE " . implode(" AND ", $additional_conditions);
        } else {
            $sql .= " AND " . implode(" AND ", $additional_conditions);
        }
    }
} elseif (!empty($where_conditions)) {
    $sql .= " WHERE " . implode(" AND ", $where_conditions);
}

$sql .= " ORDER BY last_name, first_name";

if (!empty($params)) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} else {
    $stmt = $pdo->query($sql);
}

$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем список уникальных курсов для фильтра (только для доступных студентов)
if ($has_city_column && $teacher_city) {
    $courses_sql = "SELECT DISTINCT course_number FROM students WHERE city IS NULL OR city = ? ORDER BY course_number";
    $courses_stmt = $pdo->prepare($courses_sql);
    $courses_stmt->execute([$teacher_city]);
} else {
    $courses_sql = "SELECT DISTINCT course_number FROM students ORDER BY course_number";
    $courses_stmt = $pdo->query($courses_sql);
}
$courses = $courses_stmt->fetchAll(PDO::FETCH_COLUMN);

// Получаем список уникальных специальностей для фильтра (только для доступных студентов)
if ($has_city_column && $teacher_city) {
    $specialties_sql = "SELECT DISTINCT specialty FROM students WHERE specialty IS NOT NULL AND specialty != '' AND (city IS NULL OR city = ?) ORDER BY specialty";
    $specialties_stmt = $pdo->prepare($specialties_sql);
    $specialties_stmt->execute([$teacher_city]);
} else {
    $specialties_sql = "SELECT DISTINCT specialty FROM students WHERE specialty IS NOT NULL AND specialty != '' ORDER BY specialty";
    $specialties_stmt = $pdo->query($specialties_sql);
}
$specialties = $specialties_stmt->fetchAll(PDO::FETCH_COLUMN);

// Получаем список уникальных городов для фильтра (только доступные города)
$cities = [];
if ($has_city_column) {
    if ($teacher_city) {
        $cities_sql = "SELECT DISTINCT city FROM students WHERE city IS NOT NULL AND city != '' AND (city IS NULL OR city = ?) ORDER BY city";
        $cities_stmt = $pdo->prepare($cities_sql);
        $cities_stmt->execute([$teacher_city]);
    } else {
        $cities_sql = "SELECT DISTINCT city FROM students WHERE city IS NOT NULL AND city != '' ORDER BY city";
        $cities_stmt = $pdo->query($cities_sql);
    }
    $cities = $cities_stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Просмотр студентов - Преподаватель</title>
    <link rel="icon" href="/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
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
        .table-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .specialty-badge {
            background: linear-gradient(45deg, #6f42c1, #9b6bff);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
        }
        .course-badge {
            background: linear-gradient(45deg, #0d6efd, #3d8bfd);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
        }
        .city-badge {
            background: linear-gradient(45deg, #20c997, #17a2b8);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            margin-left: 5px;
        }
        .no-city-badge {
            background: linear-gradient(45deg, #6c757d, #adb5bd);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            margin-left: 5px;
        }
        .stats-badge {
            background: linear-gradient(45deg, #198754, #20c997);
            color: white;
            padding: 5px 10px;
            border-radius: 10px;
            font-size: 0.9rem;
        }
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
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
        .student-row:hover {
            background-color: #f8f9fa;
            transition: background-color 0.2s;
        }
        .table th {
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }
        .login-badge {
            background: #e9ecef;
            color: #495057;
            padding: 3px 8px;
            border-radius: 5px;
            font-family: monospace;
            font-size: 0.85rem;
        }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px dashed #dee2e6;
        }
        .empty-state i {
            font-size: 4rem;
            color: #6c757d;
            margin-bottom: 20px;
        }
        .filter-badge {
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .filter-badge:hover {
            opacity: 0.9;
        }
        .teacher-city-badge {
            background: linear-gradient(45deg, #fd7e14, #ff922b);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Заголовок -->
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-people"></i> Студенты</h1>
                    <p class="mb-0">Преподаватель: <?php echo $_SESSION['teacher']['last_name'] . ' ' . $_SESSION['teacher']['first_name']; ?>
                    <?php if ($teacher_city): ?>
                        <span class="teacher-city-badge">Город: <?php echo htmlspecialchars($teacher_city); ?></span>
                    <?php endif; ?>
                    </p>
                    <p class="mb-0 mt-1">Найдено студентов: <?php echo count($students); ?></p>
                </div>
                <div>
                    <a href="index.php" class="btn btn-light me-2">
                        <i class="bi bi-arrow-left"></i> Назад
                    </a>
                </div>
            </div>
        </div>

        <!-- Информация о доступе -->
        <?php if (!$has_city_column): ?>
            <div class="warning-info">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Внимание!</strong> Столбец "city" отсутствует в таблице студентов.
                <br>
                <small>Для фильтрации студентов по городам добавьте столбец в базу данных.</small>
            </div>
        <?php elseif ($teacher_city): ?>
            <div class="access-info">
                <i class="bi bi-info-circle"></i>
                <strong>Доступ ограничен:</strong> Вы видите только студентов из города 
                <strong><?php echo htmlspecialchars($teacher_city); ?></strong> и студентов без указания города.
            </div>
        <?php else: ?>
            <div class="access-info">
                <i class="bi bi-globe"></i>
                <strong>Доступ ко всем студентам:</strong> У вас нет привязки к городу, поэтому вы видите всех студентов в системе.
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
            
            <!-- Статистика фильтра -->
            <?php if ($course_filter !== '' || $specialty_filter !== '' || $city_filter !== ''): ?>
                <div class="mt-3 pt-3 border-top">
                    <p class="mb-2">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Активные фильтры:</strong>
                        <?php if ($course_filter !== ''): ?>
                            <a href="students.php?course=<?php echo $course_filter; ?><?php echo $specialty_filter ? '&specialty=' . urlencode($specialty_filter) : ''; ?><?php echo $city_filter ? '&city=' . urlencode($city_filter) : ''; ?>"
                               class="course-badge filter-badge text-decoration-none"
                               title="Показать студентов <?php echo $course_filter; ?> курса">
                                <?php echo $course_filter; ?> курс
                            </a>
                        <?php endif; ?>
                        <?php if ($specialty_filter !== ''): ?>
                            <a href="students.php?specialty=<?php echo urlencode($specialty_filter); ?><?php echo $course_filter ? '&course=' . $course_filter : ''; ?><?php echo $city_filter ? '&city=' . urlencode($city_filter) : ''; ?>"
                               class="specialty-badge filter-badge text-decoration-none"
                               title="Показать студентов специальности <?php echo htmlspecialchars($specialty_filter); ?>">
                                <?php echo htmlspecialchars($specialty_filter); ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($city_filter !== '' && $has_city_column): ?>
                            <?php if ($city_filter === 'NULL'): ?>
                                <span class="no-city-badge">Без города</span>
                            <?php else: ?>
                                <a href="students.php?city=<?php echo urlencode($city_filter); ?><?php echo $course_filter ? '&course=' . $course_filter : ''; ?><?php echo $specialty_filter ? '&specialty=' . urlencode($specialty_filter) : ''; ?>"
                                   class="city-badge filter-badge text-decoration-none"
                                   title="Показать студентов из города <?php echo htmlspecialchars($city_filter); ?>">
                                    <?php echo htmlspecialchars($city_filter); ?>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                    <p class="mb-0 text-muted">
                        Найдено студентов: <?php echo count($students); ?>
                    </p>
                </div>
            <?php endif; ?>
            
            <!-- Информация о городе преподавателя -->
            <?php if ($teacher_city && !$city_filter): ?>
                <div class="mt-2 pt-2 border-top">
                    <small class="text-muted">
                        <i class="bi bi-geo-alt"></i>
                        По умолчанию отображаются студенты из города 
                        <strong><?php echo htmlspecialchars($teacher_city); ?></strong> и без привязки к городу.
                    </small>
                </div>
            <?php endif; ?>
        </div>

        <!-- Таблица студентов -->
        <div class="table-container">
            <?php if (empty($students)): ?>
                <div class="empty-state">
                    <i class="bi bi-people"></i>
                    <h3 class="mt-3">Студенты не найдены</h3>
                    <p class="text-muted">
                        <?php if ($course_filter !== '' || $specialty_filter !== '' || $city_filter !== ''): ?>
                            По вашим фильтрам студентов не найдено.
                            <?php if ($teacher_city): ?>
                                <br>Проверьте, что студенты указаны в городе 
                                <strong><?php echo htmlspecialchars($teacher_city); ?></strong> или не имеют привязки к городу.
                            <?php endif; ?>
                        <?php else: ?>
                            В системе пока нет студентов.
                            <?php if ($teacher_city): ?>
                                <br>Или нет студентов из вашего города 
                                <strong><?php echo htmlspecialchars($teacher_city); ?></strong>.
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                    <a href="students.php" class="btn btn-primary mt-2">
                        <i class="bi bi-arrow-clockwise"></i> Сбросить фильтры
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Фамилия</th>
                                <th>Имя</th>
                                <th>Курс</th>
                                <th>Специальность</th>
                                <?php if ($has_city_column): ?>
                                    <th>Город</th>
                                <?php endif; ?>
                                <th>Логин</th>
                                <th>Дата регистрации</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr class="student-row">
                                    <td><?php echo $student['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($student['last_name']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['first_name']); ?></td>
                                    <td>
                                        <a href="students.php?course=<?php echo $student['course_number']; ?><?php echo $specialty_filter ? '&specialty=' . urlencode($specialty_filter) : ''; ?><?php echo $city_filter ? '&city=' . urlencode($city_filter) : ''; ?>"
                                           class="course-badge text-decoration-none filter-badge"
                                           title="Показать студентов <?php echo $student['course_number']; ?> курса">
                                            <?php echo $student['course_number']; ?> курс
                                        </a>
                                    </td>
                                    <td>
                                        <?php if (!empty($student['specialty'])): ?>
                                            <a href="students.php?specialty=<?php echo urlencode($student['specialty']); ?><?php echo $course_filter ? '&course=' . $course_filter : ''; ?><?php echo $city_filter ? '&city=' . urlencode($city_filter) : ''; ?>"
                                               class="specialty-badge text-decoration-none filter-badge"
                                               title="Показать студентов специальности <?php echo htmlspecialchars($student['specialty']); ?>">
                                                <?php echo htmlspecialchars($student['specialty']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Не указана</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($has_city_column): ?>
                                        <td>
                                            <?php if (!empty($student['city'])): ?>
                                                <a href="students.php?city=<?php echo urlencode($student['city']); ?><?php echo $course_filter ? '&course=' . $course_filter : ''; ?><?php echo $specialty_filter ? '&specialty=' . urlencode($specialty_filter) : ''; ?>"
                                                   class="city-badge text-decoration-none filter-badge"
                                                   title="Показать студентов из города <?php echo htmlspecialchars($student['city']); ?>">
                                                    <?php echo htmlspecialchars($student['city']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="no-city-badge">Не указан</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <span class="login-badge"><?php echo htmlspecialchars($student['username']); ?></span>
                                    </td>
                                    <td>
                                        <small><?php echo date('d.m.Y H:i', strtotime($student['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <a href="student_results.php?id=<?php echo $student['id']; ?>" 
                                           class="btn btn-sm btn-outline-info" 
                                           title="Результаты тестов">
                                            <i class="bi bi-bar-chart"></i> Результаты
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Статистика -->
                <div class="mt-4 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted">
                            <i class="bi bi-people"></i> Показано <?php echo count($students); ?> студентов
                        </span>
                    </div>
                    <div>
                        <?php if ($has_city_column && $teacher_city): ?>
                            <span class="badge bg-info">
                                <i class="bi bi-geo-alt"></i> Доступ: <?php echo htmlspecialchars($teacher_city); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Распределение по курсам -->
                <?php if (count($students) > 0): ?>
                    <?php
                    $course_stats = [];
                    $specialty_stats = [];
                    $city_stats = [];
                    
                    foreach ($students as $student) {
                        // Статистика по курсам
                        $course = $student['course_number'];
                        $course_stats[$course] = ($course_stats[$course] ?? 0) + 1;
                        
                        // Статистика по специальностям
                        $specialty = $student['specialty'] ?: 'Не указана';
                        $specialty_stats[$specialty] = ($specialty_stats[$specialty] ?? 0) + 1;
                        
                        // Статистика по городам
                        if ($has_city_column) {
                            $city = $student['city'] ?: 'Не указан';
                            $city_stats[$city] = ($city_stats[$city] ?? 0) + 1;
                        }
                    }
                    
                    ksort($course_stats);
                    ?>
                    
                    <div class="row mt-4">
                        <div class="col-md-<?php echo $has_city_column ? '4' : '6'; ?>">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="bi bi-mortarboard"></i> Распределение по курсам</h6>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($course_stats as $course => $count): ?>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span><?php echo $course; ?> курс</span>
                                            <span class="badge bg-primary"><?php echo $count; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-<?php echo $has_city_column ? '4' : '6'; ?>">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="bi bi-briefcase"></i> Распределение по специальностям</h6>
                                </div>
                                <div class="card-body">
                                    <?php 
                                    arsort($specialty_stats);
                                    $counter = 0;
                                    foreach ($specialty_stats as $specialty => $count):
                                        if ($counter++ >= 5) break;
                                    ?>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span class="text-truncate" style="max-width: 150px;" title="<?php echo htmlspecialchars($specialty); ?>">
                                                <?php echo htmlspecialchars($specialty); ?>
                                            </span>
                                            <span class="badge bg-secondary"><?php echo $count; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($specialty_stats) > 5): ?>
                                        <div class="text-center mt-2">
                                            <small class="text-muted">и ещё <?php echo count($specialty_stats) - 5; ?> специальностей</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($has_city_column): ?>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0"><i class="bi bi-geo-alt"></i> Распределение по городам</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php 
                                        arsort($city_stats);
                                        $counter = 0;
                                        foreach ($city_stats as $city => $count):
                                            if ($counter++ >= 5) break;
                                        ?>
                                            <div class="d-flex justify-content-between mb-2">
                                                <span class="text-truncate" style="max-width: 150px;" title="<?php echo htmlspecialchars($city); ?>">
                                                    <?php echo htmlspecialchars($city); ?>
                                                </span>
                                                <span class="badge bg-info"><?php echo $count; ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <!-- Кнопка назад -->
        <div class="mt-4 text-center">
            <a href="index.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Вернуться в преподавательскую панель
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Добавляем подсветку строк таблицы
            const rows = document.querySelectorAll('.student-row');
            rows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#f8f9fa';
                });
                row.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                });
            });
            
            // Автофокус на первом фильтре
            const courseFilter = document.getElementById('course');
            if (courseFilter) {
                courseFilter.focus();
            }
            
            // Показываем информацию о доступе
            const cityWarning = document.querySelector('.warning-info');
            if (cityWarning) {
                cityWarning.addEventListener('click', function() {
                    const sql = 'ALTER TABLE students ADD COLUMN city VARCHAR(100) NULL;';
                    if (confirm('Для работы фильтрации по городам необходимо добавить столбец "city" в таблицу students.\n\nСкопировать SQL-запрос?')) {
                        navigator.clipboard.writeText(sql)
                            .then(() => alert('SQL-запрос скопирован в буфер обмена!'))
                            .catch(err => console.error('Ошибка копирования:', err));
                    }
                });
            }
        });
    </script>
</body>
</html>