<?php
/*
 * 7J English Center — Manage Schedule
 * ปรับปรุง: ใช้ dropdown จาก sevenj_students + sevenj_teachers (FK จริง)
 * student_id → sevenj_students.id | teacher_ref_id → sevenj_teachers.id
 */

// ─── POST handlers ────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';
$msg    = '';

function q($conn, $v) { return $conn->quote(trim($v ?? '')); }
function qNull($conn, $v) { $s = trim($v ?? ''); return $s === '' ? 'NULL' : $conn->quote($s); }
function qInt($v, $def=0) { $s = trim($v ?? ''); return $s === '' ? $def : (int)$s; }

if ($action === 'add' || $action === 'edit') {
    $sid    = trim($_POST['student_id']    ?? '');
    $tid    = trim($_POST['teacher_ref_id'] ?? '');
    $sname  = trim($_POST['student_name']  ?? '');
    $scode  = trim($_POST['student_code']  ?? '');
    $tname  = trim($_POST['teacher_name']  ?? '');

    // ถ้าเลือกจาก dropdown ให้ดึงชื่อจาก DB แทน
    if ($sid) {
        $stmt = $connection2->prepare("SELECT displayName, studentCode FROM sevenj_students WHERE id=?");
        $stmt->execute([$sid]);
        $srow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($srow) { $sname = $srow['displayName']; $scode = $srow['studentCode'] ?? ''; }
    }
    if ($tid) {
        $stmt = $connection2->prepare("SELECT displayName FROM sevenj_teachers WHERE id=?");
        $stmt->execute([$tid]);
        $trow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($trow) { $tname = $trow['displayName']; }
    }

    $stype  = in_array($_POST['schedule_type'] ?? '', ['weekly','one_time']) ? $_POST['schedule_type'] : 'weekly';
    $day    = trim($_POST['day_of_week']   ?? '');
    $ts     = trim($_POST['time_start']    ?? '');
    $te     = trim($_POST['time_end']      ?? '');
    // per-date times (one_time) — fallback to global if empty
    $tsList = array_values(array_map('trim', (array)($_POST['time_starts'] ?? [])));
    $teList = array_values(array_map('trim', (array)($_POST['time_ends']   ?? [])));
    if (!$ts && !empty($tsList[0])) $ts = $tsList[0];
    if (!$te && !empty($teList[0])) $te = $teList[0];
    $sdates = array_filter(array_map('trim', (array)($_POST['specific_dates'] ?? [])));
    $sdate  = $sdates ? reset($sdates) : '';
    $course = trim($_POST['course']        ?? '');
    $total  = max(1, qInt($_POST['total_classes'] ?? '', 20));
    $done   = max(0, qInt($_POST['completed_classes'] ?? '', 0));
    $note   = trim($_POST['note']          ?? '');

    // ─── Validation เงื่อนไข 1-3 ──────────────────────────────────────────────────
    if (!$sname || !$tname) {
        $msg = 'error|กรุณาเลือกนักเรียนและครู';
    } elseif (!$ts || !$te) {
        $msg = 'error|กรุณาระบุเวลาเริ่มและเวลาจบ';
    } elseif ($ts >= $te) {
        $msg = 'error|เวลาเริ่ม ('.$ts.') ต้องน้อยกว่าเวลาจบ ('.$te.') — วัน/เวลาไม่ตรงกัน';
    } elseif ($stype === 'one_time' && empty($sdates)) {
        $msg = 'error|กรุณาระบุวันที่เรียนอย่างน้อย 1 วัน';
    } elseif ($stype === 'weekly' && !$day) {
        $msg = 'error|กรุณาเลือกวันในสัปดาห์';
    } else {
        // เงื่อนไขที่ 3: ห้ามบันทึกวัน/เวลาที่ผ่านมาแล้ว + ตรวจวันตรงกับ day_of_week (one_time เท่านั้น)
        if ($stype === 'one_time' && $action === 'add') {
            $DAYS_TH_MAP = ['Monday'=>'จันทร์','Tuesday'=>'อังคาร','Wednesday'=>'พุธ',
                'Thursday'=>'พฤหัสบดี','Friday'=>'ศุกร์','Saturday'=>'เสาร์','Sunday'=>'อาทิตย์'];
            $nowDT = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
            $sdatesIdx = array_values($sdates);
            foreach ($sdatesIdx as $i => $d) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    $msg = 'error|รูปแบบวันที่ไม่ถูกต้อง: '.$d; break;
                }
                // ตรวจวันที่ต้องตรงกับ day_of_week ที่เลือก
                if ($day) {
                    $dateDow = date('l', strtotime($d));
                    if (strcasecmp($dateDow, $day) !== 0) {
                        $thDay = $DAYS_TH_MAP[$day] ?? $day;
                        $thAct = $DAYS_TH_MAP[$dateDow] ?? $dateDow;
                        $msg = 'error|วันที่ '.$d.' ตรงกับวัน'.$thAct.' — ไม่ตรงกับวัน'.$thDay.'ที่เลือกไว้'; break;
                    }
                }
                // ตรวจ: ห้ามวันที่ผ่านมาแล้ว
                $tsI = $tsList[$i] ?? $ts;
                $slotDT = new DateTime($d . ' ' . ($tsI ?: '00:00'), new DateTimeZone('Asia/Bangkok'));
                if ($slotDT <= $nowDT) {
                    $msg = 'error|วันที่ '.$d.' เวลา '.($tsI ?: $ts).' ผ่านมาแล้ว — ไม่สามารถบันทึกตารางได้'; break;
                }
            }
        }
    }
    if (!$msg) {
        $sidQ   = qNull($connection2, $sid);
        $tidQ   = qNull($connection2, $tid);
        $scodeQ = q($connection2, $scode);
        $snameQ = q($connection2, $sname);
        $tnameQ = q($connection2, $tname);
        $dayQ   = q($connection2, $day);
        $tsQ    = q($connection2, $ts);
        $teQ    = q($connection2, $te);
        $sdateQ = $sdate ? q($connection2, $sdate) : 'NULL';
        $courseQ = q($connection2, $course);
        $noteQ  = q($connection2, $note);

        if ($action === 'add') {
            if ($stype === 'one_time' && count($sdates) > 1) {
                // ── เพิ่มหลายวันพร้อมกัน ──
                $inserted = 0;
                $sdatesIdx = array_values($sdates);
                foreach ($sdatesIdx as $i => $d) {
                    $dQ   = q($connection2, $d);
                    $tsI  = !empty($tsList[$i]) ? $tsList[$i] : $ts;
                    $teI  = !empty($teList[$i]) ? $teList[$i] : $te;
                    $tsIQ = q($connection2, $tsI);
                    $teIQ = q($connection2, $teI);
                    // validate per-date time
                    if (!$tsI || !$teI || $tsI >= $teI) continue;
                    $connection2->query("INSERT INTO sevenj_schedule
                        (student_id,student_code,student_name,teacher_ref_id,teacher_name,
                         schedule_type,day_of_week,time_start,time_end,specific_date,
                         course,total_classes,completed_classes,note)
                        VALUES ($sidQ,$scodeQ,$snameQ,$tidQ,$tnameQ,
                        'one_time',$dayQ,$tsIQ,$teIQ,$dQ,
                        $courseQ,$total,$done,$noteQ)");
                    $inserted++;
                }
                $msg = "success|เพิ่มตารางเรียนสำเร็จ ($inserted วัน)";
            } else {
                $connection2->query("INSERT INTO sevenj_schedule
                    (student_id,student_code,student_name,teacher_ref_id,teacher_name,
                     schedule_type,day_of_week,time_start,time_end,specific_date,
                     course,total_classes,completed_classes,note)
                    VALUES ($sidQ,$scodeQ,$snameQ,$tidQ,$tnameQ,
                    '$stype',$dayQ,$tsQ,$teQ,$sdateQ,
                    $courseQ,$total,$done,$noteQ)");
                $msg = 'success|เพิ่มตารางเรียนสำเร็จ';
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                // ─── lock check: ห้ามแก้ไขถ้าถึงเวลาเรียนหรือบันทึกแล้ว ──────
                $stmtG = $connection2->prepare("
                    SELECT s.*,
                        (SELECT COUNT(*) FROM sevenj_class_completions
                         WHERE schedule_id=? AND completed_date=?) AS logged_today
                    FROM sevenj_schedule s WHERE s.id=? LIMIT 1");
                $stmtG->execute([$id, date('Y-m-d'), $id]);
                $sch = $stmtG->fetch(PDO::FETCH_ASSOC);
                $editLocked = false;
                if ($sch) {
                    $nowDT  = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
                    $nowHM  = (int)$nowDT->format('H') * 60 + (int)$nowDT->format('i');
                    [$eh,$em] = array_map('intval', explode(':', ($sch['time_start'] ?? '00:00').':00'));
                    $slotHM = $eh*60+$em;
                    if ($sch['schedule_type'] === 'one_time' && ($sch['specific_date'] ?? '')) {
                        $editLocked = ($sch['specific_date'] < date('Y-m-d'))
                                   || ($sch['specific_date'] === date('Y-m-d') && $nowHM >= $slotHM);
                    } else {
                        $editLocked = ($nowDT->format('l') === ($sch['day_of_week'] ?? '')) && $nowHM >= $slotHM;
                    }
                    if ((int)$sch['logged_today'] > 0) $editLocked = true;
                }
                if ($editLocked) {
                    $msg = 'error|ไม่สามารถแก้ไขได้ — ถึงเวลาเรียนหรือบันทึกแล้ว';
                } else {
                    $connection2->query("UPDATE sevenj_schedule SET
                        student_id=$sidQ, student_code=$scodeQ, student_name=$snameQ,
                        teacher_ref_id=$tidQ, teacher_name=$tnameQ,
                        schedule_type='$stype', day_of_week=$dayQ,
                        time_start=$tsQ, time_end=$teQ, specific_date=$sdateQ,
                        course=$courseQ, total_classes=$total,
                        completed_classes=$done, note=$noteQ
                        WHERE id=$id");
                    $msg = 'success|อัปเดตตารางเรียนสำเร็จ';
                }
            }
        }
    }

} elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $connection2->query("DELETE FROM sevenj_schedule WHERE id=$id"); // completions คงไว้ 30 วัน
        $msg = 'success|ลบตารางเรียนสำเร็จ';
    }
} elseif ($action === 'log_class') {
    $id      = (int)($_POST['id'] ?? 0);
    $logDate = trim($_POST['log_date'] ?? date('Y-m-d'));
    $logNote = trim($_POST['log_note'] ?? '');
    if ($id) {
        $stmtRow = $connection2->prepare("SELECT * FROM sevenj_schedule WHERE id=?");
        $stmtRow->execute([$id]);
        $row = $stmtRow->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // ถ้า teacher_ref_id เป็น NULL ให้ lookup จากชื่อ
            $teaRefId = $row['teacher_ref_id'] ?: null;
            if (!$teaRefId && $row['teacher_name']) {
                $stmtTea = $connection2->prepare("SELECT id FROM sevenj_teachers WHERE displayName=? AND status='active' LIMIT 1");
                $stmtTea->execute([$row['teacher_name']]);
                $teaRow = $stmtTea->fetch(PDO::FETCH_ASSOC);
                if ($teaRow) $teaRefId = $teaRow['id'];
            }
            $newDone   = (int)$row['completed_classes'] + 1;
            $sessNum   = $newDone;
            $newStatus = ($newDone >= (int)$row['total_classes']) ? 'completed' : $row['status'];
            $connection2->prepare("UPDATE sevenj_schedule SET completed_classes=?, status=? WHERE id=?")
                ->execute([$newDone, $newStatus, $id]);
            // อัปเดต completedClasses ใน sevenj_students ด้วย
            if ($row['student_id']) {
                $connection2->prepare("UPDATE sevenj_students SET completedClasses=completedClasses+1 WHERE id=?")
                    ->execute([$row['student_id']]);
            }
            $connection2->prepare("INSERT INTO sevenj_class_completions
                (schedule_id,student_id,student_code,student_name,teacher_name,teacher_ref_id,
                 day_of_week,time_start,session_number,completed_date,note)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $id,
                    $row['student_id'] ?: null,
                    $row['student_code'],
                    $row['student_name'],
                    $row['teacher_name'],
                    $teaRefId,
                    $row['day_of_week'],
                    $row['time_start'],
                    $sessNum,
                    $logDate,
                    $logNote,
                ]);
            $label = $newStatus === 'completed' ? ' 🎉 เรียนครบแล้ว!' : '';
            $msg = 'success|บันทึกคาบที่ '.$sessNum.' สำเร็จ'.$label;
        }
    }
} elseif ($action === 'change_status') {
    $id  = (int)($_POST['id'] ?? 0);
    $st  = trim($_POST['new_status'] ?? '');
    $allowed = ['active','completed','cancelled'];
    if ($id && in_array($st, $allowed)) {
        $connection2->query("UPDATE sevenj_schedule SET status='$st' WHERE id=$id");
        $msg = 'success|เปลี่ยนสถานะเป็น "'.$st.'" สำเร็จ';
    }
}

