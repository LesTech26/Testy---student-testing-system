<?php

// Настройки базы данных
define('DB_HOST', 'localhost');
define('DB_USER', 'cx686375_test');
define('DB_PASS', 'Test_st123');
define('DB_NAME', 'cx686375_test');

// Установка временной зоны
date_default_timezone_set('Europe/Moscow');

// Создание подключения
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    
    // Создаем таблицы если их нет
    createTablesIfNotExist($pdo);
    
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}

function createTablesIfNotExist($pdo) {
    // Таблица тестов
    $sql = "CREATE TABLE IF NOT EXISTS tests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        course_id INT NULL,
        time_limit INT DEFAULT 30,
        passing_score INT DEFAULT 60,
        is_active BOOLEAN DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
    
    // Таблица вопросов
    $sql = "CREATE TABLE IF NOT EXISTS questions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        test_id INT NOT NULL,
        question_text TEXT NOT NULL,
        question_type ENUM('single', 'multiple') DEFAULT 'single',
        image_url VARCHAR(500) NULL,
        explanation TEXT NULL,
        FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    
    // Таблица ответов
    $sql = "CREATE TABLE IF NOT EXISTS answers (
        id INT PRIMARY KEY AUTO_INCREMENT,
        question_id INT NOT NULL,
        answer_text TEXT NOT NULL,
        is_correct BOOLEAN DEFAULT 0,
        FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    
    // Таблица результатов (детальные по каждому вопросу)
    $sql = "CREATE TABLE IF NOT EXISTS results (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id INT NOT NULL,
        test_id INT NOT NULL,
        question_id INT NOT NULL,
        selected_answers TEXT,
        student_answer TEXT,
        correct_answer TEXT,
        is_correct BOOLEAN DEFAULT 0,
        score INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_student_test (student_id, test_id)
    )";
    $pdo->exec($sql);
    
    // Таблица общих результатов тестов
    $sql = "CREATE TABLE IF NOT EXISTS test_results (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id INT NOT NULL,
        test_id INT NOT NULL,
        score INT NOT NULL,
        total_questions INT NOT NULL,
        percent DECIMAL(5,2) NOT NULL,
        grade VARCHAR(10) NULL,
        is_passed BOOLEAN DEFAULT 0,
        time_spent INT DEFAULT 0,
        completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_test_result (student_id, test_id),
        INDEX idx_student (student_id),
        INDEX idx_test (test_id)
    )";
    $pdo->exec($sql);
}
?>