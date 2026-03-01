<?php
// Включим вывод ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Начинаем сессию ТОЛЬКО если она еще не начата
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';

// Обработка POST запроса
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';

    if (empty($username) || empty($password)) {
        $error = "Пожалуйста, заполните все поля";
    } else {
        try {
            $config_path = __DIR__ . '/../config/database.php';

            if (!file_exists($config_path)) {
                throw new Exception("Файл конфигурации базы данных не найден");
            }

            require_once $config_path;

            if (!isset($pdo)) {
                throw new Exception("Не удалось подключиться к базе данных");
            }

            $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? AND password = ? AND is_active = 1");
            $stmt->execute([$username, $password]);
            $admin = $stmt->fetch();

            if ($admin) {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_type'] = $admin['is_superadmin'] ? 'super' : 'city';
                $_SESSION['is_superadmin'] = $admin['is_superadmin'];
                $_SESSION['admin_city'] = $admin['city'] ?? '';

                // Редирект только после успешного входа
                if (!headers_sent()) {
                    header('Location: index.php');
                    exit();
                }
            } else {
                $error = "Неверное имя пользователя или пароль";
            }
        } catch (Exception $e) {
            $error = "Ошибка: " . $e->getMessage();
        }
    }
}

// Если пользователь УЖЕ авторизован, показываем информационное сообщение
if (isset($_SESSION['admin_id'])) {
    ?>
    <!DOCTYPE html>
    <html lang="ru">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Уже авторизованы - Testy</title>
        <link rel="icon" href="/logo.png">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
        <style>
            body {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }

            .info-card {
                border-radius: 20px;
                overflow: hidden;
                box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
                border: none;
                background: white;
                max-width: 500px;
                margin: 0 auto;
            }

            .info-header {
                background: linear-gradient(45deg, #28a745, #20c997);
                padding: 30px;
                color: white;
                text-align: center;
            }

            .info-body {
                padding: 40px;
            }
        </style>
    </head>

    <body>
        <div class="container">
            <div class="info-card">
                <div class="info-header">
                    <h2><i class="bi bi-check-circle"></i> Уже авторизованы</h2>
                </div>
                <div class="info-body">
                    <div class="alert alert-info">
                        <h4 class="alert-heading">Добро пожаловать,
                            <?php echo htmlspecialchars($_SESSION['admin_username']); ?>!
                        </h4>
                        <p>Вы уже вошли в систему как
                            <strong><?php echo $_SESSION['admin_type'] === 'super' ? 'Супер-администратор' : 'Городской администратор'; ?></strong>
                        </p>
                        <hr>
                        <p class="mb-0">Ваш город: <?php echo htmlspecialchars($_SESSION['admin_city'] ?: 'Не указан'); ?>
                        </p>
                    </div>
                    <div class="d-grid gap-2">
                        <a href="index.php" class="btn btn-success btn-lg">
                            <i class="bi bi-speedometer2"></i> Перейти в админ-панель
                        </a>
                        <a href="logout.php" class="btn btn-outline-secondary">
                            <i class="bi bi-box-arrow-right"></i> Выйти
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </body>

    </html>
    <?php
    exit();
}
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход для администратора - Testy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .login-card {
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            border: none;
            background: white;
            max-width: 500px;
            margin: 0 auto;
        }

        .login-header {
            background: linear-gradient(45deg, #dc3545, #c82333);
            padding: 30px;
            color: white;
            text-align: center;
        }

        .login-body {
            padding: 40px;
        }

        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
        }

        .form-control:focus {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }

        .btn-login {
            background: linear-gradient(45deg, #dc3545, #c82333);
            border: none;
            padding: 12px;
            font-size: 16px;
            border-radius: 10px;
            color: white;
            width: 100%;
            font-weight: 600;
        }

        .btn-login:hover {
            background: linear-gradient(45deg, #c82333, #bd2130);
        }

        .password-toggle-btn {
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

        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #6c757d;
            text-decoration: none;
        }

        .back-link:hover {
            color: #dc3545;
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
    </style>
</head>

<body>
    <div class="container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo-container">
                    <img src="/logo.png" alt="Testy Logo" class="logo-image"
                        onerror="this.style.display='none'; document.getElementById('textLogo').style.display='block';">
                    <div id="textLogo" style="display: none; font-size: 2rem; font-weight: bold;">Testy</div>
                </div>
            </div>

            <div class="login-body">
                <h3 class="text-center mb-4">Вход для администратора</h3>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" id="loginForm">
                    <div class="mb-4">
                        <label for="username" class="form-label fw-bold">Логин администратора:</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">
                                <i class="bi bi-person text-danger"></i>
                            </span>
                            <input type="text" class="form-control" id="username" name="username"
                                placeholder="Введите логин"
                                value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                                required>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label fw-bold">Пароль:</label>
                        <div class="position-relative">
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="bi bi-key text-danger"></i>
                                </span>
                                <input type="password" class="form-control" id="password" name="password"
                                    placeholder="Введите пароль" required>
                            </div>
                            <button type="button" class="password-toggle-btn" id="togglePassword">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-login">
                            <i class="bi bi-box-arrow-in-right me-2"></i> Войти в систему
                        </button>
                    </div>
                </form>

                <div class="text-center">
                    <a href="../index.php" class="back-link">
                        <i class="bi bi-arrow-left me-1"></i> Вернуться на главную страницу
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
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

        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('username').focus();
        });
    </script>
</body>

</html>