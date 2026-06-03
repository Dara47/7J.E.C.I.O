<?php
/*
 * 7J English Center — User Management
 * Ported from UserManagement.tsx
 * Tabs: Students | Teachers — search, add, edit, delete
 */

// ─── POST handlers ───────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';
$msg = '';

function nextStudentCode($conn) {
    $row = $conn->query("SELECT studentCode FROM sevenj_students WHERE studentCode REGEXP '^S[0-9]+$' ORDER BY CAST(SUBSTRING(studentCode,2) AS UNSIGNED) DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row && preg_match('/^S(\d+)$/', $row['studentCode'], $m)) {
        return 'S' . str_pad((int)$m[1] + 1, 6, '0', STR_PAD_LEFT);
    }
    return 'S260001';
}

function nextTeacherCode($conn) {
    $row = $conn->query("SELECT teacherCode FROM sevenj_teachers WHERE teacherCode REGEXP '^T[0-9]+$' ORDER BY CAST(SUBSTRING(teacherCode,2) AS UNSIGNED) DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row && preg_match('/^T(\d+)$/', $row['teacherCode'], $m)) {
        return 'T' . str_pad((int)$m[1] + 1, 6, '0', STR_PAD_LEFT);
    }
    return 'T260001';
}

function safeStr($v) { return trim($v ?? ''); }
function safeNullStr($v) { $s = trim($v ?? ''); return $s === '' ? null : $s; }
function safeNullInt($v) { $s = trim($v ?? ''); return $s === '' ? null : (int)$s; }

