<?php
require_once '../config/functions.php';
requireAdminLogin();

// Проверяем, что текущий пользователь - супер-админ
if (!isset($_SESSION['is_superadmin']) || !$_SESSION['is_superadmin']) {
    $_SESSION['error'] = 'У вас нет прав для управления администраторами';
    header('Location: index.php');
    exit();
}

// Обработка удаления администратора
if (isset($_GET['delete'])) {
    $admin_id = intval($_GET['delete']);
    
    // Нельзя удалить самого себя
    if ($admin_id == $_SESSION['admin_id']) {
        $_SESSION['error'] = 'Нельзя удалить свою учетную запись';
        header('Location: admins.php');
        exit();
    }
    
    // Нельзя удалить супер-админа
    $stmt = $pdo->prepare("SELECT is_superadmin FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();
    
    if ($admin && $admin['is_superadmin']) {
        $_SESSION['error'] = 'Нельзя удалить супер-администратора';
        header('Location: admins.php');
        exit();
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM admins WHERE id = ?");
        if ($stmt->execute([$admin_id])) {
            $_SESSION['success'] = 'Администратор успешно удален';
        } else {
            $_SESSION['error'] = 'Ошибка при удалении администратора';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка: ' . $e->getMessage();
    }
    
    header('Location: admins.php');
    exit();
}

// Обработка активации/деактивации
if (isset($_GET['toggle'])) {
    $admin_id = intval($_GET['toggle']);
    
    // Нельзя деактивировать самого себя
    if ($admin_id == $_SESSION['admin_id']) {
        $_SESSION['error'] = 'Нельзя деактивировать свою учетную запись';
        header('Location: admins.php');
        exit();
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE admins SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$admin_id]);
        
        $_SESSION['success'] = 'Статус администратора изменен';
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка: ' . $e->getMessage();
    }
    
    header('Location: admins.php');
    exit();
}

// Обработка изменения данных администратора
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin'])) {
    $admin_id = intval($_POST['admin_id']);
    $last_name = trim($_POST['last_name']);
    $first_name = trim($_POST['first_name']);
    $city = trim($_POST['city']);
    $password = trim($_POST['password']);
    
    // Валидация
    $errors = [];
    
    if (empty($last_name) || empty($first_name)) {
        $errors[] = 'Фамилия и имя обязательны';
    }
    
    if (empty($city)) {
        $errors[] = 'Город обязателен';
    }
    
    if (!empty($password) && strlen($password) < 6) {
        $errors[] = 'Пароль должен быть не менее 6 символов';
    }
    
    if (empty($errors)) {
        try {
            if (!empty($password)) {
                // Обновляем с паролем
                $stmt = $pdo->prepare("UPDATE admins SET last_name = ?, first_name = ?, city = ?, password = ? WHERE id = ?");
                $stmt->execute([$last_name, $first_name, $city, $password, $admin_id]);
                $_SESSION['success'] = 'Данные администратора обновлены, пароль изменен';
            } else {
                // Обновляем без пароля
                $stmt = $pdo->prepare("UPDATE admins SET last_name = ?, first_name = ?, city = ? WHERE id = ?");
                $stmt->execute([$last_name, $first_name, $city, $admin_id]);
                $_SESSION['success'] = 'Данные администратора обновлены';
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Ошибка: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errors);
    }
    
    header('Location: admins.php');
    exit();
}

// Обработка назначения/снятия супер-админа
if (isset($_GET['make_super'])) {
    $admin_id = intval($_GET['make_super']);
    
    // Нельзя понизить самого себя
    if ($admin_id == $_SESSION['admin_id'] && isset($_GET['remove'])) {
        $_SESSION['error'] = 'Нельзя понизить свою учетную запись';
        header('Location: admins.php');
        exit();
    }
    
    $is_super = isset($_GET['remove']) ? 0 : 1;
    
    try {
        $stmt = $pdo->prepare("UPDATE admins SET is_superadmin = ? WHERE id = ?");
        $stmt->execute([$is_super, $admin_id]);
        
        $action = $is_super ? 'назначен' : 'понижен до городского';
        $_SESSION['success'] = "Администратор {$action}";
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка: ' . $e->getMessage();
    }
    
    header('Location: admins.php');
    exit();
}

