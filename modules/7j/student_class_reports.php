<?php
/*
 * 7J English Center — Student Class Reports (รายงานนักเรียน)
 */

// ─── POST: Delete handlers ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act       = $_POST['action'] ?? '';
    $searchB   = trim($_POST['search_back']  ?? '');
    $filterTidB= trim($_POST['teacher_back'] ?? '');
    $filterSidB= trim($_POST['sid_back']     ?? '');

    if ($act === 'no_renew') {
        $sid = trim($_POST['student_id'] ?? '');
        if ($sid) {
            // ลบ schedule (แต่ไม่ลบ class_completions — เพื่อคงประวัติในรายงานการเรียน-สอน)
            $connection2->prepare("DELETE FROM sevenj_schedule WHERE student_id=?")->execute([$sid]);
            $connection2->prepare("DELETE FROM sevenj_leave_requests WHERE requester_id=? AND requester_role='student'")->execute([$sid]);
            $connection2->prepare("DELETE FROM sevenj_login_logs WHERE user_ref_id=?")->execute([$sid]);
            $connection2->prepare("DELETE FROM sevenj_students WHERE id=?")->execute([$sid]);
        }
        header('Location: /MyNewShool/?q=/modules/7j/student_class_reports.php'
            . ($searchB ? '&search='.urlencode($searchB) : ''));
        exit;
    } elseif ($act === 'renew_course') {
        $sid      = trim($_POST['student_id']      ?? '');
        $sname    = trim($_POST['student_name']    ?? '');
        $scode    = trim($_POST['student_code']    ?? '');
        $tid      = trim($_POST['teacher_id']      ?? '');
        $tname    = trim($_POST['teacher_name_txt']?? '');
        $stype    = ($_POST['schedule_type'] ?? '') === 'one_time' ? 'one_time' : 'weekly';
        $day      = $stype === 'weekly'   ? trim($_POST['day_of_week']   ?? '') : null;
        $sdate    = $stype === 'one_time' ? trim($_POST['specific_date'] ?? '') : null;
        $tstart   = trim($_POST['time_start']        ?? '');
        $tend     = trim($_POST['time_end']          ?? '');
        $course   = trim($_POST['course']            ?? '');
        $newTotal = max(1, (int)($_POST['new_total_classes'] ?? 1));
        if ($sid && $tstart && $tend) {
            if ($tid) {
                $tr = $connection2->prepare("SELECT displayName FROM sevenj_teachers WHERE id=?");
                $tr->execute([$tid]);
                $trow = $tr->fetch(PDO::FETCH_ASSOC);
                if ($trow) $tname = $trow['displayName'];
            }
            // mark ทุก active schedule ของนักเรียนนี้เป็น completed ก่อนสร้างคอร์สใหม่
            $connection2->prepare("UPDATE sevenj_schedule SET status='completed' WHERE student_id=? AND status='active'")
                ->execute([$sid]);
            $connection2->prepare("INSERT INTO sevenj_schedule
                (student_id,student_code,student_name,teacher_ref_id,teacher_name,
                 schedule_type,day_of_week,specific_date,time_start,time_end,
                 course,total_classes,completed_classes,status,note)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,'active','คอร์สใหม่')")
                ->execute([$sid,$scode,$sname,$tid?:null,$tname,
                           $stype,$day,$sdate,$tstart,$tend,$course?:null,$newTotal]);
            $connection2->prepare("UPDATE sevenj_students SET totalClasses=? WHERE id=?")
                ->execute([$newTotal, $sid]);
        }
        header('Location: /MyNewShool/?q=/modules/7j/student_class_reports.php'
            . ($filterSidB ? '&sid='.urlencode($filterSidB) : '')
            . ($searchB    ? '&search='.urlencode($searchB) : ''));
        exit;
    } elseif ($act === 'delete_log') {
        $lid = (int)($_POST['log_id'] ?? 0);
        if ($lid) {
            // SELECT ก่อน DELETE เพื่อเก็บ schedule_id ไว้ลด counter
            $stmtPre = $connection2->prepare("SELECT student_id, schedule_id FROM sevenj_class_completions WHERE id=?");
            $stmtPre->execute([$lid]);
            $row = $stmtPre->fetch(PDO::FETCH_ASSOC);
            $connection2->prepare("DELETE FROM sevenj_class_completions WHERE id=?")->execute([$lid]);
            if ($row && $row['schedule_id']) {
                $connection2->prepare("UPDATE sevenj_schedule SET completed_classes=GREATEST(completed_classes-1,0) WHERE id=?")
                    ->execute([$row['schedule_id']]);
            }
        }
    } elseif ($act === 'delete_student_logs') {
        $sid = trim($_POST['student_id'] ?? '');
        if ($sid) {
            $connection2->prepare("DELETE FROM sevenj_class_completions WHERE student_id=?")->execute([$sid]);
        }
    }

    header('Location: /MyNewShool/?q=/modules/7j/student_class_reports.php'
        . ($filterSidB ? '&sid='.urlencode($filterSidB) : '')
        . ($searchB    ? '&search='.urlencode($searchB) : '')
        . ($filterTidB ? '&teacher='.urlencode($filterTidB) : ''));
    exit;
}

$today      = date('Y-m-d');
$search     = trim($_GET['search']  ?? '');
$filterTid  = trim($_GET['teacher'] ?? '');
$filterSid  = trim($_GET['sid']     ?? '');
$hasFilter  = $search || $filterTid || $filterSid;

