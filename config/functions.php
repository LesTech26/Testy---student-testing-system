<?php
// database.php должен быть подключен раньше
if (!isset($pdo)) {
    require_once 'database.php';
}

// Проверка авторизации администратора
function isAdminLoggedIn()
{
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

// Проверка авторизации студента
function isStudentLoggedIn()
{
    return isset($_SESSION['student']) && !empty($_SESSION['student']);
}

// Редирект если студент не авторизован
function requireStudentLogin()
{
    if (!isStudentLoggedIn()) {
        header('Location: index.php');
        exit();
    }
}

/**
 * Проверяет, может ли студент проходить тест
 */
function canStudentTakeTest($student_id, $test_id, $pdo)
{
    $response = ['allowed' => true, 'message' => ''];

    try {
        // Получаем настройки теста
        $stmt = $pdo->prepare("SELECT max_attempts FROM tests WHERE id = ?");
        $stmt->execute([$test_id]);
        $test = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$test) {
            $response['allowed'] = false;
            $response['message'] = 'Тест не найден';
            return $response;
        }

        $max_attempts = $test['max_attempts'] ?? 1;

        // Получаем количество попыток студента
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as attempts 
            FROM test_results 
            WHERE student_id = ? AND test_id = ?
        ");
        $stmt->execute([$student_id, $test_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $attempts = $result['attempts'] ?? 0;

        // Проверяем лимит попыток
        if ($attempts >= $max_attempts) {
            // Проверяем, есть ли разрешение на пересдачу
            $stmt = $pdo->prepare("
                SELECT allow_retake 
                FROM test_results 
                WHERE student_id = ? AND test_id = ? 
                ORDER BY completed_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$student_id, $test_id]);
            $last_result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$last_result || $last_result['allow_retake'] == 0) {
                $response['allowed'] = false;
                $response['message'] = 'Вы использовали все доступные попытки. Для повторного прохождения обратитесь к администратору.';
                return $response;
            }
        }

        return $response;

    } catch (PDOException $e) {
        $response['allowed'] = false;
        $response['message'] = 'Ошибка проверки доступа к тесту: ' . $e->getMessage();
        return $response;
    }
}

/**
 * Проверяет, разрешена ли пересдача теста
 */
