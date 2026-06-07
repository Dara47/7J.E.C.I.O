<?php
/*
 * 7J English Center — Auto Class Log Monitor
 * ดูสถานะ + trigger manual + ดู log history
 */

$logDir      = dirname(__DIR__, 2) . '/uploads/auto_log/';
$lastRunFile = $logDir . 'last_run.json';
$logFile     = $logDir . 'log_' . date('Y-m') . '.txt';

// ─── Add Schedule (จาก modal ในหน้านี้) ────────────────────────────────────────
if (($_POST['action'] ?? '') === 'add_schedule') {
    $stId    = trim($_POST['student_id']   ?? '');
    $stCode  = trim($_POST['student_code'] ?? '');
    $stName  = trim($_POST['student_name'] ?? '');
    $teaId   = trim($_POST['teacher_id']   ?? '');
    $stype   = ($_POST['schedule_type'] ?? 'one_time') === 'weekly' ? 'weekly' : 'one_time';
    $dow     = trim($_POST['day_of_week']  ?? '');
    $tstart  = trim($_POST['time_start']   ?? '');
    $tend    = trim($_POST['time_end']     ?? '');
    $total   = max(1, (int)($_POST['total_classes'] ?? 1));
    $note    = trim($_POST['note']         ?? '');
    $teaName = '';
    if ($teaId) {
        $tr = $connection2->prepare("SELECT displayName FROM sevenj_teachers WHERE id=? LIMIT 1");
        $tr->execute([$teaId]);
        $tr = $tr->fetch(PDO::FETCH_ASSOC);
        if ($tr) $teaName = $tr['displayName'];
    }
    // ─── Validation เงื่อนไข 1-3 ──────────────────────────────────────────────────
    $valErr = '';
    if (!$tstart || !$tend) {
        $valErr = 'กรุณาระบุเวลาเริ่มและเวลาจบ';
    } elseif ($tstart >= $tend) {
        $valErr = 'เวลาเริ่ม ('.$tstart.') ต้องน้อยกว่าเวลาจบ ('.$tend.') — วัน/เวลาไม่ตรงกัน';
    } elseif ($stype === 'one_time') {
        $dates = array_filter(array_map('trim', (array)($_POST['specific_dates'] ?? [])));
        if (empty($dates)) {
            $valErr = 'กรุณาระบุวันที่เรียนอย่างน้อย 1 วัน';
        } else {
            $nowTs = time();
            foreach ($dates as $d) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $valErr = 'รูปแบบวันที่ไม่ถูกต้อง: '.$d; break; }
                $dtTs = strtotime($d . ' ' . $tstart . ':00');
                if ($dtTs === false || $dtTs <= $nowTs) { $valErr = 'วันที่ '.$d.' เวลา '.$tstart.' ผ่านมาแล้ว — ไม่สามารถบันทึกตารางได้'; break; }
            }
        }
    } elseif ($stype === 'weekly' && !$dow) {
        $valErr = 'กรุณาเลือกวันในสัปดาห์';
    }
    if ($valErr) {
        header('Location: /MyNewShool/?q=/modules/7j/auto_log.php&sched_err='.urlencode($valErr));
        exit;
    }

    $ins = $connection2->prepare("INSERT INTO sevenj_schedule
        (student_id,student_code,student_name,teacher_ref_id,teacher_name,
         schedule_type,day_of_week,specific_date,time_start,time_end,
         total_classes,completed_classes,status,note)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,0,'active',?)");
    if ($stype === 'one_time') {
        foreach ($dates as $d) {
            $ins->execute([$stId,$stCode,$stName,$teaId?:null,$teaName,'one_time','',$d,$tstart,$tend,$total,$note]);
        }
    } else {
        $ins->execute([$stId,$stCode,$stName,$teaId?:null,$teaName,'weekly',$dow,null,$tstart,$tend,$total,$note]);
    }
    header('Location: /MyNewShool/?q=/modules/7j/auto_log.php&added=1');
    exit;
}

// ─── Manual Trigger ────────────────────────────────────────────────────────────
$triggerMsg = '';
if (isset($_POST['action']) && $_POST['action'] === 'trigger') {
    $php    = 'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe';
    $script = dirname(__DIR__, 2) . '\\auto_class_log.php';
    $output = [];
    exec('"'.$php.'" "'.$script.'" 2>&1', $output, $code);
    $triggerMsg = implode("\n", $output);
}