// เวลาเก็บแบบ 24h จริง — แปลงเป็น 12h AM/PM
function fmtTimePM(string $t): string {
    if ($t === '') return '';
    [$h, $m] = array_pad(explode(':', $t), 2, '00');
    $h = (int)$h;
    $suffix = $h >= 12 ? 'PM' : 'AM';
    $h12    = $h % 12 ?: 12;
    return $h12 . ':' . $m . ' ' . $suffix;
}

// ─── Fetch data ───────────────────────────────────────────────────────────────
$search     = trim($_GET['search'] ?? '');
$filterSt   = trim($_GET['status'] ?? '');
$filterTid  = trim($_GET['teacher'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$limit      = 50;
$offset     = ($page - 1) * $limit;

$conditions = []; $bindP = [];
if ($search) {
    $conditions[] = "(s.student_name LIKE ? OR s.student_code LIKE ? OR s.teacher_name LIKE ? OR s.course LIKE ?)";
    $l = '%'.$search.'%'; $bindP[]=$l; $bindP[]=$l; $bindP[]=$l; $bindP[]=$l;
}
if ($filterSt) {
    if ($filterSt === 'not_started') {
        $conditions[] = "s.status='active' AND s.completed_classes = 0";
    } elseif ($filterSt === 'in_progress') {
        $conditions[] = "s.status='active' AND s.completed_classes > 0 AND s.completed_classes < s.total_classes";
    } else {
        $conditions[] = "s.status=?"; $bindP[] = $filterSt;
    }
}
if ($filterTid) { $conditions[] = "s.teacher_ref_id=?";   $bindP[] = (int)$filterTid; }
$where = $conditions ? 'WHERE '.implode(' AND ', $conditions) : '';


$stmtCount = $connection2->prepare("SELECT COUNT(*) FROM sevenj_schedule s $where");
$stmtCount->execute($bindP);
$totalRows = (int)$stmtCount->fetchColumn();
$pages     = $totalRows > 0 ? ceil($totalRows / $limit) : 1;

$today = date('Y-m-d');

$stmtSch = $connection2->prepare("
    SELECT s.*,
        st.displayName AS st_name, st.studentCode AS st_code,
        t.displayName  AS t_name,  t.teacherCode  AS t_code,
        (SELECT COUNT(*) FROM sevenj_class_completions c
         WHERE c.schedule_id = s.id AND c.completed_date = ?) AS logged_today
    FROM sevenj_schedule s
    LEFT JOIN sevenj_students st ON st.id = s.student_id
    LEFT JOIN sevenj_teachers t  ON t.id  = s.teacher_ref_id
    $where
    HAVING NOT (s.schedule_type = 'one_time' AND s.specific_date = ? AND logged_today > 0)
    ORDER BY s.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmtSch->execute(array_merge([$today], $bindP, [$today]));
$schedules = $stmtSch->fetchAll(PDO::FETCH_ASSOC);

// ครู (จำนวนน้อย โหลดทั้งหมดได้)
$allTeachers = $connection2->query("
    SELECT id, displayName, teacherCode FROM sevenj_teachers
    WHERE status='active' ORDER BY displayName")->fetchAll(PDO::FETCH_ASSOC);

// นักเรียน — โหลดทั้งหมด (ค้นหาใน JS)
$allStudents = $connection2->query("
    SELECT s.id, s.displayName, s.studentCode, s.nickname, s.teacherId, s.totalClasses,
           (SELECT COUNT(*) FROM sevenj_class_completions c WHERE c.student_id = s.id) AS actualCompleted
    FROM sevenj_students s
    WHERE s.status='active' ORDER BY s.displayName")->fetchAll(PDO::FETCH_ASSOC);

// Teacher availability slots (สำหรับ JS)
$availSlots = $connection2->query("
    SELECT teacher_id, type, day, specific_date, start_time, end_time, note
    FROM sevenj_teacher_availability
    ORDER BY teacher_id, type DESC, day, specific_date, start_time
")->fetchAll(PDO::FETCH_ASSOC);
// Group by teacher_id
$availByTeacher = [];
foreach ($availSlots as $av) {
    $availByTeacher[$av['teacher_id']][] = $av;
}

// Build teacher map for student default teacher
$teacherMap = [];
foreach ($allTeachers as $t) { $teacherMap[$t['id']] = $t; }

$DAYS    = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$DAYS_TH = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
$dayThMap = array_combine($DAYS, $DAYS_TH);

[$alertType, $alertText] = $msg ? explode('|', $msg, 2) : ['', ''];

function msAvatarColor($name) {
    $colors = ['#ea580c','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2','#65a30d'];
    $idx = array_sum(array_map('ord', str_split($name ?? '?'))) % count($colors);
    return $colors[$idx];
}
function msInitials($name) {
    $words = preg_split('/\s+/', trim($name ?? ''));
    $ini = '';
    foreach ($words as $w) { if ($w) $ini .= mb_strtoupper(mb_substr($w,0,1)); }
    return mb_substr($ini,0,2) ?: '?';
}
?>

<?php require_once __DIR__.'/_theme.php'; ?>
<style>
.ms-card{background:#fff;border-radius:10px;padding:.85rem 1rem;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:8px;}
.ms-avatar{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9rem;color:#fff;flex-shrink:0;}
.ms-badge{display:inline-block;background:#fff7ed;color:#9a3412;border-radius:99px;padding:1px 9px;font-size:.72rem;font-weight:700;}
.ms-badge-teacher{background:#dbeafe;color:#1e40af;border-radius:6px;padding:2px 8px;font-size:.75rem;font-weight:600;}
.ms-badge-day{background:#d1fae5;color:#065f46;border-radius:6px;padding:2px 8px;font-size:.75rem;font-weight:600;}
.ms-badge-date{background:#fef3c7;color:#92400e;border-radius:6px;padding:2px 8px;font-size:.75rem;font-weight:600;}
.ms-badge-course{background:#f0fdf4;color:#166534;border-radius:6px;padding:2px 8px;font-size:.75rem;font-weight:600;}
.ms-prog{height:5px;border-radius:99px;background:#e5e7eb;overflow:hidden;width:80px;display:inline-block;vertical-align:middle;margin-left:4px;}
.ms-prog-bar{height:100%;border-radius:99px;}
.ms-linked{display:inline-block;background:#d1fae5;color:#065f46;border-radius:4px;padding:0 5px;font-size:.65rem;font-weight:700;}
.ms-unlinked{display:inline-block;background:#fee2e2;color:#991b1b;border-radius:4px;padding:0 5px;font-size:.65rem;font-weight:700;}
.ms-btn{padding:6px 14px;border-radius:7px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;}
.ms-btn-primary{background:#ea580c;color:#fff;} .ms-btn-primary:hover{background:#c2410c;}
.ms-btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db;} .ms-btn-outline:hover{background:#f3f4f6;}
.ms-btn-icon{padding:5px;border-radius:6px;background:none;border:none;cursor:pointer;color:#6b7280;} .ms-btn-icon:hover{background:#f3f4f6;}
.ms-search{width:100%;padding:9px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:.9rem;outline:none;box-sizing:border-box;}
.ms-search:focus{border-color:#ea580c;box-shadow:0 0 0 2px #fff7ed;}
.ms-form-group{margin-bottom:10px;}
.ms-form-group label{display:block;font-size:.78rem;font-weight:600;color:#374151;margin-bottom:3px;}
.ms-form-group input,.ms-form-group select,.ms-form-group textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;outline:none;}
.ms-form-group input:focus,.ms-form-group select:focus{border-color:#ea580c;box-shadow:0 0 0 2px #fff7ed;}
.ms-grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.ms-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center;}
.ms-modal-bg.open{display:flex;}
.ms-modal{background:#fff;border-radius:14px;width:100%;max-width:540px;padding:1.5rem;box-shadow:0 20px 60px rgba(0,0,0,.25);max-height:90vh;overflow-y:auto;}
.ms-modal h3{margin:0 0 1rem;font-size:1.1rem;font-weight:700;}
.ms-page-nav{display:flex;gap:6px;justify-content:center;margin-top:1rem;flex-wrap:wrap;}
.ms-page-btn{padding:5px 12px;border-radius:6px;border:1px solid #d1d5db;background:#fff;font-size:.82rem;text-decoration:none;color:#374151;}
.ms-page-btn:hover{background:#f3f4f6;}
.ms-page-btn.active{background:#ea580c;color:#fff;border-color:#ea580c;}
.ms-section-label{font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin:8px 0 4px;padding:0 2px;border-bottom:1px solid #e5e7eb;}
</style>

<div style="max-width:100%;padding-bottom:2rem;">

<?php if ($alertText): ?>
<div style="background:<?= $alertType==='success'?'#d1fae5':'#fee2e2' ?>;border-radius:8px;padding:10px 16px;margin-bottom:1rem;color:<?= $alertType==='success'?'#065f46':'#991b1b' ?>;"><?= htmlspecialchars($alertText) ?></div>
<?php endif; ?>

<!-- Header -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:1rem;">
    <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0;">📅 Manage Schedule</h2>
    <button class="ms-btn ms-btn-primary" onclick="openModal('add')">+ เพิ่มตารางเรียน</button>
</div>

<!-- Search + filter -->
<form method="get" style="display:flex;gap:8px;margin-bottom:1.25rem;flex-wrap:wrap;">
    <input type="hidden" name="q" value="/modules/7j/manage_schedule.php">
    <?php if ($filterSt): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filterSt) ?>"><?php endif; ?>
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="ms-search"
           style="min-width:200px;flex:1;" placeholder="ค้นหาชื่อนักเรียน, รหัส, ครู, คอร์ส...">
    <select name="teacher" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:.88rem;outline:none;" onchange="this.form.submit()">
        <option value="">ครูทั้งหมด</option>
        <?php foreach ($allTeachers as $t): ?>
        <option value="<?= htmlspecialchars($t['id']) ?>" <?= $filterTid===$t['id']?'selected':'' ?>>
            <?= htmlspecialchars($t['displayName']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="ms-btn ms-btn-outline">ค้นหา</button>
    <?php if ($search || $filterSt || $filterTid): ?>
    <a href="?q=/modules/7j/manage_schedule.php" class="ms-btn ms-btn-outline">✕ ล้าง</a>
    <?php endif; ?>
</form>
<?php if ($totalRows > 0): ?>
<div style="font-size:.8rem;color:#9ca3af;margin-bottom:.75rem;">แสดง <?= $totalRows ?> รายการ</div>
<?php endif; ?>

<!-- List -->
<?php if (empty($schedules)): ?>
<div style="text-align:center;padding:3rem 1rem;color:#9ca3af;">
    <div style="font-size:2.5rem;margin-bottom:.5rem;">📅</div>
    <?= $search ? 'ไม่พบผลลัพธ์' : 'ยังไม่มีตารางเรียน' ?>
</div>
<?php else: ?>

<?php foreach ($schedules as $s):
    // ใช้ชื่อจาก JOIN ถ้ามี FK ไม่งั้นใช้ text field เดิม
    $displayName  = $s['st_name'] ?: $s['student_name'];
    $displayCode  = $s['st_code'] ?: $s['student_code'];
    $displayTeach = $s['t_name']  ?: $s['teacher_name'];
    $isLinkedStu  = !empty($s['student_id']);
    $isLinkedTea  = !empty($s['teacher_ref_id']);
    $done   = (int)$s['completed_classes'];
    $tot    = (int)$s['total_classes'];
    $pct    = $tot > 0 ? min(100, round($done/$tot*100)) : 0;
    $remain = max(0, $tot - $done);
    $barCol = $pct >= 80 ? '#dc2626' : ($pct >= 50 ? '#d97706' : '#ea580c');
    $isOne  = $s['schedule_type'] === 'one_time';
    $dayLbl = $isOne ? '📅 '.fmtDate($s['specific_date']) : '🗓 '.($dayThMap[$s['day_of_week']] ?? $s['day_of_week']);
?>
<div class="ms-card">
    <div style="display:flex;align-items:flex-start;gap:12px;">
        <div class="ms-avatar" style="background:<?= msAvatarColor($displayName) ?>;"><?= htmlspecialchars(msInitials($displayName)) ?></div>
        <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;flex-wrap:wrap;gap:5px;">
                <span style="font-weight:600;font-size:.95rem;"><?= htmlspecialchars($displayName) ?></span>
                <?php if ($displayCode): ?><span class="ms-badge"><?= htmlspecialchars($displayCode) ?></span><?php endif; ?>
                <span class="<?= $isLinkedStu?'ms-linked':'ms-unlinked' ?>"><?= $isLinkedStu?'🔗 linked':'⚠️ unlinked' ?></span>
            </div>
            <div style="font-size:.8rem;color:#6b7280;margin-top:3px;display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
                <span class="ms-badge-teacher">👨‍🏫 <?= htmlspecialchars($displayTeach) ?><?= $s['t_code'] ? ' ('.$s['t_code'].')' : '' ?></span>
                <span class="<?= $isLinkedTea?'ms-linked':'ms-unlinked' ?>"><?= $isLinkedTea?'🔗':'⚠️' ?></span>
                <?php if ($s['course']): ?><span class="ms-badge-course">📚 <?= htmlspecialchars($s['course']) ?></span><?php endif; ?>
            </div>
            <div style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;margin-top:4px;">
                <?php if ($isOne): ?>
                <span class="ms-badge-date"><?= $dayLbl ?></span>
                <?php else: ?>
                <span class="ms-badge-day"><?= $dayLbl ?></span>
                <?php endif; ?>
                <?php if ($s['time_start']): ?><span style="font-size:.8rem;color:#374151;">⏰ <?= fmtTimePM($s['time_start']) ?><?= $s['time_end']?' – '.fmtTimePM($s['time_end']):'' ?></span><?php endif; ?>
            </div>
            <div style="font-size:.78rem;color:#6b7280;margin-top:4px;display:flex;align-items:center;gap:6px;">
                คาบ <?= $done ?>/<?= $tot ?> (เหลือ <?= $remain ?>)
                <div class="ms-prog"><div class="ms-prog-bar" style="width:<?= $pct ?>%;background:<?= $barCol ?>;"></div></div>
            </div>
            <?php if ($s['note']): ?>
            <div style="font-size:.75rem;color:#9ca3af;margin-top:3px;">📝 <?= htmlspecialchars($s['note']) ?></div>
            <?php endif; ?>
        </div>
        <div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0;align-items:flex-end;">
            <?php
            // ─── สถานะคอร์ส ───────────────────────────────────────────
            $stColors = ['active'=>'#dbeafe|#1e40af','completed'=>'#fef3c7|#92400e','cancelled'=>'#fee2e2|#991b1b'];
            $stLabels = ['active'=>'เปิดสอน','completed'=>'ครบแล้ว','cancelled'=>'ยกเลิก'];
            [$stBg,$stFg] = explode('|', $stColors[$s['status']] ?? '#f3f4f6|#374151');

            // ─── real-time session status (วันนี้เท่านั้น) ────────────
            $thNow    = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
            $todayStr = $thNow->format('Y-m-d');
            $todayDay = $thNow->format('l');
            $nowMins  = (int)$thNow->format('H') * 60 + (int)$thNow->format('i');
            $rtLabel  = ''; $rtStyle = '';
            if ($s['status'] === 'active') {
                $slotDate = ($isOne ? $s['specific_date'] : ($weekDates[$s['day_of_week']] ?? ''));
                if ($slotDate === $todayStr) {
                    $ts = $s['time_start'] ? array_map('intval', explode(':', $s['time_start'].':00')) : null;
                    $te = $s['time_end']   ? array_map('intval', explode(':', $s['time_end'].':00'))   : null;
                    if ($ts) {
                        $startMins = $ts[0]*60+$ts[1];
                        $endMins   = $te ? $te[0]*60+$te[1] : $startMins+60;
                        if ($nowMins < $startMins) {
                            $rtLabel = '⏳ รอเรียน'; $rtStyle = 'background:#fef3c7;color:#92400e;';
                        } elseif ($nowMins <= $endMins) {
                            $rtLabel = '🟢 กำลังเรียน'; $rtStyle = 'background:#dcfce7;color:#166534;';
                        } elseif ((int)($s['logged_today'] ?? 0) > 0) {
                            $rtLabel = '✅ เรียนแล้ววันนี้'; $rtStyle = 'background:#f0fdf4;color:#15803d;';
                        } else {
                            $rtLabel = '⏳ รอเรียน'; $rtStyle = 'background:#fef3c7;color:#92400e;';
                        }
                    }
                } elseif ($slotDate > $todayStr || $slotDate === '') {
                    // วันในอนาคต หรือ weekly ที่ยังไม่ถึงวัน
                    $rtLabel = '⏳ รอเรียน'; $rtStyle = 'background:#fef3c7;color:#92400e;';
                }
            }
            // ─── canEdit: ล็อคถ้าถึงเวลาเรียนหรือบันทึกแล้ว ──────────────
            $canEdit = true;
            $loggedTodayFlag = (int)($s['logged_today'] ?? 0) > 0;
            if ($loggedTodayFlag) {
                $canEdit = false;
            } else {
                $tsArr = $s['time_start'] ? array_map('intval', explode(':', $s['time_start'].':00')) : null;
                if ($tsArr) {
                    $sMins = $tsArr[0]*60+$tsArr[1];
                    if ($isOne) {
                        if (($s['specific_date'] ?? '') < $todayStr)                             $canEdit = false;
                        elseif (($s['specific_date'] ?? '') === $todayStr && $nowMins >= $sMins) $canEdit = false;
                    } else {
                        if ($todayDay === ($s['day_of_week'] ?? '') && $nowMins >= $sMins)       $canEdit = false;
                    }
                }
            }
            ?>
            <?php if ($rtLabel): ?>
            <span style="<?= $rtStyle ?>border-radius:99px;padding:1px 9px;font-size:.7rem;font-weight:700;">
                <?= $rtLabel ?>
            </span>
            <?php else: ?>
            <span style="background:<?= $stBg ?>;color:<?= $stFg ?>;border-radius:99px;padding:1px 9px;font-size:.7rem;font-weight:700;">
                <?= $stLabels[$s['status']] ?? $s['status'] ?>
            </span>
            <?php endif; ?>
            <div style="display:flex;gap:4px;">
                <?php if ($canEdit): ?>
                <button class="ms-btn-icon" title="แก้ไข" onclick="openEditModal(<?= htmlspecialchars(json_encode($s)) ?>)">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <?php else: ?>
                <span class="ms-btn-icon" title="ไม่สามารถแก้ไขได้ — ถึงเวลาเรียนหรือบันทึกแล้ว" style="cursor:default;color:#d1d5db;">🔒</span>
                <?php endif; ?>
                <button class="ms-btn-icon" title="ลบ" style="color:#dc2626;" onclick="confirmDelete(<?= (int)$s['id'] ?>,'<?= htmlspecialchars($displayName) ?>')">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php if ($pages > 1): ?>
<div class="ms-page-nav">
    <?php if ($page > 1): ?><a href="?q=/modules/7j/manage_schedule.php&page=<?= $page-1 ?><?= $search?'&search='.urlencode($search):'' ?>" class="ms-page-btn">&#8249;</a><?php endif; ?>
    <?php for ($p=max(1,$page-2); $p<=min($pages,$page+2); $p++): ?>
    <a href="?q=/modules/7j/manage_schedule.php&page=<?= $p ?><?= $search?'&search='.urlencode($search):'' ?>" class="ms-page-btn <?= $p==$page?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($page < $pages): ?><a href="?q=/modules/7j/manage_schedule.php&page=<?= $page+1 ?><?= $search?'&search='.urlencode($search):'' ?>" class="ms-page-btn">&#8250;</a><?php endif; ?>
</div>
<div style="text-align:center;font-size:.8rem;color:#9ca3af;margin-top:6px;">หน้า <?= $page ?>/<?= $pages ?></div>
<?php endif; ?>
<?php endif; ?>
</div>

<!-- ─── Add/Edit Modal ─────────────────────────────────────────────────────── -->
<div id="modal-schedule" class="ms-modal-bg">
<div class="ms-modal">
    <h3 id="modal-title">📅 เพิ่มตารางเรียน</h3>
    <form method="post" id="schedule-form">
        <input type="hidden" name="q" value="/modules/7j/manage_schedule.php">
        <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
        <input type="hidden" name="action" id="f-action" value="add">
        <input type="hidden" name="id" id="f-id" value="0">

        <!-- นักเรียน -->
        <div class="ms-section-label">นักเรียน</div>
        <input type="hidden" name="student_id" id="f-student-id">
        <div class="ms-form-group" style="position:relative;">
            <label>ค้นหานักเรียน</label>
            <input type="text" id="f-student-search" autocomplete="off"
                   placeholder="พิมพ์ชื่อหรือรหัส เช่น S260001..."
                   oninput="studentSearch(this.value)"
                   onfocus="studentSearch(this.value)"
                   style="padding-right:32px;">
            <span id="f-student-clear" onclick="clearStudent()"
                  style="display:none;position:absolute;right:28px;top:28px;cursor:pointer;color:#9ca3af;font-size:1rem;">✕</span>
            <div id="f-student-dropdown"
                 style="display:none;position:absolute;z-index:200;width:100%;background:#fff;
                        border:1px solid #d1d5db;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);
                        max-height:220px;overflow-y:auto;top:100%;left:0;margin-top:2px;"></div>
        </div>
        <div class="ms-grid2">
            <div class="ms-form-group">
                <label>ชื่อนักเรียน *</label>
                <input type="text" name="student_name" id="f-sname" required placeholder="กรอกอัตโนมัติเมื่อเลือก">
            </div>
            <div class="ms-form-group">
                <label>รหัสนักเรียน</label>
                <input type="text" name="student_code" id="f-scode" placeholder="S260001">
            </div>
        </div>

        <!-- ครู -->
        <div class="ms-section-label">ครู</div>
        <div class="ms-form-group" style="position:relative;">
            <label>ค้นหาครู</label>
            <input type="text" id="f-teacher-search" autocomplete="off"
                   placeholder="พิมพ์ชื่อหรือรหัสครู..."
                   oninput="teacherSearch(this.value)"
                   onfocus="teacherSearch(this.value)"
                   style="padding-right:32px;">
            <span id="f-teacher-clear" onclick="clearTeacher()"
                  style="display:none;position:absolute;right:28px;top:28px;cursor:pointer;color:#9ca3af;font-size:1rem;">✕</span>
            <div id="f-teacher-dropdown"
                 style="display:none;position:absolute;z-index:200;width:100%;background:#fff;
                        border:1px solid #d1d5db;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);
                        max-height:200px;overflow-y:auto;top:100%;left:0;margin-top:2px;"></div>
        </div>
        <div class="ms-form-group">
            <label>เลือกจากระบบ (แนะนำ)</label>
            <select name="teacher_ref_id" id="f-teacher-id" onchange="onTeacherChange(this)">
                <option value="">— เลือกครู —</option>
                <?php foreach ($allTeachers as $t): ?>
                <option value="<?= htmlspecialchars($t['id']) ?>"
                    data-name="<?= htmlspecialchars($t['displayName']) ?>">
                    <?= htmlspecialchars($t['displayName']) ?><?= $t['teacherCode']?' ('.$t['teacherCode'].')':'' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ms-form-group">
            <label>ชื่อครู *</label>
            <input type="text" name="teacher_name" id="f-tname" required placeholder="หรือกรอกเอง">
        </div>

        <!-- Availability hint -->
        <div id="avail-hint" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:8px 12px;margin-bottom:8px;font-size:.8rem;color:#166534;">
            <strong>🕐 ช่วงเวลาว่างของครู:</strong>
            <div id="avail-list" style="margin-top:4px;"></div>
        </div>

        <!-- ตาราง -->
        <div class="ms-section-label">ตารางเรียน</div>
        <div class="ms-grid2">
            <div class="ms-form-group">
                <label>คอร์ส</label>
                <input type="text" name="course" id="f-course" placeholder="เช่น Basic, IELTS">
            </div>
            <div class="ms-form-group">
                <label>ประเภท</label>
                <select name="schedule_type" id="f-stype" onchange="toggleType()">
                    <option value="weekly">🗓 รายสัปดาห์</option>
                    <option value="one_time">📆 วันเดียว</option>
                </select>
            </div>
        </div>
        <div id="f-weekly-group" class="ms-form-group">
            <label id="f-day-label">วันในสัปดาห์</label>
            <select name="day_of_week" id="f-day">
                <?php foreach ($DAYS as $i => $d): ?>
                <option value="<?= $d ?>"><?= $DAYS_TH[$i] ?> (<?= $d ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div id="f-date-group" style="display:none;margin-bottom:10px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                <label style="font-size:.8rem;font-weight:600;color:#374151;margin:0;">วันที่</label>
                <button type="button" onclick="addDateSlot()"
                    style="display:flex;align-items:center;gap:4px;padding:3px 10px;background:#1A2A5E;color:#fff;border:none;border-radius:6px;font-size:.78rem;font-weight:600;cursor:pointer;">
                    + เพิ่มวันที่
                </button>
            </div>
            <div id="f-dates-container">
                <div class="f-date-row" style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin-bottom:6px;">
                    <input type="date" name="specific_dates[]" id="f-date"
                        style="flex:1;min-width:110px;padding:7px 8px;border:1px solid #d1d5db;border-radius:7px;font-size:.82rem;box-sizing:border-box;outline:none;">
                    <input type="time" name="time_starts[]"
                        style="width:92px;padding:7px 6px;border:1px solid #d1d5db;border-radius:7px;font-size:.82rem;box-sizing:border-box;outline:none;" placeholder="เริ่ม">
                    <span style="color:#9ca3af;font-size:.8rem;flex-shrink:0;">–</span>
                    <input type="time" name="time_ends[]"
                        style="width:92px;padding:7px 6px;border:1px solid #d1d5db;border-radius:7px;font-size:.82rem;box-sizing:border-box;outline:none;" placeholder="จบ">
                </div>
            </div>
        </div>
        <div id="f-time-group" class="ms-grid2">
            <div class="ms-form-group"><label>เวลาเริ่ม</label><input type="time" name="time_start" id="f-tstart"></div>
            <div class="ms-form-group"><label>เวลาจบ</label><input type="time" name="time_end" id="f-tend"></div>
        </div>
        <div class="ms-grid2">
            <div class="ms-form-group"><label>คาบทั้งหมด</label><input type="number" name="total_classes" id="f-total" value="20" min="1"></div>
            <div class="ms-form-group"><label>คาบที่เรียนแล้ว</label><input type="number" name="completed_classes" id="f-done" value="0" min="0"></div>
        </div>
        <div class="ms-form-group">
            <label>หมายเหตุ</label>
            <textarea name="note" id="f-note" rows="2" placeholder="บันทึกเพิ่มเติม..."></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
            <button type="button" class="ms-btn ms-btn-outline" onclick="closeModal()">ยกเลิก</button>
            <button type="submit" class="ms-btn ms-btn-primary">บันทึก</button>
        </div>
    </form>
</div>
</div>

<!-- Delete Modal -->
<div id="modal-del" class="ms-modal-bg">
<div class="ms-modal" style="max-width:380px;">
    <h3 style="color:#dc2626;">ยืนยันการลบ</h3>
    <p id="del-text" style="color:#374151;margin:.5rem 0 1.25rem;"></p>
    <form method="post">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="q" value="/modules/7j/manage_schedule.php">
        <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
        <input type="hidden" name="id" id="del-id">
        <div style="display:flex;justify-content:flex-end;gap:8px;">
            <button type="button" class="ms-btn ms-btn-outline" onclick="document.getElementById('modal-del').classList.remove('open')">ยกเลิก</button>
            <button type="submit" class="ms-btn" style="background:#dc2626;color:#fff;">ลบ</button>
        </div>
    </form>
</div>
</div>

<!-- Log Class Modal -->
<div id="modal-log" class="ms-modal-bg">
<div class="ms-modal" style="max-width:400px;">
    <h3>✅ บันทึกคาบเรียน</h3>
    <p id="log-name" style="font-size:.9rem;color:#6b7280;margin:-4px 0 14px;"></p>
    <form method="post">
        <input type="hidden" name="action" value="log_class">
        <input type="hidden" name="q" value="/modules/7j/manage_schedule.php">
        <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
        <?php if ($filterSt): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filterSt) ?>"><?php endif; ?>
        <input type="hidden" name="id" id="log-id">
        <div class="ms-form-group">
            <label>วันที่เรียน</label>
            <input type="date" name="log_date" id="log-date" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="ms-form-group">
            <label>คาบที่ <span id="log-session" style="color:#ea580c;font-weight:700;"></span></label>
            <textarea name="log_note" rows="2" placeholder="หมายเหตุ เช่น เรียนหัวข้อ Grammar, นักเรียนขยัน..." style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;"></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
            <button type="button" class="ms-btn ms-btn-outline" onclick="document.getElementById('modal-log').classList.remove('open')">ยกเลิก</button>
            <button type="submit" class="ms-btn" style="background:#059669;color:#fff;">✓ บันทึก</button>
        </div>
    </form>
</div>
</div>


<!-- Students JSON for JS -->
<script>
var studentsData = <?= json_encode(array_column($allStudents, null, 'id')) ?>;
var teachersData = <?= json_encode(array_column($allTeachers, null, 'id')) ?>;
var availData    = <?= json_encode($availByTeacher) ?>;

var DAYS_TH = {Sunday:'อาทิตย์',Monday:'จันทร์',Tuesday:'อังคาร',Wednesday:'พุธ',Thursday:'พฤหัสบดี',Friday:'ศุกร์',Saturday:'เสาร์'};

function openModal(mode) {
    document.getElementById('modal-title').textContent = mode === 'add' ? '📅 เพิ่มตารางเรียน' : '✏️ แก้ไขตารางเรียน';
    document.getElementById('f-action').value = mode;
    document.getElementById('modal-schedule').classList.add('open');
}
function closeModal() {
    document.getElementById('modal-schedule').classList.remove('open');
    document.getElementById('schedule-form').reset();
    document.getElementById('f-action').value = 'add';
    document.getElementById('f-id').value = '0';
    document.getElementById('modal-title').textContent = '📅 เพิ่มตารางเรียน';
    clearStudent();
    document.getElementById('avail-hint').style.display = 'none';
    // reset dates container เหลือแค่ 1 row (พร้อม time fields)
    var c = document.getElementById('f-dates-container');
    if (c) {
        c.innerHTML = '<div class="f-date-row" style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin-bottom:6px;">'
            + '<input type="date" name="specific_dates[]" id="f-date" style="flex:1;min-width:110px;padding:7px 8px;border:1px solid #d1d5db;border-radius:7px;font-size:.82rem;box-sizing:border-box;outline:none;">'
            + '<input type="time" name="time_starts[]" style="width:92px;padding:7px 6px;border:1px solid #d1d5db;border-radius:7px;font-size:.82rem;box-sizing:border-box;outline:none;">'
            + '<span style="color:#9ca3af;font-size:.8rem;flex-shrink:0;">–</span>'
            + '<input type="time" name="time_ends[]" style="width:92px;padding:7px 6px;border:1px solid #d1d5db;border-radius:7px;font-size:.82rem;box-sizing:border-box;outline:none;">'
            + '</div>';
    }
    toggleType();
}

function addDateSlot(dateVal, tsVal, teVal) {
    var c = document.getElementById('f-dates-container');
    if (!c) return;
    // copy time from last row if not specified
    var lastRow = c.querySelector('.f-date-row:last-child');
    if (!tsVal && lastRow) tsVal = (lastRow.querySelector('[name="time_starts[]"]') || {}).value || '';
    if (!teVal && lastRow) teVal = (lastRow.querySelector('[name="time_ends[]"]') || {}).value || '';
    var row = document.createElement('div');
    row.className = 'f-date-row';
    row.style.cssText = 'display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin-bottom:6px;';
    var inp = 'style="padding:7px 6px;border:1px solid #d1d5db;border-radius:7px;font-size:.82rem;box-sizing:border-box;outline:none;"';
    row.innerHTML = '<input type="date" name="specific_dates[]" value="' + (dateVal||'') + '" '
        + 'style="flex:1;min-width:110px;padding:7px 8px;border:1px solid #d1d5db;border-radius:7px;font-size:.82rem;box-sizing:border-box;outline:none;">'
        + '<input type="time" name="time_starts[]" value="' + (tsVal||'') + '" style="width:92px;' + inp.slice(7) + '>'
        + '<span style="color:#9ca3af;font-size:.8rem;flex-shrink:0;">–</span>'
        + '<input type="time" name="time_ends[]" value="' + (teVal||'') + '" style="width:92px;' + inp.slice(7) + '>'
        + '<button type="button" onclick="this.parentNode.remove()" '
        + 'style="padding:4px 9px;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:6px;cursor:pointer;font-size:.85rem;font-weight:700;flex-shrink:0;" title="ลบ">✕</button>';
    c.appendChild(row);
    row.querySelector('input').focus();
}
document.querySelectorAll('.ms-modal-bg').forEach(function(bg) {
    bg.addEventListener('click', function(e) { if (e.target === bg) bg.classList.remove('open'); });
});

// อัปเดต badge วันที่เมื่อ user เปลี่ยนวันที่ใน container หรือเปลี่ยน dropdown วัน
document.getElementById('f-dates-container') && document.getElementById('f-dates-container').addEventListener('change', function(e) {
    if (e.target && e.target.name === 'specific_dates[]') updateDateDayBadges();
});
document.getElementById('f-day') && document.getElementById('f-day').addEventListener('change', updateDateDayBadges);

var DAY_TH_JS = {Monday:'จันทร์',Tuesday:'อังคาร',Wednesday:'พุธ',Thursday:'พฤหัสบดี',Friday:'ศุกร์',Saturday:'เสาร์',Sunday:'อาทิตย์'};
var DAY_EN_JS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

function toggleType() {
    var t = document.getElementById('f-stype').value;
    // แสดง day dropdown เสมอ — ต่างกันที่ label
    document.getElementById('f-weekly-group').style.display = '';
    var lbl = document.getElementById('f-day-label');
    if (lbl) lbl.textContent = t === 'weekly' ? 'วันในสัปดาห์' : '📅 วันที่ต้องตรง (ตรวจสอบวันที่กรอก)';
    document.getElementById('f-date-group').style.display   = t === 'one_time' ? '' : 'none';
    document.getElementById('f-time-group').style.display   = t === 'weekly'   ? '' : 'none';
    updateDateDayBadges();
}

function getDayName(dateStr) {
    if (!dateStr) return '';
    var d = new Date(dateStr + 'T00:00:00');
    return isNaN(d.getTime()) ? '' : DAY_EN_JS[d.getDay()];
}

function updateDateDayBadges() {
    var t = (document.getElementById('f-stype') || {}).value;
    if (t !== 'one_time') return;
    var selectedDay = (document.getElementById('f-day') || {}).value || '';
    document.querySelectorAll('#f-dates-container .f-date-row').forEach(function(row) {
        var di = row.querySelector('[name="specific_dates[]"]');
        if (!di) return;
        var badge = row.querySelector('.day-badge');
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'day-badge';
            badge.style.cssText = 'font-size:.72rem;font-weight:700;padding:2px 7px;border-radius:5px;flex-shrink:0;';
            di.insertAdjacentElement('afterend', badge);
        }
        var dn = getDayName(di.value);
        if (!dn) { badge.textContent = ''; badge.style.display = 'none'; return; }
        badge.style.display = '';
        var match = !selectedDay || dn === selectedDay;
        badge.textContent = (match ? '✓ ' : '✗ ') + DAY_TH_JS[dn];
        badge.style.background = match ? '#dcfce7' : '#fee2e2';
        badge.style.color      = match ? '#166534' : '#dc2626';
    });
}

// ─── Student Autocomplete (ค้นหาจาก studentsData ที่โหลดแล้ว) ────────────────
var _stuTimer = null;

function studentSearch(val) {
    var dd = document.getElementById('f-student-dropdown');
    clearTimeout(_stuTimer);
    val = val.trim();
    if (val.length < 1) { dd.style.display = 'none'; return; }
    _stuTimer = setTimeout(function() {
        var q = val.toLowerCase();
        var results = Object.values(studentsData).filter(function(s) {
            return (s.displayName && s.displayName.toLowerCase().indexOf(q) >= 0)
                || (s.studentCode && s.studentCode.toLowerCase().indexOf(q) >= 0)
                || (s.nickname && s.nickname.toLowerCase().indexOf(q) >= 0);
        }).slice(0, 20);

        if (!results.length) {
            dd.innerHTML = '<div style="padding:10px 14px;color:#9ca3af;font-size:.85rem;">ไม่พบนักเรียน</div>';
        } else {
            dd.innerHTML = results.map(function(s) {
                return '<div data-sid="' + _escHtml(s.id) + '"'
                    + ' style="padding:8px 14px;cursor:pointer;font-size:.88rem;border-bottom:1px solid #f3f4f6;"'
                    + ' onmouseover="this.style.background=\'#fffbeb\'" onmouseout="this.style.background=\'\'">'
                    + '<span style="font-weight:600;">' + _escHtml(s.displayName) + '</span>'
                    + (s.studentCode ? ' <span style="background:#fff7ed;color:#9a3412;border-radius:99px;padding:1px 7px;font-size:.75rem;margin-left:4px;">' + _escHtml(s.studentCode) + '</span>' : '')
                    + '</div>';
            }).join('');
            // event delegation — คลิกที่ item ใดก็ได้
            dd.querySelectorAll('[data-sid]').forEach(function(el) {
                el.addEventListener('click', function() {
                    var s = studentsData[this.dataset.sid];
                    if (s) selectStudent(s);
                });
            });
        }
        dd.style.display = '';
    }, 150);
}

function _escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function selectStudent(s) {
    document.getElementById('f-student-id').value    = s.id          || '';
    document.getElementById('f-student-search').value = s.displayName + (s.studentCode ? ' (' + s.studentCode + ')' : '');
    document.getElementById('f-sname').value          = s.displayName || '';
    document.getElementById('f-scode').value          = s.studentCode || '';
    document.getElementById('f-student-clear').style.display = 'inline';
    document.getElementById('f-student-dropdown').style.display = 'none';
    // auto-fill ครูประจำ
    if (s.teacherId && teachersData[s.teacherId]) {
        document.getElementById('f-teacher-id').value = s.teacherId;
        document.getElementById('f-tname').value = teachersData[s.teacherId].displayName || '';
        showAvailability(s.teacherId);
    }
    // auto-fill คาบทั้งหมด จาก package ของนักเรียน (ยังแก้ไขได้)
    if (s.totalClasses) {
        document.getElementById('f-total').value = s.totalClasses;
    }
    // auto-fill คาบที่เรียนแล้ว จาก sevenj_class_completions จริง
    document.getElementById('f-done').value = s.actualCompleted || 0;
}

function clearStudent() {
    document.getElementById('f-student-id').value     = '';
    document.getElementById('f-student-search').value = '';
    document.getElementById('f-sname').value          = '';
    document.getElementById('f-scode').value          = '';
    document.getElementById('f-student-clear').style.display = 'none';
    document.getElementById('f-student-dropdown').style.display = 'none';
}

// ปิด dropdown เมื่อคลิกนอก
document.addEventListener('click', function(e) {
    if (!e.target.closest('#f-student-search') && !e.target.closest('#f-student-dropdown')) {
        var dd = document.getElementById('f-student-dropdown');
        if (dd) dd.style.display = 'none';
    }
});

// ─── Teacher Search ────────────────────────────────────────────────────────
function teacherSearch(q) {
    var dd = document.getElementById('f-teacher-dropdown');
    if (!q.trim()) { dd.style.display = 'none'; return; }
    var lq = q.toLowerCase();
    var matches = Object.values(teachersData).filter(function(t) {
        return (t.displayName||'').toLowerCase().includes(lq)
            || (t.teacherCode||'').toLowerCase().includes(lq);
    });
    if (!matches.length) { dd.style.display = 'none'; return; }
    dd.innerHTML = '';
    matches.forEach(function(t) {
        var item = document.createElement('div');
        item.style.cssText = 'padding:9px 14px;cursor:pointer;border-bottom:1px solid #f3f4f6;font-size:.88rem;';
        item.innerHTML = '<strong>' + t.displayName + '</strong>'
            + (t.teacherCode ? ' <span style="color:#9ca3af;font-size:.78rem;">(' + t.teacherCode + ')</span>' : '');
        item.onmousedown = function(e) { e.preventDefault(); selectTeacher(t.id); };
        item.onmouseover = function() { this.style.background='#f9fafb'; };
        item.onmouseout  = function() { this.style.background=''; };
        dd.appendChild(item);
    });
    dd.style.display = 'block';
}
function selectTeacher(tid) {
    var t = teachersData[tid];
    if (!t) return;
    document.getElementById('f-teacher-search').value = t.displayName + (t.teacherCode ? ' (' + t.teacherCode + ')' : '');
    document.getElementById('f-teacher-clear').style.display = 'inline';
    document.getElementById('f-teacher-dropdown').style.display = 'none';
    document.getElementById('f-teacher-id').value = tid;
    document.getElementById('f-tname').value = t.displayName || '';
    showAvailability(tid);
}
function clearTeacher() {
    document.getElementById('f-teacher-search').value = '';
    document.getElementById('f-teacher-clear').style.display = 'none';
    document.getElementById('f-teacher-id').value = '';
    document.getElementById('f-tname').value = '';
    document.getElementById('f-teacher-dropdown').style.display = 'none';
    showAvailability('');
}
document.addEventListener('click', function(e) {
    if (!document.getElementById('f-teacher-search').contains(e.target))
        document.getElementById('f-teacher-dropdown').style.display = 'none';
});

// เมื่อเลือกครูจาก dropdown — auto-fill ชื่อ + แสดง availability
function onTeacherChange(sel) {
    var opt = sel.options[sel.selectedIndex];
    document.getElementById('f-tname').value = opt.value ? (opt.dataset.name || '') : '';
    if (opt.value && teachersData[opt.value]) {
        document.getElementById('f-teacher-search').value = opt.dataset.name || '';
        document.getElementById('f-teacher-clear').style.display = 'inline';
    }
    showAvailability(opt.value);
}

function showAvailability(teacherId) {
    var hint = document.getElementById('avail-hint');
    var list = document.getElementById('avail-list');
    if (!teacherId || !availData[teacherId] || availData[teacherId].length === 0) {
        hint.style.display = 'none'; return;
    }
    var slots = availData[teacherId].slice().sort(function(a,b){
        return (a.start_time||'').localeCompare(b.start_time||'');
    });
    list.innerHTML = '';
    slots.forEach(function(s) {
        var label = s.type === 'weekly'
            ? '🗓 ' + (DAYS_TH[s.day] || s.day)
            : '📅 ' + s.specific_date;
        var chip = document.createElement('span');
        chip.style.cssText = 'display:inline-block;background:#bbf7d0;color:#166534;border-radius:6px;'
            + 'padding:3px 10px;margin:3px 3px;font-size:.78rem;cursor:pointer;'
            + 'border:1px solid #86efac;transition:background .15s;user-select:none;';
        chip.title = 'คลิกเพื่อเลือกช่วงเวลานี้';
        chip.innerHTML = label + ' <strong>' + s.start_time + '–' + s.end_time + '</strong>'
            + (s.note ? ' <span style="opacity:.65;">(' + s.note + ')</span>' : '')
            + ' <span style="font-size:.7rem;opacity:.6;">▶ เลือก</span>';
        chip.onmouseover = function() { this.style.background = '#4ade80'; };
        chip.onmouseout  = function() { this.style.background = '#bbf7d0'; };
        chip.onclick = function() { applyAvailSlot(s); };
        list.appendChild(chip);
    });
    hint.style.display = '';
}

function applyAvailSlot(s) {
    // ตั้งประเภท
    var stype = s.type === 'weekly' ? 'weekly' : 'one_time';
    document.getElementById('f-stype').value = stype;
    toggleType();

    if (stype === 'weekly') {
        // ตั้งวันในสัปดาห์
        document.getElementById('f-day').value = s.day || 'Monday';
        // ตั้งเวลา global (weekly)
        document.getElementById('f-tstart').value = s.start_time || '';
        document.getElementById('f-tend').value   = s.end_time   || '';
    } else {
        // ใส่วันที่เข้า slot ที่ว่างอยู่ หรือเพิ่ม slot ใหม่
        var rows   = document.querySelectorAll('#f-dates-container .f-date-row');
        var filled = false;
        for (var i = 0; i < rows.length; i++) {
            var di = rows[i].querySelector('[name="specific_dates[]"]');
            if (di && !di.value) {
                di.value = s.specific_date || '';
                var tsi = rows[i].querySelector('[name="time_starts[]"]');
                var tei = rows[i].querySelector('[name="time_ends[]"]');
                if (tsi) tsi.value = s.start_time || '';
                if (tei) tei.value = s.end_time   || '';
                filled = true; break;
            }
        }
        if (!filled) addDateSlot(s.specific_date || '', s.start_time || '', s.end_time || '');
    }

    // highlight chip ที่เลือก
    document.querySelectorAll('#avail-list span').forEach(function(c) {
        c.style.background = '#bbf7d0';
        c.style.fontWeight = 'normal';
    });
    event.currentTarget.style.background  = '#16a34a';
    event.currentTarget.style.color       = '#fff';
}

function openEditModal(d) {
    document.getElementById('f-action').value        = 'edit';
    document.getElementById('f-id').value            = d.id;
    // student search display
    document.getElementById('f-student-id').value    = d.student_id   || '';
    var sLabel = (d.student_name || '') + (d.student_code ? ' (' + d.student_code + ')' : '');
    document.getElementById('f-student-search').value = sLabel;
    document.getElementById('f-student-clear').style.display = sLabel ? 'inline' : 'none';
    document.getElementById('f-sname').value         = d.student_name || '';
    document.getElementById('f-scode').value         = d.student_code || '';
    document.getElementById('f-teacher-id').value    = d.teacher_ref_id || '';
    document.getElementById('f-tname').value         = d.teacher_name || '';
    document.getElementById('f-course').value        = d.course       || '';
    document.getElementById('f-stype').value         = d.schedule_type|| 'weekly';
    document.getElementById('f-day').value           = d.day_of_week  || 'Monday';
    // reset dates container to single date for edit (พร้อม time fields)
    var c = document.getElementById('f-dates-container');
    if (c) {
        var ts0 = d.time_start || '', te0 = d.time_end || '';
        c.innerHTML = '<div class="f-date-row" style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin-bottom:6px;">'
            + '<input type="date" name="specific_dates[]" id="f-date" value="' + (d.specific_date || '') + '" '
            + 'style="flex:1;min-width:110px;padding:7px 8px;border:1px solid #d1d5db;border-radius:7px;font-size:.82rem;box-sizing:border-box;outline:none;">'
            + '<input type="time" name="time_starts[]" value="' + ts0 + '" style="width:92px;padding:7px 6px;border:1px solid #d1d5db;border-radius:7px;font-size:.82rem;box-sizing:border-box;outline:none;">'
            + '<span style="color:#9ca3af;font-size:.8rem;flex-shrink:0;">–</span>'
            + '<input type="time" name="time_ends[]" value="' + te0 + '" style="width:92px;padding:7px 6px;border:1px solid #d1d5db;border-radius:7px;font-size:.82rem;box-sizing:border-box;outline:none;">'
            + '</div>';
    }
    document.getElementById('f-tstart').value        = d.time_start   || '';
    document.getElementById('f-tend').value          = d.time_end     || '';
    document.getElementById('f-total').value         = d.total_classes|| 20;
    document.getElementById('f-done').value          = d.completed_classes || 0;
    document.getElementById('f-note').value          = d.note         || '';
    toggleType();
    openModal('edit');
}

function confirmDelete(id, name) {
    document.getElementById('del-id').value = id;
    document.getElementById('del-text').textContent = 'ต้องการลบตารางเรียนของ "' + name + '" ใช่หรือไม่?';
    document.getElementById('modal-del').classList.add('open');
}

function openLogModal(id, name, sessionNum) {
    document.getElementById('log-id').value = id;
    document.getElementById('log-name').textContent = name;
    document.getElementById('log-session').textContent = sessionNum;
    document.getElementById('log-date').value = new Date().toISOString().slice(0,10);
    document.getElementById('modal-log').classList.add('open');
}

</script>
