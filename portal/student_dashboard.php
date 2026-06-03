<?php
require_once __DIR__ . '/_auth.php';
$me = require_student_login();

$pdo = portal_db();

// ─── POST: ยื่นใบลา ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_leave') {
    $leaveDate   = trim($_POST['leave_date']   ?? '');
    $leaveDay    = trim($_POST['leave_day']    ?? '');
    $leaveTs     = trim($_POST['leave_time_start'] ?? '');
    $leaveTe     = trim($_POST['leave_time_end']   ?? '');
    $leaveReason = trim($_POST['leave_reason'] ?? '');
    $teaIds      = array_filter(array_map('intval', (array)($_POST['notify_teacher_ids'] ?? [])));
    if ($leaveDate && $leaveReason) {
        $teaNames = '';
        if ($teaIds) {
            $in = implode(',', $teaIds);
            $teaNames = implode(', ', $pdo->query("SELECT displayName FROM sevenj_teachers WHERE id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
        }
        $ins = $pdo->prepare(
            'INSERT INTO sevenj_leave_requests
             (requester_name, requester_role, requester_id, leave_date, leave_day,
              leave_time_start, leave_time_end, notify_teacher_ids, notify_teacher_names, reason, status)
             VALUES (?, "student", ?, ?, ?, ?, ?, ?, ?, ?, "pending")'
        );
        $ins->execute([
            $me['displayName'], $me['id'], $leaveDate, $leaveDay ?: null,
            $leaveTs ?: null, $leaveTe ?: null,
            $teaIds ? implode(',', $teaIds) : null,
            $teaNames ?: null,
            $leaveReason
        ]);
        $msg = '📋 ยื่นใบลาสำเร็จ รอการพิจารณาจากแอดมิน';
        if ($teaNames) $msg .= " · แจ้งครู: $teaNames";
        $_SESSION['student_flash'] = $msg;
    } else {
        $_SESSION['student_flash'] = '⚠️ กรุณากรอกวันที่และเหตุผลให้ครบ';
    }
    header('Location: ' . ABSOLUTE_URL . '/portal/student_dashboard.php');
    exit;
}

// ─── ครูที่สอนนักเรียนคนนี้ (สำหรับ form ยื่นใบลา) ──────────────────────────────
$myTeachers = $pdo->prepare(
    'SELECT DISTINCT t.id, t.displayName, t.teacherCode
     FROM sevenj_teachers t
     WHERE t.id = (SELECT teacherId FROM sevenj_students WHERE id = ? LIMIT 1)
        OR t.id IN (SELECT DISTINCT teacher_ref_id FROM sevenj_schedule
                    WHERE student_id = ? AND status = "active" AND teacher_ref_id IS NOT NULL)
     ORDER BY t.displayName'
);
$myTeachers->execute([$me['id'], $me['id']]);
$myTeachers = $myTeachers->fetchAll();

// ─── ใบลาจากครู (notify_student_ids มี id นักเรียนนี้) ─────────────────────────
$teacherLeaves = $pdo->prepare(
    'SELECT lr.*, t.displayName AS teacher_display, t.teacherCode AS teacher_code
     FROM sevenj_leave_requests lr
     LEFT JOIN sevenj_teachers t ON t.id = lr.requester_id
     WHERE lr.requester_role = "teacher"
       AND FIND_IN_SET(?, COALESCE(lr.notify_student_ids,""))
     ORDER BY lr.leave_date DESC, lr.created_at DESC
     LIMIT 10'
);
$teacherLeaves->execute([$me['id']]);
$teacherLeaves = $teacherLeaves->fetchAll();

// Flash
$flash = '';
if (!empty($_SESSION['student_flash'])) {
    $flash = $_SESSION['student_flash'];
    unset($_SESSION['student_flash']);
}

// Student + teacher
$stmt = $pdo->prepare(
    'SELECT s.*, t.displayName AS teacherName, t.nickname AS teacherNickname,
            t.teacherCode AS teacherCode, t.googleMeetLink AS teacherMeetLink
     FROM sevenj_students s
     LEFT JOIN sevenj_teachers t ON s.teacherId = t.id
     WHERE s.id = ?'
);
$stmt->execute([$me['id']]);
$student = $stmt->fetch();

// Schedules (all statuses)
$stmtSched = $pdo->prepare(
    'SELECT sch.*, t.displayName AS tName
     FROM sevenj_schedule sch
     LEFT JOIN sevenj_teachers t ON t.id = sch.teacher_ref_id
     WHERE sch.student_id = ?
     ORDER BY FIELD(sch.day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"),
              sch.specific_date, sch.time_start'
);
$stmtSched->execute([$me['id']]);
$schedules = $stmtSched->fetchAll();

// Completions
$stmtComp = $pdo->prepare(
    'SELECT * FROM sevenj_class_completions
     WHERE student_id = ?
     ORDER BY completed_date DESC, id DESC
     LIMIT 20'
);
$stmtComp->execute([$me['id']]);
$completions = $stmtComp->fetchAll();

// Progress
$total     = (int)($student['totalClasses'] ?? 0);
$completed = (int)($student['completedClasses'] ?? 0);
$remaining = max(0, $total - $completed);
$pct       = $total > 0 ? round($completed / $total * 100) : 0;

// Real-time status
$thNow    = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
$todayStr = $thNow->format('Y-m-d');
$todayDay = $thNow->format('l');
$nowMins  = (int)$thNow->format('H') * 60 + (int)$thNow->format('i');
$rtStatus = 'รอเรียน';
$rtColor  = 'bg-blue-100 text-blue-700';
if ($schedules) {
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
            $rtStatus = 'กำลังเรียน';
            $rtColor  = 'bg-green-100 text-green-700';
            break;
        }
    }
}
// ไม่มีตารางเรียน หรือ ไม่ตรงเวลา → คงเป็น "รอเรียน" (ค่าเริ่มต้น)

