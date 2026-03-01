<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';

// Проверка авторизации преподавателя
if (!isset($_SESSION['teacher'])) {
    header('Location: login.php');
    exit();
}

$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($student_id <= 0) {
    header('Location: students.php');
    exit();
}

// Получаем информацию о студенте
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    header('Location: students.php');
    exit();
}

// Получаем результаты студента - ИСПРАВЛЕНО: results -> test_results
$stmt = $pdo->prepare("
    SELECT r.*, t.title as test_title 
    FROM test_results r 
    JOIN tests t ON r.test_id = t.id 
    WHERE r.student_id = ? 
    ORDER BY r.completed_at DESC
");
$stmt->execute([$student_id]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Статистика
$total_tests = count($results);
$passed_count = 0;
$failed_count = 0;
$total_score = 0;
$total_questions = 0;

foreach ($results as $result) {
    if ($result['is_passed'] == 1) {
        $passed_count++;
    } else {
        $failed_count++;
    }
    $total_score += $result['score'] ?? 0;
    $total_questions += $result['total_questions'] ?? 1;
}

$average_score = $total_questions > 0 ? round(($total_score / $total_questions) * 100, 1) : 0;
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
            background: linear-gradient(45deg, #0dcaf0, #17a2b8);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .student-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .progress {
            height: 20px;
        }
        .result-card {
            border-left: 4px solid #0dcaf0;
            margin-bottom: 15px;
        }
        .passed {
            border-left-color: #198754;
        }
        .failed {
            border-left-color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Заголовок -->
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-person-check"></i> Результаты студента</h1>
                    <p class="mb-0">Преподаватель: <?php echo $_SESSION['teacher']['last_name'] . ' ' . $_SESSION['teacher']['first_name']; ?></p>
                </div>
                <div>
                    <a href="students.php" class="btn btn-light">
                        <i class="bi bi-arrow-left"></i> Назад к студентам
                    </a>
                </div>
            </div>
        </div>

        <!-- Информация о студенте -->
        <div class="student-card">
            <h4><?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name']); ?></h4>
            <div class="row">
                <div class="col-md-4">
                    <p><strong>Курс:</strong> <span class="badge bg-primary"><?php echo $student['course_number']; ?> курс</span></p>
                </div>
                <div class="col-md-4">
                    <p><strong>Специальность:</strong> <span class="badge bg-secondary"><?php echo htmlspecialchars($student['specialty']); ?></span></p>
                </div>
                <div class="col-md-4">
                    <p><strong>Логин:</strong> <code><?php echo htmlspecialchars($student['username']); ?></code></p>
                </div>
            </div>
        </div>

        <!-- Статистика -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h6>Всего тестов</h6>
                        <h3><?php echo $total_tests; ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h6 class="text-success">Сдано</h6>
                        <h3 class="text-success"><?php echo $passed_count; ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h6 class="text-danger">Не сдано</h6>
                        <h3 class="text-danger"><?php echo $failed_count; ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h6>Средний балл</h6>
                        <h3><?php echo $average_score; ?>%</h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Результаты тестов -->
        <h3 class="mb-3">Результаты тестов</h3>
        
        <?php if (empty($results)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                Студент еще не проходил тесты.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Тест</th>
                            <th>Дата прохождения</th>
                            <th>Результат</th>
                            <th>Оценка</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                            <tr class="result-card <?php echo $result['is_passed'] == 1 ? 'passed' : 'failed'; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($result['test_title']); ?></strong>
                                </td>
                                <td>
                                    <?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="progress flex-grow-1 me-2" style="min-width: 100px;">
                                            <?php
                                            $total_questions = $result['total_questions'] ?? 1;
                                            $percentage = ($total_questions > 0) ? ($result['score'] / $total_questions) * 100 : 0;
                                            $color = $percentage >= 80 ? 'bg-success' : 
                                                     ($percentage >= 60 ? 'bg-primary' : 
                                                     ($percentage >= 40 ? 'bg-warning' : 'bg-danger'));
                                            ?>
                                            <div class="progress-bar <?php echo $color; ?>" 
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
                                    <span class="badge <?php echo $grade_class; ?>">
                                        <?php echo !empty($grade) ? $grade : 'Не оценено'; ?>
                                    </span>
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
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <!-- Кнопка назад -->
        <div class="mt-4 text-center">
            <a href="students.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Вернуться к списку студентов
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>