function isRetakeAllowed($student_id, $test_id, $pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT allow_retake 
            FROM test_results 
            WHERE student_id = ? AND test_id = ? 
            ORDER BY completed_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$student_id, $test_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result && $result['allow_retake'] == 1;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Безопасный расчет процента (защита от деления на ноль)
 */
function safePercentage($part, $total, $default = 0)
{
    if ($total == 0 || $total === null) {
        return $default;
    }
    return ($part / $total) * 100;
}

/**
 * Получает максимальный балл для теста
 */
function getMaxScore($test_id, $pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as max_score 
            FROM questions 
            WHERE test_id = ?
        ");
        $stmt->execute([$test_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['max_score'] ?? 1; // По умолчанию 1 балл
    } catch (PDOException $e) {
        return 1; // Возвращаем 1 в случае ошибки
    }
}

// Получение последнего результата студента по тесту
function getLastStudentResult($student_id, $test_id)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM test_results 
            WHERE student_id = ? AND test_id = ? 
            ORDER BY completed_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$student_id, $test_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

// Получение всех результатов студента по тесту
function getStudentResultsForTest($student_id, $test_id)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM results 
            WHERE student_id = ? AND test_id = ?
            ORDER BY completed_at
        ");
        $stmt->execute([$student_id, $test_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Проверка правильности ответа
function checkAnswer($student_answer, $correct_answer, $question_type = 'multiple_choice')
{
    if ($question_type == 'multiple_choice') {
        return strtoupper(trim($student_answer)) == strtoupper(trim($correct_answer));
    }

    // Для текстовых ответов - простое сравнение
    return trim($student_answer) == trim($correct_answer);
}

// Получение текста опции по букве
function getOptionText($question, $option_letter)
{
    switch (strtoupper($option_letter)) {
        case 'A':
            return $question['option_a'] ?? '';
        case 'B':
            return $question['option_b'] ?? '';
        case 'C':
            return $question['option_c'] ?? '';
        case 'D':
            return $question['option_d'] ?? '';
        default:
            return '';
    }
}

// Подсчет правильных ответов
function countCorrectAnswers($results_array)
{
    $correct = 0;
    foreach ($results_array as $result) {
        if (isset($result['is_correct']) && $result['is_correct']) {
            $correct++;
        }
    }
    return $correct;
}

// Получение правильного ответа для вопроса
function getCorrectAnswerText($question)
{
    $correct_letter = $question['correct_answer'] ?? '';
    if (empty($correct_letter)) {
        return '';
    }

    return getOptionText($question, $correct_letter);
}

// Получение ответа студента текстом
function getStudentAnswerText($question, $student_answer_letter)
{
    if (empty($student_answer_letter)) {
        return 'Нет ответа';
    }

    return getOptionText($question, $student_answer_letter);
}

// Создание массива всех опций для вопроса
function getAllOptions($question)
{
    $options = [];
    if (!empty($question['option_a']))
        $options['A'] = $question['option_a'];
    if (!empty($question['option_b']))
        $options['B'] = $question['option_b'];
    if (!empty($question['option_c']))
        $options['C'] = $question['option_c'];
    if (!empty($question['option_d']))
        $options['D'] = $question['option_d'];

    return $options;
}

// Генерация случайного кода
function generateRandomCode($length = 8)
{
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

// Логирование действий
function logAction($user_id, $action, $details = '')
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO logs (user_id, action, details, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)
        ");

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt->execute([
            $user_id,
            $action,
            $details,
            $ip_address,
            $user_agent
        ]);

        return true;
    } catch (PDOException $e) {
        error_log("Ошибка логирования: " . $e->getMessage());
        return false;
    }
}

// Получение списка специальностей
function getAllSpecialties()
{
    global $pdo;

    try {
        $stmt = $pdo->query("SELECT * FROM specialties ORDER BY name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Ошибка получения специальностей: " . $e->getMessage());
        return [];
    }
}

// Получение специальности по ID
function getSpecialtyById($id)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT * FROM specialties WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Ошибка получения специальности: " . $e->getMessage());
        return null;
    }
}

// Проверка уникальности логина
function isLoginUnique($login, $exclude_id = null)
{
    global $pdo;

    try {
        if ($exclude_id) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM students WHERE login = ? AND id != ?");
            $stmt->execute([$login, $exclude_id]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM students WHERE login = ?");
            $stmt->execute([$login]);
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] == 0;
    } catch (PDOException $e) {
        error_log("Ошибка проверки уникальности логина: " . $e->getMessage());
        return false;
    }
}

// Получение количества вопросов в тесте
function getQuestionCount($test_id)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM questions WHERE test_id = ?");
        $stmt->execute([$test_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] ?? 0;
    } catch (PDOException $e) {
        error_log("Ошибка получения количества вопросов: " . $e->getMessage());
        return 0;
    }
}

// Получение среднего результата по тесту
function getAverageTestScore($test_id)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT AVG(percent) as avg_percent, 
                   AVG(score) as avg_score,
                   COUNT(*) as total_attempts
            FROM test_results 
            WHERE test_id = ?
        ");
        $stmt->execute([$test_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Ошибка получения средней оценки: " . $e->getMessage());
        return ['avg_percent' => 0, 'avg_score' => 0, 'total_attempts' => 0];
    }
}

// Отправка email уведомления
function sendEmailNotification($to, $subject, $message)
{
    // Базовая реализация отправки email
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=utf-8\r\n";
    $headers .= "From: Система тестирования <noreply@testing-system.ru>\r\n";

    return mail($to, $subject, $message, $headers);
}