if ($action === 'add_student') {
    $displayName = safeStr($_POST['displayName'] ?? '');
    if ($displayName === '') { $msg = 'error|กรุณากรอกชื่อ'; }
    else {
        $code = nextStudentCode($connection2);
        $id   = 'student_' . time() . rand(100,999);
        $totalClasses = max(1, (int)($_POST['totalClasses'] ?? 20));
        $stmt = $connection2->prepare(
            'INSERT INTO sevenj_students
             (id,studentCode,displayName,nickname,age,teacherId,googleMeetLink,totalClasses,completedClasses,status)
             VALUES (?,?,?,?,?,?,?,?,0,"active")'
        );
        $stmt->execute([
            $id, $code, $displayName,
            safeNullStr($_POST['nickname'] ?? ''),
            safeNullInt($_POST['age'] ?? ''),
            safeNullStr($_POST['teacherId'] ?? ''),
            safeNullStr($_POST['googleMeetLink'] ?? ''),
            $totalClasses,
        ]);
        // Auto-set portal credentials: username=CODE, password=CODE
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $connection2->prepare('UPDATE sevenj_students SET username=?, password_hash=? WHERE id=?')
                    ->execute([$code, $hash, $id]);
        $msg = 'success|เพิ่มนักเรียนสำเร็จ ('.$code.') — Portal login: '.$code.' / '.$code;
    }
} elseif ($action === 'edit_student') {
    $sid  = safeStr($_POST['sid'] ?? '');
    $name = safeStr($_POST['displayName'] ?? '');
    if ($sid && $name) {
        $totalClasses = max(1, (int)($_POST['totalClasses'] ?? 20));
        $stmt = $connection2->prepare(
            'UPDATE sevenj_students
             SET displayName=?, nickname=?, age=?, totalClasses=?
             WHERE id=?'
        );
        $stmt->execute([
            $name,
            safeNullStr($_POST['nickname'] ?? ''),
            safeNullInt($_POST['age'] ?? ''),
            $totalClasses,
            $sid,
        ]);
        $msg = 'success|แก้ไขข้อมูลนักเรียนสำเร็จ';
    }
} elseif ($action === 'delete_student') {
    $sid = safeStr($_POST['sid'] ?? '');
    if ($sid) {
        // 1. ดึง schedule ids ของนักเรียนนี้
        $schIds = $connection2->prepare('SELECT id FROM sevenj_schedule WHERE student_id=?');
        $schIds->execute([$sid]);
        $ids = $schIds->fetchAll(PDO::FETCH_COLUMN);
        // 2. ลบ schedules ของนักเรียนนี้ (completions คงไว้ 30 วัน)
        $connection2->prepare('DELETE FROM sevenj_schedule WHERE student_id=?')->execute([$sid]);
        // 5. ลบใบลา
        $connection2->prepare("DELETE FROM sevenj_leave_requests WHERE requester_id=? AND requester_role='student'")->execute([$sid]);
        // 6. ลบ login logs
        $connection2->prepare('DELETE FROM sevenj_login_logs WHERE user_ref_id=?')->execute([$sid]);
        // 7. ลบนักเรียน
        $connection2->prepare('DELETE FROM sevenj_students WHERE id=?')->execute([$sid]);
        $msg = 'success|ลบนักเรียนและข้อมูลที่เกี่ยวข้องทั้งหมดสำเร็จ';
    }
} elseif ($action === 'set_student_password') {
    $sid   = safeStr($_POST['sid'] ?? '');
    $uname = safeNullStr($_POST['username'] ?? '');
    $pwd   = $_POST['new_password'] ?? '';
    if ($sid) {
        $errMsg = '';
        if ($uname !== null) {
            $dup = $connection2->prepare('SELECT id FROM sevenj_students WHERE username=? AND id!=? LIMIT 1');
            $dup->execute([$uname, $sid]);
            if ($dup->fetch()) { $errMsg = 'Username นี้ถูกใช้งานแล้ว'; }
        }
        if (!$errMsg) {
            if ($uname === null) {
                $connection2->prepare('UPDATE sevenj_students SET username=NULL, password_hash=NULL WHERE id=?')->execute([$sid]);
            } else {
                $hash = $pwd !== '' ? password_hash($pwd, PASSWORD_DEFAULT) : null;
                if ($hash) {
                    $connection2->prepare('UPDATE sevenj_students SET username=?, password_hash=? WHERE id=?')->execute([$uname, $hash, $sid]);
                } else {
                    $connection2->prepare('UPDATE sevenj_students SET username=? WHERE id=?')->execute([$uname, $sid]);
                }
            }
            $msg = 'success|ตั้งค่า Portal Access สำเร็จ';
        } else {
            $msg = 'error|'.$errMsg;
        }
    }
} elseif ($action === 'add_teacher') {
    $displayName = safeStr($_POST['displayName'] ?? '');
    if ($displayName === '') { $msg = 'error|กรุณากรอกชื่อ'; }
    else {
        $code = nextTeacherCode($connection2);
        $id   = 'teacher_' . time() . rand(100,999);
        $stmt = $connection2->prepare(
            'INSERT INTO sevenj_teachers
             (id,teacherCode,displayName,nickname,age,location,googleMeetLink,status)
             VALUES (?,?,?,?,?,?,?,"active")'
        );
        $stmt->execute([
            $id, $code, $displayName,
            safeNullStr($_POST['nickname'] ?? ''),
            safeNullInt($_POST['age'] ?? ''),
            safeNullStr($_POST['location'] ?? ''),
            safeNullStr($_POST['googleMeetLink'] ?? ''),
        ]);
        // Auto-set portal credentials: username=CODE, password=CODE
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $connection2->prepare('UPDATE sevenj_teachers SET username=?, password_hash=? WHERE id=?')
                    ->execute([$code, $hash, $id]);
        // ส่ง Google Meet link ไปยังนักเรียนที่ผูกกับครูนี้อัตโนมัติ
        $meetLink = safeNullStr($_POST['googleMeetLink'] ?? '');
        if ($meetLink) {
            $connection2->prepare('UPDATE sevenj_students SET googleMeetLink=? WHERE teacherId=?')
                        ->execute([$meetLink, $id]);
        }
        $msg = 'success|เพิ่มครูสำเร็จ ('.$code.') — Portal login: '.$code.' / '.$code;
    }
} elseif ($action === 'edit_teacher') {
    $tid  = safeStr($_POST['tid'] ?? '');
    $name = safeStr($_POST['displayName'] ?? '');
    if ($tid && $name) {
        $stmt = $connection2->prepare(
            'UPDATE sevenj_teachers
             SET displayName=?, nickname=?, age=?, location=?, googleMeetLink=?
             WHERE id=?'
        );
        $stmt->execute([
            $name,
            safeNullStr($_POST['nickname'] ?? ''),
            safeNullInt($_POST['age'] ?? ''),
            safeNullStr($_POST['location'] ?? ''),
            safeNullStr($_POST['googleMeetLink'] ?? ''),
            $tid,
        ]);
        // ส่ง Google Meet link ไปยังนักเรียนที่ผูกกับครูนี้อัตโนมัติ
        $meetLink = safeNullStr($_POST['googleMeetLink'] ?? '');
        if ($meetLink) {
            $connection2->prepare('UPDATE sevenj_students SET googleMeetLink=? WHERE teacherId=?')
                        ->execute([$meetLink, $tid]);
        }
        $msg = 'success|แก้ไขข้อมูลครูสำเร็จ';
    }
} elseif ($action === 'delete_teacher') {
    $tid = safeStr($_POST['tid'] ?? '');
    if ($tid) {
        // ── A. ลบนักเรียนที่ผูกกับครูนี้ (teacherId = tid) พร้อม cascade ──
        $stuList = $connection2->prepare('SELECT id FROM sevenj_students WHERE teacherId=?');
        $stuList->execute([$tid]);
        $stuIds = $stuList->fetchAll(PDO::FETCH_COLUMN);
        foreach ($stuIds as $sid) {
            // ดึง schedule ids ของนักเรียนนี้
            $sids = $connection2->prepare('SELECT id FROM sevenj_schedule WHERE student_id=?');
            $sids->execute([$sid]);
            $sSchIds = $sids->fetchAll(PDO::FETCH_COLUMN);
            $connection2->prepare('DELETE FROM sevenj_schedule WHERE student_id=?')->execute([$sid]); // completions คงไว้ 30 วัน
            $connection2->prepare("DELETE FROM sevenj_leave_requests WHERE requester_id=? AND requester_role='student'")->execute([$sid]);
            $connection2->prepare('DELETE FROM sevenj_login_logs WHERE user_ref_id=?')->execute([$sid]);
            $connection2->prepare('DELETE FROM sevenj_students WHERE id=?')->execute([$sid]);
        }

        // ── B. ลบ schedules ที่ครูนี้สอน (completions คงไว้ 30 วัน) ──
        $connection2->prepare('DELETE FROM sevenj_schedule WHERE teacher_ref_id=?')->execute([$tid]);

        // ── C. ลบข้อมูลของครูเอง ──
        $connection2->prepare('DELETE FROM sevenj_teacher_availability WHERE teacher_id=?')->execute([$tid]);
        $connection2->prepare("DELETE FROM sevenj_leave_requests WHERE requester_id=? AND requester_role='teacher'")->execute([$tid]);
        $connection2->prepare('DELETE FROM sevenj_login_logs WHERE user_ref_id=?')->execute([$tid]);
        $connection2->prepare('DELETE FROM sevenj_teachers WHERE id=?')->execute([$tid]);
        $msg = 'success|ลบครูและข้อมูลที่เกี่ยวข้องทั้งหมดสำเร็จ (รวมนักเรียน '.count($stuIds).' คน)';
    }
} elseif ($action === 'set_teacher_password') {
    $tid   = safeStr($_POST['tid'] ?? '');
    $uname = safeNullStr($_POST['username'] ?? '');
    $pwd   = $_POST['new_password'] ?? '';
    if ($tid) {
        $errMsg = '';
        if ($uname !== null) {
            $dup = $connection2->prepare('SELECT id FROM sevenj_teachers WHERE username=? AND id!=? LIMIT 1');
            $dup->execute([$uname, $tid]);
            if ($dup->fetch()) { $errMsg = 'Username นี้ถูกใช้งานแล้ว'; }
        }
        if (!$errMsg) {
            if ($uname === null) {
                $connection2->prepare('UPDATE sevenj_teachers SET username=NULL, password_hash=NULL WHERE id=?')->execute([$tid]);
            } else {
                $hash = $pwd !== '' ? password_hash($pwd, PASSWORD_DEFAULT) : null;
                if ($hash) {
                    $connection2->prepare('UPDATE sevenj_teachers SET username=?, password_hash=? WHERE id=?')->execute([$uname, $hash, $tid]);
                } else {
                    $connection2->prepare('UPDATE sevenj_teachers SET username=? WHERE id=?')->execute([$uname, $tid]);
                }
            }
            $msg = 'success|ตั้งค่า Portal Access ครูสำเร็จ';
        } else {
            $msg = 'error|'.$errMsg;
        }
    }
}

