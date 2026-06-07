<?php
/*
 * 7J English Center — รายงานการเรียน-การสอน
 * บันทึกคาบเรียนที่เสร็จแล้วทุกวัน (sevenj_class_completions)
 * ลบได้เฉพาะ Admin (gibbonRoleIDPrimary = '001')
 */

// ─── Admin check ──────────────────────────────────────────────────────────────
$isAdmin = false;
try {
    if (isset($session)) {
        $roleId = $session->get('gibbonRoleIDPrimary') ?? '';
        $isAdmin = ($roleId === '001');
    }
} catch (Exception $e) { $isAdmin = false; }

// ─── POST: Delete (admin only) ────────────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $postAction = $_POST['action'] ?? '';
    $qs = http_build_query(['q' => '/modules/7j/class_learning_report.php',
        'search'    => $_POST['s_back']    ?? '',
        'date_from' => $_POST['df_back']   ?? '',
        'date_to'   => $_POST['dt_back']   ?? '',
        'page'      => $_POST['page_back'] ?? '1',
    ]);
    if ($postAction === 'delete_record') {
        $rid = (int)($_POST['record_id'] ?? 0);
        if ($rid > 0) {
            $connection2->prepare('DELETE FROM sevenj_class_completions WHERE id=?')->execute([$rid]);
            $msg = 'success|ลบบันทึก #'.$rid.' สำเร็จ';
        }
    } elseif ($postAction === 'delete_by_date') {
        $ddate = trim($_POST['del_date'] ?? '');
        if ($ddate) {
            $cnt = $connection2->prepare('SELECT COUNT(*) FROM sevenj_class_completions WHERE completed_date=?');
            $cnt->execute([$ddate]);
            $n = (int)$cnt->fetchColumn();
            $connection2->prepare('DELETE FROM sevenj_class_completions WHERE completed_date=?')->execute([$ddate]);
            $msg = 'success|ลบบันทึกวันที่ '.$ddate.' ทั้งหมด '.$n.' รายการสำเร็จ';
        }
    }
    // Redirect with flash message via session
    if ($msg) { $_SESSION['7j_clr_msg'] = $msg; }
    header("Location: /MyNewShool/?$qs");
    exit;
}
// รับ flash message
if (!$msg && isset($_SESSION['7j_clr_msg'])) {
    $msg = $_SESSION['7j_clr_msg'];
    unset($_SESSION['7j_clr_msg']);
}

// ─── Auto-cleanup ────────────────────────────────────────────────────────────
// กฎ 1: เกิน 365 วัน → ลบเสมอ
$connection2->query("DELETE FROM sevenj_class_completions WHERE created_at < DATE_SUB(NOW(), INTERVAL 365 DAY)");

