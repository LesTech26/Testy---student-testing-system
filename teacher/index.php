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

// Получаем статистику с учетом города преподавателя
$testsCount = $pdo->prepare("SELECT COUNT(*) as count FROM tests WHERE city IS NULL OR city = ?");
$testsCount->execute([$teacher_city]);
$testsCount = $testsCount->fetch()['count'];

$studentsCount = $pdo->prepare("SELECT COUNT(*) as count FROM students WHERE city IS NULL OR city = ?");
$studentsCount->execute([$teacher_city]);
$studentsCount = $studentsCount->fetch()['count'];

$questionsCount = $pdo->prepare("SELECT COUNT(*) as count FROM questions q
                               JOIN tests t ON q.test_id = t.id
                               WHERE t.city IS NULL OR t.city = ?");
$questionsCount->execute([$teacher_city]);
$questionsCount = $questionsCount->fetch()['count'];

$resultsCount = $pdo->prepare("SELECT COUNT(*) as count FROM results r
                             JOIN tests t ON r.test_id = t.id
                             WHERE t.city IS NULL OR t.city = ?");
$resultsCount->execute([$teacher_city]);
$resultsCount = $resultsCount->fetch()['count'];

// Получаем последние активности
$recentResults = $pdo->prepare("SELECT r.*, s.last_name, s.first_name, t.title as test_title
                               FROM results r
                               JOIN students s ON r.student_id = s.id
                               JOIN tests t ON r.test_id = t.id
                               WHERE (t.city IS NULL OR t.city = ?)
                               ORDER BY r.completed_at DESC
                               LIMIT 5");
$recentResults->execute([$teacher_city]);
$recentResults = $recentResults->fetchAll();

// Получаем распределение по городам
$cityStats = $pdo->prepare("SELECT city, COUNT(*) as count 
                          FROM students 
                          WHERE city IS NOT NULL 
                          AND (city = ? OR ? IS NULL)
                          GROUP BY city 
                          ORDER BY count DESC");
$cityStats->execute([$teacher_city, $teacher_city]);
$cityStats = $cityStats->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Преподавательская панель - Главная</title>
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
            background: linear-gradient(45deg, #0dcaf0, #17a2b8);
            color: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .stat-card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }
        .quick-action-btn {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 10px;
            display: block;
            text-decoration: none;
            transition: all 0.3s;
        }
        .quick-action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .teacher-info {
            background: #d1ecf1;
            border-left: 4px solid #0dcaf0;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .city-badge {
            background: linear-gradient(45deg, #20c997, #17a2b8);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-left: 5px;
        }
        .recent-activity {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }
        .city-distribution {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }
        .progress {
            height: 10px;
        }
        .badge-status {
            font-size: 0.8em;
            padding: 3px 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Заголовок -->
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-person-badge"></i> Преподавательская панель</h1>
                    <p class="mb-0">Добро пожаловать, <?php echo $_SESSION['teacher']['last_name'] . ' ' . $_SESSION['teacher']['first_name']; ?>!
                    <?php if ($teacher_city): ?>
                        <span class="city-badge">Город: <?php echo htmlspecialchars($teacher_city); ?></span>
                    <?php endif; ?>
                    </p>
                </div>
                <div>
                    <a href="../index.php" class="btn btn-light me-2" target="_blank">
                        <i class="bi bi-eye"></i> Посмотреть сайт
                    </a>
                    <a href="logout.php" class="btn btn-outline-light">
                        <i class="bi bi-box-arrow-right"></i> Выйти
                    </a>
                </div>
            </div>
        </div>

        <!-- Информация о преподавателе -->
        <div class="teacher-info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h5><i class="bi bi-info-circle"></i> Информация о преподавателе</h5>
                    <p class="mb-1"><strong><?php echo $_SESSION['teacher']['last_name'] . ' ' . $_SESSION['teacher']['first_name']; ?></strong></p>
                    <?php if (!empty($_SESSION['teacher']['city'])): ?>
                        <p class="mb-1">Город: <?php echo htmlspecialchars($_SESSION['teacher']['city']); ?></p>
                    <?php else: ?>
                        <p class="mb-1">Город: Все города (нет привязки)</p>
                    <?php endif; ?>
                    <p class="mb-0">Логин: <?php echo htmlspecialchars($_SESSION['teacher']['username']); ?></p>
                </div>
                <div>
                    <span class="badge bg-info">ID: <?php echo $_SESSION['teacher']['id']; ?></span>
                </div>
            </div>
            <?php if ($teacher_city): ?>
                <div class="alert alert-warning mt-2 mb-0 p-2" style="font-size: 0.9rem;">
                    <i class="bi bi-info-circle"></i> 
                    Вы можете просматривать только студентов и тесты из города <strong><?php echo htmlspecialchars($teacher_city); ?></strong> и общие (без привязки к городу).
                </div>
            <?php else: ?>
                <div class="alert alert-info mt-2 mb-0 p-2" style="font-size: 0.9rem;">
                    <i class="bi bi-info-circle"></i> 
                    У вас нет привязки к городу. Вы можете просматривать всех студентов и все тесты.
                </div>
            <?php endif; ?>
        </div>

        <!-- Статистика -->
        <h2 class="mb-4">📊 Статистика системы</h2>
        <div class="row mb-4">
            <div class="col-md-3 mb-4">
                <div class="stat-card bg-primary text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-journal-text"></i>
                    </div>
                    <h3><?php echo $testsCount; ?></h3>
                    <h5>Тестов</h5>
                    <?php if ($teacher_city): ?>
                        <small>в городе <?php echo htmlspecialchars($teacher_city); ?></small>
                    <?php else: ?>
                        <small>всего в системе</small>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="stat-card bg-success text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-people"></i>
                    </div>
                    <h3><?php echo $studentsCount; ?></h3>
                    <h5>Студентов</h5>
                    <?php if ($teacher_city): ?>
                        <small>в городе <?php echo htmlspecialchars($teacher_city); ?></small>
                    <?php else: ?>
                        <small>всего в системе</small>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="stat-card bg-warning text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-question-circle"></i>
                    </div>
                    <h3><?php echo $questionsCount; ?></h3>
                    <h5>Вопросов</h5>
                    <?php if ($teacher_city): ?>
                        <small>в ваших тестах</small>
                    <?php else: ?>
                        <small>во всех тестах</small>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="stat-card bg-info text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-clipboard-data"></i>
                    </div>
                    <h3><?php echo $resultsCount; ?></h3>
                    <h5>Результатов</h5>
                    <?php if ($teacher_city): ?>
                        <small>по вашим тестам</small>
                    <?php else: ?>
                        <small>по всем тестам</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Распределение по городам -->
        <?php if (!empty($cityStats)): ?>
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="city-distribution">
                        <h5><i class="bi bi-map"></i> Распределение студентов по городам</h5>
                        <?php foreach ($cityStats as $cityStat): ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span><?php echo htmlspecialchars($cityStat['city']); ?></span>
                                    <span class="badge bg-secondary"><?php echo $cityStat['count']; ?></span>
                                </div>
                                <?php 
                                $percentage = ($studentsCount > 0) ? round(($cityStat['count'] / $studentsCount) * 100, 1) : 0;
                                ?>
                                <div class="progress">
                                    <div class="progress-bar bg-info" role="progressbar" 
                                         style="width: <?php echo $percentage; ?>%"
                                         aria-valuenow="<?php echo $percentage; ?>" 
                                         aria-valuemin="0" aria-valuemax="100">
                                    </div>
                                </div>
                                <small class="text-muted"><?php echo $percentage; ?>%</small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Последние активности -->
                <div class="col-md-6">
                    <div class="recent-activity">
                        <h5><i class="bi bi-clock-history"></i> Последние активности</h5>
                        <?php if (empty($recentResults)): ?>
                            <div class="alert alert-info text-center p-3">
                                <i class="bi bi-info-circle"></i>
                                <p class="mb-0">Пока нет результатов тестирования</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentResults as $result): ?>
                                <div class="border-bottom pb-2 mb-2">
                                    <div class="d-flex justify-content-between">
                                        <strong><?php echo escape($result['last_name'] . ' ' . $result['first_name']); ?></strong>
                                        <span class="badge badge-status 
                                            <?php echo $result['is_passed'] == 1 ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo $result['is_passed'] == 1 ? 'Сдал' : 'Не сдал'; ?>
                                        </span>
                                    </div>
                                    <small class="text-muted"><?php echo escape($result['test_title']); ?></small>
                                    <div class="d-flex justify-content-between mt-1">
                                        <span class="badge bg-secondary">
                                            <?php echo $result['score']; ?>/<?php echo $result['total_questions'] ?? 1; ?>
                                        </span>
                                        <small><?php echo date('d.m.Y H:i', strtotime($result['completed_at'])); ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="text-center mt-3">
                                <a href="results.php" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-arrow-right"></i> Все результаты
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Быстрые действия -->
        <h2 class="mb-4">⚡ Быстрые действия</h2>
        <div class="row mb-5">
            <div class="col-md-3 mb-3">
                <a href="tests.php?action=create" class="quick-action-btn bg-primary text-white text-center p-4">
                    <i class="bi bi-plus-circle fs-1 mb-3"></i>
                    <h5>Создать тест</h5>
                    <p class="mb-0">Добавить новый тест</p>
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="questions.php" class="quick-action-btn bg-success text-white text-center p-4">
                    <i class="bi bi-question-circle fs-1 mb-3"></i>
                    <h5>Добавить вопросы</h5>
                    <p class="mb-0">Создать вопросы для тестов</p>
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="students.php" class="quick-action-btn bg-warning text-white text-center p-4">
                    <i class="bi bi-people fs-1 mb-3"></i>
                    <h5>Студенты</h5>
                    <p class="mb-0">Просмотр студентов</p>
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="results.php" class="quick-action-btn bg-info text-white text-center p-4">
                    <i class="bi bi-bar-chart fs-1 mb-3"></i>
                    <h5>Результаты</h5>
                    <p class="mb-0">Анализ результатов</p>
                </a>
            </div>
        </div>

        <!-- Навигация -->
        <h2 class="mb-4">🧭 Навигация</h2>
        <div class="row">
            <div class="col-md-3 mb-3">
                <a href="tests.php" class="btn btn-outline-primary w-100 p-3">
                    <i class="bi bi-journal-text fs-4 mb-2 d-block"></i>
                    Управление тестами
                    <?php if ($testsCount > 0): ?>
                        <span class="badge bg-primary mt-1"><?php echo $testsCount; ?></span>
                    <?php endif; ?>
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="questions.php" class="btn btn-outline-success w-100 p-3">
                    <i class="bi bi-question-circle fs-4 mb-2 d-block"></i>
                    Управление вопросами
                    <?php if ($questionsCount > 0): ?>
                        <span class="badge bg-success mt-1"><?php echo $questionsCount; ?></span>
                    <?php endif; ?>
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="students.php" class="btn btn-outline-warning w-100 p-3">
                    <i class="bi bi-people fs-4 mb-2 d-block"></i>
                    Управление студентами
                    <?php if ($studentsCount > 0): ?>
                        <span class="badge bg-warning mt-1"><?php echo $studentsCount; ?></span>
                    <?php endif; ?>
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="results.php" class="btn btn-outline-info w-100 p-3">
                    <i class="bi bi-bar-chart fs-4 mb-2 d-block"></i>
                    Результаты тестов
                    <?php if ($resultsCount > 0): ?>
                        <span class="badge bg-info mt-1"><?php echo $resultsCount; ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>

        <!-- Информация -->
        <div class="mt-5 pt-4 border-top text-center text-muted">
            <p>
                <i class="bi bi-info-circle"></i> 
                Тести © <?php echo date('Y'); ?> | 
                Версия 1.0 | Преподаватель: <?php echo $_SESSION['teacher']['last_name'] . ' ' . $_SESSION['teacher']['first_name']; ?>
                <?php if ($teacher_city): ?>
                    | Город: <?php echo htmlspecialchars($teacher_city); ?>
                <?php endif; ?>
            </p>
            <?php if ($teacher_city): ?>
                <small class="text-muted">
                    <i class="bi bi-geo-alt"></i> 
                    Доступ ограничен: студенты и тесты из города <?php echo htmlspecialchars($teacher_city); ?> и общие.
                </small>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
