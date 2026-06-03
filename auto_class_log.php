<?php
/**
 * 7J English Center — Auto Class Log
 * ตัดคาบอัตโนมัติเมื่อถึงเวลา time_end
 *
 * รันผ่าน Windows Task Scheduler ทุก 1 นาที
 * หรือเรียกจาก Admin panel
 */

date_default_timezone_set('Asia/Bangkok');

// ─── Security: รับเฉพาะ CLI หรือ local request ──────────────────────────────
$isCLI = php_sapi_name() === 'cli';
if (!$isCLI) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $token = $_GET['token'] ?? '';
    // token สำหรับ web trigger (เปลี่ยนได้)
    define('WEB_TOKEN', 'auto7j2026');
    if ($token !== WEB_TOKEN && !in_array($ip, ['127.0.0.1','::1'])) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// ─── DB connection ────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    "mysql:host={$databaseServer};dbname={$databaseName};charset=utf8mb4",
    $databaseUsername, $databasePassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// ─── เวลาและวันที่ปัจจุบัน ────────────────────────────────────────────────────
$now     = date('H:i');     // เช่น "18:45"
$today   = date('Y-m-d');   // เช่น "2026-05-31"
$dayName = date('l');       // เช่น "Sunday"

$runAt   = date('Y-m-d H:i:s');
$logged  = 0;
$skipped = 0;
$errors  = 0;
$results = [];

echo "=== 7J Auto Class Log ===\n";
echo "Run: {$runAt}\n";
echo "Day: {$dayName} | Time: {$now}\n\n";

