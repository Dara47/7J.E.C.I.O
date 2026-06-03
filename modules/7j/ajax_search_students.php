<?php
/**
 * 7J — AJAX Student Search
 * GET ?q=searchTerm  → JSON array ไม่เกิน 20 results
 * ใช้ใน Manage Schedule modal (dropdown แบบ autocomplete)
 */
header('Content-Type: application/json; charset=utf-8');

// Gibbon session/auth check — ต้องใช้ session name เดียวกับ Gibbon
if (session_status() === PHP_SESSION_NONE) {
    $guid = 'pwb8piqo-9uog-nvzw-n0bz-zcp3eb0mqmd';
    session_name('G' . substr(hash('sha256', $guid), 0, 16));
    session_start();
}
if (empty($_SESSION['gibbonPersonID'])) {
    echo json_encode([]);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) {
    echo json_encode([]);
    exit;
}

try {
    require_once dirname(__DIR__, 2) . '/config.php';
    $pdo = new PDO(
        "mysql:host={$databaseServer};dbname={$databaseName};charset=utf8mb4",
        $databaseUsername, $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $like = '%' . $q . '%';
    $stmt = $pdo->prepare(
        'SELECT id, displayName, studentCode, teacherId, totalClasses
         FROM sevenj_students
         WHERE status = "active"
           AND (displayName LIKE ? OR studentCode LIKE ? OR nickname LIKE ?)
         ORDER BY displayName
         LIMIT 20'
    );
    $stmt->execute([$like, $like, $like]);
    echo json_encode($stmt->fetchAll());
} catch (Exception $e) {
    echo json_encode([]);
}