$dayTH = ['Monday'=>'จันทร์','Tuesday'=>'อังคาร','Wednesday'=>'พุธ',
          'Thursday'=>'พฤหัสบดี','Friday'=>'ศุกร์','Saturday'=>'เสาร์','Sunday'=>'อาทิตย์'];

// Fallback: ถ้าไม่มีครูจาก teacherId FK → ดึงจาก schedule.teacher_ref_id
$schedTeacher = null;
if (!$student['teacherName']) {
    $stFb = $pdo->prepare(
        'SELECT t.id, t.displayName AS teacherName, t.nickname AS teacherNickname,
                t.teacherCode AS teacherCode, t.googleMeetLink AS teacherMeetLink, t.status AS teacherStatus
         FROM sevenj_schedule sch
         JOIN sevenj_teachers t ON t.id = sch.teacher_ref_id
         WHERE sch.student_id = ? AND sch.status = "active" AND sch.teacher_ref_id IS NOT NULL
         ORDER BY sch.completed_classes ASC LIMIT 1'
    );
    $stFb->execute([$me['id']]);
    $schedTeacher = $stFb->fetch();
    if ($schedTeacher) {
        $student['teacherName']     = $schedTeacher['teacherName'];
        $student['teacherNickname'] = $schedTeacher['teacherNickname'];
        $student['teacherCode']     = $schedTeacher['teacherCode'];
        $student['teacherMeetLink'] = $schedTeacher['teacherMeetLink'];
        $student['teacherStatus']   = $schedTeacher['teacherStatus'];
        $student['teacherId']       = $schedTeacher['id'];
    }
}

// สถานะ real-time ของครู (วันนี้ ตรงเวลากับนักเรียนคนนี้ไหม)
$teacherRtStatus = ''; $teacherRtBg = ''; $teacherRtFg = '';
if ($student['teacherId'] ?? null) {
    $thNow2    = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
    $now2Mins  = (int)$thNow2->format('H') * 60 + (int)$thNow2->format('i');
    $today2Str = $thNow2->format('Y-m-d');
    $today2Day = $thNow2->format('l');
    foreach ($schedules as $sc) {
        if (($sc['teacher_ref_id'] ?? '') != $student['teacherId'] && ($sc['teacher_name'] ?? '') == '') continue;
        if ($sc['status'] !== 'active' || !$sc['time_start']) continue;
        $mDay = ($sc['schedule_type'] === 'one_time')
            ? ($sc['specific_date'] === $today2Str)
            : (strtolower($sc['day_of_week']) === strtolower($today2Day));
        if (!$mDay) continue;
        [$sh,$sm] = array_pad(explode(':', $sc['time_start'].':00'), 2, '00');
        [$eh,$em] = array_pad(explode(':', ($sc['time_end'] ?? '00:00').':00'), 2, '00');
        $sM = (int)$sh*60+(int)$sm; $eM = (int)$eh*60+(int)$em;
        if ($now2Mins >= 720 && (int)$sh < 12) $sM += 720;
        if ($now2Mins >= 720 && (int)$eh < 12) $eM += 720;
        if ($now2Mins < $sM)        { $teacherRtStatus='⏳ รอสอน';      $teacherRtBg='#fef9c3'; $teacherRtFg='#713f12'; }
        elseif ($now2Mins <= $eM)   { $teacherRtStatus='🟢 กำลังสอน';  $teacherRtBg='#dcfce7'; $teacherRtFg='#166534'; }
        else                         { $teacherRtStatus='⛔ หมดเวลา';    $teacherRtBg='#f3f4f6'; $teacherRtFg='#6b7280'; }
        break;
    }
    if (!$teacherRtStatus) { $teacherRtStatus='📚 พร้อมสอน'; $teacherRtBg='#dbeafe'; $teacherRtFg='#1e40af'; }
}

