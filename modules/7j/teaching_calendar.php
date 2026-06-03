<?php
/*
 * 7J English Center — Teaching & Learning Calendar
 * ปฏิทินการเรียน-การสอน รวม Add/Edit/Delete ในหน้าเดียว
 * สร้าง: 31/05/2026
 */

// ─── POST handlers (PDO prepared statements) ──────────────────────────────────
$action = $_POST['action'] ?? '';
$msg    = '';

function tcNull($v) { $s = trim($v ?? ''); return $s === '' ? null : $s; }

if ($action === 'add' || $action === 'edit') {
    $sid   = trim($_POST['student_id']     ?? '');
    $tid   = trim($_POST['teacher_ref_id'] ?? '');
    $sname = trim($_POST['student_name']   ?? '');
    $scode = trim($_POST['student_code']   ?? '');
    $tname = trim($_POST['teacher_name']   ?? '');

    if ($sid) {
        $r = $connection2->prepare('SELECT displayName,studentCode FROM sevenj_students WHERE id=?');
        $r->execute([$sid]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if ($row) { $sname = $row['displayName']; $scode = $row['studentCode'] ?? ''; }
    }
    if ($tid) {
        $r = $connection2->prepare('SELECT displayName FROM sevenj_teachers WHERE id=?');
        $r->execute([$tid]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if ($row) { $tname = $row['displayName']; }
    }

    $stype  = in_array($_POST['schedule_type'] ?? '', ['weekly','one_time']) ? $_POST['schedule_type'] : 'weekly';
    $day    = trim($_POST['day_of_week']    ?? '');
    $tstart = trim($_POST['time_start']     ?? '');
    $tend   = trim($_POST['time_end']       ?? '');
    $sdate  = trim($_POST['specific_date']  ?? '') ?: null;
    $course = trim($_POST['course']         ?? '');
    $total  = max(1, (int)($_POST['total_classes']     ?? 20));
    $done   = max(0, (int)($_POST['completed_classes'] ?? 0));
    $note   = trim($_POST['note']  ?? '');
    $status = in_array($_POST['status'] ?? '', ['active','completed','cancelled','pending']) ? $_POST['status'] : 'active';

    if (!$sname || !$tname) {
        $msg = 'error|กรุณากรอกชื่อนักเรียนและครู';
    } else {
        if ($action === 'add') {
            $stmt = $connection2->prepare(
                'INSERT INTO sevenj_schedule
                 (student_id,student_code,student_name,teacher_ref_id,teacher_name,
                  schedule_type,day_of_week,time_start,time_end,specific_date,
                  course,total_classes,completed_classes,status,note)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                tcNull($sid), $scode, $sname, tcNull($tid), $tname,
                $stype, $day, $tstart, $tend, $sdate,
                $course, $total, $done, $status, $note
            ]);
            $msg = 'success|เพิ่มตารางเรียนสำเร็จ';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $stmt = $connection2->prepare(
                    'UPDATE sevenj_schedule SET
                     student_id=?,student_code=?,student_name=?,teacher_ref_id=?,teacher_name=?,
                     schedule_type=?,day_of_week=?,time_start=?,time_end=?,specific_date=?,
                     course=?,total_classes=?,completed_classes=?,status=?,note=?
                     WHERE id=?'
                );
                $stmt->execute([
                    tcNull($sid), $scode, $sname, tcNull($tid), $tname,
                    $stype, $day, $tstart, $tend, $sdate,
                    $course, $total, $done, $status, $note, $id
                ]);
                $msg = 'success|อัปเดตตารางเรียนสำเร็จ';
            }
        }
    }
} elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $connection2->prepare('DELETE FROM sevenj_schedule WHERE id=?')->execute([$id]); // completions คงไว้ 30 วัน
        $msg = 'success|ลบตารางเรียนสำเร็จ';
    }
} elseif ($action === 'change_status') {
    $id = (int)($_POST['id'] ?? 0);
    $st = trim($_POST['new_status'] ?? '');
    if ($id && in_array($st, ['active','completed','cancelled','pending'])) {
        $connection2->prepare('UPDATE sevenj_schedule SET status=? WHERE id=?')->execute([$st, $id]);
        $msg = 'success|เปลี่ยนสถานะสำเร็จ';
    }
}

// ─── Calendar setup ───────────────────────────────────────────────────────────
$year  = max(2020, min(2035, (int)($_GET['year']  ?? date('Y'))));
$month = (int)($_GET['month'] ?? date('m'));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }
$month = max(1, min(12, $month));

$filterTeacher = trim($_GET['teacher'] ?? '');
$filterStatus  = trim($_GET['status']  ?? '');
if (!in_array($filterStatus, ['active','completed','cancelled','pending',''])) $filterStatus = '';

$firstDayTs  = mktime(0,0,0,$month,1,$year);
$daysInMonth = (int)date('t', $firstDayTs);
$startDow    = (int)date('w', $firstDayTs); // 0=Sunday
$monthStart  = sprintf('%04d-%02d-01', $year, $month);
$monthEnd    = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

// ─── Fetch data ───────────────────────────────────────────────────────────────
$conds = [];
if ($filterTeacher) $conds[] = "s.teacher_ref_id='".$connection2->quote($filterTeacher)."'";
if ($filterStatus)  $conds[] = "s.status='".$connection2->quote($filterStatus)."'";
$extraWhere = $conds ? 'AND '.implode(' AND ', $conds) : '';