// ─── Fetch data ───────────────────────────────────────────────────────────────
$tab    = $_GET['tab'] ?? 'students';
$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 50;
$offset = ($page - 1) * $limit;

// Teachers (all, for dropdown)
$allTeachers = $connection2->query("SELECT id, displayName, teacherCode FROM sevenj_teachers WHERE status='active' ORDER BY displayName")->fetchAll(PDO::FETCH_ASSOC);
$teacherMap  = [];
foreach ($allTeachers as $t) { $teacherMap[$t['id']] = $t['displayName']; }

// Students
if ($search) {
    $sLike  = '%'.$search.'%';
    $sWhere = 'WHERE (displayName LIKE ? OR studentCode LIKE ? OR nickname LIKE ?)';
    $stmtSC = $connection2->prepare("SELECT COUNT(*) FROM sevenj_students $sWhere");
    $stmtSC->execute([$sLike, $sLike, $sLike]);
    $sTotal = (int)$stmtSC->fetchColumn();
    $stmtSL = $connection2->prepare("SELECT * FROM sevenj_students $sWhere ORDER BY displayName LIMIT $limit OFFSET $offset");
    $stmtSL->execute([$sLike, $sLike, $sLike]);
    $students = $stmtSL->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sTotal   = (int)$connection2->query("SELECT COUNT(*) FROM sevenj_students")->fetchColumn();
    $stmtSL   = $connection2->prepare("SELECT * FROM sevenj_students ORDER BY displayName LIMIT $limit OFFSET $offset");
    $stmtSL->execute();
    $students = $stmtSL->fetchAll(PDO::FETCH_ASSOC);
}
$sPages = ceil($sTotal / $limit);

// Teachers
if ($search) {
    $tLike  = '%'.$search.'%';
    $tWhere = 'WHERE (displayName LIKE ? OR teacherCode LIKE ? OR nickname LIKE ? OR location LIKE ?)';
    $stmtTC = $connection2->prepare("SELECT COUNT(*) FROM sevenj_teachers $tWhere");
    $stmtTC->execute([$tLike, $tLike, $tLike, $tLike]);
    $tTotal = (int)$stmtTC->fetchColumn();
    $stmtTL = $connection2->prepare("SELECT * FROM sevenj_teachers $tWhere ORDER BY displayName LIMIT $limit OFFSET $offset");
    $stmtTL->execute([$tLike, $tLike, $tLike, $tLike]);
    $teachers = $stmtTL->fetchAll(PDO::FETCH_ASSOC);
} else {
    $tTotal   = (int)$connection2->query("SELECT COUNT(*) FROM sevenj_teachers")->fetchColumn();
    $stmtTL   = $connection2->prepare("SELECT * FROM sevenj_teachers ORDER BY displayName LIMIT $limit OFFSET $offset");
    $stmtTL->execute();
    $teachers = $stmtTL->fetchAll(PDO::FETCH_ASSOC);
}
$tPages = ceil($tTotal / $limit);

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

$baseUrl = $_SERVER['REQUEST_URI'];
$baseUrl = preg_replace('/[&?]page=\d+/', '', $baseUrl);
$baseUrl = preg_replace('/[&?]tab=[^&]*/', '', $baseUrl);
$sep = strpos($baseUrl, '?') !== false ? '&' : '?';

