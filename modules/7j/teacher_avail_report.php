<?php
/*
 * 7J English Center — Teacher Availability Report
 * แสดงตารางว่างครูรายสัปดาห์ + สถานะจอง/ว่าง
 */

$search = trim($_GET['search'] ?? '');
$selDay = trim($_GET['day']    ?? '');

// ─── จองเวลา (POST handler) ───────────────────────────────────────────────────
$bookMsg = '';
if (($_POST['_action'] ?? '') === 'book_slot') {
    $bTid    = trim($_POST['b_teacher_id']   ?? '');
    $bTname  = trim($_POST['b_teacher_name'] ?? '');
    $bStype  = in_array($_POST['b_stype'] ?? '', ['weekly','one_time']) ? $_POST['b_stype'] : 'one_time';
    $bDay    = trim($_POST['b_day']          ?? '');
    $bDate   = trim($_POST['b_date']         ?? '');
    $bTs     = trim($_POST['b_time_start']   ?? '');
    $bTe     = trim($_POST['b_time_end']     ?? '');
    $bSid    = trim($_POST['b_student_id']   ?? '');
    $bScode  = trim($_POST['b_student_code'] ?? '');
    $bSname  = trim($_POST['b_student_name'] ?? '');
    $bCourse = trim($_POST['b_course']       ?? '');
    $bTotal  = max(1, (int)($_POST['b_total_classes'] ?? 1));

    if (!$bTname || !$bSname || !$bTs || !$bTe) {
        $bookMsg = 'error|กรุณากรอกข้อมูลให้ครบ (นักเรียน, เวลา)';
    } elseif ($bStype === 'one_time' && !$bDate) {
        $bookMsg = 'error|กรุณาระบุวันที่';
    } elseif ($bStype === 'weekly' && !$bDay) {
        $bookMsg = 'error|กรุณาระบุวันในสัปดาห์';
    } else {
        try {
            $stmt = $connection2->prepare(
                "INSERT INTO sevenj_schedule
                 (student_id,student_code,student_name,teacher_ref_id,teacher_name,
                  schedule_type,day_of_week,time_start,time_end,specific_date,
                  course,total_classes,completed_classes,status,note)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,'active','')"
            );
            $stmt->execute([
                $bSid ?: null, $bScode, $bSname, $bTid ?: null, $bTname,
                $bStype, $bDay, $bTs, $bTe, $bDate ?: null,
                $bCourse, $bTotal,
            ]);
            $bookMsg = 'success|จองเวลาสำเร็จ! ตารางถูกเพิ่มใน ตารางสอน/ตารางเรียน แล้ว';
        } catch (Exception $e) {
            $bookMsg = 'error|เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    }
}

// ─── โหลดนักเรียน (สำหรับ booking modal) ────────────────────────────────────
$allStudents = $connection2->query("
    SELECT id, studentCode, displayName, nickname
    FROM sevenj_students WHERE status='active' ORDER BY displayName
")->fetchAll(PDO::FETCH_ASSOC);

// ─── ข้อมูลครู ────────────────────────────────────────────────────────────────
$teachers = $connection2->query("
    SELECT id, displayName, teacherCode, nickname
    FROM sevenj_teachers WHERE status='active' ORDER BY displayName
")->fetchAll(PDO::FETCH_ASSOC);

// ─── ช่วงเวลาว่างครู ─────────────────────────────────────────────────────────
$availRows = $connection2->query("
    SELECT * FROM sevenj_teacher_availability ORDER BY teacher_id, day, start_time
")->fetchAll(PDO::FETCH_ASSOC);

$availByTeacher = [];
foreach ($availRows as $av) {
    $availByTeacher[$av['teacher_id']][] = $av;
}

// ─── ตารางเรียน active (สำหรับตรวจสอบการจอง) ─────────────────────────────────
$schedRows = $connection2->query("
    SELECT s.teacher_ref_id, s.day_of_week, s.schedule_type,
           s.specific_date, s.time_start, s.time_end,
           COALESCE(st.displayName, s.student_name) AS student_display,
           s.course, s.completed_classes, s.total_classes
    FROM sevenj_schedule s
    LEFT JOIN sevenj_students st ON st.id = s.student_id
    WHERE s.status = 'active' AND s.completed_classes < s.total_classes
")->fetchAll(PDO::FETCH_ASSOC);

// index schedules by teacher_ref_id → day → list
$schedByTeacherDay = [];
foreach ($schedRows as $sr) {
    $tid = $sr['teacher_ref_id'] ?? '';
    if (!$tid) continue;
    $day = $sr['day_of_week'] ?? '';
    // one_time + specific_date → index by date key
    if ($sr['schedule_type'] === 'one_time' && ($sr['specific_date'] ?? '')) {
        $day = '__date__'.$sr['specific_date'];
    }
    $schedByTeacherDay[$tid][$day][] = $sr;
}

$DAYS_ORDER = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$DAYS_TH    = ['Sunday'=>'อาทิตย์','Monday'=>'จันทร์','Tuesday'=>'อังคาร','Wednesday'=>'พุธ','Thursday'=>'พฤหัสบดี','Friday'=>'ศุกร์','Saturday'=>'เสาร์'];
$TODAY_DOW  = date('l');
$TODAY_STR  = date('Y-m-d');
$thNow      = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
$NOW_MINS   = (int)$thNow->format('H') * 60 + (int)$thNow->format('i');

// helper: ตรวจว่า slot หมดเวลาแล้วหรือไม่
function tarIsExpired(string $slotDate, string $endTime, string $todayStr, int $nowMins): bool {
    if ($slotDate < $todayStr) return true;
    if ($slotDate === $todayStr && $endTime !== '') {
        [$eh, $em] = array_pad(explode(':', $endTime), 2, '00');
        $endMins = (int)$eh * 60 + (int)$em;
        return $nowMins > $endMins;
    }
    return false;
}

// helper: แปลงเวลา 24h → 12h AM/PM
function tarFmt(string $t): string {
    if ($t === '') return '';
    [$h, $m] = array_pad(explode(':', $t), 2, '00');
    $h = (int)$h;
    $s = $h >= 12 ? 'PM' : 'AM';
    $h = $h % 12 ?: 12;
    return $h . ':' . $m . ' ' . $s;
}

// helper: check ถ้า slot ถูกจอง (time overlap)
function isBooked(string $slotStart, string $slotEnd, array $dayScheds): array {
    $booked = [];
    foreach ($dayScheds as $sc) {
        $ss = $sc['time_start'] ?? '';
        $se = $sc['time_end']   ?? '';
        // overlap: start < slotEnd AND end > slotStart
        if ($ss < $slotEnd && ($se === '' || $se > $slotStart)) {
            $booked[] = $sc;
        }
    }
    return $booked;
}

// filter teachers by search
$filteredTeachers = $teachers;
if ($search) {
    $filteredTeachers = array_filter($teachers, fn($t) =>
        mb_stripos($t['displayName'], $search) !== false ||
        mb_stripos($t['teacherCode'], $search) !== false
    );
}
?>
<?php require_once __DIR__.'/_theme.php'; ?>
<style>
.tar-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:1.25rem;overflow:hidden;}
.tar-teacher-hdr{background:linear-gradient(135deg,#ea580c,#d97706);padding:10px 16px;display:flex;align-items:center;gap:10px;color:#fff;}
.tar-day-section{border-bottom:1px solid #f3f4f6;}
.tar-day-hdr{padding:7px 14px;background:#fffbeb;font-size:.78rem;font-weight:700;color:#92400e;display:flex;align-items:center;gap:6px;border-bottom:1px solid #fde68a;}
.tar-slot{display:flex;align-items:center;gap:8px;padding:7px 16px;border-bottom:1px solid #f9fafb;font-size:.82rem;flex-wrap:wrap;}
.tar-slot:last-child{border-bottom:none;}
.tar-badge{display:inline-block;border-radius:20px;padding:2px 10px;font-size:.72rem;font-weight:700;}
.tar-free{background:#dcfce7;color:#166534;}
.tar-busy{background:#fee2e2;color:#991b1b;}
.tar-today{background:#fef9c3;color:#713f12;}
.tar-stu{background:#dbeafe;color:#1e40af;border-radius:6px;padding:2px 8px;font-size:.75rem;}
.tar-empty{padding:10px 16px;font-size:.8rem;color:#9ca3af;font-style:italic;}
.tar-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:0;}
.tar-grid-cell{border-right:1px solid #f3f4f6;padding:4px;}
.tar-grid-cell:last-child{border-right:none;}
</style>

<div style="max-width:100%;padding-bottom:2rem;">

<!-- Header -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:1rem;">
    <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0;">📊 รายงานตารางว่างครู</h2>
    <div style="font-size:.8rem;color:#9ca3af;"><?= count($filteredTeachers) ?> ครู</div>
</div>

<!-- Filter -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1.25rem;">
    <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;">
        <input type="hidden" name="q" value="/modules/7j/teacher_avail_report.php">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
               placeholder="ค้นหาครู..." style="flex:1;min-width:160px;padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:.88rem;outline:none;">
        <!-- Filter วัน -->
        <select name="day" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:.85rem;outline:none;">
            <option value="">ทุกวัน</option>
            <?php foreach ($DAYS_ORDER as $d): ?>
            <option value="<?= $d ?>" <?= $selDay===$d?'selected':'' ?>><?= $DAYS_TH[$d] ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" style="padding:7px 16px;background:linear-gradient(135deg,#92400e,#d97706);color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">ค้นหา</button>
        <?php if ($search || $selDay): ?>
        <a href="?q=/modules/7j/teacher_avail_report.php" style="padding:7px 14px;background:#e5e7eb;color:#374151;border-radius:8px;font-size:.85rem;font-weight:600;text-decoration:none;">✕ ล้าง</a>
        <?php endif; ?>
    </form>
</div>

<!-- Summary stats -->
<?php
$totalAvail = count($availRows);
$totalBooked = 0; $totalExpired = 0;
foreach ($availRows as $av) {
    $tid      = $av['teacher_id'];
    $avDate   = $av['type']==='specific_date' ? ($av['specific_date'] ?? '') : '';
    $avDay    = $av['type']==='specific_date' ? '__date__'.$av['specific_date'] : ($av['day'] ?? '');
    // expired check
    $expDate  = $avDate ?: (($av['day'] ?? '') === $TODAY_DOW ? $TODAY_STR : '9999-12-31');
    if (tarIsExpired($expDate, $av['end_time'], $TODAY_STR, $NOW_MINS)) {
        $totalExpired++; continue;
    }
    $dayScheds = $schedByTeacherDay[$tid][$avDay] ?? [];
    if (!empty(isBooked($av['start_time'], $av['end_time'], $dayScheds))) $totalBooked++;
}
$totalFree = $totalAvail - $totalBooked - $totalExpired;
?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:1.25rem;">
    <?php foreach ([
        ['📋','ทั้งหมด',     $totalAvail,   '#6b7280','#f3f4f6'],
        ['🟢','ว่าง',        $totalFree,    '#166534','#dcfce7'],
        ['🔴','มีนักเรียน',  $totalBooked,  '#991b1b','#fee2e2'],
        ['⛔','หมดเวลา',     $totalExpired, '#6b7280','#f3f4f6'],
    ] as [$ic,$lb,$vl,$fg,$bg]): ?>
    <div style="background:<?= $bg ?>;border-radius:10px;padding:.75rem 1rem;text-align:center;">
        <div style="font-size:1.6rem;font-weight:800;color:<?= $fg ?>;"><?= $vl ?></div>
        <div style="font-size:.72rem;color:#6b7280;"><?= $ic ?> <?= $lb ?></div>
    </div>
    <?php endforeach; ?>
</div>

<?php if (empty($filteredTeachers)): ?>
<div style="text-align:center;padding:2rem;color:#9ca3af;">ไม่พบครูที่ค้นหา</div>
<?php endif; ?>

<?php foreach ($filteredTeachers as $teacher):
    $tid     = $teacher['id'];
    $avSlots = $availByTeacher[$tid] ?? [];
    if (empty($avSlots)) continue; // ข้ามครูที่ไม่มีช่วงเวลาว่าง

    // จัด avSlots ตามวัน
    $avByDay = [];
    foreach ($DAYS_ORDER as $d) { $avByDay[$d] = []; }
    $avByDay['__specific'] = [];
    foreach ($avSlots as $av) {
        if ($av['type'] === 'specific_date') {
            $avByDay['__specific'][] = $av;
        } else {
            $day = ucfirst(strtolower($av['day'] ?? ''));
            if (isset($avByDay[$day])) $avByDay[$day][] = $av;
        }
    }

    // นับ booked/free ของครูนี้ (ไม่นับ expired)
    $tBooked = 0; $tFree = 0; $tTotal = count($avSlots);
    foreach ($avSlots as $av) {
        $avDate  = $av['type']==='specific_date' ? ($av['specific_date'] ?? '') : '';
        $expDate = $avDate ?: (ucfirst(strtolower($av['day']??'')) === $TODAY_DOW ? $TODAY_STR : '9999-12-31');
        if (tarIsExpired($expDate, $av['end_time'], $TODAY_STR, $NOW_MINS)) continue;
        $day = $av['type']==='specific_date' ? '__date__'.$av['specific_date'] : ucfirst(strtolower($av['day']??''));
        $dayScheds = $schedByTeacherDay[$tid][$day] ?? [];
        if (!empty(isBooked($av['start_time'], $av['end_time'], $dayScheds))) $tBooked++;
        else $tFree++;
    }
?>
<div class="tar-card">
    <!-- Teacher Header -->
    <div class="tar-teacher-hdr">
        <div style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0;">
            <?= mb_strtoupper(mb_substr($teacher['displayName'],0,1)) ?>
        </div>
        <div style="flex:1;">
            <div style="font-weight:700;font-size:.95rem;"><?= htmlspecialchars($teacher['displayName']) ?>
                <span style="font-size:.75rem;opacity:.8;"><?= htmlspecialchars($teacher['teacherCode']) ?></span>
            </div>
        </div>
        <div style="display:flex;gap:8px;font-size:.78rem;">
            <span style="background:#dcfce7;color:#166534;border-radius:20px;padding:2px 10px;font-weight:700;">🟢 ว่าง <?= $tFree ?></span>
            <span style="background:#fee2e2;color:#991b1b;border-radius:20px;padding:2px 10px;font-weight:700;">🔴 จอง <?= $tBooked ?></span>
        </div>
    </div>

    <!-- วัน อาทิตย์–เสาร์ -->
    <?php foreach ($DAYS_ORDER as $dayEn):
        if ($selDay && $selDay !== $dayEn) continue;
        $slots = $avByDay[$dayEn] ?? [];
        if (empty($slots)) continue;
        $isToday = ($dayEn === $TODAY_DOW);
    ?>
    <div class="tar-day-section">
        <div class="tar-day-hdr" style="<?= $isToday?'background:#fef9c3;color:#713f12;border-color:#fde68a;':'' ?>">
            <?= $isToday ? '📍' : '📅' ?>
            <?= $DAYS_TH[$dayEn] ?>
            <?php if ($isToday): ?><span style="font-size:.68rem;opacity:.8;">(วันนี้)</span><?php endif; ?>
            <span style="font-size:.7rem;color:#9ca3af;margin-left:auto;"><?= count($slots) ?> ช่วง</span>
        </div>
        <?php foreach ($slots as $av):
            $dayKey    = $dayEn;
            $dayScheds = $schedByTeacherDay[$tid][$dayKey] ?? [];
            $bookedScs = isBooked($av['start_time'], $av['end_time'], $dayScheds);
            $isBusy    = !empty($bookedScs);
            // ตรวจว่าหมดเวลาแล้ว (เฉพาะวันนี้)
            $slotDateForDay = ($dayEn === $TODAY_DOW) ? $TODAY_STR : '9999-12-31';
            $isExpired = tarIsExpired($slotDateForDay, $av['end_time'], $TODAY_STR, $NOW_MINS);
        ?>
        <div class="tar-slot" style="<?= $isExpired ? 'opacity:.5;background:#f3f4f6;' : '' ?>">
            <!-- เวลา -->
            <span style="font-family:monospace;font-size:.83rem;min-width:140px;font-weight:600;
                         <?= $isExpired ? 'color:#9ca3af;text-decoration:line-through;' : 'color:#374151;' ?>">
                ⏰ <?= tarFmt($av['start_time']) ?> – <?= tarFmt($av['end_time']) ?>
            </span>
            <!-- สถานะ -->
            <?php if ($isExpired): ?>
            <span class="tar-badge" style="background:#e5e7eb;color:#6b7280;">⛔ หมดเวลา</span>
            <?php elseif ($isBusy): ?>
            <span class="tar-badge tar-busy">🔴 มีนักเรียน</span>
            <?php foreach ($bookedScs as $bsc): ?>
            <span class="tar-stu">
                👤 <?= htmlspecialchars($bsc['student_display']) ?>
                <?php if ($bsc['course']): ?><span style="color:#6b7280;">📚 <?= htmlspecialchars($bsc['course']) ?></span><?php endif; ?>
                <span style="color:#9ca3af;">(<?= (int)$bsc['completed_classes'] ?>/<?= (int)$bsc['total_classes'] ?>)</span>
            </span>
            <?php endforeach; ?>
            <?php else: ?>
            <span class="tar-badge tar-free">🟢 ว่าง</span>
            <?php
            $bData = json_encode([
                'teacher_id'   => $tid,
                'teacher_name' => $teacher['displayName'],
                'stype'        => 'weekly',
                'day'          => $dayEn,
                'date'         => '',
                'time_start'   => $av['start_time'],
                'time_end'     => $av['end_time'],
            ], JSON_HEX_APOS|JSON_HEX_QUOT);
            ?>
            <button onclick='openBookModal(<?= $bData ?>)'
                style="margin-left:auto;padding:3px 12px;background:#1A2A5E;color:#fff;border:none;border-radius:6px;font-size:.75rem;font-weight:700;cursor:pointer;">
                📅 จอง
            </button>
            <?php endif; ?>
            <!-- หมายเหตุ -->
            <?php if ($av['note']): ?>
            <span style="font-size:.72rem;color:#9ca3af;">📝 <?= htmlspecialchars($av['note']) ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <!-- specific_date slots -->
    <?php if (!empty($avByDay['__specific']) && !$selDay): ?>
    <div class="tar-day-section">
        <div class="tar-day-hdr" style="background:#f3f4f6;color:#6b7280;">
            📆 วันที่เฉพาะ
            <span style="font-size:.7rem;margin-left:auto;"><?= count($avByDay['__specific']) ?> ช่วง</span>
        </div>
        <?php foreach ($avByDay['__specific'] as $av):
            $dayKey    = '__date__'.$av['specific_date'];
            $dayScheds = $schedByTeacherDay[$tid][$dayKey] ?? [];
            $bookedScs = isBooked($av['start_time'], $av['end_time'], $dayScheds);
            $isBusy    = !empty($bookedScs);
            $isExpired = tarIsExpired($av['specific_date'] ?? '', $av['end_time'], $TODAY_STR, $NOW_MINS);
        ?>
        <div class="tar-slot" style="<?= $isExpired ? 'opacity:.5;background:#f3f4f6;' : '' ?>">
            <span style="font-size:.78rem;border-radius:6px;padding:1px 7px;
                         <?= $isExpired ? 'background:#e5e7eb;color:#9ca3af;text-decoration:line-through;' : 'background:#f3f4f6;color:#374151;' ?>">
                <?= fmtDate($av['specific_date']) ?>
            </span>
            <span style="font-family:monospace;font-size:.83rem;font-weight:600;
                         <?= $isExpired ? 'color:#9ca3af;text-decoration:line-through;' : 'color:#374151;' ?>">
                ⏰ <?= tarFmt($av['start_time']) ?> – <?= tarFmt($av['end_time']) ?>
            </span>
            <?php if ($isExpired): ?>
            <span class="tar-badge" style="background:#e5e7eb;color:#6b7280;">⛔ หมดเวลา</span>
            <?php elseif ($isBusy): ?>
            <span class="tar-badge tar-busy">🔴 มีนักเรียน</span>
            <?php foreach ($bookedScs as $bsc): ?>
            <span class="tar-stu">👤 <?= htmlspecialchars($bsc['student_display']) ?><?php if($bsc['course']): ?> 📚<?= htmlspecialchars($bsc['course']) ?><?php endif; ?></span>
            <?php endforeach; ?>
            <?php else: ?>
            <span class="tar-badge tar-free">🟢 ว่าง</span>
            <?php
            $bDataS = json_encode([
                'teacher_id'   => $tid,
                'teacher_name' => $teacher['displayName'],
                'stype'        => 'one_time',
                'day'          => '',
                'date'         => $av['specific_date'],
                'time_start'   => $av['start_time'],
                'time_end'     => $av['end_time'],
            ], JSON_HEX_APOS|JSON_HEX_QUOT);
            ?>
            <button onclick='openBookModal(<?= $bDataS ?>)'
                style="margin-left:auto;padding:3px 12px;background:#1A2A5E;color:#fff;border:none;border-radius:6px;font-size:.75rem;font-weight:700;cursor:pointer;">
                📅 จอง
            </button>
            <?php endif; ?>
            <?php if ($av['note']): ?><span style="font-size:.72rem;color:#9ca3af;">📝 <?= htmlspecialchars($av['note']) ?></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ถ้าทุกวันถูก filter ออกหมด -->
    <?php
    $hasAnyShown = false;
    foreach ($DAYS_ORDER as $d) {
        if ($selDay && $selDay !== $d) continue;
        if (!empty($avByDay[$d])) { $hasAnyShown = true; break; }
    }
    if (!$hasAnyShown && !(!$selDay && !empty($avByDay['__specific']))): ?>
    <div class="tar-empty">ไม่มีช่วงเวลาว่างในวันที่เลือก</div>
    <?php endif; ?>

</div><!-- end tar-card -->
<?php endforeach; ?>

<?php
// ครูที่ไม่มีช่วงเวลาว่างเลย
$noAvailTeachers = array_filter($filteredTeachers, fn($t) => empty($availByTeacher[$t['id']]));
if (!empty($noAvailTeachers)): ?>
<div style="background:#fff;border-radius:12px;padding:1rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.06);border:1px solid #f3f4f6;">
    <div style="font-size:.82rem;font-weight:700;color:#9ca3af;margin-bottom:8px;">ครูที่ยังไม่ได้ตั้งเวลาว่าง</div>
    <div style="display:flex;flex-wrap:wrap;gap:6px;">
    <?php foreach ($noAvailTeachers as $t): ?>
    <span style="background:#f3f4f6;color:#6b7280;border-radius:20px;padding:3px 12px;font-size:.78rem;">
        <?= htmlspecialchars($t['displayName']) ?> (<?= htmlspecialchars($t['teacherCode']) ?>)
    </span>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

</div>

<!-- ══ Booking Modal ══════════════════════════════════════════════════════════ -->
<div id="book-modal-bg" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;overflow-y:auto;padding:20px;">
<div style="background:#fff;border-radius:14px;max-width:480px;margin:40px auto;padding:0;box-shadow:0 8px 32px rgba(0,0,0,.18);">
    <div style="background:linear-gradient(135deg,#1A2A5E,#2563eb);padding:14px 20px;border-radius:14px 14px 0 0;display:flex;align-items:center;justify-content:space-between;">
        <div style="color:#fff;font-weight:700;font-size:1rem;">📅 จองช่วงเวลาเรียน</div>
        <button onclick="closeBookModal()" style="background:none;border:none;color:#fff;font-size:1.2rem;cursor:pointer;opacity:.7;">✕</button>
    </div>
    <?php if ($bookMsg): [$bType,$bText] = explode('|',$bookMsg,2); ?>
    <div style="margin:12px 20px 0;padding:9px 14px;border-radius:8px;font-size:.85rem;font-weight:600;
        background:<?= $bType==='success'?'#dcfce7':'#fee2e2' ?>;color:<?= $bType==='success'?'#166534':'#991b1b' ?>;">
        <?= htmlspecialchars($bText) ?>
    </div>
    <?php endif; ?>
    <form method="POST" style="padding:18px 20px;">
        <input type="hidden" name="q"              value="/modules/7j/teacher_avail_report.php">
        <input type="hidden" name="_action"        value="book_slot">
        <input type="hidden" name="b_teacher_id"   id="b-tid">
        <input type="hidden" name="b_teacher_name" id="b-tname">
        <input type="hidden" name="b_stype"        id="b-stype">
        <input type="hidden" name="b_day"          id="b-day">
        <input type="hidden" name="b_date"         id="b-date">
        <input type="hidden" name="b_time_start"   id="b-tstart">
        <input type="hidden" name="b_time_end"     id="b-tend">
        <input type="hidden" name="b_student_id"   id="b-sid">
        <input type="hidden" name="b_student_code" id="b-scode">
        <input type="hidden" name="b_student_name" id="b-sname">
        <!-- Info preview -->
        <div id="b-info-box" style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.82rem;color:#0369a1;"></div>
        <!-- นักเรียน -->
        <div style="margin-bottom:12px;position:relative;">
            <label style="display:block;font-size:.8rem;font-weight:700;color:#374151;margin-bottom:4px;">นักเรียน *</label>
            <input type="text" id="b-stu-search" placeholder="พิมพ์ชื่อหรือรหัส..." autocomplete="off"
                oninput="bStuSearch(this.value)"
                style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:.88rem;box-sizing:border-box;outline:none;">
            <div id="b-stu-dd" style="display:none;position:absolute;background:#fff;border:1px solid #e5e7eb;border-radius:8px;max-height:160px;overflow-y:auto;z-index:10;box-shadow:0 4px 12px rgba(0,0,0,.1);width:100%;top:100%;left:0;"></div>
            <div id="b-stu-selected" style="display:none;margin-top:6px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:6px 10px;font-size:.82rem;color:#166534;"></div>
        </div>
        <!-- คอร์ส + คาบ -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;">
            <div>
                <label style="display:block;font-size:.8rem;font-weight:700;color:#374151;margin-bottom:4px;">คอร์ส</label>
                <input type="text" name="b_course" placeholder="เช่น Basic, IELTS"
                    style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:.85rem;box-sizing:border-box;outline:none;">
            </div>
            <div>
                <label style="display:block;font-size:.8rem;font-weight:700;color:#374151;margin-bottom:4px;">คาบทั้งหมด *</label>
                <input type="number" name="b_total_classes" value="20" min="1" required
                    style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:.85rem;box-sizing:border-box;outline:none;">
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;">
            <button type="button" onclick="closeBookModal()"
                style="padding:8px 18px;background:#e5e7eb;color:#374151;border:none;border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;">ยกเลิก</button>
            <button type="submit"
                style="padding:8px 20px;background:linear-gradient(135deg,#ea580c,#d97706);color:#fff;border:none;border-radius:8px;font-size:.88rem;font-weight:700;cursor:pointer;">
                ✅ จองเวลา
            </button>
        </div>
    </form>
</div>
</div>

<?php
$studentsJS = [];
foreach ($allStudents as $st) {
    $studentsJS[] = ['id'=>$st['id'],'code'=>$st['studentCode'],'name'=>$st['displayName'],'nick'=>$st['nickname']??''];
}
$DAYS_TH_JS_MAP = ['Sunday'=>'อาทิตย์','Monday'=>'จันทร์','Tuesday'=>'อังคาร','Wednesday'=>'พุธ','Thursday'=>'พฤหัสบดี','Friday'=>'ศุกร์','Saturday'=>'เสาร์'];
?>
<script>
var _bStudents = <?= json_encode($studentsJS) ?>;
var _bDaysTh   = <?= json_encode($DAYS_TH_JS_MAP) ?>;

function openBookModal(d) {
    document.getElementById('b-tid').value    = d.teacher_id   || '';
    document.getElementById('b-tname').value  = d.teacher_name || '';
    document.getElementById('b-stype').value  = d.stype        || 'one_time';
    document.getElementById('b-day').value    = d.day          || '';
    document.getElementById('b-date').value   = d.date         || '';
    document.getElementById('b-tstart').value = d.time_start   || '';
    document.getElementById('b-tend').value   = d.time_end     || '';
    var dayLabel = d.stype === 'weekly' ? '🗓 ทุกวัน' + (_bDaysTh[d.day]||d.day) : '📅 ' + (d.date||'');
    document.getElementById('b-info-box').innerHTML =
        '<strong>👨‍🏫 '+(d.teacher_name||'')+'</strong> &nbsp;|&nbsp; '+dayLabel+
        ' &nbsp;|&nbsp; ⏰ <strong>'+bFmt12(d.time_start||'')+' – '+bFmt12(d.time_end||'')+'</strong>';
    document.getElementById('b-stu-search').value = '';
    document.getElementById('b-stu-dd').style.display = 'none';
    document.getElementById('b-stu-selected').style.display = 'none';
    document.getElementById('b-sid').value = '';
    document.getElementById('b-scode').value = '';
    document.getElementById('b-sname').value = '';
    document.getElementById('book-modal-bg').style.display = 'block';
}
function closeBookModal() { document.getElementById('book-modal-bg').style.display='none'; }
function bFmt12(t) {
    if (!t) return '';
    var p=t.split(':'),h=parseInt(p[0]),m=p[1]||'00',s=h>=12?'PM':'AM';
    return (h%12||12)+':'+m+' '+s;
}
var _bStuTimer=null;
function bStuSearch(val) {
    var dd=document.getElementById('b-stu-dd');
    clearTimeout(_bStuTimer);
    val=val.trim();
    if(!val){dd.style.display='none';return;}
    _bStuTimer=setTimeout(function(){
        var q=val.toLowerCase();
        var res=_bStudents.filter(function(s){
            return (s.name&&s.name.toLowerCase().indexOf(q)>=0)||(s.code&&s.code.toLowerCase().indexOf(q)>=0)||(s.nick&&s.nick.toLowerCase().indexOf(q)>=0);
        }).slice(0,8);
        dd.innerHTML='';
        if(!res.length){dd.style.display='none';return;}
        res.forEach(function(s){
            var item=document.createElement('div');
            item.style.cssText='padding:8px 12px;cursor:pointer;font-size:.85rem;border-bottom:1px solid #f3f4f6;';
            item.innerHTML='<strong>'+s.name+'</strong> <span style="color:#9ca3af;font-size:.78rem;">'+s.code+'</span>';
            item.onmouseover=function(){this.style.background='#f0fdf4';};
            item.onmouseout=function(){this.style.background='#fff';};
            item.onclick=function(){
                document.getElementById('b-sid').value=s.id;
                document.getElementById('b-scode').value=s.code;
                document.getElementById('b-sname').value=s.name;
                document.getElementById('b-stu-search').value=s.name+' ('+s.code+')';
                document.getElementById('b-stu-selected').textContent='✅ '+s.name+' · '+s.code;
                document.getElementById('b-stu-selected').style.display='block';
                dd.style.display='none';
            };
            dd.appendChild(item);
        });
        dd.style.display='block';
    },200);
}
document.getElementById('book-modal-bg').addEventListener('click',function(e){if(e.target===this)closeBookModal();});
</script>
