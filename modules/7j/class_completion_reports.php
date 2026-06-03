<?php
/*
 * 7J English Center — Class Completion Reports
 * Ported from ClassCompletionReports.tsx
 * แสดงรายการคาบเรียนที่เสร็จสมบูรณ์ — กรอง/ลบได้
 */

// ─── POST: Delete ─────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';
$msg = '';

if ($action === 'delete_completion') {
    $cid = (int)($_POST['completion_id'] ?? 0);
    if ($cid) {
        $connection2->prepare("DELETE FROM sevenj_class_completions WHERE id=?")->execute([$cid]);
        $msg = 'success|ลบรายการคาบเรียนสำเร็จ';
    }
}

// ─── Export CSV ───────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $expTeacher = trim($_GET['teacher'] ?? '');
    $expStudent = trim($_GET['student'] ?? '');
    $expStart   = trim($_GET['date_start'] ?? '');
    $expEnd     = trim($_GET['date_end']   ?? '');
    $expWhere = []; $expP = [];
    if ($expTeacher) { $expWhere[] = "(c.teacher_name LIKE ? OR t.displayName LIKE ?)";  $l='%'.$expTeacher.'%'; $expP[]=$l; $expP[]=$l; }
    if ($expStudent) { $expWhere[] = "(c.student_name LIKE ? OR st.displayName LIKE ?)"; $l='%'.$expStudent.'%'; $expP[]=$l; $expP[]=$l; }
    if ($expStart)   { $expWhere[] = "c.completed_date >= ?"; $expP[] = $expStart; }
    if ($expEnd)     { $expWhere[] = "c.completed_date <= ?"; $expP[] = $expEnd; }
    $expSQL = $expWhere ? 'WHERE '.implode(' AND ', $expWhere) : '';
    $stmtExp = $connection2->prepare("
        SELECT c.completed_date, c.session_number, c.day_of_week, c.time_start,
               COALESCE(st.displayName, c.student_name) AS student_name,
               COALESCE(st.studentCode, c.student_code) AS student_code,
               COALESCE(t.displayName, c.teacher_name)  AS teacher_name,
               c.note
        FROM sevenj_class_completions c
        LEFT JOIN sevenj_students st ON st.id = c.student_id
        LEFT JOIN sevenj_teachers t  ON t.id  = c.teacher_ref_id
        $expSQL ORDER BY c.completed_date DESC, c.created_at DESC
    ");
    $stmtExp->execute($expP);
    $rows = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="completion_report_'.date('Ymd').'.csv"');
    echo "\xEF\xBB\xBF"; // BOM
    $out = fopen('php://output', 'w');
    fputcsv($out, ['วันที่','คาบที่','วัน','เวลา','นักเรียน','รหัสนักเรียน','ครู','หมายเหตุ']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['completed_date'], $r['session_number'], $r['day_of_week'],
            $r['time_start'], $r['student_name'], $r['student_code'], $r['teacher_name'], $r['note']]);
    }
    fclose($out);
    exit;
}

// ─── Filters ──────────────────────────────────────────────────────────────────
$filterTeacher  = trim($_GET['teacher'] ?? '');
$filterStudent  = trim($_GET['student'] ?? '');
$filterStart    = trim($_GET['date_start'] ?? '');
$filterEnd      = trim($_GET['date_end'] ?? '');
$page           = max(1, (int)($_GET['page'] ?? 1));
$limit          = 50;
$offset         = ($page - 1) * $limit;

$where = []; $bindP = [];
if ($filterTeacher) { $where[] = "(c.teacher_name LIKE ? OR t.displayName LIKE ?)";  $l='%'.$filterTeacher.'%'; $bindP[]=$l; $bindP[]=$l; }
if ($filterStudent) { $where[] = "(c.student_name LIKE ? OR st.displayName LIKE ? OR st.studentCode LIKE ?)"; $l='%'.$filterStudent.'%'; $bindP[]=$l; $bindP[]=$l; $bindP[]=$l; }
if ($filterStart)   { $where[] = "c.completed_date >= ?"; $bindP[] = $filterStart; }
if ($filterEnd)     { $where[] = "c.completed_date <= ?"; $bindP[] = $filterEnd; }
$whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