// ─── ดึง schedules ที่ตรงเงื่อนไข (ไม่กรอง time_end ใน SQL เพราะ PM normalization) ──
//   1. status = active
//   2. completed_classes < total_classes
//   3. ประเภท weekly → day_of_week ตรงกับวันนี้
//      ประเภท one_time → specific_date = วันนี้
//   4. time_end ผ่านไปแล้ว → ตรวจใน PHP พร้อม PM normalization
$stmtSched = $pdo->prepare("
    SELECT * FROM sevenj_schedule
    WHERE status = 'active'
      AND completed_classes < total_classes
      AND time_end IS NOT NULL
      AND time_end != ''
      AND (
          (schedule_type = 'weekly'   AND LOWER(day_of_week) = LOWER(:dayName))
          OR
          (schedule_type = 'one_time' AND specific_date = :today)
      )
");
$stmtSched->execute([':dayName' => $dayName, ':today' => $today]);
$allCandidates = $stmtSched->fetchAll();

// ─── PM normalization: กรอง time_end ที่ผ่านไปแล้วจริงๆ ─────────────────────
// ระบบเก็บเวลาแบบ 12h ไม่มี AM/PM (เช่น "01:45" หมายถึง 13:45)
// ถ้าเวลาปัจจุบันเป็น PM (>= 720 นาที) และ slot hour < 12 → บวก 720 (12 ชม.)
$nowMins = (int)date('H') * 60 + (int)date('i');
$schedules = [];
foreach ($allCandidates as $sch) {
    [$eh, $em] = array_map('intval', explode(':', $sch['time_end'].':00'));
    $endMins = $eh * 60 + $em;
    if ($nowMins >= 720 && $eh < 12) $endMins += 720;   // PM normalization
    if ($nowMins >= $endMins) {
        $schedules[] = $sch;
    }
}

echo "พบตารางเรียนที่ตรงเงื่อนไข: " . count($schedules) . " รายการ\n";
echo str_repeat('-', 50) . "\n";

foreach ($schedules as $sch) {
    $label = "[{$sch['student_name']} | {$sch['time_start']}-{$sch['time_end']}]";

    // ─── ตรวจว่า log วันนี้ไปแล้วหรือยัง ──────────────────────────────────────
    $chk = $pdo->prepare(
        'SELECT COUNT(*) FROM sevenj_class_completions
         WHERE schedule_id = ? AND completed_date = ?'
    );
    $chk->execute([$sch['id'], $today]);
    if ($chk->fetchColumn() > 0) {
        echo "  SKIP {$label} — log แล้ววันนี้\n";
        $skipped++;
        continue;
    }

    try {
        $newDone   = (int)$sch['completed_classes'] + 1;
        $newStatus = $newDone >= (int)$sch['total_classes'] ? 'completed' : 'active';
        // session_number = ลำดับคาบรวมทั้งนักเรียน (COUNT completions ก่อนหน้า + 1)
        $stmtSess = $pdo->prepare("SELECT COUNT(*) FROM sevenj_class_completions WHERE student_id=?");
        $stmtSess->execute([$sch['student_id'] ?: '']);
        $sessNum = (int)$stmtSess->fetchColumn() + 1;

        // UPDATE sevenj_schedule
        $pdo->prepare('UPDATE sevenj_schedule
            SET completed_classes = ?, status = ? WHERE id = ?')
            ->execute([$newDone, $newStatus, $sch['id']]);

        // UPDATE sevenj_students
        if ($sch['student_id']) {
            $pdo->prepare('UPDATE sevenj_students
                SET completedClasses = completedClasses + 1 WHERE id = ?')
                ->execute([$sch['student_id']]);
        }

        // INSERT sevenj_class_completions
        $note = '[AUTO] บันทึกอัตโนมัติ ' . date('H:i:s');
        if ($newStatus === 'completed') $note .= ' | ✅ เรียนครบแล้ว!';

        // คำนวณวันจริงจาก completed_date (ไม่ใช้ day_of_week ใน schedule เพราะ one_time อาจผิด)
        $actualDay = date('l', strtotime($today));

        $pdo->prepare('INSERT INTO sevenj_class_completions
            (schedule_id, student_id, student_code, student_name,
             teacher_name, teacher_ref_id, day_of_week, time_start,
             session_number, completed_date, note)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $sch['id'],
                $sch['student_id'],
                $sch['student_code'],
                $sch['student_name'],
                $sch['teacher_name'],
                $sch['teacher_ref_id'],
                $actualDay,
                $sch['time_start'],
                $sessNum,
                $today,
                $note,
            ]);

        $statusTxt = $newStatus === 'completed' ? ' ✅ COMPLETED' : '';
        echo "  OK   {$label} → คาบที่ {$sessNum}{$statusTxt}\n";
        $results[] = [
            'student'  => $sch['student_name'],
            'teacher'  => $sch['teacher_name'],
            'time'     => $sch['time_start'].'-'.$sch['time_end'],
            'session'  => $sessNum,
            'status'   => $newStatus,
            'date'     => $today,
        ];
        $logged++;

    } catch (Exception $e) {
        echo "  ERR  {$label} → " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo str_repeat('-', 50) . "\n";
echo "สรุป: logged={$logged} | skipped={$skipped} | errors={$errors}\n";

// ─── เขียน log file ────────────────────────────────────────────────────────────
$logDir = __DIR__ . '/uploads/auto_log/';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$logFile = $logDir . 'log_' . date('Y-m') . '.txt';

$logEntry  = "[{$runAt}] day={$dayName} time={$now}";
$logEntry .= " | checked=".count($schedules)." logged={$logged} skipped={$skipped} errors={$errors}";
if (!empty($results)) {
    foreach ($results as $r) {
        $logEntry .= "\n  + {$r['student']} / {$r['teacher']} | คาบ#{$r['session']} ({$r['time']}) [{$r['status']}]";
    }
}
$logEntry .= "\n";
file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

// ─── บันทึกผลลัพธ์ล่าสุดในรูป JSON เพื่อให้ admin page อ่าน ─────────────────
$lastRunFile = $logDir . 'last_run.json';
file_put_contents($lastRunFile, json_encode([
    'run_at'   => $runAt,
    'day'      => $dayName,
    'time'     => $now,
    'checked'  => count($schedules),
    'logged'   => $logged,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'results'  => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "\nDone.\n";
