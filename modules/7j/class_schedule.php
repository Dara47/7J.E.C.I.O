<?php
/*
 * 7J English Center — Class Schedule
 * Ported from StudentSchedule.tsx
 * List + Calendar view, filter by student, complete class session
 */

// ─── POST: Edit schedule (day/date/time) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_schedule') {
    $id  = (int)($_POST['schedule_id'] ?? 0);
    $qs  = http_build_query(['q'=>'/modules/7j/class_schedule.php','view'=>$_POST['view']??'list','student'=>$_POST['student_filter']??'','search'=>$_POST['search_val']??'']);
    if ($id > 0) {
        // ─ Server-side guard: ตรวจว่าถึงเวลาเรียนหรือบันทึกแล้วหรือยัง ───────
        $stmtG = $connection2->prepare("SELECT * FROM sevenj_schedule WHERE id=? LIMIT 1");
        $stmtG->execute([$id]);
        $sch = $stmtG->fetch(PDO::FETCH_ASSOC);
        $canSave = false;
        if ($sch) {
            $nowDT  = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
            $nowHM  = (int)$nowDT->format('H') * 60 + (int)$nowDT->format('i');
            [$h,$m] = array_map('intval', explode(':', ($sch['time_start'] ?? '00:00').':00'));
            $slotHM = $h * 60 + $m;
            if ($sch['schedule_type'] === 'one_time' && ($sch['specific_date'] ?? '')) {
                $slotDate = new DateTime($sch['specific_date'], new DateTimeZone('Asia/Bangkok'));
                $todayDT  = new DateTime('today',  new DateTimeZone('Asia/Bangkok'));
                $isReady  = ($slotDate < $todayDT) || ($slotDate == $todayDT && $nowHM >= $slotHM);
            } else {
                $isReady = ($nowDT->format('l') === ($sch['day_of_week'] ?? '')) && $nowHM >= $slotHM;
            }
            // ตรวจบันทึกซ้ำวันนี้
            $chk = $connection2->prepare("SELECT COUNT(*) FROM sevenj_class_completions WHERE schedule_id=? AND completed_date=?");
            $chk->execute([$id, date('Y-m-d')]);
            $alreadyLogged = (int)$chk->fetchColumn() > 0;
            $canSave = !$isReady && !$alreadyLogged;
        }
        if ($canSave) {
            $type   = $_POST['schedule_type'] ?? '';
            $tStart = trim($_POST['time_start']    ?? '');
            $tEnd   = trim($_POST['time_end']      ?? '');
            $tid    = trim($_POST['teacher_ref_id']?? '');
            $tname  = '';
            if ($tid) {
                $tr = $connection2->prepare("SELECT displayName FROM sevenj_teachers WHERE id=?");
                $tr->execute([$tid]);
                $trow = $tr->fetch(PDO::FETCH_ASSOC);
                if ($trow) $tname = $trow['displayName'];
            }
            if ($type === 'weekly') {
                $day  = trim($_POST['day_of_week'] ?? '');
                $stmt = $connection2->prepare("UPDATE sevenj_schedule SET day_of_week=?, time_start=?, time_end=?, teacher_ref_id=?, teacher_name=? WHERE id=?");
                $stmt->execute([$day, $tStart, $tEnd, $tid?:null, $tname?:null, $id]);
            } else {
                $date = trim($_POST['specific_date'] ?? '');
                $stmt = $connection2->prepare("UPDATE sevenj_schedule SET specific_date=?, time_start=?, time_end=?, teacher_ref_id=?, teacher_name=? WHERE id=?");
                $stmt->execute([$date, $tStart, $tEnd, $tid?:null, $tname?:null, $id]);
            }
        }
    }
    header("Location: /MyNewShool/?$qs");
    exit;
}

// ─── POST: Complete class ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete') {
    $id   = (int)($_POST['schedule_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    if ($id) {
        $noteQ = "'".$connection2->quote($note)."'";
        $connection2->query("UPDATE sevenj_schedule SET completed_classes = LEAST(completed_classes+1, total_classes), note=$noteQ WHERE id=$id");
    }
    $qs = http_build_query(['q'=>'/modules/7j/class_schedule.php','view'=>$_POST['view']??'list','student'=>$_POST['student_filter']??'','search'=>$_POST['search_val']??'']);
    header("Location: /MyNewShool/?$qs");
    exit;
}

// ─── Constants — Sunday first ─────────────────────────────────────────────────
$DAYS_EN   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$DAYS_TH   = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
$DAY_ORDER = array_flip($DAYS_EN);
$dayThMap  = array_combine($DAYS_EN, $DAYS_TH);

// ─── คำนวณวันที่สัปดาห์นี้ (เริ่มอาทิตย์) ─────────────────────────────────────
$todayDow   = (int)date('w');                      // 0=Sun
$weekSunday = strtotime("-{$todayDow} days");
$todayName  = date('l');
$weekDates  = [];
foreach ($DAYS_EN as $i => $d) {
    $weekDates[$d] = date('Y-m-d', strtotime("+{$i} days", $weekSunday));
}
$weekDatesDisplay = [];
foreach ($weekDates as $d => $dt) {
    $weekDatesDisplay[$d] = date('j/n', strtotime($dt));
}

// ─── Params ───────────────────────────────────────────────────────────────────
$view            = $_GET['view']    ?? 'list';
$selectedStudent = trim($_GET['student'] ?? '');
$search          = trim($_GET['search']  ?? '');

