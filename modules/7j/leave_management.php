<?php
/*
 * 7J English Center — Leave Management
 * Ported from LeaveRequest.tsx
 * Admin: ดู/เพิ่ม/อนุมัติ/ปฏิเสธ/ลบ ใบลาครูและนักเรียน
 */

// ─── POST handlers ────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';
$msg    = '';

if ($action === 'add') {
    $reqId  = trim($_POST['requester_id'] ?? '');
    $name   = trim($_POST['requester_name'] ?? '');
    $role   = in_array($_POST['requester_role'] ?? '', ['student','teacher','admin']) ? $_POST['requester_role'] : 'student';
    $date   = trim($_POST['leave_date'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    // ดึงชื่อจาก DB ถ้าเลือกจาก dropdown
    if ($reqId && $role === 'student') {
        $r = $connection2->prepare('SELECT displayName FROM sevenj_students WHERE id=? LIMIT 1');
        $r->execute([$reqId]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if ($row) $name = $row['displayName'];
    } elseif ($reqId && $role === 'teacher') {
        $r = $connection2->prepare('SELECT displayName FROM sevenj_teachers WHERE id=? LIMIT 1');
        $r->execute([$reqId]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if ($row) $name = $row['displayName'];
    }

    if ($name && $date && $reason) {
        $stmt = $connection2->prepare(
            'INSERT INTO sevenj_leave_requests (requester_name,requester_role,requester_id,leave_date,reason,status)
             VALUES (?,?,?,?,?,"pending")'
        );
        $stmt->execute([$name, $role, $reqId ?: null, $date, $reason]);
        $msg = 'success|เพิ่มใบลาสำเร็จ';
    } else {
        $msg = 'error|กรุณากรอกข้อมูลให้ครบ (ชื่อ, วันที่, เหตุผล)';
    }

} elseif ($action === 'approve' || $action === 'reject') {
    $id         = (int)($_POST['lid'] ?? 0);
    $reviewNote = trim($_POST['review_note'] ?? '') ?: null;
    $status     = $action === 'approve' ? 'approved' : 'rejected';
    if ($id) {
        $stmt = $connection2->prepare(
            'UPDATE sevenj_leave_requests SET status=?, review_note=?, reviewed_at=NOW() WHERE id=?'
        );
        $stmt->execute([$status, $reviewNote, $id]);
        $msg = 'success|'.($status === 'approved' ? '✅ อนุมัติใบลาสำเร็จ' : '❌ ปฏิเสธใบลาสำเร็จ');
    }

} elseif ($action === 'delete') {
    $id = (int)($_POST['lid'] ?? 0);
    if ($id) {
        $stmt = $connection2->prepare('DELETE FROM sevenj_leave_requests WHERE id=?');
        $stmt->execute([$id]);
        $msg = 'success|ลบใบลาสำเร็จ';
    }
}

// ─── Filters ──────────────────────────────────────────────────────────────────
$search        = trim($_GET['search'] ?? '');
$filterStatus  = trim($_GET['status'] ?? '');
$filterRole    = trim($_GET['role'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$limit         = 30;
$offset        = ($page - 1) * $limit;

$where  = [];
$params = [];
if ($search) {
    $where[]  = "(lr.requester_name LIKE ? OR lr.reason LIKE ? OR DATE_FORMAT(lr.leave_date,'%Y-%m-%d') LIKE ?)";
    $like = '%'.$search.'%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filterStatus) { $where[] = "lr.status=?";           $params[] = $filterStatus; }
if ($filterRole)   { $where[] = "lr.requester_role=?";   $params[] = $filterRole;   }
$whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

$stmtCount = $connection2->prepare("SELECT COUNT(*) FROM sevenj_leave_requests $whereSQL");
$stmtCount->execute($params);
$total = (int)$stmtCount->fetchColumn();
$pages = $total > 0 ? ceil($total / $limit) : 1;

// JOIN sevenj_students/teachers เพื่อแสดงข้อมูล linked
$stmtReq = $connection2->prepare("
    SELECT lr.*,
        COALESCE(st.displayName, t.displayName, lr.requester_name) AS disp_name,
        st.studentCode AS disp_stu_code,
        t.teacherCode  AS disp_tea_code
    FROM sevenj_leave_requests lr
    LEFT JOIN sevenj_students st ON st.id = lr.requester_id AND lr.requester_role = 'student'
    LEFT JOIN sevenj_teachers t  ON t.id  = lr.requester_id AND lr.requester_role = 'teacher'
    $whereSQL
    ORDER BY lr.created_at DESC LIMIT $limit OFFSET $offset
");
$stmtReq->execute($params);
$requests = $stmtReq->fetchAll(PDO::FETCH_ASSOC);

// Dropdowns for Add modal
$allStudentsDrop = $connection2->query("SELECT id, displayName, studentCode FROM sevenj_students WHERE status='active' ORDER BY displayName LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$allTeachersDrop = $connection2->query("SELECT id, displayName, teacherCode FROM sevenj_teachers WHERE status='active' ORDER BY displayName")->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = $connection2->query("SELECT status, COUNT(*) as cnt FROM sevenj_leave_requests GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
$statMap = ['pending'=>0,'approved'=>0,'rejected'=>0];
foreach ($stats as $s) $statMap[$s['status']] = (int)$s['cnt'];
$totalAll = array_sum($statMap);

[$alertType, $alertText] = $msg ? explode('|', $msg, 2) : ['', ''];

$statusLabel  = ['pending'=>'⏳ รอพิจารณา','approved'=>'✅ อนุมัติ','rejected'=>'❌ ปฏิเสธ'];
$statusColors = [
    'pending'  => ['#fef3c7','#92400e','#d97706'],
    'approved' => ['#d1fae5','#065f46','#059669'],
    'rejected' => ['#fee2e2','#991b1b','#dc2626'],
];
$roleLabel = ['student'=>'🎓 นักเรียน','teacher'=>'👨‍🏫 ครู','admin'=>'⚙️ Admin'];

function avatarColor($name) {
    $colors = ['#ea580c','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2','#65a30d'];
    $idx = array_sum(array_map('ord', str_split($name ?? '?'))) % count($colors);
    return $colors[$idx];
}
function initials($name) {
    $words = preg_split('/\s+/', trim($name ?? ''));
    $ini = '';
    foreach ($words as $w) { if ($w) $ini .= mb_strtoupper(mb_substr($w,0,1)); }
    return mb_substr($ini,0,2) ?: '?';
}
?>

<?php require_once __DIR__.'/_theme.php'; ?>
<style>
.lm-card{background:#fff;border-radius:10px;padding:.9rem 1rem;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:8px;}
.lm-avatar{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9rem;color:#fff;flex-shrink:0;}
.lm-badge{display:inline-block;border-radius:99px;padding:2px 10px;font-size:.75rem;font-weight:700;}
.lm-btn{padding:6px 14px;border-radius:7px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;}
.lm-btn-primary{background:#ea580c;color:#fff;}
.lm-btn-primary:hover{background:#c2410c;}
.lm-btn-success{background:#059669;color:#fff;}
.lm-btn-success:hover{background:#047857;}
.lm-btn-danger{background:#dc2626;color:#fff;}
.lm-btn-danger:hover{background:#b91c1c;}
.lm-btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db;}
.lm-btn-outline:hover{background:#f3f4f6;}
.lm-btn-icon{padding:5px;border-radius:6px;background:none;border:none;cursor:pointer;color:#9ca3af;}
.lm-btn-icon:hover{background:#fee2e2;color:#dc2626;}
.lm-search{padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:.88rem;outline:none;}
.lm-search:focus{border-color:#ea580c;box-shadow:0 0 0 2px #fff7ed;}
.lm-stat{background:#fff;border-radius:10px;padding:.7rem 1rem;box-shadow:0 1px 4px rgba(0,0,0,.07);text-align:center;border-top:3px solid;}
.lm-filter-tab{padding:5px 14px;border-radius:99px;font-size:.8rem;font-weight:600;cursor:pointer;border:1px solid #e5e7eb;text-decoration:none;display:inline-block;}
.lm-review-box{margin-top:8px;padding:8px 12px;background:#f8f7ff;border-left:3px solid #ea580c;border-radius:0 6px 6px 0;font-size:.82rem;color:#374151;}
/* Modals */
.lm-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center;}
.lm-modal-bg.open{display:flex;}
.lm-modal{background:#fff;border-radius:14px;width:100%;max-width:460px;padding:1.5rem;box-shadow:0 20px 60px rgba(0,0,0,.25);max-height:90vh;overflow-y:auto;}
.lm-modal h3{margin:0 0 1rem;font-size:1.1rem;font-weight:700;}
.lm-form-group{margin-bottom:12px;}
.lm-form-group label{display:block;font-size:.8rem;font-weight:600;color:#374151;margin-bottom:4px;}
.lm-form-group input,.lm-form-group select,.lm-form-group textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.88rem;box-sizing:border-box;outline:none;}
.lm-form-group input:focus,.lm-form-group select:focus,.lm-form-group textarea:focus{border-color:#ea580c;box-shadow:0 0 0 2px #fff7ed;}
.lm-grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.lm-page-nav{display:flex;gap:6px;justify-content:center;margin-top:1rem;flex-wrap:wrap;}
.lm-page-btn{padding:5px 12px;border-radius:6px;border:1px solid #d1d5db;background:#fff;font-size:.82rem;cursor:pointer;color:#374151;text-decoration:none;}
.lm-page-btn:hover{background:#f3f4f6;}
.lm-page-btn.active{background:#ea580c;color:#fff;border-color:#ea580c;}
</style>

<div style="max-width:100%;padding-bottom:2rem;">

<?php if ($alertText): ?>
<div style="background:<?= $alertType==='success'?'#d1fae5':'#fee2e2' ?>;border-radius:8px;padding:10px 16px;margin-bottom:1rem;color:<?= $alertType==='success'?'#065f46':'#991b1b' ?>;"><?= htmlspecialchars($alertText) ?></div>
<?php endif; ?>

<!-- Header -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:1rem;">
    <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0;">📋 จัดการใบลา</h2>
    <button class="lm-btn lm-btn-primary" onclick="openModal('add')">+ เพิ่มใบลา</button>
</div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin-bottom:1.25rem;">
    <div class="lm-stat" style="border-color:#6b7280;">
        <div style="font-size:1.5rem;font-weight:800;color:#1f2937;"><?= $totalAll ?></div>
        <div style="font-size:.72rem;color:#6b7280;">ทั้งหมด</div>
    </div>
    <div class="lm-stat" style="border-color:#d97706;">
        <div style="font-size:1.5rem;font-weight:800;color:#92400e;"><?= $statMap['pending'] ?></div>
        <div style="font-size:.72rem;color:#6b7280;">⏳ รอพิจารณา</div>
    </div>
    <div class="lm-stat" style="border-color:#059669;">
        <div style="font-size:1.5rem;font-weight:800;color:#065f46;"><?= $statMap['approved'] ?></div>
        <div style="font-size:.72rem;color:#6b7280;">✅ อนุมัติ</div>
    </div>
    <div class="lm-stat" style="border-color:#dc2626;">
        <div style="font-size:1.5rem;font-weight:800;color:#991b1b;"><?= $statMap['rejected'] ?></div>
        <div style="font-size:.72rem;color:#6b7280;">❌ ปฏิเสธ</div>
    </div>
</div>

<!-- Filters -->
<form method="get" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:1rem;align-items:center;">
    <input type="hidden" name="q" value="/modules/7j/leave_management.php">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="lm-search" placeholder="ค้นหาชื่อ, เหตุผล, วันที่..." style="flex:1;min-width:160px;">
    <select name="status" class="lm-search">
        <option value="">— สถานะทั้งหมด —</option>
        <option value="pending"  <?= $filterStatus==='pending' ?'selected':'' ?>>⏳ รอพิจารณา</option>
        <option value="approved" <?= $filterStatus==='approved'?'selected':'' ?>>✅ อนุมัติ</option>
        <option value="rejected" <?= $filterStatus==='rejected'?'selected':'' ?>>❌ ปฏิเสธ</option>
    </select>
    <button type="submit" class="lm-btn lm-btn-primary">ค้นหา</button>
    <?php if ($search || $filterStatus): ?>
    <a href="?q=/modules/7j/leave_management.php" class="lm-btn lm-btn-outline">✕ ล้าง</a>
    <?php endif; ?>
</form>

<p style="font-size:.82rem;color:#6b7280;margin-bottom:.75rem;">แสดง <?= count($requests) ?>/<?= $total ?> รายการ</p>

<!-- List -->
<?php if (empty($requests)): ?>
<div style="text-align:center;padding:3rem 1rem;color:#9ca3af;">
    <div style="font-size:2.5rem;margin-bottom:.5rem;">📋</div>
    ยังไม่มีใบลา<?= $search || $filterStatus || $filterRole ? 'ที่ตรงกับเงื่อนไข' : '' ?>
</div>
<?php else: ?>

<?php foreach ($requests as $r):
    $sc   = $statusColors[$r['status']] ?? ['#f3f4f6','#374151','#6b7280'];
    $slbl = $statusLabel[$r['status']] ?? $r['status'];
    $rlbl = $roleLabel[$r['requester_role']] ?? $r['requester_role'];
    $bg   = avatarColor($r['requester_name']);
    $ini  = initials($r['requester_name']);
    $dateFormatted = $r['leave_date'] ? date('d/m/Y', strtotime($r['leave_date'])) : '';
    $createdFormatted = $r['created_at'] ? date('d/m/Y H:i', strtotime($r['created_at'])) : '';
?>
<div class="lm-card">
    <div style="display:flex;align-items:flex-start;gap:12px;">
        <div class="lm-avatar" style="background:<?= $bg ?>;"><?= htmlspecialchars($ini) ?></div>
        <div style="flex:1;min-width:0;">
            <!-- Name + Role + Status -->
            <div style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;margin-bottom:4px;">
                <span style="font-weight:700;font-size:.95rem;"><?= htmlspecialchars($r['disp_name'] ?? $r['requester_name']) ?></span>
                <?php if ($r['disp_stu_code'] || $r['disp_tea_code']): ?>
                <span style="background:#fff7ed;color:#9a3412;border-radius:99px;padding:1px 8px;font-size:.7rem;font-weight:700;"><?= htmlspecialchars($r['disp_stu_code'] ?? $r['disp_tea_code']) ?></span>
                <?php endif; ?>
                <span style="background:#f3f4f6;color:#374151;border-radius:99px;padding:1px 8px;font-size:.72rem;font-weight:600;"><?= $rlbl ?></span>
                <span class="lm-badge" style="background:<?= $sc[0] ?>;color:<?= $sc[1] ?>;"><?= $slbl ?></span>
                <?php if ($r['requester_id']): ?><span style="background:#d1fae5;color:#065f46;border-radius:4px;padding:0 5px;font-size:.65rem;font-weight:700;">🔗</span><?php else: ?><span style="background:#fef3c7;color:#92400e;border-radius:4px;padding:0 5px;font-size:.65rem;font-weight:700;">⚠️</span><?php endif; ?>
            </div>
            <!-- Leave date + Created at -->
            <div style="font-size:.82rem;color:#374151;margin-bottom:3px;">
                📅 วันที่ลา: <strong><?= $dateFormatted ?></strong>
            </div>
            <div style="font-size:.82rem;color:#6b7280;margin-bottom:4px;">
                💬 เหตุผล: <?= htmlspecialchars($r['reason']) ?>
            </div>
            <div style="font-size:.72rem;color:#9ca3af;">ยื่นเมื่อ: <?= $createdFormatted ?></div>
            <!-- Review note -->
            <?php if ($r['review_note'] && $r['status'] !== 'pending'): ?>
            <div class="lm-review-box">
                💬 หมายเหตุผู้อนุมัติ: <?= htmlspecialchars($r['review_note']) ?>
            </div>
            <?php endif; ?>
        </div>
        <!-- Actions -->
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
            <?php if ($r['status'] === 'pending'): ?>
            <button class="lm-btn lm-btn-success" style="font-size:.78rem;padding:4px 12px;"
                onclick="openReview(<?= (int)$r['id'] ?>,'approve','<?= htmlspecialchars(addslashes($r['requester_name'])) ?>')">✅ อนุมัติ</button>
            <button class="lm-btn lm-btn-danger" style="font-size:.78rem;padding:4px 12px;"
                onclick="openReview(<?= (int)$r['id'] ?>,'reject','<?= htmlspecialchars(addslashes($r['requester_name'])) ?>')">❌ ปฏิเสธ</button>
            <?php endif; ?>
            <button class="lm-btn-icon" title="ลบ"
                onclick="confirmDelete(<?= (int)$r['id'] ?>,'<?= htmlspecialchars(addslashes($r['requester_name'])) ?>')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
            </button>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div class="lm-page-nav">
    <?php if ($page > 1): ?>
    <a href="?q=/modules/7j/leave_management.php&page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>" class="lm-page-btn">&#8249; ก่อน</a>
    <?php endif; ?>
    <?php for ($p = max(1,$page-2); $p <= min($pages,$page+2); $p++): ?>
    <a href="?q=/modules/7j/leave_management.php&page=<?= $p ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>" class="lm-page-btn <?= $p==$page?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($page < $pages): ?>
    <a href="?q=/modules/7j/leave_management.php&page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>" class="lm-page-btn">ถัดไป &#8250;</a>
    <?php endif; ?>
</div>
<div style="text-align:center;font-size:.8rem;color:#9ca3af;margin-top:6px;">หน้า <?= $page ?>/<?= $pages ?></div>
<?php endif; ?>
<?php endif; ?>

</div><!-- end main -->

<!-- ─── Add Leave Modal ─────────────────────────────────────────────────── -->
<div id="modal-add" class="lm-modal-bg">
<div class="lm-modal">
    <h3>📋 เพิ่มใบลา</h3>
    <form method="post">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="q" value="/modules/7j/leave_management.php">
        <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>"><?php endif; ?>

        <div class="lm-form-group">
            <label>บทบาท</label>
            <select name="requester_role" id="add-req-role" onchange="updateRequesterDropdown()">
                <option value="student">🎓 นักเรียน</option>
                <option value="teacher">👨‍🏫 ครู</option>
            </select>
        </div>
        <div class="lm-form-group" id="drop-student-wrap">
            <label>เลือกจากระบบ (แนะนำ)</label>
            <select name="requester_id" id="add-req-id" onchange="onRequesterChange()">
                <option value="">— เลือกนักเรียน —</option>
                <?php foreach ($allStudentsDrop as $st): ?>
                <option value="<?= htmlspecialchars($st['id']) ?>" data-name="<?= htmlspecialchars($st['displayName']) ?>">
                    <?= htmlspecialchars($st['displayName']) ?><?= $st['studentCode']?' ('.$st['studentCode'].')':'' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="lm-form-group" id="drop-teacher-wrap" style="display:none;">
            <label>เลือกจากระบบ (แนะนำ)</label>
            <select name="requester_id_t" id="add-req-id-t" onchange="onRequesterChangeT()">
                <option value="">— เลือกครู —</option>
                <?php foreach ($allTeachersDrop as $t): ?>
                <option value="<?= htmlspecialchars($t['id']) ?>" data-name="<?= htmlspecialchars($t['displayName']) ?>">
                    <?= htmlspecialchars($t['displayName']) ?><?= $t['teacherCode']?' ('.$t['teacherCode'].')':'' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="lm-form-group">
            <label>ชื่อผู้ยื่นลา *</label>
            <input type="text" name="requester_name" id="add-req-name" required placeholder="หรือกรอกเองได้">
        </div>
        <div class="lm-form-group">
            <label>วันที่ลา *</label>
            <input type="date" name="leave_date" required>
        </div>
        <div class="lm-form-group">
            <label>เหตุผล *</label>
            <textarea name="reason" rows="3" required placeholder="ระบุเหตุผลการลา..."></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
            <button type="button" class="lm-btn lm-btn-outline" onclick="closeModal('add')">ยกเลิก</button>
            <button type="submit" class="lm-btn lm-btn-primary">บันทึก</button>
        </div>
    </form>
</div>
</div>

<!-- ─── Approve/Reject Modal ────────────────────────────────────────────── -->
<div id="modal-review" class="lm-modal-bg">
<div class="lm-modal" style="max-width:400px;">
    <h3 id="review-title">ยืนยันการอนุมัติ</h3>
    <p id="review-desc" style="font-size:.88rem;color:#6b7280;margin:.25rem 0 1rem;"></p>
    <form method="post">
        <input type="hidden" name="q" value="/modules/7j/leave_management.php">
        <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
        <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>"><?php endif; ?>
        <input type="hidden" name="action" id="review-action" value="approve">
        <input type="hidden" name="lid" id="review-lid" value="0">
        <div class="lm-form-group">
            <label>หมายเหตุ (ถ้ามี)</label>
            <textarea name="review_note" id="review-note" rows="3" placeholder="เพิ่มหมายเหตุ..."></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
            <button type="button" class="lm-btn lm-btn-outline" onclick="closeModal('review')">ยกเลิก</button>
            <button type="submit" class="lm-btn" id="review-submit-btn" style="background:#059669;color:#fff;">ยืนยัน</button>
        </div>
    </form>
</div>
</div>

<!-- ─── Delete Confirm Modal ─────────────────────────────────────────────── -->
<div id="modal-delete" class="lm-modal-bg">
<div class="lm-modal" style="max-width:380px;">
    <h3 style="color:#dc2626;">ยืนยันการลบ</h3>
    <p id="del-text" style="color:#374151;margin:.5rem 0 1.25rem;"></p>
    <form method="post">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="q" value="/modules/7j/leave_management.php">
        <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
        <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>"><?php endif; ?>
        <input type="hidden" name="lid" id="del-lid" value="0">
        <div style="display:flex;justify-content:flex-end;gap:8px;">
            <button type="button" class="lm-btn lm-btn-outline" onclick="closeModal('delete')">ยกเลิก</button>
            <button type="submit" class="lm-btn lm-btn-danger">ลบ</button>
        </div>
    </form>
</div>
</div>

<script>
// Requester dropdown logic
function updateRequesterDropdown() {
    var role = document.getElementById('add-req-role').value;
    document.getElementById('drop-student-wrap').style.display = role === 'student' ? '' : 'none';
    document.getElementById('drop-teacher-wrap').style.display = role === 'teacher' ? '' : 'none';
    document.getElementById('add-req-id').value   = '';
    document.getElementById('add-req-id-t').value = '';
    document.getElementById('add-req-name').value = '';
}
function onRequesterChange() {
    var sel = document.getElementById('add-req-id');
    var opt = sel.options[sel.selectedIndex];
    if (opt.value) document.getElementById('add-req-name').value = opt.dataset.name || '';
}
function onRequesterChangeT() {
    var sel = document.getElementById('add-req-id-t');
    var opt = sel.options[sel.selectedIndex];
    if (opt.value) {
        document.getElementById('add-req-name').value = opt.dataset.name || '';
        // copy value to requester_id
        document.getElementById('add-req-id').value = opt.value;
    }
}
function openModal(id) { document.getElementById('modal-' + id).classList.add('open'); }
function closeModal(id) { document.getElementById('modal-' + id).classList.remove('open'); }
document.querySelectorAll('.lm-modal-bg').forEach(function(bg) {
    bg.addEventListener('click', function(e) { if (e.target === bg) bg.classList.remove('open'); });
});

function openReview(lid, action, name) {
    document.getElementById('review-lid').value    = lid;
    document.getElementById('review-action').value = action;
    document.getElementById('review-note').value   = '';
    var isApprove = action === 'approve';
    document.getElementById('review-title').textContent  = isApprove ? '✅ ยืนยันอนุมัติ' : '❌ ยืนยันปฏิเสธ';
    document.getElementById('review-desc').textContent   = 'ใบลาของ "' + name + '"';
    var btn = document.getElementById('review-submit-btn');
    btn.textContent = isApprove ? 'อนุมัติ' : 'ปฏิเสธ';
    btn.style.background = isApprove ? '#059669' : '#dc2626';
    openModal('review');
}

function confirmDelete(lid, name) {
    document.getElementById('del-lid').value  = lid;
    document.getElementById('del-text').textContent = 'ต้องการลบใบลาของ "' + name + '" ใช่หรือไม่?';
    openModal('delete');
}
</script>
