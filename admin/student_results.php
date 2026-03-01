<?php
require_once '../config/functions.php';
requireAdminLogin();

if (!isset($_GET['id'])) {
    header('Location: students.php');
    exit();
}

$student_id = (int)$_GET['id'];

// Получаем информацию о студенте
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    $_SESSION['error'] = 'Студент не найден';
    header('Location: students.php');
    exit();
}

// Получаем результаты студента - ИСПРАВЛЕНО: results -> test_results
$stmt = $pdo->prepare("
    SELECT r.*, t.title as test_title, t.description as test_description 
    FROM test_results r 
    JOIN tests t ON r.test_id = t.id 
    WHERE r.student_id = ? 
    ORDER BY r.completed_at DESC
");
$stmt->execute([$student_id]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Подсчет статистики
$totalScore = 0;
$totalMaxScore = 0;
$passed_count = 0;
$failed_count = 0;

foreach ($results as $result) {
    $totalScore += $result['score'];
    $totalMaxScore += $result['total_questions'];
    
    if ($result['is_passed'] == 1) {
        $passed_count++;
    } else {
        $failed_count++;
    }
}

$averagePercent = $totalMaxScore > 0 ? round(($totalScore / $totalMaxScore) * 100, 1) : 0;
$total_tests = count($results);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Результаты студента</title>
    <link rel="icon" href="/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
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
        .student-info {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .result-card {
            border-radius: 10px;
            margin-bottom: 15px;
            transition: transform 0.3s;
            border-left: 4px solid transparent;
        }
        .result-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .result-card.passed {
            border-left-color: #28a745;
        }
        .result-card.failed {
            border-left-color: #dc3545;
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
        .progress {
            height: 10px;
        }
        .badge-grade {
            font-size: 0.9rem;
            padding: 5px 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Заголовок -->
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-bar-chart"></i> Результаты студента</h1>
                    <p class="mb-0">Администратор: <?php echo $_SESSION['admin_username']; ?></p>
                </div>
                <div>
                    <a href="students.php" class="btn btn-light">
                        <i class="bi bi-arrow-left"></i> Назад к списку
                    </a>
                </div>
            </div>
        </div>

        <!-- Информация о студенте -->
        <div class="student-info">
            <div class="row">
                <div class="col-md-8">
                    <h3><?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name']); ?></h3>
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <p class="mb-1"><strong>Курс:</strong> <span class="badge bg-primary"><?php echo $student['course_number']; ?> курс</span></p>
                        </div>
                        <div class="col-md-4">
                            <p class="mb-1"><strong>Город:</strong> 
                                <?php if (!empty($student['city'])): ?>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($student['city']); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Не указан</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-4">
                            <p class="mb-1"><strong>Специальность:</strong> 
                                <?php if (!empty($student['specialty'])): ?>
                                    <span class="badge bg-purple"><?php echo htmlspecialchars($student['specialty']); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Не указана</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <p class="mb-0"><strong>Логин:</strong> <code><?php echo htmlspecialchars($student['username']); ?></code></p>
                    <p class="mb-0"><strong>Дата регистрации:</strong> <?php echo date('d.m.Y H:i', strtotime($student['created_at'])); ?></p>
                </div>
                <div class="col-md-4 text-end">
                    <h2 class="display-4"><?php echo $total_tests; ?></h2>
                    <p class="text-muted">всего тестов</p>
                    <?php if ($total_tests > 0): ?>
                        <div class="mt-2">
                            <span class="badge bg-success">Сдано: <?php echo $passed_count; ?></span>
                            <span class="badge bg-danger">Не сдано: <?php echo $failed_count; ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Статистика -->
        <?php if (!empty($results)): ?>
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stats-card text-center">
                        <div class="card-body">
                            <h6 class="text-muted">Средний результат</h6>
                            <h2><?php echo $averagePercent; ?>%</h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card text-center">
                        <div class="card-body">
                            <h6 class="text-muted">Всего баллов</h6>
                            <h2><?php echo $totalScore; ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card text-center">
                        <div class="card-body">
                            <h6 class="text-muted">Всего вопросов</h6>
                            <h2><?php echo $totalMaxScore; ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card text-center">
                        <div class="card-body">
                            <h6 class="text-muted">Процент сдач</h6>
                            <h2><?php echo $total_tests > 0 ? round(($passed_count / $total_tests) * 100, 1) : 0; ?>%</h2>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Результаты тестов -->
        <h3 class="mb-3"><i class="bi bi-list-check"></i> Пройденные тесты</h3>
        
        <?php if (empty($results)): ?>
            <div class="alert alert-info text-center py-5">
                <i class="bi bi-info-circle display-4 d-block mb-3"></i>
                <h4>Нет результатов тестов</h4>
                <p class="mb-0">Студент еще не проходил ни одного теста.</p>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($results as $result): ?>
                    <?php
                    $percent = $result['total_questions'] > 0 ? round(($result['score'] / $result['total_questions']) * 100, 1) : 0;
                    
                    // Определяем класс для карточки
                    $status_class = $result['is_passed'] == 1 ? 'passed' : 'failed';
                    
                    // Определяем цвет прогресс-бара
                    if ($percent >= 80) {
                        $progress_class = 'bg-success';
                    } elseif ($percent >= 60) {
                        $progress_class = 'bg-primary';
                    } elseif ($percent >= 40) {
                        $progress_class = 'bg-warning';
                    } else {
                        $progress_class = 'bg-danger';
                    }
                    
                    // Определяем класс для оценки
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
                    <div class="col-md-6 mb-4">
                        <div class="card result-card <?php echo $status_class; ?>">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0"><?php echo htmlspecialchars($result['test_title']); ?></h5>
                                <span class="badge <?php echo $grade_class; ?>"><?php echo !empty($grade) ? $grade : 'Не оценено'; ?></span>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($result['test_description'])): ?>
                                    <p class="card-text text-muted small"><?php echo htmlspecialchars($result['test_description']); ?></p>
                                <?php endif; ?>
                                
                                <div class="row align-items-center mb-3">
                                    <div class="col-8">
                                        <div class="progress">
                                            <div class="progress-bar <?php echo $progress_class; ?>" 
                                                 role="progressbar" 
                                                 style="width: <?php echo $percent; ?>%"
                                                 aria-valuenow="<?php echo $percent; ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <span class="badge bg-secondary"><?php echo $result['score']; ?>/<?php echo $result['total_questions']; ?></span>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        <i class="bi bi-calendar"></i> <?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?>
                                    </small>
                                    <span class="badge <?php echo $result['is_passed'] == 1 ? 'bg-success' : 'bg-danger'; ?>">
                                        <i class="bi bi-<?php echo $result['is_passed'] == 1 ? 'check-circle' : 'x-circle'; ?>"></i>
                                        <?php echo $result['is_passed'] == 1 ? 'Сдано' : 'Не сдано'; ?>
                                    </span>
                                </div>
                                
                                <?php if (isset($result['time_spent']) && $result['time_spent'] > 0): ?>
                                    <small class="text-muted d-block mt-2">
                                        <i class="bi bi-clock"></i> Время: <?php echo floor($result['time_spent'] / 60); ?> мин <?php echo $result['time_spent'] % 60; ?> сек
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Детальная статистика -->
            <div class="card mt-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-graph-up"></i> Детальная статистика</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 text-center">
                            <div class="display-6"><?php echo $total_tests; ?></div>
                            <p class="text-muted">Всего тестов</p>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="display-6"><?php echo $totalScore; ?></div>
                            <p class="text-muted">Всего баллов</p>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="display-6"><?php echo $averagePercent; ?>%</div>
                            <p class="text-muted">Средний результат</p>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="display-6">
                                <?php
                                if (!empty($results)) {
                                    $lastResult = $results[0];
                                    $lastPercent = $lastResult['total_questions'] > 0 ? round(($lastResult['score'] / $lastResult['total_questions']) * 100, 1) : 0;
                                    echo $lastPercent;
                                } else {
                                    echo '0';
                                }
                                ?>%
                            </div>
                            <p class="text-muted">Последний тест</p>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Распределение по оценкам</h6>
                            <?php
                            $grade_counts = [
                                '5' => 0, '4' => 0, '3' => 0, '2' => 0,
                                'Зачет' => 0, 'Незачет' => 0
                            ];
                            
                            foreach ($results as $result) {
                                $grade = $result['grade'] ?? '';
                                if (strpos($grade, '5') !== false) $grade_counts['5']++;
                                elseif (strpos($grade, '4') !== false) $grade_counts['4']++;
                                elseif (strpos($grade, '3') !== false) $grade_counts['3']++;
                                elseif (strpos($grade, '2') !== false) $grade_counts['2']++;
                                
                                if (strpos($grade, 'Зачет') !== false) $grade_counts['Зачет']++;
                                elseif (strpos($grade, 'Незачет') !== false) $grade_counts['Незачет']++;
                            }
                            ?>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if ($grade_counts['5'] > 0): ?>
                                    <span class="badge bg-success">Отлично (5): <?php echo $grade_counts['5']; ?></span>
                                <?php endif; ?>
                                <?php if ($grade_counts['4'] > 0): ?>
                                    <span class="badge bg-primary">Хорошо (4): <?php echo $grade_counts['4']; ?></span>
                                <?php endif; ?>
                                <?php if ($grade_counts['3'] > 0): ?>
                                    <span class="badge bg-info">Удовл. (3): <?php echo $grade_counts['3']; ?></span>
                                <?php endif; ?>
                                <?php if ($grade_counts['2'] > 0): ?>
                                    <span class="badge bg-danger">Неуд. (2): <?php echo $grade_counts['2']; ?></span>
                                <?php endif; ?>
                                <?php if ($grade_counts['Зачет'] > 0): ?>
                                    <span class="badge bg-success">Зачет: <?php echo $grade_counts['Зачет']; ?></span>
                                <?php endif; ?>
                                <?php if ($grade_counts['Незачет'] > 0): ?>
                                    <span class="badge bg-danger">Незачет: <?php echo $grade_counts['Незачет']; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6>Успеваемость</h6>
                            <div class="progress" style="height: 25px;">
                                <?php
                                $passed_percent = $total_tests > 0 ? round(($passed_count / $total_tests) * 100) : 0;
                                $failed_percent = $total_tests > 0 ? round(($failed_count / $total_tests) * 100) : 0;
                                ?>
                                <div class="progress-bar bg-success" style="width: <?php echo $passed_percent; ?>%">
                                    Сдано <?php echo $passed_percent; ?>%
                                </div>
                                <div class="progress-bar bg-danger" style="width: <?php echo $failed_percent; ?>%">
                                    Не сдано <?php echo $failed_percent; ?>%
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Кнопка назад -->
        <div class="mt-4 text-center">
            <a href="students.php" class="btn btn-secondary btn-lg">
                <i class="bi bi-arrow-left"></i> Вернуться к списку студентов
            </a>
        </div>
    </div>

    <style>
        .bg-purple {
            background: linear-gradient(45deg, #6f42c1, #9b6bff);
            color: white;
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>