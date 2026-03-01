<?php
session_start();
require_once '../config/database.php';

if (isset($_SESSION['teacher'])) {
    header('Location: index.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    if (empty($username) || empty($password)) {
        $error = 'Введите логин и пароль';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM teachers WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($teacher && $teacher['password'] === $password) {
            $_SESSION['teacher'] = [
                'id' => $teacher['id'],
                'last_name' => $teacher['last_name'],
                'first_name' => $teacher['first_name'],
                'username' => $teacher['username'],
                'city' => $teacher['city']
            ];
            
            header('Location: index.php');
            exit();
        } else {
            $error = 'Неверный логин или пароль';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход для преподавателей</title>
    <link rel="icon" href="/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(45deg, #0dcaf0, #17a2b8);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: white;
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
        }
        .teacher-icon {
            font-size: 4rem;
            color: #0dcaf0;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="text-center">
            <i class="bi bi-person-badge teacher-icon"></i>
            <h2 class="mb-4">Вход для преподавателей</h2>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="mb-3">
                <label for="username" class="form-label">Логин:</label>
                <input type="text" class="form-control" id="username" name="username" 
                       required placeholder="Введите логин">
            </div>
            
            <div class="mb-4">
                <label for="password" class="form-label">Пароль:</label>
                <input type="password" class="form-control" id="password" name="password" 
                       required placeholder="Введите пароль">
            </div>
            
            <button type="submit" class="btn btn-info w-100">
                <i class="bi bi-box-arrow-in-right"></i> Войти
            </button>
            
            <div class="mt-3 text-center">
                <a href="../index.php" class="text-decoration-none">
                    <i class="bi bi-arrow-left"></i> Вернуться на сайт
                </a>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>