// Валидация email
function isValidEmail($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Валидация номера телефона
function isValidPhone($phone)
{
    // Простая валидация российских номеров
    $phone = preg_replace('/[^0-9]/', '', $phone);
    return strlen($phone) >= 10 && strlen($phone) <= 15;
}

// Форматирование номера телефона
function formatPhone($phone)
{
    $phone = preg_replace('/[^0-9]/', '', $phone);

    if (strlen($phone) == 11) {
        return '+7 (' . substr($phone, 1, 3) . ') ' . substr($phone, 4, 3) . '-' . substr($phone, 7, 2) . '-' . substr($phone, 9, 2);
    }

    return $phone;
}

// Получение текущего учебного года
function getAcademicYear()
{
    $current_month = date('n');
    $current_year = date('Y');

    // Если месяц с сентября по декабрь, учебный год начинается в этом году
    if ($current_month >= 9 && $current_month <= 12) {
        return $current_year . '/' . ($current_year + 1);
    }
    // Если месяц с января по август, учебный год начался в прошлом году
    else {
        return ($current_year - 1) . '/' . $current_year;
    }
}

// Проверка сложности пароля
function isPasswordStrong($password)
{
    // Пароль должен содержать минимум 8 символов, включая буквы и цифры
    if (strlen($password) < 8) {
        return false;
    }

    if (!preg_match('/[A-Za-z]/', $password)) {
        return false;
    }

    if (!preg_match('/[0-9]/', $password)) {
        return false;
    }

    return true;
}

// Хеширование пароля
function hashPassword($password)
{
    return password_hash($password, PASSWORD_DEFAULT);
}

// Проверка пароля
function verifyPassword($password, $hash)
{
    return password_verify($password, $hash);
}

// Получение информации о сессии
function getSessionInfo()
{
    return [
        'session_id' => session_id(),
        'session_name' => session_name(),
        'session_status' => session_status(),
        'session_cookie_params' => session_get_cookie_params()
    ];
}

// Очистка старых сессий
function cleanupOldSessions($days = 7)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            DELETE FROM sessions 
            WHERE last_activity < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$days]);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("Ошибка очистки сессий: " . $e->getMessage());
        return 0;
    }
}

// Бэкап базы данных (упрощенный)
function backupDatabase($backup_path = '../backups/')
{
    global $pdo;

    if (!file_exists($backup_path)) {
        mkdir($backup_path, 0777, true);
    }

    $backup_file = $backup_path . 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $tables = [];

    try {
        // Получаем список таблиц
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $backup_content = "";

        foreach ($tables as $table) {
            // Получаем структуру таблицы
            $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
            $create_table = $stmt->fetch(PDO::FETCH_ASSOC);
            $backup_content .= $create_table['Create Table'] . ";\n\n";

            // Получаем данные таблицы
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($rows) > 0) {
                $backup_content .= "INSERT INTO `$table` VALUES\n";
                $insert_values = [];

                foreach ($rows as $row) {
                    $values = array_map(function ($value) use ($pdo) {
                        if ($value === null) {
                            return 'NULL';
                        }
                        return "'" . addslashes($value) . "'";
                    }, $row);

                    $insert_values[] = "(" . implode(', ', $values) . ")";
                }

                $backup_content .= implode(",\n", $insert_values) . ";\n\n";
            }
        }

        file_put_contents($backup_file, $backup_content);
        return $backup_file;

    } catch (PDOException $e) {
        error_log("Ошибка бэкапа базы данных: " . $e->getMessage());
        return false;
    }
}

// Восстановление базы данных из бэкапа
function restoreDatabase($backup_file)
{
    global $pdo;

    if (!file_exists($backup_file)) {
        return false;
    }

    $sql_content = file_get_contents($backup_file);

    try {
        // Выполняем SQL скрипт
        $pdo->exec($sql_content);
        return true;
    } catch (PDOException $e) {
        error_log("Ошибка восстановления базы данных: " . $e->getMessage());
        return false;
    }
}

// ============= ФУНКЦИИ ДЛЯ ОЦЕНИВАНИЯ =============

// Получение оценки по правилам (автоматическая или ручная система)
function calculateGrade($correctAnswers, $maxScore, $testId = null)
{
    if ($maxScore == 0) {
        return 'Не оценено';
    }

    // Если указан test_id, получаем правила оценивания из теста
    if ($testId) {
        $test = getTestById($testId);
        if ($test && $test['grading_system'] == 'manual' && !empty($test['grading_rules'])) {
            $grade = getGradeByManualRules($correctAnswers, $maxScore, $test['grading_rules']);
            // Добавляем текстовое описание к оценке
            return formatGradeWithText($grade, $test['grading_rules']);
        }
    }

    // Автоматическая система по умолчанию
    return getGradeByScore($correctAnswers, $maxScore);
}

