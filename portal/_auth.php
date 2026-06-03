<?php
/**
 * 7J English Center — Student Portal Auth Helper
 * Include at top of every protected portal page.
 */

session_start();

define('PORTAL_ROOT', dirname(__DIR__));
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'gibbon');
define('ABSOLUTE_URL', 'http://localhost/MyNewShool');

function portal_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function require_student_login(): array {
    if (empty($_SESSION['student_id'])) {
        header('Location: ' . ABSOLUTE_URL . '/portal/student_login.php');
        exit;
    }
    return [
        'id'          => $_SESSION['student_id'],
        'displayName' => $_SESSION['student_name'],
        'username'    => $_SESSION['student_username'],
    ];
}

function log_portal_login(string $userId, string $userName, string $userCode, string $role): void {
    try {
        $pdo  = portal_db();
        $stmt = $pdo->prepare(
            'INSERT INTO sevenj_login_logs
             (id, userName, userCode, role, user_ref_id, log_date, log_timestamp)
             VALUES (?, ?, ?, ?, ?, CURDATE(), NOW())'
        );
        $stmt->execute([
            uniqid($role . '_', true),
            $userName,
            $userCode,
            $role,
            $userId,
        ]);
    } catch (Exception $e) {
        // log ล้มเหลวไม่ block การ login
    }
}

function require_teacher_login(): array {
    if (empty($_SESSION['teacher_id'])) {
        header('Location: ' . ABSOLUTE_URL . '/portal/teacher_login.php');
        exit;
    }
    return [
        'id'          => $_SESSION['teacher_id'],
        'displayName' => $_SESSION['teacher_name'],
        'username'    => $_SESSION['teacher_username'],
    ];
}
