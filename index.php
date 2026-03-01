<?php
session_start();
require_once 'config/database.php';

// Проверяем, если студент уже авторизован, перенаправляем на выбор теста
if (isset($_SESSION['student']) && !empty($_SESSION['student'])) {
    header('Location: tests.php');
    exit();
}

// Проверяем, если преподаватель уже авторизован
if (isset($_SESSION['teacher']) && !empty($_SESSION['teacher'])) {
    header('Location: teacher/index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Testy - Система тестирования студентов</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="icon" href="logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .login-container {
            max-width: 450px;
            margin: 0 auto;
            animation: fadeIn 0.8s ease-in-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }

        .login-header {
            background: linear-gradient(45deg, #3faff9, #5c8bb9);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .login-header h1 {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .login-body {
            padding: 35px;
        }

        .form-control {
            padding: 12px 15px;
            border-radius: 8px;
            border: 2px solid #e0e0e0;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
        }

        .input-group-text {
            background-color: #f8f9fa;
            border: 2px solid #e0e0e0;
            border-right: none;
        }

        .btn-primary {
            background: linear-gradient(45deg, #3498db, #2980b9);
            border: none;
            padding: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.4);
        }

        .password-container {
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

        .footer-links {
            margin-top: 25px;
            text-align: center;
        }

        .footer-links a {
            text-decoration: none;
            color: #6c757d;
            transition: color 0.3s;
            display: block;
            margin-bottom: 10px;
            padding: 8px;
            border-radius: 5px;
            background: #f8f9fa;
        }

        .footer-links a:hover {
            color: #3498db;
            background: #e9ecef;
            text-decoration: none;
        }

        .footer-links .admin-link {
            border-left: 4px solid #dc3545;
        }

        .footer-links .teacher-link {
            border-left: 4px solid #0dcaf0;
        }

        .footer-links .student-link {
            border-left: 4px solid #198754;
        }

        .alert {
            border-radius: 8px;
            border: none;
        }

        .system-info {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(5px);
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            color: white;
            text-align: center;
        }

        .role-selector {
            margin-bottom: 20px;
            text-align: center;
        }

        .role-badge {
            display: inline-block;
            padding: 5px 15px;
            margin: 0 5px;
            border-radius: 20px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .role-badge.active {
            transform: scale(1.05);
        }

        .role-badge.student {
            background: linear-gradient(45deg, #198754, #20c997);
            color: white;
            border-color: #198754;
        }

        .role-badge.teacher {
            background: linear-gradient(45deg, #0dcaf0, #17a2b8);
            color: white;
            border-color: #0dcaf0;
        }

        .form-title {
            text-align: center;
            margin-bottom: 25px;
            color: #2c3e50;
            font-weight: 600;
        }

        .logo-container {
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 90px;
        }

        .logo-image {
            max-width: 100%;
            max-height: 100px;
            object-fit: contain;
            padding-left: 50px;
        }

        .system-info {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(5px);
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
            color: white;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .brand-name {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
            background: linear-gradient(135deg, #fff, #e0e0e0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: 1px;
        }

        .copyright {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .copyright i {
            font-size: 0.8rem;
        }

        .developer {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .developer-text {
            font-size: 0.9rem;
            opacity: 0.8;
            text-transform: lowercase;
        }

        .developer-link {
            text-decoration: none;
            font-weight: bold;
            font-size: 1.4rem;
            transition: all 0.3s ease;
            display: inline-flex;
            gap: 4px;
            color: blue;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="login-container">
            <div class="login-card">
                <div class="login-header">
                    <div class="logo-container">
                        <img src="logo.png" alt="Testy Logo" class="logo-image"
                            onerror="this.style.display='none'; document.getElementById('textLogo').style.display='block';">
                        <div id="textLogo" style="display: none; font-size: 2rem; font-weight: bold;">Testy</div>
                    </div>
                </div>

                <div class="login-body">
                    <!-- Роль пользователя -->
                    <div class="role-selector">
                        <span class="role-badge student active" onclick="setRole('student')">
                            <i class="bi bi-person-fill"></i> Студент
                        </span>
                        <span class="role-badge teacher" onclick="setRole('teacher')">
                            <i class="bi bi-person-badge-fill"></i> Преподаватель
                        </span>
                    </div>

                    <!-- Форма входа студента -->
                    <div id="studentLoginForm">
                        <h4 class="form-title">
                            <i class="bi bi-person-fill"></i> Вход для студентов
                        </h4>

                        <?php if (isset($_SESSION['login_error'])): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <?php echo $_SESSION['login_error']; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                            <?php unset($_SESSION['login_error']); ?>
                        <?php endif; ?>

                        <?php if (isset($_SESSION['login_success'])): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle-fill"></i>
                                <?php echo $_SESSION['login_success']; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                            <?php unset($_SESSION['login_success']); ?>
                        <?php endif; ?>

                        <form action="login.php" method="POST" id="studentForm">
                            <input type="hidden" name="role" value="student">

                            <div class="mb-4">
                                <label for="student_username" class="form-label">Логин студента:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person-circle"></i></span>
                                    <input type="text" class="form-control" id="student_username" name="username"
                                        required placeholder="Введите ваш логин"
                                        value="<?php echo isset($_SESSION['temp_username']) ? htmlspecialchars($_SESSION['temp_username']) : ''; ?>">
                                </div>
                                <?php unset($_SESSION['temp_username']); ?>
                            </div>

                            <div class="mb-4">
                                <label for="student_password" class="form-label">Пароль:</label>
                                <div class="password-container">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                                        <input type="password" class="form-control" id="student_password"
                                            name="password" required placeholder="Введите ваш пароль">
                                    </div>
                                    <button type="button" class="password-toggle" id="toggleStudentPassword">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="d-grid mb-4">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="bi bi-box-arrow-in-right"></i> Войти как студент
                                </button>
                            </div>

                            <div class="text-center mb-4">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" id="rememberStudent"
                                        name="remember_me">
                                    <label class="form-check-label" for="rememberStudent">Запомнить меня</label>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Форма входа преподавателя -->
                    <div id="teacherLoginForm" style="display: none;">
                        <h4 class="form-title">
                            <i class="bi bi-person-badge-fill"></i> Вход для преподавателей
                        </h4>

                        <?php if (isset($_SESSION['teacher_login_error'])): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <?php echo $_SESSION['teacher_login_error']; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                            <?php unset($_SESSION['teacher_login_error']); ?>
                        <?php endif; ?>

                        <?php if (isset($_SESSION['teacher_login_success'])): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle-fill"></i>
                                <?php echo $_SESSION['teacher_login_success']; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                            <?php unset($_SESSION['teacher_login_success']); ?>
                        <?php endif; ?>

                        <form action="teacher/login.php" method="POST" id="teacherForm">
                            <input type="hidden" name="role" value="teacher">

                            <div class="mb-4">
                                <label for="teacher_username" class="form-label">Логин преподавателя:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                                    <input type="text" class="form-control" id="teacher_username" name="username"
                                        required placeholder="Введите ваш логин">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="teacher_password" class="form-label">Пароль:</label>
                                <div class="password-container">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                                        <input type="password" class="form-control" id="teacher_password"
                                            name="password" required placeholder="Введите ваш пароль">
                                    </div>
                                    <button type="button" class="password-toggle" id="toggleTeacherPassword">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="d-grid mb-4">
                                <button type="submit" class="btn btn-info btn-lg">
                                    <i class="bi bi-box-arrow-in-right"></i> Войти как преподаватель
                                </button>
                            </div>

                            <div class="text-center mb-4">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" id="rememberTeacher"
                                        name="remember_me">
                                    <label class="form-check-label" for="rememberTeacher">Запомнить меня</label>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Контейнер для алертов -->
                    <div id="alertContainer"></div>

                    <div class="footer-links">
                        <a href="admin/login.php" class="admin-link">
                            <i class="bi bi-shield-lock"></i> Вход для администратора
                        </a>
                        <small class="text-muted d-block mb-2 mt-2">
                            Нет учетной записи? Обратитесь к администратору
                        </small>
                    </div>
                </div>
            </div>

            <div class="system-info">
                <div class="brand-name">Testy</div>
                <div class="copyright">
                    <i class="bi bi-c-circle"></i> © 2026 | Все права защищены
                </div>
                <div class="developer">
                    <span class="developer-text" style="padding-right:10px">разработано</span>
                    <a href="https://t.me/les_tech" class="developer-link" target="_blank" rel="noopener noreferrer">
                        <span class="les">LES TECH</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Переключение между формами входа
        function setRole(role) {
            const studentForm = document.getElementById('studentLoginForm');
            const teacherForm = document.getElementById('teacherLoginForm');
            const studentBadge = document.querySelector('.role-badge.student');
            const teacherBadge = document.querySelector('.role-badge.teacher');

            if (role === 'student') {
                studentForm.style.display = 'block';
                teacherForm.style.display = 'none';
                studentBadge.classList.add('active');
                teacherBadge.classList.remove('active');

                // Фокус на поле логина студента
                document.getElementById('student_username').focus();
            } else {
                studentForm.style.display = 'none';
                teacherForm.style.display = 'block';
                studentBadge.classList.remove('active');
                teacherBadge.classList.add('active');

                // Фокус на поле логина преподавателя
                document.getElementById('teacher_username').focus();
            }
        }

        // Показать/скрыть пароль для студента
        document.getElementById('toggleStudentPassword').addEventListener('click', function () {
            const passwordInput = document.getElementById('student_password');
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

        // Показать/скрыть пароль для преподавателя
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

        // Валидация формы студента
        document.getElementById('studentForm').addEventListener('submit', function (e) {
            const username = document.getElementById('student_username').value.trim();
            const password = document.getElementById('student_password').value.trim();

            if (!username) {
                e.preventDefault();
                alert('Введите логин студента');
                document.getElementById('student_username').focus();
                return false;
            }

            if (!password) {
                e.preventDefault();
                alert('Введите пароль студента');
                document.getElementById('student_password').focus();
                return false;
            }

            return true;
        });

        // Валидация формы преподавателя
        document.getElementById('teacherForm').addEventListener('submit', function (e) {
            const username = document.getElementById('teacher_username').value.trim();
            const password = document.getElementById('teacher_password').value.trim();

            if (!username) {
                e.preventDefault();
                alert('Введите логин преподавателя');
                document.getElementById('teacher_username').focus();
                return false;
            }

            if (!password) {
                e.preventDefault();
                alert('Введите пароль преподавателя');
                document.getElementById('teacher_password').focus();
                return false;
            }

            return true;
        });

        // Запоминание логина студента через localStorage
        const rememberStudent = document.getElementById('rememberStudent');
        const studentUsernameField = document.getElementById('student_username');

        // Проверяем сохраненный логин студента
        const savedStudentUsername = localStorage.getItem('student_username');
        if (savedStudentUsername) {
            studentUsernameField.value = savedStudentUsername;
            rememberStudent.checked = true;
        }

        // Сохраняем логин студента при отправке формы
        document.getElementById('studentForm').addEventListener('submit', function () {
            if (rememberStudent.checked) {
                localStorage.setItem('student_username', studentUsernameField.value);
            } else {
                localStorage.removeItem('student_username');
            }
        });

        // Запоминание логина преподавателя через localStorage
        const rememberTeacher = document.getElementById('rememberTeacher');
        const teacherUsernameField = document.getElementById('teacher_username');

        // Проверяем сохраненный логин преподавателя
        const savedTeacherUsername = localStorage.getItem('teacher_username');
        if (savedTeacherUsername) {
            teacherUsernameField.value = savedTeacherUsername;
            rememberTeacher.checked = true;
        }

        // Сохраняем логин преподавателя при отправке формы
        document.getElementById('teacherForm').addEventListener('submit', function () {
            if (rememberTeacher.checked) {
                localStorage.setItem('teacher_username', teacherUsernameField.value);
            } else {
                localStorage.removeItem('teacher_username');
            }
        });

        // Автофокус на соответствующее поле
        document.getElementById('student_username').focus();

        // Функция для проверки доступа к админ-панели
        function checkAdminAccess(event) {
            event.preventDefault();

            // Показываем уведомление о загрузке
            showAlert('info', 'Проверка доступа...');

            // Проверяем существование файла админ-логина
            fetch('admin/login.php')
                .then(response => {
                    if (response.ok) {
                        // Если файл существует, переходим на страницу
                        window.location.href = 'admin/login.php';
                    } else {
                        // Если файл не найден, показываем сообщение
                        showAlert('warning', 'Страница входа для администратора временно недоступна. Свяжитесь с техподдержкой.');
                    }
                })
                .catch(error => {
                    console.error('Ошибка:', error);
                    showAlert('danger', 'Ошибка при проверке доступа к админ-панели.');
                });

            return false;
        }

        // Вспомогательная функция для показа сообщений
        function showAlert(type, message) {
            const alertContainer = document.getElementById('alertContainer');

            const alertClass = {
                'info': 'alert-info',
                'warning': 'alert-warning',
                'danger': 'alert-danger',
                'success': 'alert-success'
            }[type] || 'alert-info';

            const icon = {
                'info': 'bi-info-circle-fill',
                'warning': 'bi-exclamation-triangle-fill',
                'danger': 'bi-x-circle-fill',
                'success': 'bi-check-circle-fill'
            }[type] || 'bi-info-circle-fill';

            alertContainer.innerHTML = `
                <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                    <i class="bi ${icon}"></i> ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
        }
    </script>
</body>

</html>