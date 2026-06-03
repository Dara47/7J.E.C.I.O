<?php
/*
 * 7J English Center — Attendance Logs (Login History)
 * Ported from AttendanceLogs.tsx
 * ดู/ลบ log การเข้าสู่ระบบ — รายวัน หรือลบเป็นช่วงวันที่
 */

// ─── POST handlers ────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';
$msg = '';

if ($action === 'delete_single') {
    $lid = trim($_POST['log_id'] ?? '');
    if ($lid) {
        $stmt = $connection2->prepare('DELETE FROM sevenj_login_logs WHERE id=?');
        $stmt->execute([$lid]);
        $msg = 'success|ลบรายการสำเร็จ';
    }
} elseif ($action === 'delete_range') {
    $ds = trim($_POST['del_start'] ?? '');
    $de = trim($_POST['del_end'] ?? '');
    if ($ds && $de && $ds <= $de) {
        $cnt = $connection2->prepare('SELECT COUNT(*) FROM sevenj_login_logs WHERE log_date BETWEEN ? AND ?');
        $cnt->execute([$ds, $de]);
        $count = (int)$cnt->fetchColumn();
        if ($count > 0) {
            $del = $connection2->prepare('DELETE FROM sevenj_login_logs WHERE log_date BETWEEN ? AND ?');
            $del->execute([$ds, $de]);
            $msg = 'success|ลบสำเร็จ '.$count.' รายการ ('.$ds.' ถึง '.$de.')';
        } else {
            $msg = 'error|ไม่พบรายการในช่วงวันที่นี้';
        }
    } else {
        $msg = 'error|กรุณาระบุวันที่ให้ถูกต้อง';
    }
}

