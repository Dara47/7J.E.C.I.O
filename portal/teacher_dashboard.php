<?php
require_once __DIR__ . '/_auth.php';
$me = require_teacher_login();

$pdo = portal_db();

// ─── POST: จัดการเวลาว่าง ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_avail') {
    $type  = ($_POST['slot_type'] ?? '') === 'specific_date' ? 'specific_date' : 'weekly';
    $day   = $type === 'weekly'        ? (trim($_POST['day'] ?? 'monday') ?: null) : null;
    $sdate = $type === 'specific_date' ? (trim($_POST['specific_date'] ?? '') ?: null) : null;
    $stime = trim($_POST['start_time'] ?? '');
    $etime = trim($_POST['end_time']   ?? '');
    $note  = trim($_POST['note']       ?? '') ?: null;
    if ($stime && $etime) {
        $id = 'slot_' . time() . rand(100,999);
        $ins = $pdo->prepare('INSERT INTO sevenj_teacher_availability
            (id,teacher_id,type,day,specific_date,start_time,end_time,note)
            VALUES (?,?,?,?,?,?,?,?)');
        $ins->execute([$id, $me['id'], $type, $day, $sdate, $stime, $etime, $note]);
        $_SESSION['teacher_flash'] = '✅ เพิ่มช่วงเวลาว่างสำเร็จ';
    } else {
        $_SESSION['teacher_flash'] = '⚠️ กรุณากรอกเวลาเริ่มและสิ้นสุด';
    }
    header('Location: ' . ABSOLUTE_URL . '/portal/teacher_dashboard.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'del_avail') {
    $sid = trim($_POST['slot_id'] ?? '');
    if ($sid) {
        $pdo->prepare('DELETE FROM sevenj_teacher_availability WHERE id=? AND teacher_id=?')
            ->execute([$sid, $me['id']]);
        $_SESSION['teacher_flash'] = '🗑️ ลบช่วงเวลาว่างสำเร็จ';
    }
    header('Location: ' . ABSOLUTE_URL . '/portal/teacher_dashboard.php');
    exit;
}

// ─── POST: ยื่นใบลา ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_leave') {
    $leaveDate   = trim($_POST['leave_date']   ?? '');
    $leaveDay    = trim($_POST['leave_day']    ?? '');
    $leaveTs     = trim($_POST['leave_time_start'] ?? '');
    $leaveTe     = trim($_POST['leave_time_end']   ?? '');
    $leaveReason = trim($_POST['leave_reason'] ?? '');
    $stuIds      = array_filter(array_map('intval', (array)($_POST['notify_student_ids'] ?? [])));
    if ($leaveDate && $leaveReason) {
        // ดึงชื่อนักเรียนที่เลือก
        $stuNames = '';
        if ($stuIds) {
            $in = implode(',', $stuIds);
            $stuNames = implode(', ', $pdo->query("SELECT displayName FROM sevenj_students WHERE id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
        }
        $ins = $pdo->prepare(
            'INSERT INTO sevenj_leave_requests
             (requester_name, requester_role, requester_id, leave_date, leave_day,
              leave_time_start, leave_time_end, notify_student_ids, notify_student_names, reason, status)
             VALUES (?, "teacher", ?, ?, ?, ?, ?, ?, ?, ?, "pending")'
        );
        $ins->execute([
            $me['displayName'], $me['id'], $leaveDate, $leaveDay ?: null,
            $leaveTs ?: null, $leaveTe ?: null,
            $stuIds ? implode(',', $stuIds) : null,
            $stuNames ?: null,
            $leaveReason
        ]);
        $msg = '📋 ยื่นใบลาสำเร็จ รอการพิจารณาจากแอดมิน';
        if ($stuNames) $msg .= " · แจ้ง: $stuNames";
        $_SESSION['teacher_flash'] = $msg;
    } else {
        $_SESSION['teacher_flash'] = '⚠️ กรุณากรอกวันที่และเหตุผลให้ครบ';
    }
    header('Location: ' . ABSOLUTE_URL . '/portal/teacher_dashboard.php');
    exit;
}

// ─── POST: บันทึกคาบ ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_class') {
    $schedId  = (int)($_POST['schedule_id'] ?? 0);
    $logDate  = trim($_POST['log_date'] ?? date('Y-m-d'));
    $logNote  = trim($_POST['log_note'] ?? '');

    if ($schedId) {
        $sched = $pdo->prepare('SELECT * FROM sevenj_schedule WHERE id = ? AND teacher_ref_id = ?');
        $sched->execute([$schedId, $me['id']]);
        $row = $sched->fetch();

        if ($row) {
            $newDone   = (int)$row['completed_classes'] + 1;
            $sessNum   = $newDone;
            $newStatus = $newDone >= (int)$row['total_classes'] ? 'completed' : 'active';

            // อัปเดต schedule
            $upd = $pdo->prepare('UPDATE sevenj_schedule
                SET completed_classes = ?, status = ? WHERE id = ?');
            $upd->execute([$newDone, $newStatus, $schedId]);

            // อัปเดต completedClasses ใน sevenj_students ด้วย
            if ($row['student_id']) {
                $pdo->prepare('UPDATE sevenj_students
                    SET completedClasses = completedClasses + 1
                    WHERE id = ?')->execute([$row['student_id']]);
            }

            // INSERT sevenj_class_completions
            $ins = $pdo->prepare('INSERT INTO sevenj_class_completions
                (schedule_id, student_id, student_code, student_name,
                 teacher_name, teacher_ref_id,
                 day_of_week, time_start, session_number, completed_date, note)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $ins->execute([
                $schedId,
                $row['student_id'],
                $row['student_code'],
                $row['student_name'],
                $row['teacher_name'],
                $me['id'],
                $row['day_of_week'],
                $row['time_start'],
                $sessNum,
                $logDate,
                $logNote,
            ]);

            $flash = $newStatus === 'completed'
                ? "✅ บันทึกคาบที่ {$sessNum} สำเร็จ 🎉 นักเรียนเรียนครบแล้ว!"
                : "✅ บันทึกคาบที่ {$sessNum} สำเร็จ";
            $_SESSION['teacher_flash'] = $flash;
        }
    }
    // PRG — redirect กลับหน้าเดิมเพื่อป้องกัน double-submit
    header('Location: ' . ABSOLUTE_URL . '/portal/teacher_dashboard.php');
    exit;
}

// ─── POST: บันทึก Google Meet Link + ส่งให้นักเรียน ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_meet_link') {
    $link = trim($_POST['meet_link'] ?? '');
    // บันทึกลงครู
    $pdo->prepare('UPDATE sevenj_teachers SET googleMeetLink=? WHERE id=?')
        ->execute([$link ?: null, $me['id']]);
    // ส่งให้นักเรียนทุกคนที่มีครูนี้เป็นครูประจำ
    if ($link) {
        $pdo->prepare('UPDATE sevenj_students SET googleMeetLink=? WHERE teacherId=?')
            ->execute([$link, $me['id']]);
    }
    $_SESSION['teacher_flash'] = '✅ บันทึก Meet Link สำเร็จ และส่งให้นักเรียนแล้ว';
    header('Location: ' . ABSOLUTE_URL . '/portal/teacher_dashboard.php');
    exit;
}

// ─── Flash message ────────────────────────────────────────────────────────────
$flash = '';
if (!empty($_SESSION['teacher_flash'])) {
    $flash = $_SESSION['teacher_flash'];
    unset($_SESSION['teacher_flash']);
}

// Full teacher record
$stmt = $pdo->prepare('SELECT * FROM sevenj_teachers WHERE id = ?');
$stmt->execute([$me['id']]);
$teacher = $stmt->fetch();

// My students — ดึงจากทั้ง teacherId FK และ sevenj_schedule.teacher_ref_id
$stmtStudents = $pdo->prepare(
    'SELECT DISTINCT st.id, st.studentCode, st.displayName, st.nickname,
            st.totalClasses, st.completedClasses, st.status, st.googleMeetLink,
            (SELECT MAX(sch2.course) FROM sevenj_schedule sch2
             WHERE sch2.student_id = st.id AND sch2.teacher_ref_id = ? AND sch2.status = "active"
             LIMIT 1) AS course,
            (SELECT COUNT(*) FROM sevenj_class_completions cc
             WHERE cc.student_id = st.id AND cc.teacher_ref_id = ?) AS actual_completed
     FROM sevenj_students st
     WHERE st.teacherId = ?
        OR st.id IN (
            SELECT DISTINCT student_id FROM sevenj_schedule
            WHERE teacher_ref_id = ? AND student_id IS NOT NULL AND status = "active"
        )
     ORDER BY st.displayName'
);
$stmtStudents->execute([$me['id'], $me['id'], $me['id'], $me['id']]);
$myStudents = $stmtStudents->fetchAll();

// My active schedules (via teacher_ref_id) — ทุก status เพื่อดูครบ
$stmtSched = $pdo->prepare(
    'SELECT s.*,
            (SELECT COUNT(*) FROM sevenj_class_completions c
             WHERE c.schedule_id = s.id AND c.completed_date = CURDATE()) AS logged_today
     FROM sevenj_schedule s
     WHERE s.teacher_ref_id = ?
     ORDER BY s.status ASC,
              FIELD(s.day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"),
              s.time_start'
);
$stmtSched->execute([$me['id']]);
$schedules = $stmtSched->fetchAll();

// index schedules by student_id → เอา schedule ที่ active ก่อน (สำหรับแสดงสถานะในตาราง นักเรียนของฉัน)
$schedByStudent = [];
foreach ($schedules as $sch) {
    $sid = $sch['student_id'] ?? null;
    if (!$sid) continue;
    if (!isset($schedByStudent[$sid]) || $sch['status'] === 'active') {
        $schedByStudent[$sid] = $sch;
    }
}

// Recent sessions I taught (last 15)
$stmtComp = $pdo->prepare(
    'SELECT * FROM sevenj_class_completions
     WHERE teacher_ref_id = ?
     ORDER BY completed_date DESC, id DESC
     LIMIT 15'
);
$stmtComp->execute([$me['id']]);
$completions = $stmtComp->fetchAll();

// Real-time vars — ต้อง define ก่อน stats ที่ใช้
$thNow    = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
$todayStr = $thNow->format('Y-m-d');
$todayDay = $thNow->format('l');
$nowMins  = (int)$thNow->format('H') * 60 + (int)$thNow->format('i');

// Stats — นับจากทั้ง teacherId (FK) และ teacher_ref_id (schedule)
$stmtCount = $pdo->prepare('SELECT COUNT(DISTINCT student_id) FROM sevenj_schedule WHERE teacher_ref_id=? AND status="active"');
$stmtCount->execute([$me['id']]);
$schedStudentCount = (int)$stmtCount->fetchColumn();
$totalStudents  = max(count($myStudents), $schedStudentCount);
// นับนักเรียนที่กำลังเรียนจริง (real-time — ตรงวันนี้และเวลาตรงปัจจุบัน)
$activeStudents = 0;
foreach ($schedules as $_sch) {
    if ($_sch['status'] !== 'active' || !$_sch['time_start'] || !$_sch['time_end']) continue;
    $_matchDay = ($_sch['schedule_type'] === 'one_time')
        ? ($_sch['specific_date'] === $todayStr)
        : (strtolower($_sch['day_of_week']) === strtolower($todayDay));
    if (!$_matchDay) continue;
    [$_sh,$_sm] = array_pad(explode(':', $_sch['time_start'].':00'), 2, '00');
    [$_eh,$_em] = array_pad(explode(':', $_sch['time_end'].':00'),   2, '00');
    $_sM = (int)$_sh*60+(int)$_sm;
    $_eM = (int)$_eh*60+(int)$_em;
    if ($nowMins >= 720 && (int)$_sh < 12) $_sM += 720;
    if ($nowMins >= 720 && (int)$_eh < 12) $_eM += 720;
    if ($nowMins >= $_sM && $nowMins <= $_eM) $activeStudents++;
}
$stmtTotalSess  = $pdo->prepare('SELECT COUNT(*) FROM sevenj_class_completions WHERE teacher_ref_id = ?');
$stmtTotalSess->execute([$me['id']]);
$totalSessionsAll = (int)$stmtTotalSess->fetchColumn();
// ถ้าไม่มีจาก completions ให้นับจาก completed_classes ใน schedule
if ($totalSessionsAll === 0) {
    $stmtSC = $pdo->prepare('SELECT SUM(completed_classes) FROM sevenj_schedule WHERE teacher_ref_id=?');
    $stmtSC->execute([$me['id']]);
    $totalSessionsAll = (int)$stmtSC->fetchColumn();
}
$teachingStatus = 'รอสอน';
$teachingStatusColor = 'bg-blue-100 text-blue-700';
// ตรวจตารางวันนี้
foreach ($schedules as $sch) {
    if (!$sch['time_start'] || !$sch['time_end']) continue;
    $matchDay = ($sch['schedule_type'] === 'one_time')
        ? ($sch['specific_date'] === $todayStr)
        : (strtolower($sch['day_of_week']) === strtolower($todayDay));
    if (!$matchDay) continue;
    [$sh,$sm] = explode(':', $sch['time_start'].':00');
    [$eh,$em] = explode(':', $sch['time_end'].':00');
    $startM = (int)$sh*60+(int)$sm;
    $endM   = (int)$eh*60+(int)$em;
    if ($nowMins >= $startM && $nowMins <= $endM) {
        $teachingStatus = 'กำลังสอน'; $teachingStatusColor = 'bg-green-100 text-green-700'; break;
    }
}

// เวลาว่างของครู
$stmtAvail = $pdo->prepare(
    'SELECT * FROM sevenj_teacher_availability WHERE teacher_id=?
     ORDER BY type DESC, day, specific_date, start_time'
);
$stmtAvail->execute([$me['id']]);
$myAvails = $stmtAvail->fetchAll();

// ประวัติใบลาของครู (5 รายการล่าสุด)
$stmtLeave = $pdo->prepare(
    'SELECT id, leave_date, leave_day, leave_time_start, leave_time_end, reason, status, review_note, created_at
     FROM sevenj_leave_requests
     WHERE requester_id = ? AND requester_role = "teacher"
     ORDER BY created_at DESC LIMIT 5'
);
$stmtLeave->execute([$me['id']]);
$myLeaves = $stmtLeave->fetchAll();

// ใบลาจากนักเรียนที่แจ้งครูนี้
$stuLeavesForMe = $pdo->prepare(
    'SELECT lr.*, st.displayName AS stu_display, st.studentCode AS stu_code
     FROM sevenj_leave_requests lr
     LEFT JOIN sevenj_students st ON st.id = lr.requester_id
     WHERE lr.requester_role = "student"
       AND FIND_IN_SET(?, COALESCE(lr.notify_teacher_ids,""))
     ORDER BY lr.leave_date DESC, lr.created_at DESC
     LIMIT 10'
);
$stuLeavesForMe->execute([$me['id']]);
$stuLeavesForMe = $stuLeavesForMe->fetchAll();

$dayTH = [
    'Monday'    => 'จันทร์',
    'Tuesday'   => 'อังคาร',
    'Wednesday' => 'พุธ',
    'Thursday'  => 'พฤหัสบดี',
    'Friday'    => 'ศุกร์',
    'Saturday'  => 'เสาร์',
    'Sunday'    => 'อาทิตย์',
];

$statusColors = ['active' => 'bg-green-100 text-green-700', 'inactive' => 'bg-gray-100 text-gray-500', 'pending' => 'bg-yellow-100 text-yellow-700'];
$statusLabels = ['active' => 'กำลังเรียน', 'inactive' => 'หยุดพัก', 'pending' => 'รอ'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Teacher Dashboard — <?= htmlspecialchars($teacher['displayName']) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <style>
    body { font-family: 'Sarabun', 'Segoe UI', sans-serif; background: #fffbeb; }
    .brand-gradient { background: linear-gradient(135deg, #92400e 0%, #c27016 50%, #d97706 100%); }
    .progress-bar { transition: width 0.8s ease; }
  </style>
</head>
<body class="min-h-screen bg-amber-50">

<!-- ───── TOP NAV ───── -->
<header class="brand-gradient shadow-lg">
  <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <img src="<?= ABSOLUTE_URL ?>/uploads/logo_7j.png"
           alt="7J" class="h-9 w-9 object-contain"
           onerror="this.style.display='none'">
      <div>
        <div class="text-white font-bold text-base leading-tight">7J English Center</div>
        <div class="text-amber-200 text-xs">Teacher Portal</div>
      </div>
    </div>
    <div class="flex items-center gap-4">
      <span class="text-amber-200 text-sm hidden sm:block">
        สวัสดี, <span class="text-white font-medium"><?= htmlspecialchars($teacher['nickname'] ?: $teacher['displayName']) ?></span>
      </span>
      <button onclick="openLeaveModal()"
              class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white text-xs font-medium
                     px-3 py-1.5 rounded-lg transition flex items-center gap-1">
        📋 ยื่นใบลา
      </button>
      <a href="<?= ABSOLUTE_URL ?>/portal/teacher_logout.php"
         class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white text-xs font-medium
                px-3 py-1.5 rounded-lg transition flex items-center gap-1">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
        </svg>
        ออกจากระบบ
      </a>
    </div>
  </div>
</header>

<!-- ───── MAIN CONTENT ───── -->
<main class="max-w-6xl mx-auto px-4 py-6 space-y-5">

  <?php if ($flash): ?>
  <div class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-5 py-3 flex items-center gap-3 text-sm font-medium shadow-sm">
    <svg class="w-5 h-5 flex-shrink-0 text-green-500" fill="currentColor" viewBox="0 0 20 20">
      <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
    </svg>
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>

  <!-- ── Row 1: Profile + Stats ── -->
  <div class="grid grid-cols-1 md:grid-cols-4 gap-5">

    <!-- Profile Card -->
    <div class="bg-white rounded-2xl shadow-sm border border-amber-100 p-5">
      <div class="flex items-center gap-3 mb-4">
        <div class="w-12 h-12 rounded-full brand-gradient flex items-center justify-center text-white text-xl font-bold flex-shrink-0">
          <?= mb_substr($teacher['displayName'], 0, 1) ?>
        </div>
        <div class="min-w-0">
          <div class="font-bold text-gray-800 truncate"><?= htmlspecialchars($teacher['displayName']) ?></div>
          <?php if ($teacher['nickname']): ?>
          <div class="text-sm text-gray-400">"<?= htmlspecialchars($teacher['nickname']) ?>"</div>
          <?php endif; ?>
        </div>
      </div>
      <div class="space-y-1.5 text-sm">
        <?php if ($teacher['teacherCode']): ?>
        <div class="flex justify-between">
          <span class="text-gray-500">รหัส</span>
          <span class="font-mono text-gray-700"><?= htmlspecialchars($teacher['teacherCode']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($teacher['location']): ?>
        <div class="flex justify-between">
          <span class="text-gray-500">สถานที่</span>
          <span class="text-gray-700 text-right"><?= htmlspecialchars($teacher['location']) ?></span>
        </div>
        <?php endif; ?>
        <div class="flex justify-between items-center">
          <span class="text-gray-500">สถานะ</span>
          <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?= $teachingStatusColor ?>">
            <?= $teachingStatus ?>
          </span>
        </div>
      </div>

      <!-- ลิ้งก์จาก Admin -->
      <div style="margin-top:12px;padding-top:12px;border-top:1px solid #fde68a;">
        <div style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">
          📹 ลิ้งก์ห้องเรียนจาก Admin
        </div>
        <?php if ($teacher['googleMeetLink']): ?>
        <a href="<?= htmlspecialchars($teacher['googleMeetLink']) ?>" target="_blank" rel="noopener"
           style="display:flex;align-items:center;justify-content:center;gap:8px;
                  padding:10px 16px;border-radius:10px;font-weight:700;font-size:.88rem;
                  color:#fff;text-decoration:none;
                  background:linear-gradient(135deg,#1d4ed8,#3b82f6);
                  box-shadow:0 3px 10px rgba(59,130,246,.4);transition:opacity .15s;">
          📹 กดเข้าห้องเรียน
        </a>
        <p style="font-size:.7rem;color:#9ca3af;text-align:center;margin-top:5px;word-break:break-all;">
          <?= htmlspecialchars($teacher['googleMeetLink']) ?>
        </p>
        <?php else: ?>
        <div style="background:#f3f4f6;border-radius:8px;padding:10px;text-align:center;color:#9ca3af;font-size:.82rem;">
          ⏳ ยังไม่ได้รับลิ้งก์จาก Admin
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Stat Cards (3 cols) -->
    <div class="md:col-span-3 grid grid-cols-3 gap-4">
      <div class="bg-white rounded-2xl shadow-sm border border-amber-100 p-5 flex flex-col items-center justify-center text-center">
        <div class="text-4xl font-bold text-amber-600"><?= $totalStudents ?></div>
        <div class="text-xs text-gray-400 mt-1">นักเรียนทั้งหมด</div>
      </div>
      <div class="bg-white rounded-2xl shadow-sm border border-amber-100 p-5 flex flex-col items-center justify-center text-center">
        <div class="text-4xl font-bold text-green-600"><?= $activeStudents ?></div>
        <div class="text-xs text-gray-400 mt-1">กำลังเรียน</div>
      </div>
      <div class="bg-white rounded-2xl shadow-sm border border-amber-100 p-5 flex flex-col items-center justify-center text-center">
        <div class="text-4xl font-bold text-violet-600"><?= $totalSessionsAll ?></div>
        <div class="text-xs text-gray-400 mt-1">คาบที่สอนทั้งหมด</div>
      </div>
    </div>
  </div>

  <!-- ── Row 2: My Students ── -->
  <div class="bg-white rounded-2xl shadow-sm border border-amber-100 p-5" x-data="{search:''}">
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide">นักเรียนของฉัน</h3>
    </div>

    <?php if ($myStudents): ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-gray-100 text-xs text-gray-400 uppercase">
            <th class="pb-2 text-left font-medium">นักเรียน</th>
            <th class="pb-2 text-left font-medium hidden sm:table-cell">รหัส</th>
            <th class="pb-2 text-center font-medium">สถานะ</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($myStudents as $s): ?>
          <tr class="hover:bg-amber-50 transition">
            <td class="py-2.5">
              <div class="font-medium text-gray-800"><?= htmlspecialchars($s['displayName']) ?></div>
              <div class="text-xs text-gray-400">
                <?php if ($s['nickname']): ?>"<?= htmlspecialchars($s['nickname']) ?>"<?php endif; ?>
                <?php if ($s['course']): ?><span class="ml-1 text-amber-600">📚 <?= htmlspecialchars($s['course']) ?></span><?php endif; ?>
              </div>
            </td>
            <td class="py-2.5 font-mono text-gray-400 text-xs hidden sm:table-cell">
              <?= htmlspecialchars($s['studentCode'] ?: '—') ?>
            </td>
            <td class="py-2.5 text-center">
              <?php
                $stuSch = $schedByStudent[$s['id']] ?? null;
                if (!$stuSch || $stuSch['status'] === 'cancelled') {
                    $stuStLbl = '❌ ยกเลิก'; $stuStBg = '#fee2e2'; $stuStFg = '#991b1b';
                } elseif ($stuSch['status'] === 'completed') {
                    $stuStLbl = '✅ ครบแล้ว'; $stuStBg = '#fef3c7'; $stuStFg = '#92400e';
                } else {
                    // active — ตรวจวันนี้
                    $mDay = ($stuSch['schedule_type'] === 'one_time')
                        ? ($stuSch['specific_date'] === $todayStr)
                        : (strtolower($stuSch['day_of_week']) === strtolower($todayDay));
                    if (!$mDay) {
                        $stuStLbl = '📚 เปิดสอน'; $stuStBg = '#dbeafe'; $stuStFg = '#1e40af';
                    } else {
                        [$sh,$sm] = array_pad(explode(':', $stuSch['time_start'].':00'), 2, '00');
                        [$eh,$em] = array_pad(explode(':', ($stuSch['time_end'] ?? '00:00').':00'), 2, '00');
                        $sM = (int)$sh*60+(int)$sm; $eM = (int)$eh*60+(int)$em;
                        if ($nowMins >= 720 && (int)$sh < 12) $sM += 720;
                        if ($nowMins >= 720 && (int)$eh < 12) $eM += 720;
                        if ((int)($stuSch['logged_today'] ?? 0) > 0) {
                            $stuStLbl = '✅ เรียนแล้ว'; $stuStBg = '#dcfce7'; $stuStFg = '#166534';
                        } elseif ($nowMins < $sM) {
                            $stuStLbl = '⏳ รอสอน';    $stuStBg = '#fef9c3'; $stuStFg = '#713f12';
                        } elseif ($nowMins <= $eM) {
                            $stuStLbl = '🟢 กำลังเรียน'; $stuStBg = '#d1fae5'; $stuStFg = '#065f46';
                        } else {
                            $stuStLbl = '⛔ หมดเวลา'; $stuStBg = '#f3f4f6'; $stuStFg = '#6b7280';
                        }
                    }
                }
              ?>
              <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold"
                    style="background:<?= $stuStBg ?>;color:<?= $stuStFg ?>;">
                <?= $stuStLbl ?>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <p class="text-sm text-gray-400 py-4 text-center">ยังไม่มีนักเรียนที่กำหนดให้คุณ</p>
    <?php endif; ?>
  </div>

  <!-- ── Row 3: Schedule + Recent Sessions ── -->
  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

    <!-- My Schedule -->
    <div class="bg-white rounded-2xl shadow-sm border border-amber-100 p-5">
      <h3 class="text-sm font-semibold text-gray-600 mb-3 uppercase tracking-wide">ตารางสอนของฉัน</h3>
      <?php if ($schedules): ?>
      <div class="space-y-2">
        <?php foreach ($schedules as $sch):
          $schDone   = (int)$sch['completed_classes'];
          $schTotal  = (int)$sch['total_classes'];
          $schPct    = $schTotal > 0 ? round($schDone / $schTotal * 100) : 0;
          $schRemain = max(0, $schTotal - $schDone);

          // ── real-time status ───────────────────────────────────────────
          $matchDay = ($sch['schedule_type'] === 'one_time')
              ? ($sch['specific_date'] === $todayStr)
              : (strtolower($sch['day_of_week']) === strtolower($todayDay));

          if ($sch['status'] === 'completed') {
              $schStatusLbl = '✅ ครบแล้ว';
              $schStatusBg  = '#fef3c7'; $schStatusFg = '#92400e';
          } elseif ($sch['status'] === 'cancelled') {
              $schStatusLbl = '❌ ยกเลิก';
              $schStatusBg  = '#fee2e2'; $schStatusFg = '#991b1b';
          } elseif (!$matchDay) {
              $schStatusLbl = '📚 เปิดสอน';
              $schStatusBg  = '#dbeafe'; $schStatusFg = '#1e40af';
          } else {
              // วันนี้ — ตรวจเวลา
              [$sh,$sm] = array_pad(explode(':', $sch['time_start'].':00'), 2, '00');
              [$eh,$em] = array_pad(explode(':', ($sch['time_end'] ?? '00:00').':00'), 2, '00');
              $startM = (int)$sh*60+(int)$sm;
              $endM   = (int)$eh*60+(int)$em;
              if ($nowMins >= 720 && (int)$sh < 12) $startM += 720;
              if ($nowMins >= 720 && (int)$eh < 12) $endM   += 720;

              if ((int)($sch['logged_today'] ?? 0) > 0) {
                  $schStatusLbl = '✅ เรียนแล้ว';
                  $schStatusBg  = '#dcfce7'; $schStatusFg = '#166534';
              } elseif ($nowMins < $startM) {
                  $schStatusLbl = '⏳ รอสอน';
                  $schStatusBg  = '#fef9c3'; $schStatusFg = '#713f12';
              } elseif ($nowMins <= $endM) {
                  $schStatusLbl = '🟢 กำลังเรียน';
                  $schStatusBg  = '#d1fae5'; $schStatusFg = '#065f46';
              } else {
                  $schStatusLbl = '⛔ หมดเวลา';
                  $schStatusBg  = '#f3f4f6'; $schStatusFg = '#6b7280';
              }
          }
        ?>
        <div class="rounded-xl px-4 py-3 text-sm <?= $sch['status']==='active' ? 'bg-amber-50 border border-amber-100' : 'bg-gray-50 border border-gray-100 opacity-75' ?>">
          <div class="flex items-center gap-3">
            <!-- วัน/วันที่ -->
            <div class="w-14 text-center flex-shrink-0">
              <?php if ($sch['schedule_type'] === 'weekly'): ?>
              <span class="inline-block bg-amber-200 text-amber-900 text-xs font-semibold px-2 py-0.5 rounded-full">
                <?= $dayTH[$sch['day_of_week']] ?? $sch['day_of_week'] ?>
              </span>
              <?php else: ?>
              <span class="inline-block bg-blue-100 text-blue-700 text-xs font-semibold px-1.5 py-0.5 rounded-full">
                <?= $sch['specific_date'] ?>
              </span>
              <?php endif; ?>
            </div>

            <!-- เวลา -->
            <div class="font-mono text-gray-500 w-20 flex-shrink-0 text-xs">
              <?= htmlspecialchars($sch['time_start']) ?>
              <?= $sch['time_end'] ? '–'.htmlspecialchars($sch['time_end']) : '' ?>
            </div>

            <!-- นักเรียน + คอร์ส -->
            <div class="min-w-0 flex-1">
              <div class="font-semibold text-gray-800 truncate"><?= htmlspecialchars($sch['student_name'] ?: '—') ?></div>
              <div class="text-xs text-gray-400"><?= htmlspecialchars($sch['course'] ?: 'English') ?></div>
            </div>

            <!-- คาบ + progress -->
            <div class="text-right flex-shrink-0 hidden sm:block">
              <div class="text-xs font-semibold text-gray-600"><?= $schDone ?>/<?= $schTotal ?> คาบ</div>
              <div class="h-1.5 w-20 bg-gray-200 rounded-full overflow-hidden mt-1">
                <div class="h-full rounded-full <?= $schPct>=100?'bg-amber-500':($schPct>=50?'bg-amber-400':'bg-green-400') ?>"
                     style="width:<?= $schPct ?>%"></div>
              </div>
              <div class="text-xs text-gray-400 mt-0.5">เหลือ <?= $schRemain ?></div>
            </div>

            <!-- Status badge -->
            <div class="flex flex-col items-end gap-1.5 flex-shrink-0">
              <span class="text-xs font-semibold px-2 py-0.5 rounded-full"
                    style="background:<?= $schStatusBg ?>;color:<?= $schStatusFg ?>;">
                <?= $schStatusLbl ?>
              </span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p class="text-sm text-gray-400 py-4 text-center">ยังไม่มีตารางสอน</p>
      <?php endif; ?>
    </div>

    <!-- Recent Sessions -->
    <div class="bg-white rounded-2xl shadow-sm border border-amber-100 p-5">
      <h3 class="text-sm font-semibold text-gray-600 mb-3 uppercase tracking-wide">
        คาบล่าสุดที่สอน
      </h3>
      <?php if ($completions): ?>
      <div class="space-y-2 overflow-y-auto max-h-80">
        <?php foreach ($completions as $c): ?>
        <div class="flex items-start gap-3 py-2 border-b border-gray-50 last:border-0">
          <div class="w-8 h-8 rounded-full bg-amber-100 flex items-center justify-center text-amber-700 font-bold text-xs flex-shrink-0 mt-0.5">
            #<?= $c['session_number'] ?: '?' ?>
          </div>
          <div class="min-w-0 flex-1">
            <div class="font-medium text-gray-700 text-sm truncate"><?= htmlspecialchars($c['student_name'] ?: '—') ?></div>
            <div class="text-xs text-gray-400">
              <?= $c['completed_date'] ? date('d M Y', strtotime($c['completed_date'])) : '—' ?>
              <?php if ($c['day_of_week']): ?>
              · <?= $dayTH[$c['day_of_week']] ?? $c['day_of_week'] ?>
              <?= $c['time_start'] ? $c['time_start'] : '' ?>
              <?php endif; ?>
            </div>
            <?php if ($c['note']): ?>
            <div class="text-xs text-gray-400 mt-0.5 truncate italic"><?= htmlspecialchars($c['note']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p class="text-sm text-gray-400 py-4 text-center">ยังไม่มีประวัติการสอน</p>
      <?php endif; ?>
    </div>
  </div>

<footer class="text-center py-6 text-xs text-gray-400">
  © 7J English Center — Global Language Institute Online
</footer>


<!-- ── ใบลาจากนักเรียน ── -->
<?php if (!empty($stuLeavesForMe)):
$slDayTh = ['Monday'=>'จันทร์','Tuesday'=>'อังคาร','Wednesday'=>'พุธ','Thursday'=>'พฤหัสบดี','Friday'=>'ศุกร์','Saturday'=>'เสาร์','Sunday'=>'อาทิตย์'];
?>
<div class="bg-white rounded-2xl shadow-sm border border-blue-100 p-5 mb-5">
  <div class="flex items-center gap-2 mb-3">
    <span style="font-size:1.1rem;">🎓</span>
    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">ใบลาจากนักเรียน</h3>
    <span style="background:#dbeafe;color:#1e40af;border-radius:99px;padding:1px 9px;font-size:.72rem;font-weight:700;margin-left:4px;">
      <?= count($stuLeavesForMe) ?> รายการ
    </span>
  </div>
  <div class="space-y-3">
  <?php foreach ($stuLeavesForMe as $sl):
    $slSt = match($sl['status']) {
        'approved' => ['✅ อนุมัติแล้ว','#dcfce7','#166534'],
        'rejected' => ['❌ ไม่อนุมัติ','#fee2e2','#991b1b'],
        default    => ['⏳ รออนุมัติ','#fef9c3','#713f12'],
    };
  ?>
  <div style="background:#f0f9ff;border-radius:10px;padding:10px 14px;border-left:3px solid #60a5fa;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
      <div>
        <div style="font-weight:700;color:#1f2937;font-size:.88rem;">
          🎓 <?= htmlspecialchars($sl['stu_display'] ?? $sl['requester_name']) ?>
          <span style="color:#9ca3af;font-size:.75rem;font-weight:400;margin-left:4px;"><?= htmlspecialchars($sl['stu_code'] ?? '') ?></span>
        </div>
        <div style="font-size:.8rem;color:#6b7280;margin-top:3px;">
          <?php if ($sl['leave_day']): ?>
            <span style="background:#eff6ff;color:#1e40af;border-radius:4px;padding:1px 6px;font-size:.75rem;font-weight:600;margin-right:4px;">
              วัน<?= $slDayTh[$sl['leave_day']] ?? $sl['leave_day'] ?>
            </span>
          <?php endif; ?>
          📅 <?= date('d/m/Y', strtotime($sl['leave_date'])) ?>
          <?php if ($sl['leave_time_start']): ?>
            · 🕐 <?= htmlspecialchars($sl['leave_time_start']) ?>
            <?= $sl['leave_time_end'] ? '–'.htmlspecialchars($sl['leave_time_end']) : '' ?>
          <?php endif; ?>
        </div>
        <div style="font-size:.8rem;color:#374151;margin-top:4px;">💬 <?= htmlspecialchars($sl['reason']) ?></div>
      </div>
      <span style="background:<?= $slSt[1] ?>;color:<?= $slSt[2] ?>;border-radius:99px;padding:2px 10px;font-size:.72rem;font-weight:700;white-space:nowrap;flex-shrink:0;">
        <?= $slSt[0] ?>
      </span>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── ประวัติใบลาของฉัน ── -->
<?php if ($myLeaves): ?>
<div class="bg-white rounded-2xl shadow-sm border border-amber-100 p-5">
  <h3 class="text-sm font-semibold text-gray-600 mb-3 uppercase tracking-wide">📋 ใบลาของฉัน</h3>
  <div class="space-y-2">
    <?php foreach ($myLeaves as $lv):
      $lvStatus = match($lv['status']) {
        'approved' => ['bg-green-100 text-green-700', '✅ อนุมัติ'],
        'rejected' => ['bg-red-100 text-red-700',   '❌ ปฏิเสธ'],
        default    => ['bg-yellow-100 text-yellow-700','⏳ รอพิจารณา'],
      };
    ?>
    <div class="flex items-start gap-3 py-2 border-b border-gray-50 last:border-0">
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
          <span class="text-sm font-medium text-gray-700">
            <?= date('d/m/Y', strtotime($lv['leave_date'])) ?>
          </span>
          <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?= $lvStatus[0] ?>">
            <?= $lvStatus[1] ?>
          </span>
        </div>
        <div class="text-xs text-gray-400 mt-0.5 truncate"><?= htmlspecialchars($lv['reason']) ?></div>
        <?php if ($lv['review_note'] && $lv['status'] !== 'pending'): ?>
        <div class="text-xs text-violet-600 mt-0.5">💬 <?= htmlspecialchars($lv['review_note']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

</main>


<!-- ───── Leave Request Modal ───── -->
<div id="leave-modal-bg"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center;padding:16px;"
     onclick="if(event.target===this)closeLeaveModal()">
  <div style="background:#fff;border-radius:18px;width:100%;max-width:500px;max-height:92vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,.25);">
    <div style="background:linear-gradient(135deg,#92400e,#d97706);padding:18px 22px;position:sticky;top:0;z-index:1;">
      <div style="color:#fff;font-weight:700;font-size:1.05rem;">📋 ยื่นใบลา</div>
      <div style="color:#fde68a;font-size:.85rem;margin-top:2px;"><?= htmlspecialchars($teacher['displayName']) ?></div>
    </div>
    <form method="POST" style="padding:20px 22px;">
      <input type="hidden" name="action" value="submit_leave">

      <!-- วันที่ (auto-fill วันในสัปดาห์) -->
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:5px;">📅 วันที่ขอลา</label>
        <input type="hidden" name="leave_day" id="leave-day">
        <div style="position:relative;">
          <input type="date" name="leave_date" id="leave-date" required
                 oninput="updateLeaveDay(this.value)"
                 style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:9px;font-size:.88rem;box-sizing:border-box;outline:none;">
          <span id="leave-day-lbl" style="position:absolute;right:40px;top:50%;transform:translateY(-50%);font-size:.78rem;color:#d97706;font-weight:600;pointer-events:none;"></span>
        </div>
      </div>

      <!-- เวลาเริ่ม–สิ้นสุด -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:5px;">🕐 เวลาเริ่ม</label>
          <input type="time" name="leave_time_start" id="leave-ts"
                 style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:9px;font-size:.88rem;box-sizing:border-box;outline:none;">
        </div>
        <div>
          <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:5px;">🕐 เวลาสิ้นสุด</label>
          <input type="time" name="leave_time_end" id="leave-te"
                 style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:9px;font-size:.88rem;box-sizing:border-box;outline:none;">
        </div>
      </div>

      <!-- เลือกนักเรียน -->
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:5px;">🎓 แจ้งนักเรียน (เลือกได้หลายคน)</label>
        <div style="border:1px solid #d1d5db;border-radius:9px;overflow:hidden;">
          <input type="text" id="leave-stu-search" placeholder="ค้นหาชื่อนักเรียน..."
                 oninput="filterLeaveStudents(this.value)"
                 style="width:100%;padding:8px 12px;border:none;border-bottom:1px solid #e5e7eb;font-size:.85rem;box-sizing:border-box;outline:none;">
          <div id="leave-stu-list" style="max-height:140px;overflow-y:auto;padding:6px 8px;">
            <?php foreach ($myStudents as $ls): ?>
            <label style="display:flex;align-items:center;gap:8px;padding:5px 4px;cursor:pointer;border-radius:6px;font-size:.85rem;"
                   onmouseover="this.style.background='#fffbeb'" onmouseout="this.style.background=''">
              <input type="checkbox" name="notify_student_ids[]" value="<?= (int)$ls['id'] ?>"
                     class="leave-stu-cb" style="accent-color:#d97706;">
              <span class="leave-stu-name"><?= htmlspecialchars($ls['displayName']) ?></span>
              <span style="color:#9ca3af;font-size:.75rem;margin-left:auto;"><?= htmlspecialchars($ls['studentCode']) ?></span>
            </label>
            <?php endforeach; ?>
            <?php if (empty($myStudents)): ?>
            <p style="font-size:.82rem;color:#9ca3af;text-align:center;padding:8px;">ไม่มีนักเรียนในการดูแล</p>
            <?php endif; ?>
          </div>
        </div>
        <div id="leave-stu-selected" style="margin-top:5px;font-size:.75rem;color:#d97706;min-height:16px;"></div>
      </div>

      <!-- เหตุผล -->
      <div style="margin-bottom:16px;">
        <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:5px;">💬 เหตุผลการลา</label>
        <textarea name="leave_reason" rows="3" required
                  placeholder="เช่น ป่วย, ธุระส่วนตัว, นัดหมายแพทย์..."
                  style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:9px;font-size:.88rem;box-sizing:border-box;outline:none;resize:vertical;font-family:inherit;"></textarea>
      </div>

      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 12px;margin-bottom:16px;font-size:.8rem;color:#92400e;">
        ⚠️ ใบลาจะถูกส่งให้แอดมินพิจารณา และนักเรียนที่เลือกจะได้รับการแจ้งเตือน
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeLeaveModal()"
                style="padding:9px 20px;border-radius:9px;border:1px solid #d1d5db;background:#fff;font-size:.88rem;font-weight:600;cursor:pointer;">
          ยกเลิก
        </button>
        <button type="submit"
                style="padding:9px 24px;border-radius:9px;border:none;background:linear-gradient(135deg,#92400e,#d97706);color:#fff;font-size:.88rem;font-weight:700;cursor:pointer;">
          ✓ ยื่นใบลา
        </button>
      </div>
    </form>
  </div>
</div>

<script>
var LEAVE_DAYS_EN = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
var LEAVE_DAYS_TH = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
function updateLeaveDay(val) {
    if (!val) { document.getElementById('leave-day').value=''; document.getElementById('leave-day-lbl').textContent=''; return; }
    var d = new Date(val + 'T12:00:00');
    var idx = d.getDay();
    document.getElementById('leave-day').value = LEAVE_DAYS_EN[idx];
    document.getElementById('leave-day-lbl').textContent = 'วน' + LEAVE_DAYS_TH[idx];
}
function openLeaveModal() {
    var d = new Date();
    var dateStr = d.toISOString().slice(0,10);
    document.getElementById('leave-date').value = dateStr;
    updateLeaveDay(dateStr);
    document.querySelectorAll('.leave-stu-cb').forEach(function(cb){ cb.checked = false; });
    document.getElementById('leave-stu-search').value = '';
    document.getElementById('leave-stu-selected').textContent = '';
    filterLeaveStudents('');
    document.getElementById('leave-modal-bg').style.display = 'flex';
}
function closeLeaveModal() {
    document.getElementById('leave-modal-bg').style.display = 'none';
}
function filterLeaveStudents(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#leave-stu-list label').forEach(function(lbl) {
        var name = lbl.querySelector('.leave-stu-name').textContent.toLowerCase();
        lbl.style.display = name.includes(q) ? '' : 'none';
    });
}
document.addEventListener('change', function(e) {
    if (!e.target.classList.contains('leave-stu-cb')) return;
    var names = [];
    document.querySelectorAll('.leave-stu-cb:checked').forEach(function(cb) {
        names.push(cb.closest('label').querySelector('.leave-stu-name').textContent);
    });
    var el = document.getElementById('leave-stu-selected');
    el.textContent = names.length ? '✓ เลือก: ' + names.join(', ') : '';
});
</script>

<!-- ───── Log Class Modal ───── -->
<div id="log-modal-bg"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center;padding:16px;"
     onclick="if(event.target===this)closeLogModal()">
  <div style="background:#fff;border-radius:18px;width:100%;max-width:440px;box-shadow:0 24px 64px rgba(0,0,0,.25);overflow:hidden;">

    <!-- Modal header -->
    <div style="background:linear-gradient(135deg,#92400e,#d97706);padding:18px 22px;">
      <div style="color:#fff;font-weight:700;font-size:1.05rem;">✅ บันทึกคาบเรียน</div>
      <div id="log-modal-subtitle" style="color:#fde68a;font-size:.85rem;margin-top:2px;"></div>
    </div>

    <form method="POST" style="padding:20px 22px;">
      <input type="hidden" name="action" value="log_class">
      <input type="hidden" name="schedule_id" id="log-schedule-id">

      <!-- Session info -->
      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;margin-bottom:16px;font-size:.85rem;">
        <span style="color:#92400e;">คาบที่กำลังบันทึก:</span>
        <span id="log-session-num" style="font-weight:700;color:#d97706;font-size:1.1rem;margin-left:8px;"></span>
      </div>

      <!-- Date -->
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:5px;">
          📅 วันที่เรียน
        </label>
        <input type="date" name="log_date" id="log-date"
               style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:9px;
                      font-size:.9rem;box-sizing:border-box;outline:none;"
               onfocus="this.style.borderColor='#d97706'"
               onblur="this.style.borderColor='#d1d5db'">
      </div>

      <!-- Note -->
      <div style="margin-bottom:18px;">
        <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:5px;">
          📝 หมายเหตุ (ไม่บังคับ)
        </label>
        <textarea name="log_note" rows="3"
                  placeholder="เช่น เรียนหัวข้อ Tense, นักเรียนตั้งใจมาก, ยังต้องทบทวน Vocab..."
                  style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:9px;
                         font-size:.88rem;box-sizing:border-box;outline:none;resize:vertical;font-family:inherit;"
                  onfocus="this.style.borderColor='#d97706'"
                  onblur="this.style.borderColor='#d1d5db'"></textarea>
      </div>

      <!-- Buttons -->
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeLogModal()"
                style="padding:9px 20px;border-radius:9px;border:1px solid #d1d5db;
                       background:#fff;font-size:.88rem;font-weight:600;cursor:pointer;color:#374151;">
          ยกเลิก
        </button>
        <button type="submit"
                style="padding:9px 24px;border-radius:9px;border:none;
                       background:linear-gradient(135deg,#92400e,#d97706);
                       color:#fff;font-size:.88rem;font-weight:700;cursor:pointer;">
          ✓ บันทึกคาบ
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openLogModal(scheduleId, studentName, sessionNum, course) {
    document.getElementById('log-schedule-id').value = scheduleId;
    document.getElementById('log-modal-subtitle').textContent =
        studentName + (course ? ' — ' + course : '');
    document.getElementById('log-session-num').textContent = sessionNum;
    document.getElementById('log-date').value = new Date().toISOString().slice(0, 10);
    var bg = document.getElementById('log-modal-bg');
    bg.style.display = 'flex';
    setTimeout(function() { bg.querySelector('textarea').focus(); }, 100);
}
function closeLogModal() {
    document.getElementById('log-modal-bg').style.display = 'none';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLogModal();
});
</script>

</body>
</html>
