<?php
require_once '../config/functions.php';
requireAdminLogin();

// Обработка действий
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $name = trim($_POST['name']);
                $code = trim($_POST['code']);
                $description = trim($_POST['description']);

                $stmt = $pdo->prepare("INSERT INTO specialties (name, code, description) VALUES (?, ?, ?)");
                $stmt->execute([$name, $code, $description]);

                header('Location: specialties.php?success=created');
                exit();
            }
            break;

        case 'edit':
            if (!isset($_GET['id'])) {
                header('Location: specialties.php');
                exit();
            }

            $id = intval($_GET['id']);

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $name = trim($_POST['name']);
                $code = trim($_POST['code']);
                $description = trim($_POST['description']);

                $stmt = $pdo->prepare("UPDATE specialties SET name = ?, code = ?, description = ? WHERE id = ?");
                $stmt->execute([$name, $code, $description, $id]);

                header('Location: specialties.php?success=updated');
                exit();
            }

            $stmt = $pdo->prepare("SELECT * FROM specialties WHERE id = ?");
            $stmt->execute([$id]);
            $specialty = $stmt->fetch();

            if (!$specialty) {
                header('Location: specialties.php');
                exit();
            }
            break;

        case 'delete':
            if (isset($_GET['id'])) {
                $id = intval($_GET['id']);

                // Проверяем, есть ли тесты, привязанные к специальности
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tests WHERE specialty_id = ?");
                $stmt->execute([$id]);
                $testsCount = $stmt->fetch()['count'];

                if ($testsCount > 0) {
                    header('Location: specialties.php?error=specialty_has_tests');
                    exit();
                }

                $stmt = $pdo->prepare("DELETE FROM specialties WHERE id = ?");
                $stmt->execute([$id]);

                header('Location: specialties.php?success=deleted');
                exit();
            }
            break;
    }
}

// Получение всех специальностей
$stmt = $pdo->prepare("SELECT * FROM specialties ORDER BY name");
$stmt->execute();
$specialties = $stmt->fetchAll();

