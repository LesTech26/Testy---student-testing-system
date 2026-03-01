<?php
require_once '../config/functions.php';
requireAdminLogin();

// Проверяем, что текущий пользователь - супер-админ
if (!isset($_SESSION['is_superadmin']) || !$_SESSION['is_superadmin']) {
    $_SESSION['error'] = 'У вас нет прав для редактирования администраторов';
    header('Location: index.php');
    exit();
}

// Проверяем ID администратора
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = 'Неверный ID администратора';
    header('Location: admins.php');
    exit();
}

$admin_id = (int)$_GET['id'];

// Получаем информацию об администраторе
try {
    $stmt = $pdo->prepare("
        SELECT id, last_name, first_name, city, username, is_superadmin, is_active, created_at 
        FROM admins 
        WHERE id = ? AND is_superadmin = 0
    ");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();
    
    if (!$admin) {
        $_SESSION['error'] = 'Администратор не найден или это супер-администратор';
        header('Location: admins.php');
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error'] = 'Ошибка при получении данных администратора: ' . $e->getMessage();
    header('Location: admins.php');
    exit();
}

// Обработка формы редактирования
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $last_name = trim($_POST['last_name'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Валидация данных
    $errors = [];
    
    if (empty($last_name)) {
        $errors[] = 'Фамилия обязательна';
    }
    
    if (empty($first_name)) {
        $errors[] = 'Имя обязательно';
    }
    
    if (empty($city)) {
        $errors[] = 'Город обязателен';
    }
    
    if (empty($username)) {
        $errors[] = 'Логин обязателен';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'Логин может содержать только латинские буквы, цифры и символ _';
    }
    
    // Проверка уникальности логина (кроме текущего администратора)
    if ($username !== $admin['username']) {
        $stmt = $pdo->prepare("SELECT id FROM admins WHERE username = ? AND id != ?");
        $stmt->execute([$username, $admin_id]);
        if ($stmt->fetch()) {
            $errors[] = 'Логин уже используется другим администратором';
        }
    }
    
    // Если введен новый пароль
    if (!empty($password)) {
        if (strlen($password) < 6) {
            $errors[] = 'Пароль должен быть минимум 6 символов';
        }
    }
    
    if (empty($errors)) {
        try {
            // Если введен новый пароль - обновляем его
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    UPDATE admins 
                    SET last_name = ?, first_name = ?, city = ?, username = ?, 
                        password = ?, is_active = ?, created_at = created_at 
                    WHERE id = ?
                ");
                $stmt->execute([$last_name, $first_name, $city, $username, $hashed_password, $is_active, $admin_id]);
                
                // Сохраняем пароль для отображения
                $_SESSION['admin_updated_password'] = $password;
            } else {
                // Без изменения пароля
                $stmt = $pdo->prepare("
                    UPDATE admins 
                    SET last_name = ?, first_name = ?, city = ?, username = ?, 
                        is_active = ?, created_at = created_at 
                    WHERE id = ?
                ");
                $stmt->execute([$last_name, $first_name, $city, $username, $is_active, $admin_id]);
            }
            
            $_SESSION['success'] = 'Данные администратора успешно обновлены!';
            
            // Если был изменен пароль, показываем его
            if (!empty($password)) {
                $_SESSION['admin_updated_info'] = [
                    'username' => $username,
                    'password' => $password,
                    'full_name' => $last_name . ' ' . $first_name
                ];
            }
            
            header('Location: admins.php');
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Ошибка при обновлении данных: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errors);
    }
    
    // Обновляем данные из формы
    $admin = [
        'id' => $admin_id,
        'last_name' => $last_name,
        'first_name' => $first_name,
        'city' => $city,
        'username' => $username,
        'is_active' => $is_active,
        'created_at' => $admin['created_at']
    ];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактирование администратора</title>
    <link rel="icon" href="/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
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
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-pencil-square"></i> Редактирование администратора</h1>
            <a href="admins.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Назад к списку
            </a>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['error']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Основная информация</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="last_name" class="form-label">Фамилия:</label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" 
                                           required maxlength="100" placeholder="Иванов"
                                           value="<?php echo htmlspecialchars($admin['last_name']); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="first_name" class="form-label">Имя:</label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" 
                                           required maxlength="100" placeholder="Иван"
                                           value="<?php echo htmlspecialchars($admin['first_name']); ?>">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="city" class="form-label">Город:</label>
                                    <input type="text" class="form-control" id="city" name="city" 
                                           required maxlength="100" placeholder="Москва"
                                           value="<?php echo htmlspecialchars($admin['city']); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="username" class="form-label">Логин:</label>
                                    <input type="text" class="form-control" id="username" name="username" 
                                           required maxlength="50" placeholder="ivanov_admin"
                                           value="<?php echo htmlspecialchars($admin['username']); ?>">
                                    <div class="form-text">
                                        Только латинские буквы, цифры и символ _
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">
                                    Новый пароль (оставьте пустым, если не нужно менять):
                                </label>
                                <div class="password-field">
                                    <input type="password" class="form-control" id="password" name="password"
                                           minlength="6" maxlength="100" placeholder="Минимум 6 символов">
                                    <button type="button" class="password-toggle" id="togglePassword">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text">
                                    Минимум 6 символов. Пароль будет зашифрован.
                                </div>
                            </div>

                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="is_active" name="is_active" 
                                       value="1" <?php echo $admin['is_active'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">
                                    Активный (может входить в систему)
                                </label>
                            </div>

                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                <strong>Внимание!</strong> При изменении пароля старый пароль будет утерян.
                                Новый пароль будет показан только один раз после сохранения.
                            </div>

                            <div class="d-flex justify-content-between">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Сохранить изменения
                                </button>
                                
                                <button type="button" class="btn btn-outline-secondary" id="generatePasswordBtn">
                                    <i class="bi bi-shuffle"></i> Сгенерировать пароль
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Информация</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <strong>ID администратора:</strong>
                            <div class="text-muted"><?php echo $admin['id']; ?></div>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Дата создания:</strong>
                            <div class="text-muted">
                                <?php 
                                if (!empty($admin['created_at'])) {
                                    echo date('d.m.Y H:i', strtotime($admin['created_at']));
                                } else {
                                    echo 'Не указана';
                                }
                                ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Текущий статус:</strong>
                            <div>
                                <?php if ($admin['is_active']): ?>
                                    <span class="badge bg-success">Активен</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Неактивен</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Тип:</strong>
                            <div>
                                <span class="badge bg-secondary">Городской администратор</span>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Важно!</strong> Городской администратор может управлять только данными своего города.
                        </div>
                    </div>
                </div>
                
                <div class="card mt-3">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">Опасная зона</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">
                            Будьте осторожны с этими действиями. Они необратимы.
                        </p>
                        
                        <div class="d-grid gap-2">
                            <a href="delete_admin.php?id=<?php echo $admin['id']; ?>" 
                               class="btn btn-outline-danger"
                               onclick="return confirm('Вы уверены, что хотите удалить этого администратора? Это действие нельзя отменить.');">
                                <i class="bi bi-trash"></i> Удалить администратора
                            </a>
                            
                            <?php if ($admin['is_active']): ?>
                                <a href="toggle_admin.php?id=<?php echo $admin['id']; ?>&action=deactivate" 
                                   class="btn btn-outline-warning"
                                   onclick="return confirm('Заблокировать администратора? Он не сможет входить в систему.');">
                                    <i class="bi bi-lock"></i> Заблокировать
                                </a>
                            <?php else: ?>
                                <a href="toggle_admin.php?id=<?php echo $admin['id']; ?>&action=activate" 
                                   class="btn btn-outline-success"
                                   onclick="return confirm('Активировать администратора? Он сможет входить в систему.');">
                                    <i class="bi bi-unlock"></i> Активировать
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Показать/скрыть пароль
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

        // Генерация пароля
        document.getElementById('generatePasswordBtn').addEventListener('click', function () {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
            let password = '';
            const length = 12;

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

        // Валидация формы перед отправкой
        document.querySelector('form').addEventListener('submit', function (e) {
            const lastName = document.getElementById('last_name').value.trim();
            const firstName = document.getElementById('first_name').value.trim();
            const city = document.getElementById('city').value.trim();
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;

            let errors = [];

            if (!lastName) errors.push('Фамилия обязательна');
            if (!firstName) errors.push('Имя обязательно');
            if (!city) errors.push('Город обязателен');
            if (!username) errors.push('Логин обязателен');

            if (!/^[a-zA-Z0-9_]+$/.test(username)) {
                errors.push('Логин может содержать только латинские буквы, цифры и символ _');
            }

            if (password && password.length < 6) {
                errors.push('Пароль должен быть минимум 6 символов');
            }

            if (errors.length > 0) {
                e.preventDefault();
                alert('Ошибки в форме:\n' + errors.join('\n'));
                return false;
            }

            if (password) {
                if (!confirm('Вы уверены, что хотите изменить пароль? Старый пароль будет утерян.')) {
                    e.preventDefault();
                    return false;
                }
            }

            return true;
        });

        // Автоматическое скрытие пароля через 30 секунд
        setTimeout(function () {
            const passwordInput = document.getElementById('password');
            if (passwordInput && passwordInput.type === 'text') {
                if (confirm('Сгенерированный пароль все еще виден. Хотите скрыть его?')) {
                    passwordInput.type = 'password';
                    const toggleBtn = document.getElementById('togglePassword');
                    const icon = toggleBtn.querySelector('i');
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            }
        }, 30000);
    </script>
</body>
</html>