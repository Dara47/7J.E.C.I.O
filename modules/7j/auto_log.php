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
$dayName  = date('l');
$today    = date('Y-m-d');
$nowTime  = date('H:i');

// ─── บันทึกวันนี้ — อ้างอิงจาก sevenj_schedule (มุมมองตารางเรียน) ──────────────
$todayLogs = $connection2->query("
    SELECT sch.id AS sch_id, sch.total_classes, sch.schedule_type,
        sch.day_of_week, sch.specific_date, sch.time_start, sch.time_end,
        COALESCE(st.displayName, sch.student_name) AS disp_student,
        COALESCE(t.displayName,  sch.teacher_name) AS disp_teacher,
        COUNT(c.id) AS logged_today,
        MAX(c.note) AS log_note,
        (SELECT COUNT(*) FROM sevenj_class_completions c2 WHERE c2.student_id = sch.student_id) AS total_done_all
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
        (SELECT COUNT(*) FROM sevenj_class_completions c WHERE c.student_id = s.student_id) AS total_done_all
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
        <p style="font-size:.85rem;color:#6b7280;margin:4px 0 0;">ระบบตัดคาบเรียนอัตโนมัติ — วันนี้: <?= $dayTH[$dayName]??$dayName ?> <?= date('d/m/Y') ?> เวลา <?= $nowTime ?></p>
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
                <th>เวลา</th><th>นักเรียน</th><th>ครู</th><th>คาบที่</th>
                <th style="text-align:center;">สถานะวันนี้</th><th style="text-align:center;">เวลาปัจจุบัน</th>
                <th style="text-align:center;">จัดตาราง</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($todaySchedules as $s):
            $loggedToday = (int)$s['logged_today'] > 0;
            // ใช้ total_done_all (COUNT จาก class_completions ต่อนักเรียน) เพื่อให้ได้ลำดับคาบจริง
            $thisSess = $loggedToday
                ? (int)$s['total_done_all']         // บันทึกแล้ว = จำนวนรวมหลังบันทึก
                : (int)$s['total_done_all'] + 1;    // ยังไม่บันทึก = คาบถัดไป
            $timeStarted = $nowTime >= $s['time_start'];
        ?>
        <tr>
            <td style="font-family:monospace;font-weight:600;">
                <?= fmtTimePM($s['time_start'] ?? '') ?> – <?= fmtTimePM($s['time_end'] ?? '') ?>
            </td>
            <td style="font-weight:600;"><?= htmlspecialchars($s['disp_student']) ?></td>
            <td style="color:#6b7280;"><?= htmlspecialchars($s['disp_teacher']) ?></td>
            <td><span class="al-badge" style="background:#dbeafe;color:#1e40af;">#<?= $thisSess ?>/<?= (int)$s['total_classes'] ?></span></td>
            <td style="text-align:center;">
                <?php if ($loggedToday): ?>
                <span class="al-badge" style="background:#d1fae5;color:#065f46;">✅ บันทึกแล้ว</span>
                <?php else: ?>
                <span class="al-badge" style="background:#fef3c7;color:#92400e;">⏳ รอบันทึก</span>
                <?php endif; ?>
            </td>
            <td style="text-align:center;">
                <?php if ($loggedToday): ?>
                <span style="color:#059669;font-weight:700;font-size:.8rem;">🟢 เรียนแล้ว</span>
                <?php else: ?>
                <span style="color:#d97706;font-size:.8rem;">🟡 รอเรียน</span>
                <?php endif; ?>
            </td>
            <td style="text-align:center;">
                <?php
                $remain = max(0, (int)$s['total_classes'] - (int)$s['total_done_all']);
                $modalData = json_encode([
                    'student_id'   => $s['student_id']   ?? '',
                    'student_code' => $s['student_code']  ?? '',
                    'student_name' => $s['disp_student']  ?? '',
                    'teacher_id'   => $s['teacher_ref_id'] ?? '',
                    'teacher_name' => $s['disp_teacher']  ?? '',
                    'total_classes'=> (int)$s['total_classes'],
                    'done'         => (int)$s['total_done_all'],
                    'remain'       => $remain,
                ], JSON_HEX_QUOT|JSON_HEX_APOS);
                ?>
                <button onclick='openSchedModal(<?= $modalData ?>)'
                    style="background:linear-gradient(135deg,#1A2A5E,#E8640A);color:#fff;border:none;border-radius:7px;padding:4px 12px;font-size:.75rem;font-weight:700;cursor:pointer;white-space:nowrap;">
                    + จัดตาราง
                </button>
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
            <span style="font-weight:700;color:#1f2937;">✅ บันทึกวันนี้ (<?= $dayTH[$dayName]??$dayName ?> <?= date('d/m/Y') ?>)</span>
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
            <tr><th>นักเรียน</th><th>ครู</th><th>เวลา (ตาราง)</th><th style="text-align:center;">เรียนแล้ว/ทั้งหมด</th><th>หมายเหตุ</th></tr>
        </thead>
        <tbody>
        <?php foreach ($todayLogs as $lg): ?>
        <tr>
            <td style="font-weight:600;"><?= htmlspecialchars($lg['disp_student']) ?></td>
            <td style="color:#6b7280;"><?= htmlspecialchars($lg['disp_teacher']) ?></td>
            <td style="font-family:monospace;"><?= $lg['time_start'] ? fmtTimePM($lg['time_start']).' – '.fmtTimePM($lg['time_end']) : '—' ?></td>
            <td style="text-align:center;"><span class="al-badge" style="background:#dbeafe;color:#1e40af;"><?= (int)$lg['total_done_all'] ?>/<?= (int)$lg['total_classes'] ?></span></td>
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

<!-- ─── Modal จัดตาราง ─────────────────────────────────────────────────────── -->
<div id="sched-modal-bg" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:8000;align-items:center;justify-content:center;overflow-y:auto;padding:20px;">
<div style="background:#fff;border-radius:14px;max-width:560px;width:100%;margin:auto;box-shadow:0 8px 32px rgba(0,0,0,.2);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#1A2A5E,#E8640A);padding:14px 20px;display:flex;justify-content:space-between;align-items:center;">
        <div style="color:#fff;font-weight:700;font-size:1rem;">📅 จัดตารางเรียน</div>
        <button onclick="closeSchedModal()" style="background:none;border:none;color:#fff;font-size:1.3rem;cursor:pointer;line-height:1;">✕</button>
    </div>
    <form method="POST" id="sched-form" style="padding:1.25rem 1.5rem;">
        <input type="hidden" name="action"       value="add_schedule">
        <input type="hidden" name="q"            value="/modules/7j/auto_log.php">
        <input type="hidden" name="student_id"   id="sm-sid">
        <input type="hidden" name="student_code" id="sm-scode">
        <input type="hidden" name="student_name" id="sm-sname">

        <!-- นักเรียน (readonly) -->
        <div style="background:#f9fafb;border-radius:8px;padding:10px 14px;margin-bottom:14px;display:flex;gap:12px;align-items:center;">
            <span style="font-size:1.3rem;">🎓</span>
            <div>
                <div id="sm-disp-name" style="font-weight:700;font-size:.95rem;color:#1f2937;"></div>
                <div id="sm-disp-code" style="font-size:.75rem;color:#9ca3af;"></div>
            </div>
            <div style="margin-left:auto;text-align:right;">
                <div style="font-size:.72rem;color:#6b7280;">คาบเรียน</div>
                <div id="sm-disp-prog" style="font-size:.88rem;font-weight:700;color:#E8640A;"></div>
                <div id="sm-disp-remain" style="font-size:.72rem;color:#059669;"></div>
            </div>
        </div>

        <!-- ครู -->
        <div style="margin-bottom:12px;">
            <label style="display:block;font-size:.8rem;font-weight:700;color:#374151;margin-bottom:4px;">ครูผู้สอน</label>
            <select name="teacher_id" id="sm-teacher" onchange="smShowAvailability(this.value)" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.86rem;box-sizing:border-box;">
                <option value="">— เลือกครู —</option>
                <?php foreach ($allTeachersForModal as $t): ?>
                <option value="<?= htmlspecialchars($t['id']) ?>"><?= htmlspecialchars($t['displayName']) ?> (<?= htmlspecialchars($t['teacherCode']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <div id="sm-avail-hint" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:8px 12px;margin-top:8px;font-size:.8rem;color:#166534;">
                <strong>🕐 ช่วงเวลาว่างของครู — คลิกเพื่อเลือก:</strong>
                <div id="sm-avail-list" style="margin-top:5px;"></div>
            </div>
        </div>

        <!-- ประเภท -->
        <div style="margin-bottom:12px;display:flex;gap:16px;align-items:center;">
            <label style="font-size:.8rem;font-weight:700;color:#374151;">ประเภท</label>
            <label style="display:flex;align-items:center;gap:5px;font-size:.85rem;cursor:pointer;">
                <input type="radio" name="schedule_type" value="one_time" id="sm-type-once" checked onchange="smToggleType()"> วันเฉพาะ
            </label>
            <label style="display:flex;align-items:center;gap:5px;font-size:.85rem;cursor:pointer;">
                <input type="radio" name="schedule_type" value="weekly" id="sm-type-weekly" onchange="smToggleType()"> รายสัปดาห์
            </label>
        </div>

        <!-- วันเฉพาะ (one_time) -->
        <div id="sm-dates-section" style="margin-bottom:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <label style="font-size:.8rem;font-weight:700;color:#374151;">วันที่เรียน</label>
                <button type="button" onclick="smAddDate()" style="font-size:.75rem;padding:3px 10px;background:#E8640A;color:#fff;border:none;border-radius:6px;cursor:pointer;">+ เพิ่มวันที่</button>
            </div>
            <div id="sm-date-rows"></div>
        </div>

        <!-- วันในสัปดาห์ (weekly) -->
        <div id="sm-weekly-section" style="display:none;margin-bottom:12px;">
            <label style="display:block;font-size:.8rem;font-weight:700;color:#374151;margin-bottom:4px;">วันในสัปดาห์</label>
            <select name="day_of_week" id="sm-dow" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.86rem;box-sizing:border-box;">
                <option value="Monday">จันทร์</option><option value="Tuesday">อังคาร</option>
                <option value="Wednesday">พุธ</option><option value="Thursday">พฤหัสบดี</option>
                <option value="Friday">ศุกร์</option><option value="Saturday">เสาร์</option>
                <option value="Sunday">อาทิตย์</option>
            </select>
        </div>

        <!-- เวลา -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
            <div>
                <label style="display:block;font-size:.8rem;font-weight:700;color:#374151;margin-bottom:4px;">เวลาเริ่ม</label>
                <input type="time" name="time_start" id="sm-tstart" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.86rem;box-sizing:border-box;">
            </div>
            <div>
                <label style="display:block;font-size:.8rem;font-weight:700;color:#374151;margin-bottom:4px;">เวลาจบ</label>
                <input type="time" name="time_end" id="sm-tend" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.86rem;box-sizing:border-box;">
            </div>
        </div>

        <!-- คาบทั้งหมด -->
        <div style="margin-bottom:12px;">
            <label style="display:block;font-size:.8rem;font-weight:700;color:#374151;margin-bottom:4px;">คาบทั้งหมด (package)</label>
            <input type="number" name="total_classes" id="sm-total" min="1" value="20"
                style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.86rem;box-sizing:border-box;">
        </div>

        <!-- หมายเหตุ -->
        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:.8rem;font-weight:700;color:#374151;margin-bottom:4px;">หมายเหตุ</label>
            <textarea name="note" id="sm-note" rows="2" placeholder="บันทึกเพิ่มเติม..."
                style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.86rem;box-sizing:border-box;resize:vertical;"></textarea>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" onclick="closeSchedModal()" style="padding:9px 20px;border:1px solid #d1d5db;border-radius:7px;background:#fff;font-size:.88rem;cursor:pointer;">ยกเลิก</button>
            <button type="submit" style="padding:9px 24px;background:linear-gradient(135deg,#1A2A5E,#E8640A);color:#fff;border:none;border-radius:7px;font-size:.88rem;font-weight:700;cursor:pointer;">💾 บันทึกตาราง</button>
        </div>
    </form>
</div>
</div>

<script>
var _smDateCount = 0;
var smAvailData = <?= json_encode($smAvailByTeacher) ?>;
var SM_DAYS_TH  = {Sunday:'อาทิตย์',Monday:'จันทร์',Tuesday:'อังคาร',Wednesday:'พุธ',Thursday:'พฤหัสบดี',Friday:'ศุกร์',Saturday:'เสาร์'};

function smShowAvailability(teacherId) {
    var hint = document.getElementById('sm-avail-hint');
    var list = document.getElementById('sm-avail-list');
    if (!teacherId || !smAvailData[teacherId] || smAvailData[teacherId].length === 0) {
        hint.style.display = 'none'; return;
    }
    list.innerHTML = '';
    smAvailData[teacherId].forEach(function(s) {
        var label = s.type === 'weekly'
            ? '🗓 ' + (SM_DAYS_TH[s.day] || s.day)
            : '📅 ' + s.specific_date;
        var chip = document.createElement('span');
        chip.style.cssText = 'display:inline-block;background:#bbf7d0;color:#166534;border-radius:6px;'
            + 'padding:3px 10px;margin:3px 3px;font-size:.78rem;cursor:pointer;border:1px solid #86efac;';
        chip.title = 'คลิกเพื่อเลือกช่วงเวลานี้';
        chip.innerHTML = label + ' <strong>' + s.start_time + '–' + s.end_time + '</strong>'
            + (s.note ? ' <span style="opacity:.65;">(' + s.note + ')</span>' : '')
            + ' <span style="font-size:.7rem;opacity:.6;">▶ เลือก</span>';
        chip.onmouseover = function() { this.style.background = '#4ade80'; };
        chip.onmouseout  = function() { this.style.background = '#bbf7d0'; };
        chip.onclick     = function() { smApplySlot(s, chip); };
        list.appendChild(chip);
    });
    hint.style.display = '';
}

function smApplySlot(s, chip) {
    document.getElementById('sm-tstart').value = s.start_time || '';
    document.getElementById('sm-tend').value   = s.end_time   || '';
    if (s.type === 'weekly') {
        document.getElementById('sm-type-weekly').checked = true;
        document.getElementById('sm-dow').value = s.day || '';
        smToggleType();
    } else {
        document.getElementById('sm-type-once').checked = true;
        smToggleType();
        var firstDate = document.querySelector('#sm-date-rows input[type=date]');
        if (firstDate) firstDate.value = s.specific_date || '';
    }
    document.querySelectorAll('#sm-avail-list span').forEach(function(c) { c.style.background='#bbf7d0'; c.style.color='#166534'; });
    chip.style.background = '#16a34a'; chip.style.color = '#fff';
}

function openSchedModal(d) {
    document.getElementById('sm-sid').value   = d.student_id   || '';
    document.getElementById('sm-scode').value = d.student_code || '';
    document.getElementById('sm-sname').value = d.student_name || '';
    document.getElementById('sm-disp-name').textContent  = d.student_name || '';
    document.getElementById('sm-disp-code').textContent  = d.student_code || '';
    document.getElementById('sm-disp-prog').textContent  = d.done + ' / ' + d.total_classes + ' คาบ';
    document.getElementById('sm-disp-remain').textContent = 'เหลือ ' + d.remain + ' คาบ';
    document.getElementById('sm-total').value = d.total_classes || 20;
    // pre-select teacher ถ้ามี + แสดง availability
    var sel = document.getElementById('sm-teacher');
    sel.value = d.teacher_id || '';
    smShowAvailability(d.teacher_id || '');
    // reset dates
    _smDateCount = 0;
    document.getElementById('sm-date-rows').innerHTML = '';
    smAddDate();
    // reset type
    document.getElementById('sm-type-once').checked = true;
    smToggleType();
    // reset note/time
    document.getElementById('sm-tstart').value = '';
    document.getElementById('sm-tend').value   = '';
    document.getElementById('sm-note').value   = '';
    // show modal
    var bg = document.getElementById('sched-modal-bg');
    bg.style.display = 'flex';
}

function closeSchedModal() {
    document.getElementById('sched-modal-bg').style.display = 'none';
}

function smAddDate() {
    _smDateCount++;
    var idx = _smDateCount;
    var row = document.createElement('div');
    row.id = 'sm-dr-' + idx;
    row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px;';
    row.innerHTML = '<input type="date" name="specific_dates[]" style="flex:1;padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.86rem;box-sizing:border-box;">'
        + (idx > 1 ? '<button type="button" onclick="smRemoveDate(' + idx + ')" style="padding:5px 10px;background:#fee2e2;color:#991b1b;border:none;border-radius:6px;cursor:pointer;font-size:.8rem;">ลบ</button>' : '');
    document.getElementById('sm-date-rows').appendChild(row);
}

function smRemoveDate(idx) {
    var el = document.getElementById('sm-dr-' + idx);
    if (el) el.remove();
}

function smToggleType() {
    var isOnce = document.getElementById('sm-type-once').checked;
    document.getElementById('sm-dates-section').style.display  = isOnce ? '' : 'none';
    document.getElementById('sm-weekly-section').style.display = isOnce ? 'none' : '';
}

// ปิด modal เมื่อคลิก backdrop
document.getElementById('sched-modal-bg').addEventListener('click', function(e) {
    if (e.target === this) closeSchedModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeSchedModal();
});
</script>

</div>