// Определяем текущее действие
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление специальностями</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="icon" href="/logo.png">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 20px;
        }

        .header {
            background: linear-gradient(45deg, #6f42c1, #9b6bff);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .table-responsive {
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            padding: 20px;
        }

        .action-buttons {
            margin-bottom: 20px;
        }

        .card {
            border: none;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }

        .specialty-card {
            background: #f8f9fa;
            border-left: 4px solid #6f42c1;
            margin-bottom: 10px;
            transition: all 0.3s;
        }

        .specialty-card:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }

        .code-badge {
            background: linear-gradient(45deg, #6f42c1, #9b6bff);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Заголовок с навигацией -->
        <div class="header mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-briefcase"></i> Управление специальностями</h1>
                    <p class="mb-0">Администратор: <?php echo $_SESSION['admin_username']; ?></p>
                </div>
                <div>
                    <a href="tests.php" class="btn btn-light me-2">
                        <i class="bi bi-arrow-left"></i> К тестам
                    </a>
                    <a href="logout.php" class="btn btn-outline-light">
                        <i class="bi bi-box-arrow-right"></i> Выйти
                    </a>
                </div>
            </div>
        </div>

        <!-- Навигационные кнопки -->
        <div class="action-buttons mb-4">
            <div class="d-flex justify-content-between">
                <div>
                    <a href="index.php" class="btn btn-outline-primary me-2">
                        <i class="bi bi-house"></i> Главная
                    </a>
                    <a href="tests.php" class="btn btn-outline-info me-2">
                        <i class="bi bi-journal-text"></i> Тесты
                    </a>
                </div>
                <div>
                    <a href="?action=create" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Добавить специальность
                    </a>
                </div>
            </div>
        </div>

        <!-- Сообщения об успехе/ошибке -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php
                switch ($_GET['success']) {
                    case 'created':
                        echo 'Специальность успешно создана!';
                        break;
                    case 'updated':
                        echo 'Специальность успешно обновлена!';
                        break;
                    case 'deleted':
                        echo 'Специальность успешно удалена!';
                        break;
                }
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php
                switch ($_GET['error']) {
                    case 'specialty_has_tests':
                        echo 'Нельзя удалить специальность, к которой привязаны тесты!';
                        break;
                }
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Основное содержимое -->
        <?php if ($action === 'list'): ?>
            <?php if (empty($specialties)): ?>
                <div class="alert alert-info text-center">
                    <i class="bi bi-info-circle fs-4"></i>
                    <h4 class="mt-2">Нет созданных специальностей</h4>
                    <p>Начните с создания первой специальности</p>
                    <a href="?action=create" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Добавить специальность
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Название</th>
                                <th>Код</th>
                                <th>Описание</th>
                                <th>Дата создания</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($specialties as $specialty): ?>
                                <tr class="specialty-card">
                                    <td><?php echo $specialty['id']; ?></td>
                                    <td>
                                        <strong><?php echo escape($specialty['name']); ?></strong>
                                    </td>
                                    <td>
                                        <?php if (!empty($specialty['code'])): ?>
                                            <span class="code-badge"><?php echo escape($specialty['code']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo escape($specialty['description']); ?></td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($specialty['created_at'])); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="?action=edit&id=<?php echo $specialty['id']; ?>"
                                                class="btn btn-outline-primary" title="Редактировать">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="?action=delete&id=<?php echo $specialty['id']; ?>"
                                                class="btn btn-outline-danger"
                                                onclick="return confirm('Вы уверены, что хотите удалить эту специальность?')"
                                                title="Удалить">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php elseif ($action === 'create' || $action === 'edit'): ?>
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h3 class="mb-0">
                                <i class="bi bi-briefcase"></i>
                                <?php echo $action === 'create' ? 'Добавление специальности' : 'Редактирование специальности'; ?>
                            </h3>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="name" class="form-label">Название специальности:</label>
                                    <input type="text" class="form-control" id="name" name="name" required
                                        placeholder="Например: Информационные технологии"
                                        value="<?php echo isset($specialty) ? escape($specialty['name']) : ''; ?>">
                                </div>

                                <div class="mb-3">
                                    <label for="code" class="form-label">Код специальности:</label>
                                    <input type="text" class="form-control" id="code" name="code"
                                        placeholder="Например: IT, ECON, MNG"
                                        value="<?php echo isset($specialty) ? escape($specialty['code']) : ''; ?>">
                                    <small class="text-muted">Короткий код для идентификации (опционально)</small>
                                </div>

                                <div class="mb-3">
                                    <label for="description" class="form-label">Описание:</label>
                                    <textarea class="form-control" id="description" name="description" rows="3"
                                        placeholder="Опишите специальность..."><?php echo isset($specialty) ? escape($specialty['description']) : ''; ?></textarea>
                                    <small class="text-muted">Краткое описание специальности (опционально)</small>
                                </div>

                                <div class="d-flex justify-content-between mt-4">
                                    <a href="specialties.php" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Назад к списку
                                    </a>
                                    <button type="submit" class="btn btn-primary px-4">
                                        <?php if ($action === 'create'): ?>
                                            <i class="bi bi-plus-circle"></i> Добавить
                                        <?php else: ?>
                                            <i class="bi bi-check-circle"></i> Сохранить
                                        <?php endif; ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Навигация внизу -->
        <div class="mt-4 pt-3 border-top">
            <div class="row">
                <div class="col-md-4 mb-2">
                    <a href="index.php" class="btn btn-outline-primary w-100">
                        <i class="bi bi-house"></i> Главная
                    </a>
                </div>
                <div class="col-md-4 mb-2">
                    <a href="tests.php" class="btn btn-outline-info w-100">
                        <i class="bi bi-journal-text"></i> Тесты
                    </a>
                </div>
                <div class="col-md-4 mb-2">
                    <a href="specialties.php" class="btn btn-primary w-100">
                        <i class="bi bi-briefcase"></i> Специальности
                    </a>
                </div>
            </div>
        </div>

        <!-- Информация о системе -->
        <div class="mt-4 text-center text-muted">
            <small>
                <i class="bi bi-info-circle"></i> Всего специальностей: <?php echo count($specialties); ?> |
                Администратор: <?php echo $_SESSION['admin_username']; ?> |
                <a href="logout.php" class="text-decoration-none">Выйти</a>
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Подтверждение удаления
        document.addEventListener('DOMContentLoaded', function () {
            const deleteButtons = document.querySelectorAll('a[href*="action=delete"]');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function (e) {
                    if (!confirm('Вы уверены, что хотите удалить эту специальность?')) {
                        e.preventDefault();
                    }
                });
            });
        });
    </script>
</body>

</html>