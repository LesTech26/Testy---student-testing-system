<?php
require_once '../config/functions.php';
requireAdminLogin();

// Обработка удаления преподавателя
if (isset($_GET['delete'])) {
    $teacher_id = intval($_GET['delete']);
    
    try {
        // Удаляем преподавателя
        $stmt = $pdo->prepare("DELETE FROM teachers WHERE id = ?");
        if ($stmt->execute([$teacher_id])) {
            $_SESSION['success'] = 'Преподаватель успешно удален';
        } else {
            $_SESSION['error'] = 'Ошибка при удалении преподавателя';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка: ' . $e->getMessage();
    }
    
    header('Location: teachers.php');
    exit();
}

// Обработка активации/деактивации
if (isset($_GET['toggle'])) {
    $teacher_id = intval($_GET['toggle']);
    
    try {
        $stmt = $pdo->prepare("UPDATE teachers SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$teacher_id]);
        
        $_SESSION['success'] = 'Статус преподавателя изменен';
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка: ' . $e->getMessage();
    }
    
    header('Location: teachers.php');
    exit();
}

// Обработка изменения данных преподавателя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_teacher'])) {
    $teacher_id = intval($_POST['teacher_id']);
    $last_name = trim($_POST['last_name']);
    $first_name = trim($_POST['first_name']);
    $city = trim($_POST['city']);
    $password = trim($_POST['password']);
    
    // Валидация
    $errors = [];
    
    if (empty($last_name) || empty($first_name)) {
        $errors[] = 'Фамилия и имя обязательны';
    }
    
    if (!empty($password) && strlen($password) < 6) {
        $errors[] = 'Пароль должен быть не менее 6 символов';
    }
    
    if (empty($errors)) {
        try {
            if (!empty($password)) {
                // Обновляем с паролем
                $stmt = $pdo->prepare("UPDATE teachers SET last_name = ?, first_name = ?, city = ?, password = ? WHERE id = ?");
                $stmt->execute([$last_name, $first_name, $city, $password, $teacher_id]);
                $_SESSION['success'] = 'Данные преподавателя обновлены, пароль изменен';
            } else {
                // Обновляем без пароля
                $stmt = $pdo->prepare("UPDATE teachers SET last_name = ?, first_name = ?, city = ? WHERE id = ?");
                $stmt->execute([$last_name, $first_name, $city, $teacher_id]);
                $_SESSION['success'] = 'Данные преподавателя обновлены';
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Ошибка: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errors);
    }
    
    header('Location: teachers.php');
    exit();
}

// Получаем всех преподавателей
$stmt = $pdo->query("SELECT * FROM teachers ORDER BY last_name, first_name");
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление преподавателями</title>
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
        .table-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .status-active {
            color: #198754;
        }
        .status-inactive {
            color: #dc3545;
        }
        .city-badge {
            background-color: #6f42c1;
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
        }
        .password-cell {
            font-family: monospace;
            font-size: 0.9rem;
            color: #666;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }
        .password-cell:hover {
            color: #333;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        .modal-xl {
            max-width: 800px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Заголовок -->
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-person-badge"></i> Управление преподавателями</h1>
                    <p class="mb-0">Всего преподавателей: <?php echo count($teachers); ?></p>
                </div>
                <div>
                    <a href="index.php" class="btn btn-light">
                        <i class="bi bi-arrow-left"></i> Назад
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

        <!-- Таблица преподавателей -->
        <div class="table-container">
            <?php if (empty($teachers)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-person-badge display-1 text-muted"></i>
                    <h3 class="mt-3">Преподаватели не найдены</h3>
                    <p class="text-muted">Добавьте первого преподавателя через форму на главной странице.</p>
                    <a href="index.php#createTeacherForm" class="btn btn-info">
                        <i class="bi bi-person-plus"></i> Добавить преподавателя
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
                                <th>Город</th>
                                <th>Логин</th>
                                <th>Пароль</th>
                                <th>Статус</th>
                                <th>Дата регистрации</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teachers as $teacher): ?>
                                <tr>
                                    <td><?php echo $teacher['id']; ?></td>
                                    <td><?php echo htmlspecialchars($teacher['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['first_name']); ?></td>
                                    <td>
                                        <?php if (!empty($teacher['city'])): ?>
                                            <span class="city-badge"><?php echo htmlspecialchars($teacher['city']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Не указан</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code><?php echo htmlspecialchars($teacher['username']); ?></code>
                                    </td>
                                    <td>
                                        <div class="password-cell" 
                                             title="<?php echo htmlspecialchars($teacher['password']); ?>"
                                             onclick="copyToClipboard('<?php echo htmlspecialchars($teacher['password']); ?>')">
                                            <?php echo htmlspecialchars($teacher['password']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($teacher['is_active']): ?>
                                            <span class="status-active">
                                                <i class="bi bi-check-circle"></i> Активен
                                            </span>
                                        <?php else: ?>
                                            <span class="status-inactive">
                                                <i class="bi bi-x-circle"></i> Неактивен
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($teacher['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-primary" 
                                                    title="Редактировать"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editModal<?php echo $teacher['id']; ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <a href="?toggle=<?php echo $teacher['id']; ?>" 
                                               class="btn btn-sm btn-outline-<?php echo $teacher['is_active'] ? 'warning' : 'success'; ?>"
                                               title="<?php echo $teacher['is_active'] ? 'Деактивировать' : 'Активировать'; ?>">
                                                <i class="bi bi-power"></i>
                                            </a>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-danger" 
                                                    title="Удалить"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#deleteModal<?php echo $teacher['id']; ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                        
                                        <!-- Модальное окно редактирования -->
                                        <div class="modal fade" id="editModal<?php echo $teacher['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-xl">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            <i class="bi bi-pencil"></i> Редактирование преподавателя
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST" action="">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="teacher_id" value="<?php echo $teacher['id']; ?>">
                                                            <input type="hidden" name="update_teacher" value="1">
                                                            
                                                            <div class="row mb-3">
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="last_name_<?php echo $teacher['id']; ?>" class="form-label">Фамилия:</label>
                                                                        <input type="text" class="form-control" 
                                                                               id="last_name_<?php echo $teacher['id']; ?>" 
                                                                               name="last_name" 
                                                                               value="<?php echo htmlspecialchars($teacher['last_name']); ?>"
                                                                               required>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="first_name_<?php echo $teacher['id']; ?>" class="form-label">Имя:</label>
                                                                        <input type="text" class="form-control" 
                                                                               id="first_name_<?php echo $teacher['id']; ?>" 
                                                                               name="first_name" 
                                                                               value="<?php echo htmlspecialchars($teacher['first_name']); ?>"
                                                                               required>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="row mb-3">
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="city_<?php echo $teacher['id']; ?>" class="form-label">Город:</label>
                                                                        <input type="text" class="form-control" 
                                                                               id="city_<?php echo $teacher['id']; ?>" 
                                                                               name="city" 
                                                                               value="<?php echo htmlspecialchars($teacher['city']); ?>"
                                                                               placeholder="Введите город">
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="username_<?php echo $teacher['id']; ?>" class="form-label">Логин (неизменяемый):</label>
                                                                        <input type="text" class="form-control" 
                                                                               id="username_<?php echo $teacher['id']; ?>" 
                                                                               value="<?php echo htmlspecialchars($teacher['username']); ?>"
                                                                               disabled>
                                                                        <small class="text-muted">Логин нельзя изменить</small>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="row mb-3">
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="current_password_<?php echo $teacher['id']; ?>" class="form-label">Текущий пароль:</label>
                                                                        <div class="input-group">
                                                                            <input type="text" class="form-control" 
                                                                                   id="current_password_<?php echo $teacher['id']; ?>" 
                                                                                   value="<?php echo htmlspecialchars($teacher['password']); ?>"
                                                                                   disabled>
                                                                            <button type="button" class="btn btn-outline-secondary" 
                                                                                    onclick="copyToClipboard('<?php echo htmlspecialchars($teacher['password']); ?>')">
                                                                                <i class="bi bi-clipboard"></i>
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="password_<?php echo $teacher['id']; ?>" class="form-label">Новый пароль:</label>
                                                                        <div class="input-group">
                                                                            <input type="password" class="form-control" 
                                                                                   id="password_<?php echo $teacher['id']; ?>" 
                                                                                   name="password"
                                                                                   placeholder="Оставьте пустым, чтобы не менять">
                                                                            <button type="button" class="btn btn-outline-secondary" 
                                                                                    onclick="togglePasswordVisibility('password_<?php echo $teacher['id']; ?>', this)">
                                                                                <i class="bi bi-eye"></i>
                                                                            </button>
                                                                            <button type="button" class="btn btn-outline-secondary" 
                                                                                    onclick="generatePassword('password_<?php echo $teacher['id']; ?>')">
                                                                                <i class="bi bi-shuffle"></i>
                                                                            </button>
                                                                        </div>
                                                                        <small class="text-muted">Минимум 6 символов. Оставьте пустым, если не нужно менять</small>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="row">
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label class="form-label">Статус:</label>
                                                                        <div>
                                                                            <?php if ($teacher['is_active']): ?>
                                                                                <span class="badge bg-success">
                                                                                    <i class="bi bi-check-circle"></i> Активен
                                                                                </span>
                                                                            <?php else: ?>
                                                                                <span class="badge bg-danger">
                                                                                    <i class="bi bi-x-circle"></i> Неактивен
                                                                                </span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label class="form-label">Дата регистрации:</label>
                                                                        <div>
                                                                            <?php echo date('d.m.Y H:i', strtotime($teacher['created_at'])); ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                                                            <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Модальное окно подтверждения удаления -->
                                        <div class="modal fade" id="deleteModal<?php echo $teacher['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Подтверждение удаления</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p>Вы уверены, что хотите удалить преподавателя?</p>
                                                        <div class="alert alert-warning">
                                                            <strong><?php echo htmlspecialchars($teacher['last_name'] . ' ' . $teacher['first_name']); ?></strong><br>
                                                            <?php if (!empty($teacher['city'])): ?>
                                                                Город: <?php echo htmlspecialchars($teacher['city']); ?><br>
                                                            <?php endif; ?>
                                                            Логин: <?php echo htmlspecialchars($teacher['username']); ?><br>
                                                            Статус: <?php echo $teacher['is_active'] ? 'Активен' : 'Неактивен'; ?>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                                                        <a href="teachers.php?delete=<?php echo $teacher['id']; ?>" 
                                                           class="btn btn-danger">Удалить</a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Статистика -->
                <div class="mt-4">
                    <p class="text-muted">
                        Показано <?php echo count($teachers); ?> преподавателей
                    </p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Кнопка назад -->
        <div class="mt-4 text-center">
            <a href="index.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Вернуться в админ-панель
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Функция для копирования текста в буфер обмена
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('Текст скопирован в буфер обмена: ' + text);
            }).catch(err => {
                console.error('Ошибка при копировании: ', err);
                // Fallback для старых браузеров
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    alert('Текст скопирован в буфер обмена: ' + text);
                } catch (err) {
                    console.error('Fallback ошибка: ', err);
                    alert('Не удалось скопировать текст');
                }
                document.body.removeChild(textArea);
            });
        }
        
        // Показать/скрыть пароль
        function togglePasswordVisibility(inputId, button) {
            const input = document.getElementById(inputId);
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }
        
        // Генерация случайного пароля
        function generatePassword(inputId) {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            let password = '';
            const length = 10;
            
            for (let i = 0; i < length; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            
            const input = document.getElementById(inputId);
            input.value = password;
            input.type = 'text';
            
            // Обновляем иконку рядом, если есть
            const button = input.closest('.input-group').querySelector('button[onclick*="togglePasswordVisibility"]');
            if (button) {
                const icon = button.querySelector('i');
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            }
            
            // Выделяем пароль
            input.focus();
            input.select();
            
            // Показываем сообщение
            alert('Сгенерирован новый пароль: ' + password);
        }
        
        // Показ полного пароля при наведении на ячейку
        document.addEventListener('DOMContentLoaded', function() {
            const passwordCells = document.querySelectorAll('.password-cell');
            
            passwordCells.forEach(cell => {
                cell.addEventListener('mouseenter', function() {
                    const password = this.getAttribute('title');
                    const originalText = this.innerText;
                    
                    // Если пароль обрезан, показываем его полностью при наведении
                    if (originalText.length < password.length) {
                        this.setAttribute('data-original-text', originalText);
                        this.innerText = password;
                    }
                });
                
                cell.addEventListener('mouseleave', function() {
                    const originalText = this.getAttribute('data-original-text');
                    if (originalText) {
                        this.innerText = originalText;
                        this.removeAttribute('data-original-text');
                    }
                });
            });
        });
        
        // Валидация формы редактирования
        document.querySelectorAll('form').forEach(form => {
            if (form.querySelector('input[name="update_teacher"]')) {
                form.addEventListener('submit', function(e) {
                    const lastName = form.querySelector('input[name="last_name"]').value.trim();
                    const firstName = form.querySelector('input[name="first_name"]').value.trim();
                    const password = form.querySelector('input[name="password"]').value;
                    
                    let errors = [];
                    
                    if (!lastName) errors.push('Фамилия обязательна');
                    if (!firstName) errors.push('Имя обязательно');
                    
                    if (password && password.length < 6) {
                        errors.push('Пароль должен быть не менее 6 символов');
                    }
                    
                    if (errors.length > 0) {
                        e.preventDefault();
                        alert('Ошибки в форме:\n' + errors.join('\n'));
                        return false;
                    }
                    
                    return true;
                });
            }
        });
    </script>
</body>
</html>