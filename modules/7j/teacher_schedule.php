<?php
error_reporting(E_ALL); ini_set('display_errors', 1);
/*
 * 7J English Center — Teacher Schedule
 * Ported from TeacherSchedule.tsx
 * List + Calendar view, filter by teacher, complete class session
 */

// ─── POST handlers ────────────────────────────────────────────────────────────
$postAction = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'complete') {
    $id   = (int)($_POST['schedule_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    if ($id) {
        $connection2->prepare("UPDATE sevenj_schedule SET completed_classes = LEAST(completed_classes+1, total_classes), note=? WHERE id=?")
            ->execute([$note, $id]);
    }
    $teacher = urlencode($_POST['teacher_filter'] ?? '');
    $search  = urlencode($_POST['search_val'] ?? '');
    header("Location: /MyNewShool/?q=/modules/7j/teacher_schedule.php&teacher=$teacher&search=$search");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'delete') {
    $id = (int)($_POST['schedule_id'] ?? 0);
    if ($id) {
        $connection2->prepare("DELETE FROM sevenj_schedule WHERE id=?")->execute([$id]); // completions คงไว้ 30 วัน
    }
    $teacher = urlencode($_POST['teacher_filter'] ?? '');
    $search  = urlencode($_POST['search_val'] ?? '');
    header("Location: /MyNewShool/?q=/modules/7j/teacher_schedule.php&teacher=$teacher&search=$search");
    exit;
}

// ─── Constants — Sunday first ─────────────────────────────────────────────────
$DAYS_EN   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$DAYS_TH   = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
$DAY_ORDER = array_flip($DAYS_EN);
$dayThMap  = array_combine($DAYS_EN, $DAYS_TH);

// ─── คำนวณวันปัจจุบัน ─────────────────────────────────────────────────────────
$todayDow   = (int)date('w');
$weekSunday = strtotime("-{$todayDow} days");
$todayName  = date('l');
$todayDate  = date('Y-m-d');

$weekDates = [];
foreach ($DAYS_EN as $i => $d) {
    $weekDates[$d] = date('Y-m-d', strtotime("+{$i} days", $weekSunday));
}

// ─── Params ───────────────────────────────────────────────────────────────────
$view            = 'list';
$selectedTeacher = trim($_GET['teacher'] ?? '');
$search          = trim($_GET['search']  ?? '');

// ─── Query (prepared statements — ป้องกัน quote-bug) ────────────────────────
$params = [];
$whereParts = [];

if ($selectedTeacher) {
    $whereParts[] = "(s.teacher_name = ? OR t.displayName = ?)";
    $params[] = $selectedTeacher;
    $params[] = $selectedTeacher;
}
if ($search) {
    $like = '%' . $search . '%';
    $whereParts[] = "(s.student_name LIKE ? OR s.teacher_name LIKE ? OR s.student_code LIKE ? OR st.displayName LIKE ? OR t.displayName LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
$whereSQL = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$sql = "
    SELECT s.*,
        COALESCE(st.displayName, s.student_name) AS disp_student,
        COALESCE(st.studentCode, s.student_code) AS disp_code,
        COALESCE(t.displayName,  s.teacher_name) AS disp_teacher,
        t.teacherCode AS disp_teacher_code,
        t.location    AS teacher_location,
        (SELECT COUNT(*) FROM sevenj_class_completions c WHERE c.student_id = s.student_id) AS actual_completed
    FROM sevenj_schedule s
    LEFT JOIN sevenj_students st ON st.id = s.student_id
    LEFT JOIN sevenj_teachers  t ON  t.id = s.teacher_ref_id
    $whereSQL
    ORDER BY COALESCE(t.displayName, s.teacher_name), s.time_start
";
if ($params) {
    $stmt = $connection2->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $rows = $connection2->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// Dropdown ครู
$allTeachers = $connection2->query("
    SELECT DISTINCT COALESCE(t.displayName, s.teacher_name) AS teacher_name
    FROM sevenj_schedule s
    LEFT JOIN sevenj_teachers t ON t.id = s.teacher_ref_id
    WHERE (s.teacher_name IS NOT NULL AND s.teacher_name != '')
       OR t.displayName IS NOT NULL
    ORDER BY teacher_name
")->fetchAll(PDO::FETCH_COLUMN);

// ─── Group: teacher → student → [slots] ─────────────────────────────────────
// นักเรียนคนเดียวปรากฏครั้งเดียวต่อครู รวม progress ทุก slot
$byTeacher = [];
foreach ($rows as $r) {
    $t      = $r['disp_teacher'] ?: $r['teacher_name'];
    $dispS  = $r['disp_student'] ?: $r['student_name'];
    $dispC  = $r['disp_code']    ?: $r['student_code'];
    $stuKey = !empty($r['student_id']) ? $r['student_id'] : ($dispS.'|'.$dispC);

    if (!isset($byTeacher[$t])) $byTeacher[$t] = [];
    if (!isset($byTeacher[$t][$stuKey])) {
        $byTeacher[$t][$stuKey] = [
            'student_name'      => $r['student_name'],
            '_disp_student'     => $dispS,
            '_disp_code'        => $dispC,
            'student_id'        => $r['student_id'],
            'total_classes'     => 0,
            'completed_classes' => 0,
            'slots'             => [],
        ];
    }
    // total_classes = MAX (package size เหมือนกันทุก row ของ package เดียวกัน)
    $byTeacher[$t][$stuKey]['total_classes'] = max(
        $byTeacher[$t][$stuKey]['total_classes'],
        (int)$r['total_classes']
    );
    // completed_classes = COUNT จริงจาก sevenj_class_completions (ไม่ใช่ SUM slots)
    $byTeacher[$t][$stuKey]['completed_classes'] = (int)$r['actual_completed'];
    // เก็บ slot ย่อย
    $byTeacher[$t][$stuKey]['slots'][] = [
        'id'            => $r['id'],
        'type'          => $r['schedule_type'],
        'day'           => $r['day_of_week'] ?? '',
        'specific_date' => $r['specific_date'] ?? '',
        'time'          => $r['time_start'],
        'time_end'      => $r['time_end'] ?? '',
    ];
}
// แปลง associative → indexed per teacher
foreach ($byTeacher as &$tStudents) {
    $tStudents = array_values($tStudents);
}
unset($tStudents);
uasort($byTeacher, fn($a,$b) => count($b) - count($a));

// ระบบเก็บเวลาแบบ 12h ไม่มี AM/PM — hours 1-11 = PM, 0 = AM, 12 = noon PM
function fmtTimePM(string $t): string {
    if ($t === '') return '';
    [$h, $m] = array_pad(explode(':', $t), 2, '00');
    $h = (int)$h;
    if ($h === 0)  return '12:' . $m . ' AM';
    if ($h === 12) return '12:' . $m . ' PM';
    return $h . ':' . $m . ' PM';
}

// ─── Thailand time helper ─────────────────────────────────────────────────────
function isSlotReady(string $day, string $time, string $type='weekly', string $specificDate=''): bool {
    $thNow  = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
    $thHM   = (int)$thNow->format('H') * 60 + (int)$thNow->format('i');
    [$h,$m] = array_map('intval', explode(':', $time.':00'));
    $slotHM = $h * 60 + $m;
    if ($thHM >= 720 && $h < 12) $slotHM += 720;
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

// 'waiting' = รอเรียน | 'active' = กำลังเรียน | 'finished' = เรียนเสร็จแล้ว
function getSlotStatus(string $day, string $timeStart, string $timeEnd, string $type, string $specificDate, array $weekDates): string {
    $thNow    = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
    $todayStr = $thNow->format('Y-m-d');
    $slotDate = ($type === 'one_time' && $specificDate) ? $specificDate : ($weekDates[$day] ?? '');
    if (!$slotDate || $slotDate > $todayStr) return 'waiting';
    if ($slotDate < $todayStr) return 'finished';
    $nowMins = (int)$thNow->format('H') * 60 + (int)$thNow->format('i');
    [$sh, $sm] = array_map('intval', explode(':', $timeStart . ':00'));
    $startMins = $sh * 60 + $sm;
    // เวลาปัจจุบัน PM และ slot < 12 → ถือเป็น PM (+12 ชม.)
    if ($nowMins >= 720 && $sh < 12) $startMins += 720;
    if ($nowMins < $startMins) return 'waiting';
    if ($timeEnd) {
        [$eh, $em] = array_map('intval', explode(':', $timeEnd . ':00'));
        $endMins = $eh * 60 + $em;
        if ($nowMins >= 720 && $eh < 12) $endMins += 720;
        if ($nowMins <= $endMins) return 'active';
    }
    return 'finished';
}

// ─── Stats ────────────────────────────────────────────────────────────────────
$totalTeachers = count($byTeacher);
$totalStudents = count(array_unique(array_column($rows, 'student_name')));
$totalSlots    = count($rows);
$totalDone     = array_sum(array_column($rows, 'completed_classes'));
?>

<?php require_once __DIR__.'/_theme.php'; ?>
<style>
.ts-card{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:10px;overflow:hidden;}
.ts-head{background:linear-gradient(135deg,#ea580c,#ea580c);padding:11px 16px;display:flex;justify-content:space-between;align-items:center;cursor:pointer;user-select:none;}
.ts-slot{border:1px solid #fff7ed;border-radius:8px;padding:8px 12px;margin:6px 0;background:#faf5ff;}
.ts-chip{display:inline-flex;align-items:center;gap:5px;background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:3px 10px 3px 6px;font-size:.75rem;margin:2px;}
.ts-badge{background:#fff7ed;color:#9a3412;border-radius:99px;padding:1px 8px;font-size:.7rem;font-weight:700;}
.ts-btn-complete{background:#059669;color:#fff;border:none;border-radius:6px;padding:2px 9px;font-size:.65rem;font-weight:700;cursor:pointer;}
.ts-btn{padding:6px 16px;border-radius:7px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-block;}
.ts-btn-active{background:#ea580c;color:#fff;}
.ts-btn-inactive{background:#e5e7eb;color:#374151;}
.ts-btn-inactive:hover{background:#d1d5db;}
.ts-search{padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:.88rem;outline:none;}
.ts-search:focus{border-color:#ea580c;box-shadow:0 0 0 2px #fff7ed;}
.cal-cell{border:1px solid #e5e7eb;border-radius:7px;padding:5px;min-height:48px;vertical-align:top;}
.cal-chip{background:#fff7ed;border-radius:6px;padding:3px 6px;margin:2px 0;color:#9a3412;font-size:.7rem;}
.cal-chip.ready{background:#dcfce7;color:#166534;}
/* Modal */
.ts-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center;}
.ts-modal-bg.open{display:flex;}
.ts-modal{background:#fff;border-radius:14px;width:100%;max-width:420px;padding:1.5rem;box-shadow:0 20px 60px rgba(0,0,0,.25);}
</style>

<div style="max-width:100%;padding-bottom:2rem;">

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:1rem;">
    <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0;">👨‍🏫 Teacher Schedule</h2>
    <div style="display:flex;gap:8px;">
        <a href="?q=/modules/7j/manage_schedule.php" class="ts-btn" style="background:#ea580c;color:#fff;">⚙️ จัดการ</a>
    </div>
</div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:1.25rem;">
<?php foreach ([
    ['👨‍🏫','ครูทั้งหมด',$totalTeachers,'#ea580c'],
    ['🎓','นักเรียน',$totalStudents,'#2563eb'],
    ['🗓','ตารางสอน',$totalSlots,'#059669'],
    ['✅','คาบที่สอนแล้ว',$totalDone,'#d97706'],
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
    <input type="hidden" name="q" value="/modules/7j/teacher_schedule.php">
    <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="ts-search" placeholder="ค้นหาครู / นักเรียน..." style="flex:1;min-width:160px;">
    <select name="teacher" class="ts-search">
        <option value="">ครูทั้งหมด</option>
        <?php foreach ($allTeachers as $tn): ?>
        <option value="<?= htmlspecialchars($tn) ?>" <?= $selectedTeacher===$tn?'selected':'' ?>><?= htmlspecialchars($tn) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="ts-btn ts-btn-active">ค้นหา</button>
    <?php if ($search || $selectedTeacher): ?>
    <a href="?q=/modules/7j/teacher_schedule.php&view=<?= $view ?>" class="ts-btn ts-btn-inactive">✕ ล้าง</a>
    <?php endif; ?>
</form>

<?php if (empty($byTeacher)): ?>
<div style="text-align:center;padding:3rem 1rem;color:#9ca3af;">
    <div style="font-size:2.5rem;margin-bottom:.5rem;">📭</div>
    ไม่พบตารางสอน<?= $selectedTeacher?' ของ '.htmlspecialchars($selectedTeacher):'' ?>
    <br><a href="?q=/modules/7j/manage_schedule.php" style="color:#ea580c;font-size:.9rem;">+ เพิ่มตารางเรียน</a>
</div>

<?php elseif ($view === 'list'): ?>
<!-- ════ LIST VIEW ════ -->
<?php foreach ($byTeacher as $tName => $tStudents):
    $cardId = 'tc_'.md5($tName);
    $slotStatusLabels = ['waiting'=>'รอเรียน','active'=>'กำลังเรียน','finished'=>'เรียนเสร็จแล้ว'];
    $slotStatusStyles = [
        'waiting'  => 'background:#fef3c7;color:#92400e;',
        'active'   => 'background:#dbeafe;color:#1e40af;',
        'finished' => 'background:#dcfce7;color:#166534;',
    ];
?>
<div class="ts-card">
    <div class="ts-head" onclick="toggleCard('<?= $cardId ?>')">
        <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;flex-shrink:0;">
                <?= htmlspecialchars(mb_strtoupper(mb_substr($tName,0,2))) ?>
            </div>
            <div>
                <div style="color:#fff;font-weight:700;font-size:.95rem;"><?= htmlspecialchars($tName) ?></div>
                <div style="color:rgba(255,255,255,.7);font-size:.72rem;">
                    <?= array_sum(array_column($tStudents,'completed_classes')) ?> slot · <?= count($tStudents) ?> นักเรียน
                </div>
            </div>
        </div>
        <span id="chev-<?= $cardId ?>" style="color:#fff;font-size:1rem;transition:transform .2s;display:inline-block;">▼</span>
    </div>
    <div id="<?= $cardId ?>" style="padding:10px 14px;">
        <?php foreach ($tStudents as $s):
            $done = (int)$s['completed_classes'] >= (int)$s['total_classes'];
            // เรียงลำดับ slots ตามเวลา
            usort($s['slots'], fn($a,$b) => strcmp($a['time'], $b['time']));
        ?>
        <div class="ts-slot">
            <!-- นักเรียน + progress รวม -->
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;flex-wrap:wrap;">
                <div style="width:22px;height:22px;border-radius:50%;background:#ea580c;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:700;flex-shrink:0;">
                    <?= mb_strtoupper(mb_substr($s['student_name'],0,1)) ?>
                </div>
                <span style="font-weight:700;color:#374151;font-size:.88rem;"><?= htmlspecialchars($s['_disp_student']) ?></span>
                <span class="ts-badge"><?= htmlspecialchars($s['_disp_code']) ?></span>
                <span style="color:<?= $done?'#059669':'#6b7280' ?>;font-size:.72rem;font-weight:700;">
                    <?= (int)$s['completed_classes'] ?>/<?= (int)$s['total_classes'] ?>
                    <?php if ($done): ?><span style="color:#059669;">✓ ครบแล้ว</span><?php endif; ?>
                </span>
            </div>
            <!-- slots ย่อย พร้อมสถานะแต่ละ slot -->
            <?php foreach ($s['slots'] as $slotIdx => $slot):
                $slotStatus = getSlotStatus($slot['day'], $slot['time'], $slot['time_end'], $slot['type'], $slot['specific_date'], $weekDates);
                $dayLabel   = $slot['type']==='one_time'
                    ? '📅 '.$slot['specific_date']
                    : '🗓 '.($dayThMap[$slot['day']] ?? $slot['day']);
                $slotNo     = $slotIdx + 1;
            ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 8px;background:rgba(255,255,255,.6);border-radius:6px;margin-bottom:3px;flex-wrap:wrap;gap:4px;">
                <div style="font-size:.75rem;color:#374151;display:flex;align-items:center;gap:6px;">
                    <span style="width:18px;height:18px;border-radius:50%;background:#ea580c;color:#fff;font-size:.65rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;"><?= $slotNo ?></span>
                    <span><?= $dayLabel ?></span>
                    <span style="color:#ea580c;">🕐 <?= fmtTimePM($slot['time']) ?><?= $slot['time_end']?' – '.fmtTimePM($slot['time_end']):'' ?></span>
                    <?php if ($slot['type']==='one_time'): ?>
                    <span style="background:#fef3c7;color:#92400e;font-size:.62rem;padding:0 5px;border-radius:20px;">ครั้งเดียว</span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <span style="<?= $slotStatusStyles[$slotStatus] ?>font-size:.65rem;padding:2px 8px;border-radius:20px;font-weight:700;">
                        <?= $slotStatus==='active'?'🟢 ':($slotStatus==='finished'?'✅ ':'⏳ ') ?><?= $slotStatusLabels[$slotStatus] ?>
                    </span>
                    <button onclick="confirmDeleteSchedule(<?= (int)$slot['id'] ?>,'<?= htmlspecialchars($s['_disp_student']) ?>','<?= htmlspecialchars($selectedTeacher) ?>','<?= htmlspecialchars($search) ?>')"
                        style="background:none;border:none;cursor:pointer;color:#dc2626;font-size:.75rem;padding:1px 3px;line-height:1;" title="ลบ">🗑</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>
<?php if (false): // calendar content removed ?>

<!-- Calendar: header + body ในกล่อง scroll เดียวกัน เพื่อให้คอลัมน์ตรงกัน -->
<div style="overflow-x:auto;">
<div style="min-width:600px;">

<!-- Day headers row -->
<div style="display:flex;gap:0;margin-bottom:2px;">
    <!-- Time axis spacer -->
    <div style="width:52px;flex-shrink:0;"></div>
    <?php foreach ($DAYS_EN as $i => $d):
        $isToday  = ($d === $todayName);
        $hasEvent = false;
        foreach ($calGrid as $k => $v) { if (str_starts_with($k, "$d-")) { $hasEvent = true; break; } }
    ?>
    <div style="flex:1;text-align:center;padding:6px 2px;
                border:1px solid <?= $isToday ? '#d97706' : '#e5e7eb' ?>;
                border-bottom:none;
                border-radius:6px 6px 0 0;
                background:<?= $isToday ? '#d97706' : ($hasEvent ? '#fff7ed' : '#f9fafb') ?>;
                margin:0 1px;
                cursor:default;">
        <div style="font-size:.72rem;font-weight:700;color:<?= $isToday ? '#fff' : '#374151' ?>;">
            <?= $DAYS_TH[$i] ?>
        </div>
        <div style="font-size:1.1rem;font-weight:800;line-height:1.1;color:<?= $isToday ? '#fff' : '#1f2937' ?>;">
            <?= ltrim(explode('/', $weekDatesDisplay[$d] ?? '')[0], '0') ?>
        </div>
        <div style="font-size:.62rem;color:<?= $isToday ? 'rgba(255,255,255,.8)' : '#9ca3af' ?>;">
            <?= explode('/', $weekDatesDisplay[$d] ?? '/')[1] ?? '' ?> · <?= substr($d, 0, 3) ?>
        </div>
        <?php if ($isToday): ?>
        <div style="width:6px;height:6px;border-radius:50%;background:#fff;margin:2px auto 0;opacity:.9;"></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Calendar grid body -->
<div style="display:flex;gap:0;">

    <!-- ── Time axis ── -->
    <div style="width:52px;flex-shrink:0;position:relative;height:<?= $calTotalHeight ?>px;">
        <?php foreach ($calHours as $hr):
            $py = calToPx($hr, $CAL_START, $CAL_PX_HOUR);
        ?>
        <div style="position:absolute;top:<?= $py ?>px;left:0;right:4px;text-align:right;">
            <span style="font-size:.68rem;color:#9ca3af;font-weight:500;line-height:1;"><?= $hr ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Day columns ── -->
    <?php foreach ($DAYS_EN as $di => $d):
        $isToday = ($d === $todayName);
    ?>
    <div style="flex:1;position:relative;height:<?= $calTotalHeight ?>px;
                border:1px solid <?= $isToday ? '#d97706' : '#e5e7eb' ?>;
                border-top:none;border-radius:0 0 6px 6px;
                background:<?= $isToday ? '#fffbeb' : '#fff' ?>;
                margin:0 1px;overflow:hidden;">

        <!-- Hour grid lines -->
        <?php foreach ($calHours as $hr):
            $py = calToPx($hr, $CAL_START, $CAL_PX_HOUR);
        ?>
        <div style="position:absolute;top:<?= $py ?>px;left:0;right:0;
                    border-top:1px solid <?= $hr === $CAL_START ? 'transparent' : '#f0f0f0' ?>;"></div>
        <?php endforeach; ?>

        <!-- Current time line -->
        <?php if ($isToday && $nowPx >= 0): ?>
        <div style="position:absolute;top:<?= $nowPx ?>px;left:0;right:0;z-index:10;pointer-events:none;">
            <div style="border-top:2px solid #3b82f6;position:relative;">
                <div style="position:absolute;left:-1px;top:-5px;width:8px;height:8px;border-radius:50%;background:#3b82f6;"></div>
                <div style="position:absolute;right:0;top:-4px;width:6px;height:6px;border-radius:50%;background:#3b82f6;"></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Events — คลิกเพื่อดูรายละเอียด -->
        <?php
        $statusCfg = [
            'waiting'  => ['bg'=>'#fff7ed','bd'=>'#fdba74','fg'=>'#9a3412','accent'=>'#ea580c','badge_bg'=>'#fef3c7','badge_fg'=>'#92400e','label'=>'รอเรียน'],
            'active'   => ['bg'=>'#eff6ff','bd'=>'#93c5fd','fg'=>'#1e40af','accent'=>'#3b82f6','badge_bg'=>'#dbeafe','badge_fg'=>'#1e40af','label'=>'กำลังเรียน'],
            'finished' => ['bg'=>'#f0fdf4','bd'=>'#86efac','fg'=>'#166534','accent'=>'#059669','badge_bg'=>'#dcfce7','badge_fg'=>'#166534','label'=>'เรียนเสร็จแล้ว'],
        ];
        foreach ($calGrid as $gKey => $cells):
            if (!str_starts_with($gKey, "$d-")) continue;
            foreach ($cells as $cell):
                $slot     = $cell['slot'];
                $tStart   = $slot['time'];
                $tEnd     = $slot['time_end'] ?? '';
                $topPx    = calToPx($tStart, $CAL_START, $CAL_PX_HOUR);
                $heightPx = max(54, calDuration($tStart, $tEnd, $CAL_PX_HOUR));
                $status   = getSlotStatus($d, $tStart, $tEnd, $slot['type'], $slot['specific_date'] ?? '', $weekDates);
                $sc       = $statusCfg[$status];
                $nStudents = count($slot['students']);

                // สร้าง JSON ข้อมูลสำหรับ popup
                $evStudents = [];
                foreach ($slot['students'] as $st) {
                    $evStudents[] = [
                        'id'        => (int)$st['id'],
                        'name'      => $st['_disp_student'] ?? $st['student_name'],
                        'code'      => $st['_disp_code'] ?? $st['student_code'],
                        'done'      => (int)$st['completed_classes'] >= (int)$st['total_classes'],
                        'completed' => (int)$st['completed_classes'],
                        'total'     => (int)$st['total_classes'],
                        'rawName'   => $st['student_name'],
                    ];
                }
                $evData = [
                    'teacher' => $cell['teacher'],
                    'tStart'  => $tStart,
                    'tEnd'    => $tEnd,
                    'status'  => $status,
                    'label'   => $sc['label'],
                    'badgeBg' => $sc['badge_bg'],
                    'badgeFg' => $sc['badge_fg'],
                    'accent'  => $sc['accent'],
                    'dayTh'   => $dayThMap[$d] ?? $d,
                    'date'    => $weekDates[$d] ?? '',
                    'students'=> $evStudents,
                    'view'    => $view,
                    'tf'      => $selectedTeacher,
                    'srch'    => $search,
                ];
                $evJson = htmlspecialchars(json_encode($evData, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS), ENT_QUOTES);
        ?>
        <div data-ev="<?= $evJson ?>"
             onclick="openEventDetail(this)"
             style="position:absolute;top:<?= $topPx + 1 ?>px;left:2px;right:2px;
                    min-height:<?= $heightPx - 2 ?>px;
                    background:<?= $sc['bg'] ?>;border:1px solid <?= $sc['bd'] ?>;
                    border-left:3px solid <?= $sc['accent'] ?>;
                    border-radius:4px;overflow:hidden;padding:4px 5px;
                    font-size:.68rem;z-index:5;box-sizing:border-box;cursor:pointer;
                    transition:box-shadow .15s;">
            <!-- เวลา + สถานะ -->
            <div style="display:flex;align-items:center;justify-content:space-between;gap:2px;margin-bottom:2px;">
                <span style="color:<?= $sc['fg'] ?>;font-weight:700;font-size:.63rem;white-space:nowrap;">
                    <?= htmlspecialchars($tStart) ?><?= $tEnd ? '–'.htmlspecialchars($tEnd) : '' ?>
                </span>
                <span style="background:<?= $sc['badge_bg'] ?>;color:<?= $sc['badge_fg'] ?>;
                             font-size:.52rem;font-weight:700;padding:1px 5px;border-radius:20px;
                             white-space:nowrap;flex-shrink:0;line-height:1.4;">
                    <?= $sc['label'] ?>
                </span>
            </div>
            <!-- ครู -->
            <div style="display:flex;align-items:center;gap:3px;margin-bottom:3px;">
                <span style="font-size:.6rem;flex-shrink:0;">👨‍🏫</span>
                <span style="font-weight:700;color:#1f2937;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.67rem;">
                    <?= htmlspecialchars($cell['teacher']) ?>
                </span>
            </div>
            <!-- นักเรียน: dots + จำนวน -->
            <div style="display:flex;align-items:center;gap:3px;">
                <span style="font-size:.58rem;flex-shrink:0;">🎓</span>
                <?php foreach (array_slice($slot['students'], 0, 4) as $si => $st):
                    $sdone = (int)$st['completed_classes'] >= (int)$st['total_classes'];
                ?>
                <span style="width:8px;height:8px;border-radius:50%;flex-shrink:0;
                             background:<?= $sdone ? '#059669' : $sc['accent'] ?>;"
                      title="<?= htmlspecialchars($st['_disp_student'] ?? $st['student_name']) ?>"></span>
                <?php endforeach; ?>
                <?php if ($nStudents > 4): ?>
                <span style="font-size:.55rem;color:#6b7280;">+<?= $nStudents - 4 ?></span>
                <?php endif; ?>
                <span style="font-size:.6rem;color:#6b7280;margin-left:1px;"><?= $nStudents ?> คน</span>
            </div>
        </div>
        <?php endforeach; endforeach; ?>

    </div>
    <?php endforeach; ?>
</div><!-- flex body -->
</div><!-- min-width:600px -->
</div><!-- overflow-x:auto -->

<?php endif; ?>

</div><!-- end main -->

<!-- Delete Confirm Modal -->
<div id="modal-delete" class="ts-modal-bg">
<div class="ts-modal" style="max-width:380px;">
    <h3 style="color:#dc2626;margin:0 0 8px;">🗑 ยืนยันการลบ</h3>
    <p id="del-stu-text" style="color:#374151;font-size:.9rem;margin:0 0 1rem;"></p>
    <form method="post">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="q" value="/modules/7j/teacher_schedule.php">
        <input type="hidden" name="schedule_id" id="del-stu-id">
        <input type="hidden" name="teacher_filter" id="del-stu-teacher">
        <input type="hidden" name="search_val" id="del-stu-search">
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="ts-btn ts-btn-inactive" onclick="document.getElementById('modal-delete').classList.remove('open')">ยกเลิก</button>
            <button type="submit" class="ts-btn" style="background:#dc2626;color:#fff;">🗑 ลบ</button>
        </div>
    </form>
</div>
</div>

<!-- Complete Class Modal -->
<div id="modal-complete" class="ts-modal-bg">
<div class="ts-modal">
    <h3 style="margin:0 0 4px;font-size:1.1rem;font-weight:700;color:#1f2937;">✅ บันทึกการสอน</h3>
    <p style="color:#6b7280;font-size:.85rem;margin:0 0 1rem;">นักเรียน: <strong id="complete-name"></strong></p>
    <form method="post">
        <input type="hidden" name="action" value="complete">
        <input type="hidden" name="q" value="/modules/7j/teacher_schedule.php">
        <input type="hidden" name="schedule_id" id="complete-id">
        <input type="hidden" name="view" id="complete-view">
        <input type="hidden" name="teacher_filter" id="complete-teacher">
        <input type="hidden" name="search_val" id="complete-search">
        <div style="margin-bottom:1rem;">
            <label style="font-size:.8rem;font-weight:600;color:#374151;">หมายเหตุ (ถ้ามี)</label>
            <textarea name="note" rows="2" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:.9rem;margin-top:4px;box-sizing:border-box;resize:vertical;outline:none;"></textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="ts-btn ts-btn-inactive" onclick="closeComplete()">ยกเลิก</button>
            <button type="submit" class="ts-btn" style="background:#059669;color:#fff;">✅ บันทึก</button>
        </div>
    </form>
</div>
</div>

<script>
// ── Delete schedule ──────────────────────────────────────────────────────────
function confirmDeleteSchedule(id, name, teacher, search) {
    document.getElementById('del-stu-id').value      = id;
    document.getElementById('del-stu-teacher').value = teacher || '';
    document.getElementById('del-stu-search').value  = search  || '';
    document.getElementById('del-stu-text').textContent = 'ต้องการลบตารางเรียนของ "' + name + '" ใช่หรือไม่?';
    document.getElementById('modal-delete').classList.add('open');
}
document.getElementById('modal-delete').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});

// ── Card toggle ─────────────────────────────────────────────────────────────
function toggleCard(id) {
    var el   = document.getElementById(id);
    var chev = document.getElementById('chev-' + id);
    var open = el.style.display !== 'none';
    el.style.display = open ? 'none' : '';
    if (chev) chev.style.transform = open ? 'rotate(-90deg)' : 'rotate(0deg)';
}
function openComplete(id, name, view, teacher, search) {
    document.getElementById('complete-id').value      = id;
    document.getElementById('complete-name').textContent = name;
    document.getElementById('complete-view').value    = view;
    document.getElementById('complete-teacher').value = teacher;
    document.getElementById('complete-search').value  = search || '';
    document.getElementById('modal-complete').classList.add('open');
}
function closeComplete() {
    document.getElementById('modal-complete').classList.remove('open');
}
document.getElementById('modal-complete').addEventListener('click', function(e) {
    if (e.target === this) closeComplete();
});
</script>