// ─── Query (JOIN sevenj_students + sevenj_teachers) ──────────────────────────
$where = []; $bindP = [];
if ($selectedStudent) { $where[] = "(s.student_name=? OR st.displayName=?)"; $bindP[]=$selectedStudent; $bindP[]=$selectedStudent; }
if ($search) {
    $where[] = "(s.student_name LIKE ? OR s.student_code LIKE ? OR s.teacher_name LIKE ? OR s.course LIKE ? OR st.displayName LIKE ? OR t.displayName LIKE ?)";
    $l='%'.$search.'%'; $bindP[]=$l; $bindP[]=$l; $bindP[]=$l; $bindP[]=$l; $bindP[]=$l; $bindP[]=$l;
}
$whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

$stmtRows = $connection2->prepare("
    SELECT s.*,
        COALESCE(st.displayName, s.student_name) AS disp_student,
        COALESCE(st.studentCode, s.student_code) AS disp_code,
        COALESCE(t.displayName,  s.teacher_name) AS disp_teacher,
        (SELECT COUNT(*) FROM sevenj_class_completions c
         WHERE c.schedule_id = s.id AND c.completed_date = CURDATE()) AS logged_today,
        (SELECT COUNT(*) FROM sevenj_class_completions c WHERE c.student_id = s.student_id) AS actual_completed
    FROM sevenj_schedule s
    LEFT JOIN sevenj_students st ON st.id = s.student_id
    LEFT JOIN sevenj_teachers t  ON t.id  = s.teacher_ref_id
    $whereSQL
    ORDER BY disp_student, s.day_of_week, s.time_start
");
$stmtRows->execute($bindP);
$rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

$allStudents = $connection2->query("
    SELECT DISTINCT
        COALESCE(st.displayName, s.student_name) AS student_name,
        COALESCE(st.studentCode, s.student_code) AS student_code
    FROM sevenj_schedule s
    LEFT JOIN sevenj_students st ON st.id = s.student_id
    WHERE s.student_name IS NOT NULL AND s.student_name != ''
    ORDER BY student_name
")->fetchAll(PDO::FETCH_ASSOC);

// ─── Group: student → active_slots / old_slots ───────────────────────────────
$byStudent = [];
foreach ($rows as $r) {
    $dispName = $r['disp_student'] ?: $r['student_name'];
    $dispCode = $r['disp_code']    ?: $r['student_code'];
    $key = $dispName.'||'.$dispCode;
    if (!isset($byStudent[$key])) {
        $byStudent[$key] = [
            'name'  => $dispName, 'code' => $dispCode,
            'total' => 0, 'done' => 0,
            'active_slots' => [], 'old_slots' => [],
        ];
    }
    $r['_disp_teacher'] = $r['disp_teacher'] ?: $r['teacher_name'];
    $isCompleted = ($r['status'] === 'completed');
    if ($r['schedule_type'] === 'one_time') {
        $isReady = csIsSlotReady('', $r['time_start']??'', 'one_time', $r['specific_date']??'');
        $r['_eff_completed'] = ($isReady && (int)$r['completed_classes'] > 0) ? 1 : 0;
        if (!$isCompleted) $byStudent[$key]['total'] = max($byStudent[$key]['total'], (int)$r['total_classes']);
    } else {
        $r['_eff_completed'] = (int)$r['completed_classes'];
        if (!$isCompleted) $byStudent[$key]['total'] += (int)$r['total_classes'];
    }
    if ($isCompleted) {
        $byStudent[$key]['old_slots'][] = $r;
    } else {
        $byStudent[$key]['active_slots'][] = $r;
    }
    // done = COUNT จริงจาก sevenj_class_completions ต่อนักเรียน (ไม่ใช่ accumulate per slot)
    $byStudent[$key]['done'] = (int)$r['actual_completed'];
}
// Recalculate total/done จาก slot times (อ้างอิงเดียวกับตารางสอนครู)
foreach ($byStudent as &$v) {
    $allSlots = array_merge($v['active_slots'] ?? [], $v['old_slots'] ?? []);
    $v['total'] = count($allSlots);
    $v['done']  = 0;
    foreach ($allSlots as $sl) {
        if (csIsSlotReady($sl['day_of_week'] ?? '', $sl['time_start'] ?? '', $sl['schedule_type'] ?? 'weekly', $sl['specific_date'] ?? ''))
            $v['done']++;
    }
    $v['slots'] = $v['active_slots'];
}
unset($v);
uasort($byStudent, fn($a,$b) => strcmp($a['name'],$b['name']));

// ─── Teachers + Availability (สำหรับ edit modal) ─────────────────────────────
$csTeachers = $connection2->query("SELECT id, displayName, teacherCode FROM sevenj_teachers WHERE status='active' ORDER BY displayName")->fetchAll(PDO::FETCH_ASSOC);
$csAvailSlots = $connection2->query("SELECT teacher_id, type, day, specific_date, start_time, end_time, note FROM sevenj_teacher_availability ORDER BY teacher_id, start_time")->fetchAll(PDO::FETCH_ASSOC);
$csAvailByTeacher = [];
foreach ($csAvailSlots as $av) { $csAvailByTeacher[$av['teacher_id']][] = $av; }

// ─── Calendar grid ────────────────────────────────────────────────────────────
$calGrid  = []; $timesSet = [];
foreach ($rows as $r) {
    $day = $r['schedule_type']==='one_time' && $r['specific_date']
        ? date('l', strtotime($r['specific_date']))
        : $r['day_of_week'];
    $t = $r['time_start'];
    if ($t && $day) {
        $timesSet[$t] = true;
        $gKey = "$day-$t";
        $calGrid[$gKey][] = $r;
    }
}
ksort($timesSet);
$sortedTimes = array_keys($timesSet);

// ─── Gibbon Calendar: pixel helpers ──────────────────────────────────────────
$CS_PX_HOUR = 80;
$CS_START   = '07:00';
$CS_END     = '21:00';
if (!empty($sortedTimes)) {
    $fh = max(6, (int)substr($sortedTimes[0], 0, 2) - 1);
    $CS_START = sprintf('%02d:00', $fh);
    $latestEnd = '20:00';
    foreach ($calGrid as $cells) {
        foreach ($cells as $c) {
            $te = $c['time_end'] ?? '';
            if ($te && $te > $latestEnd) $latestEnd = $te;
        }
    }
    $CS_END = sprintf('%02d:00', min(23, (int)substr($latestEnd, 0, 2) + 1));
}
function csToPx(string $t, string $s, int $px): int {
    [$sh,$sm] = array_map('intval', explode(':', $s));
    [$th,$tm] = array_map('intval', explode(':', $t.':00'));
    return (int)((($th-$sh)*60+($tm-$sm))*$px/60);
}
function csDuration(string $s, string $e, int $px): int {
    if (!$e || $e<=$s) return $px;
    [$sh,$sm]=array_map('intval',explode(':',$s.':00'));
    [$eh,$em]=array_map('intval',explode(':',$e.':00'));
    return max(24,(int)((($eh-$sh)*60+($em-$sm))*$px/60));
}
$csHours = [];
[$csSH] = array_map('intval', explode(':', $CS_START));
[$csEH] = array_map('intval', explode(':', $CS_END));
for ($h=$csSH; $h<=$csEH; $h++) $csHours[] = sprintf('%02d:00',$h);
$csTotalH = csToPx($CS_END, $CS_START, $CS_PX_HOUR);
$csNow    = (new DateTime('now', new DateTimeZone('Asia/Bangkok')))->format('H:i');
$csNowPx  = ($csNow>=$CS_START && $csNow<=$CS_END) ? csToPx($csNow,$CS_START,$CS_PX_HOUR) : -1;

// เวลาเก็บแบบ 24h จริง — แปลงเป็น 12h AM/PM
function fmtTimePM(string $t): string {
    if ($t === '') return '';
    [$h, $m] = array_pad(explode(':', $t), 2, '00');
    $h = (int)$h;
    $suffix = $h >= 12 ? 'PM' : 'AM';
    $h12    = $h % 12 ?: 12;
    return $h12 . ':' . $m . ' ' . $suffix;
}

// ─── Thailand time helper ─────────────────────────────────────────────────────
function csIsSlotReady(string $day, string $time, string $type='weekly', string $specificDate=''): bool {
    $thNow  = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
    $thHM   = (int)$thNow->format('H') * 60 + (int)$thNow->format('i');
    [$h,$m] = array_map('intval', explode(':', $time.':00'));
    $slotHM = $h * 60 + $m;
    if ($type === 'one_time' && $specificDate) {
        $slotDate = new DateTime($specificDate, new DateTimeZone('Asia/Bangkok'));
        $today    = new DateTime('today', new DateTimeZone('Asia/Bangkok'));
        if ($slotDate > $today) return false;
        if ($slotDate < $today) return true;
        return $thHM >= $slotHM;
    }
    if ($thNow->format('l') !== $day) return false;
    return $thHM >= $slotHM;
}

// ─── Stats ────────────────────────────────────────────────────────────────────
$totalSchedules = count($rows);
// one_time = 1 คาบต่อ row; weekly = ตาม total_classes
// คาบทั้งหมด = ผลรวม total จาก byStudent (one_time ใช้ MAX แล้ว, weekly ใช้ SUM แล้ว)
$totalClasses = array_sum(array_column($byStudent, 'total'));
$totalDone    = array_sum(array_column($byStudent, 'done'));
$totalRemain  = $totalClasses - $totalDone;

function avatarColor($name) {
    $colors = ['#ea580c','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2','#65a30d'];
    $idx = array_sum(array_map('ord', str_split($name ?? '?'))) % count($colors);
    return $colors[$idx];
}
function initials2($name) {
    $words = preg_split('/\s+/', trim($name ?? ''));
    $ini = '';
    foreach ($words as $w) { if ($w) $ini .= mb_strtoupper(mb_substr($w,0,1)); }
    return mb_substr($ini,0,2) ?: '?';
}
?>

<?php require_once __DIR__.'/_theme.php'; ?>
<style>
.cs-card{background:#fff;border-radius:10px;padding:.85rem 1rem;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:8px;}
.cs-avatar{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9rem;color:#fff;flex-shrink:0;}
.cs-badge{background:#fff7ed;color:#9a3412;border-radius:99px;padding:1px 9px;font-size:.72rem;font-weight:700;display:inline-block;}
.cs-badge-day{background:#dbeafe;color:#1e40af;border-radius:6px;padding:2px 8px;font-size:.75rem;font-weight:600;}
.cs-badge-date{background:#fef3c7;color:#92400e;border-radius:6px;padding:2px 8px;font-size:.75rem;font-weight:600;}
.cs-slot{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 10px;background:#f8f7ff;border-radius:8px;margin-bottom:5px;flex-wrap:wrap;}
.cs-prog{height:5px;border-radius:99px;background:#e5e7eb;overflow:hidden;flex:1;max-width:100px;}
.cs-prog-bar{height:100%;border-radius:99px;}
.cs-btn{padding:6px 16px;border-radius:7px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-block;}
.cs-btn-active{background:#ea580c;color:#fff;}
.cs-btn-inactive{background:#e5e7eb;color:#374151;}
.cs-btn-inactive:hover{background:#d1d5db;}
.cs-search{padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:.88rem;outline:none;}
.cs-search:focus{border-color:#ea580c;box-shadow:0 0 0 2px #fff7ed;}
.cal-cell{border:1px solid #e5e7eb;border-radius:7px;padding:5px;min-height:48px;vertical-align:top;}
.cal-chip{background:#fff7ed;border-radius:6px;padding:4px 7px;margin:2px 0;color:#9a3412;font-size:.7rem;}
.cal-chip.ready{background:#dcfce7;color:#166534;}
/* Modal */
.cs-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center;}
.cs-modal-bg.open{display:flex;}
.cs-modal{background:#fff;border-radius:14px;width:100%;max-width:420px;padding:1.5rem;box-shadow:0 20px 60px rgba(0,0,0,.25);}
.cs-btn-edit{background:none;border:1px solid #d1d5db;border-radius:6px;padding:3px 8px;font-size:.75rem;cursor:pointer;color:#6b7280;line-height:1.4;}
.cs-btn-edit:hover{background:#f3f4f6;border-color:#9ca3af;color:#374151;}
.cs-form-label{font-size:.8rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;}
.cs-form-input{width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 10px;font-size:.9rem;box-sizing:border-box;outline:none;}
.cs-form-input:focus{border-color:#ea580c;box-shadow:0 0 0 2px #fff7ed;}
</style>

<div style="max-width:100%;padding-bottom:2rem;">

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:1rem;">
    <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0;">🗓 Class Schedule</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="?q=/modules/7j/class_schedule.php&view=list<?= $selectedStudent?'&student='.urlencode($selectedStudent):'' ?><?= $search?'&search='.urlencode($search):'' ?>"
           class="cs-btn cs-btn-active">📋 List</a>
    </div>
</div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:1.25rem;">
<?php foreach ([
    ['🗓','ตารางเรียน',$totalSchedules,'#ea580c'],
    ['📚','คาบทั้งหมด',$totalClasses,'#2563eb'],
    ['✅','เรียนแล้ว',$totalDone,'#059669'],
    ['⏳','คงเหลือ',$totalRemain,'#d97706'],
] as [$ic,$lb,$vl,$co]): ?>
<div style="background:#fff;border-radius:10px;padding:.75rem 1rem;box-shadow:0 1px 4px rgba(0,0,0,.07);border-left:4px solid <?= $co ?>;">
    <div style="font-size:1.1rem;"><?= $ic ?></div>
    <div style="font-size:1.5rem;font-weight:800;color:#1f2937;"><?= number_format($vl) ?></div>
    <div style="font-size:.72rem;color:#6b7280;"><?= $lb ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- Filters -->
<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1.25rem;align-items:center;">
    <input type="hidden" name="q" value="/modules/7j/class_schedule.php">
    <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="cs-search" placeholder="ค้นหาชื่อ, รหัส, ครู, คอร์ส..." style="flex:1;min-width:160px;">
    <select name="student" class="cs-search">
        <option value="">นักเรียนทั้งหมด</option>
        <?php foreach ($allStudents as $st): ?>
        <option value="<?= htmlspecialchars($st['student_name']) ?>" <?= $selectedStudent===$st['student_name']?'selected':'' ?>>
            <?= htmlspecialchars($st['student_name']) ?><?= $st['student_code']?' ('.$st['student_code'].')':'' ?>
        </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="cs-btn cs-btn-active">ค้นหา</button>
    <?php if ($search || $selectedStudent): ?>
    <a href="?q=/modules/7j/class_schedule.php&view=<?= $view ?>" class="cs-btn cs-btn-inactive">✕ ล้าง</a>
    <?php endif; ?>
</form>

<?php if (empty($rows)): ?>
<div style="text-align:center;padding:3rem 1rem;color:#9ca3af;">
    <div style="font-size:2.5rem;margin-bottom:.5rem;">🗓</div>
    ไม่พบตารางเรียน<?= $selectedStudent?' ของ '.htmlspecialchars($selectedStudent):'' ?>
    <br><a href="?q=/modules/7j/schedule_center.php&tab=schedule" style="color:#ea580c;font-size:.9rem;">+ เพิ่มตารางเรียน</a>
</div>

<?php elseif ($view === 'list'): ?>
<!-- ════ LIST VIEW ════ -->
<?php foreach ($byStudent as $s):
    $pct     = $s['total'] > 0 ? min(100, round($s['done']/$s['total']*100)) : 0;
    $remain  = max(0, $s['total'] - $s['done']);
    $bg      = avatarColor($s['name']);
    $ini     = initials2($s['name']);
    $barCol  = $pct >= 80 ? '#dc2626' : ($pct >= 50 ? '#d97706' : '#ea580c');
?>
<div class="cs-card">
    <div style="display:flex;align-items:flex-start;gap:12px;">
        <div class="cs-avatar" style="background:<?= $bg ?>;"><?= htmlspecialchars($ini) ?></div>
        <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;margin-bottom:4px;">
                <span style="font-weight:700;font-size:.95rem;"><?= htmlspecialchars($s['name']) ?></span>
                <?php if ($s['code']): ?><span class="cs-badge"><?= htmlspecialchars($s['code']) ?></span><?php endif; ?>
            </div>
            <?php if ($s['total'] > 0): ?>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                <span style="font-size:.78rem;color:#374151;font-weight:600;"><?= $s['done'] ?>/<?= $s['total'] ?> คาบ</span>
                <?php if ($remain > 0): ?><span style="font-size:.72rem;color:#9ca3af;">(เหลือ <?= $remain ?>)</span><?php endif; ?>
                <div class="cs-prog" style="max-width:80px;"><div class="cs-prog-bar" style="width:<?= $pct ?>%;background:<?= $barCol ?>;"></div></div>
            </div>
            <?php endif; ?>
            <?php
            // ── Active slots (คอร์สปัจจุบัน) ──
            foreach ($s['active_slots'] as $slotIdx => $slot):
                $isOne       = $slot['schedule_type']==='one_time';
                $dayLbl      = $isOne ? '📅 '.fmtDate($slot['specific_date']) : '🗓 '.($dayThMap[$slot['day_of_week']]??$slot['day_of_week']);
                $loggedToday = (int)($slot['logged_today'] ?? 0) > 0;
                $slotReady   = csIsSlotReady($slot['day_of_week']??'', $slot['time_start']??'', $slot['schedule_type']??'weekly', $slot['specific_date']??'');
                $canEdit     = !$slotReady && !$loggedToday;
                $isNew       = ($slot['note'] === 'คอร์สใหม่');
            ?>
            <div class="cs-slot" style="<?= $isNew ? 'border-left:3px solid #86efac;background:#f0fdf4;' : '' ?>">
                <div style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;flex:1;">
                    <span style="width:18px;height:18px;border-radius:50%;background:<?= $isNew?'#16a34a':'#ea580c' ?>;color:#fff;font-size:.65rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;"><?= $slotIdx+1 ?></span>
                    <?php if ($isNew): ?><span style="background:#dbeafe;color:#1e40af;border-radius:20px;padding:1px 7px;font-size:.67rem;font-weight:700;">🆕 ใหม่</span><?php endif; ?>
                    <?php if ($isOne): ?><span class="cs-badge-date"><?= $dayLbl ?></span>
                    <?php else: ?><span class="cs-badge-day"><?= $dayLbl ?></span><?php endif; ?>
                    <span style="font-size:.8rem;color:#374151;">⏰ <?= fmtTimePM($slot['time_start']??'') ?><?= $slot['time_end']?' – '.fmtTimePM($slot['time_end']):'' ?></span>
                    <span style="font-size:.8rem;color:#6b7280;">👨‍🏫 <?= htmlspecialchars($slot['_disp_teacher']??$slot['teacher_name']) ?></span>
                    <?php if ($slot['course']): ?><span style="font-size:.75rem;color:#059669;">📚 <?= htmlspecialchars($slot['course']) ?></span><?php endif; ?>
                </div>
                <?php if ($canEdit): ?>
                <button class="cs-btn-edit" onclick="openEditSchedule(<?= (int)$slot['id'] ?>,'<?= htmlspecialchars($slot['schedule_type']) ?>','<?= htmlspecialchars($slot['day_of_week']??'') ?>','<?= htmlspecialchars($slot['specific_date']??'') ?>','<?= htmlspecialchars($slot['time_start']??'') ?>','<?= htmlspecialchars($slot['time_end']??'') ?>','<?= htmlspecialchars($slot['teacher_ref_id']??'') ?>')" title="แก้ไข">✏️ แก้ไข</button>
                <?php else: ?><span style="font-size:.72rem;color:#9ca3af;" title="ล็อค">🔒</span><?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (!empty($s['old_slots'])): ?>
            <!-- คอร์สเก่า separator -->
            <div style="margin:8px 0 4px;padding:3px 8px;background:#f3f4f6;border-radius:6px;font-size:.7rem;color:#9ca3af;font-weight:600;display:flex;align-items:center;gap:5px;">
                ✅ คอร์สเก่า (เรียนครบแล้ว)
            </div>
            <?php foreach ($s['old_slots'] as $slot):
                $isOne  = $slot['schedule_type']==='one_time';
                $dayLbl = $isOne ? '📅 '.fmtDate($slot['specific_date']) : '🗓 '.($dayThMap[$slot['day_of_week']]??$slot['day_of_week']);
            ?>
            <div class="cs-slot" style="opacity:.55;background:#f9fafb;border-left:3px solid #d1d5db;">
                <div style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;flex:1;">
                    <?php if ($isOne): ?><span class="cs-badge-date"><?= $dayLbl ?></span>
                    <?php else: ?><span class="cs-badge-day"><?= $dayLbl ?></span><?php endif; ?>
                    <span style="font-size:.8rem;color:#6b7280;">⏰ <?= fmtTimePM($slot['time_start']??'') ?><?= $slot['time_end']?' – '.fmtTimePM($slot['time_end']):'' ?></span>
                    <span style="font-size:.8rem;color:#9ca3af;">👨‍🏫 <?= htmlspecialchars($slot['_disp_teacher']??$slot['teacher_name']) ?></span>
                    <?php if ($slot['course']): ?><span style="font-size:.75rem;color:#9ca3af;">📚 <?= htmlspecialchars($slot['course']) ?></span><?php endif; ?>
                </div>
                <span style="font-size:.7rem;color:#9ca3af;">🔒</span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php else: ?>
<!-- ════ CALENDAR VIEW — Gibbon Style ════ -->
<?php if (empty($sortedTimes)): ?>
<div style="text-align:center;padding:2rem;color:#9ca3af;">ไม่มีข้อมูลตารางเรียน</div>
<?php else: ?>

<!-- Day headers -->
<div style="display:flex;gap:0;margin-bottom:2px;">
    <div style="width:52px;flex-shrink:0;"></div>
    <?php foreach ($DAYS_EN as $i => $d):
        $isToday = ($d === $todayName);
        $hasCell = false;
        foreach ($calGrid as $k=>$v){ if(str_starts_with($k,"$d-")){$hasCell=true;break;} }
        [$dNum,$dMon] = explode('/', $weekDatesDisplay[$d].'/');
    ?>
    <div style="flex:1;text-align:center;padding:6px 2px;margin:0 1px;cursor:default;
                border:1px solid <?= $isToday?'#ea580c':'#e5e7eb' ?>;border-bottom:none;border-radius:6px 6px 0 0;
                background:<?= $isToday?'#ea580c':($hasCell?'#fff7ed':'#f9fafb') ?>;">
        <div style="font-size:.72rem;font-weight:700;color:<?= $isToday?'#fff':'#374151' ?>;"><?= $DAYS_TH[$i] ?></div>
        <div style="font-size:1.1rem;font-weight:800;line-height:1.1;color:<?= $isToday?'#fff':'#1f2937' ?>;">
            <?= ltrim(explode('/', $weekDatesDisplay[$d])[0],'0') ?>
        </div>
        <div style="font-size:.62rem;color:<?= $isToday?'rgba(255,255,255,.8)':'#9ca3af' ?>;">
            <?= explode('/', $weekDatesDisplay[$d])[1]??'' ?> · <?= substr($d,0,3) ?>
        </div>
        <?php if ($isToday): ?><div style="width:6px;height:6px;border-radius:50%;background:#fff;margin:2px auto 0;opacity:.9;"></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Calendar body -->
<div style="overflow-x:auto;">
<div style="display:flex;gap:0;min-width:600px;">

    <!-- Time axis -->
    <div style="width:52px;flex-shrink:0;position:relative;height:<?= $csTotalH ?>px;">
        <?php foreach ($csHours as $hr): ?>
        <div style="position:absolute;top:<?= csToPx($hr,$CS_START,$CS_PX_HOUR) ?>px;left:0;right:4px;text-align:right;">
            <span style="font-size:.68rem;color:#9ca3af;font-weight:500;line-height:1;"><?= $hr ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Day columns -->
    <?php foreach ($DAYS_EN as $di => $d):
        $isToday = ($d === $todayName);
    ?>
    <div style="flex:1;position:relative;height:<?= $csTotalH ?>px;margin:0 1px;overflow:hidden;
                border:1px solid <?= $isToday?'#ea580c':'#e5e7eb' ?>;border-top:none;border-radius:0 0 6px 6px;
                background:<?= $isToday?'#fffbeb':'#fff' ?>;">

        <!-- Hour grid lines -->
        <?php foreach ($csHours as $hr): ?>
        <div style="position:absolute;top:<?= csToPx($hr,$CS_START,$CS_PX_HOUR) ?>px;left:0;right:0;
                    border-top:1px solid <?= $hr===$CS_START?'transparent':'#f0f0f0' ?>;"></div>
        <?php endforeach; ?>

        <!-- Current time line -->
        <?php if ($isToday && $csNowPx >= 0): ?>
        <div style="position:absolute;top:<?= $csNowPx ?>px;left:0;right:0;z-index:10;pointer-events:none;">
            <div style="border-top:2px solid #3b82f6;position:relative;">
                <div style="position:absolute;left:-1px;top:-5px;width:8px;height:8px;border-radius:50%;background:#3b82f6;"></div>
                <div style="position:absolute;right:0;top:-4px;width:6px;height:6px;border-radius:50%;background:#3b82f6;"></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Events -->
        <?php foreach ($calGrid as $gKey => $cells):
            if (!str_starts_with($gKey,"$d-")) continue;
            foreach ($cells as $r):
                $tStart   = $r['time_start'];
                $tEnd     = $r['time_end'] ?? '';
                $topPx    = csToPx($tStart, $CS_START, $CS_PX_HOUR);
                $heightPx = csDuration($tStart, $tEnd, $CS_PX_HOUR);
                $ready    = csIsSlotReady($d, $tStart, $r['schedule_type']??'weekly', $r['specific_date']??'');
                $full     = (int)$r['completed_classes'] >= (int)$r['total_classes'];
                $pct2     = $r['total_classes']>0 ? min(100,round($r['completed_classes']/$r['total_classes']*100)) : 0;
                $chipBg   = $ready ? '#dcfce7' : '#fff7ed';
                $chipBd   = $ready ? '#86efac' : '#fed7aa';
                $chipFg   = $ready ? '#166534' : '#9a3412';
                $chipAcc  = $ready ? '#059669' : '#ea580c';
        ?>
        <div style="position:absolute;top:<?= $topPx+1 ?>px;left:2px;right:2px;height:<?= $heightPx-2 ?>px;
                    background:<?= $chipBg ?>;border:1px solid <?= $chipBd ?>;border-left:3px solid <?= $chipAcc ?>;
                    border-radius:4px;overflow:hidden;padding:3px 5px;font-size:.68rem;z-index:5;">
            <!-- เวลา -->
            <div style="color:<?= $chipFg ?>;font-weight:700;font-size:.63rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?= htmlspecialchars($tStart) ?><?= $tEnd?'–'.htmlspecialchars($tEnd):'' ?>
            </div>
            <!-- นักเรียน -->
            <div style="font-weight:700;color:#1f2937;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?= htmlspecialchars($r['disp_student']??$r['student_name']) ?>
            </div>
            <!-- ครู -->
            <div style="color:#6b7280;font-size:.63rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?= htmlspecialchars($r['_disp_teacher']??$r['teacher_name']) ?>
            </div>
            <!-- Progress + บันทึก -->
            <div style="display:flex;align-items:center;gap:3px;margin-top:2px;">
                <span style="font-size:.6rem;color:<?= $chipFg ?>;white-space:nowrap;"><?= (int)$r['completed_classes'] ?>/<?= (int)$r['total_classes'] ?></span>
                <?php if ($full): ?>
                <span style="color:#059669;font-size:.6rem;flex-shrink:0;">✓ครบ</span>
                <?php elseif ($ready): ?>
                <button onclick="openComplete(<?= (int)$r['id'] ?>,'<?= htmlspecialchars($r['student_name']) ?>','<?= htmlspecialchars(addslashes($r['teacher_name']??'')) ?>','<?= $d.' '.$tStart ?>','<?= htmlspecialchars($tStart) ?>','<?= $view ?>','<?= htmlspecialchars($selectedStudent) ?>','<?= htmlspecialchars($search) ?>')"
                        style="background:#059669;color:#fff;border:none;border-radius:3px;padding:0 4px;font-size:.58rem;cursor:pointer;flex-shrink:0;line-height:14px;">✓</button>
                <?php endif; ?>
            </div>
            <!-- Progress bar -->
            <?php if ($pct2 > 0): ?>
            <div style="height:2px;border-radius:99px;background:#e5e7eb;margin-top:2px;overflow:hidden;">
                <div style="height:100%;width:<?= $pct2 ?>%;background:<?= $chipAcc ?>;border-radius:99px;"></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; endforeach; ?>

    </div>
    <?php endforeach; ?>
</div>
</div>

<?php endif; endif; ?>

</div><!-- end main -->

<!-- Edit Schedule Modal -->
<div id="modal-edit" class="cs-modal-bg">
<div class="cs-modal">
    <h3 style="margin:0 0 1rem;font-size:1.1rem;font-weight:700;color:#1f2937;">✏️ แก้ไขตารางเรียน</h3>
    <form method="post">
        <input type="hidden" name="action"        value="edit_schedule">
        <input type="hidden" name="q"             value="/modules/7j/class_schedule.php">
        <input type="hidden" name="schedule_id"   id="e-id">
        <input type="hidden" name="schedule_type" id="e-type">
        <input type="hidden" name="view"          id="e-view"   value="<?= htmlspecialchars($view) ?>">
        <input type="hidden" name="student_filter" id="e-student" value="<?= htmlspecialchars($selectedStudent) ?>">
        <input type="hidden" name="search_val"    id="e-search"  value="<?= htmlspecialchars($search) ?>">

        <!-- ครู -->
        <div style="margin-bottom:.85rem;">
            <label class="cs-form-label">👨‍🏫 ครูผู้สอน</label>
            <select name="teacher_ref_id" id="e-teacher-id" class="cs-form-input" onchange="csOnTeacher(this)">
                <option value="">— เลือกครู —</option>
                <?php foreach ($csTeachers as $ct): ?>
                <option value="<?= htmlspecialchars($ct['id']) ?>" data-name="<?= htmlspecialchars($ct['displayName']) ?>">
                    <?= htmlspecialchars($ct['displayName']) ?> (<?= htmlspecialchars($ct['teacherCode']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <!-- ช่วงเวลาว่างครู -->
        <div id="e-avail-box" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:8px 12px;margin-bottom:.85rem;font-size:.78rem;">
            <div style="font-weight:700;color:#166534;margin-bottom:4px;">🕐 ช่วงเวลาว่างของครู: <span style="font-size:.72rem;color:#4b5563;">(กดเลือกเพื่อ auto-fill)</span></div>
            <div id="e-avail-list"></div>
        </div>

        <!-- วันสำหรับ weekly -->
        <div id="e-row-day" style="margin-bottom:.85rem;">
            <label class="cs-form-label">📅 วัน</label>
            <select name="day_of_week" id="e-day" class="cs-form-input">
                <?php foreach ($DAYS_EN as $i => $den): ?>
                <option value="<?= $den ?>"><?= $DAYS_TH[$i] ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- วันที่สำหรับ one_time -->
        <div id="e-row-date" style="margin-bottom:.85rem;display:none;">
            <label class="cs-form-label">📅 วันที่</label>
            <input type="date" name="specific_date" id="e-date" class="cs-form-input">
        </div>

        <!-- เวลาเริ่ม -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:.85rem;">
            <div>
                <label class="cs-form-label">⏰ เวลาเริ่ม</label>
                <input type="time" name="time_start" id="e-time-start" class="cs-form-input">
            </div>
            <div>
                <label class="cs-form-label">⏰ เวลาสิ้นสุด</label>
                <input type="time" name="time_end" id="e-time-end" class="cs-form-input">
            </div>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="cs-btn cs-btn-inactive" onclick="closeEditSchedule()">ยกเลิก</button>
            <button type="submit" class="cs-btn" style="background:#ea580c;color:#fff;">💾 บันทึก</button>
        </div>
    </form>
</div>
</div>

<!-- Complete Class Modal -->
<div id="modal-complete" class="cs-modal-bg">
<div class="cs-modal">
    <h3 style="margin:0 0 4px;font-size:1.1rem;font-weight:700;color:#1f2937;">✅ บันทึกการเรียน</h3>
    <div style="font-size:.85rem;color:#6b7280;margin-bottom:1rem;">
        นักเรียน: <strong id="c-student"></strong><br>
        ครู: <strong id="c-teacher"></strong><br>
        เวลา: <strong id="c-time"></strong>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="complete">
        <input type="hidden" name="q" value="/modules/7j/class_schedule.php">
        <input type="hidden" name="schedule_id" id="c-id">
        <input type="hidden" name="view" id="c-view">
        <input type="hidden" name="student_filter" id="c-student-filter">
        <input type="hidden" name="search_val" id="c-search">
        <div style="margin-bottom:1rem;">
            <label style="font-size:.8rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;">หมายเหตุ (ถ้ามี)</label>
            <textarea name="note" rows="2" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:.9rem;box-sizing:border-box;resize:vertical;outline:none;"></textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="cs-btn cs-btn-inactive" onclick="closeComplete()">ยกเลิก</button>
            <button type="submit" class="cs-btn" style="background:#059669;color:#fff;">✅ ยืนยัน</button>
        </div>
    </form>
</div>
</div>

<script>
var csAvailData = <?= json_encode($csAvailByTeacher) ?>;
var CS_DAYS_TH  = {Sunday:'อาทิตย์',Monday:'จันทร์',Tuesday:'อังคาร',Wednesday:'พุธ',Thursday:'พฤหัสบดี',Friday:'ศุกร์',Saturday:'เสาร์'};

function fmt24AmPmCS(t) {
    if (!t) return t;
    var p=t.split(':'), h=parseInt(p[0]), m=p[1]||'00';
    var s=h>=12?'PM':'AM'; h=h%12||12;
    return h+':'+m+' '+s;
}
function csOnTeacher(sel) {
    csShowAvail(sel.value);
}
function csShowAvail(tid) {
    var box=document.getElementById('e-avail-box');
    var list=document.getElementById('e-avail-list');
    if (!tid || !csAvailData[tid] || !csAvailData[tid].length) { box.style.display='none'; return; }
    var html='';
    csAvailData[tid].forEach(function(s,i){
        var lbl = s.type==='specific_date' ? '📅 '+s.specific_date : '🗓 '+(CS_DAYS_TH[s.day]||s.day);
        html+='<button type="button" onclick="csPickSlot(\''+tid+'\','+i+')"'
            +' style="background:#dcfce7;color:#166534;border:1.5px solid #86efac;border-radius:20px;'
            +'padding:3px 11px;margin:2px;font-size:.75rem;cursor:pointer;font-weight:600;">'
            +lbl+' ⏰ '+fmt24AmPmCS(s.start_time)+'–'+fmt24AmPmCS(s.end_time)
            +(s.note?' ('+s.note+')':'')+'</button>';
    });
    list.innerHTML=html;
    box.style.display='';
}
function csPickSlot(tid,idx){
    var s=csAvailData[tid][idx];
    if(!s) return;
    document.getElementById('e-time-start').value=s.start_time;
    document.getElementById('e-time-end').value=s.end_time;
    if(s.type==='specific_date'){
        document.getElementById('e-type').value='one_time';
        document.getElementById('e-row-day').style.display='none';
        document.getElementById('e-row-date').style.display='';
        document.getElementById('e-date').value=s.specific_date||'';
    } else {
        document.getElementById('e-type').value='weekly';
        document.getElementById('e-row-day').style.display='';
        document.getElementById('e-row-date').style.display='none';
        document.getElementById('e-day').value=s.day||'Monday';
    }
    document.querySelectorAll('#e-avail-list button').forEach(function(b,i){
        b.style.background=i===idx?'#86efac':'#dcfce7';
    });
}

function openEditSchedule(id, type, day, date, tStart, tEnd, teacherId) {
    document.getElementById('e-id').value    = id;
    document.getElementById('e-type').value  = type;
    document.getElementById('e-time-start').value = tStart;
    document.getElementById('e-time-end').value   = tEnd;
    var tSel = document.getElementById('e-teacher-id');
    tSel.value = teacherId || '';
    csShowAvail(teacherId || '');
    if (type === 'weekly') {
        document.getElementById('e-row-day').style.display  = '';
        document.getElementById('e-row-date').style.display = 'none';
        document.getElementById('e-day').value = day;
    } else {
        document.getElementById('e-row-day').style.display  = 'none';
        document.getElementById('e-row-date').style.display = '';
        document.getElementById('e-date').value = date;
    }
    document.getElementById('modal-edit').classList.add('open');
}
function closeEditSchedule() {
    document.getElementById('modal-edit').classList.remove('open');
}
document.getElementById('modal-edit').addEventListener('click', function(e) {
    if (e.target === this) closeEditSchedule();
});

function openComplete(id, student, teacher, dayTime, time, view, studentFilter, search) {
    document.getElementById('c-id').value             = id;
    document.getElementById('c-student').textContent  = student;
    document.getElementById('c-teacher').textContent  = teacher;
    document.getElementById('c-time').textContent     = time;
    document.getElementById('c-view').value           = view;
    document.getElementById('c-student-filter').value = studentFilter || '';
    document.getElementById('c-search').value         = search || '';
    document.getElementById('modal-complete').classList.add('open');
}
function closeComplete() {
    document.getElementById('modal-complete').classList.remove('open');
}
document.getElementById('modal-complete').addEventListener('click', function(e) {
    if (e.target === this) closeComplete();
});
</script>
