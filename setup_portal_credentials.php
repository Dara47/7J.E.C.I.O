<?php
/**
 * Setup Portal Credentials — รันครั้งเดียว
 * นักเรียน: username=S260001@7j.com  password=S260001
 * ครู:      username=T260001@7j.com  password=T260001
 */
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    "mysql:host={$databaseServer};dbname={$databaseName};charset=utf8mb4",
    $databaseUsername, $databasePassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$stuDone = 0; $teaDone = 0;

// ─── นักเรียน ──────────────────────────────────────────────────────────────
$students = $pdo->query("SELECT id, studentCode FROM sevenj_students WHERE studentCode IS NOT NULL AND studentCode != ''")->fetchAll();
$stmt = $pdo->prepare("UPDATE sevenj_students SET username=?, password_hash=? WHERE id=?");
foreach ($students as $s) {
    $code     = $s['studentCode'];
    $username = $code;
    $hash     = password_hash($code, PASSWORD_DEFAULT);
    $stmt->execute([$username, $hash, $s['id']]);
    $stuDone++;
}

// ─── ครู ───────────────────────────────────────────────────────────────────
$teachers = $pdo->query("SELECT id, teacherCode FROM sevenj_teachers WHERE teacherCode IS NOT NULL AND teacherCode != ''")->fetchAll();
$stmt2 = $pdo->prepare("UPDATE sevenj_teachers SET username=?, password_hash=? WHERE id=?");
foreach ($teachers as $t) {
    $code     = $t['teacherCode'];
    $username = $code;
    $hash     = password_hash($code, PASSWORD_DEFAULT);
    $stmt2->execute([$username, $hash, $t['id']]);
    $teaDone++;
}

echo "✅ ตั้งรหัสผ่านสำเร็จ\n";
echo "นักเรียน: {$stuDone} คน\n";
echo "ครู: {$teaDone} คน\n";
echo "\nตัวอย่าง:\n";
echo "  นักเรียน → username: S260001@7j.com  password: S260001\n";
echo "  ครู       → username: T260001@7j.com  password: T260001\n";