// ─── Export CSV ───────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $hasFilter) {
    $w = ['1=1']; $ep = [];
    if ($filterSid) { $w[] = "c.student_id=?";   $ep[] = (int)$filterSid; }
    if ($search)    { $w[] = "(st.displayName LIKE ? OR st.studentCode LIKE ? OR st.nickname LIKE ?)"; $l='%'.$search.'%'; $ep[]=$l; $ep[]=$l; $ep[]=$l; }
    if ($filterTid) { $w[] = "c.teacher_ref_id=?"; $ep[] = (int)$filterTid; }
    $stmtExp = $connection2->prepare("
        SELECT c.completed_date, c.session_number, c.day_of_week, c.time_start,
               COALESCE(st.displayName,st2.displayName,c.student_name) AS student_name,
               COALESCE(st.studentCode,c.student_code) AS student_code,
               COALESCE(t.displayName,c.teacher_name) AS teacher_name,
               sch.course, c.note
        FROM sevenj_class_completions c
        LEFT JOIN sevenj_students st ON st.id = c.student_id
        LEFT JOIN sevenj_students st2 ON st2.displayName = c.student_name
        LEFT JOIN sevenj_teachers t  ON t.id  = c.teacher_ref_id
        LEFT JOIN sevenj_schedule sch ON sch.id = c.schedule_id
        WHERE ".implode(' AND ',$w)."
        ORDER BY st.displayName, c.completed_date
    ");
    $stmtExp->execute($ep);
    $expRows = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_report_'.date('Ymd').'.csv"');
    echo "\xEF\xBB\xBF";
    $f = fopen('php://output','w');
    fputcsv($f,['วันที่','คาบที่','วัน','เวลา','นักเรียน','รหัส','ครู','คอร์ส','หมายเหตุ']);
    foreach ($expRows as $r) {
        fputcsv($f,[$r['completed_date'],$r['session_number'],$r['day_of_week'],
            $r['time_start'],$r['student_name'],$r['student_code'],
            $r['teacher_name'],$r['course'],$r['note']]);
    }
    fclose($f);
    exit;
}

// ─── Student summary (แสดงเสมอ) ──────────────────────────────────────────────
$sumWhere = ["s.status='active'"]; $sumP = [];
if ($search)    { $sumWhere[] = "(s.displayName LIKE ? OR s.studentCode LIKE ? OR s.nickname LIKE ?)"; $l='%'.$search.'%'; $sumP[]=$l; $sumP[]=$l; $sumP[]=$l; }
if ($filterSid) { $sumWhere[] = "s.id=?"; $sumP[] = (int)$filterSid; }