?>
<?php require_once __DIR__.'/_theme.php'; ?>
<style>
.um-tab{padding:9px 20px;border:none;background:none;font-weight:600;font-size:.85rem;cursor:pointer;border-bottom:3px solid transparent;color:#6b7280;display:inline-flex;align-items:center;gap:6px;}
.um-tab.active{color:#ea580c;border-bottom-color:#ea580c;background:none;}
.um-tab:hover{color:#4b5563;}
.um-card{background:#fff;border-radius:10px;padding:.85rem 1rem;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:8px;display:flex;align-items:center;gap:12px;}
.um-avatar{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;color:#fff;flex-shrink:0;}
.um-badge{display:inline-block;background:#fff7ed;color:#9a3412;border-radius:99px;padding:1px 9px;font-size:.72rem;font-weight:700;margin-left:6px;}
.um-badge-status-active{background:#d1fae5;color:#065f46;}
.um-badge-status-inactive{background:#fee2e2;color:#991b1b;}
.um-badge-status-pending{background:#fef3c7;color:#92400e;}
.um-btn{padding:6px 14px;border-radius:7px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;}
.um-btn-primary{background:#ea580c;color:#fff;}
.um-btn-primary:hover{background:#c2410c;}
.um-btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db;}
.um-btn-outline:hover{background:#f3f4f6;}
.um-btn-danger{background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;}
.um-btn-danger:hover{background:#fecaca;}
.um-btn-icon{padding:5px;border-radius:6px;background:none;border:none;cursor:pointer;color:#6b7280;}
.um-btn-icon:hover{background:#f3f4f6;color:#111827;}
.um-search{width:100%;padding:9px 14px 9px 38px;border:1px solid #d1d5db;border-radius:8px;font-size:.9rem;outline:none;}
.um-search:focus{border-color:#ea580c;box-shadow:0 0 0 2px #fff7ed;}
.um-form-row{display:grid;gap:10px;margin-bottom:12px;}
.um-form-row.cols2{grid-template-columns:1fr 1fr;}
.um-form-group label{display:block;font-size:.8rem;font-weight:600;color:#374151;margin-bottom:4px;}
.um-form-group input,.um-form-group select,.um-form-group textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.88rem;box-sizing:border-box;outline:none;}
.um-form-group input:focus,.um-form-group select:focus,.um-form-group textarea:focus{border-color:#ea580c;box-shadow:0 0 0 2px #fff7ed;}
/* Modal */
.um-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center;}
.um-modal-bg.open{display:flex;}
.um-modal{background:#fff;border-radius:14px;width:100%;max-width:460px;padding:1.5rem;box-shadow:0 20px 60px rgba(0,0,0,.25);max-height:90vh;overflow-y:auto;}
.um-modal h3{margin:0 0 1rem;font-size:1.1rem;font-weight:700;}
.um-prog{height:6px;border-radius:99px;background:#e5e7eb;overflow:hidden;margin-top:4px;}
.um-prog-bar{height:100%;border-radius:99px;background:#ea580c;}
.um-page-nav{display:flex;gap:6px;justify-content:center;margin-top:1rem;flex-wrap:wrap;}
.um-page-btn{padding:5px 12px;border-radius:6px;border:1px solid #d1d5db;background:#fff;font-size:.82rem;cursor:pointer;color:#374151;text-decoration:none;}
.um-page-btn:hover{background:#f3f4f6;}
.um-page-btn.active{background:#ea580c;color:#fff;border-color:#ea580c;}
</style>

<div style="max-width:100%;padding-bottom:2rem;">

<?php if ($alertText): ?>
<div style="background:<?= $alertType==='success'?'#d1fae5':'#fee2e2' ?>;border-radius:8px;padding:10px 16px;margin-bottom:1rem;color:<?= $alertType==='success'?'#065f46':'#991b1b' ?>;"><?= htmlspecialchars($alertText) ?></div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:1rem;">
    <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0;">👥 User Management</h2>
    <div style="display:flex;gap:8px;">
        <?php if ($tab === 'students'): ?>
        <button class="um-btn um-btn-primary" onclick="openModal('add-student')">+ เพิ่มนักเรียน</button>
        <?php else: ?>
        <button class="um-btn um-btn-primary" onclick="openModal('add-teacher')">+ เพิ่มครู</button>
        <?php endif; ?>
    </div>
</div>

<!-- Search -->
<div style="position:relative;margin-bottom:1rem;">
    <svg style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#9ca3af;" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
    <form method="get" id="searchForm" style="display:flex;gap:8px;align-items:center;">
        <input type="hidden" name="q" value="/modules/7j/user_management.php">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="um-search"
               placeholder="<?= $tab==='students' ? 'ค้นหาชื่อ, รหัส, ชื่อเล่น...' : 'ค้นหาชื่อ, รหัส, สถานที่...' ?>">
        <button type="submit" class="um-btn um-btn-outline">ค้นหา</button>
        <?php if ($search): ?><a href="?q=/modules/7j/user_management.php&tab=<?= $tab ?>" class="um-btn um-btn-outline">✕</a><?php endif; ?>
    </form>
</div>

<!-- Tabs -->
<div style="border-bottom:1px solid #e5e7eb;margin-bottom:1.5rem;">
    <a href="?q=/modules/7j/user_management.php&tab=students<?= $search ? '&search='.urlencode($search) : '' ?>"
       class="um-tab <?= $tab==='students'?'active':'' ?>">
        🎓 นักเรียน
        <span style="background:<?= $tab==='students'?'#fff7ed':'#f3f4f6' ?>;color:<?= $tab==='students'?'#9a3412':'#6b7280' ?>;border-radius:99px;padding:1px 8px;font-size:.75rem;"><?= $sTotal ?></span>
    </a>
    <a href="?q=/modules/7j/user_management.php&tab=teachers<?= $search ? '&search='.urlencode($search) : '' ?>"
       class="um-tab <?= $tab==='teachers'?'active':'' ?>">
        👨‍🏫 ครู
        <span style="background:<?= $tab==='teachers'?'#fff7ed':'#f3f4f6' ?>;color:<?= $tab==='teachers'?'#9a3412':'#6b7280' ?>;border-radius:99px;padding:1px 8px;font-size:.75rem;"><?= $tTotal ?></span>
    </a>
</div>

<!-- ─── STUDENTS TAB ─────────────────────────────────────────────────────── -->
<?php if ($tab === 'students'): ?>

<?php if (empty($students)): ?>
<div style="text-align:center;padding:3rem 1rem;color:#9ca3af;">
    <?= $search ? 'ไม่พบผลลัพธ์' : 'ยังไม่มีนักเรียน' ?>
</div>
<?php else: ?>
<?php foreach ($students as $s):
    $iniS   = initials($s['displayName']);
    $bgS    = avatarColor($s['displayName']);
    $done   = (int)$s['completedClasses'];
    $total  = (int)$s['totalClasses'];
    $pct    = $total > 0 ? min(100, round($done/$total*100)) : 0;
    $remain = max(0, $total - $done);
    $tName  = isset($teacherMap[$s['teacherId']]) ? $teacherMap[$s['teacherId']] : null;
    $statusColor = $s['status']==='active' ? '#d1fae5|#065f46' : ($s['status']==='inactive' ? '#fee2e2|#991b1b' : '#fef3c7|#92400e');
    [$sBg,$sFg] = explode('|', $statusColor);
?>
<div class="um-card" id="row-s-<?= htmlspecialchars($s['id']) ?>">
    <div class="um-avatar" style="background:<?= $bgS ?>;"><?= htmlspecialchars($iniS) ?></div>
    <div style="flex:1;min-width:0;">
        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:4px;">
            <span style="font-weight:600;font-size:.95rem;"><?= htmlspecialchars($s['displayName']) ?></span>
            <?php if ($s['studentCode']): ?><span class="um-badge"><?= htmlspecialchars($s['studentCode']) ?></span><?php endif; ?>
            <span style="background:<?= $sBg ?>;color:<?= $sFg ?>;border-radius:99px;padding:1px 8px;font-size:.7rem;font-weight:600;"><?= $s['status'] ?></span>
            <?php if ($s['username']): ?>
            <span style="background:#fff7ed;color:#9a3412;border-radius:99px;padding:1px 8px;font-size:.7rem;font-weight:600;" title="Portal username">🔑 <?= htmlspecialchars($s['username']) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($s['nickname'] || $s['age']): ?>
        <div style="font-size:.8rem;color:#6b7280;margin-top:1px;">
            <?= $s['nickname'] ? '('.htmlspecialchars($s['nickname']).')' : '' ?>
            <?= $s['age'] ? ' อายุ '.(int)$s['age'].' ปี' : '' ?>
        </div>
        <?php endif; ?>
        <?php if ($tName): ?>
        <div style="font-size:.78rem;color:#6b7280;margin-top:1px;">ครู: <?= htmlspecialchars($tName) ?></div>
        <?php endif; ?>
        <div style="font-size:.78rem;color:#6b7280;margin-top:4px;">
            📚 คาบ <?= $done ?>/<?= $total ?> (เหลือ <?= $remain ?> คาบ)
            <div class="um-prog" style="width:120px;display:inline-block;vertical-align:middle;margin-left:6px;">
                <div class="um-prog-bar" style="width:<?= $pct ?>%;background:<?= $pct>=80?'#dc2626':($pct>=50?'#d97706':'#ea580c') ?>;"></div>
            </div>
        </div>
    </div>
    <div style="display:flex;gap:4px;flex-shrink:0;">
        <button class="um-btn-icon" title="แก้ไข"
            onclick="openEditStudent(<?= htmlspecialchars(json_encode([
                'id'=>$s['id'],'displayName'=>$s['displayName'],'nickname'=>$s['nickname']??'',
                'age'=>$s['age']??'','teacherId'=>$s['teacherId']??'','googleMeetLink'=>$s['googleMeetLink']??'',
                'totalClasses'=>(int)$s['totalClasses']
            ])) ?>)">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </button>
        <button class="um-btn-icon" title="ลบ" style="color:#dc2626;"
            onclick="confirmDelete('student','<?= htmlspecialchars($s['id']) ?>','<?= htmlspecialchars($s['displayName']) ?>')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
        </button>
    </div>
</div>
<?php endforeach; ?>

<!-- Student pagination -->
<?php if ($sPages > 1): ?>
<div class="um-page-nav">
    <?php if ($page > 1): ?><a href="?q=/modules/7j/user_management.php&tab=students&page=<?= $page-1 ?><?= $search?'&search='.urlencode($search):'' ?>" class="um-page-btn">&#8249; ก่อน</a><?php endif; ?>
    <?php for ($p=max(1,$page-2); $p<=min($sPages,$page+2); $p++): ?>
    <a href="?q=/modules/7j/user_management.php&tab=students&page=<?= $p ?><?= $search?'&search='.urlencode($search):'' ?>" class="um-page-btn <?= $p==$page?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($page < $sPages): ?><a href="?q=/modules/7j/user_management.php&tab=students&page=<?= $page+1 ?><?= $search?'&search='.urlencode($search):'' ?>" class="um-page-btn">ถัดไป &#8250;</a><?php endif; ?>
</div>
<div style="text-align:center;font-size:.8rem;color:#9ca3af;margin-top:6px;">หน้า <?= $page ?>/<?= $sPages ?> (ทั้งหมด <?= $sTotal ?> คน)</div>
<?php endif; ?>
<?php endif; ?>

<!-- ─── TEACHERS TAB ─────────────────────────────────────────────────────── -->
<?php elseif ($tab === 'teachers'): ?>

<?php if (empty($teachers)): ?>
<div style="text-align:center;padding:3rem 1rem;color:#9ca3af;">
    <?= $search ? 'ไม่พบผลลัพธ์' : 'ยังไม่มีครู' ?>
</div>
<?php else: ?>
<?php foreach ($teachers as $t):
    $iniT = initials($t['displayName']);
    $bgT  = avatarColor($t['displayName']);
    $statusColor = $t['status']==='active' ? '#d1fae5|#065f46' : ($t['status']==='inactive' ? '#fee2e2|#991b1b' : '#fef3c7|#92400e');
    [$tBg,$tFg] = explode('|', $statusColor);
?>
<div class="um-card" id="row-t-<?= htmlspecialchars($t['id']) ?>">
    <div class="um-avatar" style="background:<?= $bgT ?>;"><?= htmlspecialchars($iniT) ?></div>
    <div style="flex:1;min-width:0;">
        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:4px;">
            <span style="font-weight:600;font-size:.95rem;"><?= htmlspecialchars($t['displayName']) ?></span>
            <?php if ($t['teacherCode']): ?><span class="um-badge"><?= htmlspecialchars($t['teacherCode']) ?></span><?php endif; ?>
            <span style="background:<?= $tBg ?>;color:<?= $tFg ?>;border-radius:99px;padding:1px 8px;font-size:.7rem;font-weight:600;"><?= $t['status'] ?></span>
            <?php if ($t['username']): ?>
            <span style="background:#fef3c7;color:#92400e;border-radius:99px;padding:1px 8px;font-size:.7rem;font-weight:600;" title="Portal username">🔑 <?= htmlspecialchars($t['username']) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($t['nickname'] || $t['location']): ?>
        <div style="font-size:.8rem;color:#6b7280;margin-top:1px;">
            <?= $t['nickname'] ? '('.htmlspecialchars($t['nickname']).')' : '' ?>
            <?= $t['location'] ? ' — '.htmlspecialchars($t['location']) : '' ?>
        </div>
        <?php endif; ?>
        <?php if ($t['googleMeetLink']): ?>
        <div style="font-size:.78rem;margin-top:2px;">
            <a href="<?= htmlspecialchars($t['googleMeetLink']) ?>" target="_blank" style="color:#2563eb;">🎥 Meet Link</a>
        </div>
        <?php endif; ?>
    </div>
    <div style="display:flex;gap:4px;flex-shrink:0;">
        <button class="um-btn-icon" title="แก้ไข"
            onclick="openEditTeacher(<?= htmlspecialchars(json_encode([
                'id'=>$t['id'],'displayName'=>$t['displayName'],'nickname'=>$t['nickname']??'',
                'age'=>$t['age']??'','location'=>$t['location']??'','googleMeetLink'=>$t['googleMeetLink']??''
            ])) ?>)">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </button>
        <button class="um-btn-icon" title="ลบ" style="color:#dc2626;"
            onclick="confirmDelete('teacher','<?= htmlspecialchars($t['id']) ?>','<?= htmlspecialchars($t['displayName']) ?>')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
        </button>
    </div>
</div>
<?php endforeach; ?>

<!-- Teacher pagination -->
<?php if ($tPages > 1): ?>
<div class="um-page-nav">
    <?php if ($page > 1): ?><a href="?q=/modules/7j/user_management.php&tab=teachers&page=<?= $page-1 ?><?= $search?'&search='.urlencode($search):'' ?>" class="um-page-btn">&#8249; ก่อน</a><?php endif; ?>
    <?php for ($p=max(1,$page-2); $p<=min($tPages,$page+2); $p++): ?>
    <a href="?q=/modules/7j/user_management.php&tab=teachers&page=<?= $p ?><?= $search?'&search='.urlencode($search):'' ?>" class="um-page-btn <?= $p==$page?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($page < $tPages): ?><a href="?q=/modules/7j/user_management.php&tab=teachers&page=<?= $page+1 ?><?= $search?'&search='.urlencode($search):'' ?>" class="um-page-btn">ถัดไป &#8250;</a><?php endif; ?>
</div>
<div style="text-align:center;font-size:.8rem;color:#9ca3af;margin-top:6px;">หน้า <?= $page ?>/<?= $tPages ?> (ทั้งหมด <?= $tTotal ?> คน)</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

</div><!-- end main div -->

<!-- ─── MODALS ───────────────────────────────────────────────────────────── -->

<!-- Add Student Modal -->
<div id="modal-add-student" class="um-modal-bg">
<div class="um-modal">
    <h3>เพิ่มนักเรียน</h3>
    <form method="post">
        <input type="hidden" name="action" value="add_student">
        <input type="hidden" name="q" value="/modules/7j/user_management.php">
        <div class="um-form-row">
            <div class="um-form-group">
                <label>ชื่อ-นามสกุล *</label>
                <input type="text" name="displayName" required autofocus>
            </div>
        </div>
        <div class="um-form-row cols2">
            <div class="um-form-group"><label>ชื่อเล่น</label><input type="text" name="nickname"></div>
            <div class="um-form-group"><label>อายุ</label><input type="number" name="age" min="1" max="99"></div>
        </div>
        <div class="um-form-row">
            <div class="um-form-group">
                <label>จำนวนคาบเรียนทั้งหมด *</label>
                <input type="number" name="totalClasses" value="20" min="1" max="9999"
                       style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.88rem;box-sizing:border-box;"
                       placeholder="เช่น 20, 30, 60">
                <div style="font-size:.72rem;color:#9ca3af;margin-top:3px;">จำนวนคาบทั้งหมดตามแพ็กเกจที่ซื้อ</div>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
            <button type="button" class="um-btn um-btn-outline" onclick="closeModal('add-student')">ยกเลิก</button>
            <button type="submit" class="um-btn um-btn-primary">เพิ่ม</button>
        </div>
    </form>
</div>
</div>

<!-- Edit Student Modal -->
<div id="modal-edit-student" class="um-modal-bg">
<div class="um-modal">
    <h3>แก้ไขข้อมูลนักเรียน</h3>
    <form method="post">
        <input type="hidden" name="action" value="edit_student">
        <input type="hidden" name="q" value="/modules/7j/user_management.php">
        <input type="hidden" name="tab" value="students">
        <input type="hidden" name="sid" id="edit-s-id">
        <div class="um-form-row">
            <div class="um-form-group"><label>ชื่อ-นามสกุล *</label><input type="text" name="displayName" id="edit-s-name" required></div>
        </div>
        <div class="um-form-row cols2">
            <div class="um-form-group"><label>ชื่อเล่น</label><input type="text" name="nickname" id="edit-s-nick"></div>
            <div class="um-form-group"><label>อายุ</label><input type="number" name="age" id="edit-s-age" min="1" max="99"></div>
        </div>
        <div class="um-form-row">
            <div class="um-form-group">
                <label>จำนวนคาบเรียนทั้งหมด *</label>
                <input type="number" name="totalClasses" id="edit-s-total" value="20" min="1" max="9999"
                       style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.88rem;box-sizing:border-box;">
                <div style="font-size:.72rem;color:#9ca3af;margin-top:3px;">จำนวนคาบทั้งหมดตามแพ็กเกจ (การเพิ่ม/ลบคาบที่เรียนแล้วอยู่ที่เมนูอื่น)</div>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
            <button type="button" class="um-btn um-btn-outline" onclick="closeModal('edit-student')">ยกเลิก</button>
            <button type="submit" class="um-btn um-btn-primary">บันทึก</button>
        </div>
    </form>
</div>
</div>

<!-- Set Student Portal Access Modal -->
<div id="modal-student-password" class="um-modal-bg">
<div class="um-modal">
    <h3>🔑 ตั้งค่า Portal Access</h3>
    <p id="sp-name-label" style="font-size:.9rem;color:#6b7280;margin:-4px 0 14px;"></p>
    <form method="post">
        <input type="hidden" name="action" value="set_student_password">
        <input type="hidden" name="q" value="/modules/7j/user_management.php">
        <input type="hidden" name="tab" value="students">
        <input type="hidden" name="sid" id="sp-sid">
        <div class="um-form-row">
            <div class="um-form-group">
                <label>Username (สำหรับ login portal)</label>
                <input type="text" name="username" id="sp-username" placeholder="เช่น student01" autocomplete="off">
                <div style="font-size:.75rem;color:#9ca3af;margin-top:3px;">เว้นว่างเพื่อยกเลิก Portal Access</div>
            </div>
        </div>
        <div class="um-form-row">
            <div class="um-form-group">
                <label>Password ใหม่</label>
                <input type="password" name="new_password" id="sp-password" autocomplete="new-password" placeholder="เว้นว่างถ้าไม่ต้องการเปลี่ยน">
            </div>
        </div>
        <div style="background:#fff7ed;border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:.8rem;color:#9a3412;">
            Student Portal: <a href="http://localhost/MyNewShool/portal/student_login.php" target="_blank" style="color:#ea580c;">localhost/MyNewShool/portal/student_login.php</a>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
            <button type="button" class="um-btn um-btn-outline" onclick="closeModal('student-password')">ยกเลิก</button>
            <button type="submit" class="um-btn um-btn-primary">บันทึก</button>
        </div>
    </form>
</div>
</div>

<!-- Add Teacher Modal -->
<div id="modal-add-teacher" class="um-modal-bg">
<div class="um-modal">
    <h3>เพิ่มครู</h3>
    <form method="post">
        <input type="hidden" name="action" value="add_teacher">
        <input type="hidden" name="q" value="/modules/7j/user_management.php">
        <input type="hidden" name="tab" value="teachers">
        <div class="um-form-row">
            <div class="um-form-group"><label>ชื่อ-นามสกุล *</label><input type="text" name="displayName" required autofocus></div>
        </div>
        <div class="um-form-row cols2">
            <div class="um-form-group"><label>ชื่อเล่น</label><input type="text" name="nickname"></div>
            <div class="um-form-group"><label>อายุ</label><input type="number" name="age" min="1" max="99"></div>
        </div>
        <div class="um-form-row">
            <div class="um-form-group"><label>สถานที่/ที่อยู่</label><input type="text" name="location" placeholder="เช่น Bangkok, Thailand"></div>
        </div>
        <div class="um-form-row">
            <div class="um-form-group"><label>Google Meet Link</label><input type="url" name="googleMeetLink" placeholder="https://meet.google.com/..."></div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
            <button type="button" class="um-btn um-btn-outline" onclick="closeModal('add-teacher')">ยกเลิก</button>
            <button type="submit" class="um-btn um-btn-primary">เพิ่ม</button>
        </div>
    </form>
</div>
</div>

<!-- Set Teacher Portal Access Modal -->
<div id="modal-teacher-password" class="um-modal-bg">
<div class="um-modal">
    <h3>🔑 ตั้งค่า Portal Access — ครู</h3>
    <p id="tp-name-label" style="font-size:.9rem;color:#6b7280;margin:-4px 0 14px;"></p>
    <form method="post">
        <input type="hidden" name="action" value="set_teacher_password">
        <input type="hidden" name="q" value="/modules/7j/user_management.php">
        <input type="hidden" name="tab" value="teachers">
        <input type="hidden" name="tid" id="tp-tid">
        <div class="um-form-row">
            <div class="um-form-group">
                <label>Username (สำหรับ login portal)</label>
                <input type="text" name="username" id="tp-username" placeholder="เช่น teacher01" autocomplete="off">
                <div style="font-size:.75rem;color:#9ca3af;margin-top:3px;">เว้นว่างเพื่อยกเลิก Portal Access</div>
            </div>
        </div>
        <div class="um-form-row">
            <div class="um-form-group">
                <label>Password ใหม่</label>
                <input type="password" name="new_password" id="tp-password" autocomplete="new-password" placeholder="เว้นว่างถ้าไม่ต้องการเปลี่ยน">
            </div>
        </div>
        <div style="background:#fffbeb;border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:.8rem;color:#92400e;">
            Teacher Portal: <a href="http://localhost/MyNewShool/portal/teacher_login.php" target="_blank" style="color:#d97706;">localhost/MyNewShool/portal/teacher_login.php</a>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
            <button type="button" class="um-btn um-btn-outline" onclick="closeModal('teacher-password')">ยกเลิก</button>
            <button type="submit" class="um-btn um-btn-primary">บันทึก</button>
        </div>
    </form>
</div>
</div>

<!-- Edit Teacher Modal -->
<div id="modal-edit-teacher" class="um-modal-bg">
<div class="um-modal">
    <h3>แก้ไขข้อมูลครู</h3>
    <form method="post">
        <input type="hidden" name="action" value="edit_teacher">
        <input type="hidden" name="q" value="/modules/7j/user_management.php">
        <input type="hidden" name="tab" value="teachers">
        <input type="hidden" name="tid" id="edit-t-id">
        <div class="um-form-row">
            <div class="um-form-group"><label>ชื่อ-นามสกุล *</label><input type="text" name="displayName" id="edit-t-name" required></div>
        </div>
        <div class="um-form-row cols2">
            <div class="um-form-group"><label>ชื่อเล่น</label><input type="text" name="nickname" id="edit-t-nick"></div>
            <div class="um-form-group"><label>อายุ</label><input type="number" name="age" id="edit-t-age" min="1" max="99"></div>
        </div>
        <div class="um-form-row">
            <div class="um-form-group"><label>สถานที่/ที่อยู่</label><input type="text" name="location" id="edit-t-loc" placeholder="เช่น Bangkok, Thailand"></div>
        </div>
        <div class="um-form-row">
            <div class="um-form-group"><label>Google Meet Link</label><input type="url" name="googleMeetLink" id="edit-t-meet" placeholder="https://meet.google.com/..."></div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
            <button type="button" class="um-btn um-btn-outline" onclick="closeModal('edit-teacher')">ยกเลิก</button>
            <button type="submit" class="um-btn um-btn-primary">บันทึก</button>
        </div>
    </form>
</div>
</div>

<!-- Delete Confirm Modal -->
<div id="modal-delete" class="um-modal-bg">
<div class="um-modal" style="max-width:380px;">
    <h3 style="color:#dc2626;">ยืนยันการลบ</h3>
    <p id="del-confirm-text" style="margin:.5rem 0 1.25rem;color:#374151;"></p>
    <form method="post" id="del-form">
        <input type="hidden" name="q" value="/modules/7j/user_management.php">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <input type="hidden" name="action" id="del-action">
        <input type="hidden" name="sid" id="del-sid">
        <input type="hidden" name="tid" id="del-tid">
        <div style="display:flex;justify-content:flex-end;gap:8px;">
            <button type="button" class="um-btn um-btn-outline" onclick="closeModal('delete')">ยกเลิก</button>
            <button type="submit" class="um-btn" style="background:#dc2626;color:#fff;">ลบ</button>
        </div>
    </form>
</div>
</div>

<script>
function openModal(id) {
    document.getElementById('modal-' + id).classList.add('open');
}
function closeModal(id) {
    document.getElementById('modal-' + id).classList.remove('open');
}
// Close on backdrop click
document.querySelectorAll('.um-modal-bg').forEach(function(bg) {
    bg.addEventListener('click', function(e) {
        if (e.target === bg) bg.classList.remove('open');
    });
});

function openTeacherPassword(data) {
    document.getElementById('tp-tid').value = data.id;
    document.getElementById('tp-username').value = data.username || '';
    document.getElementById('tp-password').value = '';
    document.getElementById('tp-name-label').textContent = data.displayName;
    openModal('teacher-password');
}

function openStudentPassword(data) {
    document.getElementById('sp-sid').value = data.id;
    document.getElementById('sp-username').value = data.username || '';
    document.getElementById('sp-password').value = '';
    document.getElementById('sp-name-label').textContent = data.displayName;
    openModal('student-password');
}

function openEditStudent(data) {
    document.getElementById('edit-s-id').value    = data.id;
    document.getElementById('edit-s-name').value  = data.displayName;
    document.getElementById('edit-s-nick').value  = data.nickname || '';
    document.getElementById('edit-s-age').value   = data.age || '';
    document.getElementById('edit-s-total').value = data.totalClasses || 20;
    openModal('edit-student');
}

function openEditTeacher(data) {
    document.getElementById('edit-t-id').value = data.id;
    document.getElementById('edit-t-name').value = data.displayName;
    document.getElementById('edit-t-nick').value = data.nickname || '';
    document.getElementById('edit-t-age').value = data.age || '';
    document.getElementById('edit-t-loc').value = data.location || '';
    document.getElementById('edit-t-meet').value = data.googleMeetLink || '';
    openModal('edit-teacher');
}

function confirmDelete(type, id, name) {
    document.getElementById('del-confirm-text').textContent = 'ต้องการลบ "' + name + '" ใช่หรือไม่? การดำเนินการนี้ไม่สามารถย้อนกลับได้';
    document.getElementById('del-action').value = 'delete_' + type;
    document.getElementById('del-sid').value = type === 'student' ? id : '';
    document.getElementById('del-tid').value = type === 'teacher' ? id : '';
    openModal('delete');
}

// Auto-fill Google Meet Link จากครูที่เลือก
function autoFillTeacherMeet(sel, meetInputId) {
    var opt      = sel.options[sel.selectedIndex];
    var meetLink = opt ? (opt.getAttribute('data-meet') || '') : '';
    var inp      = document.getElementById(meetInputId);
    var hint     = document.getElementById(meetInputId.replace('-meet', '-meet-hint'));
    if (!inp) return;
    if (meetLink) {
        inp.value = meetLink;
        if (hint) hint.style.display = 'inline';
    } else {
        inp.value = '';
        if (hint) hint.style.display = 'none';
    }
}
</script>