// ─── Filters ──────────────────────────────────────────────────────────────────
$filterDate = trim($_GET['date'] ?? '');
$filterRole = trim($_GET['role'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$limit      = 100;
$offset     = ($page - 1) * $limit;

$where = [];
if ($filterDate) $where[] = "log_date = '".addslashes($filterDate)."'";
if ($filterRole) $where[] = "role = '".addslashes($filterRole)."'";
$whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

$total = (int)$connection2->query("SELECT COUNT(*) FROM sevenj_login_logs $whereSQL")->fetchColumn();
$pages = $total > 0 ? ceil($total / $limit) : 1;

// JOIN sevenj_students/teachers เพื่อดึงชื่อและรหัสจาก DB จริง
$logs = $connection2->query("
    SELECT l.*,
        COALESCE(st.displayName, t.displayName, l.userName) AS disp_name,
        COALESCE(st.studentCode, t.teacherCode, l.userCode) AS disp_code
    FROM sevenj_login_logs l
    LEFT JOIN sevenj_students st ON st.id = l.user_ref_id AND l.role = 'student'
    LEFT JOIN sevenj_teachers t  ON t.id  = l.user_ref_id AND l.role = 'teacher'
    $whereSQL
    ORDER BY l.log_timestamp DESC, l.created_at DESC
    LIMIT $limit OFFSET $offset
")->fetchAll(PDO::FETCH_ASSOC);

$hasFilters = $filterDate || $filterRole;

[$alertType, $alertText] = $msg ? explode('|', $msg, 2) : ['', ''];

$roleLabel = ['student' => '🎓 นักเรียน', 'teacher' => '👨‍🏫 ครู', 'admin' => '⚙️ Admin'];
$roleColors = [
    'student' => '#dbeafe|#1e40af',
    'teacher' => '#d1fae5|#065f46',
    'admin'   => '#fff7ed|#9a3412',
];
function avatarBg($role) {
    $map = ['student'=>'#2563eb','teacher'=>'#059669','admin'=>'#ea580c'];
    return $map[$role] ?? '#6b7280';
}
function avatarIcon($role) {
    if ($role === 'teacher') return '👨‍🏫';
    if ($role === 'admin')   return '⚙️';
    return '🎓';
}
?>

<?php require_once __DIR__.'/_theme.php'; ?>
<style>
.al-card{background:#fff;border-radius:10px;padding:.8rem 1rem;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:7px;display:flex;align-items:center;gap:12px;}
.al-avatar{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
.al-badge{display:inline-block;border-radius:99px;padding:1px 9px;font-size:.72rem;font-weight:700;}
.al-btn{padding:6px 14px;border-radius:7px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;}
.al-btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db;}
.al-btn-outline:hover{background:#f3f4f6;}
.al-btn-danger{background:#dc2626;color:#fff;}
.al-btn-danger:hover{background:#b91c1c;}
.al-btn-icon{padding:5px;border-radius:6px;background:none;border:none;cursor:pointer;color:#9ca3af;}
.al-btn-icon:hover{background:#fee2e2;color:#dc2626;}
.al-filter-box{background:#f8f7ff;border:1px solid #e5e7eb;border-radius:10px;padding:.9rem 1rem;margin-bottom:1rem;}
.al-delete-box{background:#fff5f5;border:1px dashed #fca5a5;border-radius:10px;padding:.9rem 1rem;margin-bottom:1.25rem;}
.al-form-group label{display:block;font-size:.78rem;font-weight:600;color:#374151;margin-bottom:3px;}
.al-form-group input,.al-form-group select{padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;outline:none;}
.al-form-group input:focus,.al-form-group select:focus{border-color:#ea580c;box-shadow:0 0 0 2px #fff7ed;}
.al-page-nav{display:flex;gap:6px;justify-content:center;margin-top:1rem;flex-wrap:wrap;}
.al-page-btn{padding:5px 12px;border-radius:6px;border:1px solid #d1d5db;background:#fff;font-size:.82rem;cursor:pointer;color:#374151;text-decoration:none;}
.al-page-btn:hover{background:#f3f4f6;}
.al-page-btn.active{background:#ea580c;color:#fff;border-color:#ea580c;}
/* Modals */
.al-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center;}
.al-modal-bg.open{display:flex;}
.al-modal{background:#fff;border-radius:14px;width:100%;max-width:380px;padding:1.5rem;box-shadow:0 20px 60px rgba(0,0,0,.25);}
.al-modal h3{margin:0 0 .75rem;font-size:1.05rem;font-weight:700;}
</style>

<div style="max-width:100%;padding-bottom:2rem;">

<?php if ($alertText): ?>
<div style="background:<?= $alertType==='success'?'#d1fae5':'#fee2e2' ?>;border-radius:8px;padding:10px 16px;margin-bottom:1rem;color:<?= $alertType==='success'?'#065f46':'#991b1b' ?>;"><?= htmlspecialchars($alertText) ?></div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:1rem;">
    <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0;">🕐 ประวัติการเข้าใช้งาน</h2>
    <span style="background:#fff7ed;color:#9a3412;border-radius:99px;padding:3px 12px;font-size:.82rem;font-weight:700;"><?= $total ?> รายการ</span>
</div>

<!-- Filter -->
<form method="get" class="al-filter-box">
    <input type="hidden" name="q" value="/modules/7j/attendance_logs.php">
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <div class="al-form-group">
            <label>📅 กรองตามวันที่</label>
            <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>">
        </div>
        <div class="al-form-group">
            <label>👤 บทบาท</label>
            <select name="role">
                <option value="">— ทั้งหมด —</option>
                <option value="student" <?= $filterRole==='student'?'selected':'' ?>>🎓 นักเรียน</option>
                <option value="teacher" <?= $filterRole==='teacher'?'selected':'' ?>>👨‍🏫 ครู</option>
                <option value="admin"   <?= $filterRole==='admin'?'selected':'' ?>>⚙️ Admin</option>
            </select>
        </div>
        <div style="display:flex;gap:6px;">
            <button type="submit" class="al-btn al-btn-outline">กรอง</button>
            <?php if ($hasFilters): ?>
            <a href="?q=/modules/7j/attendance_logs.php" class="al-btn al-btn-outline">✕ ล้าง</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- Delete by date range -->
<div class="al-delete-box">
    <div style="font-size:.85rem;font-weight:600;color:#dc2626;margin-bottom:.6rem;">🗑️ ลบรายการตามช่วงวันที่</div>
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <div class="al-form-group">
            <label>วันที่เริ่ม</label>
            <input type="date" id="del-start" name="del_start">
        </div>
        <div class="al-form-group">
            <label>วันที่สิ้นสุด</label>
            <input type="date" id="del-end" name="del_end">
        </div>
        <button type="button" class="al-btn al-btn-danger"
            onclick="confirmBulkDelete()">🗑️ ลบ</button>
    </div>
</div>

<!-- Count -->
<p style="font-size:.85rem;color:#6b7280;margin-bottom:.75rem;">
    แสดง <?= count($logs) ?> / <?= $total ?> รายการ
    <?= $hasFilters ? '<span style="color:#ea580c;font-weight:600;">(มีการกรอง)</span>' : '' ?>
</p>

<!-- Log list -->
<?php if (empty($logs)): ?>
<div style="text-align:center;padding:3rem 1rem;color:#9ca3af;">
    <div style="font-size:2.5rem;margin-bottom:.5rem;">🕐</div>
    ยังไม่มีประวัติการเข้าสู่ระบบ
</div>
<?php else: ?>

<?php foreach ($logs as $log):
    $rColors = explode('|', $roleColors[$log['role']] ?? '#f3f4f6|#374151');
    $rLabel  = $roleLabel[$log['role']] ?? $log['role'];
    $dtStr   = '';
    $tmStr   = '';
    if ($log['log_timestamp']) {
        $ts    = strtotime($log['log_timestamp']);
        $dtStr = date('d/m/Y', $ts);
        $tmStr = date('H:i', $ts);
    } elseif ($log['log_date']) {
        $dtStr = date('d/m/Y', strtotime($log['log_date']));
    }
?>
<div class="al-card">
    <div class="al-avatar" style="background:<?= avatarBg($log['role']) ?>;"><?= avatarIcon($log['role']) ?></div>
    <div style="flex:1;min-width:0;">
        <div style="font-weight:600;font-size:.92rem;"><?= htmlspecialchars($log['disp_name'] ?? $log['userName'] ?? '—') ?></div>
        <div style="display:flex;align-items:center;gap:6px;margin-top:2px;flex-wrap:wrap;">
            <?php if ($log['disp_code'] ?? $log['userCode']): ?>
            <span class="al-badge" style="background:#f3f4f6;color:#374151;"><?= htmlspecialchars($log['disp_code'] ?? $log['userCode']) ?></span>
            <?php endif; ?>
            <span class="al-badge" style="background:<?= $rColors[0] ?>;color:<?= $rColors[1] ?>;"><?= $rLabel ?></span>
        </div>
    </div>
    <div style="text-align:right;flex-shrink:0;">
        <div style="font-size:.85rem;font-weight:600;color:#1f2937;"><?= $dtStr ?></div>
        <?php if ($tmStr): ?>
        <div style="font-size:.78rem;color:#9ca3af;">⏰ <?= $tmStr ?></div>
        <?php endif; ?>
    </div>
    <button class="al-btn-icon" title="ลบรายการนี้"
        onclick="confirmSingleDelete('<?= htmlspecialchars($log['id']) ?>','<?= htmlspecialchars(addslashes($log['userName']??'')) ?>')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
    </button>
</div>
<?php endforeach; ?>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div class="al-page-nav">
    <?php if ($page > 1): ?>
    <a href="?q=/modules/7j/attendance_logs.php&page=<?= $page-1 ?>&date=<?= urlencode($filterDate) ?>&role=<?= urlencode($filterRole) ?>" class="al-page-btn">&#8249; ก่อน</a>
    <?php endif; ?>
    <?php for ($p = max(1,$page-2); $p <= min($pages,$page+2); $p++): ?>
    <a href="?q=/modules/7j/attendance_logs.php&page=<?= $p ?>&date=<?= urlencode($filterDate) ?>&role=<?= urlencode($filterRole) ?>" class="al-page-btn <?= $p==$page?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($page < $pages): ?>
    <a href="?q=/modules/7j/attendance_logs.php&page=<?= $page+1 ?>&date=<?= urlencode($filterDate) ?>&role=<?= urlencode($filterRole) ?>" class="al-page-btn">ถัดไป &#8250;</a>
    <?php endif; ?>
</div>
<div style="text-align:center;font-size:.8rem;color:#9ca3af;margin-top:6px;">หน้า <?= $page ?>/<?= $pages ?></div>
<?php endif; ?>
<?php endif; ?>

</div><!-- end main -->

<!-- Single Delete Modal -->
<div id="modal-del-single" class="al-modal-bg">
<div class="al-modal">
    <h3 style="color:#dc2626;">ยืนยันการลบ</h3>
    <p id="del-single-text" style="color:#374151;margin:.5rem 0 1.25rem;font-size:.9rem;"></p>
    <form method="post">
        <input type="hidden" name="action" value="delete_single">
        <input type="hidden" name="q" value="/modules/7j/attendance_logs.php">
        <?php if ($filterDate): ?><input type="hidden" name="date" value="<?= htmlspecialchars($filterDate) ?>"><?php endif; ?>
        <?php if ($filterRole): ?><input type="hidden" name="role" value="<?= htmlspecialchars($filterRole) ?>"><?php endif; ?>
        <input type="hidden" name="log_id" id="del-single-id">
        <div style="display:flex;justify-content:flex-end;gap:8px;">
            <button type="button" class="al-btn al-btn-outline" onclick="closeModal('del-single')">ยกเลิก</button>
            <button type="submit" class="al-btn al-btn-danger">ลบ</button>
        </div>
    </form>
</div>
</div>

<!-- Bulk Delete Modal -->
<div id="modal-del-bulk" class="al-modal-bg">
<div class="al-modal">
    <h3 style="color:#dc2626;">ยืนยันลบเป็นช่วงวันที่</h3>
    <p id="del-bulk-text" style="color:#374151;margin:.5rem 0 1.25rem;font-size:.9rem;"></p>
    <form method="post">
        <input type="hidden" name="action" value="delete_range">
        <input type="hidden" name="q" value="/modules/7j/attendance_logs.php">
        <?php if ($filterDate): ?><input type="hidden" name="date" value="<?= htmlspecialchars($filterDate) ?>"><?php endif; ?>
        <?php if ($filterRole): ?><input type="hidden" name="role" value="<?= htmlspecialchars($filterRole) ?>"><?php endif; ?>
        <input type="hidden" name="del_start" id="del-bulk-start">
        <input type="hidden" name="del_end" id="del-bulk-end">
        <div style="display:flex;justify-content:flex-end;gap:8px;">
            <button type="button" class="al-btn al-btn-outline" onclick="closeModal('del-bulk')">ยกเลิก</button>
            <button type="submit" class="al-btn al-btn-danger">ลบทั้งหมดในช่วงนี้</button>
        </div>
    </form>
</div>
</div>

<script>
function openModal(id) { document.getElementById('modal-' + id).classList.add('open'); }
function closeModal(id) { document.getElementById('modal-' + id).classList.remove('open'); }
document.querySelectorAll('.al-modal-bg').forEach(function(bg) {
    bg.addEventListener('click', function(e) { if (e.target === bg) bg.classList.remove('open'); });
});

function confirmSingleDelete(logId, userName) {
    document.getElementById('del-single-id').value = logId;
    document.getElementById('del-single-text').textContent =
        'ต้องการลบรายการเข้าสู่ระบบของ "' + userName + '" ใช่หรือไม่?';
    openModal('del-single');
}

function confirmBulkDelete() {
    var ds = document.getElementById('del-start').value;
    var de = document.getElementById('del-end').value;
    if (!ds || !de) { alert('กรุณาระบุวันที่เริ่มและสิ้นสุด'); return; }
    if (ds > de) { alert('วันที่เริ่มต้องไม่มากกว่าวันที่สิ้นสุด'); return; }
    document.getElementById('del-bulk-start').value = ds;
    document.getElementById('del-bulk-end').value = de;
    document.getElementById('del-bulk-text').innerHTML =
        'ต้องการลบ <b>ทุกรายการ</b> ในช่วงวันที่<br><b>' + ds + ' ถึง ' + de + '</b><br>การดำเนินการนี้ไม่สามารถย้อนกลับได้';
    openModal('del-bulk');
}
</script>
