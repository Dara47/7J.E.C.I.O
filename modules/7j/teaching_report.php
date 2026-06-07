<?php
/*
 * 7J English Center — Teaching Report (รายงานการสอน)
 */

$search   = trim($_GET['search']    ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to']   ?? '');
$hasFilter = $search || $dateFrom || $dateTo;

// ─── Export CSV ───────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $hasFilter) {
    $w = ['1=1']; $ep = [];
    if ($search)   { $w[] = "(c.teacher_name LIKE ? OR c.student_name LIKE ? OR c.student_code LIKE ?)"; $l='%'.$search.'%'; $ep[]=$l; $ep[]=$l; $ep[]=$l; }
    if ($dateFrom) { $w[] = "c.completed_date >= ?"; $ep[] = $dateFrom; }
    if ($dateTo)   { $w[] = "c.completed_date <= ?"; $ep[] = $dateTo; }
    $stmtExp = $connection2->prepare("
        SELECT c.completed_date, c.teacher_name, c.student_name, c.student_code,
               c.day_of_week, c.time_start, c.session_number, c.note, sch.course
        FROM sevenj_class_completions c
        LEFT JOIN sevenj_schedule sch ON c.schedule_id = sch.id
        WHERE ".implode(' AND ', $w)."
        ORDER BY c.teacher_name, c.completed_date, c.time_start
    ");
    $stmtExp->execute($ep);
    $expRows = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="teaching_report_'.date('Ymd').'.csv"');
    echo "\xEF\xBB\xBF";
    $f = fopen('php://output','w');
    fputcsv($f,['วันที่','ครู','นักเรียน','รหัส','วัน','เวลา','คาบที่','คอร์ส','หมายเหตุ']);
    foreach ($expRows as $r) {
        fputcsv($f,[$r['completed_date'],$r['teacher_name'],$r['student_name'],
            $r['student_code'],$r['day_of_week'],$r['time_start'],
            $r['session_number'],$r['course'],$r['note']]);
    }
    fclose($f);
    exit;
}

// ─── Teacher Summary (แสดงเสมอ) ──────────────────────────────────────────────
// num_students = distinct students จาก sevenj_schedule (ไม่ใช่ teacherId ใน student record)
// total_sessions = SUM ของ total_classes จาก sevenj_schedule (package size รวมทุก student)
$teacherSummary = $connection2->query("
    SELECT t.id, t.displayName, t.teacherCode, t.nickname,
        COALESCE(stu.num_students, 0)     AS num_students,
        COALESCE(stu.total_classes_sum, 0) AS total_sessions,
        COALESCE(comp.this_week, 0)       AS this_week,
        COALESCE(comp.this_month, 0)      AS this_month,
        comp.last_session
    FROM sevenj_teachers t
    LEFT JOIN (
        SELECT teacher_ref_id,
            COUNT(DISTINCT student_id) AS num_students,
            SUM(pkg.max_classes)       AS total_classes_sum
        FROM (
            SELECT teacher_ref_id, student_id, MAX(total_classes) AS max_classes
            FROM sevenj_schedule
            WHERE status = 'active'
            GROUP BY teacher_ref_id, student_id
        ) pkg
        GROUP BY teacher_ref_id
    ) stu ON stu.teacher_ref_id = t.id
    LEFT JOIN (
        SELECT teacher_ref_id,
            SUM(CASE WHEN completed_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS this_week,
            SUM(CASE WHEN YEAR(completed_date)=YEAR(CURDATE()) AND MONTH(completed_date)=MONTH(CURDATE()) THEN 1 ELSE 0 END) AS this_month,
            MAX(completed_date) AS last_session
        FROM sevenj_class_completions
        WHERE teacher_ref_id IS NOT NULL
        GROUP BY teacher_ref_id
    ) comp ON comp.teacher_ref_id = t.id
    WHERE t.status = 'active'
    ORDER BY total_sessions DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ─── Recalculate this_week/this_month/last_session จากเวลาจริง (อ้างอิงรายงานนักเรียน) ──
$_trBkk     = new DateTimeZone('Asia/Bangkok');
$_trNow     = new DateTime('now', $_trBkk);
$_trNowHM   = $_trNow->format('H:i');
$_trToday   = $_trNow->format('Y-m-d');
$_trDayName = $_trNow->format('l');
$_trWeekAgo = (clone $_trNow)->modify('-6 days')->format('Y-m-d');
$_trYM      = substr($_trToday, 0, 7);

$_schSlots = $connection2->query("
    SELECT teacher_ref_id, schedule_type, day_of_week, specific_date, time_start
    FROM sevenj_schedule
    WHERE status IN ('active','completed') AND teacher_ref_id IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);

$_teaStats = [];
foreach ($_schSlots as $sl) {
    $tid    = $sl['teacher_ref_id'];
    $stype  = $sl['schedule_type'] ?? 'weekly';
    $tstart = $sl['time_start']    ?? '';
    $slotDate   = null;
    $slotPassed = false;
    if ($stype === 'one_time') {
        $sdate = $sl['specific_date'] ?? '';
        if ($sdate < $_trToday) { $slotPassed = true; $slotDate = $sdate; }
        elseif ($sdate === $_trToday && $tstart !== '' && $_trNowHM >= $tstart) { $slotPassed = true; $slotDate = $sdate; }
    } else {
        $dow = ucfirst(strtolower($sl['day_of_week'] ?? ''));
        if ($_trDayName === $dow && $tstart !== '' && $_trNowHM >= $tstart) { $slotPassed = true; $slotDate = $_trToday; }
    }
    if (!$slotPassed || !$slotDate) continue;
    if (!isset($_teaStats[$tid])) $_teaStats[$tid] = ['week'=>0,'month'=>0,'last'=>null];
    if ($slotDate >= $_trWeekAgo)          $_teaStats[$tid]['week']++;
    if (substr($slotDate,0,7) === $_trYM)  $_teaStats[$tid]['month']++;
    if (!$_teaStats[$tid]['last'] || $slotDate > $_teaStats[$tid]['last']) $_teaStats[$tid]['last'] = $slotDate;
}
foreach ($teacherSummary as &$_t) {
    $tid = $_t['id'];
    if (isset($_teaStats[$tid])) {
        $_t['this_week']    = $_teaStats[$tid]['week'];
        $_t['this_month']   = $_teaStats[$tid]['month'];
        $_t['last_session'] = $_teaStats[$tid]['last'];
    }
}
unset($_t);

// ─── Filtered detail ──────────────────────────────────────────────────────────
$rows = [];
$byTeacher = [];
if ($hasFilter) {
    $w = ['1=1']; $fp = [];
    if ($search)   { $w[] = "(c.teacher_name LIKE ? OR c.student_name LIKE ? OR c.student_code LIKE ?)"; $l='%'.$search.'%'; $fp[]=$l; $fp[]=$l; $fp[]=$l; }
    if ($dateFrom) { $w[] = "c.completed_date >= ?"; $fp[] = $dateFrom; }
    if ($dateTo)   { $w[] = "c.completed_date <= ?"; $fp[] = $dateTo; }

    $stmtRows = $connection2->prepare("
        SELECT c.*, sch.course,
            COALESCE(t.displayName, c.teacher_name) AS disp_teacher
        FROM sevenj_class_completions c
        LEFT JOIN sevenj_schedule sch ON c.schedule_id = sch.id
        LEFT JOIN sevenj_teachers t   ON t.id = c.teacher_ref_id
        WHERE ".implode(' AND ',$w)."
        ORDER BY c.teacher_name, c.completed_date, c.time_start, c.student_name
    ");
    $stmtRows->execute($fp);
    $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = $r['disp_teacher'] ?: $r['teacher_name'] ?: '(ไม่ระบุครู)';
        $byTeacher[$key][] = $r;
    }
    ksort($byTeacher);
}

$dayTh = ['Monday'=>'จันทร์','Tuesday'=>'อังคาร','Wednesday'=>'พุธ',
          'Thursday'=>'พฤหัสบดี','Friday'=>'ศุกร์','Saturday'=>'เสาร์','Sunday'=>'อาทิตย์'];

function ini2($name) {
    $w = preg_split('/\s+/', trim($name??''));
    $s=''; foreach($w as $x){if($x)$s.=mb_strtoupper(mb_substr($x,0,1));} return mb_substr($s,0,2)?:'?';
}
function acol($name) {
    $c=['#ea580c','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2','#65a30d'];
    return $c[array_sum(array_map('ord',str_split($name??'?')))%count($c)];
}
?>
<?php require_once __DIR__.'/_theme.php'; ?>
<style>
.tr-stat{background:#fff;border-radius:10px;padding:.75rem 1rem;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.08);border-top:3px solid;}
.tr-sum-table{width:100%;border-collapse:collapse;font-size:.85rem;background:#fff;}
.tr-sum-table th{padding:9px 14px;text-align:left;background:#fffbeb;color:#92400e;font-weight:700;border-bottom:2px solid #fde68a;white-space:nowrap;}
.tr-sum-table td{padding:8px 14px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.tr-sum-table tr:hover td{background:#fffbeb;}
.tr-detail-hdr{background:linear-gradient(135deg,#92400e,#d97706);padding:12px 16px;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;}
.tr-detail-table{width:100%;border-collapse:collapse;font-size:.83rem;}
.tr-detail-table th{padding:9px 14px;text-align:left;color:#6b7280;font-weight:600;white-space:nowrap;background:#f9fafb;border-bottom:2px solid #e5e7eb;}
.tr-detail-table td{padding:8px 14px;border-bottom:1px solid #f3f4f6;}
.tr-detail-table tr:hover td{background:#fffbeb;}
.tr-btn{padding:6px 14px;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:5px;}
.tr-input{border:1px solid #d1d5db;border-radius:8px;padding:7px 12px;font-size:.88rem;outline:none;}
.tr-input:focus{border-color:#d97706;box-shadow:0 0 0 2px #fef3c7;}
.tr-badge{display:inline-block;border-radius:99px;padding:1px 9px;font-size:.72rem;font-weight:700;}
</style>

<div style="max-width:100%;padding-bottom:2rem;">

<!-- Header -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:1rem;">
    <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0;">📊 รายงานการสอน</h2>
    <?php if ($hasFilter): ?>
    <a href="?q=/modules/7j/teaching_report.php&export=csv&search=<?= urlencode($search) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>"
       style="background:#059669;color:#fff;" class="tr-btn">⬇ Export CSV</a>
    <?php endif; ?>
</div>

<!-- Filter form -->
<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:1rem;margin-bottom:1.25rem;">
    <input type="hidden" name="q" value="/modules/7j/teaching_report.php">
    <div>
        <div style="font-size:.75rem;color:#92400e;font-weight:600;margin-bottom:3px;">🔍 ค้นหาครู / นักเรียน</div>
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
               placeholder="ชื่อครู หรือ นักเรียน..." class="tr-input" style="min-width:200px;">
    </div>
    <div>
        <div style="font-size:.75rem;color:#92400e;font-weight:600;margin-bottom:3px;">📅 ตั้งแต่</div>
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="tr-input">
    </div>
    <div>
        <div style="font-size:.75rem;color:#92400e;font-weight:600;margin-bottom:3px;">📅 ถึง</div>
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="tr-input">
    </div>
    <button type="submit" style="background:linear-gradient(135deg,#92400e,#d97706);color:#fff;" class="tr-btn">ค้นหา</button>
    <?php if ($hasFilter): ?>
    <a href="?q=/modules/7j/teaching_report.php" style="background:#e5e7eb;color:#374151;" class="tr-btn">✕ ล้าง</a>
    <?php endif; ?>
</form>

<?php if ($hasFilter && !empty($rows)): ?>
<!-- ─── Filtered summary ────────────────────────────────────────────────── -->
<?php
$fTeachers = count($byTeacher);
$fStudents = count(array_unique(array_column($rows,'student_name')));
$fSessions = count($rows);
?>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:1.25rem;">
    <?php foreach ([['👨‍🏫','ครู',$fTeachers,'#d97706'],['🎓','นักเรียน',$fStudents,'#ea580c'],['📋','คาบทั้งหมด',$fSessions,'#059669']] as [$ic,$lb,$vl,$co]): ?>
    <div class="tr-stat" style="border-top-color:<?= $co ?>;">
        <div style="font-size:1.6rem;font-weight:800;color:<?= $co ?>;"><?= $vl ?></div>
        <div style="font-size:.72rem;color:#6b7280;"><?= $ic ?> <?= $lb ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Detail grouped by teacher -->
<?php foreach ($byTeacher as $tName => $sessions): ?>
<div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:14px;overflow:hidden;">
    <div class="tr-detail-hdr">
        <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.2);
                        display:flex;align-items:center;justify-content:center;
                        font-weight:700;color:#fff;flex-shrink:0;">
                <?= ini2($tName) ?>
            </div>
            <div>
                <div style="color:#fff;font-weight:700;"><?= htmlspecialchars($tName) ?></div>
                <div style="color:rgba(255,255,255,.75);font-size:.72rem;">
                    <?= count(array_unique(array_column($sessions,'student_name'))) ?> นักเรียน
                </div>
            </div>
        </div>
        <div style="background:rgba(255,255,255,.2);color:#fff;border-radius:20px;padding:3px 14px;font-size:.85rem;font-weight:700;">
            รวม <?= count($sessions) ?> คาบ
        </div>
    </div>
    <div style="overflow-x:auto;">
        <table class="tr-detail-table">
            <thead>
                <tr>
                    <th>วันที่</th><th>วัน</th><th>เวลา</th>
                    <th>นักเรียน</th><th>รหัส</th><th>คอร์ส</th>
                    <th style="text-align:center;">คาบที่</th><th>หมายเหตุ</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sessions as $i => $s): ?>
            <tr>
                <td style="color:#374151;font-weight:500;white-space:nowrap;"><?= fmtDate($s['completed_date']) ?></td>
                <td style="color:#6b7280;"><?= $dayTh[$s['day_of_week']] ?? $s['day_of_week'] ?></td>
                <td style="color:#6b7280;white-space:nowrap;"><?= htmlspecialchars($s['time_start']) ?></td>
                <td style="font-weight:500;"><?= htmlspecialchars($s['student_name']) ?></td>
                <td><span class="tr-badge" style="background:#fff7ed;color:#9a3412;"><?= htmlspecialchars($s['student_code']) ?></span></td>
                <td style="color:#6b7280;font-size:.8rem;"><?= htmlspecialchars($s['course'] ?? '') ?></td>
                <td style="text-align:center;"><span class="tr-badge" style="background:#dbeafe;color:#1e40af;">#<?= (int)$s['session_number'] ?></span></td>
                <td style="color:#9ca3af;font-size:.78rem;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= htmlspecialchars($s['note'] ?? '') ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php elseif ($hasFilter && empty($rows)): ?>
<div style="text-align:center;padding:3rem;color:#9ca3af;background:#fff;border-radius:12px;box-shadow:0 2px 6px rgba(0,0,0,.07);">
    <div style="font-size:2.5rem;margin-bottom:.75rem;">📭</div>
    <p>ไม่พบข้อมูลตามเงื่อนไขที่ค้นหา</p>
</div>
<?php endif; ?>

<!-- ─── Teacher Summary (แสดงเสมอ) ─────────────────────────────────────────── -->
<div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;margin-top:<?= $hasFilter ? '1.5rem' : '0' ?>;">
    <div style="padding:12px 16px;border-bottom:2px solid #fde68a;display:flex;align-items:center;justify-content:space-between;">
        <span style="font-weight:700;font-size:1rem;color:#92400e;">📊 สรุปสถิติครูทุกคน</span>
        <span style="font-size:.8rem;color:#9ca3af;"><?= count($teacherSummary) ?> คน</span>
    </div>
    <div style="overflow-x:auto;">
    <table class="tr-sum-table">
        <thead>
            <tr>
                <th>#</th>
                <th>ครู</th>
                <th style="text-align:center;">นักเรียน</th>
                <th style="text-align:center;">คาบทั้งหมด</th>
                <th style="text-align:center;">7 วันล่าสุด</th>
                <th style="text-align:center;">เดือนนี้</th>
                <th>สอนล่าสุด</th>
                <th>ดูรายละเอียด</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($teacherSummary as $i => $t): ?>
        <tr>
            <td style="color:#9ca3af;font-size:.8rem;"><?= $i+1 ?></td>
            <td>
                <div style="display:flex;align-items:center;gap:8px;">
                    <div style="width:32px;height:32px;border-radius:50%;background:<?= acol($t['displayName']) ?>;
                                display:flex;align-items:center;justify-content:center;
                                color:#fff;font-weight:700;font-size:.8rem;flex-shrink:0;">
                        <?= ini2($t['displayName']) ?>
                    </div>
                    <div>
                        <div style="font-weight:600;"><?= htmlspecialchars($t['displayName']) ?></div>
                        <?php if ($t['nickname']): ?>
                        <div style="font-size:.75rem;color:#9ca3af;">"<?= htmlspecialchars($t['nickname']) ?>"</div>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <td style="text-align:center;">
                <span class="tr-badge" style="background:#fff7ed;color:#9a3412;font-size:.82rem;"><?= (int)$t['num_students'] ?></span>
            </td>
            <td style="text-align:center;font-weight:700;font-size:1rem;color:#1f2937;"><?= (int)$t['total_sessions'] ?></td>
            <td style="text-align:center;">
                <span class="tr-badge" style="background:<?= (int)$t['this_week']>0?'#d1fae5':'#f3f4f6' ?>;color:<?= (int)$t['this_week']>0?'#065f46':'#9ca3af' ?>;">
                    <?= (int)$t['this_week'] ?>
                </span>
            </td>
            <td style="text-align:center;">
                <span class="tr-badge" style="background:<?= (int)$t['this_month']>0?'#dbeafe':'#f3f4f6' ?>;color:<?= (int)$t['this_month']>0?'#1e40af':'#9ca3af' ?>;">
                    <?= (int)$t['this_month'] ?>
                </span>
            </td>
            <td style="color:#6b7280;font-size:.82rem;">
                <?= $t['last_session'] ? date('d/m/Y', strtotime($t['last_session'])) : '—' ?>
            </td>
            <td>
                <a href="?q=/modules/7j/teaching_report.php&search=<?= urlencode($t['displayName']) ?>"
                   style="background:#fef3c7;color:#92400e;border-radius:7px;padding:3px 10px;font-size:.78rem;font-weight:600;text-decoration:none;">
                    ดูรายละเอียด →
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

</div>
