<?php
// Включаем отображение ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Стартуем сессию если еще не стартовала
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Подключаем конфигурацию базы данных
$config_path = '../config/database.php';
if (!file_exists($config_path)) {
    die("Ошибка: Файл конфигурации базы данных не найден по пути: $config_path");
}
require_once $config_path;

// Подключаем функции
$functions_path = '../config/functions.php';
if (file_exists($functions_path)) {
    require_once $functions_path;
}

// Проверяем авторизацию администратора
if (!isset($_SESSION['admin_id'])) {
    $_SESSION['error'] = 'Для доступа к панели администратора необходимо авторизоваться';
    header('Location: login.php');
    exit();
}

// Проверяем, что пользователь не супер-администратор (городской администратор)
if (isset($_SESSION['admin_type']) && $_SESSION['admin_type'] === 'super') {
    // Если это супер-админ, перенаправляем на его страницу
    header('Location: index.php');
    exit();
}

// Получаем город администратора
$admin_city = $_SESSION['admin_city'] ?? '';
if (empty($admin_city)) {
    $_SESSION['error'] = 'Для городского администратора не указан город. Обратитесь к супер-администратору.';
    header('Location: logout.php');
    exit();
}

// Получаем статистику только для своего города
try {
    // Тесты в городе администратора
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tests WHERE city = ? OR city IS NULL");
    $stmt->execute([$admin_city]);
    $testsCount = $stmt->fetch()['count'] ?? 0;
    
    // Студенты из города администратора
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM students WHERE city = ?");
    $stmt->execute([$admin_city]);
    $studentsCount = $stmt->fetch()['count'] ?? 0;
    
    // Результаты тестов студентов из города администратора
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM results r 
        JOIN students s ON r.student_id = s.id 
        WHERE s.city = ?
    ");
    $stmt->execute([$admin_city]);
    $resultsCount = $stmt->fetch()['count'] ?? 0;
    
    // Общее количество вопросов (доступно всем)
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM questions");
    $questionsCount = $stmt->fetch()['count'] ?? 0;
    
    // Преподаватели из города администратора
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM teachers WHERE is_active = 1 AND city = ?");
    $stmt->execute([$admin_city]);
    $teachersCount = $stmt->fetch()['count'] ?? 0;
    
    // Количество специальностей (только просмотр, без создания)
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM specialties");
    $specialtiesCount = $stmt->fetch()['count'] ?? 0;
    
} catch (Exception $e) {
    // Если есть ошибки, устанавливаем значения по умолчанию
    $testsCount = 0;
    $studentsCount = 0;
    $resultsCount = 0;
    $questionsCount = 0;
    $specialtiesCount = 0;
    $teachersCount = 0;
    
    // Для отладки
    error_log("Ошибка при получении статистики: " . $e->getMessage());
}

