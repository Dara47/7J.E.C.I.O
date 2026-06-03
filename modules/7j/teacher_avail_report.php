<?php
/*
 * 7J English Center — Teacher Availability Report
 * แสดงตารางว่างครูรายสัปดาห์ + สถานะจอง/ว่าง
 */

$search = trim($_GET['search'] ?? '');
$selDay = trim($_GET['day']    ?? '');

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
        if ($nowMins >= 720 && (int)$eh < 12) $endMins += 720;
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