// ─── Stats ────────────────────────────────────────────────────────────────────
$statsAll   = (int)$connection2->query("SELECT COUNT(*) FROM sevenj_class_completions")->fetchColumn();
$statsToday = (int)$connection2->query("SELECT COUNT(*) FROM sevenj_class_completions WHERE completed_date = CURDATE()")->fetchColumn();
$statsWeek  = (int)$connection2->query("SELECT COUNT(*) FROM sevenj_class_completions WHERE completed_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
$statsMonth = (int)$connection2->query("SELECT COUNT(*) FROM sevenj_class_completions WHERE YEAR(completed_date)=YEAR(CURDATE()) AND MONTH(completed_date)=MONTH(CURDATE())")->fetchColumn();

// Total count
$stmtTotal = $connection2->prepare("
    SELECT COUNT(*) FROM sevenj_class_completions c
    LEFT JOIN sevenj_students st ON st.id = c.student_id
    LEFT JOIN sevenj_teachers t  ON t.id  = c.teacher_ref_id
    $whereSQL
");
$stmtTotal->execute($bindP);
$total = (int)$stmtTotal->fetchColumn();
$pages = $total > 0 ? ceil($total / $limit) : 1;

// Fetch completions
$stmtComp = $connection2->prepare("
    SELECT c.*,
        COALESCE(st.displayName, c.student_name) AS disp_student,
        COALESCE(st.studentCode, c.student_code) AS disp_code,
        COALESCE(t.displayName,  c.teacher_name) AS disp_teacher,
        t.teacherCode AS disp_teacher_code
    FROM sevenj_class_completions c
    LEFT JOIN sevenj_students st ON st.id = c.student_id
    LEFT JOIN sevenj_teachers t  ON t.id  = c.teacher_ref_id
    $whereSQL
    ORDER BY c.completed_date DESC, c.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmtComp->execute($bindP);
$completions = $stmtComp->fetchAll(PDO::FETCH_ASSOC);

// Teachers for filter dropdown — รวมจาก sevenj_teachers + text เดิม
$allTeachers = $connection2->query("
    SELECT DISTINCT COALESCE(t.displayName, c.teacher_name) AS teacher_name
    FROM sevenj_class_completions c
    LEFT JOIN sevenj_teachers t ON t.id = c.teacher_ref_id
    WHERE c.teacher_name IS NOT NULL AND c.teacher_name != ''
    ORDER BY teacher_name
")->fetchAll(PDO::FETCH_COLUMN);

$hasFilters = $filterTeacher || $filterStudent || $filterStart || $filterEnd;

[$alertType, $alertText] = $msg ? explode('|', $msg, 2) : ['', ''];

function initials($name) {
    $words = preg_split('/\s+/', trim($name ?? ''));
    $ini = '';
    foreach ($words as $w) { if ($w) $ini .= mb_strtoupper(mb_substr($w, 0, 1)); }
    return mb_substr($ini, 0, 2) ?: '?';
}
function avatarColor($name) {
    $colors = ['#ea580c','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2','#65a30d'];
    $idx = array_sum(array_map('ord', str_split($name ?? '?'))) % count($colors);
    return $colors[$idx];
}
$DAYS_TH = [
    'sunday'=>'อาทิตย์','monday'=>'จันทร์','tuesday'=>'อังคาร',
    'wednesday'=>'พุธ','thursday'=>'พฤหัสบดี','friday'=>'ศุกร์','saturday'=>'เสาร์',
];
function dayTh($d, $map) {
    $d = strtolower(trim($d ?? ''));
    return $map[$d] ?? $d;
}
?>

<?php require_once __DIR__.'/_theme.php'; ?>
<style>
.cr-filter-box{background:#f8f7ff;border:1px solid #e5e7eb;border-radius:10px;padding:1rem;margin-bottom:1.25rem;}
.cr-filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end;}
.cr-form-group label{display:block;font-size:.78rem;font-weight:600;color:#374151;margin-bottom:3px;}
.cr-form-group select,.cr-form-group input{width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;outline:none;}
.cr-form-group select:focus,.cr-form-group input:focus{border-color:#ea580c;box-shadow:0 0 0 2px #fff7ed;}
.cr-card{background:#fff;border-radius:10px;padding:.85rem 1rem;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:8px;display:flex;align-items:flex-start;gap:12px;}
.cr-avatar{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;color:#fff;flex-shrink:0;}
.cr-badge{display:inline-block;background:#fff7ed;color:#9a3412;border-radius:99px;padding:1px 8px;font-size:.7rem;font-weight:700;}
.cr-badge-session{background:#dbeafe;color:#1e40af;border-radius:6px;padding:2px 9px;font-size:.75rem;font-weight:700;}
.cr-btn{padding:6px 14px;border-radius:7px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;}
.cr-btn-primary{background:#ea580c;color:#fff;}
.cr-btn-primary:hover{background:#c2410c;}
.cr-btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db;}
.cr-btn-outline:hover{background:#f3f4f6;}
.cr-btn-icon{padding:5px;border-radius:6px;background:none;border:none;cursor:pointer;color:#9ca3af;}
.cr-btn-icon:hover{background:#fee2e2;color:#dc2626;}
.cr-page-nav{display:flex;gap:6px;justify-content:center;margin-top:1rem;flex-wrap:wrap;}
.cr-page-btn{padding:5px 12px;border-radius:6px;border:1px solid #d1d5db;background:#fff;font-size:.82rem;cursor:pointer;color:#374151;text-decoration:none;}
.cr-page-btn:hover{background:#f3f4f6;}
.cr-page-btn.active{background:#ea580c;color:#fff;border-color:#ea580c;}
/* Modal */
.cr-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center;}
.cr-modal-bg.open{display:flex;}
.cr-modal{background:#fff;border-radius:14px;width:100%;max-width:360px;padding:1.5rem;box-shadow:0 20px 60px rgba(0,0,0,.25);}
.cr-modal h3{margin:0 0 .75rem;font-size:1.05rem;font-weight:700;}
</style>

<div style="max-width:100%;padding-bottom:2rem;">

<?php if ($alertText): ?>
<div style="background:<?= $alertType==='success'?'#d1fae5':'#fee2e2' ?>;border-radius:8px;padding:10px 16px;margin-bottom:1rem;color:<?= $alertType==='success'?'#065f46':'#991b1b' ?>;"><?= htmlspecialchars($alertText) ?></div>
<?php endif; ?>

<!-- Header -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:1rem;">
    <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0;">📋 รายงานการเรียน</h2>
    <a href="?q=/modules/7j/class_completion_reports.php&export=csv&teacher=<?= urlencode($filterTeacher) ?>&student=<?= urlencode($filterStudent) ?>&date_start=<?= urlencode($filterStart) ?>&date_end=<?= urlencode($filterEnd) ?>"
       style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:8px;font-size:.82rem;font-weight:600;
              background:#059669;color:#fff;text-decoration:none;">
        ⬇ Export CSV
    </a>
</div>

<!-- Stats bar -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:1.25rem;">
    <div style="background:#fff;border-radius:10px;padding:.75rem;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.08);border-top:3px solid #ea580c;">
        <div style="font-size:1.6rem;font-weight:800;color:#ea580c;"><?= $statsAll ?></div>
        <div style="font-size:.72rem;color:#6b7280;margin-top:2px;">ทั้งหมด</div>
    </div>
    <div style="background:#fff;border-radius:10px;padding:.75rem;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.08);border-top:3px solid #059669;">
        <div style="font-size:1.6rem;font-weight:800;color:#059669;"><?= $statsToday ?></div>
        <div style="font-size:.72rem;color:#6b7280;margin-top:2px;">วันนี้</div>
    </div>
    <div style="background:#fff;border-radius:10px;padding:.75rem;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.08);border-top:3px solid #d97706;">
        <div style="font-size:1.6rem;font-weight:800;color:#d97706;"><?= $statsWeek ?></div>
        <div style="font-size:.72rem;color:#6b7280;margin-top:2px;">7 วันล่าสุด</div>
    </div>
    <div style="background:#fff;border-radius:10px;padding:.75rem;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.08);border-top:3px solid #2563eb;">
        <div style="font-size:1.6rem;font-weight:800;color:#2563eb;"><?= $statsMonth ?></div>
        <div style="font-size:.72rem;color:#6b7280;margin-top:2px;">เดือนนี้</div>
    </div>
</div>

<!-- Filters -->
<form method="get" class="cr-filter-box">
    <input type="hidden" name="q" value="/modules/7j/class_completion_reports.php">
    <div class="cr-filter-grid">
        <div class="cr-form-group">
            <label>🎓 ค้นหานักเรียน</label>
            <input type="text" name="student" value="<?= htmlspecialchars($filterStudent) ?>" placeholder="ชื่อหรือรหัสนักเรียน...">
        </div>
        <div class="cr-form-group">
            <label>👨‍🏫 ครู</label>
            <select name="teacher">
                <option value="">— ครูทั้งหมด —</option>
                <?php foreach ($allTeachers as $tn): ?>
                <option value="<?= htmlspecialchars($tn) ?>" <?= $filterTeacher===$tn?'selected':'' ?>><?= htmlspecialchars($tn) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cr-form-group">
            <label>📅 วันที่เริ่ม</label>
            <input type="date" name="date_start" value="<?= htmlspecialchars($filterStart) ?>">
        </div>
        <div class="cr-form-group">
            <label>📅 วันที่สิ้นสุด</label>
            <input type="date" name="date_end" value="<?= htmlspecialchars($filterEnd) ?>">
        </div>
        <div class="cr-form-group" style="display:flex;gap:6px;align-items:flex-end;">
            <button type="submit" class="cr-btn cr-btn-primary" style="flex:1;">กรอง</button>
            <?php if ($hasFilters): ?>
            <a href="?q=/modules/7j/class_completion_reports.php" class="cr-btn cr-btn-outline">✕ ล้าง</a>
            <?php endif; ?>
        </div>
    </div>
</form>
<p style="font-size:.82rem;color:#6b7280;margin-bottom:.75rem;">
    แสดง <?= $total ?> รายการ<?= $hasFilters ? ' <span style="color:#ea580c;font-weight:600;">(มีการกรอง)</span>' : '' ?>
</p>

<!-- List -->
<?php if (empty($completions)): ?>
<div style="text-align:center;padding:3rem 1rem;color:#9ca3af;">
    <div style="font-size:2.5rem;margin-bottom:.5rem;">📋</div>
    ยังไม่มีรายการ<?= $hasFilters ? 'ที่ตรงกับเงื่อนไข' : 'คาบเรียน' ?>
</div>
<?php else: ?>

<?php foreach ($completions as $c):
    $dispStu  = $c['disp_student']      ?? $c['student_name'] ?? '?';
    $dispCode = $c['disp_code']         ?? $c['student_code'] ?? '';
    $dispTea  = $c['disp_teacher']      ?? $c['teacher_name'] ?? '';
    $dispTeaCode = $c['disp_teacher_code'] ?? '';
    $ini = initials($dispStu);
    $bg  = avatarColor($dispStu);
    $dayLabel = $DAYS_TH[strtolower(trim($c['day_of_week'] ?? ''))] ?? ($c['day_of_week'] ?? '');
?>
<div class="cr-card">
    <div class="cr-avatar" style="background:<?= $bg ?>;"><?= htmlspecialchars($ini) ?></div>
    <div style="flex:1;min-width:0;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;flex-wrap:wrap;">
            <!-- Left: student + teacher info -->
            <div style="min-width:0;">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <span style="font-weight:600;font-size:.95rem;"><?= htmlspecialchars($dispStu) ?></span>
                    <?php if ($dispCode): ?>
                    <span class="cr-badge"><?= htmlspecialchars($dispCode) ?></span>
                    <?php endif; ?>
                </div>
                <div style="font-size:.8rem;color:#6b7280;margin-top:2px;">
                    🎓 <?= htmlspecialchars($dispTea) ?><?= $dispTeaCode?' ('.$dispTeaCode.')':'' ?>
                </div>
            </div>
            <!-- Right: date/time + session + delete -->
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0;">
                <div style="font-size:.82rem;color:#374151;">
                    📅 <?= htmlspecialchars($dayLabel) ?>
                    <?= $c['completed_date'] ? ' &nbsp;'.$c['completed_date'] : '' ?>
                    <?= $c['time_start'] ? ' &nbsp;⏰ '.$c['time_start'] : '' ?>
                </div>
                <div style="font-size:.75rem;color:#9ca3af;">
                    บันทึกเมื่อ <?= $c['created_at'] ? date('d/m/Y H:i', strtotime($c['created_at'])) : '' ?>
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <span class="cr-badge-session">คาบที่ <?= (int)$c['session_number'] ?></span>
                    <button class="cr-btn-icon" title="ลบ"
                        onclick="confirmDelete(<?= (int)$c['id'] ?>,'<?= htmlspecialchars($c['student_name']??'') ?>',<?= (int)$c['session_number'] ?>)">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <?php if ($c['note']): ?>
        <div style="margin-top:6px;padding:6px 10px;background:#f8f7ff;border-radius:6px;font-size:.8rem;color:#4b5563;">
            📝 <?= htmlspecialchars($c['note']) ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div class="cr-page-nav">
    <?php if ($page > 1): ?>
    <?php $pgBase = '?q=/modules/7j/class_completion_reports.php&teacher='.urlencode($filterTeacher).'&student='.urlencode($filterStudent).'&date_start='.urlencode($filterStart).'&date_end='.urlencode($filterEnd); ?>
    <a href="<?= $pgBase ?>&page=<?= $page-1 ?>" class="cr-page-btn">&#8249; ก่อน</a>
    <?php endif; ?>
    <?php for ($p = max(1,$page-2); $p <= min($pages,$page+2); $p++): ?>
    <a href="<?= $pgBase ?>&page=<?= $p ?>" class="cr-page-btn <?= $p==$page?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($page < $pages): ?>
    <a href="<?= $pgBase ?>&page=<?= $page+1 ?>" class="cr-page-btn">ถัดไป &#8250;</a>
    <?php endif; ?>
</div>
<div style="text-align:center;font-size:.8rem;color:#9ca3af;margin-top:6px;">
    หน้า <?= $page ?>/<?= $pages ?> (ทั้งหมด <?= $total ?> รายการ)
</div>
<?php endif; ?>

<?php endif; ?>
</div><!-- end main -->

<!-- Delete Confirm Modal -->
<div id="modal-del" class="cr-modal-bg">
<div class="cr-modal">
    <h3 style="color:#dc2626;">ยืนยันการลบ</h3>
    <p id="del-text" style="color:#374151;margin:.5rem 0 1.25rem;font-size:.9rem;"></p>
    <form method="post">
        <input type="hidden" name="action" value="delete_completion">
        <input type="hidden" name="q" value="/modules/7j/class_completion_reports.php">
        <?php if ($filterTeacher): ?><input type="hidden" name="teacher" value="<?= htmlspecialchars($filterTeacher) ?>"><?php endif; ?>
        <?php if ($filterStart):  ?><input type="hidden" name="date_start" value="<?= htmlspecialchars($filterStart) ?>"><?php endif; ?>
        <?php if ($filterEnd):    ?><input type="hidden" name="date_end" value="<?= htmlspecialchars($filterEnd) ?>"><?php endif; ?>
        <input type="hidden" name="completion_id" id="del-id">
        <div style="display:flex;justify-content:flex-end;gap:8px;">
            <button type="button" class="cr-btn cr-btn-outline" onclick="closeDelModal()">ยกเลิก</button>
            <button type="submit" class="cr-btn" style="background:#dc2626;color:#fff;">ลบ</button>
        </div>
    </form>
</div>
</div>

<script>
function confirmDelete(id, studentName, sessionNum) {
    document.getElementById('del-id').value = id;
    document.getElementById('del-text').textContent =
        'ต้องการลบบันทึกคาบที่ ' + sessionNum + ' ของ "' + studentName + '" ใช่หรือไม่? ไม่สามารถย้อนกลับได้';
    document.getElementById('modal-del').classList.add('open');
}
function closeDelModal() {
    document.getElementById('modal-del').classList.remove('open');
}
document.getElementById('modal-del').addEventListener('click', function(e) {
    if (e.target === this) closeDelModal();
});
</script>
