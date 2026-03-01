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

// Получаем список тестов преподавателя (из его города)
$stmt = $pdo->prepare("SELECT id, title FROM tests WHERE city IS NULL OR city = ? ORDER BY title");
$stmt->execute([$teacher_city]);
$allTests = $stmt->fetchAll();

// Если выбран тест
$selectedTestId = isset($_GET['test_id']) ? intval($_GET['test_id']) : null;

if ($selectedTestId) {
    // Проверяем доступ к тесту
    $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ? AND (city IS NULL OR city = ?)");
    $stmt->execute([$selectedTestId, $teacher_city]);
    $test = $stmt->fetch();
    
    if (!$test) {
        header('Location: questions.php?error=access_denied');
        exit();
    }
    
    // Получаем вопросы выбранного теста
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE test_id = ? ORDER BY id");
    $stmt->execute([$selectedTestId]);
    $questions = $stmt->fetchAll();
}

// Обработка действий
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedTestId) {
                // Проверяем доступ к тесту
                $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ? AND (city IS NULL OR city = ?)");
                $stmt->execute([$selectedTestId, $teacher_city]);
                $test = $stmt->fetch();
                
                if (!$test) {
                    header('Location: questions.php?error=access_denied');
                    exit();
                }
                
                $question_text = trim($_POST['question_text']);
                $question_type = 'multiple_choice'; // Всегда multiple_choice
                $question_image = '';
                
                // Обработка загрузки изображения
                if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = '../assets/uploads/questions/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $fileName = uniqid() . '_' . basename($_FILES['question_image']['name']);
                    $uploadFile = $uploadDir . $fileName;
                    
                    // Проверяем тип файла
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $fileType = mime_content_type($_FILES['question_image']['tmp_name']);
                    
                    if (in_array($fileType, $allowedTypes) && move_uploaded_file($_FILES['question_image']['tmp_name'], $uploadFile)) {
                        $question_image = 'assets/uploads/questions/' . $fileName;
                    }
                }
                
                $option_a = trim($_POST['option_a']);
                $option_b = trim($_POST['option_b']);
                $option_c = trim($_POST['option_c']);
                $option_d = trim($_POST['option_d']);
                $correct_answer = $_POST['correct_answer'];
                
                $stmt = $pdo->prepare("INSERT INTO questions (test_id, question_text, question_image, question_type, option_a, option_b, option_c, option_d, correct_answer) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$selectedTestId, $question_text, $question_image, $question_type, $option_a, $option_b, $option_c, $option_d, $correct_answer]);
                
                header("Location: questions.php?test_id=$selectedTestId&success=question_created");
                exit();
            }
            break;
            
        case 'edit':
            if (!isset($_GET['id'])) {
                header("Location: questions.php?test_id=$selectedTestId");
                exit();
            }
            
            $id = intval($_GET['id']);
            
            // Проверяем доступ к вопросу через тест
            $stmt = $pdo->prepare("
                SELECT q.* 
                FROM questions q 
                JOIN tests t ON q.test_id = t.id 
                WHERE q.id = ? AND (t.city IS NULL OR t.city = ?)
            ");
            $stmt->execute([$id, $teacher_city]);
            $question = $stmt->fetch();
            
            if (!$question) {
                header("Location: questions.php?test_id=$selectedTestId&error=access_denied");
                exit();
            }
            
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $question_text = trim($_POST['question_text']);
                $question_type = 'multiple_choice'; // Всегда multiple_choice
                
                // Получаем текущий вопрос для проверки изображения
                $question_image = $question['question_image'];
                
                // Обработка загрузки нового изображения
                if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = '../assets/uploads/questions/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $fileName = uniqid() . '_' . basename($_FILES['question_image']['name']);
                    $uploadFile = $uploadDir . $fileName;
                    
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $fileType = mime_content_type($_FILES['question_image']['tmp_name']);
                    
                    if (in_array($fileType, $allowedTypes) && move_uploaded_file($_FILES['question_image']['tmp_name'], $uploadFile)) {
                        // Удаляем старое изображение если оно есть
                        if ($question_image && file_exists('../' . $question_image)) {
                            unlink('../' . $question_image);
                        }
                        $question_image = 'assets/uploads/questions/' . $fileName;
                    }
                }
                
                // Удаление изображения
                if (isset($_POST['delete_image']) && $_POST['delete_image'] == '1' && $question_image) {
                    if (file_exists('../' . $question_image)) {
                        unlink('../' . $question_image);
                    }
                    $question_image = null;
                }
                
                $option_a = trim($_POST['option_a']);
                $option_b = trim($_POST['option_b']);
                $option_c = trim($_POST['option_c']);
                $option_d = trim($_POST['option_d']);
                $correct_answer = $_POST['correct_answer'];
                
                $stmt = $pdo->prepare("UPDATE questions SET question_text = ?, question_image = ?, question_type = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct_answer = ? WHERE id = ?");
                $stmt->execute([$question_text, $question_image, $question_type, $option_a, $option_b, $option_c, $option_d, $correct_answer, $id]);
                
                header("Location: questions.php?test_id=$selectedTestId&success=question_updated");
                exit();
            }
            break;
            
        case 'delete':
            if (isset($_GET['id']) && $selectedTestId) {
                $id = intval($_GET['id']);
                
                // Проверяем доступ к вопросу через тест
                $stmt = $pdo->prepare("
                    SELECT q.* 
                    FROM questions q 
                    JOIN tests t ON q.test_id = t.id 
                    WHERE q.id = ? AND (t.city IS NULL OR t.city = ?)
                ");
                $stmt->execute([$id, $teacher_city]);
                $question = $stmt->fetch();
                
                if (!$question) {
                    header("Location: questions.php?test_id=$selectedTestId&error=access_denied");
                    exit();
                }
                
                // Получаем информацию о вопросе для удаления изображения
                if ($question['question_image'] && file_exists('../' . $question['question_image'])) {
                    unlink('../' . $question['question_image']);
                }
                
                $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ?");
                $stmt->execute([$id]);
                
                header("Location: questions.php?test_id=$selectedTestId&success=question_deleted");
                exit();
            }
            break;
    }
}

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление вопросами - Преподаватель</title>
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
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .question-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            transition: transform 0.3s, box-shadow 0.3s;
            border-left: 4px solid #27ae60;
        }
        .question-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        .option-correct {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 5px;
        }
        .question-image {
            max-width: 100%;
            max-height: 300px;
            object-fit: contain;
            margin-bottom: 15px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .image-preview-container {
            position: relative;
            display: inline-block;
            margin-bottom: 15px;
        }
        .delete-image-btn {
            position: absolute;
            top: -10px;
            right: -10px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: none;
            font-size: 12px;
            transition: background 0.3s;
        }
        .delete-image-btn:hover {
            background: #c82333;
        }
        .type-badge {
            font-size: 0.75em;
            padding: 3px 8px;
            background-color: #0dcaf0;
        }
        .city-badge {
            background: linear-gradient(45deg, #20c997, #17a2b8);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-left: 5px;
        }
        .test-select-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            font-weight: 600;
        }
        .form-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .form-card .card-header {
            background: linear-gradient(45deg, #0d6efd, #0dcaf0);
            color: white;
            border-radius: 10px 10px 0 0 !important;
        }
        .btn-group-sm .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        .preview-image-container {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            text-align: center;
            background: #f8f9fa;
        }
        .question-text {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 15px;
        }
        .option-label {
            font-weight: 600;
            color: #495057;
            margin-right: 5px;
        }
        .navigation-buttons {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
        }
        .stats-badge {
            background: linear-gradient(45deg, #6f42c1, #9b6bff);
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.9rem;
        }
        .action-buttons-container {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }
        .empty-state i {
            font-size: 4rem;
            color: #6c757d;
            margin-bottom: 20px;
        }
        .form-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .form-section h5 {
            color: #495057;
            margin-bottom: 15px;
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 10px;
        }
        .required-field::after {
            content: " *";
            color: #dc3545;
        }
        .alert-custom {
            border-radius: 8px;
            border-left: 4px solid;
        }
        .alert-success {
            border-left-color: #198754;
        }
        .alert-danger {
            border-left-color: #dc3545;
        }
        .alert-info {
            border-left-color: #0dcaf0;
        }
        .question-counter {
            background: #e9ecef;
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 0.85rem;
            color: #6c757d;
            margin-left: 10px;
        }
        @media (max-width: 768px) {
            .header {
                padding: 15px;
            }
            .question-card {
                margin-bottom: 15px;
            }
            .action-buttons-container {
                padding: 10px;
            }
        }
        /* Скрываем переключатель типа вопроса */
        .form-switch {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Заголовок -->
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-question-circle"></i> Управление вопросами</h1>
                    <p class="mb-0">Преподаватель: <?php echo $_SESSION['teacher']['last_name'] . ' ' . $_SESSION['teacher']['first_name']; ?> 
                    <?php if ($teacher_city): ?>
                        <span class="city-badge">Город: <?php echo htmlspecialchars($teacher_city); ?></span>
                    <?php endif; ?>
                    </p>
                </div>
                <div>
                    <a href="index.php" class="btn btn-light me-2">
                        <i class="bi bi-house"></i> Главная
                    </a>
                    <a href="logout.php" class="btn btn-outline-light">
                        <i class="bi bi-box-arrow-right"></i> Выйти
                    </a>
                </div>
            </div>
        </div>

        <!-- Навигация -->
        <div class="action-buttons-container mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex flex-wrap gap-2">
                    <a href="index.php" class="btn btn-outline-primary">
                        <i class="bi bi-house"></i> Главная
                    </a>
                    <a href="tests.php" class="btn btn-outline-secondary">
                        <i class="bi bi-journal-text"></i> Тесты
                    </a>
                    <a href="results.php" class="btn btn-outline-info">
                        <i class="bi bi-bar-chart"></i> Результаты
                    </a>
                </div>
                <div>
                    <?php if ($selectedTestId && $action === 'list'): ?>
                        <a href="?action=create&test_id=<?php echo $selectedTestId; ?>" class="btn btn-success">
                            <i class="bi bi-plus-circle"></i> Добавить вопрос
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Сообщения -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
                <?php
                switch ($_GET['success']) {
                    case 'question_created': echo '<i class="bi bi-check-circle"></i> Вопрос успешно создан!'; break;
                    case 'question_updated': echo '<i class="bi bi-check-circle"></i> Вопрос успешно обновлен!'; break;
                    case 'question_deleted': echo '<i class="bi bi-check-circle"></i> Вопрос успешно удален!'; break;
                }
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
                <?php
                switch ($_GET['error']) {
                    case 'access_denied': echo '<i class="bi bi-exclamation-triangle"></i> Доступ запрещен!'; break;
                }
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Выбор теста -->
        <div class="test-select-card mb-4">
            <h5 class="mb-3"><i class="bi bi-filter"></i> Выберите тест для работы с вопросами</h5>
            <form method="GET" class="row g-3">
                <div class="col-md-8">
                    <select class="form-select" id="test_id" name="test_id" onchange="this.form.submit()">
                        <option value="">-- Выберите тест для просмотра вопросов --</option>
                        <?php foreach ($allTests as $testItem): ?>
                            <option value="<?php echo $testItem['id']; ?>" 
                                    <?php echo $selectedTestId == $testItem['id'] ? 'selected' : ''; ?>>
                                <?php echo escape($testItem['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <a href="tests.php" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-arrow-left"></i> К управлению тестами
                    </a>
                </div>
            </form>
        </div>

        <?php if ($selectedTestId): ?>
            <?php if ($action === 'list'): ?>
                <div class="alert alert-info alert-custom mb-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0">
                                <i class="bi bi-journal-text"></i> Тест: <?php echo escape($test['title']); ?>
                                <span class="stats-badge ms-2">Вопросов: <?php echo count($questions); ?></span>
                            </h5>
                            <?php if ($test['description']): ?>
                                <p class="mb-0 mt-2"><small><?php echo escape($test['description']); ?></small></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <a href="tests.php?action=edit&id=<?php echo $test['id']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i> Редактировать тест
                            </a>
                        </div>
                    </div>
                </div>
                
                <?php if (empty($questions)): ?>
                    <div class="empty-state">
                        <i class="bi bi-question-circle"></i>
                        <h4 class="mt-3">В этом тесте пока нет вопросов</h4>
                        <p class="text-muted mb-4">Добавьте первый вопрос для этого теста</p>
                        <a href="?action=create&test_id=<?php echo $selectedTestId; ?>" class="btn btn-success btn-lg">
                            <i class="bi bi-plus-circle"></i> Добавить вопрос
                        </a>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($questions as $index => $q): ?>
                            <div class="col-md-6 mb-4">
                                <div class="card question-card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="fw-bold">Вопрос #<?php echo $index + 1; ?></span>
                                            <span class="question-counter">ID: <?php echo $q['id']; ?></span>
                                        </div>
                                        <div class="btn-group btn-group-sm">
                                            <a href="?action=edit&id=<?php echo $q['id']; ?>&test_id=<?php echo $selectedTestId; ?>" 
                                               class="btn btn-outline-primary" title="Редактировать">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="?action=delete&id=<?php echo $q['id']; ?>&test_id=<?php echo $selectedTestId; ?>" 
                                               class="btn btn-outline-danger" title="Удалить"
                                               onclick="return confirm('Вы уверены, что хотите удалить этот вопрос?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <!-- Изображение вопроса -->
                                        <?php if ($q['question_image']): ?>
                                            <div class="text-center mb-3">
                                                <img src="../<?php echo $q['question_image']; ?>" 
                                                     alt="Изображение вопроса" 
                                                     class="question-image">
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Текст вопроса -->
                                        <p class="question-text"><?php echo nl2br(escape($q['question_text'])); ?></p>
                                        
                                        <!-- Тип вопроса -->
                                        <div class="mb-3">
                                            <span class="badge bg-info type-badge">
                                                Варианты ответов
                                            </span>
                                        </div>
                                        
                                        <!-- Варианты ответов -->
                                        <div class="mb-2">
                                            <div class="form-check <?php echo $q['correct_answer'] == 'A' ? 'option-correct' : ''; ?> p-2 rounded">
                                                <label class="form-check-label">
                                                    <span class="option-label">A:</span> <?php echo escape($q['option_a']); ?>
                                                    <?php if ($q['correct_answer'] == 'A'): ?>
                                                        <span class="badge bg-success ms-2">
                                                            <i class="bi bi-check-circle"></i> Правильный
                                                        </span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-2">
                                            <div class="form-check <?php echo $q['correct_answer'] == 'B' ? 'option-correct' : ''; ?> p-2 rounded">
                                                <label class="form-check-label">
                                                    <span class="option-label">B:</span> <?php echo escape($q['option_b']); ?>
                                                    <?php if ($q['correct_answer'] == 'B'): ?>
                                                        <span class="badge bg-success ms-2">
                                                            <i class="bi bi-check-circle"></i> Правильный
                                                        </span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-2">
                                            <div class="form-check <?php echo $q['correct_answer'] == 'C' ? 'option-correct' : ''; ?> p-2 rounded">
                                                <label class="form-check-label">
                                                    <span class="option-label">C:</span> <?php echo escape($q['option_c']); ?>
                                                    <?php if ($q['correct_answer'] == 'C'): ?>
                                                        <span class="badge bg-success ms-2">
                                                            <i class="bi bi-check-circle"></i> Правильный
                                                        </span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-2">
                                            <div class="form-check <?php echo $q['correct_answer'] == 'D' ? 'option-correct' : ''; ?> p-2 rounded">
                                                <label class="form-check-label">
                                                    <span class="option-label">D:</span> <?php echo escape($q['option_d']); ?>
                                                    <?php if ($q['correct_answer'] == 'D'): ?>
                                                        <span class="badge bg-success ms-2">
                                                            <i class="bi bi-check-circle"></i> Правильный
                                                        </span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
            <?php elseif ($action === 'create' || $action === 'edit'): ?>
                <div class="row justify-content-center">
                    <div class="col-md-10">
                        <div class="card form-card">
                            <div class="card-header">
                                <h3 class="mb-0">
                                    <i class="bi bi-question-circle"></i> 
                                    <?php echo $action === 'create' ? 'Добавление нового вопроса' : 'Редактирование вопроса'; ?>
                                </h3>
                                <small class="text-white">Тест: <?php echo escape($test['title']); ?></small>
                            </div>
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data" id="questionForm">
                                    <!-- Текст вопроса -->
                                    <div class="form-section">
                                        <h5><i class="bi bi-card-text"></i> Текст вопроса</h5>
                                        <div class="mb-3">
                                            <label for="question_text" class="form-label required-field">Текст вопроса:</label>
                                            <textarea class="form-control" id="question_text" 
                                                      name="question_text" rows="3" required
                                                      placeholder="Введите текст вопроса"><?php echo isset($question) ? escape($question['question_text']) : ''; ?></textarea>
                                            <small class="text-muted">Обязательное поле. Четко сформулируйте вопрос.</small>
                                        </div>
                                    </div>
                                    
                                    <!-- Изображение вопроса -->
                                    <div class="form-section">
                                        <h5><i class="bi bi-image"></i> Изображение вопроса (опционально)</h5>
                                        
                                        <?php if (isset($question) && $question['question_image']): ?>
                                            <div class="mb-3">
                                                <p class="mb-2"><strong>Текущее изображение:</strong></p>
                                                <div class="image-preview-container">
                                                    <img src="../<?php echo $question['question_image']; ?>" 
                                                         alt="Текущее изображение" 
                                                         class="question-image" 
                                                         style="max-width: 300px;">
                                                    <button type="button" class="delete-image-btn" 
                                                            onclick="confirmImageDelete()" title="Удалить изображение">
                                                        <i class="bi bi-x"></i>
                                                    </button>
                                                    <input type="hidden" name="delete_image" id="delete_image" value="0">
                                                </div>
                                                <small class="text-muted">Нажмите на крестик, чтобы удалить текущее изображение</small>
                                            </div>
                                            <hr>
                                        <?php endif; ?>
                                        
                                        <div class="mb-3">
                                            <label for="question_image" class="form-label">Загрузить новое изображение:</label>
                                            <input type="file" class="form-control" 
                                                   id="question_image" name="question_image"
                                                   accept="image/*">
                                            <small class="text-muted">Поддерживаемые форматы: JPG, PNG, GIF, WebP. Максимальный размер: 2MB</small>
                                        </div>
                                        
                                        <!-- Предпросмотр нового изображения -->
                                        <div id="imagePreview" class="mb-3" style="display: none;">
                                            <p class="mb-2"><strong>Предпросмотр нового изображения:</strong></p>
                                            <div class="preview-image-container">
                                                <img id="previewImage" class="question-image" style="max-width: 300px;">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Блок вариантов ответов -->
                                    <div id="multiple_choice_block">
                                        <div class="form-section">
                                            <h5><i class="bi bi-list-ul"></i> Варианты ответов</h5>
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <label for="option_a" class="form-label required-field">Вариант A:</label>
                                                    <input type="text" class="form-control" 
                                                           id="option_a" name="option_a" required
                                                           placeholder="Введите вариант A"
                                                           value="<?php echo isset($question) ? escape($question['option_a']) : ''; ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="option_b" class="form-label required-field">Вариант B:</label>
                                                    <input type="text" class="form-control" 
                                                           id="option_b" name="option_b" required
                                                           placeholder="Введите вариант B"
                                                           value="<?php echo isset($question) ? escape($question['option_b']) : ''; ?>">
                                                </div>
                                            </div>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <label for="option_c" class="form-label required-field">Вариант C:</label>
                                                    <input type="text" class="form-control" 
                                                           id="option_c" name="option_c" required
                                                           placeholder="Введите вариант C"
                                                           value="<?php echo isset($question) ? escape($question['option_c']) : ''; ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="option_d" class="form-label required-field">Вариант D:</label>
                                                    <input type="text" class="form-control" 
                                                           id="option_d" name="option_d" required
                                                           placeholder="Введите вариант D"
                                                           value="<?php echo isset($question) ? escape($question['option_d']) : ''; ?>">
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="correct_answer" class="form-label required-field">Правильный ответ:</label>
                                                <select class="form-select" id="correct_answer" name="correct_answer" required>
                                                    <option value="">-- Выберите правильный ответ --</option>
                                                    <option value="A" <?php echo (isset($question) && $question['correct_answer'] == 'A') ? 'selected' : ''; ?>>Вариант A</option>
                                                    <option value="B" <?php echo (isset($question) && $question['correct_answer'] == 'B') ? 'selected' : ''; ?>>Вариант B</option>
                                                    <option value="C" <?php echo (isset($question) && $question['correct_answer'] == 'C') ? 'selected' : ''; ?>>Вариант C</option>
                                                    <option value="D" <?php echo (isset($question) && $question['correct_answer'] == 'D') ? 'selected' : ''; ?>>Вариант D</option>
                                                </select>
                                                <small class="text-muted">Выберите правильный вариант ответа из предложенных</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Кнопки формы -->
                                    <div class="d-flex justify-content-between mt-4 pt-3 border-top">
                                        <a href="questions.php?test_id=<?php echo $selectedTestId; ?>" 
                                           class="btn btn-secondary">
                                            <i class="bi bi-arrow-left"></i> Назад к вопросам
                                        </a>
                                        <button type="submit" class="btn btn-primary px-4" id="submitBtn">
                                            <i class="bi bi-check-circle"></i>
                                            <?php echo $action === 'create' ? 'Создать вопрос' : 'Сохранить изменения'; ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Навигация внизу -->
        <div class="navigation-buttons">
            <div class="row">
                <div class="col-md-3 mb-2">
                    <a href="index.php" class="btn btn-outline-primary w-100">
                        <i class="bi bi-house"></i> Главная
                    </a>
                </div>
                <div class="col-md-3 mb-2">
                    <a href="tests.php" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-journal-text"></i> Тесты
                    </a>
                </div>
                <div class="col-md-3 mb-2">
                    <a href="questions.php" class="btn btn-success w-100">
                        <i class="bi bi-question-circle"></i> Вопросы
                    </a>
                </div>
                <div class="col-md-3 mb-2">
                    <a href="results.php" class="btn btn-outline-info w-100">
                        <i class="bi bi-bar-chart"></i> Результаты
                    </a>
                </div>
            </div>
        </div>

        <!-- Информация -->
        <div class="mt-4 text-center text-muted">
            <small>
                <i class="bi bi-info-circle"></i> 
                Всего тестов: <?php echo count($allTests); ?> | 
                Преподаватель: <?php echo $_SESSION['teacher']['last_name'] . ' ' . $_SESSION['teacher']['first_name']; ?> |
                <?php if ($teacher_city): ?>Город: <?php echo htmlspecialchars($teacher_city); ?> | <?php endif; ?>
                <a href="logout.php" class="text-decoration-none">Выйти</a>
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Предпросмотр изображения
        document.getElementById('question_image')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            const preview = document.getElementById('imagePreview');
            const previewImage = document.getElementById('previewImage');
            
            if (file) {
                // Проверка размера файла (2MB максимум)
                if (file.size > 2 * 1024 * 1024) {
                    alert('Файл слишком большой! Максимальный размер: 2MB.');
                    this.value = '';
                    return;
                }
                
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    preview.style.display = 'block';
                }
                
                reader.onerror = function() {
                    alert('Ошибка при чтении файла!');
                    preview.style.display = 'none';
                }
                
                reader.readAsDataURL(file);
            } else {
                preview.style.display = 'none';
            }
        });
        
        // Подтверждение удаления изображения
        function confirmImageDelete() {
            if (confirm('Вы уверены, что хотите удалить это изображение? Это действие нельзя отменить.')) {
                document.getElementById('delete_image').value = '1';
                const container = document.querySelector('.image-preview-container');
                if (container) {
                    container.style.display = 'none';
                }
            }
        }
        
        // Валидация формы при отправке
        document.getElementById('questionForm')?.addEventListener('submit', function(e) {
            const optionA = document.getElementById('option_a').value.trim();
            const optionB = document.getElementById('option_b').value.trim();
            const optionC = document.getElementById('option_c').value.trim();
            const optionD = document.getElementById('option_d').value.trim();
            const correctAnswer = document.getElementById('correct_answer').value;
            
            if (!optionA || !optionB || !optionC || !optionD) {
                e.preventDefault();
                alert('Ошибка: Заполните все варианты ответов (A, B, C, D)');
            } else if (!correctAnswer) {
                e.preventDefault();
                alert('Ошибка: Выберите правильный ответ');
            }
        });
        
        // Инициализация при загрузке страницы
        document.addEventListener('DOMContentLoaded', function() {
            // Добавляем класс required для обязательных полей
            const requiredFields = document.querySelectorAll('[required]');
            requiredFields.forEach(field => {
                const label = document.querySelector(`label[for="${field.id}"]`);
                if (label && !label.classList.contains('required-field')) {
                    label.classList.add('required-field');
                }
            });
            
            // Автофокус на первом поле в форме создания/редактирования
            if (document.getElementById('question_text')) {
                document.getElementById('question_text').focus();
            }
        });
    </script>
</body>
</html>