$stmtSum = $connection2->prepare("
    SELECT s.id, s.studentCode, s.displayName, s.nickname, s.status,
           COALESCE(sch_agg.total_classes, 0) AS totalClasses,
           COALESCE(sch_agg.active_remaining, 0) AS active_remaining,
           COALESCE(
               (SELECT COALESCE(t2.displayName, sch.teacher_name)
                FROM sevenj_schedule sch
                LEFT JOIN sevenj_teachers t2 ON t2.id = sch.teacher_ref_id
                WHERE sch.student_id = s.id AND sch.status = 'active'
                ORDER BY sch.id DESC LIMIT 1),
               t.displayName
           ) AS teacherName,
           t.id AS teacherId,
           COUNT(c.id) AS logged_sessions,
           MAX(c.completed_date) AS last_session
    FROM sevenj_students s
    LEFT JOIN sevenj_teachers t ON t.id = s.teacherId
    LEFT JOIN sevenj_class_completions c ON c.student_id = s.id
    LEFT JOIN (
        SELECT student_id,
               MAX(total_classes) AS total_classes,
               SUM(CASE WHEN status='active' AND completed_classes < total_classes THEN 1 ELSE 0 END) AS active_remaining
        FROM sevenj_schedule
        WHERE status IN ('active','completed')
        GROUP BY student_id
    ) sch_agg ON sch_agg.student_id = s.id
    WHERE ".implode(' AND ', $sumWhere)."
    GROUP BY s.id ORDER BY s.displayName
");
$stmtSum->execute($sumP);
$studentSummary = $stmtSum->fetchAll(PDO::FETCH_ASSOC);

// ─── Schedules ต่อนักเรียน (สำหรับแสดง sub-row) ─────────────────────────────
$schDetailRows = $connection2->query("
    SELECT sch.student_id, sch.id AS sch_id,
           COALESCE(t.displayName, sch.teacher_name) AS teacher_display,
           sch.schedule_type, sch.day_of_week, sch.specific_date,
           sch.time_start, sch.time_end, sch.course, sch.note,
           sch.total_classes, sch.completed_classes, sch.status AS sch_status
    FROM sevenj_schedule sch
    LEFT JOIN sevenj_teachers t ON t.id = sch.teacher_ref_id
    WHERE sch.status IN ('active','completed')
    ORDER BY sch.student_id, sch.id ASC
")->fetchAll(PDO::FETCH_ASSOC);
$schByStudent = [];
foreach ($schDetailRows as $sd) {
    if ($sd['student_id']) $schByStudent[$sd['student_id']][] = $sd;
}

// ─── Data สำหรับ Renew Modal ──────────────────────────────────────────────────
$allTeachers = $connection2->query("SELECT id, displayName, teacherCode FROM sevenj_teachers WHERE status='active' ORDER BY displayName")->fetchAll(PDO::FETCH_ASSOC);
$availSlots  = $connection2->query("SELECT teacher_id, type, day, specific_date, start_time, end_time, note FROM sevenj_teacher_availability ORDER BY teacher_id, start_time")->fetchAll(PDO::FETCH_ASSOC);
$availByTeacher = [];
foreach ($availSlots as $av) { $availByTeacher[$av['teacher_id']][] = $av; }
$lastSchRows = $connection2->query("SELECT student_id,teacher_ref_id,teacher_name,day_of_week,time_start,time_end,course,schedule_type,specific_date FROM sevenj_schedule WHERE status IN ('active','completed') ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$lastSchByStudent = [];
foreach ($lastSchRows as $ls) {
    if ($ls['student_id'] && !isset($lastSchByStudent[$ls['student_id']])) {
        $lastSchByStudent[$ls['student_id']] = $ls;
    }
}

// ─── Session detail (เมื่อกรอง) ───────────────────────────────────────────────
$byStudent = [];
if ($hasFilter) {
    $dw = ['1=1']; $dp = [];
    if ($filterSid) { $dw[] = "c.student_id=?";    $dp[] = (int)$filterSid; }
    if ($search)    { $dw[] = "(COALESCE(st.displayName,c.student_name) LIKE ? OR COALESCE(st.studentCode,c.student_code) LIKE ?)"; $l='%'.$search.'%'; $dp[]=$l; $dp[]=$l; }
    if ($filterTid) { $dw[] = "c.teacher_ref_id=?"; $dp[] = (int)$filterTid; }

    $stmtRows = $connection2->prepare("
        SELECT c.*,
            COALESCE(st.displayName, c.student_name) AS disp_student,
            COALESCE(st.studentCode, c.student_code) AS disp_code,
            COALESCE(t.displayName,  c.teacher_name) AS disp_teacher,
            sch.course
        FROM sevenj_class_completions c
        LEFT JOIN sevenj_students st  ON st.id  = c.student_id
        LEFT JOIN sevenj_teachers t   ON t.id   = c.teacher_ref_id
        LEFT JOIN sevenj_schedule sch ON sch.id = c.schedule_id
        WHERE ".implode(' AND ',$dw)."
        ORDER BY disp_student, c.completed_date DESC, c.session_number DESC
        LIMIT 500
    ");
    $stmtRows->execute($dp);
    $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = $r['disp_student'] ?: '?';
        $byStudent[$key][] = $r;
    }
}

$dayTh = ['Monday'=>'จันทร์','Tuesday'=>'อังคาร','Wednesday'=>'พุธ',
          'Thursday'=>'พฤหัสบดี','Friday'=>'ศุกร์','Saturday'=>'เสาร์','Sunday'=>'อาทิตย์'];

// เวลาเก็บแบบ 24h จริง — แปลงเป็น 12h AM/PM
function fmtTimePM(string $t): string {
    if ($t === '') return '';
    [$h, $m] = array_pad(explode(':', $t), 2, '00');
    $h = (int)$h;
    $suffix = $h >= 12 ? 'PM' : 'AM';
    $h12    = $h % 12 ?: 12;
    return $h12 . ':' . $m . ' ' . $suffix;
}
function ini1($n){ return mb_strtoupper(mb_substr($n??'?',0,1)); }
function ini2s($n){ $w=preg_split('/\s+/',trim($n??'')); $s=''; foreach($w as $x){if($x)$s.=mb_strtoupper(mb_substr($x,0,1));} return mb_substr($s,0,2)?:'?'; }
function acols($n){ $c=['#ea580c','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2','#65a30d']; return $c[array_sum(array_map('ord',str_split($n??'?')))%count($c)]; }
?>
<?php require_once __DIR__.'/_theme.php'; ?>
<style>
.sr-input{border:1px solid #d1d5db;border-radius:8px;padding:7px 12px;font-size:.88rem;outline:none;box-sizing:border-box;}
.sr-input:focus{border-color:#d97706;box-shadow:0 0 0 2px #fef3c7;}
.sr-btn{padding:7px 16px;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:5px;}
.sr-badge{display:inline-block;border-radius:99px;padding:1px 9px;font-size:.72rem;font-weight:700;}
.sr-sum-table{width:100%;border-collapse:collapse;font-size:.85rem;}
.sr-sum-table th{padding:9px 14px;text-align:left;background:#fffbeb;color:#92400e;font-weight:700;border-bottom:2px solid #fde68a;white-space:nowrap;}
.sr-sum-table td{padding:8px 14px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.sr-sum-table tr:hover td{background:#fffbeb;}
.sr-pbar{height:6px;background:#e5e7eb;border-radius:99px;overflow:hidden;width:80px;}
.sr-pbar-fill{height:100%;border-radius:99px;}
.sr-detail-hdr{background:linear-gradient(135deg,#92400e,#d97706);padding:12px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;}
.sr-detail-table{width:100%;border-collapse:collapse;font-size:.83rem;}
.sr-detail-table th{padding:9px 14px;text-align:left;color:#6b7280;font-weight:600;white-space:nowrap;background:#f9fafb;border-bottom:2px solid #e5e7eb;}
.sr-detail-table td{padding:8px 14px;border-bottom:1px solid #f3f4f6;}
.sr-detail-table tr:hover td{background:#fffbeb;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.7}}
</style>

<div style="max-width:100%;padding-bottom:2rem;">

<!-- Header -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:1rem;">
    <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0;">🎓 รายงานนักเรียน</h2>
    <?php if ($hasFilter): ?>
    <a href="?q=/modules/7j/student_class_reports.php&export=csv&search=<?= urlencode($search) ?>&teacher=<?= urlencode($filterTid) ?>&sid=<?= urlencode($filterSid) ?>"
       style="background:#059669;color:#fff;" class="sr-btn">⬇ Export CSV</a>
    <?php endif; ?>
</div>

<!-- Filter -->
<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:1rem;margin-bottom:1.25rem;">
    <input type="hidden" name="q" value="/modules/7j/student_class_reports.php">
    <input type="hidden" name="sid" id="sid-input" value="<?= htmlspecialchars($filterSid) ?>">
    <div style="flex:1;min-width:180px;">
        <div style="font-size:.75rem;color:#92400e;font-weight:600;margin-bottom:3px;">🔍 ค้นหานักเรียน</div>
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
               placeholder="ชื่อ, รหัส, ชื่อเล่น..." class="sr-input" style="width:100%;">
    </div>
    <div style="display:flex;gap:6px;align-items:flex-end;">
        <button type="submit" style="background:linear-gradient(135deg,#92400e,#d97706);color:#fff;" class="sr-btn">ค้นหา</button>
        <?php if ($hasFilter): ?>
        <a href="?q=/modules/7j/student_class_reports.php" style="background:#e5e7eb;color:#374151;" class="sr-btn">✕ ล้าง</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($hasFilter && !empty($byStudent)): ?>
<!-- ─── Stats ── -->
<?php
$totalSess = array_sum(array_map('count', $byStudent));
?>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:1.25rem;">
    <?php foreach ([['🎓','นักเรียน',count($studentSummary),'#d97706'],['📋','คาบที่บันทึก',$totalSess,'#ea580c'],['👨‍🏫','ครูในรายงาน',count(array_unique(array_column($rows??[],'disp_teacher'))),'#059669']] as [$ic,$lb,$vl,$co]): ?>
    <div style="background:#fff;border-radius:10px;padding:.75rem;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.08);border-top:3px solid <?= $co ?>;">
        <div style="font-size:1.6rem;font-weight:800;color:<?= $co ?>;"><?= $vl ?></div>
        <div style="font-size:.72rem;color:#6b7280;"><?= $ic ?> <?= $lb ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ─── Detail per student ── -->
<?php foreach ($byStudent as $sName => $sessions):
    $sInfo = null;
    foreach ($studentSummary as $ss) { if ($ss['displayName'] === $sName || ($sessions[0]['student_id']??'')===$ss['id']) { $sInfo=$ss; break; } }
    $done  = count($sessions);
    $total = $sInfo['totalClasses'] ?? 0;
    $pct   = $total>0 ? round($done/$total*100) : 0;
    $pctBar= $total>0 ? round($sInfo['logged_sessions']/$total*100) : 0;
    $barColor = $pctBar>=100?'#dc2626':($pctBar>=80?'#d97706':($pctBar>=50?'#2563eb':'#059669'));
?>
<div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:14px;overflow:hidden;">
    <div class="sr-detail-hdr">
        <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;flex-shrink:0;">
                <?= ini1($sName) ?>
            </div>
            <div>
                <div style="color:#fff;font-weight:700;"><?= htmlspecialchars($sName) ?></div>
                <div style="color:rgba(255,255,255,.75);font-size:.72rem;">
                    <?= htmlspecialchars($sInfo['studentCode'] ?? '') ?>
                    <?php if ($sInfo['teacherName']??''): ?>
                    &nbsp;|&nbsp; ครู <?= htmlspecialchars($sInfo['teacherName']) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div style="text-align:right;">
            <div style="color:#fff;font-weight:700;font-size:.9rem;">
                <?= (int)($sInfo['logged_sessions']??$done) ?>/<?= $total?: '?' ?> คาบ
            </div>
            <div style="background:rgba(255,255,255,.25);border-radius:20px;height:5px;width:90px;margin-top:4px;overflow:hidden;display:inline-block;vertical-align:middle;">
                <div style="background:#fff;width:<?= $pctBar ?>%;height:100%;border-radius:20px;"></div>
            </div>
            <span style="color:rgba(255,255,255,.85);font-size:.78rem;margin-left:6px;"><?= $pctBar ?>%</span>
        </div>
    </div>
    <div style="overflow-x:auto;">
        <table class="sr-detail-table">
            <thead><tr>
                <th>คาบที่</th><th>วันที่</th><th>วัน</th><th>เวลา</th>
                <th>ครูผู้สอน</th><th>คอร์ส</th><th>หมายเหตุ</th><th style="text-align:center;">ลบ</th>
            </tr></thead>
            <tbody>
            <?php foreach ($sessions as $i => $s): ?>
            <tr>
                <td style="text-align:center;">
                    <span class="sr-badge" style="background:#dbeafe;color:#1e40af;">#<?= (int)$s['session_number'] ?></span>
                </td>
                <td style="font-weight:500;white-space:nowrap;color:#374151;"><?= fmtDate($s['completed_date']) ?></td>
                <td style="color:#6b7280;"><?= $dayTh[$s['day_of_week']]??$s['day_of_week'] ?></td>
                <td style="color:#6b7280;white-space:nowrap;"><?= htmlspecialchars($s['time_start']) ?></td>
                <td style="color:#374151;"><?= htmlspecialchars($s['disp_teacher']??$s['teacher_name']) ?></td>
                <td><span class="sr-badge" style="background:#d1fae5;color:#065f46;"><?= htmlspecialchars($s['course']??'') ?></span></td>
                <td style="color:#9ca3af;font-size:.78rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($s['note']??'') ?></td>
                <td style="text-align:center;">
                    <button onclick="delLog(<?= (int)$s['id'] ?>,'<?= htmlspecialchars($s['disp_student']??$s['student_name']) ?>',<?= (int)$s['session_number'] ?>)"
                        style="background:none;border:none;cursor:pointer;color:#dc2626;font-size:.9rem;" title="ลบคาบนี้">🗑</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php elseif ($hasFilter && empty($byStudent)): ?>
<div style="background:#fff;border-radius:12px;padding:3rem;text-align:center;color:#9ca3af;box-shadow:0 2px 6px rgba(0,0,0,.07);">
    <div style="font-size:2.5rem;margin-bottom:.75rem;">📭</div>
    <p>ไม่พบประวัติการเรียนตามเงื่อนไขนี้</p>
</div>
<?php endif; ?>

<!-- ─── Student Summary Table ─────────────────────────────────────────────────── -->
<?php
$dayMap = ['Monday'=>'จันทร์','Tuesday'=>'อังคาร','Wednesday'=>'พุธ','Thursday'=>'พฤหัสบดี','Friday'=>'ศุกร์','Saturday'=>'เสาร์','Sunday'=>'อาทิตย์'];
// index studentSummary by id สำหรับ lookup action buttons
$stuById = [];
foreach ($studentSummary as $ss) { $stuById[$ss['id']] = $ss; }
// หา completed schedule ล่าสุดต่อนักเรียน (แสดงแค่ 1 row เก่า)
$lastCompletedId = [];
// รวม completed_classes และ max total จากทุก completed row ต่อนักเรียน
$completedAgg = []; // [student_id => ['done'=>SUM, 'total'=>MAX]]
foreach ($schDetailRows as $sd) {
    if ($sd['sch_status'] === 'completed' && $sd['student_id']) {
        $sid = $sd['student_id'];
        $lastCompletedId[$sid] = $sd['sch_id'];
        if (!isset($completedAgg[$sid])) $completedAgg[$sid] = ['done'=>0,'total'=>0];
        $completedAgg[$sid]['done']  += (int)$sd['completed_classes'];
        $completedAgg[$sid]['total']  = max($completedAgg[$sid]['total'], (int)$sd['total_classes']);
    }
}
// สร้าง rows: completed แสดงแค่ row ล่าสุด, active แสดงทั้งหมด
$allSchRows = [];
foreach ($studentSummary as $ss) {
    $schs = $schByStudent[$ss['id']] ?? [];
    foreach ($schs as $sc) {
        if ($sc['sch_status'] === 'completed' && $sc['sch_id'] !== ($lastCompletedId[$ss['id']] ?? null)) continue;
        $allSchRows[] = array_merge($sc, ['_stu' => $ss]);
    }
}
?>
<div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;margin-top:<?= $hasFilter?'1.5rem':'0' ?>;">
    <div style="padding:12px 16px;border-bottom:2px solid #fde68a;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <span style="font-weight:700;font-size:1rem;color:#92400e;">🎓 ภาพรวมนักเรียนทั้งหมด</span>
        <span style="font-size:.8rem;color:#9ca3af;"><?= count($studentSummary) ?> คน / <?= count($allSchRows) ?> คอร์ส</span>
    </div>
    <?php if (empty($allSchRows)): ?>
    <div style="text-align:center;padding:2rem;color:#9ca3af;">ยังไม่มีข้อมูล</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="sr-sum-table">
        <thead>
            <tr>
                <th style="width:32px;text-align:center;">#</th>
                <th>นักเรียน</th>
                <th>ครูที่สอน</th>
                <th>วัน / เวลา</th>
                <th style="text-align:center;">ความคืบหน้า</th>
                <th>คอร์ส</th>
                <th style="text-align:center;">ประเภท</th>
                <th style="text-align:center;">สถานะ</th>
                <th>สอนล่าสุด</th>
                <th style="text-align:center;">จัดการ</th>
            </tr>
        </thead>
        <tbody>
        <?php $stuActiveDone = []; // running total per student for active rows ?>
        <?php foreach ($allSchRows as $rowIdx => $sc):
            $s        = $sc['_stu'];
            $isNew    = ($sc['note'] === 'คอร์สใหม่');
            $isOld    = ($sc['sch_status'] === 'completed');
            // คำนวณ running total สำหรับ active rows (ใช้ร่วมกันทั้ง scDone และ prgDone)
            if (!$isOld) {
                $stuActiveDone[$s['id']] = ($stuActiveDone[$s['id']] ?? 0) + (int)$sc['completed_classes'];
                $activeRunningTotal = $stuActiveDone[$s['id']];
            } else {
                $activeRunningTotal = (int)$sc['completed_classes'];
            }
            // scDone/scTotal: ใช้สำหรับ badge + ปุ่มต่อคอร์ส
            if ($isOld && isset($completedAgg[$s['id']])) {
                $scDone  = $completedAgg[$s['id']]['done'];
                $scTotal = $completedAgg[$s['id']]['total'];
            } else {
                $scDone  = $activeRunningTotal;
                $scTotal = (int)$sc['total_classes'];
            }
            $scPct    = $scTotal > 0 ? min(100,round($scDone/$scTotal*100)) : 0;
            $scRemain = max(0, $scTotal - $scDone);
            $isCur    = (!$isNew && !$isOld);
            $scColor  = $isOld ? '#9ca3af' : ($scPct>=100?'#dc2626':($scPct>=80?'#f59e0b':($scPct>=50?'#3b82f6':'#10b981')));
            // prgDone/prgTotal: ใช้แสดง progress bar
            $prgDone  = $activeRunningTotal;
            $prgTotal = (int)$sc['total_classes'];
            $prgPct    = $prgTotal > 0 ? min(100, round($prgDone/$prgTotal*100)) : 0;
            $prgRemain = max(0, $prgTotal - $prgDone);
            $prgColor  = $isOld ? '#9ca3af' : ($prgPct>=100?'#dc2626':($prgPct>=80?'#f59e0b':($prgPct>=50?'#3b82f6':'#10b981')));
            if ($isNew)        { $prgLabel = '🆕 คอร์สใหม่'; $prgLabelColor = '#059669'; }
            elseif ($isOld)    { $prgLabel = '📚 คอร์สเก่า'; $prgLabelColor = '#9ca3af'; }
            else               { $prgLabel = '📖 ปัจจุบัน';  $prgLabelColor = '#1e40af'; }
            $dayLabel = $sc['schedule_type']==='one_time' ? fmtDate($sc['specific_date']) : ($dayMap[$sc['day_of_week']]??$sc['day_of_week']);
            // สถานะนักเรียนโดยรวม (สำหรับ action buttons)
            $stuData  = $stuById[$s['id']] ?? null;
            $isFull   = $stuData && (int)$stuData['logged_sessions'] >= (int)$stuData['totalClasses']
                        && (int)$stuData['totalClasses'] > 0
                        && (int)$stuData['active_remaining'] === 0;
            $learnedToday = $stuData && ($stuData['last_session'] === $today);
            // row background
            if ($isOld)      $rowBg = '#f9fafb';
            elseif ($isNew)  $rowBg = '#f0fdf4';
            else             $rowBg = '#fff';
        ?>
        <tr style="background:<?= $rowBg ?>;border-left:3px solid <?= $isOld?'#d1d5db':($isNew?'#86efac':'#fde68a') ?>;">
            <!-- # -->
            <td style="color:#9ca3af;font-size:.78rem;text-align:center;"><?= $rowIdx+1 ?></td>
            <!-- นักเรียน -->
            <td>
                <div style="display:flex;align-items:center;gap:7px;">
                    <div style="width:30px;height:30px;border-radius:50%;background:<?= acols($s['displayName']) ?>;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.75rem;flex-shrink:0;"><?= ini2s($s['displayName']) ?></div>
                    <div>
                        <div style="font-weight:600;font-size:.85rem;<?= $isOld?'color:#9ca3af;':'' ?>"><?= htmlspecialchars($s['displayName']) ?></div>
                        <div style="font-size:.7rem;color:#9ca3af;"><?= htmlspecialchars($s['studentCode']??'') ?></div>
                    </div>
                </div>
            </td>
            <!-- ครู -->
            <td style="font-size:.82rem;color:<?= $isOld?'#9ca3af':'#374151' ?>;"><?= htmlspecialchars($sc['teacher_display']??'—') ?></td>
            <!-- วัน/เวลา -->
            <td style="font-size:.78rem;color:#6b7280;white-space:nowrap;">
                <?= htmlspecialchars($dayLabel??'') ?>
                <?php if ($sc['time_start']): ?>
                <br><span style="color:#374151;"><?= fmtTimePM($sc['time_start']) ?></span>
                <?php endif; ?>
            </td>
            <!-- ความคืบหน้า -->
            <td style="text-align:center;white-space:nowrap;">
                <div style="font-size:.65rem;font-weight:700;color:<?= $prgLabelColor ?>;margin-bottom:3px;"><?= $prgLabel ?></div>
                <div style="display:flex;align-items:center;gap:5px;justify-content:center;">
                    <div class="sr-pbar" style="width:60px;<?= $isOld?'opacity:.6':'' ?>">
                        <div class="sr-pbar-fill" style="width:<?= $prgPct ?>%;background:<?= $prgColor ?>;"></div>
                    </div>
                    <span style="font-size:.8rem;font-weight:700;color:<?= $prgColor ?>"><?= $prgDone ?>/<?= $prgTotal ?></span>
                </div>
                <?php if ($prgRemain > 0): ?>
                <div style="font-size:.68rem;color:#9ca3af;">(เหลือ <?= $prgRemain ?>)</div>
                <?php endif; ?>
            </td>
            <!-- คอร์ส -->
            <td style="font-size:.78rem;color:#059669;"><?= $sc['course'] ? htmlspecialchars($sc['course']) : '<span style="color:#d1d5db;">—</span>' ?></td>
            <!-- ประเภท -->
            <td style="text-align:center;">
                <?php if ($isNew): ?>
                <span style="background:#dbeafe;color:#1e40af;border-radius:20px;padding:2px 9px;font-size:.72rem;font-weight:700;white-space:nowrap;">🆕 คอร์สใหม่</span>
                <?php elseif ($isOld): ?>
                <span style="background:#f3f4f6;color:#9ca3af;border-radius:20px;padding:2px 9px;font-size:.72rem;font-weight:700;white-space:nowrap;">✅ คอร์สเก่า</span>
                <?php else: ?>
                <span style="background:#dcfce7;color:#166534;border-radius:20px;padding:2px 9px;font-size:.72rem;font-weight:700;white-space:nowrap;">📚 ปัจจุบัน</span>
                <?php endif; ?>
            </td>
            <!-- สถานะ + action buttons: แสดงต่อคอร์สเมื่อเรียนครบคาบในแถวนี้ -->
            <td style="text-align:center;white-space:nowrap;">
                <?php if ($scDone >= $scTotal && $scTotal > 0):
                    $ls = $lastSchByStudent[$s['id']] ?? null;
                    $stuTotal = (int)($stuData['totalClasses'] ?? 0);
                    $stuDone  = (int)($stuData['logged_sessions'] ?? 0);
                    $rd = json_encode(['id'=>$s['id'],'name'=>$s['displayName'],'code'=>$s['studentCode'],
                        'teacherId'=>$ls['teacher_ref_id']??'','teacherName'=>$ls['teacher_name']??'',
                        'scheduleType'=>$ls['schedule_type']??'weekly','dayOfWeek'=>$ls['day_of_week']??'Monday',
                        'specificDate'=>$ls['specific_date']??'','timeStart'=>$ls['time_start']??'',
                        'timeEnd'=>$ls['time_end']??'','course'=>$ls['course']??'',
                        'oldTotal'=>$stuTotal,'oldDone'=>$stuDone], JSON_HEX_QUOT|JSON_HEX_APOS);
                ?>
                <span class="sr-badge" style="background:#fef3c7;color:#92400e;display:block;margin-bottom:4px;">🏆 เรียนครบแล้ว</span>
                <div style="display:flex;gap:3px;flex-wrap:wrap;justify-content:center;">
                    <button onclick="openRenewModal(<?= htmlspecialchars($rd,ENT_QUOTES) ?>)"
                        style="background:linear-gradient(135deg,#ea580c,#dc2626);color:#fff;border-radius:6px;padding:3px 8px;font-size:.7rem;font-weight:700;border:none;cursor:pointer;animation:pulse 1.5s infinite;">📞 ต่อคอร์ส</button>
                    <button onclick="confirmNoRenewDirect('<?= htmlspecialchars($s['id'],ENT_QUOTES) ?>','<?= htmlspecialchars($s['displayName'],ENT_QUOTES) ?>')"
                        style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:6px;padding:3px 8px;font-size:.7rem;font-weight:700;cursor:pointer;">❌ ไม่ต่อ</button>
                </div>
                <?php elseif (!$isOld): ?>
                <?php if ($learnedToday): ?>
                <span class="sr-badge" style="background:#dcfce7;color:#166534;">✅ เรียนแล้ว</span>
                <?php else: ?>
                <span class="sr-badge" style="background:#dbeafe;color:#1e40af;">รอเรียน</span>
                <?php endif; ?>
                <?php endif; ?>
            </td>
            <!-- สอนล่าสุด -->
            <td style="color:#6b7280;font-size:.78rem;white-space:nowrap;">
                <?= ($stuData && $stuData['last_session']) ? date('d/m/Y',strtotime($stuData['last_session'])) : '—' ?>
            </td>
            <!-- จัดการ -->
            <td style="text-align:center;">
                <button onclick="delStudentLogs('<?= htmlspecialchars($s['id']) ?>','<?= htmlspecialchars($s['displayName']) ?>')"
                    title="ลบประวัติทั้งหมด" style="background:none;border:none;cursor:pointer;color:#dc2626;font-size:.9rem;padding:2px 6px;">🗑</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

</div>

<!-- Hidden forms for delete -->
<form id="form-del-log" method="post" style="display:none;">
    <input type="hidden" name="action" value="delete_log">
    <input type="hidden" name="q" value="/modules/7j/student_class_reports.php">
    <input type="hidden" name="log_id" id="del-log-id">
    <input type="hidden" name="sid_back" value="<?= htmlspecialchars($filterSid) ?>">
    <input type="hidden" name="search_back" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="teacher_back" value="<?= htmlspecialchars($filterTid) ?>">
</form>
<form id="form-no-renew" method="post" style="display:none;">
    <input type="hidden" name="action"      value="no_renew">
    <input type="hidden" name="q"           value="/modules/7j/student_class_reports.php">
    <input type="hidden" name="student_id"  id="no-renew-sid">
    <input type="hidden" name="search_back" id="no-renew-search" value="">
</form>
<form id="form-del-student" method="post" style="display:none;">
    <input type="hidden" name="action" value="delete_student_logs">
    <input type="hidden" name="q" value="/modules/7j/student_class_reports.php">
    <input type="hidden" name="student_id" id="del-student-id">
    <input type="hidden" name="sid_back" value="<?= htmlspecialchars($filterSid) ?>">
    <input type="hidden" name="search_back" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="teacher_back" value="<?= htmlspecialchars($filterTid) ?>">
</form>

<!-- ═══ RENEW COURSE MODAL ═══ -->
<div id="modal-renew" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;overflow-y:auto;padding:20px;">
<div style="background:#fff;border-radius:14px;max-width:560px;margin:auto;box-shadow:0 8px 32px rgba(0,0,0,.2);">
    <!-- header -->
    <div style="background:linear-gradient(135deg,#92400e,#d97706);border-radius:14px 14px 0 0;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;">
        <div style="color:#fff;font-weight:700;font-size:1rem;">📞 ต่อคอร์ส — <span id="rn-title"></span></div>
        <button onclick="closeRenewModal()" style="background:none;border:none;color:#fff;font-size:1.3rem;cursor:pointer;line-height:1;">✕</button>
    </div>
    <!-- old summary -->
    <div id="rn-old-summary" style="background:#fffbeb;border-bottom:1px solid #fde68a;padding:10px 20px;font-size:.82rem;color:#92400e;"></div>
    <!-- form -->
    <form method="post" style="padding:18px 20px;">
        <input type="hidden" name="action"       value="renew_course">
        <input type="hidden" name="q"            value="/modules/7j/student_class_reports.php">
        <input type="hidden" name="student_id"   id="rn-sid">
        <input type="hidden" name="student_name" id="rn-sname">
        <input type="hidden" name="student_code" id="rn-scode">
        <input type="hidden" name="teacher_name_txt" id="rn-tname-hidden">

        <!-- นักเรียน (read-only) -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
            <div>
                <label style="font-size:.78rem;font-weight:600;color:#374151;display:block;margin-bottom:3px;">ชื่อนักเรียน</label>
                <input id="rn-sname-disp" type="text" readonly style="width:100%;padding:7px 10px;border:1px solid #e5e7eb;border-radius:7px;background:#f9fafb;font-size:.85rem;box-sizing:border-box;color:#6b7280;">
            </div>
            <div>
                <label style="font-size:.78rem;font-weight:600;color:#374151;display:block;margin-bottom:3px;">รหัสนักเรียน</label>
                <input id="rn-scode-disp" type="text" readonly style="width:100%;padding:7px 10px;border:1px solid #e5e7eb;border-radius:7px;background:#f9fafb;font-size:.85rem;box-sizing:border-box;color:#6b7280;">
            </div>
        </div>

        <!-- ครู -->
        <div style="margin-bottom:12px;">
            <label style="font-size:.78rem;font-weight:600;color:#374151;display:block;margin-bottom:3px;">ครูผู้สอน</label>
            <select id="rn-teacher-id" name="teacher_id" onchange="rnOnTeacher(this)"
                style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;">
                <option value="">— เลือกครู —</option>
                <?php foreach ($allTeachers as $t): ?>
                <option value="<?= htmlspecialchars($t['id']) ?>" data-name="<?= htmlspecialchars($t['displayName']) ?>">
                    <?= htmlspecialchars($t['displayName']) ?> (<?= htmlspecialchars($t['teacherCode']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- ช่วงเวลาว่างครู -->
        <div id="rn-avail-box" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:8px 12px;margin-bottom:12px;font-size:.78rem;">
            <div style="font-weight:700;color:#166534;margin-bottom:4px;">🕐 ช่วงเวลาว่างของครู:</div>
            <div id="rn-avail-list"></div>
        </div>

        <!-- ประเภท + วัน/วันที่ -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
            <div>
                <label style="font-size:.78rem;font-weight:600;color:#374151;display:block;margin-bottom:3px;">ประเภท</label>
                <select id="rn-stype" name="schedule_type" onchange="rnToggleType()"
                    style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;">
                    <option value="weekly">🗓 รายสัปดาห์</option>
                    <option value="one_time">📆 วันเดียว</option>
                </select>
            </div>
            <div id="rn-day-grp">
                <label style="font-size:.78rem;font-weight:600;color:#374151;display:block;margin-bottom:3px;">วัน</label>
                <select id="rn-day" name="day_of_week" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;">
                    <?php foreach (['Monday'=>'จันทร์','Tuesday'=>'อังคาร','Wednesday'=>'พุธ','Thursday'=>'พฤหัสบดี','Friday'=>'ศุกร์','Saturday'=>'เสาร์','Sunday'=>'อาทิตย์'] as $en=>$th): ?>
                    <option value="<?= $en ?>"><?= $th ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="rn-date-grp" style="display:none;">
                <label style="font-size:.78rem;font-weight:600;color:#374151;display:block;margin-bottom:3px;">วันที่</label>
                <input type="date" id="rn-date" name="specific_date" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;">
            </div>
        </div>

        <!-- เวลา -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
            <div>
                <label style="font-size:.78rem;font-weight:600;color:#374151;display:block;margin-bottom:3px;">เวลาเริ่ม</label>
                <input type="time" id="rn-tstart" name="time_start" required style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;">
            </div>
            <div>
                <label style="font-size:.78rem;font-weight:600;color:#374151;display:block;margin-bottom:3px;">เวลาจบ</label>
                <input type="time" id="rn-tend" name="time_end" required style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;">
            </div>
        </div>

        <!-- คอร์ส + คาบใหม่ -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;">
            <div>
                <label style="font-size:.78rem;font-weight:600;color:#374151;display:block;margin-bottom:3px;">คอร์ส</label>
                <input type="text" id="rn-course" name="course" placeholder="เช่น Basic, IELTS"
                    style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;">
            </div>
            <div>
                <label style="font-size:.78rem;font-weight:600;color:#374151;display:block;margin-bottom:3px;">จำนวนคาบใหม่ *</label>
                <input type="number" id="rn-total" name="new_total_classes" min="1" max="9999" required
                    style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;">
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:8px;">
            <button type="button" onclick="closeRenewModal()"
                style="padding:8px 18px;border:1px solid #d1d5db;border-radius:8px;background:#fff;font-size:.88rem;font-weight:600;cursor:pointer;">ยกเลิก</button>
            <button type="submit"
                style="padding:8px 18px;background:linear-gradient(135deg,#ea580c,#dc2626);color:#fff;border:none;border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;">✅ ต่อคอร์ส</button>
        </div>
    </form>
</div>
</div>

<script>
var rnAvailData = <?= json_encode($availByTeacher) ?>;
var DAYS_TH_RN  = {Sunday:'อาทิตย์',Monday:'จันทร์',Tuesday:'อังคาร',Wednesday:'พุธ',Thursday:'พฤหัสบดี',Friday:'ศุกร์',Saturday:'เสาร์'};
// availability เก็บ 24h จริง → แปลง 12h AM/PM
function fmt24AmPm(t) {
    if (!t) return t;
    var p = t.split(':'), h = parseInt(p[0]), m = p[1] || '00';
    var s = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + m + ' ' + s;
}

function openRenewModal(d) {
    document.getElementById('rn-sid').value          = d.id;
    document.getElementById('rn-sname').value        = d.name;
    document.getElementById('rn-scode').value        = d.code;
    document.getElementById('rn-sname-disp').value   = d.name;
    document.getElementById('rn-scode-disp').value   = d.code;
    document.getElementById('rn-title').textContent  = d.name;
    document.getElementById('rn-teacher-id').value   = d.teacherId || '';
    document.getElementById('rn-tname-hidden').value = d.teacherName || '';
    document.getElementById('rn-stype').value        = d.scheduleType || 'weekly';
    document.getElementById('rn-day').value          = d.dayOfWeek  || 'Monday';
    document.getElementById('rn-date').value         = d.specificDate || '';
    document.getElementById('rn-tstart').value       = d.timeStart  || '';
    document.getElementById('rn-tend').value         = d.timeEnd    || '';
    document.getElementById('rn-course').value       = d.course     || '';
    document.getElementById('rn-total').value        = '';
    document.getElementById('rn-old-summary').innerHTML =
        '📋 คอร์สที่ผ่านมา: เรียนครบ <strong>' + d.oldDone + '/' + d.oldTotal + ' คาบ</strong>'
        + (d.course ? ' &nbsp;|&nbsp; คอร์ส: <strong>' + escHtml(d.course) + '</strong>' : '')
        + (d.teacherName ? ' &nbsp;|&nbsp; ครู: <strong>' + escHtml(d.teacherName) + '</strong>' : '');
    rnToggleType();
    rnShowAvail(d.teacherId);
    document.getElementById('modal-renew').style.display = 'block';
    document.getElementById('rn-total').focus();
}
function closeRenewModal() {
    document.getElementById('modal-renew').style.display = 'none';
}
function rnToggleType() {
    var t = document.getElementById('rn-stype').value;
    document.getElementById('rn-day-grp').style.display  = t === 'weekly'   ? '' : 'none';
    document.getElementById('rn-date-grp').style.display = t === 'one_time' ? '' : 'none';
}
function rnOnTeacher(sel) {
    var tid = sel.value;
    var opt = sel.options[sel.selectedIndex];
    document.getElementById('rn-tname-hidden').value = opt ? (opt.dataset.name || '') : '';
    rnShowAvail(tid);
}
function rnShowAvail(tid) {
    var box  = document.getElementById('rn-avail-box');
    var list = document.getElementById('rn-avail-list');
    if (!tid || !rnAvailData[tid] || !rnAvailData[tid].length) { box.style.display='none'; return; }
    var slots = rnAvailData[tid];
    var html = '';
    slots.forEach(function(s, i) {
        var label = s.type === 'specific_date'
            ? '📅 ' + s.specific_date
            : '🗓 ' + (DAYS_TH_RN[s.day] || s.day);
        var timeLabel = fmt24AmPm(s.start_time) + ' – ' + fmt24AmPm(s.end_time);
        var note = s.note ? ' (' + escHtml(s.note) + ')' : '';
        html += '<button type="button" onclick="rnPickSlot(' + i + ',\'' + escHtml(tid) + '\')"'
            + ' style="background:#dcfce7;color:#166534;border:1.5px solid #86efac;border-radius:20px;'
            + 'padding:4px 12px;margin:3px;font-size:.78rem;cursor:pointer;font-weight:600;'
            + 'transition:background .15s;" '
            + 'onmouseover="this.style.background=\'#bbf7d0\'" onmouseout="this.style.background=\'#dcfce7\'">'
            + label + ' &nbsp; ⏰ ' + timeLabel + note
            + '</button>';
    });
    list.innerHTML = html;
    box.style.display = '';
}
function rnPickSlot(idx, tid) {
    var s = rnAvailData[tid][idx];
    if (!s) return;
    document.getElementById('rn-tstart').value = s.start_time;
    document.getElementById('rn-tend').value   = s.end_time;
    if (s.type === 'specific_date') {
        document.getElementById('rn-stype').value = 'one_time';
        document.getElementById('rn-date').value  = s.specific_date || '';
    } else {
        document.getElementById('rn-stype').value = 'weekly';
        document.getElementById('rn-day').value   = s.day || 'Monday';
    }
    rnToggleType();
    // highlight ปุ่มที่เลือก
    document.querySelectorAll('#rn-avail-list button').forEach(function(b,i){
        b.style.background = i===idx ? '#86efac' : '#dcfce7';
        b.style.borderColor= i===idx ? '#16a34a' : '#86efac';
    });
}
function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function confirmNoRenewDirect(sid, name) {
    if (!confirm('ยืนยันไม่ต่อคอร์สสำหรับ "' + name + '" ?\n\nระบบจะลบนักเรียนและตารางเรียนออกจากระบบ\nแต่ประวัติการเรียนใน "รายงานการเรียน-สอน" จะยังคงอยู่')) return;
    document.getElementById('no-renew-sid').value = sid;
    document.getElementById('form-no-renew').submit();
}
function confirmNoRenew() {
    var name = document.getElementById('rn-sname').value;
    confirmNoRenewDirect(document.getElementById('rn-sid').value, name);
}
// ปิด modal เมื่อคลิกด้านนอก
document.getElementById('modal-renew').addEventListener('click', function(e) {
    if (e.target === this) closeRenewModal();
});

function delLog(id, name, session) {
    if (!confirm('ลบคาบที่ #' + session + ' ของ "' + name + '" ใช่หรือไม่?')) return;
    document.getElementById('del-log-id').value = id;
    document.getElementById('form-del-log').submit();
}
function delStudentLogs(sid, name) {
    if (!confirm('ล้างประวัติการเรียนทั้งหมดของ "' + name + '" ใช่หรือไม่?\n(ข้อมูลคาบเรียนทั้งหมดจะถูกลบ)')) return;
    document.getElementById('del-student-id').value = sid;
    document.getElementById('form-del-student').submit();
}
</script>