$schedules = $connection2->query("
    SELECT s.*, st.displayName AS st_name, st.studentCode AS st_code,
           t.displayName AS t_name, t.teacherCode AS t_code
    FROM sevenj_schedule s
    LEFT JOIN sevenj_students st ON st.id = s.student_id
    LEFT JOIN sevenj_teachers  t ON  t.id = s.teacher_ref_id
    WHERE (s.schedule_type='weekly'
           OR (s.schedule_type='one_time' AND s.specific_date BETWEEN '$monthStart' AND '$monthEnd'))
    $extraWhere
    ORDER BY s.time_start
")->fetchAll(PDO::FETCH_ASSOC);

$allTeachers = $connection2->query("
    SELECT id,displayName,teacherCode,googleMeetLink FROM sevenj_teachers WHERE status='active' ORDER BY displayName
")->fetchAll(PDO::FETCH_ASSOC);

$allStudents = $connection2->query("
    SELECT id,displayName,studentCode,teacherId FROM sevenj_students WHERE status='active' ORDER BY displayName LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

// Teacher availability (for add/edit hint)
$availByTeacher = [];
$avRows = $connection2->query("
    SELECT teacher_id,type,day,specific_date,start_time,end_time,note
    FROM sevenj_teacher_availability ORDER BY teacher_id,type DESC,day,specific_date,start_time
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($avRows as $av) $availByTeacher[$av['teacher_id']][] = $av;

// ─── Build calendar grid data ─────────────────────────────────────────────────
$DAYS       = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$DAYS_TH    = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
$DAYS_S     = ['อา','จ','อ','พ','พฤ','ศ','ส'];
$MONTHS_TH  = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
               'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
$dayNameMap = array_combine($DAYS, $DAYS_TH);

$calData = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $ts_d  = mktime(0,0,0,$month,$d,$year);
    $dname = date('l', $ts_d);
    $dstr  = date('Y-m-d', $ts_d);
    $evs   = [];
    foreach ($schedules as $s) {
        if ($s['schedule_type'] === 'weekly'   && $s['day_of_week']   === $dname) { $e=$s; $e['_date']=$dstr; $evs[]=$e; }
        elseif ($s['schedule_type'] === 'one_time' && $s['specific_date'] === $dstr)  { $e=$s; $e['_date']=$dstr; $evs[]=$e; }
    }
    usort($evs, fn($a,$b) => strcmp($a['time_start']??'',$b['time_start']??''));
    $calData[$d] = $evs;
}

$totalEventsMonth = array_sum(array_map('count', $calData));
$todayD = (int)date('j'); $todayM = (int)date('n'); $todayY = (int)date('Y');
$isThisMonth = ($month === $todayM && $year === $todayY);

// Status config [color, bgColor, label, icon]
$SC = [
    'active'    => ['#ea580c','#fff7ed','กำลังเรียน','●'],
    'pending'   => ['#2563eb','#dbeafe','รอเรียน','●'],
    'completed' => ['#059669','#d1fae5','เรียนครบ','●'],
    'cancelled' => ['#9ca3af','#f3f4f6','ยกเลิก','●'],
];

[$alertType, $alertText] = $msg ? explode('|', $msg, 2) : ['',''];

// URL builder
function tcUrl($y,$m,$t='',$s='') {
    if ($m < 1) { $m=12; $y--; } if ($m > 12) { $m=1; $y++; }
    $u = '?q=/modules/7j/teaching_calendar.php&year='.$y.'&month='.$m;
    if ($t) $u .= '&teacher='.urlencode($t);
    if ($s) $u .= '&status='.urlencode($s);
    return $u;
}
$prevUrl  = tcUrl($year,$month-1,$filterTeacher,$filterStatus);
$nextUrl  = tcUrl($year,$month+1,$filterTeacher,$filterStatus);
$todayUrl = tcUrl($todayY,$todayM,$filterTeacher,$filterStatus);
$baseUrl  = '?q=/modules/7j/teaching_calendar.php&year='.$year.'&month='.$month;

function tcAvatarColor($name) {
    $c=['#ea580c','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2','#65a30d','#7c3aed'];
    return $c[array_sum(array_map('ord',str_split($name??'?')))%count($c)];
}
function tcInitials($name) {
    $w=preg_split('/\s+/',trim($name??'')); $i='';
    foreach($w as $x) if($x) $i.=mb_strtoupper(mb_substr($x,0,1));
    return mb_substr($i,0,2)?:'?';
}
?>
<?php require_once __DIR__.'/_theme.php'; ?>
<style>
/* ── Calendar layout ── */
.tc-wrap{max-width:100%;padding-bottom:2rem;}
.tc-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px;margin-top:4px;}
.tc-day-hdr{background:linear-gradient(135deg,#c2410c,#ea580c);color:#fff;text-align:center;padding:7px 4px;font-size:.75rem;font-weight:700;border-radius:5px;}
.tc-day-hdr.weekend{background:linear-gradient(135deg,#7c3aed,#a855f7);}
.tc-cell{background:#fff;border-radius:7px;min-height:90px;padding:5px 4px;border:1px solid #e5e7eb;position:relative;transition:border-color .15s;}
.tc-cell:hover{border-color:#fdba74;}
.tc-cell.today{border-color:#ea580c!important;border-width:2px;background:#fff7ed;}
.tc-cell.empty{background:#f9fafb;border-color:#f3f4f6;}
.tc-cell.weekend{background:#fdfaff;border-color:#e9d5ff;}
.tc-dnum{font-size:.8rem;font-weight:700;color:#374151;margin-bottom:4px;display:flex;align-items:center;justify-content:space-between;}
.tc-dnum-circle{width:22px;height:22px;background:#ea580c;color:#fff;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.73rem;font-weight:700;}
.tc-add-btn{opacity:0;display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#f3f4f6;color:#9ca3af;font-size:.8rem;cursor:pointer;border:none;transition:opacity .15s;}
.tc-cell:hover .tc-add-btn{opacity:1;}
.tc-add-btn:hover{background:#ea580c!important;color:#fff!important;}
.tc-event{display:block;border-radius:4px;padding:2px 5px;font-size:.68rem;margin-bottom:2px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;transition:opacity .15s,transform .1s;line-height:1.4;}
.tc-event:hover{opacity:.85;transform:scale(1.01);}
.tc-more{font-size:.65rem;color:#6b7280;cursor:pointer;background:#f3f4f6;border-radius:4px;padding:1px 5px;text-align:center;margin-top:2px;}
.tc-more:hover{background:#e5e7eb;}
/* ── Header & filter bar ── */
.tc-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:.75rem;}
.tc-month-nav{display:flex;align-items:center;gap:6px;}
.tc-month-title{font-size:1.25rem;font-weight:700;color:#1f2937;min-width:180px;text-align:center;}
.tc-nav-btn{padding:5px 12px;border-radius:6px;border:1px solid #d1d5db;background:#fff;font-size:.85rem;cursor:pointer;color:#374151;text-decoration:none;transition:background .15s;}
.tc-nav-btn:hover{background:#f3f4f6;}
.tc-nav-btn.today-btn{background:#ea580c;color:#fff;border-color:#ea580c;}
.tc-nav-btn.today-btn:hover{background:#c2410c;}
/* ── Filter bar ── */
.tc-filter{display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:#fff7ed;border:1px solid #fdba74;border-radius:10px;padding:.75rem 1rem;margin-bottom:.75rem;}
/* ── Legend ── */
.tc-legend{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:.5rem;font-size:.75rem;}
.tc-legend-item{display:flex;align-items:center;gap:4px;}
/* ── Modal ── */
.tc-modal{background:#fff;border-radius:14px;width:100%;max-width:560px;box-shadow:0 24px 64px rgba(0,0,0,.22);max-height:90vh;overflow-y:auto;}
.tc-modal-hdr{padding:14px 20px;background:linear-gradient(135deg,#c2410c,#ea580c);color:#fff;border-radius:14px 14px 0 0;}
.tc-modal-hdr h3{margin:0;font-size:1rem;font-weight:700;}
.tc-modal-hdr p{margin:2px 0 0;font-size:.8rem;opacity:.85;}
.tc-modal-body{padding:1.1rem 1.4rem;}
.tc-modal-foot{padding:.7rem 1.4rem;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:8px;background:#f9fafb;}
/* ── Day detail panel (bottom-sheet style) ── */
.tc-detail{background:#fff;border-radius:14px;width:100%;max-width:520px;box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden;max-height:80vh;overflow-y:auto;}
.tc-detail-hdr{padding:12px 18px;background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;}
.tc-event-row{display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid #f3f4f6;}
.tc-event-row:last-child{border-bottom:none;}
/* ── Autocomplete dropdown ── */
.tc-dropdown{display:none;position:absolute;z-index:9999;width:100%;background:#fff;border:1px solid #d1d5db;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.18);max-height:220px;overflow-y:auto;top:100%;left:0;margin-top:2px;}
.tc-dd-item{padding:8px 12px;cursor:pointer;font-size:.85rem;border-bottom:1px solid #f3f4f6;}
.tc-dd-item:hover{background:#fff7ed;}
/* ── Stats chips ── */
.tc-chip{display:inline-flex;align-items:center;gap:4px;border-radius:99px;padding:3px 10px;font-size:.75rem;font-weight:600;}
</style>

<div class="tc-wrap">

<?php if ($alertText): ?>
<div class="g-alert g-alert-<?= $alertType === 'success' ? 'success' : 'error' ?>" style="margin-bottom:.75rem;">
    <?= htmlspecialchars($alertText) ?>
</div>
<?php endif; ?>

<!-- Header -->
<div class="tc-header">
    <div style="display:flex;align-items:center;gap:10px;">
        <h2 class="g-title" style="margin:0;">📅 ปฏิทินการเรียน-การสอน</h2>
        <span style="background:#fff7ed;color:#9a3412;border-radius:99px;padding:2px 10px;font-size:.75rem;font-weight:700;">
            <?= $totalEventsMonth ?> คาบ/เดือน
        </span>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button onclick="openAddModal()" class="g-btn g-btn-primary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            เพิ่มตารางเรียน
        </button>
        <a href="?q=/modules/7j/manage_schedule.php" class="g-btn g-btn-outline">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            แบบรายการ
        </a>
    </div>
</div>

<!-- Month navigation + filter -->
<div style="background:#fff;border-radius:10px;padding:.85rem 1rem;margin-bottom:.75rem;box-shadow:0 1px 4px rgba(0,0,0,.07);">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <!-- Month nav -->
        <div class="tc-month-nav">
            <a href="<?= $prevUrl ?>" class="tc-nav-btn">&#8249;</a>
            <span class="tc-month-title">
                <?= $MONTHS_TH[$month] ?> <?= $year + 543 ?>
            </span>
            <a href="<?= $nextUrl ?>" class="tc-nav-btn">&#8250;</a>
            <?php if (!$isThisMonth): ?>
            <a href="<?= $todayUrl ?>" class="tc-nav-btn today-btn">วันนี้</a>
            <?php endif; ?>
        </div>
        <!-- Filter -->
        <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="q" value="/modules/7j/teaching_calendar.php">
            <input type="hidden" name="year"  value="<?= $year ?>">
            <input type="hidden" name="month" value="<?= $month ?>">
            <select name="teacher" class="g-select" style="min-width:150px;" onchange="this.form.submit()">
                <option value="">👨‍🏫 ครูทั้งหมด</option>
                <?php foreach ($allTeachers as $t): ?>
                <option value="<?= htmlspecialchars($t['id']) ?>" <?= $filterTeacher===$t['id']?'selected':'' ?>>
                    <?= htmlspecialchars($t['displayName']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="g-select" style="min-width:130px;" onchange="this.form.submit()">
                <option value="">สถานะทั้งหมด</option>
                <option value="active"    <?= $filterStatus==='active'?'selected':'' ?>>🟢 กำลังเรียน</option>
                <option value="pending"   <?= $filterStatus==='pending'?'selected':'' ?>>🔵 รอเรียน</option>
                <option value="completed" <?= $filterStatus==='completed'?'selected':'' ?>>🏆 เรียนครบ</option>
                <option value="cancelled" <?= $filterStatus==='cancelled'?'selected':'' ?>>❌ ยกเลิก</option>
            </select>
            <?php if ($filterTeacher || $filterStatus): ?>
            <a href="<?= tcUrl($year,$month) ?>" class="tc-nav-btn">✕ ล้างตัวกรอง</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Legend -->
<div class="tc-legend" style="margin-bottom:.75rem;">
    <span style="font-size:.75rem;color:#6b7280;font-weight:600;">ตำนาน:</span>
    <?php foreach ($SC as $sk => [$sc,$sbg,$sl]): ?>
    <span class="tc-legend-item">
        <span style="width:10px;height:10px;border-radius:50%;background:<?= $sc ?>;display:inline-block;"></span>
        <span style="color:#374151;"><?= $sl ?></span>
    </span>
    <?php endforeach; ?>
    <span class="tc-legend-item">
        <span style="width:10px;height:10px;border-radius:50%;background:#7c3aed;display:inline-block;"></span>
        <span style="color:#374151;">วันหยุด (เสาร์/อาทิตย์)</span>
    </span>
    <span class="tc-legend-item">
        <span style="width:10px;height:10px;border-radius:50%;background:#2563eb;display:inline-block;"></span>
        <span style="color:#374151;">รอเรียน</span>
    </span>
    <span style="color:#6b7280;font-size:.73rem;">• คลิกที่คาบเรียนเพื่อแก้ไข &nbsp;• คลิก + เพื่อเพิ่มคาบในวันนั้น</span>
</div>

<!-- Calendar Grid -->
<div style="background:#fff;border-radius:12px;padding:.85rem;box-shadow:0 1px 4px rgba(0,0,0,.07);">

    <div class="tc-cal-grid">
        <!-- Day headers -->
        <?php foreach ($DAYS_S as $i => $ds): ?>
        <div class="tc-day-hdr <?= in_array($i,[0,6])?'weekend':'' ?>"><?= $ds ?></div>
        <?php endforeach; ?>

        <!-- Empty cells before month starts -->
        <?php for ($e = 0; $e < $startDow; $e++): ?>
        <div class="tc-cell empty"></div>
        <?php endfor; ?>

        <!-- Day cells -->
        <?php for ($d = 1; $d <= $daysInMonth; $d++):
            $ts_d    = mktime(0,0,0,$month,$d,$year);
            $dowIdx  = (int)date('w', $ts_d);
            $isWkend = in_array($dowIdx, [0,6]);
            $isToday = $isThisMonth && $d === $todayD;
            $events  = $calData[$d];
            $evCount = count($events);
            $shown   = 0;
            $dstrFull= sprintf('%04d-%02d-%02d',$year,$month,$d);
            $dayNameEn = date('l', $ts_d);
        ?>
        <div class="tc-cell <?= $isToday?'today':'' ?> <?= $isWkend?'weekend':'' ?>">
            <!-- Day number -->
            <div class="tc-dnum">
                <?php if ($isToday): ?>
                <span class="tc-dnum-circle"><?= $d ?></span>
                <?php else: ?>
                <span style="color:<?= $isWkend?'#7c3aed':'#374151' ?>;"><?= $d ?></span>
                <?php endif; ?>
                <button class="tc-add-btn" title="เพิ่มคาบวันนี้"
                    onclick="openAddModal('<?= $dayNameEn ?>','<?= $dstrFull ?>')">+</button>
            </div>

            <!-- Events (max 3 shown) -->
            <?php foreach ($events as $ev):
                if ($shown >= 3) break;
                $shown++;
                $stName  = $ev['st_name'] ?: $ev['student_name'];
                $tchName = $ev['t_name']  ?: $ev['teacher_name'];
                [$evColor,$evBg] = $SC[$ev['status']] ?? ['#6b7280','#f3f4f6'];
                $timeLabel = $ev['time_start'] ? substr($ev['time_start'],0,5) : '—';
                $nameShort = mb_substr($stName ?: '?', 0, 8);
                $tchShort  = mb_substr($tchName ?: '?', 0, 5);
            ?>
            <div class="tc-event"
                 style="background:<?= $evBg ?>;color:<?= $evColor ?>;border-left:2px solid <?= $evColor ?>;"
                 title="<?= htmlspecialchars($stName) ?> | <?= htmlspecialchars($tchName) ?> | <?= htmlspecialchars($ev['time_start']) ?>–<?= htmlspecialchars($ev['time_end']) ?>"
                 onclick='openEditModal(<?= json_encode($ev, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                <strong><?= htmlspecialchars($timeLabel) ?></strong>
                <?= htmlspecialchars($nameShort) ?>
                <span style="opacity:.65;">·<?= htmlspecialchars($tchShort) ?></span>
            </div>
            <?php endforeach; ?>

            <!-- "+N more" -->
            <?php if ($evCount > 3): ?>
            <div class="tc-more"
                 onclick='openDayDetail(<?= $d ?>,"<?= htmlspecialchars(addslashes($dstrFull)) ?>")'
                 title="ดูทั้งหมด <?= $evCount ?> คาบ">
                + <?= $evCount - 3 ?> เพิ่มเติม
            </div>
            <?php elseif ($evCount > 0 && $shown === $evCount && $evCount >= 1): ?>
            <?php endif; ?>
        </div>
        <?php endfor; ?>
    </div>
</div>

</div><!-- /.tc-wrap -->

<!-- ═══════════════════════════════════════════════════════════
     Day Detail Modal
═══════════════════════════════════════════════════════════ -->
<div id="modal-day" class="g-modal-bg">
<div class="tc-detail">
    <div class="tc-detail-hdr">
        <h3 id="day-detail-title" style="margin:0;font-size:1rem;font-weight:700;color:#fff;">📅 รายการคาบ</h3>
    </div>
    <div id="day-detail-body" style="padding:1rem 1.25rem;"></div>
    <div style="padding:.7rem 1.25rem;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:8px;background:#f9fafb;">
        <button onclick="gCloseModal('day')" class="g-btn g-btn-outline">ปิด</button>
        <button id="day-add-btn" class="g-btn g-btn-primary">+ เพิ่มคาบวันนี้</button>
    </div>
</div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     Add / Edit Modal
═══════════════════════════════════════════════════════════ -->
<div id="modal-edit" class="g-modal-bg">
<div class="tc-modal">
    <div class="tc-modal-hdr">
        <h3 id="edit-modal-title">📅 เพิ่มตารางเรียน</h3>
        <p id="edit-modal-sub" style="margin:2px 0 0;opacity:.8;font-size:.8rem;"></p>
    </div>
    <form method="post" id="edit-form">
        <input type="hidden" name="q" value="/modules/7j/teaching_calendar.php">
        <input type="hidden" name="year"  value="<?= $year ?>">
        <input type="hidden" name="month" value="<?= $month ?>">
        <?php if ($filterTeacher): ?><input type="hidden" name="teacher" value="<?= htmlspecialchars($filterTeacher) ?>"><?php endif; ?>
        <?php if ($filterStatus):  ?><input type="hidden" name="status"  value="<?= htmlspecialchars($filterStatus) ?>"><?php endif; ?>
        <input type="hidden" name="action" id="ef-action" value="add">
        <input type="hidden" name="id"     id="ef-id"     value="0">

        <div class="tc-modal-body">
            <!-- ─── นักเรียน ─── -->
            <div class="g-section-label">นักเรียน</div>
            <input type="hidden" name="student_id" id="ef-sid">

            <div class="g-grid2">
                <div class="g-form-group" style="position:relative;">
                    <label>ชื่อนักเรียน * <span style="font-size:.72rem;color:#6b7280;font-weight:400;">(พิมพ์เพื่อค้นหา)</span></label>
                    <input type="text" name="student_name" id="ef-sname" class="g-input" required
                           autocomplete="off"
                           placeholder="พิมพ์ชื่อนักเรียน..."
                           oninput="stuNameSearch(this.value)">
                    <div id="ef-sname-dd" class="tc-dropdown"></div>
                </div>
                <div class="g-form-group">
                    <label>รหัสนักเรียน</label>
                    <input type="text" name="student_code" id="ef-scode" class="g-input"
                           placeholder="เช่น S260001">
                </div>
            </div>

            <!-- ─── ครู ─── -->
            <div class="g-section-label">ครู</div>
            <div class="g-grid2">
                <div class="g-form-group">
                    <label>เลือกครู</label>
                    <select name="teacher_ref_id" id="ef-tid" class="g-select" onchange="tcTeacherChange(this)">
                        <option value="">— เลือกครู —</option>
                        <?php foreach ($allTeachers as $t): ?>
                        <option value="<?= htmlspecialchars($t['id']) ?>"
                                data-name="<?= htmlspecialchars($t['displayName']) ?>"
                                data-code="<?= htmlspecialchars($t['teacherCode'] ?? '') ?>">
                            <?= htmlspecialchars($t['displayName']) ?><?= $t['teacherCode']?' ('.$t['teacherCode'].')':'' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="g-form-group">
                    <label>ชื่อครู *</label>
                    <input type="text" name="teacher_name" id="ef-tname" class="g-input" required placeholder="หรือกรอกเอง">
                </div>
            </div>

            <!-- ช่วงเวลาว่างครู -->
            <div id="ef-avail-hint" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:.8rem;color:#166534;">
                <strong>🕐 ช่วงเวลาว่างของครู:</strong>
                <div id="ef-avail-list" style="margin-top:4px;"></div>
            </div>

            <!-- ตาราง -->
            <div class="g-section-label">ตารางเรียน</div>
            <div class="g-grid2">
                <div class="g-form-group">
                    <label>คอร์ส</label>
                    <input type="text" name="course" id="ef-course" class="g-input" placeholder="เช่น Basic, IELTS">
                </div>
                <div class="g-form-group">
                    <label>ประเภทตาราง</label>
                    <select name="schedule_type" id="ef-stype" class="g-select" onchange="tcToggleType()">
                        <option value="weekly">🗓 รายสัปดาห์</option>
                        <option value="one_time">📆 วันเดียว/ครั้งเดียว</option>
                    </select>
                </div>
            </div>
            <div id="ef-weekly-grp" class="g-form-group">
                <label>วันในสัปดาห์</label>
                <select name="day_of_week" id="ef-day" class="g-select">
                    <?php foreach ($DAYS as $i => $d): ?>
                    <option value="<?= $d ?>"><?= $DAYS_TH[$i] ?> (<?= $d ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="ef-date-grp" class="g-form-group" style="display:none;">
                <label>วันที่</label>
                <input type="date" name="specific_date" id="ef-date" class="g-input">
            </div>
            <div class="g-grid2">
                <div class="g-form-group"><label>เวลาเริ่ม</label><input type="time" name="time_start" id="ef-tstart" class="g-input"></div>
                <div class="g-form-group"><label>เวลาจบ</label><input type="time"  name="time_end"   id="ef-tend"   class="g-input"></div>
            </div>
            <div class="g-grid3">
                <div class="g-form-group"><label>คาบทั้งหมด</label><input type="number" name="total_classes"     id="ef-total" class="g-input" value="20" min="1"></div>
                <div class="g-form-group"><label>เรียนแล้ว</label>  <input type="number" name="completed_classes" id="ef-done"  class="g-input" value="0"  min="0"></div>
                <div class="g-form-group">
                    <label>สถานะ</label>
                    <select name="status" id="ef-status" class="g-select">
                        <option value="active">🟢 กำลังเรียน</option>
                        <option value="pending">🔵 รอเรียน</option>
                        <option value="completed">🏆 เรียนครบ</option>
                        <option value="cancelled">❌ ยกเลิก</option>
                    </select>
                </div>
            </div>
            <div class="g-form-group">
                <label>หมายเหตุ</label>
                <textarea name="note" id="ef-note" class="g-textarea" rows="2" placeholder="บันทึกเพิ่มเติม..."></textarea>
            </div>
        </div>

        <div class="tc-modal-foot">
            <button type="button" class="g-btn g-btn-outline" onclick="gCloseModal('edit');tcResetForm()">ยกเลิก</button>
            <button id="ef-del-btn" type="button" class="g-btn g-btn-danger" style="display:none;" onclick="tcConfirmDelete()">🗑 ลบ</button>
            <button type="button" id="ef-save-btn" class="g-btn g-btn-primary" onclick="tcSubmitForm()">💾 บันทึก</button>
        </div>
    </form>
</div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     Delete confirmation Modal
═══════════════════════════════════════════════════════════ -->
<div id="modal-del" class="g-modal-bg">
<div class="g-modal" style="max-width:380px;">
    <div class="tc-modal-hdr" style="background:linear-gradient(135deg,#991b1b,#dc2626);">
        <h3>ยืนยันการลบ</h3>
    </div>
    <div class="tc-modal-body">
        <p id="del-text" style="color:#374151;margin:.5rem 0 1rem;"></p>
        <form method="post" id="del-form">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="q"      value="/modules/7j/teaching_calendar.php">
            <input type="hidden" name="year"   value="<?= $year ?>">
            <input type="hidden" name="month"  value="<?= $month ?>">
            <?php if ($filterTeacher): ?><input type="hidden" name="teacher" value="<?= htmlspecialchars($filterTeacher) ?>"><?php endif; ?>
            <?php if ($filterStatus):  ?><input type="hidden" name="status"  value="<?= htmlspecialchars($filterStatus) ?>"><?php endif; ?>
            <input type="hidden" name="id" id="del-id">
            <div style="display:flex;justify-content:flex-end;gap:8px;">
                <button type="button" class="g-btn g-btn-outline" onclick="gCloseModal('del')">ยกเลิก</button>
                <button type="submit" class="g-btn g-btn-danger">ลบ</button>
            </div>
        </form>
    </div>
</div>
</div>

<!-- ─── JavaScript ─────────────────────────────────────────────────────────────── -->
<script>
var tcTeachersData = <?= json_encode(array_column($allTeachers,null,'id')) ?>;
var tcAvailData    = <?= json_encode($availByTeacher) ?>;
var tcCalData      = <?= json_encode($calData) ?>;
var DAYS_TH = {Sunday:'อาทิตย์',Monday:'จันทร์',Tuesday:'อังคาร',Wednesday:'พุธ',Thursday:'พฤหัสบดี',Friday:'ศุกร์',Saturday:'เสาร์'};
var AJAX_BASE = '<?= htmlspecialchars(rtrim($absoluteURL??'','/')) ?>/modules/7j/ajax_search_students.php';
var SC_COLORS = {active:'#ea580c',pending:'#2563eb',completed:'#059669',cancelled:'#9ca3af'};
var SC_BGS    = {active:'#fff7ed',pending:'#dbeafe',completed:'#d1fae5',cancelled:'#f3f4f6'};
var SC_LABELS = {active:'🟢 กำลังเรียน',pending:'🔵 รอเรียน',completed:'🏆 เรียนครบ',cancelled:'❌ ยกเลิก'};

// ── Open Add Modal ──
function openAddModal(dayName, dateStr) {
    tcResetForm();
    document.getElementById('edit-modal-title').textContent = '📅 เพิ่มตารางเรียน';
    document.getElementById('edit-modal-sub').textContent   = '';
    document.getElementById('ef-action').value = 'add';
    document.getElementById('ef-id').value     = '0';
    document.getElementById('ef-del-btn').style.display = 'none';

    if (dayName && !dateStr) {
        document.getElementById('ef-stype').value = 'weekly';
        document.getElementById('ef-day').value   = dayName;
    } else if (dateStr) {
        document.getElementById('ef-stype').value = 'one_time';
        document.getElementById('ef-date').value  = dateStr;
    }
    tcToggleType();
    gOpenModal('edit');
}

// ── Open Edit Modal ──
function openEditModal(d) {
    tcResetForm();
    document.getElementById('edit-modal-title').textContent = '✏️ แก้ไขตารางเรียน';
    var sname = d.st_name || d.student_name || '';
    var tname = d.t_name  || d.teacher_name || '';
    document.getElementById('edit-modal-sub').textContent = sname + (tname?' · '+tname:'');

    document.getElementById('ef-action').value  = 'edit';
    document.getElementById('ef-id').value      = d.id;
    document.getElementById('ef-del-btn').style.display = 'inline-flex';

    // Student
    document.getElementById('ef-sid').value   = d.student_id   || '';
    document.getElementById('ef-sname').value = sname;
    document.getElementById('ef-scode').value = d.student_code || '';

    // Teacher
    document.getElementById('ef-tid').value   = d.teacher_ref_id || '';
    document.getElementById('ef-tname').value = tname;
    if (d.teacher_ref_id) tcShowAvailability(d.teacher_ref_id);

    // Schedule
    document.getElementById('ef-course').value  = d.course        || '';
    document.getElementById('ef-stype').value   = d.schedule_type || 'weekly';
    document.getElementById('ef-day').value     = d.day_of_week   || 'Monday';
    document.getElementById('ef-date').value    = d.specific_date || '';
    document.getElementById('ef-tstart').value  = d.time_start    || '';
    document.getElementById('ef-tend').value    = d.time_end      || '';
    document.getElementById('ef-total').value   = d.total_classes || 20;
    document.getElementById('ef-done').value    = d.completed_classes || 0;
    document.getElementById('ef-status').value  = d.status        || 'active';
    document.getElementById('ef-note').value    = d.note          || '';

    tcToggleType();
    gOpenModal('edit');
}

function tcToggleType() {
    var t = document.getElementById('ef-stype').value;
    document.getElementById('ef-weekly-grp').style.display = t === 'weekly'   ? '' : 'none';
    document.getElementById('ef-date-grp').style.display   = t === 'one_time' ? '' : 'none';
}

function tcResetForm() {
    document.getElementById('edit-form').reset();
    document.getElementById('ef-action').value = 'add';
    document.getElementById('ef-id').value     = '0';
    document.getElementById('ef-sid').value    = '';
    document.getElementById('ef-avail-hint').style.display = 'none';
    tcToggleType();
}

// ── Teacher change ──
function tcTeacherChange(sel) {
    var opt = sel.options[sel.selectedIndex];
    document.getElementById('ef-tname').value = opt.value ? (opt.dataset.name || '') : '';
    tcShowAvailability(opt.value);
}

function tcShowAvailability(teacherId) {
    var hint = document.getElementById('ef-avail-hint');
    var list = document.getElementById('ef-avail-list');
    if (!teacherId || !tcAvailData[teacherId] || !tcAvailData[teacherId].length) {
        hint.style.display = 'none'; return;
    }
    var html = tcAvailData[teacherId].map(function(s) {
        var label = s.type === 'weekly' ? '🗓 '+(DAYS_TH[s.day]||s.day) : '📅 '+s.specific_date;
        return '<span style="display:inline-block;background:#bbf7d0;color:#166534;border-radius:6px;padding:1px 8px;margin:2px;font-size:.73rem;">'
             + label + ' ' + s.start_time + '–' + s.end_time
             + (s.note?' <span style="opacity:.6;">('+s.note+')</span>':'')
             + '</span>';
    }).join('');
    list.innerHTML = html;
    hint.style.display = '';
}


// ── Form Submit via fetch (bypass htmx) ──
function tcSubmitForm() {
    var form  = document.getElementById('edit-form');
    var sname = document.getElementById('ef-sname').value.trim();
    var tname = document.getElementById('ef-tname').value.trim();
    if (!sname) { alert('กรุณากรอกชื่อนักเรียน'); document.getElementById('ef-sname').focus(); return; }
    if (!tname) { alert('กรุณากรอกชื่อครู');       document.getElementById('ef-tname').focus(); return; }

    var btn = document.getElementById('ef-save-btn');
    btn.disabled = true;
    btn.textContent = 'กำลังบันทึก...';

    var data = new FormData(form);
    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: data
    })
    .then(function(r) { return r.text(); })
    .then(function(html) {
        // parse response for alert message
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        var alertEl = tmp.querySelector('.g-alert');
        var msg = alertEl ? alertEl.textContent.trim() : '';
        var isSuccess = alertEl && alertEl.classList.contains('g-alert-success');

        gCloseModal('edit');
        tcResetForm();

        // show notification
        var notif = document.getElementById('tc-notif');
        if (!notif) {
            notif = document.createElement('div');
            notif.id = 'tc-notif';
            notif.style.cssText = 'position:fixed;top:70px;right:20px;z-index:99999;max-width:320px;padding:12px 18px;border-radius:10px;font-size:.88rem;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.18);transition:opacity .4s;';
            document.body.appendChild(notif);
        }
        notif.style.background  = isSuccess ? '#d1fae5' : '#fee2e2';
        notif.style.color       = isSuccess ? '#065f46' : '#991b1b';
        notif.style.opacity     = '1';
        notif.textContent       = msg || (isSuccess ? 'บันทึกสำเร็จ' : 'เกิดข้อผิดพลาด');
        setTimeout(function() { notif.style.opacity = '0'; }, 3000);

        // reload calendar content
        if (isSuccess) {
            var wrap = document.getElementById('content-wrap') || document.getElementById('content');
            if (wrap) {
                tmp2 = document.createElement('div');
                tmp2.innerHTML = html;
                var newWrap = tmp2.querySelector('#content-wrap') || tmp2.querySelector('#content');
                if (newWrap && wrap.parentNode) {
                    wrap.parentNode.replaceChild(newWrap, wrap);
                }
            } else {
                location.reload();
            }
        }
    })
    .catch(function(err) {
        alert('เกิดข้อผิดพลาด: ' + err.message);
    })
    .finally(function() {
        btn.disabled = false;
        btn.textContent = '💾 บันทึก';
    });
}

// ── Student Name Autocomplete ──
var _snTimer = null;
function stuNameSearch(val) {
    var dd = document.getElementById('ef-sname-dd');
    clearTimeout(_snTimer);
    if (val.length < 1) { dd.style.display = 'none'; return; }
    _snTimer = setTimeout(function() {
        fetch(AJAX_BASE + '?q=' + encodeURIComponent(val))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.length) {
                    dd.innerHTML = '<div class="tc-dd-item" style="color:#9ca3af;font-size:.82rem;">ไม่พบนักเรียน</div>';
                    dd.style.display = '';
                    return;
                }
                dd.innerHTML = data.map(function(s) {
                    var tname = (s.teacherId && tcTeachersData[s.teacherId]) ? tcTeachersData[s.teacherId].displayName : '';
                    return '<div class="tc-dd-item" onclick=\'stuNameSelect(' + JSON.stringify(s).replace(/\'/g, "\\u0027") + ')\'>'
                         + '<div style="font-weight:600;font-size:.88rem;">' + escH(s.displayName) + '</div>'
                         + '<div style="font-size:.75rem;color:#6b7280;margin-top:1px;">'
                         + (s.studentCode ? '<span style="background:#fff7ed;color:#9a3412;border-radius:4px;padding:0 5px;margin-right:6px;">' + escH(s.studentCode) + '</span>' : '')
                         + (tname ? '👨‍🏫 ' + escH(tname) : '')
                         + '</div>'
                         + '</div>';
                }).join('');
                dd.style.display = '';
            })
            .catch(function() { dd.style.display = 'none'; });
    }, 250);
}

function stuNameSelect(s) {
    // fill student fields
    document.getElementById('ef-sid').value   = s.id           || '';
    document.getElementById('ef-sname').value = s.displayName  || '';
    document.getElementById('ef-scode').value = s.studentCode  || '';
    document.getElementById('ef-sname-dd').style.display = 'none';

    // fill total classes from student record
    if (s.totalClasses) {
        document.getElementById('ef-total').value = s.totalClasses;
    }

    // auto-fill teacher
    if (s.teacherId && tcTeachersData[s.teacherId]) {
        document.getElementById('ef-tid').value   = s.teacherId;
        document.getElementById('ef-tname').value = tcTeachersData[s.teacherId].displayName || '';
        tcShowAvailability(s.teacherId);
    }
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('#ef-sname') && !e.target.closest('#ef-sname-dd'))
        document.getElementById('ef-sname-dd').style.display = 'none';
});

// ── Day Detail Panel ──
function openDayDetail(dayNum, dateStr) {
    var events = tcCalData[dayNum] || [];
    var months = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    var parts = dateStr.split('-');
    var title = parseInt(parts[2])+ ' ' + months[parseInt(parts[1])] + ' ' + (parseInt(parts[0])+543);

    document.getElementById('day-detail-title').textContent = '📅 ' + title + ' — ' + events.length + ' คาบ';

    var dayNameEn = '';
    if (events.length > 0) dayNameEn = events[0].day_of_week || '';

    var html = '';
    if (!events.length) {
        html = '<p style="color:#9ca3af;text-align:center;padding:1rem 0;">ไม่มีคาบเรียนในวันนี้</p>';
    } else {
        events.forEach(function(ev) {
            var sname = ev.st_name || ev.student_name || '(ไม่ระบุ)';
            var tname = ev.t_name  || ev.teacher_name || '(ไม่ระบุ)';
            var color = SC_COLORS[ev.status] || '#6b7280';
            var bg    = SC_BGS[ev.status]    || '#f3f4f6';
            var label = SC_LABELS[ev.status] || ev.status;
            var time  = ev.time_start ? ev.time_start.substring(0,5) : '—';
            var timeE = ev.time_end   ? ev.time_end.substring(0,5)   : '';
            html += '<div class="tc-event-row">'
                + '<div style="width:4px;min-height:50px;border-radius:2px;background:'+color+';flex-shrink:0;"></div>'
                + '<div style="flex:1;">'
                + '<div style="font-weight:700;font-size:.9rem;color:#1f2937;">'+escH(sname)+'</div>'
                + '<div style="font-size:.8rem;color:#6b7280;margin:2px 0;">👨‍🏫 '+escH(tname)+'</div>'
                + (ev.course?'<div style="font-size:.75rem;color:#6b7280;">📚 '+escH(ev.course)+'</div>':'')
                + '<div style="display:flex;gap:6px;margin-top:4px;flex-wrap:wrap;align-items:center;">'
                + '<span style="font-size:.75rem;background:#dbeafe;color:#1e40af;border-radius:5px;padding:1px 7px;">⏰ '+time+(timeE?'–'+timeE:'')+'</span>'
                + '<span style="font-size:.72rem;background:'+bg+';color:'+color+';border-radius:5px;padding:1px 7px;">'+label+'</span>'
                + '<span style="font-size:.72rem;color:#9ca3af;">คาบ '+ev.completed_classes+'/'+ev.total_classes+'</span>'
                + '</div>'
                + (ev.note?'<div style="font-size:.72rem;color:#9ca3af;margin-top:3px;">📝 '+escH(ev.note)+'</div>':'')
                + '</div>'
                + '<button class="g-btn g-btn-outline g-btn-sm" onclick=\'gCloseModal("day");openEditModal('+JSON.stringify(ev).replace(/\'/g,"\\u0027")+')\'>แก้ไข</button>'
                + '</div>';
        });
    }

    document.getElementById('day-detail-body').innerHTML = html;
    document.getElementById('day-add-btn').onclick = function() {
        gCloseModal('day');
        openAddModal(dayNameEn, dateStr);
    };
    gOpenModal('day');
}

function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Delete confirm ──
function tcConfirmDelete() {
    var id   = document.getElementById('ef-id').value;
    var name = document.getElementById('ef-sname').value;
    document.getElementById('del-id').value   = id;
    document.getElementById('del-text').textContent = 'ต้องการลบตารางเรียนของ "' + name + '" ใช่หรือไม่? ประวัติคาบเรียนจะถูกลบด้วย';
    gCloseModal('edit');
    gOpenModal('del');
}
</script>