// ─── อ่าน last run (แสดงเฉพาะภายใน 24 ชม.) ────────────────────────────────────
$lastRun = null;
if (file_exists($lastRunFile)) {
    $lr = json_decode(file_get_contents($lastRunFile), true);
    if ($lr && isset($lr['run_at'])) {
        $runTs = strtotime($lr['run_at']);
        if ($runTs && (time() - $runTs) < 86400) {   // ภายใน 24 ชม.
            $lastRun = $lr;
        }
    }
}

// ─── ข้อมูล schedule ที่จะถูก auto log วันนี้ ──────────────────────────────────
$_bkk     = new DateTimeZone('Asia/Bangkok');
$_now     = new DateTime('now', $_bkk);
$dayName  = $_now->format('l');
$today    = $_now->format('Y-m-d');
$nowTime  = $_now->format('H:i');

// ─── บันทึกวันนี้ — อ้างอิงจาก sevenj_schedule (มุมมองตารางเรียน) ──────────────
$todayLogs = $connection2->query("
    SELECT sch.id AS sch_id, sch.student_id, sch.total_classes, sch.schedule_type,
        sch.day_of_week, sch.specific_date, sch.time_start, sch.time_end,
        sch.note AS sch_note,
        COALESCE(st.displayName, sch.student_name) AS disp_student,
        COALESCE(t.displayName,  sch.teacher_name) AS disp_teacher,
        COUNT(c.id) AS logged_today,
        MAX(c.note) AS log_note,
        MIN(c.completed_date) AS log_date,
        (SELECT COUNT(*) FROM sevenj_class_completions c2 WHERE c2.schedule_id = sch.id) AS total_done_all,
        (SELECT COUNT(*) FROM sevenj_class_completions c3 WHERE c3.student_id = sch.student_id) AS total_done_all_student,
        (SELECT COUNT(*) FROM sevenj_class_completions c4 WHERE c4.student_id = sch.student_id AND c4.completed_date = '".addslashes($today)."') AS logged_today_student
    FROM sevenj_schedule sch
    LEFT JOIN sevenj_students st ON st.id = sch.student_id
    LEFT JOIN sevenj_teachers  t ON t.id  = sch.teacher_ref_id
    INNER JOIN sevenj_class_completions c ON c.schedule_id = sch.id
        AND c.completed_date = '".addslashes($today)."'
    WHERE sch.status IN ('active','completed')
      AND (
          (sch.schedule_type = 'weekly'   AND LOWER(sch.day_of_week)  = LOWER('".addslashes($dayName)."'))
          OR (sch.schedule_type = 'one_time' AND sch.specific_date = '".addslashes($today)."')
      )
    GROUP BY sch.id
    ORDER BY sch.time_start
")->fetchAll(PDO::FETCH_ASSOC);

$todaySchedules = $connection2->query("
    SELECT s.*,
        COALESCE(st.displayName, s.student_name) AS disp_student,
        COALESCE(t.displayName,  s.teacher_name) AS disp_teacher,
        (SELECT COUNT(*) FROM sevenj_class_completions c
         WHERE c.schedule_id = s.id AND c.completed_date = '".addslashes($today)."') AS logged_today,
        (SELECT c.session_number FROM sevenj_class_completions c
         WHERE c.schedule_id = s.id AND c.completed_date = '".addslashes($today)."' LIMIT 1) AS today_session,
        (SELECT COUNT(*) FROM sevenj_class_completions c WHERE c.schedule_id = s.id) AS total_done_all,
        (SELECT COUNT(*) FROM sevenj_class_completions c2
         WHERE c2.schedule_id = s.id AND c2.completed_date = '".addslashes($today)."') AS logged_today_student
    FROM sevenj_schedule s
    LEFT JOIN sevenj_students st ON st.id = s.student_id
    LEFT JOIN sevenj_teachers t  ON t.id  = s.teacher_ref_id
    WHERE s.status = 'active'
      AND s.completed_classes < s.total_classes
      AND (
          (s.schedule_type = 'weekly' AND LOWER(s.day_of_week) = LOWER('".addslashes($dayName)."'))
          OR
          (s.schedule_type = 'one_time' AND s.specific_date = '".addslashes($today)."')
      )
    ORDER BY s.time_start
")->fetchAll(PDO::FETCH_ASSOC);

// Override todayLogs: อ้างอิงจาก schedule ที่เวลาผ่านแล้ว (แทน sevenj_class_completions ที่อาจว่าง)
$todayLogs = [];
foreach ($todaySchedules as $s) {
    if ($nowTime > ($s['time_end'] ?? '')) {
        $todayLogs[] = array_merge($s, [
            'sch_note'               => $s['note'] ?? '',
            'log_note'               => $s['note'] ?? '',
            'log_date'               => $today,
            'total_done_all_student' => 0,
            'logged_today_student'   => 0,
        ]);
    }
}

$dayTH = ['Monday'=>'จันทร์','Tuesday'=>'อังคาร','Wednesday'=>'พุธ',
          'Thursday'=>'พฤหัสบดี','Friday'=>'ศุกร์','Saturday'=>'เสาร์','Sunday'=>'อาทิตย์'];

$allTeachersForModal = $connection2->query(
    "SELECT id, displayName, teacherCode FROM sevenj_teachers WHERE status='active' ORDER BY displayName"
)->fetchAll(PDO::FETCH_ASSOC);

$availSlotsRaw = $connection2->query(
    "SELECT teacher_id, type, day, specific_date, start_time, end_time, note
     FROM sevenj_teacher_availability ORDER BY teacher_id, type DESC, day, specific_date, start_time"
)->fetchAll(PDO::FETCH_ASSOC);
$smAvailByTeacher = [];
foreach ($availSlotsRaw as $av) { $smAvailByTeacher[$av['teacher_id']][] = $av; }

// เวลาเก็บแบบ 24h จริง — แปลงเป็น 12h AM/PM
function fmtTimePM(string $t): string {
    if ($t === '') return '';
    [$h, $m] = array_pad(explode(':', $t), 2, '00');
    $h = (int)$h;
    $suffix = $h >= 12 ? 'PM' : 'AM';
    $h12    = $h % 12 ?: 12;
    return $h12 . ':' . $m . ' ' . $suffix;
}
?>

<?php require_once __DIR__.'/_theme.php'; ?>
<style>
.al-card{background:#fff;border-radius:12px;padding:1.25rem 1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.08);border:1px solid #f0f0f0;margin-bottom:1.25rem;}
.al-stat{background:#fff;border-radius:10px;padding:.85rem 1rem;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.08);border-top:3px solid;}
.al-badge{display:inline-block;border-radius:99px;padding:2px 10px;font-size:.75rem;font-weight:700;}
.al-table{width:100%;border-collapse:collapse;font-size:.84rem;}
.al-table th{padding:8px 12px;background:#fffbeb;color:#92400e;font-weight:700;text-align:left;border-bottom:2px solid #fde68a;}
.al-table td{padding:7px 12px;border-bottom:1px solid #f3f4f6;}
.al-table tr:hover td{background:#fffbeb;}
.al-log{background:#1e1e2e;color:#cdd6f4;border-radius:10px;padding:1rem;font-family:monospace;font-size:.78rem;max-height:300px;overflow-y:auto;line-height:1.6;}
.al-btn{padding:8px 20px;border-radius:8px;font-weight:600;font-size:.88rem;cursor:pointer;border:none;}
.al-btn-primary{background:linear-gradient(135deg,#92400e,#d97706);color:#fff;}
.al-btn-primary:hover{opacity:.9;}
.al-trigger-out{background:#0f2a0f;color:#86efac;border-radius:8px;padding:1rem;font-family:monospace;font-size:.78rem;margin-top:1rem;white-space:pre-wrap;max-height:200px;overflow-y:auto;}
</style>

<div style="max-width:100%;padding-bottom:2rem;">

<!-- Header -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:1.25rem;">
    <div>
        <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0;">⚙️ Auto Class Log</h2>
        <p style="font-size:.85rem;color:#6b7280;margin:4px 0 0;">ระบบตัดคาบเรียนอัตโนมัติ — วันนี้: <?= $dayTH[$dayName]??$dayName ?> <?= $_now->format('d/m/Y') ?> เวลา <?= $nowTime ?></p>
    </div>
</div>


<!-- ตารางวันนี้ -->
<div class="al-card">
    <div style="font-weight:700;color:#1f2937;margin-bottom:.75rem;">
        📅 ตารางที่ต้องตัดวันนี้ (<?= $dayTH[$dayName]??$dayName ?>) — พบ <?= count($todaySchedules) ?> รายการ
    </div>
    <?php if (empty($todaySchedules)): ?>
    <p style="color:#9ca3af;font-size:.85rem;text-align:center;padding:1rem;">ไม่มีตารางเรียนวันนี้</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="al-table">
        <thead>
            <tr>
                <th>วัน</th><th>เวลา</th><th>นักเรียน</th><th>ครู</th><th>คาบที่</th>
                <th style="text-align:center;">สถานะวันนี้</th><th style="text-align:center;">เวลาปัจจุบัน</th>
            </tr>
        </thead>
        <tbody>
        <?php
        // rank: tracked per student — logged rank และ unlogged rank แยกกัน
        $stuLoggedRank   = [];
        $stuUnloggedRank = [];
        ?>
        <?php foreach ($todaySchedules as $s):
            $loggedToday    = (int)$s['logged_today'] > 0;
            $stuKey         = $s['student_id'] ?: ($s['disp_student'].'|'.($s['student_code'] ?? ''));
            $totalDoneAll   = (int)$s['total_done_all'];
            $loggedTodayStu = (int)$s['logged_today_student'];
            $prevDone       = $totalDoneAll - $loggedTodayStu; // completions ก่อนวันนี้

            if ($loggedToday) {
                // บันทึกแล้ว: prevDone + ลำดับ logged slot วันนี้ (1st=1, 2nd=2...)
                $stuLoggedRank[$stuKey] = ($stuLoggedRank[$stuKey] ?? 0) + 1;
                $thisSess = $prevDone + $stuLoggedRank[$stuKey];
            } else {
                // ยังไม่บันทึก: prevDone + logged วันนี้ + ลำดับ unlogged slot
                $stuUnloggedRank[$stuKey] = ($stuUnloggedRank[$stuKey] ?? 0) + 1;
                $thisSess = $prevDone + $loggedTodayStu + $stuUnloggedRank[$stuKey];
            }
            $timeStarted = $nowTime >= $s['time_start'];
        ?>
        <?php
        $rowDow  = $s['schedule_type']==='one_time' ? date('l',strtotime($s['specific_date']??$today)) : ($s['day_of_week']??$dayName);
        $rowDate = $s['schedule_type']==='one_time' ? date('d/m/Y',strtotime($s['specific_date']??$today)) : date('d/m/Y',strtotime($today));
        $rowDayTH = $dayTH[$rowDow] ?? $rowDow;
        ?>
        <tr>
            <td style="white-space:nowrap;font-size:.82rem;">วัน<?=$rowDayTH?><br><span style="font-family:monospace;color:#6b7280;"><?=$rowDate?></span></td>
            <td style="font-family:monospace;font-weight:600;">
                <?= fmtTimePM($s['time_start'] ?? '') ?> – <?= fmtTimePM($s['time_end'] ?? '') ?>
            </td>
            <td style="font-weight:600;"><?= htmlspecialchars($s['disp_student']) ?></td>
            <td style="color:#6b7280;"><?= htmlspecialchars($s['disp_teacher']) ?></td>
            <td><span class="al-badge" style="background:#dbeafe;color:#1e40af;">#<?= $thisSess ?>/<?= (int)$s['total_classes'] ?></span></td>
            <td style="text-align:center;">
                <?php if ($loggedToday): ?>
                <span class="al-badge" style="background:#d1fae5;color:#065f46;">✅ บันทึกแล้ว</span>
                <?php elseif ($nowTime > $s['time_end']): ?>
                <span class="al-badge" style="background:#fee2e2;color:#991b1b;">⚠️ ยังไม่บันทึก</span>
                <?php else: ?>
                <span class="al-badge" style="background:#fef3c7;color:#92400e;">⏳ รอบันทึก</span>
                <?php endif; ?>
            </td>
            <td style="text-align:center;">
                <?php if ($loggedToday): ?>
                <span style="color:#059669;font-weight:700;font-size:.8rem;">🟢 เรียนแล้ว</span>
                <?php elseif ($nowTime >= $s['time_start'] && $nowTime <= $s['time_end']): ?>
                <span style="color:#2563eb;font-weight:700;font-size:.8rem;">🔵 กำลังเรียน</span>
                <?php elseif ($nowTime > $s['time_end']): ?>
                <span style="color:#059669;font-weight:700;font-size:.8rem;">✅ เรียนแล้ว</span>
                <?php else: ?>
                <span style="color:#d97706;font-size:.8rem;">🟡 รอเรียน</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- บันทึกคาบที่ล็อกแล้ววันนี้ -->
<div class="al-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:.75rem;">
        <div>
            <span style="font-weight:700;color:#1f2937;">✅ บันทึกวันนี้ (<?= $dayTH[$dayName]??$dayName ?> <?= $_now->format('d/m/Y') ?>)</span>
            <span style="background:#d1fae5;color:#065f46;border-radius:99px;padding:1px 10px;font-size:.72rem;font-weight:700;margin-left:8px;"><?= count($todayLogs) ?> คาบ</span>
        </div>
        <?php if (!empty($todayLogs)): ?>
        <a href="?q=/modules/7j/class_learning_report.php&date_from=<?= $today ?>&date_to=<?= $today ?>"
           style="font-size:.78rem;color:#ea580c;font-weight:600;text-decoration:none;">
            ดูใน รายงานการเรียน-การสอน →
        </a>
        <?php endif; ?>
    </div>

    <?php if (empty($todayLogs)): ?>
    <p style="color:#9ca3af;font-size:.85rem;text-align:center;padding:.75rem;">ยังไม่มีบันทึกวันนี้</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="al-table">
        <thead>
            <tr><th>นักเรียน</th><th>ครู</th><th>เวลา (ตาราง)</th><th style="text-align:center;">ประเภทคอร์ส / วันที่</th><th style="text-align:center;">เรียนแล้ว/ทั้งหมด</th><th>หมายเหตุ</th></tr>
        </thead>
        <tbody>
        <?php $lgLoggedRank = []; ?>
        <?php foreach ($todayLogs as $lg):
            $lgKey    = $lg['student_id'] ?: $lg['disp_student'];
            $lgLoggedRank[$lgKey] = ($lgLoggedRank[$lgKey] ?? 0) + 1;
            $lgPrevDone  = (int)$lg['total_done_all_student'] - (int)$lg['logged_today_student'];
            $lgSessNum   = $lgPrevDone + $lgLoggedRank[$lgKey];
        ?>
        <tr>
            <td style="font-weight:600;"><?= htmlspecialchars($lg['disp_student']) ?></td>
            <td style="color:#6b7280;"><?= htmlspecialchars($lg['disp_teacher']) ?></td>
            <td style="font-family:monospace;"><?= $lg['time_start'] ? fmtTimePM($lg['time_start']).' – '.fmtTimePM($lg['time_end']) : '—' ?></td>
            <td style="text-align:center;">
                <?php
                $isNewCourse = ($lg['sch_note'] === 'คอร์สใหม่');
                $logDateFmt  = $lg['log_date'] ? date('d/m/Y', strtotime($lg['log_date'])) : '—';
                ?>
                <span class="al-badge" style="background:<?= $isNewCourse?'#dcfce7':'#fef3c7' ?>;color:<?= $isNewCourse?'#166534':'#92400e' ?>;">
                    <?= $isNewCourse ? '🆕 คอร์สใหม่' : '📚 คอร์สเก่า' ?>
                </span>
                <div style="font-size:.7rem;color:#9ca3af;margin-top:2px;"><?= $logDateFmt ?></div>
            </td>
            <td style="text-align:center;"><span class="al-badge" style="background:#dbeafe;color:#1e40af;"><?= $lgSessNum ?>/<?= (int)$lg['total_classes'] ?></span></td>
            <td style="font-size:.75rem;color:#9ca3af;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($lg['log_note'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <div style="margin-top:.85rem;padding:.65rem .85rem;background:#fff7ed;border-radius:8px;border-left:3px solid #f97316;font-size:.78rem;color:#92400e;">
        🕐 ข้อมูลวันนี้จะโอนไปยัง
        <a href="?q=/modules/7j/class_learning_report.php" style="color:#ea580c;font-weight:700;">รายงานการเรียน-การสอน</a>
        โดยอัตโนมัติเมื่อผ่าน 24 ชม. — หน้านี้แสดงเฉพาะบันทึกของวันนี้เท่านั้น
    </div>
</div>

<?php if (!empty($_GET['added'])): ?>
<div id="al-added-msg" style="position:fixed;bottom:24px;right:24px;background:#dcfce7;border:1.5px solid #86efac;color:#166534;border-radius:10px;padding:12px 20px;font-weight:700;font-size:.88rem;box-shadow:0 4px 16px rgba(0,0,0,.12);z-index:9999;">
    ✅ เพิ่มตารางเรียนสำเร็จ
</div>
<script>setTimeout(function(){var el=document.getElementById('al-added-msg');if(el)el.remove();},3000);</script>
<?php endif; ?>
<?php if (!empty($_GET['sched_err'])): ?>
<div id="al-err-msg" style="position:fixed;bottom:24px;right:24px;background:#fee2e2;border:1.5px solid #fca5a5;color:#991b1b;border-radius:10px;padding:12px 20px;font-weight:700;font-size:.88rem;box-shadow:0 4px 16px rgba(0,0,0,.12);z-index:9999;max-width:360px;">
    ❌ <?= htmlspecialchars($_GET['sched_err']) ?>
</div>
<script>setTimeout(function(){var el=document.getElementById('al-err-msg');if(el)el.remove();},5000);</script>
<?php endif; ?>

<div id="sched-modal-bg" style="display:none;"></div>


</div>