// Получаем список специальностей для формы
$specialties = [];
try {
    $stmt = $pdo->query("SELECT * FROM specialties ORDER BY name");
    $specialties = $stmt->fetchAll();
} catch (Exception $e) {
    $specialties = [];
    error_log("Ошибка при получении специальностей: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель - Городской администратор</title>
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
            background: linear-gradient(45deg, #2c3e50, #17a2b8);
            color: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .city-badge {
            background-color: #ffc107;
            color: #212529;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 1rem;
            font-weight: bold;
            display: inline-block;
        }

        .stat-card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
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
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .student-created-info {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        .teacher-created-info {
            background: #d1ecf1;
            border-left: 4px solid #0dcaf0;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        .student-credentials,
        .teacher-credentials {
            background: white;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #c3e6cb;
            margin-top: 10px;
        }

        .teacher-credentials {
            border-color: #b6d4fe;
        }

        .copy-btn {
            cursor: pointer;
            transition: all 0.3s;
        }

        .copy-btn:hover {
            transform: scale(1.1);
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
        }

        .teacher-card {
            background: linear-gradient(45deg, #0dcaf0, #17a2b8);
        }
        
        .bg-teal {
            background-color: #20c997 !important;
        }
        
        .btn-outline-teal {
            color: #20c997;
            border-color: #20c997;
        }
        
        .btn-outline-teal:hover {
            color: #fff;
            background-color: #20c997;
            border-color: #20c997;
        }

        .restricted-area {
            opacity: 0.6;
            pointer-events: none;
            position: relative;
        }

        .restricted-area::after {
            content: "Только для супер-администратора";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.8rem;
            white-space: nowrap;
        }

        .info-message {
            background: #e7f3ff;
            border-left: 4px solid #17a2b8;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Заголовок -->
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-building"></i> Админ-панель (Городской администратор)</h1>
                    <p class="mb-0">
                        Добро пожаловать, <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Администратор'); ?>!
                        <span class="city-badge ms-3">
                            <i class="bi bi-geo-alt-fill"></i> <?php echo htmlspecialchars($admin_city); ?>
                        </span>
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

        <!-- Информационное сообщение о городе -->
        <div class="info-message">
            <i class="bi bi-info-circle-fill text-info me-2"></i>
            <strong>Вы управляете данными города <?php echo htmlspecialchars($admin_city); ?>.</strong>
            Вы можете создавать и редактировать студентов, преподавателей и тесты только для этого города.
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

        <!-- Информация о созданном студенте -->
        <?php if (isset($_SESSION['student_created'])):
            $student = $_SESSION['student_created'];
            ?>
            <div class="student-created-info">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5><i class="bi bi-check-circle-fill text-success"></i> Студент успешно создан!</h5>
                        <p class="mb-1">
                            <strong><?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name']); ?></strong>
                        </p>
                        <p class="mb-1">Курс: <?php echo $student['course_number']; ?></p>
                        <p class="mb-0">Специальность: <?php echo htmlspecialchars($student['specialty']); ?></p>
                        <p class="mb-0">Город: <?php echo htmlspecialchars($student['city']); ?></p>
                        <p class="mb-0">ID: <?php echo $student['id']; ?></p>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-success copy-all-btn">
                            <i class="bi bi-clipboard"></i> Копировать все
                        </button>
                    </div>
                </div>

                <div class="student-credentials mt-3">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-2">
                                <strong>Логин:</strong>
                                <div class="d-flex justify-content-between align-items-center">
                                    <code id="username-text"><?php echo htmlspecialchars($student['username']); ?></code>
                                    <i class="bi bi-clipboard copy-btn text-primary"
                                        data-text="<?php echo htmlspecialchars($student['username']); ?>"
                                        title="Копировать логин"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-2">
                                <strong>Пароль:</strong>
                                <div class="d-flex justify-content-between align-items-center">
                                    <code id="password-text"><?php echo htmlspecialchars($student['password']); ?></code>
                                    <i class="bi bi-clipboard copy-btn text-primary"
                                        data-text="<?php echo htmlspecialchars($student['password']); ?>"
                                        title="Копировать пароль"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-warning mt-2">
                        <small>
                            <i class="bi bi-exclamation-triangle"></i>
                            Сохраните эти данные! Пароль хранится в открытом виде и будет показан только один раз.
                        </small>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['student_created']); ?>
        <?php endif; ?>

        <!-- Информация о созданном преподавателе -->
        <?php if (isset($_SESSION['teacher_created'])):
            $teacher = $_SESSION['teacher_created'];
            ?>
            <div class="teacher-created-info">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5><i class="bi bi-check-circle-fill text-info"></i> Преподаватель успешно создан!</h5>
                        <p class="mb-1">
                            <strong><?php echo htmlspecialchars($teacher['last_name'] . ' ' . $teacher['first_name']); ?></strong>
                        </p>
                        <p class="mb-1">Город: <?php echo htmlspecialchars($teacher['city']); ?></p>
                        <p class="mb-0">ID: <?php echo $teacher['id']; ?></p>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-info copy-teacher-all-btn">
                            <i class="bi bi-clipboard"></i> Копировать все
                        </button>
                    </div>
                </div>

                <div class="teacher-credentials mt-3">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-2">
                                <strong>Логин:</strong>
                                <div class="d-flex justify-content-between align-items-center">
                                    <code
                                        id="teacher-username-text"><?php echo htmlspecialchars($teacher['username']); ?></code>
                                    <i class="bi bi-clipboard copy-teacher-btn text-primary"
                                        data-text="<?php echo htmlspecialchars($teacher['username']); ?>"
                                        title="Копировать логин"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-2">
                                <strong>Пароль:</strong>
                                <div class="d-flex justify-content-between align-items-center">
                                    <code
                                        id="teacher-password-text"><?php echo htmlspecialchars($teacher['password']); ?></code>
                                    <i class="bi bi-clipboard copy-teacher-btn text-primary"
                                        data-text="<?php echo htmlspecialchars($teacher['password']); ?>"
                                        title="Копировать пароль"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info mt-2">
                        <small>
                            <i class="bi bi-info-circle"></i>
                            Преподаватель может войти в систему по адресу:
                            <strong><?php echo $_SERVER['HTTP_HOST']; ?>/teacher/index.php</strong>
                        </small>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['teacher_created']); ?>
        <?php endif; ?>

        <!-- Статистика по городу -->
        <h2 class="mb-4">📊 Статистика по городу <?php echo htmlspecialchars($admin_city); ?></h2>
        <div class="row mb-5">
            <div class="col-md-4 mb-4">
                <div class="stat-card bg-primary text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-journal-text"></i>
                    </div>
                    <h3><?php echo $testsCount; ?></h3>
                    <h5>Тестов в городе</h5>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="stat-card bg-success text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-people"></i>
                    </div>
                    <h3><?php echo $studentsCount; ?></h3>
                    <h5>Студентов в городе</h5>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="stat-card bg-teal text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-person-badge"></i>
                    </div>
                    <h3><?php echo $teachersCount; ?></h3>
                    <h5>Преподавателей в городе</h5>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="stat-card bg-warning text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-question-circle"></i>
                    </div>
                    <h3><?php echo $questionsCount; ?></h3>
                    <h5>Всего вопросов</h5>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="stat-card bg-info text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-clipboard-data"></i>
                    </div>
                    <h3><?php echo $resultsCount; ?></h3>
                    <h5>Результатов</h5>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="stat-card bg-secondary text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-briefcase"></i>
                    </div>
                    <h3><?php echo $specialtiesCount; ?></h3>
                    <h5>Специальностей (просмотр)</h5>
                </div>
            </div>
        </div>

        <!-- Добавление студента -->
        <h2 class="mb-4">👨‍🎓 Добавить студента в город <?php echo htmlspecialchars($admin_city); ?></h2>
        <div class="row mb-5">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Создание учетной записи студента</h5>
                    </div>
                    <div class="card-body">
                        <form action="create_student.php" method="POST" id="createStudentForm">
                            <input type="hidden" name="city" value="<?php echo htmlspecialchars($admin_city); ?>">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label for="last_name" class="form-label">Фамилия:</label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" required
                                        maxlength="100" placeholder="Иванов">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="first_name" class="form-label">Имя:</label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" required
                                        maxlength="100" placeholder="Иван">
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label for="course_number" class="form-label">Курс:</label>
                                    <select class="form-select" id="course_number" name="course_number" required>
                                        <option value="" selected disabled>Выберите курс</option>
                                        <option value="1">1 курс</option>
                                        <option value="2">2 курс</option>
                                        <option value="3">3 курс</option>
                                        <option value="4">4 курс</option>
                                        <option value="5">5 курс</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="specialty" class="form-label">Специальность:</label>
                                    <select class="form-select" id="specialty" name="specialty" required>
                                        <option value="" selected disabled>Выберите специальность</option>
                                        <?php if (!empty($specialties)): ?>
                                            <?php foreach ($specialties as $specialty): ?>
                                                <option value="<?php echo htmlspecialchars($specialty['name']); ?>">
                                                    <?php echo htmlspecialchars($specialty['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="" disabled>Нет доступных специальностей</option>
                                        <?php endif; ?>
                                    </select>
                                    <?php if (empty($specialties)): ?>
                                        <div class="alert alert-warning mt-2">
                                            <small>
                                                <i class="bi bi-exclamation-triangle"></i>
                                                Специальности не найдены. Обратитесь к супер-администратору.
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="username" class="form-label">Логин:</label>
                                    <input type="text" class="form-control" id="username" name="username" required
                                        maxlength="50" placeholder="ivanov2024">
                                    <div class="form-text">
                                        Только латинские буквы, цифры и символ _
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="password" class="form-label">Пароль:</label>
                                    <div class="password-field">
                                        <input type="password" class="form-control" id="password" name="password"
                                            required minlength="6" maxlength="100" placeholder="Минимум 6 символов">
                                        <button type="button" class="password-toggle" id="togglePassword">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">
                                        Минимум 6 символов. Пароль хранится в открытом виде.
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12">
                                    <button type="button" class="btn btn-outline-secondary btn-sm mb-3"
                                        id="generatePasswordBtn">
                                        <i class="bi bi-shuffle"></i> Сгенерировать пароль
                                    </button>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-person-plus"></i> Создать студента
                                </button>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="autoGenerate"
                                        name="auto_generate">
                                    <label class="form-check-label" for="autoGenerate">
                                        Автогенерация логина
                                    </label>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Добавление преподавателя -->
        <h2 class="mb-4">👨‍🏫 Добавить преподавателя в город <?php echo htmlspecialchars($admin_city); ?></h2>
        <div class="row mb-5">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Создание учетной записи преподавателя</h5>
                    </div>
                    <div class="card-body">
                        <form action="create_teacher.php" method="POST" id="createTeacherForm">
                            <input type="hidden" name="city" value="<?php echo htmlspecialchars($admin_city); ?>">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label for="teacher_last_name" class="form-label">Фамилия:</label>
                                    <input type="text" class="form-control" id="teacher_last_name" name="last_name"
                                        required maxlength="100" placeholder="Иванов">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="teacher_first_name" class="form-label">Имя:</label>
                                    <input type="text" class="form-control" id="teacher_first_name" name="first_name"
                                        required maxlength="100" placeholder="Иван">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="teacher_username" class="form-label">Логин:</label>
                                    <input type="text" class="form-control" id="teacher_username" name="username"
                                        required maxlength="50" placeholder="ivanov_teacher">
                                    <div class="form-text">
                                        Только латинские буквы, цифры и символ _
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="teacher_password" class="form-label">Пароль:</label>
                                    <div class="password-field">
                                        <input type="password" class="form-control" id="teacher_password"
                                            name="password" required minlength="6" maxlength="100"
                                            placeholder="Минимум 6 символов">
                                        <button type="button" class="password-toggle" id="toggleTeacherPassword">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">
                                        Минимум 6 символов
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <button type="button" class="btn btn-outline-secondary"
                                        id="generateTeacherPasswordBtn">
                                        <i class="bi bi-shuffle"></i> Сгенерировать пароль
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-info">
                                <i class="bi bi-person-plus"></i> Создать преподавателя
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Быстрые действия -->
        <h2 class="mb-4">⚡ Быстрые действия</h2>
        <div class="row mb-5">
            <div class="col-md-3 mb-3">
                <a href="tests.php?action=create" class="quick-action-btn bg-primary text-white text-center p-4">
                    <i class="bi bi-plus-circle fs-1 mb-3"></i>
                    <h5>Создать тест</h5>
                    <p class="mb-0">Добавить новый тест для города</p>
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
                    <h5>Студенты города</h5>
                    <p class="mb-0">Управление студентами</p>
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="teachers.php" class="quick-action-btn teacher-card text-white text-center p-4">
                    <i class="bi bi-person-badge fs-1 mb-3"></i>
                    <h5>Преподаватели города</h5>
                    <p class="mb-0">Управление преподавателями</p>
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
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="questions.php" class="btn btn-outline-success w-100 p-3">
                    <i class="bi bi-question-circle fs-4 mb-2 d-block"></i>
                    Управление вопросами
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="students.php" class="btn btn-outline-warning w-100 p-3">
                    <i class="bi bi-people fs-4 mb-2 d-block"></i>
                    Управление студентами
                </a>
            </div>
            <div class="col-md-3 mb-3">
                <a href="teachers.php" class="btn btn-outline-info w-100 p-3">
                    <i class="bi bi-person-badge fs-4 mb-2 d-block"></i>
                    Управление преподавателями
                </a>
            </div>
        </div>

        <!-- Заблокированные разделы (только для супер-админа) -->
        <h2 class="mb-4 mt-5">🔒 Разделы только для супер-администратора</h2>
        <div class="row mb-5">
            <div class="col-md-4 mb-3">
                <div class="card restricted-area">
                    <div class="card-body text-center p-4">
                        <i class="bi bi-shield-check fs-1 mb-3 text-secondary"></i>
                        <h5>Управление администраторами</h5>
                        <p class="text-muted">Создание и редактирование администраторов</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card restricted-area">
                    <div class="card-body text-center p-4">
                        <i class="bi bi-briefcase fs-1 mb-3 text-secondary"></i>
                        <h5>Управление специальностями</h5>
                        <p class="text-muted">Создание и редактирование специальностей</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card restricted-area">
                    <div class="card-body text-center p-4">
                        <i class="bi bi-geo-alt fs-1 mb-3 text-secondary"></i>
                        <h5>Управление городами</h5>
                        <p class="text-muted">Настройка городов и их администраторов</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Дополнительная навигация -->
        <div class="row mt-3">
            <div class="col-md-6 mb-3">
                <a href="results.php" class="btn btn-outline-secondary w-100 p-3">
                    <i class="bi bi-bar-chart fs-4 mb-2 d-block"></i>
                    Результаты тестов (город <?php echo htmlspecialchars($admin_city); ?>)
                </a>
            </div>
            <div class="col-md-6 mb-3">
                <a href="logout.php" class="btn btn-outline-danger w-100 p-3">
                    <i class="bi bi-box-arrow-right fs-4 mb-2 d-block"></i>
                    Выйти из системы
                </a>
            </div>
        </div>

        <!-- Информация -->
        <div class="mt-5 pt-4 border-top text-center text-muted">
            <p>
                <i class="bi bi-info-circle"></i>
                Тести © <?php echo date('Y'); ?> |
                Версия 1.0 | Городской администратор: <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Администратор'); ?>
                <br>Город: <?php echo htmlspecialchars($admin_city); ?>
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Функция для копирования текста
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                console.log('Текст скопирован: ' + text);
            }).catch(err => {
                console.error('Ошибка при копировании: ', err);
                // Fallback для старых браузеров
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                } catch (err) {
                    console.error('Fallback ошибка: ', err);
                }
                document.body.removeChild(textArea);
            });
        }

        // Копирование логина и пароля студента
        document.querySelectorAll('.copy-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const text = this.getAttribute('data-text');
                copyToClipboard(text);

                // Визуальная обратная связь
                const originalHTML = this.innerHTML;
                this.innerHTML = '<i class="bi bi-check text-success"></i>';

                setTimeout(() => {
                    this.innerHTML = originalHTML;
                }, 2000);
            });
        });

        // Копирование всех данных студента
        document.querySelector('.copy-all-btn')?.addEventListener('click', function () {
            const username = document.getElementById('username-text').textContent;
            const password = document.getElementById('password-text').textContent;
            const text = `Логин: ${username}\nПароль: ${password}`;

            copyToClipboard(text);

            // Визуальная обратная связь
            const originalHTML = this.innerHTML;
            this.innerHTML = '<i class="bi bi-check"></i> Скопировано!';
            this.classList.remove('btn-outline-success');
            this.classList.add('btn-success');

            setTimeout(() => {
                this.innerHTML = originalHTML;
                this.classList.remove('btn-success');
                this.classList.add('btn-outline-success');
            }, 2000);
        });

        // Копирование данных преподавателя
        document.querySelectorAll('.copy-teacher-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const text = this.getAttribute('data-text');
                copyToClipboard(text);

                // Визуальная обратная связь
                const originalHTML = this.innerHTML;
                this.innerHTML = '<i class="bi bi-check text-success"></i>';

                setTimeout(() => {
                    this.innerHTML = originalHTML;
                }, 2000);
            });
        });

        // Копирование всех данных преподавателя
        document.querySelector('.copy-teacher-all-btn')?.addEventListener('click', function () {
            const username = document.getElementById('teacher-username-text').textContent;
            const password = document.getElementById('teacher-password-text').textContent;
            const text = `Логин: ${username}\nПароль: ${password}`;

            copyToClipboard(text);

            // Визуальная обратная связь
            const originalHTML = this.innerHTML;
            this.innerHTML = '<i class="bi bi-check"></i> Скопировано!';
            this.classList.remove('btn-outline-info');
            this.classList.add('btn-info');

            setTimeout(() => {
                this.innerHTML = originalHTML;
                this.classList.remove('btn-info');
                this.classList.add('btn-outline-info');
            }, 2000);
        });

        // Показать/скрыть пароль при создании студента
        document.getElementById('togglePassword').addEventListener('click', function () {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        });

        // Показать/скрыть пароль при создании преподавателя
        document.getElementById('toggleTeacherPassword').addEventListener('click', function () {
            const passwordInput = document.getElementById('teacher_password');
            const icon = this.querySelector('i');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        });

        // Генерация пароля для студента
        document.getElementById('generatePasswordBtn').addEventListener('click', function () {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            let password = '';
            const length = 10;

            for (let i = 0; i < length; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }

            const passwordInput = document.getElementById('password');
            passwordInput.value = password;
            passwordInput.type = 'text';

            // Обновляем иконку
            const toggleBtn = document.getElementById('togglePassword');
            const icon = toggleBtn.querySelector('i');
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');

            // Выделяем пароль
            passwordInput.focus();
            passwordInput.select();

            // Показываем сообщение
            alert('Сгенерирован новый пароль: ' + password);
        });

        // Генерация пароля для преподавателя
        document.getElementById('generateTeacherPasswordBtn').addEventListener('click', function () {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            let password = '';
            const length = 10;

            for (let i = 0; i < length; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }

            const passwordInput = document.getElementById('teacher_password');
            passwordInput.value = password;
            passwordInput.type = 'text';

            // Обновляем иконку
            const toggleBtn = document.getElementById('toggleTeacherPassword');
            const icon = toggleBtn.querySelector('i');
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');

            // Выделяем пароль
            passwordInput.focus();
            passwordInput.select();

            // Показываем сообщение
            alert('Сгенерирован новый пароль: ' + password);
        });

        // Автогенерация логина при вводе ФИО студента
        document.getElementById('autoGenerate').addEventListener('change', function () {
            if (this.checked) {
                const lastName = document.getElementById('last_name').value.toLowerCase();
                const firstName = document.getElementById('first_name').value.toLowerCase();

                if (lastName && firstName) {
                    // Создаем логин на основе ФИО и случайных цифр
                    const username = lastName + '_' + firstName.charAt(0) +
                        Math.floor(100 + Math.random() * 900);
                    document.getElementById('username').value = username;
                }
            }
        });

        // Блокировка ввода в поле логина студента при автогенерации
        document.getElementById('username').addEventListener('focus', function() {
            const autoGenerate = document.getElementById('autoGenerate');
            if (autoGenerate && autoGenerate.checked) {
                this.blur();
                alert('Логин генерируется автоматически. Снимите галочку "Автогенерация логина" для ручного ввода.');
            }
        });

        // Валидация логина студента (только латиница, цифры и _)
        document.getElementById('username').addEventListener('input', function () {
            const value = this.value;
            const regex = /^[a-zA-Z0-9_]*$/;

            if (!regex.test(value)) {
                this.value = value.replace(/[^a-zA-Z0-9_]/g, '');
            }
        });

        // Валидация логина преподавателя (только латиница, цифры и _)
        document.getElementById('teacher_username').addEventListener('input', function () {
            const value = this.value;
            const regex = /^[a-zA-Z0-9_]*$/;

            if (!regex.test(value)) {
                this.value = value.replace(/[^a-zA-Z0-9_]/g, '');
            }
        });

        // Валидация формы студента перед отправкой
        document.getElementById('createStudentForm').addEventListener('submit', function (e) {
            const lastName = document.getElementById('last_name').value.trim();
            const firstName = document.getElementById('first_name').value.trim();
            const course = document.getElementById('course_number').value;
            const specialty = document.getElementById('specialty').value;
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;

            let errors = [];

            if (!lastName) errors.push('Фамилия обязательна');
            if (!firstName) errors.push('Имя обязательно');
            if (!course) errors.push('Выберите курс');
            if (!specialty) errors.push('Выберите специальность');
            if (!username) errors.push('Логин обязателен');
            if (password.length < 6) errors.push('Пароль должен быть минимум 6 символов');

            if (!/^[a-zA-Z0-9_]+$/.test(username)) {
                errors.push('Логин может содержать только латинские буквы, цифры и символ _');
            }

            if (errors.length > 0) {
                e.preventDefault();
                alert('Ошибки в форме:\n' + errors.join('\n'));
                return false;
            }

            return true;
        });

        // Валидация формы преподавателя перед отправкой
        document.getElementById('createTeacherForm').addEventListener('submit', function (e) {
            const lastName = document.getElementById('teacher_last_name').value.trim();
            const firstName = document.getElementById('teacher_first_name').value.trim();
            const username = document.getElementById('teacher_username').value.trim();
            const password = document.getElementById('teacher_password').value;

            let errors = [];

            if (!lastName) errors.push('Фамилия обязательна');
            if (!firstName) errors.push('Имя обязательно');
            if (!username) errors.push('Логин обязателен');
            if (password.length < 6) errors.push('Пароль должен быть минимум 6 символов');

            if (!/^[a-zA-Z0-9_]+$/.test(username)) {
                errors.push('Логин может содержать только латинские буквы, цифры и символ _');
            }

            if (errors.length > 0) {
                e.preventDefault();
                alert('Ошибки в форме:\n' + errors.join('\n'));
                return false;
            }

            return true;
        });

        // Автоматическое скрытие пароля через 30 секунд
        setTimeout(function () {
            const passwordInput = document.getElementById('password');
            if (passwordInput && passwordInput.type === 'text') {
                if (confirm('Пароль все еще виден. Хотите скрыть его?')) {
                    passwordInput.type = 'password';
                    const toggleBtn = document.getElementById('togglePassword');
                    const icon = toggleBtn.querySelector('i');
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            }
        }, 30000);

        // Автоматическое скрытие пароля преподавателя через 30 секунд
        setTimeout(function () {
            const passwordInput = document.getElementById('teacher_password');
            if (passwordInput && passwordInput.type === 'text') {
                if (confirm('Пароль преподавателя все еще виден. Хотите скрыть его?')) {
                    passwordInput.type = 'password';
                    const toggleBtn = document.getElementById('toggleTeacherPassword');
                    const icon = toggleBtn.querySelector('i');
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            }
        }, 30000);
    </script>
</body>

</html>