// Автоматическая оценка по количеству правильных ответов
function getGradeByScore($correctAnswers, $maxScore)
{
    if ($maxScore <= 0) {
        return 'Не оценено';
    }

    $percentage = ($correctAnswers / $maxScore) * 100;

    if ($percentage >= 90) {
        return '5 (Отлично)';
    } elseif ($percentage >= 75) {
        return '4 (Хорошо)';
    } elseif ($percentage >= 60) {
        return '3 (Удовлетворительно)';
    } else {
        return '2 (Неудовлетворительно)';
    }
}

// Оценка по ручным правилам (возвращает число)
function getGradeByManualRules($correctAnswers, $maxScore, $gradingRulesJson)
{
    $rules = json_decode($gradingRulesJson, true);

    if (!$rules || !isset($rules['rules']) || !is_array($rules['rules'])) {
        // Если правил нет, используем автоматическую систему
        $percentage = ($correctAnswers / $maxScore) * 100;
        if ($percentage >= 90)
            return 5;
        if ($percentage >= 75)
            return 4;
        if ($percentage >= 60)
            return 3;
        return 2;
    }

    // Сортируем правила по минимальному количеству правильных ответов (по убыванию)
    usort($rules['rules'], function ($a, $b) {
        return $b['min_correct'] <=> $a['min_correct'];
    });

    // Находим подходящее правило
    foreach ($rules['rules'] as $rule) {
        if ($correctAnswers >= $rule['min_correct']) {
            return (int) $rule['grade']; // Возвращаем число
        }
    }

    return 2; // Минимальная оценка по умолчанию
}

// Форматирование оценки с текстовым описанием
function formatGradeWithText($grade, $gradingRulesJson = null)
{
    if ($gradingRulesJson) {
        $rules = json_decode($gradingRulesJson, true);
        if ($rules && isset($rules['rules'])) {
            foreach ($rules['rules'] as $rule) {
                if ($rule['grade'] == $grade) {
                    return $grade . ' (' . $rule['label'] . ')';
                }
            }
        }
    }

    // Если не нашли описание в правилах, используем стандартные
    switch ($grade) {
        case 5:
            return '5 (Отлично)';
        case 4:
            return '4 (Хорошо)';
        case 3:
            return '3 (Удовлетворительно)';
        case 2:
            return '2 (Неудовлетворительно)';
        case 1:
            return '1 (Очень плохо)';
        default:
            return $grade . ' (Не определено)';
    }
}

// Проверка, прошел ли студент тест
function isTestPassed($correctAnswers, $maxScore, $testId)
{
    $test = getTestById($testId);
    if (!$test) {
        return false;
    }

    $percentage = ($maxScore > 0) ? ($correctAnswers / $maxScore) * 100 : 0;
    return $percentage >= ($test['passing_score'] ?? 60);
}

// Получение полного текста оценки для отображения
function getFullGradeText($grade)
{
    if (strpos($grade, '5') !== false || strpos($grade, 'Отлично') !== false) {
        return 'Отлично';
    } elseif (strpos($grade, '4') !== false || strpos($grade, 'Хорошо') !== false) {
        return 'Хорошо';
    } elseif (strpos($grade, '3') !== false || strpos($grade, 'Удовлетворительно') !== false) {
        return 'Удовлетворительно';
    } elseif (strpos($grade, '2') !== false || strpos($grade, 'Неудовлетворительно') !== false) {
        return 'Неудовлетворительно';
    } elseif (strpos($grade, '1') !== false || strpos($grade, 'Очень плохо') !== false) {
        return 'Очень плохо';
    } elseif (strpos($grade, 'Зачет') !== false) {
        return 'Зачет';
    } elseif (strpos($grade, 'Незачет') !== false) {
        return 'Незачет';
    } else {
        return $grade;
    }
}
// Проверка зачета/незачета
function getPassFailText($isPassed)
{
    return $isPassed ? 'Зачет' : 'Незачет';
}

