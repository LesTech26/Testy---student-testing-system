<?php
require_once '../config/functions.php';
requireAdminLogin();

// Получаем список тестов
$stmt = $pdo->prepare("SELECT id, title FROM tests ORDER BY title");
$stmt->execute();
$allTests = $stmt->fetchAll();

// Если выбран тест
$selectedTestId = isset($_GET['test_id']) ? intval($_GET['test_id']) : null;

if ($selectedTestId) {
    // Получаем вопросы выбранного теста
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE test_id = ? ORDER BY id");
    $stmt->execute([$selectedTestId]);
    $questions = $stmt->fetchAll();
    
    // Получаем информацию о тесте
    $stmt = $pdo->prepare("SELECT title FROM tests WHERE id = ?");
    $stmt->execute([$selectedTestId]);
    $test = $stmt->fetch();
}

// Обработка действий
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedTestId) {
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
            
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $question_text = trim($_POST['question_text']);
                $question_type = 'multiple_choice'; // Всегда multiple_choice
                
                // Получаем текущий вопрос для проверки изображения
                $stmt = $pdo->prepare("SELECT question_image FROM questions WHERE id = ?");
                $stmt->execute([$id]);
                $currentQuestion = $stmt->fetch();
                $question_image = $currentQuestion['question_image'];
                
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
            
            $stmt = $pdo->prepare("SELECT * FROM questions WHERE id = ?");
            $stmt->execute([$id]);
            $question = $stmt->fetch();
            
            if (!$question) {
                header("Location: questions.php?test_id=$selectedTestId");
                exit();
            }
            break;
            
        case 'delete':
            if (isset($_GET['id']) && $selectedTestId) {
                $id = intval($_GET['id']);
                
                // Получаем информацию о вопросе для удаления изображения
                $stmt = $pdo->prepare("SELECT question_image FROM questions WHERE id = ?");
                $stmt->execute([$id]);
                $question = $stmt->fetch();
                
                // Удаляем изображение если оно есть
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
    <title>Управление вопросами</title>
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
            background: linear-gradient(45deg, #27ae60, #2ecc71);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .question-card {
            border-left: 4px solid #27ae60;
            margin-bottom: 15px;
        }
        .option-correct {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
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
        }
        .type-badge {
            font-size: 0.75em;
            padding: 3px 8px;
            background-color: #3498db;
        }
        .form-switch {
            display: none; /* Скрываем переключатель типа вопроса */
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
                    <p class="mb-0">Администратор: <?php echo $_SESSION['admin_username']; ?></p>
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
        <div class="mb-4">
            <div class="d-flex justify-content-between">
                <div>
                    <a href="index.php" class="btn btn-outline-primary me-2">
                        <i class="bi bi-house"></i> Главная
                    </a>
                    <a href="tests.php" class="btn btn-outline-secondary me-2">
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
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php
                switch ($_GET['success']) {
                    case 'question_created': echo 'Вопрос успешно создан!'; break;
                    case 'question_updated': echo 'Вопрос успешно обновлен!'; break;
                    case 'question_deleted': echo 'Вопрос успешно удален!'; break;
                }
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Выбор теста -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-filter"></i> Выберите тест</h5>
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
        </div>

        <?php if ($selectedTestId): ?>
            <?php if ($action === 'list'): ?>
                <div class="alert alert-info">
                    <h5 class="mb-0">
                        <i class="bi bi-journal-text"></i> Тест: <?php echo escape($test['title']); ?>
                        <span class="badge bg-primary ms-2">Вопросов: <?php echo count($questions); ?></span>
                    </h5>
                </div>
                
                <?php if (empty($questions)): ?>
                    <div class="alert alert-warning text-center">
                        <i class="bi bi-exclamation-triangle fs-4"></i>
                        <h4 class="mt-2">В этом тесте пока нет вопросов</h4>
                        <p>Добавьте первый вопрос для этого теста</p>
                        <a href="?action=create&test_id=<?php echo $selectedTestId; ?>" class="btn btn-success">
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
                                            <h6 class="mb-0">Вопрос #<?php echo $index + 1; ?></h6>
                                            <small>
                                                <span class="badge bg-primary type-badge">
                                                    Варианты ответов
                                                </span>
                                            </small>
                                        </div>
                                        <div class="btn-group btn-group-sm">
                                            <a href="?action=edit&id=<?php echo $q['id']; ?>&test_id=<?php echo $selectedTestId; ?>" 
                                               class="btn btn-outline-primary">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="?action=delete&id=<?php echo $q['id']; ?>&test_id=<?php echo $selectedTestId; ?>" 
                                               class="btn btn-outline-danger"
                                               onclick="return confirm('Вы уверены?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <?php if ($q['question_image']): ?>
                                            <div class="text-center mb-3">
                                                <img src="../<?php echo $q['question_image']; ?>" 
                                                     alt="Изображение вопроса" 
                                                     class="question-image">
                                            </div>
                                        <?php endif; ?>
                                        
                                        <p class="card-text fw-bold"><?php echo escape($q['question_text']); ?></p>
                                        
                                        <div class="mb-2">
                                            <div class="form-check <?php echo $q['correct_answer'] == 'A' ? 'option-correct' : ''; ?> p-2 rounded">
                                                <label class="form-check-label">
                                                    <strong>A:</strong> <?php echo escape($q['option_a']); ?>
                                                    <?php if ($q['correct_answer'] == 'A'): ?>
                                                        <span class="badge bg-success ms-2">Правильный</span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-2">
                                            <div class="form-check <?php echo $q['correct_answer'] == 'B' ? 'option-correct' : ''; ?> p-2 rounded">
                                                <label class="form-check-label">
                                                    <strong>B:</strong> <?php echo escape($q['option_b']); ?>
                                                    <?php if ($q['correct_answer'] == 'B'): ?>
                                                        <span class="badge bg-success ms-2">Правильный</span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-2">
                                            <div class="form-check <?php echo $q['correct_answer'] == 'C' ? 'option-correct' : ''; ?> p-2 rounded">
                                                <label class="form-check-label">
                                                    <strong>C:</strong> <?php echo escape($q['option_c']); ?>
                                                    <?php if ($q['correct_answer'] == 'C'): ?>
                                                        <span class="badge bg-success ms-2">Правильный</span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-2">
                                            <div class="form-check <?php echo $q['correct_answer'] == 'D' ? 'option-correct' : ''; ?> p-2 rounded">
                                                <label class="form-check-label">
                                                    <strong>D:</strong> <?php echo escape($q['option_d']); ?>
                                                    <?php if ($q['correct_answer'] == 'D'): ?>
                                                        <span class="badge bg-success ms-2">Правильный</span>
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
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h3 class="mb-0">
                                    <i class="bi bi-question-circle"></i> 
                                    <?php echo $action === 'create' ? 'Добавление нового вопроса' : 'Редактирование вопроса'; ?>
                                </h3>
                            </div>
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data">
                                    <div class="mb-3">
                                        <label for="question_text" class="form-label">Текст вопроса:</label>
                                        <textarea class="form-control" id="question_text" 
                                                  name="question_text" rows="3" required
                                                  placeholder="Введите текст вопроса"><?php echo isset($question) ? escape($question['question_text']) : ''; ?></textarea>
                                    </div>
                                    
                                    <!-- Загрузка изображения -->
                                    <div class="mb-3">
                                        <label for="question_image" class="form-label">Изображение вопроса (опционально):</label>
                                        <input type="file" class="form-control" 
                                               id="question_image" name="question_image"
                                               accept="image/*">
                                        <small class="text-muted">Поддерживаемые форматы: JPG, PNG, GIF, WebP. Максимальный размер: 2MB</small>
                                        
                                        <?php if (isset($question) && $question['question_image']): ?>
                                            <div class="mt-2">
                                                <div class="image-preview-container">
                                                    <img src="../<?php echo $question['question_image']; ?>" 
                                                         alt="Текущее изображение" 
                                                         class="question-image" 
                                                         style="max-width: 300px;">
                                                    <button type="button" class="delete-image-btn" 
                                                            onclick="confirmImageDelete()">
                                                        <i class="bi bi-x"></i>
                                                    </button>
                                                    <input type="hidden" name="delete_image" id="delete_image" value="0">
                                                </div>
                                                <small class="text-muted d-block">Текущее изображение</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Предпросмотр изображения -->
                                    <div id="imagePreview" class="mb-3" style="display: none;">
                                        <p class="mb-2">Предпросмотр:</p>
                                        <img id="previewImage" class="question-image" style="max-width: 300px;">
                                    </div>
                                    
                                    <!-- Блок вариантов ответов -->
                                    <div id="multiple_choice_block">
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <label for="option_a" class="form-label">Вариант A:</label>
                                                <input type="text" class="form-control" 
                                                       id="option_a" name="option_a" required
                                                       placeholder="Введите вариант A"
                                                       value="<?php echo isset($question) ? escape($question['option_a']) : ''; ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="option_b" class="form-label">Вариант B:</label>
                                                <input type="text" class="form-control" 
                                                       id="option_b" name="option_b" required
                                                       placeholder="Введите вариант B"
                                                       value="<?php echo isset($question) ? escape($question['option_b']) : ''; ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <label for="option_c" class="form-label">Вариант C:</label>
                                                <input type="text" class="form-control" 
                                                       id="option_c" name="option_c" required
                                                       placeholder="Введите вариант C"
                                                       value="<?php echo isset($question) ? escape($question['option_c']) : ''; ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="option_d" class="form-label">Вариант D:</label>
                                                <input type="text" class="form-control" 
                                                       id="option_d" name="option_d" required
                                                       placeholder="Введите вариант D"
                                                       value="<?php echo isset($question) ? escape($question['option_d']) : ''; ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="correct_answer" class="form-label">Правильный ответ:</label>
                                            <select class="form-select" id="correct_answer" name="correct_answer" required>
                                                <option value="">-- Выберите правильный ответ --</option>
                                                <option value="A" <?php echo (isset($question) && $question['correct_answer'] == 'A') ? 'selected' : ''; ?>>A</option>
                                                <option value="B" <?php echo (isset($question) && $question['correct_answer'] == 'B') ? 'selected' : ''; ?>>B</option>
                                                <option value="C" <?php echo (isset($question) && $question['correct_answer'] == 'C') ? 'selected' : ''; ?>>C</option>
                                                <option value="D" <?php echo (isset($question) && $question['correct_answer'] == 'D') ? 'selected' : ''; ?>>D</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between">
                                        <a href="questions.php?test_id=<?php echo $selectedTestId; ?>" 
                                           class="btn btn-secondary">
                                            <i class="bi bi-arrow-left"></i> Назад к вопросам
                                        </a>
                                        <button type="submit" class="btn btn-primary" id="submitBtn">
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
        <div class="mt-4 pt-3 border-top">
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
                Администратор: <?php echo $_SESSION['admin_username']; ?> | 
                <a href="logout.php" class="text-decoration-none">Выйти</a>
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Предпросмотр изображения
        document.getElementById('question_image').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const preview = document.getElementById('imagePreview');
            const previewImage = document.getElementById('previewImage');
            
            if (file) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    preview.style.display = 'block';
                }
                
                reader.readAsDataURL(file);
            } else {
                preview.style.display = 'none';
            }
        });
        
        // Подтверждение удаления изображения
        function confirmImageDelete() {
            if (confirm('Вы уверены, что хотите удалить это изображение?')) {
                document.getElementById('delete_image').value = '1';
                const container = document.querySelector('.image-preview-container');
                if (container) {
                    container.style.display = 'none';
                }
            }
        }
        
        // Валидация формы при отправке
        document.querySelector('form').addEventListener('submit', function(e) {
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
    </script>
</body>
</html>