// Получаем всех администраторов
$stmt = $pdo->query("SELECT * FROM admins ORDER BY is_superadmin DESC, last_name, first_name");
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление администраторами</title>
    <link rel="icon" href="/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 20px;
        }
        .header {
            background: linear-gradient(45deg, #0d6efd, #0dcaf0);
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
        .super-badge {
            background-color: #dc3545;
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
        .your-account {
            background-color: rgba(13, 110, 253, 0.1);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Заголовок -->
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-shield-check"></i> Управление администраторами</h1>
                    <p class="mb-0">Всего администраторов: <?php echo count($admins); ?></p>
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

        <!-- Таблица администраторов -->
        <div class="table-container">
            <?php if (empty($admins)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-shield-check display-1 text-muted"></i>
                    <h3 class="mt-3">Администраторы не найдены</h3>
                    <p class="text-muted">Добавьте первого администратора через форму на главной странице.</p>
                    <a href="index.php#createAdminForm" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Добавить администратора
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
                                <th>Тип</th>
                                <th>Логин</th>
                                <th>Пароль</th>
                                <th>Статус</th>
                                <th>Дата регистрации</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admins as $admin): ?>
                                <tr class="<?php echo $admin['id'] == $_SESSION['admin_id'] ? 'your-account' : ''; ?>">
                                    <td><?php echo $admin['id']; ?></td>
                                    <td><?php echo htmlspecialchars($admin['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($admin['first_name']); ?></td>
                                    <td>
                                        <?php if (!empty($admin['city'])): ?>
                                            <span class="city-badge"><?php echo htmlspecialchars($admin['city']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Не указан</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($admin['is_superadmin']): ?>
                                            <span class="super-badge">
                                                <i class="bi bi-shield-check"></i> Супер-админ
                                            </span>
                                        <?php else: ?>
                                            <span class="city-badge">
                                                <i class="bi bi-shield"></i> Городской
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($admin['id'] == $_SESSION['admin_id']): ?>
                                            <small class="text-muted d-block">(Вы)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code><?php echo htmlspecialchars($admin['username']); ?></code>
                                    </td>
                                    <td>
                                        <div class="password-cell" 
                                             title="<?php echo htmlspecialchars($admin['password']); ?>"
                                             onclick="copyToClipboard('<?php echo htmlspecialchars($admin['password']); ?>')">
                                            <?php echo htmlspecialchars($admin['password']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($admin['is_active']): ?>
                                            <span class="status-active">
                                                <i class="bi bi-check-circle"></i> Активен
                                            </span>
                                        <?php else: ?>
                                            <span class="status-inactive">
                                                <i class="bi bi-x-circle"></i> Неактивен
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($admin['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <!-- Кнопка редактирования -->
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-primary" 
                                                    title="Редактировать"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editModal<?php echo $admin['id']; ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            
                                            <!-- Кнопка активации/деактивации -->
                                            <?php if ($admin['id'] != $_SESSION['admin_id']): ?>
                                                <a href="?toggle=<?php echo $admin['id']; ?>" 
                                                   class="btn btn-sm btn-outline-<?php echo $admin['is_active'] ? 'warning' : 'success'; ?>"
                                                   title="<?php echo $admin['is_active'] ? 'Деактивировать' : 'Активировать'; ?>">
                                                    <i class="bi bi-power"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <!-- Кнопка назначения супер-админом -->
                                            <?php if ($admin['id'] != $_SESSION['admin_id']): ?>
                                                <?php if (!$admin['is_superadmin']): ?>
                                                    <a href="?make_super=<?php echo $admin['id']; ?>" 
                                                       class="btn btn-sm btn-outline-danger"
                                                       title="Сделать супер-админом"
                                                       onclick="return confirm('Сделать администратора супер-администратором?')">
                                                        <i class="bi bi-shield-check"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="?make_super=<?php echo $admin['id']; ?>&remove=1" 
                                                       class="btn btn-sm btn-outline-warning"
                                                       title="Сделать городским"
                                                       onclick="return confirm('Понизить до городского администратора?')">
                                                        <i class="bi bi-shield"></i>
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <!-- Кнопка удаления -->
                                            <?php if ($admin['id'] != $_SESSION['admin_id'] && !$admin['is_superadmin']): ?>
                                                <button type="button" 
                                                        class="btn btn-sm btn-outline-danger" 
                                                        title="Удалить"
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#deleteModal<?php echo $admin['id']; ?>">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Модальное окно редактирования -->
                                        <div class="modal fade" id="editModal<?php echo $admin['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-xl">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            <i class="bi bi-pencil"></i> Редактирование администратора
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST" action="">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
                                                            <input type="hidden" name="update_admin" value="1">
                                                            
                                                            <div class="row mb-3">
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="last_name_<?php echo $admin['id']; ?>" class="form-label">Фамилия:</label>
                                                                        <input type="text" class="form-control" 
                                                                               id="last_name_<?php echo $admin['id']; ?>" 
                                                                               name="last_name" 
                                                                               value="<?php echo htmlspecialchars($admin['last_name']); ?>"
                                                                               required>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="first_name_<?php echo $admin['id']; ?>" class="form-label">Имя:</label>
                                                                        <input type="text" class="form-control" 
                                                                               id="first_name_<?php echo $admin['id']; ?>" 
                                                                               name="first_name" 
                                                                               value="<?php echo htmlspecialchars($admin['first_name']); ?>"
                                                                               required>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="row mb-3">
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="city_<?php echo $admin['id']; ?>" class="form-label">Город:</label>
                                                                        <input type="text" class="form-control" 
                                                                               id="city_<?php echo $admin['id']; ?>" 
                                                                               name="city" 
                                                                               value="<?php echo htmlspecialchars($admin['city']); ?>"
                                                                               required>
                                                                        <div class="form-text">Администратор сможет управлять только данными своего города</div>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="username_<?php echo $admin['id']; ?>" class="form-label">Логин (неизменяемый):</label>
                                                                        <input type="text" class="form-control" 
                                                                               id="username_<?php echo $admin['id']; ?>" 
                                                                               value="<?php echo htmlspecialchars($admin['username']); ?>"
                                                                               disabled>
                                                                        <small class="text-muted">Логин нельзя изменить</small>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="row mb-3">
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="current_password_<?php echo $admin['id']; ?>" class="form-label">Текущий пароль:</label>
                                                                        <div class="input-group">
                                                                            <input type="text" class="form-control" 
                                                                                   id="current_password_<?php echo $admin['id']; ?>" 
                                                                                   value="<?php echo htmlspecialchars($admin['password']); ?>"
                                                                                   disabled>
                                                                            <button type="button" class="btn btn-outline-secondary" 
                                                                                    onclick="copyToClipboard('<?php echo htmlspecialchars($admin['password']); ?>')">
                                                                                <i class="bi bi-clipboard"></i>
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <div class="mb-3">
                                                                        <label for="password_<?php echo $admin['id']; ?>" class="form-label">Новый пароль:</label>
                                                                        <div class="input-group">
                                                                            <input type="password" class="form-control" 
                                                                                   id="password_<?php echo $admin['id']; ?>" 
                                                                                   name="password"
                                                                                   placeholder="Оставьте пустым, чтобы не менять">
                                                                            <button type="button" class="btn btn-outline-secondary" 
                                                                                    onclick="togglePasswordVisibility('password_<?php echo $admin['id']; ?>', this)">
                                                                                <i class="bi bi-eye"></i>
                                                                            </button>
                                                                            <button type="button" class="btn btn-outline-secondary" 
                                                                                    onclick="generatePassword('password_<?php echo $admin['id']; ?>')">
                                                                                <i class="bi bi-shuffle"></i>
                                                                            </button>
                                                                        </div>
                                                                        <small class="text-muted">Минимум 6 символов. Оставьте пустым, если не нужно менять</small>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="row">
                                                                <div class="col-md-4">
                                                                    <div class="mb-3">
                                                                        <label class="form-label">Тип:</label>
                                                                        <div>
                                                                            <?php if ($admin['is_superadmin']): ?>
                                                                                <span class="badge bg-danger">
                                                                                    <i class="bi bi-shield-check"></i> Супер-админ
                                                                                </span>
                                                                            <?php else: ?>
                                                                                <span class="badge bg-primary">
                                                                                    <i class="bi bi-shield"></i> Городской
                                                                                </span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <div class="mb-3">
                                                                        <label class="form-label">Статус:</label>
                                                                        <div>
                                                                            <?php if ($admin['is_active']): ?>
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
                                                                <div class="col-md-4">
                                                                    <div class="mb-3">
                                                                        <label class="form-label">Дата регистрации:</label>
                                                                        <div>
                                                                            <?php echo date('d.m.Y H:i', strtotime($admin['created_at'])); ?>
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
                                        <?php if ($admin['id'] != $_SESSION['admin_id'] && !$admin['is_superadmin']): ?>
                                            <div class="modal fade" id="deleteModal<?php echo $admin['id']; ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Подтверждение удаления</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>Вы уверены, что хотите удалить администратора?</p>
                                                            <div class="alert alert-warning">
                                                                <strong><?php echo htmlspecialchars($admin['last_name'] . ' ' . $admin['first_name']); ?></strong><br>
                                                                Город: <?php echo htmlspecialchars($admin['city']); ?><br>
                                                                Тип: <?php echo $admin['is_superadmin'] ? 'Супер-админ' : 'Городской'; ?><br>
                                                                Логин: <?php echo htmlspecialchars($admin['username']); ?><br>
                                                                Статус: <?php echo $admin['is_active'] ? 'Активен' : 'Неактивен'; ?>
                                                            </div>
                                                            <div class="alert alert-danger">
                                                                <i class="bi bi-exclamation-triangle"></i>
                                                                <strong>Внимание!</strong> Это действие нельзя отменить.
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                                                            <a href="admins.php?delete=<?php echo $admin['id']; ?>" 
                                                               class="btn btn-danger">Удалить</a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Статистика -->
                <div class="mt-4">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="alert alert-info">
                                <i class="bi bi-shield-check"></i>
                                <strong>Супер-админы:</strong>
                                <?php 
                                $superCount = 0;
                                foreach ($admins as $admin) {
                                    if ($admin['is_superadmin']) {
                                        $superCount++;
                                    }
                                }
                                echo $superCount;
                                ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="alert alert-primary">
                                <i class="bi bi-shield"></i>
                                <strong>Городские админы:</strong>
                                <?php 
                                $cityCount = 0;
                                foreach ($admins as $admin) {
                                    if (!$admin['is_superadmin']) {
                                        $cityCount++;
                                    }
                                }
                                echo $cityCount;
                                ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle"></i>
                                <strong>Активные:</strong>
                                <?php 
                                $activeCount = 0;
                                foreach ($admins as $admin) {
                                    if ($admin['is_active']) {
                                        $activeCount++;
                                    }
                                }
                                echo $activeCount;
                                ?>
                            </div>
                        </div>
                    </div>
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
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
            let password = '';
            const length = 12;
            
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
            if (form.querySelector('input[name="update_admin"]')) {
                form.addEventListener('submit', function(e) {
                    const lastName = form.querySelector('input[name="last_name"]').value.trim();
                    const firstName = form.querySelector('input[name="first_name"]').value.trim();
                    const city = form.querySelector('input[name="city"]').value.trim();
                    const password = form.querySelector('input[name="password"]').value;
                    
                    let errors = [];
                    
                    if (!lastName) errors.push('Фамилия обязательна');
                    if (!firstName) errors.push('Имя обязательно');
                    if (!city) errors.push('Город обязателен');
                    
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
        
        // Подтверждение действий
        document.querySelectorAll('a[onclick*="return confirm"]').forEach(link => {
            link.addEventListener('click', function(e) {
                const confirmText = this.getAttribute('onclick').match(/confirm\('([^']+)'\)/)[1];
                if (!confirm(confirmText)) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>