<?php
/**
 * 7J English Center — Student Portal Auth Helper
 * Include at top of every protected portal page.
 */

session_start();

define('PORTAL_ROOT', dirname(__DIR__));

// อ่าน DB credentials จาก config.php ของ Gibbon
// ค้นหา config.php หลาย path รองรับโครงสร้างเซิร์ฟเวอร์ที่ต่างกัน
$_portalConfigCandidates = [
    PORTAL_ROOT . '/config.php',                      // portal อยู่ใน Gibbon root
    PORTAL_ROOT . '/MyNewShool/config.php',           // portal อยู่นอก Gibbon root
    dirname(PORTAL_ROOT) . '/MyNewShool/config.php',  // อีกระดับ
];
$_portalConfigFound = false;
foreach ($_portalConfigCandidates as $_portalConfigPath) {
    if (file_exists($_portalConfigPath)) {
        require_once $_portalConfigPath;  // defines $databaseServer, $databaseUsername, etc.
        $_portalConfigFound = true;
        break;
    }
}
if (!$_portalConfigFound) {
    http_response_code(500);
    die('Portal config error: cannot find Gibbon config.php');
}
unset($_portalConfigCandidates, $_portalConfigPath, $_portalConfigFound);

define('DB_HOST', $databaseServer   ?? 'localhost');
define('DB_USER', $databaseUsername ?? 'root');
define('DB_PASS', $databasePassword ?? '');
define('DB_NAME', $databaseName     ?? 'gibbon');

// สร้าง ABSOLUTE_URL แบบ dynamic (ไม่ hardcode localhost)
define('ABSOLUTE_URL', (function () {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // portal อยู่ที่ /portal/ — เอา parent directory
    $base  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $proto . '://' . $host . dirname($base);
})());

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
