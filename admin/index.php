<?php
session_start();

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

// ЕСЛИ ЭТО ГОРОДСКОЙ АДМИНИСТРАТОР - ПЕРЕНАПРАВЛЯЕМ НА ЕГО СТРАНИЦУ
if (isset($_SESSION['admin_type']) && $_SESSION['admin_type'] === 'city') {
    header('Location: index_city.php');
    exit();
}

// Проверяем, является ли пользователь супер-администратором
if (!isset($_SESSION['admin_type']) || $_SESSION['admin_type'] !== 'super') {
    $_SESSION['error'] = 'У вас нет прав для доступа к этому разделу';
    header('Location: login.php');
    exit();
}

// Получаем статистику
try {
    $testsCount = $pdo->query("SELECT COUNT(*) as count FROM tests")->fetch()['count'] ?? 0;
    $studentsCount = $pdo->query("SELECT COUNT(*) as count FROM students")->fetch()['count'] ?? 0;
    $resultsCount = $pdo->query("SELECT COUNT(*) as count FROM results")->fetch()['count'] ?? 0;
    $questionsCount = $pdo->query("SELECT COUNT(*) as count FROM questions")->fetch()['count'] ?? 0;
    $specialtiesCount = $pdo->query("SELECT COUNT(*) as count FROM specialties")->fetch()['count'] ?? 0;
    $teachersCount = $pdo->query("SELECT COUNT(*) as count FROM teachers WHERE is_active = 1")->fetch()['count'] ?? 0;
    $adminsCount = $pdo->query("SELECT COUNT(*) as count FROM admins WHERE is_active = 1")->fetch()['count'] ?? 0;
} catch (Exception $e) {
    // Если есть ошибки, устанавливаем значения по умолчанию
    $testsCount = 0;
    $studentsCount = 0;
    $resultsCount = 0;
    $questionsCount = 0;
    $specialtiesCount = 0;
    $teachersCount = 0;
    $adminsCount = 0;
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель - Главная</title>
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
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
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

        .admin-created-info {
            background: #e7f3ff;
            border-left: 4px solid #0d6efd;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        .student-credentials,
        .teacher-credentials,
        .admin-credentials {
            background: white;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #c3e6cb;
            margin-top: 10px;
        }

        .teacher-credentials {
            border-color: #b6d4fe;
        }

        .admin-credentials {
            border-color: #0d6efd;
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

        .specialty-badge {
            background-color: #6f42c1;
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
        }

        .specialty-card {
            background: linear-gradient(45deg, #6f42c1, #9b6bff);
        }

        .teacher-card {
            background: linear-gradient(45deg, #0dcaf0, #17a2b8);
        }

        .admin-card {
            background: linear-gradient(45deg, #0d6efd, #0dcaf0);
        }
        
        .bg-purple {
            background-color: #6f42c1 !important;
        }
        
        .bg-teal {
            background-color: #20c997 !important;
        }
        
        .btn-outline-purple {
            color: #6f42c1;
            border-color: #6f42c1;
        }
        
        .btn-outline-purple:hover {
            color: #fff;
            background-color: #6f42c1;
            border-color: #6f42c1;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Заголовок -->
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-speedometer2"></i> Админ-панель (Супер-админ)</h1>
                    <p class="mb-0">Добро пожаловать, <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Администратор'); ?>!</p>
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
                        <?php if (!empty($teacher['city'])): ?>
                            <p class="mb-1">Город: <?php echo htmlspecialchars($teacher['city']); ?></p>
                        <?php endif; ?>
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

        <!-- Информация о созданном администраторе -->
        <?php if (isset($_SESSION['admin_created'])):
            $admin = $_SESSION['admin_created'];
            ?>
            <div class="admin-created-info">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5><i class="bi bi-check-circle-fill text-primary"></i> Администратор успешно создан!</h5>
                        <p class="mb-1">
                            <strong><?php echo htmlspecialchars($admin['last_name'] . ' ' . $admin['first_name']); ?></strong>
                        </p>
                        <p class="mb-1">Город: <?php echo htmlspecialchars($admin['city']); ?></p>
                        <p class="mb-0">Тип: Городской администратор</p>
                        <p class="mb-0">ID: <?php echo $admin['id']; ?></p>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-primary copy-admin-all-btn">
                            <i class="bi bi-clipboard"></i> Копировать все
                        </button>
                    </div>
                </div>

                <div class="admin-credentials mt-3">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-2">
                                <strong>Логин:</strong>
                                <div class="d-flex justify-content-between align-items-center">
                                    <code
                                        id="admin-username-text"><?php echo htmlspecialchars($admin['username']); ?></code>
                                    <i class="bi bi-clipboard copy-admin-btn text-primary"
                                        data-text="<?php echo htmlspecialchars($admin['username']); ?>"
                                        title="Копировать логин"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-2">
                                <strong>Пароль:</strong>
                                <div class="d-flex justify-content-between align-items-center">
                                    <code
                                        id="admin-password-text"><?php echo htmlspecialchars($admin['password']); ?></code>
                                    <i class="bi bi-clipboard copy-admin-btn text-primary"
                                        data-text="<?php echo htmlspecialchars($admin['password']); ?>"
                                        title="Копировать пароль"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-primary mt-2">
                        <small>
                            <i class="bi bi-info-circle"></i>
                            Администратор может войти в систему по адресу:
                            <strong><?php echo $_SERVER['HTTP_HOST']; ?>/admin/index.php</strong>
                        </small>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['admin_created']); ?>
        <?php endif; ?>

        <!-- Статистика -->
        <h2 class="mb-4">📊 Статистика системы</h2>
        <div class="row mb-5">
            <div class="col-md-3 mb-4">
                <div class="stat-card bg-primary text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-journal-text"></i>
                    </div>
                    <h3><?php echo $testsCount; ?></h3>
                    <h5>Тестов</h5>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="stat-card bg-success text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-people"></i>
                    </div>
                    <h3><?php echo $studentsCount; ?></h3>
                    <h5>Студентов</h5>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="stat-card bg-warning text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-question-circle"></i>
                    </div>
                    <h3><?php echo $questionsCount; ?></h3>
                    <h5>Вопросов</h5>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="stat-card bg-info text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-clipboard-data"></i>
                    </div>
                    <h3><?php echo $resultsCount; ?></h3>
                    <h5>Результатов</h5>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="stat-card bg-purple text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-briefcase"></i>
                    </div>
                    <h3><?php echo $specialtiesCount; ?></h3>
                    <h5>Специальностей</h5>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="stat-card bg-teal text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-person-badge"></i>
                    </div>
                    <h3><?php echo $teachersCount; ?></h3>
                    <h5>Преподавателей</h5>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="stat-card bg-dark text-white text-center p-4">
                    <div class="stat-icon">
                        <i class="bi bi-shield-check"></i>
                    </div>
                    <h3><?php echo $adminsCount; ?></h3>
                    <h5>Администраторов</h5>
                </div>
            </div>
        </div>

        <!-- Добавление администратора -->
        <h2 class="mb-4">👑 Добавить администратора</h2>
        <div class="row mb-5">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0">Создание учетной записи городского администратора</h5>
                    </div>
                    <div class="card-body">
                        <form action="create_admin.php" method="POST" id="createAdminForm">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label for="admin_last_name" class="form-label">Фамилия:</label>
                                    <input type="text" class="form-control" id="admin_last_name" name="last_name"
                                        required maxlength="100" placeholder="Иванов">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="admin_first_name" class="form-label">Имя:</label>
                                    <input type="text" class="form-control" id="admin_first_name" name="first_name"
                                        required maxlength="100" placeholder="Иван">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="admin_city" class="form-label">Город:</label>
                                    <input type="text" class="form-control" id="admin_city" name="city"
                                        required maxlength="100" placeholder="Москва">
                                    <div class="form-text">
                                        Администратор сможет управлять только данными своего города
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="admin_username" class="form-label">Логин:</label>
                                    <input type="text" class="form-control" id="admin_username" name="username"
                                        required maxlength="50" placeholder="ivanov_admin">
                                    <div class="form-text">
                                        Только латинские буквы, цифры и символ _
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="admin_password" class="form-label">Пароль:</label>
                                    <div class="password-field">
                                        <input type="password" class="form-control" id="admin_password"
                                            name="password" required minlength="6" maxlength="100"
                                            placeholder="Минимум 6 символов">
                                        <button type="button" class="password-toggle" id="toggleAdminPassword">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">
                                        Минимум 6 символов. Пароль будет храниться в открытом виде.
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3 d-flex align-items-end">
                                    <button type="button" class="btn btn-outline-secondary me-2"
                                        id="generateAdminPasswordBtn">
                                        <i class="bi bi-shuffle"></i> Сгенерировать пароль
                                    </button>
                                </div>
                            </div>

                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                <strong>Внимание!</strong> Городской администратор сможет:
                                <ul class="mb-0 mt-2">
                                    <li>Управлять студентами и преподавателями своего города</li>
                                    <li>Создавать и редактировать тесты для своего города</li>
                                    <li>Просматривать результаты тестирования в своем городе</li>
                                    <li>Не будет иметь доступа к данным других городов</li>
                                </ul>
                            </div>

                            <div class="d-flex justify-content-between align-items-center">
                                <button type="submit" class="btn btn-dark">
                                    <i class="bi bi-shield-plus"></i> Создать администратора
                                </button>
                                
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="autoGenerateAdmin">
                                    <label class="form-check-label" for="autoGenerateAdmin">
                                        Автогенерация логина
                                    </label>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Добавление студента -->
        <h2 class="mb-4">👨‍🎓 Добавить студента</h2>
        <div class="row mb-5">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Создание учетной записи студента</h5>
                    </div>
                    <div class="card-body">
                        <form action="create_student.php" method="POST" id="createStudentForm">
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
                                    <label for="city" class="form-label">Город:</label>
                                    <input type="text" class="form-control" id="city" name="city" maxlength="100"
                                        placeholder="Москва">
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
                                <div class="col-md-2 mb-3">
                                    <label for="specialty" class="form-label">Специальность:</label>
                                    <?php
                                    // Получаем список специальностей из базы данных
                                    $specialties = [];
                                    try {
                                        $stmt = $pdo->query("SELECT * FROM specialties ORDER BY name");
                                        $specialties = $stmt->fetchAll();
                                    } catch (Exception $e) {
                                        $specialties = [];
                                    }
                                    ?>
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
                                                Специальности не найдены.
                                                <a href="specialties.php" class="alert-link">Добавьте специальности</a>
                                                сначала.
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
        <h2 class="mb-4">👨‍🏫 Добавить преподавателя</h2>
        <div class="row mb-5">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Создание учетной записи преподавателя</h5>
                    </div>
                    <div class="card-body">
                        <form action="create_teacher.php" method="POST" id="createTeacherForm">
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
                                    <label for="teacher_city" class="form-label">Город:</label>
                                    <input type="text" class="form-control" id="teacher_city" name="city"
                                        maxlength="100" placeholder="Москва">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="teacher_username" class="form-label">Логин:</label>
                                    <input type="text" class="form-control" id="teacher_username" name="username"
                                        required maxlength="50" placeholder="ivanov_teacher">
                                    <div class="form-text">
                                        Только латинские буквы, цифры и символ _
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
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
                                <div class="col-md-6 mb-3 d-flex align-items-end">
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
            <div class="col-md-2 mb-3">
                <a href="tests.php?action=create" class="quick-action-btn bg-primary text-white text-center p-4">
                    <i class="bi bi-plus-circle fs-1 mb-3"></i>
                    <h5>Создать тест</h5>
                    <p class="mb-0">Добавить новый тест</p>
                </a>
            </div>
            <div class="col-md-2 mb-3">
                <a href="questions.php" class="quick-action-btn bg-success text-white text-center p-4">
                    <i class="bi bi-question-circle fs-1 mb-3"></i>
                    <h5>Добавить вопросы</h5>
                    <p class="mb-0">Создать вопросы для тестов</p>
                </a>
            </div>
            <div class="col-md-2 mb-3">
                <a href="students.php" class="quick-action-btn bg-warning text-white text-center p-4">
                    <i class="bi bi-people fs-1 mb-3"></i>
                    <h5>Студенты</h5>
                    <p class="mb-0">Управление студентами</p>
                </a>
            </div>
            <div class="col-md-2 mb-3">
                <a href="specialties.php" class="quick-action-btn specialty-card text-white text-center p-4">
                    <i class="bi bi-briefcase fs-1 mb-3"></i>
                    <h5>Специальности</h5>
                    <p class="mb-0">Управление специальностями</p>
                </a>
            </div>
            <div class="col-md-2 mb-3">
                <a href="teachers.php" class="quick-action-btn teacher-card text-white text-center p-4">
                    <i class="bi bi-person-badge fs-1 mb-3"></i>
                    <h5>Преподаватели</h5>
                    <p class="mb-0">Управление преподавателями</p>
                </a>
            </div>
            <div class="col-md-2 mb-3">
                <a href="admins.php" class="quick-action-btn admin-card text-white text-center p-4">
                    <i class="bi bi-shield-check fs-1 mb-3"></i>
                    <h5>Администраторы</h5>
                    <p class="mb-0">Управление админами</p>
                </a>
            </div>
        </div>

        <!-- Навигация -->
        <h2 class="mb-4">🧭 Навигация</h2>
        <div class="row">
            <div class="col-md-2 mb-3">
                <a href="tests.php" class="btn btn-outline-primary w-100 p-3">
                    <i class="bi bi-journal-text fs-4 mb-2 d-block"></i>
                    Управление тестами
                </a>
            </div>
            <div class="col-md-2 mb-3">
                <a href="questions.php" class="btn btn-outline-success w-100 p-3">
                    <i class="bi bi-question-circle fs-4 mb-2 d-block"></i>
                    Управление вопросами
                </a>
            </div>
            <div class="col-md-2 mb-3">
                <a href="students.php" class="btn btn-outline-warning w-100 p-3">
                    <i class="bi bi-people fs-4 mb-2 d-block"></i>
                    Управление студентами
                </a>
            </div>
            <div class="col-md-2 mb-3">
                <a href="specialties.php" class="btn btn-outline-purple w-100 p-3">
                    <i class="bi bi-briefcase fs-4 mb-2 d-block"></i>
                    Управление специальностями
                </a>
            </div>
            <div class="col-md-2 mb-3">
                <a href="teachers.php" class="btn btn-outline-info w-100 p-3">
                    <i class="bi bi-person-badge fs-4 mb-2 d-block"></i>
                    Управление преподавателями
                </a>
            </div>
            <div class="col-md-2 mb-3">
                <a href="admins.php" class="btn btn-outline-dark w-100 p-3">
                    <i class="bi bi-shield-check fs-4 mb-2 d-block"></i>
                    Управление админами
                </a>
            </div>
        </div>

        <!-- Дополнительная навигация -->
        <div class="row mt-3">
            <div class="col-md-3 mb-3">
                <a href="results.php" class="btn btn-outline-secondary w-100 p-3">
                    <i class="bi bi-bar-chart fs-4 mb-2 d-block"></i>
                    Результаты тестов
                </a>
            </div>
            <div class="col-md-3 mb-3">
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
                Testy © <?php echo date('Y'); ?> |
                Версия 1.0 | Супер-администратор: <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Администратор'); ?>
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

        // Копирование данных администратора
        document.querySelectorAll('.copy-admin-btn').forEach(btn => {
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

        // Копирование всех данных администратора
        document.querySelector('.copy-admin-all-btn')?.addEventListener('click', function () {
            const username = document.getElementById('admin-username-text').textContent;
            const password = document.getElementById('admin-password-text').textContent;
            const text = `Логин: ${username}\nПароль: ${password}`;

            copyToClipboard(text);

            // Визуальная обратная связь
            const originalHTML = this.innerHTML;
            this.innerHTML = '<i class="bi bi-check"></i> Скопировано!';
            this.classList.remove('btn-outline-primary');
            this.classList.add('btn-primary');

            setTimeout(() => {
                this.innerHTML = originalHTML;
                this.classList.remove('btn-primary');
                this.classList.add('btn-outline-primary');
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

        // Показать/скрыть пароль при создании администратора
        document.getElementById('toggleAdminPassword').addEventListener('click', function () {
            const passwordInput = document.getElementById('admin_password');
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

        // Генерация пароля для администратора
        document.getElementById('generateAdminPasswordBtn').addEventListener('click', function () {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
            let password = '';
            const length = 12;

            for (let i = 0; i < length; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }

            const passwordInput = document.getElementById('admin_password');
            passwordInput.value = password;
            passwordInput.type = 'text';

            // Обновляем иконку
            const toggleBtn = document.getElementById('toggleAdminPassword');
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

        // Автогенерация логина администратора
        document.getElementById('autoGenerateAdmin').addEventListener('change', function() {
            if (this.checked) {
                const lastName = document.getElementById('admin_last_name').value.toLowerCase();
                const firstName = document.getElementById('admin_first_name').value.toLowerCase();
                const city = document.getElementById('admin_city').value.toLowerCase();

                if (lastName && firstName && city) {
                    // Создаем логин: фамилия + первая буква имени + город + случайные цифры
                    const username = lastName + 
                                   '_' + firstName.charAt(0) + 
                                   '_' + city + 
                                   Math.floor(100 + Math.random() * 900);
                    document.getElementById('admin_username').value = username;
                }
            }
        });

        // Блокировка ввода в поле логина администратора при автогенерации
        document.getElementById('admin_username').addEventListener('focus', function() {
            const autoGenerate = document.getElementById('autoGenerateAdmin');
            if (autoGenerate && autoGenerate.checked) {
                this.blur();
                alert('Логин генерируется автоматически. Снимите галочку "Автогенерация логина" для ручного ввода.');
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

        // Валидация логина администратора (только латиница, цифры и _)
        document.getElementById('admin_username').addEventListener('input', function () {
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

        // Валидация формы администратора перед отправкой
        document.getElementById('createAdminForm').addEventListener('submit', function (e) {
            const lastName = document.getElementById('admin_last_name').value.trim();
            const firstName = document.getElementById('admin_first_name').value.trim();
            const city = document.getElementById('admin_city').value.trim();
            const username = document.getElementById('admin_username').value.trim();
            const password = document.getElementById('admin_password').value;

            let errors = [];

            if (!lastName) errors.push('Фамилия обязательна');
            if (!firstName) errors.push('Имя обязательно');
            if (!city) errors.push('Город обязателен');
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

        // Автоматическое скрытие пароля администратора через 30 секунд
        setTimeout(function () {
            const passwordInput = document.getElementById('admin_password');
            if (passwordInput && passwordInput.type === 'text') {
                if (confirm('Пароль администратора все еще виден. Хотите скрыть его?')) {
                    passwordInput.type = 'password';
                    const toggleBtn = document.getElementById('toggleAdminPassword');
                    const icon = toggleBtn.querySelector('i');
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            }
        }, 30000);
    </script>
</body>
</html>