// กฎ 2: เกิน 30 วัน + เรียนครบแล้ว → ลบ / ยังค้าง → คงไว้จนครบ 365 วัน
// ใช้ PHP fetch IDs ก่อน เพื่อหลีกเลี่ยง MySQL self-reference ใน DELETE
$old30 = $connection2->query("
    SELECT c.id, c.student_id,
        (SELECT COUNT(*) FROM sevenj_class_completions c2 WHERE c2.student_id = c.student_id) AS done_all,
        COALESCE((SELECT MAX(sch.total_classes) FROM sevenj_schedule sch
                  WHERE sch.student_id = c.student_id AND sch.status = 'active'), 0) AS total_pkg
    FROM sevenj_class_completions c
    WHERE c.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
")->fetchAll(PDO::FETCH_ASSOC);

$idsToDelete = [];
foreach ($old30 as $row) {
    $hasRemaining = ($row['total_pkg'] > 0) && ((int)$row['done_all'] < (int)$row['total_pkg']);
    if (!$hasRemaining) {
        $idsToDelete[] = (int)$row['id'];
    }
}
if ($idsToDelete) {
    $ph = implode(',', array_fill(0, count($idsToDelete), '?'));
    $connection2->prepare("DELETE FROM sevenj_class_completions WHERE id IN ($ph)")
                ->execute($idsToDelete);
}

// ─── Filters ──────────────────────────────────────────────────────────────────
$search   = trim($_GET['search']    ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to']   ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 100;
$offset   = ($page - 1) * $perPage;

$where = ['1=1']; $bindP = [];
if ($search !== '') {
    if (is_numeric($search)) {
        $where[] = 'c.id = ?';
        $bindP[] = (int)$search;
    } else {
        $where[] = '(c.student_code LIKE ? OR c.student_name LIKE ? OR c.teacher_name LIKE ?)';
        $l = '%'.$search.'%';
        $bindP[] = $l; $bindP[] = $l; $bindP[] = $l;
    }
}
if ($dateFrom) { $where[] = 'c.completed_date >= ?'; $bindP[] = $dateFrom; }
if ($dateTo)   { $where[] = 'c.completed_date <= ?'; $bindP[] = $dateTo; }
$whereSQL = implode(' AND ', $where);

// ─── Time-based stats + records (อ้างอิงรายงานนักเรียน/การสอน) ──────────────
$_clBkk     = new DateTimeZone('Asia/Bangkok');
$_clNow     = new DateTime('now', $_clBkk);
$_clNowHM   = $_clNow->format('H:i');
$_clToday   = $_clNow->format('Y-m-d');
$_clDayName = $_clNow->format('l');

$_schAll = $connection2->query("
    SELECT sch.id AS sch_id, sch.student_id, sch.student_code, sch.student_name,
           sch.teacher_ref_id, sch.teacher_name,
           sch.schedule_type, sch.day_of_week, sch.specific_date,
           sch.time_start, sch.time_end, sch.total_classes, sch.course, sch.note,
           COALESCE(st.displayName, sch.student_name) AS disp_student,
           COALESCE(st.studentCode, sch.student_code) AS disp_code,
           COALESCE(t.displayName,  sch.teacher_name) AS disp_teacher
    FROM sevenj_schedule sch
    LEFT JOIN sevenj_students st ON st.id = sch.student_id
    LEFT JOIN sevenj_teachers  t ON t.id  = sch.teacher_ref_id
    WHERE sch.status IN ('active','completed')
    ORDER BY sch.specific_date DESC, sch.time_start DESC
")->fetchAll(PDO::FETCH_ASSOC);

$_allPassed = []; $_seqMap = [];
foreach ($_schAll as $sl) {
    $stype  = $sl['schedule_type'] ?? 'weekly';
    $tstart = $sl['time_start']    ?? '';
    $slotDate = null;
    if ($stype === 'one_time') {
        $sdate = $sl['specific_date'] ?? '';
        if ($sdate < $_clToday) $slotDate = $sdate;
        elseif ($sdate === $_clToday && $tstart !== '' && $_clNowHM >= $tstart) $slotDate = $sdate;
    } else {
        $dow = ucfirst(strtolower($sl['day_of_week'] ?? ''));
        if ($_clDayName === $dow && $tstart !== '' && $_clNowHM >= $tstart) $slotDate = $_clToday;
    }
    if (!$slotDate) continue;
    $stuKey = $sl['student_id'] ?: ($sl['disp_student'].'|'.($sl['student_code'] ?? ''));
    $_seqMap[$stuKey] = ($_seqMap[$stuKey] ?? 0) + 1;
    $sl['completed_date'] = $slotDate;
    $sl['session_number'] = $_seqMap[$stuKey];
    $_allPassed[] = $sl;
}

$_slotDates = array_column($_allPassed, 'completed_date');
$stats = [
    'total_records'  => count($_allPassed),
    'total_students' => count(array_unique(array_map(fn($r) => $r['student_id'] ?: $r['disp_student'], $_allPassed))),
    'total_teachers' => count(array_unique(array_filter(array_column($_allPassed, 'teacher_ref_id')))),
    'first_date'     => !empty($_slotDates) ? min($_slotDates) : null,
    'last_date'      => !empty($_slotDates) ? max($_slotDates) : null,
];

$_filtered = $_allPassed;
if ($search !== '') {
    $sl = mb_strtolower($search);
    $_filtered = array_values(array_filter($_filtered, function($r) use ($sl) {
        return mb_strpos(mb_strtolower($r['disp_student'] ?? ''), $sl) !== false
            || mb_strpos(mb_strtolower($r['disp_code']    ?? ''), $sl) !== false
            || mb_strpos(mb_strtolower($r['disp_teacher'] ?? ''), $sl) !== false;
    }));
}
if ($dateFrom) $_filtered = array_values(array_filter($_filtered, fn($r) => $r['completed_date'] >= $dateFrom));
if ($dateTo)   $_filtered = array_values(array_filter($_filtered, fn($r) => $r['completed_date'] <= $dateTo));

$totalFiltered = count($_filtered);
$pages   = max(1, (int)ceil($totalFiltered / $perPage));
$records = array_slice($_filtered, $offset, $perPage);

$byDate = [];
foreach ($records as $r) { $byDate[$r['completed_date']][] = $r; }

[$alertType, $alertText] = $msg ? explode('|', $msg, 2) : ['', ''];

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
.clr-card{background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:12px;overflow:hidden;}
.clr-date-hdr{display:flex;justify-content:space-between;align-items:center;padding:9px 16px;background:#fffbeb;border-bottom:2px solid #fde68a;}
.clr-table{width:100%;border-collapse:collapse;font-size:.83rem;}
.clr-table th{padding:8px 12px;background:#f9fafb;color:#6b7280;font-weight:700;text-align:left;border-bottom:2px solid #e5e7eb;white-space:nowrap;}
.clr-table td{padding:7px 12px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.clr-table tr:hover td{background:#fffbeb;}
.clr-badge{display:inline-block;border-radius:99px;padding:1px 9px;font-size:.7rem;font-weight:700;}
.clr-input{padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:.88rem;outline:none;box-sizing:border-box;}
.clr-input:focus{border-color:#ea580c;box-shadow:0 0 0 2px #fff7ed;}
.clr-btn{padding:6px 14px;border-radius:7px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:4px;}
.clr-btn-del{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;} .clr-btn-del:hover{background:#fca5a5;}
.clr-btn-search{background:linear-gradient(135deg,#92400e,#d97706);color:#fff;}
.clr-page-btn{padding:4px 10px;border-radius:6px;border:1px solid #d1d5db;background:#fff;font-size:.8rem;text-decoration:none;color:#374151;}
.clr-page-btn:hover{background:#f3f4f6;} .clr-page-btn.active{background:#ea580c;color:#fff;border-color:#ea580c;}
.clr-admin-tag{background:#fee2e2;color:#991b1b;border-radius:6px;padding:2px 8px;font-size:.7rem;font-weight:700;}
</style>

<div style="max-width:100%;padding-bottom:2rem;">

<!-- Header -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:1rem;">
    <div>
        <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0;">📋 รายงานการเรียน-การสอน</h2>
        <p style="font-size:.78rem;color:#6b7280;margin:3px 0 0;">บันทึกคาบเรียนที่เสร็จแล้วทุกวัน<?= $isAdmin?' — <span class="clr-admin-tag">🔑 Admin Mode</span>':'' ?></p>
        <p style="font-size:.75rem;color:#d97706;margin:3px 0 0;">⏳ ลบอัตโนมัติ: เรียนครบแล้ว → 30 วัน &nbsp;|&nbsp; 🔒 ยังค้างอยู่ (เช่น 9/10) → 365 วัน</p>
    </div>
</div>

<?php if ($alertText): ?>
<div style="background:<?= $alertType==='success'?'#d1fae5':'#fee2e2' ?>;border-radius:8px;padding:10px 16px;margin-bottom:1rem;color:<?= $alertType==='success'?'#065f46':'#991b1b' ?>;font-weight:600;">
    <?= htmlspecialchars($alertText) ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:1.25rem;">
<?php foreach ([
    ['📋','บันทึกทั้งหมด', (int)$stats['total_records'],   '#ea580c'],
    ['🎓','นักเรียน',      (int)$stats['total_students'],  '#2563eb'],
    ['👨‍🏫','ครู',           (int)$stats['total_teachers'],  '#059669'],
    ['📅','วันแรก',        $stats['first_date'] ? date('d/m/Y', strtotime($stats['first_date'])) : '—', '#d97706'],
    ['📅','วันล่าสุด',     $stats['last_date']  ? date('d/m/Y', strtotime($stats['last_date']))  : '—', '#7c3aed'],
] as [$ic,$lb,$vl,$co]): ?>
<div style="background:#fff;border-radius:10px;padding:.75rem 1rem;box-shadow:0 1px 4px rgba(0,0,0,.07);border-left:4px solid <?= $co ?>;">
    <div style="font-size:1.1rem;"><?= $ic ?></div>
    <div style="font-size:1.4rem;font-weight:800;color:#1f2937;"><?= $vl ?></div>
    <div style="font-size:.72rem;color:#6b7280;"><?= $lb ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- Filter -->
<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:.85rem 1rem;margin-bottom:1.25rem;">
    <input type="hidden" name="q" value="/modules/7j/class_learning_report.php">
    <div style="flex:1;min-width:160px;">
        <div style="font-size:.72rem;color:#92400e;font-weight:700;margin-bottom:3px;">🔍 ค้นหา (ID / รหัส / ชื่อ)</div>
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="clr-input" style="width:100%;" placeholder="เช่น 5, S260001, นักเรียน001...">
    </div>
    <div>
        <div style="font-size:.72rem;color:#92400e;font-weight:700;margin-bottom:3px;">📅 ตั้งแต่</div>
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="clr-input">
    </div>
    <div>
        <div style="font-size:.72rem;color:#92400e;font-weight:700;margin-bottom:3px;">📅 ถึง</div>
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="clr-input">
    </div>
    <button type="submit" class="clr-btn clr-btn-search">ค้นหา</button>
    <?php if ($search || $dateFrom || $dateTo): ?>
    <a href="?q=/modules/7j/class_learning_report.php" class="clr-btn" style="background:#e5e7eb;color:#374151;">✕ ล้าง</a>
    <?php endif; ?>
</form>

<?php if ($totalFiltered === 0): ?>
<div style="text-align:center;padding:3rem 1rem;color:#9ca3af;background:#fff;border-radius:12px;">
    <div style="font-size:2.5rem;margin-bottom:.5rem;">📭</div>
    ไม่พบบันทึก<?= ($search||$dateFrom||$dateTo)?' ตามเงื่อนไขที่ค้นหา':'' ?>
</div>
<?php else: ?>

<!-- Result count -->
<div style="font-size:.8rem;color:#6b7280;margin-bottom:.75rem;">
    พบ <strong><?= number_format($totalFiltered) ?></strong> รายการ
    <?= ($search||$dateFrom||$dateTo)?'(กรองแล้ว)':'' ?>
    <?php if ($pages > 1): ?> · หน้า <?= $page ?>/<?= $pages ?><?php endif; ?>
</div>

<!-- Records grouped by date -->
<?php foreach ($byDate as $date => $dayRows):
    $dayCount = count($dayRows);
?>
<div class="clr-card">
    <div class="clr-date-hdr">
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-weight:700;color:#92400e;font-size:.95rem;">
                📅 <?= date('d/m/Y', strtotime($date)) ?>
                (<?= ['Sunday'=>'อาทิตย์','Monday'=>'จันทร์','Tuesday'=>'อังคาร','Wednesday'=>'พุธ','Thursday'=>'พฤหัสบดี','Friday'=>'ศุกร์','Saturday'=>'เสาร์'][date('l',strtotime($date))] ?>)
            </span>
            <span class="clr-badge" style="background:#fef3c7;color:#92400e;"><?= $dayCount ?> คาบ</span>
        </div>
    </div>
    <div style="overflow-x:auto;">
    <table class="clr-table">
        <thead>
            <tr>
                <th style="width:50px;">ID</th>
                <th>นักเรียน</th>
                <th>รหัส</th>
                <th>ครู</th>
                <th>เวลา</th>
                <th style="text-align:center;">คาบที่</th>
                <th>คอร์ส</th>
                <th>หมายเหตุ</th>
                <th style="text-align:center;">คาบ</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($dayRows as $r): ?>
        <tr>
            <td><span class="clr-badge" style="background:#f3f4f6;color:#6b7280;">#<?= (int)$r['sch_id'] ?></span></td>
            <td style="font-weight:600;white-space:nowrap;"><?= htmlspecialchars($r['disp_student']) ?></td>
            <td><span class="clr-badge" style="background:#fff7ed;color:#9a3412;"><?= htmlspecialchars($r['disp_code']) ?></span></td>
            <td style="color:#6b7280;white-space:nowrap;"><?= htmlspecialchars($r['disp_teacher']) ?></td>
            <td style="font-family:monospace;color:#374151;white-space:nowrap;"><?= $r['time_start'] ? fmtTimePM($r['time_start']) : '—' ?></td>
            <td style="text-align:center;"><span class="clr-badge" style="background:#dbeafe;color:#1e40af;">#<?= (int)$r['session_number'] ?></span></td>
            <td style="font-size:.78rem;color:#059669;"><?= htmlspecialchars($r['course'] ?? '—') ?></td>
            <td style="font-size:.75rem;color:#9ca3af;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($r['note'] ?? '') ?>"><?= htmlspecialchars($r['note'] ?? '—') ?></td>
            <td style="text-align:center;white-space:nowrap;">
                <?php $remain = max(0, (int)$r['total_classes'] - (int)$r['session_number']); ?>
                <span class="clr-badge" style="background:#dbeafe;color:#1e40af;"><?= (int)$r['session_number'] ?>/<?= (int)$r['total_classes'] ?></span>
                <?php if ($remain > 0): ?><div style="font-size:.65rem;color:#9ca3af;">(เหลือ <?= $remain ?>)</div><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endforeach; ?>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div style="display:flex;gap:5px;justify-content:center;margin-top:1rem;flex-wrap:wrap;">
    <?php for ($p = 1; $p <= $pages; $p++): ?>
    <a href="?<?= http_build_query(['q'=>'/modules/7j/class_learning_report.php','search'=>$search,'date_from'=>$dateFrom,'date_to'=>$dateTo,'page'=>$p]) ?>"
       class="clr-page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php endif; ?>
</div>
