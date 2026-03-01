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

// Получаем список доступных специальностей из БД
$stmt = $pdo->prepare("SELECT id, name FROM specialties ORDER BY name");
$stmt->execute();
$specialties = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем информацию о специальности студента (если она есть в таблице specialties)
$student_specialty = null;
if (!empty($student['specialty'])) {
    $stmt = $pdo->prepare("SELECT id, name FROM specialties WHERE name = ?");
    $stmt->execute([$student['specialty']]);
    $student_specialty = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $last_name = trim($_POST['last_name']);
    $first_name = trim($_POST['first_name']);
    $course_number = (int)$_POST['course_number'];
    $specialty_id = isset($_POST['specialty_id']) ? (int)$_POST['specialty_id'] : null;
    $username = trim($_POST['username']);
    
    // Получаем название специальности по ID
    $specialty_name = '';
    if ($specialty_id) {
        $stmt = $pdo->prepare("SELECT name FROM specialties WHERE id = ?");
        $stmt->execute([$specialty_id]);
        $specialty_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $specialty_name = $specialty_data ? $specialty_data['name'] : '';
    }
    
    // Проверяем данные
    if (empty($last_name) || empty($first_name) || empty($course_number) || empty($specialty_name) || empty($username)) {
        $_SESSION['error'] = 'Все поля обязательны для заполнения';
    } else {
        // Проверяем, существует ли логин у другого студента
        $stmt = $pdo->prepare("SELECT id FROM students WHERE username = ? AND id != ?");
        $stmt->execute([$username, $student_id]);
        if ($stmt->fetch()) {
            $_SESSION['error'] = 'Пользователь с таким логином уже существует';
        } else {
            // Обработка пароля
            if (isset($_POST['show_password']) && $_POST['show_password'] == '1') {
                // Показываем текущий пароль
                $current_password = $student['password'];
            } else {
                // Используем пароль из формы
                $new_password = trim($_POST['new_password']);
                if (!empty($new_password)) {
                    if (strlen($new_password) < 6) {
                        $_SESSION['error'] = 'Пароль должен содержать минимум 6 символов';
                    } else {
                        // Сохраняем пароль в открытом виде
                        $current_password = $new_password;
                    }
                } else {
                    // Оставляем старый пароль
                    $current_password = $student['password'];
                }
            }
            
            // Если нет ошибок, обновляем данные
            if (!isset($_SESSION['error'])) {
                $stmt = $pdo->prepare("
                    UPDATE students 
                    SET last_name = ?, first_name = ?, course_number = ?, 
                        specialty = ?, username = ?, password = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                
                if ($stmt->execute([$last_name, $first_name, $course_number, $specialty_name, $username, $current_password, $student_id])) {
                    $_SESSION['success'] = 'Данные студента успешно обновлены';
                    header('Location: students.php');
                    exit();
                } else {
                    $_SESSION['error'] = 'Ошибка при обновлении данных';
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактирование студента</title>
    <link rel="icon" href="/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 20px;
        }
        .edit-container {
            max-width: 700px;
            margin: 0 auto;
        }
        .edit-card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
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
        }
        .current-password-display {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            font-family: monospace;
            font-size: 1.1rem;
            letter-spacing: 1px;
        }
        .password-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .password-field {
            position: relative;
        }
        .specialty-badge {
            background-color: #6f42c1;
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.9rem;
            margin-left: 5px;
        }
        .course-badge {
            background-color: #0d6efd;
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.9rem;
            margin-left: 5px;
        }
        .student-info-header {
            background: linear-gradient(45deg, #2c3e50, #3498db);
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .specialty-select-container {
            position: relative;
        }
        .add-specialty-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
        }
        .no-specialties-alert {
            border-left: 4px solid #ffc107;
        }
    </style>
</head>
<body>
    <div class="container edit-container">
        <!-- Заголовок -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-pencil"></i> Редактирование студента</h1>
            <a href="students.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Назад
            </a>
        </div>

        <!-- Сообщения -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['error']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['success']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <!-- Информация о студенте -->
        <div class="student-info-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-1"><?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name']); ?></h4>
                    <p class="mb-0">
                        ID: <?php echo $student['id']; ?> | 
                        <span class="course-badge"><?php echo $student['course_number']; ?> курс</span>
                        <span class="specialty-badge"><?php echo htmlspecialchars($student['specialty'] ?? 'Не указана'); ?></span>
                    </p>
                </div>
                <div>
                    <span class="badge bg-light text-dark">
                        <i class="bi bi-calendar"></i> 
                        <?php echo date('d.m.Y', strtotime($student['created_at'])); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Форма редактирования -->
        <div class="edit-card">
            <form method="POST" id="editForm">
                <input type="hidden" name="show_password" id="show_password" value="0">
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="last_name" class="form-label">Фамилия:</label>
                        <input type="text" class="form-control" id="last_name" name="last_name" 
                               value="<?php echo htmlspecialchars($student['last_name']); ?>" required>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="first_name" class="form-label">Имя:</label>
                        <input type="text" class="form-control" id="first_name" name="first_name" 
                               value="<?php echo htmlspecialchars($student['first_name']); ?>" required>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="course_number" class="form-label">Курс:</label>
                        <select class="form-select" id="course_number" name="course_number" required>
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $student['course_number'] == $i ? 'selected' : ''; ?>>
                                    <?php echo $i; ?> курс
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="specialty_id" class="form-label">Специальность:</label>
                        <div class="specialty-select-container">
                            <select class="form-select" id="specialty_id" name="specialty_id" required>
                                <option value="" disabled>Выберите специальность</option>
                                <?php if (!empty($specialties)): ?>
                                    <?php foreach ($specialties as $specialty): ?>
                                        <option value="<?php echo $specialty['id']; ?>" 
                                            <?php 
                                            $is_selected = false;
                                            if ($student_specialty && $student_specialty['id'] == $specialty['id']) {
                                                $is_selected = true;
                                            } elseif (!$student_specialty && $student['specialty'] == $specialty['name']) {
                                                $is_selected = true;
                                            }
                                            echo $is_selected ? 'selected' : ''; 
                                            ?>>
                                            <?php echo htmlspecialchars($specialty['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>Нет доступных специальностей</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-text">Текущая: <?php echo htmlspecialchars($student['specialty'] ?? 'Не указана'); ?></div>
                        
                        <?php if (empty($specialties)): ?>
                            <div class="alert alert-warning mt-2 no-specialties-alert">
                                <i class="bi bi-exclamation-triangle"></i>
                                Специальности не найдены в базе данных. 
                                <a href="specialties.php?action=create" class="alert-link" target="_blank">
                                    Добавьте специальности
                                </a> сначала.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="username" class="form-label">Логин:</label>
                    <input type="text" class="form-control" id="username" name="username" 
                           value="<?php echo htmlspecialchars($student['username']); ?>" required>
                    <div class="form-text">Уникальный логин для входа в систему</div>
                </div>
                
                <!-- Текущий пароль (показывается всегда) -->
                <div class="mb-4">
                    <label class="form-label">Текущий пароль:</label>
                    <div class="current-password-display">
                        <?php echo htmlspecialchars($student['password']); ?>
                    </div>
                    <div class="password-actions">
                        <button type="button" class="btn btn-sm btn-outline-success" id="useCurrentPasswordBtn">
                            <i class="bi bi-check-circle"></i> Использовать этот пароль
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="copyPasswordBtn">
                            <i class="bi bi-clipboard"></i> Копировать пароль
                        </button>
                    </div>
                </div>
                
                <!-- Поле для нового пароля -->
                <div class="mb-4">
                    <label for="new_password" class="form-label">Новый пароль:</label>
                    <div class="password-field">
                        <input type="password" class="form-control" id="new_password" name="new_password" 
                               placeholder="Введите новый пароль (оставьте пустым, чтобы оставить текущий)">
                        <button type="button" class="password-toggle" id="toggleNewPassword">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="form-text">
                        Минимум 6 символов. Если оставить пустым - останется текущий пароль.
                    </div>
                </div>
                
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Сохранить изменения
                    </button>
                    <a href="students.php" class="btn btn-outline-secondary">Отмена</a>
                    <button type="button" class="btn btn-outline-warning" id="generatePasswordBtn">
                        <i class="bi bi-key"></i> Сгенерировать пароль
                    </button>
                    <button type="button" class="btn btn-outline-danger" 
                            data-bs-toggle="modal" data-bs-target="#resetPasswordModal">
                        <i class="bi bi-arrow-clockwise"></i> Сбросить пароль
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Дополнительная информация о студенте -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-info-circle"></i> Подробная информация</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>ID студента:</strong> <?php echo $student['id']; ?></p>
                        <p><strong>Дата регистрации:</strong> <?php echo date('d.m.Y H:i', strtotime($student['created_at'])); ?></p>
                        <p><strong>Обновлен:</strong> 
                            <?php echo isset($student['updated_at']) && $student['updated_at'] ? 
                                date('d.m.Y H:i', strtotime($student['updated_at'])) : 'Никогда'; ?>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <?php if (!empty($specialties) && $student_specialty): ?>
                            <p><strong>ID специальности:</strong> <?php echo $student_specialty['id']; ?></p>
                            <p><strong>Название специальности:</strong> <?php echo htmlspecialchars($student_specialty['name']); ?></p>
                        <?php endif; ?>
                        <p><strong>Логин:</strong> <?php echo htmlspecialchars($student['username']); ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Быстрые действия -->
        <div class="mt-4">
            <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                <a href="student_results.php?id=<?php echo $student['id']; ?>" 
                   class="btn btn-outline-info">
                    <i class="bi bi-bar-chart"></i> Результаты тестов
                </a>
                <a href="student_tests.php?id=<?php echo $student['id']; ?>" 
                   class="btn btn-outline-primary">
                    <i class="bi bi-journal-text"></i> Доступные тесты
                </a>
                <button type="button" class="btn btn-outline-success" id="sendCredentialsBtn">
                    <i class="bi bi-envelope"></i> Отправить данные
                </button>
                <a href="specialties.php" class="btn btn-outline-purple" target="_blank">
                    <i class="bi bi-briefcase"></i> Управление специальностями
                </a>
            </div>
        </div>
    </div>

    <!-- Модальное окно сброса пароля -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Сброс пароля</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Вы уверены, что хотите сбросить пароль студента?</p>
                    <div class="alert alert-warning">
                        <strong><?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name']); ?></strong><br>
                        Логин: <?php echo htmlspecialchars($student['username']); ?><br>
                        Специальность: <?php echo htmlspecialchars($student['specialty'] ?? 'Не указана'); ?>
                    </div>
                    <p>После сброса будет установлен новый случайный пароль.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-danger" id="confirmResetPassword">
                        Сбросить пароль
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Функция для генерации случайного пароля
        function generatePassword(length = 10) {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
            let password = '';
            for (let i = 0; i < length; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            return password;
        }
        
        // Показать/скрыть новый пароль
        document.getElementById('toggleNewPassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('new_password');
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
        
        // Копирование текущего пароля
        document.getElementById('copyPasswordBtn').addEventListener('click', function() {
            const password = '<?php echo htmlspecialchars($student['password']); ?>';
            navigator.clipboard.writeText(password).then(function() {
                // Меняем текст кнопки на короткое время
                const btn = document.getElementById('copyPasswordBtn');
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check"></i> Скопировано!';
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-success');
                
                setTimeout(function() {
                    btn.innerHTML = originalHTML;
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-outline-primary');
                }, 2000);
            }).catch(function(err) {
                alert('Ошибка при копировании: ' + err);
            });
        });
        
        // Использовать текущий пароль
        document.getElementById('useCurrentPasswordBtn').addEventListener('click', function() {
            const password = '<?php echo htmlspecialchars($student['password']); ?>';
            
            // Показываем пароль в поле нового пароля
            const passwordInput = document.getElementById('new_password');
            passwordInput.value = password;
            
            // Показываем пароль
            const toggleBtn = document.getElementById('toggleNewPassword');
            const icon = toggleBtn.querySelector('i');
            passwordInput.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
            
            // Выделяем поле
            passwordInput.focus();
            passwordInput.select();
            
            // Меняем текст кнопки на короткое время
            const btn = document.getElementById('useCurrentPasswordBtn');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check"></i> Пароль вставлен!';
            btn.classList.remove('btn-outline-success');
            btn.classList.add('btn-success');
            
            setTimeout(function() {
                btn.innerHTML = originalHTML;
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-success');
            }, 2000);
        });
        
        // Генерация пароля
        document.getElementById('generatePasswordBtn').addEventListener('click', function() {
            const newPassword = generatePassword(12);
            const passwordInput = document.getElementById('new_password');
            passwordInput.value = newPassword;
            
            // Показываем пароль
            const toggleBtn = document.getElementById('toggleNewPassword');
            const icon = toggleBtn.querySelector('i');
            passwordInput.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
            
            // Выделяем поле
            passwordInput.focus();
            passwordInput.select();
            
            // Показываем сообщение
            alert('Сгенерирован новый пароль: ' + newPassword + '\nСкопируйте его и сообщите студенту.');
        });
        
        // Сброс пароля
        document.getElementById('confirmResetPassword').addEventListener('click', function() {
            // Генерируем новый пароль
            const newPassword = generatePassword(12);
            
            // Устанавливаем в поле формы
            const passwordInput = document.getElementById('new_password');
            passwordInput.value = newPassword;
            
            // Показываем пароль
            const toggleBtn = document.getElementById('toggleNewPassword');
            const icon = toggleBtn.querySelector('i');
            passwordInput.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
            
            // Закрываем модальное окно
            const modal = bootstrap.Modal.getInstance(document.getElementById('resetPasswordModal'));
            modal.hide();
            
            // Фокусируемся на поле с паролем
            passwordInput.focus();
            passwordInput.select();
            
            // Показываем сообщение
            alert('Пароль сброшен!\nНовый пароль: ' + newPassword + '\nНе забудьте сохранить изменения формы.');
        });
        
        // Отправка данных студенту (заглушка)
        document.getElementById('sendCredentialsBtn').addEventListener('click', function() {
            const studentName = '<?php echo htmlspecialchars($student["last_name"] . " " . $student["first_name"]); ?>';
            const username = document.getElementById('username').value;
            const password = document.getElementById('new_password').value || '<?php echo htmlspecialchars($student["password"]); ?>';
            const specialtySelect = document.getElementById('specialty_id');
            const specialtyText = specialtySelect.options[specialtySelect.selectedIndex].text;
            const course = document.getElementById('course_number').value;
            
            const message = `Данные студента ${studentName}:\n\n` +
                           `Логин: ${username}\n` +
                           `Пароль: ${password}\n` +
                           `Курс: ${course}\n` +
                           `Специальность: ${specialtyText}\n\n` +
                           `Эти данные можно скопировать и отправить студенту.`;
            
            alert(message);
        });
        
        // Проверка формы перед отправкой
        document.getElementById('editForm').addEventListener('submit', function(e) {
            const passwordInput = document.getElementById('new_password');
            const passwordValue = passwordInput.value.trim();
            const specialtySelect = document.getElementById('specialty_id');
            const specialtyValue = specialtySelect.value;
            
            if (passwordValue.length > 0 && passwordValue.length < 6) {
                e.preventDefault();
                alert('Пароль должен содержать минимум 6 символов');
                passwordInput.focus();
                return false;
            }
            
            if (!specialtyValue) {
                e.preventDefault();
                alert('Выберите специальность');
                specialtySelect.focus();
                return false;
            }
            
            // Проверка на наличие специальностей в базе данных
            <?php if (empty($specialties)): ?>
                e.preventDefault();
                alert('Нет доступных специальностей. Пожалуйста, добавьте специальности в систему.');
                window.open('specialties.php?action=create', '_blank');
                return false;
            <?php endif; ?>
            
            return true;
        });
        
        // Автоматическое скрытие пароля через 30 секунд
        setTimeout(function() {
            const passwordInput = document.getElementById('new_password');
            if (passwordInput.value.trim() !== '') {
                if (confirm('Пароль все еще виден. Хотите скрыть его?')) {
                    passwordInput.type = 'password';
                    const toggleBtn = document.getElementById('toggleNewPassword');
                    const icon = toggleBtn.querySelector('i');
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            }
        }, 30000);
        
        // Сохранение данных при закрытии страницы
        window.addEventListener('beforeunload', function(e) {
            const form = document.getElementById('editForm');
            const formData = new FormData(form);
            let hasChanges = false;
            
            // Проверяем, были ли изменения
            if (formData.get('last_name') !== '<?php echo htmlspecialchars($student["last_name"]); ?>' ||
                formData.get('first_name') !== '<?php echo htmlspecialchars($student["first_name"]); ?>' ||
                formData.get('course_number') !== '<?php echo $student["course_number"]; ?>' ||
                formData.get('specialty_id') !== '<?php echo $student_specialty ? $student_specialty["id"] : ""; ?>' ||
                formData.get('username') !== '<?php echo htmlspecialchars($student["username"]); ?>' ||
                formData.get('new_password') !== '') {
                hasChanges = true;
            }
            
            if (hasChanges) {
                e.preventDefault();
                e.returnValue = 'У вас есть несохраненные изменения. Вы уверены, что хотите покинуть страницу?';
                return e.returnValue;
            }
        });
        
        // Добавление стиля для кнопки специальностей
        const style = document.createElement('style');
        style.textContent = `
            .btn-outline-purple {
                color: #6f42c1;
                border-color: #6f42c1;
            }
            .btn-outline-purple:hover {
                color: #fff;
                background-color: #6f42c1;
                border-color: #6f42c1;
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>