// Форматирование времени
function formatTime($seconds)
{
    if ($seconds < 60) {
        return $seconds . ' сек';
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $remaining = $seconds % 60;
        return $minutes . ' мин ' . $remaining . ' сек';
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $hours . ' ч ' . $minutes . ' мин';
    }
}

// Получение оставшегося времени
function getRemainingTime($testId, $startTime)
{
    $test = getTestById($testId);
    if (!$test || $test['time_limit'] == 0) {
        return null; // Без лимита времени
    }

    $timeLimitSeconds = $test['time_limit'] * 60;
    $elapsed = time() - strtotime($startTime);
    $remaining = $timeLimitSeconds - $elapsed;

    return max(0, $remaining);
}

// Проверка таймаута
function isTimeUp($testId, $startTime)
{
    $remaining = getRemainingTime($testId, $startTime);
    return $remaining !== null && $remaining <= 0;
}

// Сохранение временных ответов
function saveTempAnswers($studentId, $testId, $answers)
{
    global $pdo;

    // Проверяем существующую запись
    $stmt = $pdo->prepare("SELECT id FROM temp_results WHERE student_id = ? AND test_id = ?");
    $stmt->execute([$studentId, $testId]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Обновляем существующую запись
        $stmt = $pdo->prepare("UPDATE temp_results SET answers = ?, last_activity = NOW() WHERE id = ?");
        $stmt->execute([json_encode($answers), $existing['id']]);
        return $existing['id'];
    } else {
        // Создаем новую запись
        $stmt = $pdo->prepare("INSERT INTO temp_results (student_id, test_id, answers) VALUES (?, ?, ?)");
        $stmt->execute([$studentId, $testId, json_encode($answers)]);
        return $pdo->lastInsertId();
    }
}

// Получение временных ответов
function getTempAnswers($studentId, $testId)
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT answers, start_time FROM temp_results WHERE student_id = ? AND test_id = ?");
    $stmt->execute([$studentId, $testId]);
    $result = $stmt->fetch();

    if ($result && $result['answers']) {
        return [
            'answers' => json_decode($result['answers'], true),
            'start_time' => $result['start_time']
        ];
    }

    return ['answers' => [], 'start_time' => null];
}

// Очистка временных результатов
function deleteTempResults($studentId, $testId)
{
    global $pdo;

    $stmt = $pdo->prepare("DELETE FROM temp_results WHERE student_id = ? AND test_id = ?");
    return $stmt->execute([$studentId, $testId]);
}

// Очистка старых временных результатов
function cleanupOldTempResults($hours = 24)
{
    global $pdo;

    $stmt = $pdo->prepare("DELETE FROM temp_results WHERE last_activity < DATE_SUB(NOW(), INTERVAL ? HOUR)");
    $stmt->execute([$hours]);
    return $stmt->rowCount();
}

// Получение списка тестов
function getAllTests($active_only = true)
{
    global $pdo;

    try {
        $sql = "SELECT * FROM tests";
        if ($active_only) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY title";

        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Логируем ошибку
        error_log("Ошибка получения тестов: " . $e->getMessage());
        return [];
    }
}