// Meet link (student's own or teacher's)
$meetLink = $student['googleMeetLink'] ?: $student['teacherMeetLink'] ?: '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard — <?= htmlspecialchars($student['displayName']) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <style>
    body { font-family: 'Sarabun', 'Segoe UI', sans-serif; background: #fffbeb; }
    .brand-gradient { background: linear-gradient(135deg, #92400e 0%, #c27016 50%, #d97706 100%); }
    .progress-bar { transition: width 0.8s ease; }
    [x-cloak] { display: none !important; }
  </style>
</head>
<body class="min-h-screen bg-amber-50">

<!-- ───── TOP NAV ───── -->
<header class="brand-gradient shadow-lg">
  <div class="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <img src="<?= ABSOLUTE_URL ?>/uploads/logo_7j.png" alt="7J" class="h-9 w-9 object-contain" onerror="this.style.display='none'">
      <div>
        <div class="text-white font-bold text-base leading-tight">7J English Center</div>
        <div class="text-amber-200 text-xs">Student Portal</div>
      </div>
    </div>
    <div class="flex items-center gap-3">
      <span class="text-amber-200 text-sm hidden sm:block">
        สวัสดี, <span class="text-white font-medium"><?= htmlspecialchars($student['nickname'] ?: $student['displayName']) ?></span>
      </span>
      <!-- ปุ่มแจ้งลาเรียน -->
      <button onclick="document.getElementById('leave-modal').classList.remove('hidden')"
              class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white text-xs font-medium px-3 py-1.5 rounded-lg transition flex items-center gap-1">
        📋 แจ้งลาเรียน
      </button>
      <!-- ปุ่มลิ้งก์เข้าเรียน (แสดงเสมอ) -->
      <?php if ($meetLink): ?>
      <a href="<?= htmlspecialchars($meetLink) ?>" target="_blank" rel="noopener"
         class="bg-white text-amber-800 text-xs font-bold px-3 py-1.5 rounded-lg transition flex items-center gap-1 shadow-sm hover:bg-amber-50">
        📹 เข้าเรียน
      </a>
      <?php else: ?>
      <span class="bg-white bg-opacity-10 text-white text-opacity-50 text-xs font-medium px-3 py-1.5 rounded-lg flex items-center gap-1 cursor-not-allowed opacity-60"
            title="ยังไม่ได้รับลิ้งก์จากครู">
        📹 รอลิ้งก์ครู
      </span>
      <?php endif; ?>
      <a href="<?= ABSOLUTE_URL ?>/portal/student_logout.php"
         class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white text-xs font-medium px-3 py-1.5 rounded-lg transition flex items-center gap-1">
        ออกจากระบบ
      </a>
    </div>
  </div>
</header>

<!-- ───── MAIN CONTENT ───── -->
<main class="max-w-5xl mx-auto px-4 py-6 space-y-5">

  <?php if ($flash): ?>
  <div class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-5 py-3 flex items-center gap-3 text-sm font-medium shadow-sm">
    ✅ <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>

  <!-- ── Row 1: Profile + Progress ── -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-5">

    <!-- Profile -->
    <div class="bg-white rounded-2xl shadow-sm border border-amber-100 p-5 flex flex-col gap-3">
      <div class="flex items-center gap-3">
        <div class="w-12 h-12 rounded-full brand-gradient flex items-center justify-center text-white text-xl font-bold flex-shrink-0">
          <?= mb_substr($student['displayName'], 0, 1) ?>
        </div>
        <div class="min-w-0">
          <div class="font-bold text-gray-800 truncate"><?= htmlspecialchars($student['displayName']) ?></div>
          <?php if ($student['nickname']): ?>
          <div class="text-sm text-gray-400">"<?= htmlspecialchars($student['nickname']) ?>"</div>
          <?php endif; ?>
        </div>
      </div>
      <div class="space-y-1.5 text-sm">
        <?php if ($student['studentCode']): ?>
        <div class="flex justify-between">
          <span class="text-gray-500">รหัส</span>
          <span class="font-mono text-gray-700"><?= htmlspecialchars($student['studentCode']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($student['age']): ?>
        <div class="flex justify-between">
          <span class="text-gray-500">อายุ</span>
          <span class="text-gray-700"><?= $student['age'] ?> ปี</span>
        </div>
        <?php endif; ?>
        <div class="flex justify-between items-center">
          <span class="text-gray-500">สถานะ</span>
          <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?= $rtColor ?>">
            <?= $rtStatus ?>
          </span>
        </div>
      </div>
    </div>

    <!-- Progress -->
    <div class="md:col-span-2 bg-white rounded-2xl shadow-sm border border-amber-100 p-5">
      <h3 class="text-sm font-semibold text-gray-600 mb-4 uppercase tracking-wide">ความคืบหน้าการเรียน</h3>
      <div class="grid grid-cols-3 gap-4 mb-5">
        <div class="text-center">
          <div class="text-3xl font-bold text-amber-700"><?= $total ?></div>
          <div class="text-xs text-gray-400 mt-0.5">คาบทั้งหมด</div>
        </div>
        <div class="text-center">
          <div class="text-3xl font-bold text-green-600"><?= $completed ?></div>
          <div class="text-xs text-gray-400 mt-0.5">เรียนแล้ว</div>
        </div>
        <div class="text-center">
          <div class="text-3xl font-bold text-amber-500"><?= $remaining ?></div>
          <div class="text-xs text-gray-400 mt-0.5">คงเหลือ</div>
        </div>
      </div>
      <div class="space-y-1">
        <div class="flex justify-between text-xs text-gray-500 mb-1">
          <span>ความคืบหน้า</span>
          <span class="font-semibold text-amber-700"><?= $pct ?>%</span>
        </div>
        <div class="h-3 bg-amber-100 rounded-full overflow-hidden">
          <div class="progress-bar h-full brand-gradient rounded-full" style="width: <?= $pct ?>%"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Row 2: Teacher + Schedule ── -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-5">

    <!-- Teacher -->
    <div class="bg-white rounded-2xl shadow-sm border border-amber-100 p-5">
      <h3 class="text-sm font-semibold text-gray-600 mb-3 uppercase tracking-wide">ครูผู้สอน</h3>
      <?php if ($student['teacherName']): ?>
      <div class="flex items-center gap-3 mb-3">
        <div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center text-amber-700 font-bold flex-shrink-0 text-base">
          <?= mb_substr($student['teacherName'], 0, 1) ?>
        </div>
        <div class="flex-1 min-w-0">
          <div class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($student['teacherName']) ?></div>
          <?php if ($student['teacherNickname']): ?>
          <div class="text-xs text-gray-400">"<?= htmlspecialchars($student['teacherNickname']) ?>"</div>
          <?php endif; ?>
          <?php if ($student['teacherCode']): ?>
          <div class="text-xs text-gray-400 font-mono"><?= htmlspecialchars($student['teacherCode']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($teacherRtStatus): ?>
      <div style="display:inline-flex;align-items:center;gap:5px;background:<?= $teacherRtBg ?>;color:<?= $teacherRtFg ?>;border-radius:99px;padding:3px 10px;font-size:.75rem;font-weight:700;margin-bottom:8px;">
        <?= $teacherRtStatus ?>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <p class="text-sm text-gray-400">ยังไม่ได้กำหนดครู</p>
      <?php endif; ?>
    </div>

    <!-- Schedule -->
    <div class="md:col-span-2 bg-white rounded-2xl shadow-sm border border-amber-100 p-5">
      <h3 class="text-sm font-semibold text-gray-600 mb-3 uppercase tracking-wide">ตารางเรียน</h3>
      <?php if ($schedules): ?>
      <div class="space-y-2">
        <?php foreach ($schedules as $sch):
            $matchDay = ($sch['schedule_type'] === 'one_time')
                ? ($sch['specific_date'] === $todayStr)
                : (strtolower($sch['day_of_week']) === strtolower($todayDay));
            $isNow = false;
            if ($matchDay && $sch['time_start'] && $sch['time_end']) {
                [$sh,$sm] = explode(':', $sch['time_start'].':00');
                [$eh,$em] = explode(':', $sch['time_end'].':00');
                $isNow = $nowMins >= (int)$sh*60+(int)$sm && $nowMins <= (int)$eh*60+(int)$em;
            }
            $bg = $isNow ? 'bg-green-50 border-green-200' : 'bg-amber-50 border-amber-100';
        ?>
        <div class="flex items-center gap-3 <?= $bg ?> border rounded-xl px-4 py-2.5 text-sm">
          <div class="w-16 text-center flex-shrink-0">
            <?php if ($sch['schedule_type'] === 'weekly'): ?>
            <span class="inline-block bg-amber-200 text-amber-900 text-xs font-semibold px-2 py-0.5 rounded-full">
              <?= $dayTH[$sch['day_of_week']] ?? $sch['day_of_week'] ?>
            </span>
            <?php else: ?>
            <span class="inline-block bg-blue-100 text-blue-700 text-xs font-semibold px-2 py-0.5 rounded-full">
              <?= $sch['specific_date'] ?>
            </span>
            <?php endif; ?>
          </div>
          <div class="font-mono text-gray-700 w-28 flex-shrink-0">
            <?= htmlspecialchars($sch['time_start']) ?><?= $sch['time_end'] ? ' – '.$sch['time_end'] : '' ?>
          </div>
          <div class="text-gray-500 truncate flex-1">
            <?= htmlspecialchars($sch['course'] ?: 'English') ?>
            <?php $tName = $sch['tName'] ?: $sch['teacher_name']; if ($tName): ?>
            <span class="text-xs text-gray-400">· <?= htmlspecialchars($tName) ?></span>
            <?php endif; ?>
          </div>
          <div class="text-xs text-gray-400 flex-shrink-0"><?= $sch['completed_classes'] ?>/<?= $sch['total_classes'] ?> คาบ</div>
          <?php if ($isNow): ?>
          <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium flex-shrink-0">🟢 กำลังเรียน</span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p class="text-sm text-gray-400">ยังไม่มีตารางเรียน</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Row QA: Quick Actions ── -->
  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

    <!-- Card: แจ้งลาเรียน -->
    <div class="bg-white rounded-2xl shadow-sm border border-amber-100 p-5 flex flex-col gap-3">
      <div class="flex items-center gap-2 mb-1">
        <span class="text-2xl">📋</span>
        <div>
          <h3 class="font-bold text-gray-800 text-base leading-tight">แจ้งลาเรียน</h3>
          <p class="text-xs text-gray-400">ส่งใบลาไปยังแอดมิน รอการพิจารณา</p>
        </div>
      </div>
      <button onclick="document.getElementById('leave-modal').classList.remove('hidden')"
              class="w-full brand-gradient text-white font-semibold py-2.5 rounded-xl text-sm transition hover:opacity-90 flex items-center justify-center gap-2">
        📋 กดยื่นใบลาเรียน
      </button>
      <p class="text-xs text-gray-400 text-center">ระบุวันที่และเหตุผล แล้วส่งให้แอดมินพิจารณา</p>
    </div>

    <!-- Card: ลิ้งก์จาก Admin -->
    <div class="bg-white rounded-2xl shadow-sm border border-blue-100 p-5 flex flex-col gap-3">
      <div class="flex items-center gap-2 mb-1">
        <span class="text-2xl">📹</span>
        <div>
          <h3 class="font-bold text-gray-800 text-base leading-tight">ลิ้งก์ห้องเรียน</h3>
          <p class="text-xs text-gray-400">ลิ้งก์ Google Meet จาก Admin</p>
        </div>
      </div>
      <?php if ($student['googleMeetLink']): ?>
      <a href="<?= htmlspecialchars($student['googleMeetLink']) ?>" target="_blank" rel="noopener"
         style="display:flex;align-items:center;justify-content:center;gap:8px;
                width:100%;padding:10px;border-radius:12px;font-weight:700;font-size:.9rem;
                color:#fff;text-decoration:none;
                background:linear-gradient(135deg,#1d4ed8,#3b82f6);
                box-shadow:0 3px 10px rgba(59,130,246,.35);transition:opacity .15s;">
        📹 กดเข้าห้องเรียนเลย
      </a>
      <p class="text-xs text-gray-400 text-center break-all"><?= htmlspecialchars($student['googleMeetLink']) ?></p>
      <?php else: ?>
      <div style="width:100%;background:#f3f4f6;color:#9ca3af;font-size:.85rem;padding:10px;
                  border-radius:12px;text-align:center;cursor:not-allowed;">
        ⏳ ยังไม่ได้รับลิ้งก์จาก Admin
      </div>
      <p class="text-xs text-gray-400 text-center">Admin จะส่งลิ้งก์ให้ก่อนเริ่มเรียน</p>
      <?php endif; ?>
    </div>

  </div>

  <!-- ── Row 3: ผลการเรียน ── -->
  <div class="bg-white rounded-2xl shadow-sm border border-amber-100 p-5">
    <h3 class="text-sm font-semibold text-gray-600 mb-4 uppercase tracking-wide">📅 เรียนวันไหน? เวลาอะไร? กับครูอะไร?</h3>

    <?php if ($schedules): ?>
    <!-- ตารางสรุปรายคาบ -->
    <div class="overflow-x-auto mb-5">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-amber-50 text-xs text-gray-500 uppercase">
            <th class="px-3 py-2 text-left font-semibold rounded-l-xl">วัน / เวลา</th>
            <th class="px-3 py-2 text-left font-semibold">ครูผู้สอน</th>
            <th class="px-3 py-2 text-center font-semibold">คาบเรียน<br><span class="font-normal text-gray-400">(ทั้งหมด)</span></th>
            <th class="px-3 py-2 text-center font-semibold">เรียนแล้ว<br><span class="font-normal text-gray-400">(คาบ)</span></th>
            <th class="px-3 py-2 text-center font-semibold">ยังไม่ได้เรียน<br><span class="font-normal text-gray-400">(คาบ)</span></th>
            <th class="px-3 py-2 text-center font-semibold rounded-r-xl">เหลืออีก<br><span class="font-normal text-gray-400">(คาบ)</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($schedules as $sch):
              $tName = $sch['tName'] ?: $sch['teacher_name'] ?: '—';
              $done  = (int)$sch['completed_classes'];
              $tot   = (int)$sch['total_classes'];
              $rem   = max(0, $tot - $done);
              $sPct  = $tot > 0 ? round($done/$tot*100) : 0;
              $dayLbl = ($sch['schedule_type'] === 'one_time')
                  ? '📅 '.$sch['specific_date']
                  : '🗓 '.($dayTH[$sch['day_of_week']] ?? $sch['day_of_week']);
              $timeLbl = htmlspecialchars($sch['time_start']).($sch['time_end'] ? ' – '.$sch['time_end'] : '');
              $matchDayRow = ($sch['schedule_type'] === 'one_time')
                  ? ($sch['specific_date'] === $todayStr)
                  : (strtolower($sch['day_of_week']) === strtolower($todayDay));
              $isNowRow = false;
              if ($matchDayRow && $sch['time_start'] && $sch['time_end']) {
                  [$rsh,$rsm] = explode(':', $sch['time_start'].':00');
                  [$reh,$rem2] = explode(':', $sch['time_end'].':00');
                  $isNowRow = $nowMins >= (int)$rsh*60+(int)$rsm && $nowMins <= (int)$reh*60+(int)$rem2;
              }
              $rowCls = $isNowRow ? 'bg-green-50' : 'hover:bg-amber-50';
          ?>
          <tr class="transition <?= $rowCls ?>">
            <td class="px-3 py-3">
              <div class="font-semibold text-gray-800 text-sm"><?= $dayLbl ?></div>
              <div class="text-xs font-mono text-gray-400 mt-0.5"><?= $timeLbl ?></div>
              <?php if ($isNowRow): ?>
              <span class="inline-block mt-1 text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium">🟢 กำลังเรียน</span>
              <?php endif; ?>
            </td>
            <td class="px-3 py-3">
              <div class="font-medium text-gray-700"><?= htmlspecialchars($tName) ?></div>
            </td>
            <td class="px-3 py-3 text-center">
              <span class="text-xl font-bold text-amber-700"><?= $tot ?></span>
            </td>
            <td class="px-3 py-3 text-center">
              <span class="text-xl font-bold text-green-600"><?= $done ?></span>
            </td>
            <td class="px-3 py-3 text-center">
              <span class="text-xl font-bold text-orange-500"><?= $rem ?></span>
            </td>
            <td class="px-3 py-3 text-center">
              <div class="text-xl font-bold text-amber-500"><?= $rem ?></div>
              <div class="mt-1 h-1.5 bg-gray-100 rounded-full overflow-hidden w-16 mx-auto">
                <div class="h-full rounded-full brand-gradient" style="width:<?= $sPct ?>%"></div>
              </div>
              <div class="text-xs text-amber-600 font-medium mt-0.5"><?= $sPct ?>%</div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <!-- ไม่มีตารางเรียน: แสดงข้อมูลสรุปจาก student record -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
      <div class="bg-amber-50 rounded-xl p-3 text-center border border-amber-100">
        <div class="text-2xl font-bold text-amber-700"><?= $total ?></div>
        <div class="text-xs text-gray-400 mt-0.5">คาบเรียน (ทั้งหมด)</div>
      </div>
      <div class="bg-green-50 rounded-xl p-3 text-center border border-green-100">
        <div class="text-2xl font-bold text-green-600"><?= $completed ?></div>
        <div class="text-xs text-gray-400 mt-0.5">เรียนแล้ว</div>
      </div>
      <div class="bg-orange-50 rounded-xl p-3 text-center border border-orange-100">
        <div class="text-2xl font-bold text-orange-500"><?= $remaining ?></div>
        <div class="text-xs text-gray-400 mt-0.5">ยังไม่ได้เรียน</div>
      </div>
      <div class="bg-amber-50 rounded-xl p-3 text-center border border-amber-100">
        <div class="text-2xl font-bold text-amber-500"><?= $remaining ?></div>
        <div class="text-xs text-gray-400 mt-0.5">เหลืออีก</div>
      </div>
    </div>
    <?php if ($student['teacherName']): ?>
    <div class="flex items-center gap-2 text-sm text-gray-600 mb-4 bg-amber-50 rounded-xl px-4 py-2.5 border border-amber-100">
      <span>👨‍🏫</span>
      <span>ครูผู้สอน: <strong class="text-gray-800"><?= htmlspecialchars($student['teacherName']) ?></strong>
      <?php if ($student['teacherNickname']): ?>
      <span class="text-gray-400 font-normal">"<?= htmlspecialchars($student['teacherNickname']) ?>"</span>
      <?php endif; ?>
      </span>
    </div>
    <?php endif; ?>
    <p class="text-xs text-gray-400 mb-4">ยังไม่ได้กำหนดตารางเรียน</p>
    <?php endif; ?>

    <!-- ประวัติการเข้าเรียนจริง -->
    <?php if ($completions): ?>
    <div class="border-t border-gray-100 pt-4">
      <h4 class="text-xs font-semibold text-gray-500 uppercase mb-3">📋 ประวัติการเข้าเรียนจริง</h4>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-amber-50 text-xs text-gray-500 uppercase">
              <th class="px-3 py-2 text-left font-semibold rounded-l-xl">วันที่เรียน</th>
              <th class="px-3 py-2 text-left font-semibold">เวลา</th>
              <th class="px-3 py-2 text-center font-semibold">คาบที่</th>
              <th class="px-3 py-2 text-left font-semibold">ครูที่สอน</th>
              <th class="px-3 py-2 text-left font-semibold rounded-r-xl">หมายเหตุ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($completions as $c): ?>
            <tr class="hover:bg-amber-50 transition">
              <td class="px-3 py-2 text-gray-700 whitespace-nowrap"><?= $c['completed_date'] ? date('d/m/Y', strtotime($c['completed_date'])) : '-' ?></td>
              <td class="px-3 py-2 text-gray-500 font-mono text-xs"><?= htmlspecialchars($c['time_start'] ?: '-') ?></td>
              <td class="px-3 py-2 text-center">
                <span class="inline-block bg-amber-100 text-amber-800 text-xs font-semibold px-2 py-0.5 rounded-full">#<?= $c['session_number'] ?: '-' ?></span>
              </td>
              <td class="px-3 py-2 text-gray-600"><?= htmlspecialchars($c['teacher_name'] ?: '-') ?></td>
              <td class="px-3 py-2 text-gray-400 text-xs max-w-xs truncate"><?= htmlspecialchars($c['note'] ?: '') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php else: ?>
    <p class="text-sm text-gray-400 text-center py-5 border-t border-gray-50 mt-2">ยังไม่มีประวัติการเข้าเรียน</p>
    <?php endif; ?>
  </div>

<!-- ── ใบลาจากครู ──────────────────────────────────────────────────── -->
<?php if (!empty($teacherLeaves)): ?>
<div class="bg-white rounded-2xl shadow-sm border border-red-100 p-5 mb-5">
  <div class="flex items-center gap-2 mb-3">
    <span style="font-size:1.1rem;">📋</span>
    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">ใบลาจากครู</h3>
    <span style="background:#fee2e2;color:#991b1b;border-radius:99px;padding:1px 9px;font-size:.72rem;font-weight:700;margin-left:4px;">
      <?= count($teacherLeaves) ?> รายการ
    </span>
  </div>
  <div class="space-y-3">
  <?php foreach ($teacherLeaves as $tl):
    $tlStatus = match($tl['status']) {
        'approved' => ['✅ อนุมัติแล้ว','#dcfce7','#166534'],
        'rejected' => ['❌ ไม่อนุมัติ','#fee2e2','#991b1b'],
        default    => ['⏳ รออนุมัติ','#fef9c3','#713f12'],
    };
    $tlDayTh = ['Monday'=>'จันทร์','Tuesday'=>'อังคาร','Wednesday'=>'พุธ',
                'Thursday'=>'พฤหัสบดี','Friday'=>'ศุกร์','Saturday'=>'เสาร์','Sunday'=>'อาทิตย์'];
  ?>
  <div style="background:#fff5f5;border-radius:10px;padding:10px 14px;border-left:3px solid #f87171;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
      <div>
        <div style="font-weight:700;color:#1f2937;font-size:.88rem;">
          👨‍🏫 <?= htmlspecialchars($tl['teacher_display'] ?? $tl['requester_name']) ?>
          <span style="color:#9ca3af;font-size:.75rem;font-weight:400;margin-left:4px;"><?= htmlspecialchars($tl['teacher_code'] ?? '') ?></span>
        </div>
        <div style="font-size:.8rem;color:#6b7280;margin-top:3px;">
          <?php if ($tl['leave_day']): ?>
            <span style="background:#fffbeb;color:#92400e;border-radius:4px;padding:1px 6px;font-size:.75rem;font-weight:600;margin-right:4px;">
              วัน<?= $tlDayTh[$tl['leave_day']] ?? $tl['leave_day'] ?>
            </span>
          <?php endif; ?>
          📅 <?= date('d/m/Y', strtotime($tl['leave_date'])) ?>
          <?php if ($tl['leave_time_start']): ?>
            · 🕐 <?= htmlspecialchars($tl['leave_time_start']) ?>
            <?= $tl['leave_time_end'] ? '–'.htmlspecialchars($tl['leave_time_end']) : '' ?>
          <?php endif; ?>
        </div>
        <div style="font-size:.8rem;color:#374151;margin-top:4px;">💬 <?= htmlspecialchars($tl['reason']) ?></div>
      </div>
      <span style="background:<?= $tlStatus[1] ?>;color:<?= $tlStatus[2] ?>;border-radius:99px;padding:2px 10px;font-size:.72rem;font-weight:700;white-space:nowrap;flex-shrink:0;">
        <?= $tlStatus[0] ?>
      </span>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

</main>

<footer class="text-center py-6 text-xs text-gray-400">
  © 7J English Center — Global Language Institute Online
  <span class="mx-2 opacity-40">·</span>
  <span class="opacity-60">Version 3.0.0</span>
</footer>

<!-- ─── Leave Modal ────────────────────────────────────────────────────── -->
<div id="leave-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center px-4">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden" style="max-height:92vh;overflow-y:auto;">
    <div style="background:linear-gradient(135deg,#92400e,#d97706);padding:16px 20px;position:sticky;top:0;">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
          <div style="color:#fff;font-weight:700;font-size:1rem;">📋 ยื่นใบลาเรียน</div>
          <div style="color:#fde68a;font-size:.82rem;margin-top:1px;"><?= htmlspecialchars($me['displayName']) ?></div>
        </div>
        <button onclick="document.getElementById('leave-modal').classList.add('hidden')"
                style="color:#fde68a;font-size:1.3rem;font-weight:700;background:none;border:none;cursor:pointer;">✕</button>
      </div>
    </div>
    <form method="post" style="padding:18px 20px;">
      <input type="hidden" name="action" value="submit_leave">
      <input type="hidden" name="leave_day" id="stu-leave-day">

      <!-- วันที่ (auto วันในสัปดาห์) -->
      <div style="margin-bottom:12px;">
        <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:5px;">📅 วันที่ขอลา</label>
        <div style="position:relative;">
          <input type="date" name="leave_date" id="stu-leave-date" required
                 oninput="stuUpdateDay(this.value)"
                 style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:9px;font-size:.88rem;box-sizing:border-box;outline:none;">
          <span id="stu-leave-day-lbl" style="position:absolute;right:40px;top:50%;transform:translateY(-50%);font-size:.75rem;color:#d97706;font-weight:600;pointer-events:none;"></span>
        </div>
      </div>

      <!-- เวลา -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
        <div>
          <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:5px;">🕐 เวลาเริ่ม</label>
          <input type="time" name="leave_time_start"
                 style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:9px;font-size:.88rem;box-sizing:border-box;outline:none;">
        </div>
        <div>
          <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:5px;">🕐 เวลาสิ้นสุด</label>
          <input type="time" name="leave_time_end"
                 style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:9px;font-size:.88rem;box-sizing:border-box;outline:none;">
        </div>
      </div>

      <!-- แจ้งครู -->
      <?php if (!empty($myTeachers)): ?>
      <div style="margin-bottom:12px;">
        <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:5px;">👨‍🏫 แจ้งครู (เลือกได้หลายคน)</label>
        <div style="border:1px solid #d1d5db;border-radius:9px;overflow:hidden;padding:6px 8px;">
          <?php foreach ($myTeachers as $mt): ?>
          <label style="display:flex;align-items:center;gap:8px;padding:5px 4px;cursor:pointer;border-radius:6px;font-size:.85rem;"
                 onmouseover="this.style.background='#fffbeb'" onmouseout="this.style.background=''">
            <input type="checkbox" name="notify_teacher_ids[]" value="<?= (int)$mt['id'] ?>"
                   style="accent-color:#d97706;" checked>
            <span><?= htmlspecialchars($mt['displayName']) ?></span>
            <span style="color:#9ca3af;font-size:.75rem;margin-left:auto;"><?= htmlspecialchars($mt['teacherCode']) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- เหตุผล -->
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:5px;">💬 เหตุผลการลา</label>
        <textarea name="leave_reason" rows="3" required placeholder="เช่น ป่วย, ธุระส่วนตัว, นัดหมายแพทย์..."
                  style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:9px;font-size:.88rem;box-sizing:border-box;outline:none;resize:vertical;font-family:inherit;"></textarea>
      </div>

      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 12px;margin-bottom:14px;font-size:.79rem;color:#92400e;">
        ⚠️ ใบลาจะถูกส่งให้แอดมินพิจารณา และครูที่เลือกจะได้รับการแจ้งเตือน
      </div>
      <div style="display:flex;gap:10px;">
        <button type="button" onclick="document.getElementById('leave-modal').classList.add('hidden')"
                style="flex:1;padding:9px;border-radius:9px;border:1px solid #d1d5db;background:#fff;font-size:.88rem;font-weight:600;cursor:pointer;">
          ยกเลิก
        </button>
        <button type="submit"
                style="flex:1;padding:9px;border-radius:9px;border:none;background:linear-gradient(135deg,#92400e,#d97706);color:#fff;font-size:.88rem;font-weight:700;cursor:pointer;">
          ✓ ยื่นใบลา
        </button>
      </div>
    </form>
  </div>
</div>
<script>
var STU_DAYS_EN = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
var STU_DAYS_TH = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
function stuUpdateDay(val) {
    if (!val) { document.getElementById('stu-leave-day').value=''; document.getElementById('stu-leave-day-lbl').textContent=''; return; }
    var d = new Date(val + 'T12:00:00');
    var idx = d.getDay();
    document.getElementById('stu-leave-day').value = STU_DAYS_EN[idx];
    document.getElementById('stu-leave-day-lbl').textContent = 'วน' + STU_DAYS_TH[idx];
}
// auto-fill today on open
document.querySelectorAll('[onclick*="leave-modal"]').forEach(function(btn){
    btn.addEventListener('click', function(){
        var dateStr = new Date().toISOString().slice(0,10);
        var el = document.getElementById('stu-leave-date');
        if (el && !el.value) { el.value = dateStr; stuUpdateDay(dateStr); }
    });
});
</script>

</body>
</html>