// Получение теста по ID
function getTestById($id)
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM tests WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Получение вопросов теста
function getQuestionsByTestId($testId)
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM questions WHERE test_id = ? ORDER BY id");
    $stmt->execute([$testId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Получение информации о студенте
function getStudentById($id)
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Получение статистики по тесту
function getTestStatistics($testId)
{
    global $pdo;

    $stats = [];

    // Общее количество попыток
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_attempts FROM results WHERE test_id = ?");
    $stmt->execute([$testId]);
    $stats['total_attempts'] = $stmt->fetch()['total_attempts'];

    // Успешные попытки
    $stmt = $pdo->prepare("SELECT COUNT(*) as passed_attempts FROM results WHERE test_id = ? AND is_passed = 1");
    $stmt->execute([$testId]);
    $stats['passed_attempts'] = $stmt->fetch()['passed_attempts'];

    // Среднее количество правильных ответов
    $stmt = $pdo->prepare("SELECT AVG(score) as avg_score FROM results WHERE test_id = ?");
    $stmt->execute([$testId]);
    $stats['avg_score'] = round($stmt->fetch()['avg_score'], 1);

    // Средняя оценка (1-5)
    $stmt = $pdo->prepare("SELECT AVG(grade) as avg_grade FROM results WHERE test_id = ? AND grade > 0");
    $stmt->execute([$testId]);
    $stats['avg_grade'] = round($stmt->fetch()['avg_grade'], 1);

    // Среднее затраченное время
    $stmt = $pdo->prepare("SELECT AVG(time_spent) as avg_time FROM results WHERE test_id = ? AND time_spent > 0");
    $stmt->execute([$testId]);
    $stats['avg_time'] = round($stmt->fetch()['avg_time']);

    // Количество вопросов с вариантами ответов
    $stmt = $pdo->prepare("SELECT COUNT(*) as mc_questions FROM questions WHERE test_id = ? AND question_type = 'multiple_choice'");
    $stmt->execute([$testId]);
    $stats['mc_questions'] = $stmt->fetch()['mc_questions'];

    // Количество текстовых вопросов
    $stmt = $pdo->prepare("SELECT COUNT(*) as text_questions FROM questions WHERE test_id = ? AND question_type = 'text_input'");
    $stmt->execute([$testId]);
    $stats['text_questions'] = $stmt->fetch()['text_questions'];

    return $stats;
}

// Проверка существования теста
function testExists($testId)
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tests WHERE id = ?");
    $stmt->execute([$testId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['count'] > 0;
}

// Получение активных тестов для студента
function getActiveTestsForStudent()
{
    global $pdo;

    $sql = "SELECT t.*, 
            (SELECT COUNT(*) FROM questions q WHERE q.test_id = t.id) as question_count
            FROM tests t 
            WHERE t.is_active = 1 
            ORDER BY t.title";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Проверка, проходил ли студент тест ранее
function hasStudentTakenTest($studentId, $testId)
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM results WHERE student_id = ? AND test_id = ?");
    $stmt->execute([$studentId, $testId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['count'] > 0;
}

// Получение лучших результатов по тесту
function getTopResults($testId, $limit = 10)
{
    global $pdo;

    $sql = "SELECT r.*, s.last_name, s.first_name, s.course_number,
            (r.score * 100.0 / r.total_questions) as percentage
            FROM test_results r
            JOIN students s ON r.student_id = s.id
            WHERE r.test_id = ?
            ORDER BY r.percent DESC, r.time_spent ASC
            LIMIT ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$testId, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Форматирование даты
function formatDate($dateString, $format = 'd.m.Y H:i')
{
    if (empty($dateString)) {
        return '';
    }
    $date = new DateTime($dateString);
    return $date->format($format);
}

// Получение типа вопроса в читаемом формате
function getQuestionTypeText($type)
{
    $types = [
        'multiple_choice' => 'Варианты ответов',
        'text_input' => 'Текстовый ответ'
    ];

    return $types[$type] ?? 'Неизвестный тип';
}

// Обновление правил оценивания при изменении количества вопросов
function updateGradingRulesForQuestionCount($testId, $questionCount)
{
    global $pdo;

    $test = getTestById($testId);
    if (!$test) {
        return false;
    }

    // Если используется ручная система, обновляем правила
    if ($test['grading_system'] == 'manual') {
        $rules = [
            'rules' => [
                ['min_correct' => $questionCount, 'grade' => '5 (Отлично)', 'label' => 'Отлично'],
                ['min_correct' => ceil($questionCount * 0.8), 'grade' => '4 (Хорошо)', 'label' => 'Хорошо'],
                ['min_correct' => ceil($questionCount * 0.6), 'grade' => '3 (Удовлетворительно)', 'label' => 'Удовлетворительно'],
                ['min_correct' => ceil($questionCount * 0.4), 'grade' => '2 (Неудовлетворительно)', 'label' => 'Неудовлетворительно'],
                ['min_correct' => 1, 'grade' => '2 (Неудовлетворительно)', 'label' => 'Очень плохо'],
                ['min_correct' => 0, 'grade' => '2 (Неудовлетворительно)', 'label' => 'Не сдано']
            ]
        ];

        $newRules = json_encode($rules, JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare("UPDATE tests SET grading_rules = ? WHERE id = ?");
        return $stmt->execute([$newRules, $testId]);
    }

    return true;
}

// Получение минимального количества правильных ответов для оценки
function getMinCorrectForGrade($testId, $grade)
{
    $test = getTestById($testId);
    if (!$test || $test['grading_system'] != 'manual' || empty($test['grading_rules'])) {
        return null;
    }

    $rules = json_decode($test['grading_rules'], true);
    if (!$rules || !isset($rules['rules'])) {
        return null;
    }

    foreach ($rules['rules'] as $rule) {
        if ($rule['grade'] == $grade) {
            return $rule['min_correct'];
        }
    }

    return null;
}

// ============= ФУНКЦИИ ДЛЯ АВТОРИЗАЦИИ И ПРОВЕРКИ ПРАВ =============

/**
 * Функция для проверки авторизации администратора
 */
function requireAdminLogin()
{
    // Стартуем сессию если еще не стартовала
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Проверяем наличие admin_id в сессии
    if (!isset($_SESSION['admin_id'])) {
        $_SESSION['error'] = 'Для доступа к этой странице необходимо авторизоваться';
        header('Location: /admin/login.php');
        exit();
    }

    return true;
}

/**
 * Функция для проверки авторизации преподавателя
 */
function requireTeacherLogin()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['teacher'])) {
        header('Location: /teacher/login.php');
        exit();
    }

    return true;
}

/**
 * Функция для проверки прав супер-администратора
 */
function requireSuperAdmin()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Проверяем, авторизован ли пользователь как администратор
    if (!isset($_SESSION['admin_id'])) {
        $_SESSION['error'] = 'Необходимо авторизоваться';
        header('Location: /admin/login.php');
        exit();
    }

    // Проверяем тип администратора
    $isSuperAdmin = false;

    if (isset($_SESSION['is_superadmin'])) {
        $isSuperAdmin = $_SESSION['is_superadmin'] == 1;
    } elseif (isset($_SESSION['admin_type'])) {
        $isSuperAdmin = $_SESSION['admin_type'] === 'super';
    }

    if (!$isSuperAdmin) {
        $_SESSION['error'] = 'У вас нет прав для доступа к этому разделу';
        header('Location: /admin/index.php');
        exit();
    }

    return true;
}

/**
 * Функция для проверки, является ли текущий пользователь супер-администратором
 */
function isSuperAdmin()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['admin_id'])) {
        return false;
    }

    if (isset($_SESSION['is_superadmin'])) {
        return $_SESSION['is_superadmin'] == 1;
    }

    if (isset($_SESSION['admin_type'])) {
        return $_SESSION['admin_type'] === 'super';
    }

    return false;
}

/**
 * Функция для экранирования вывода (безопасность)
 */
function escape($string)
{
    if ($string === null) {
        return '';
    }
    return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8');
}

/**
 * Функция для получения информации о текущем администраторе
 */
function getCurrentAdmin()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['admin_id'])) {
        return null;
    }

    return [
        'id' => $_SESSION['admin_id'],
        'username' => $_SESSION['admin_username'] ?? '',
        'type' => isSuperAdmin() ? 'super' : 'city',
        'city' => $_SESSION['admin_city'] ?? null
    ];
}

/**
 * Функция для логирования действий администратора
 */
function logAdminAction($pdo, $action, $details = '')
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (isset($_SESSION['admin_id'])) {
        // Проверяем, существует ли таблица admin_logs
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'admin_logs'");
            $tableExists = $stmt->rowCount() > 0;

            if ($tableExists) {
                $stmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $_SESSION['admin_id'],
                    $action,
                    $details,
                    $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                ]);
            }
        } catch (PDOException $e) {
            // Игнорируем ошибки логирования
        }
    }
}

// ============= КОНЕЦ ФУНКЦИЙ =============
?>