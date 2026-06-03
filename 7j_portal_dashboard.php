<?php
/**
 * 7J English Center — Portal Dashboard
 * แสดงลิ้งก์ Google Meet และข้อมูลของนักเรียน/ครู
 */
session_start();

if (empty($_SESSION['7j_user_id'])) {
    header('Location: /MyNewShool/7j_portal.php');
    exit;
}

// Logout
if (($_GET['action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: /MyNewShool/7j_portal.php');
    exit;
}

require_once __DIR__ . '/config.php';
$pdo = new PDO(
    "mysql:host={$databaseServer};dbname={$databaseName};charset=utf8mb4",
    $databaseUsername, $databasePassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$userId   = $_SESSION['7j_user_id'];
$userType = $_SESSION['7j_user_type'];  // 'student' | 'teacher'
$userName = $_SESSION['7j_user_name'];
$userCode = $_SESSION['7j_user_code'];

// ─── ดึงข้อมูลตามประเภทผู้ใช้ ───────────────────────────────────────────────
$meetLink     = null;
$teacherName  = null;
$totalClasses = null;
$doneclasses  = null;
$myStudents   = [];

if ($userType === 'student') {
    $stmt = $pdo->prepare("
        SELECT s.googleMeetLink, s.totalClasses, s.completedClasses,
               t.displayName AS teacherName, t.googleMeetLink AS teacherMeetLink
        FROM sevenj_students s
        LEFT JOIN sevenj_schedule sch ON sch.student_id = s.id AND sch.status = 'active'
        LEFT JOIN sevenj_teachers t ON t.id = sch.teacher_ref_id
        WHERE s.id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row) {
        $meetLink     = $row['teacherMeetLink'] ?: $row['googleMeetLink'];
        $teacherName  = $row['teacherName'];
        $totalClasses = (int)$row['totalClasses'];
        $doneclasses  = (int)$row['completedClasses'];
    }
} else {
    // teacher
    $stmt = $pdo->prepare("SELECT googleMeetLink FROM sevenj_teachers WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row) $meetLink = $row['googleMeetLink'];

    // นักเรียนที่อยู่ใต้ครูคนนี้
    $stmt2 = $pdo->prepare("
        SELECT displayName, studentCode, googleMeetLink,
               totalClasses, completedClasses
        FROM sevenj_students
        WHERE teacherId = ? AND status = 'active'
        ORDER BY displayName
    ");
    $stmt2->execute([$userId]);
    $myStudents = $stmt2->fetchAll();
}

$now = new DateTime();
$thDays = ['Sunday'=>'อาทิตย์','Monday'=>'จันทร์','Tuesday'=>'อังคาร',
           'Wednesday'=>'พุธ','Thursday'=>'พฤหัสบดี','Friday'=>'ศุกร์','Saturday'=>'เสาร์'];
$thDay  = $thDays[$now->format('l')] ?? '';
$thDate = $thDay . ' ' . $now->format('d/m/') . ($now->format('Y') + 543);

$pct = ($totalClasses && $totalClasses > 0) ? min(100, round($doneclasses / $totalClasses * 100)) : 0;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>7J English Center — My Classroom</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    min-height: 100vh;
    background: #FFF8E7;
    font-family: 'Segoe UI', Tahoma, sans-serif;
    color: #1f2937;
}

/* ── Topbar ── */
.topbar {
    background: linear-gradient(135deg, #1A2A5E, #E8640A);
    padding: 0 24px;
    display: flex; align-items: center; justify-content: space-between;
    height: 58px;
    box-shadow: 0 2px 8px rgba(0,0,0,.2);
}
.topbar-brand { color: #fff; font-weight: 800; font-size: 1.05rem; display: flex; align-items: center; gap: 10px; }
.topbar-right { display: flex; align-items: center; gap: 12px; }
.topbar-user  { color: rgba(255,255,255,.9); font-size: .82rem; }
.btn-logout {
    background: rgba(255,255,255,.15);
    color: #fff; border: 1px solid rgba(255,255,255,.3);
    padding: 6px 14px; border-radius: 6px; font-size: .8rem;
    cursor: pointer; text-decoration: none; transition: background .15s;
}
.btn-logout:hover { background: rgba(255,255,255,.25); }

/* ── Content ── */
.page { max-width: 760px; margin: 0 auto; padding: 28px 20px 60px; }

/* Date badge */
.date-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: #fff; border: 1px solid #F5A623;
    border-radius: 8px; padding: 5px 14px;
    font-size: .8rem; font-weight: 600; color: #1A2A5E;
    margin-bottom: 20px;
}

/* ── Meet link card ── */
.meet-card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,.09);
    overflow: hidden;
    margin-bottom: 20px;
}
.meet-card-header {
    background: linear-gradient(135deg, #1A2A5E, #E8640A);
    padding: 18px 24px;
    color: #fff; font-weight: 700; font-size: 1rem;
    display: flex; align-items: center; gap: 10px;
}
.meet-card-body { padding: 24px; }

.meet-link-box {
    display: flex; align-items: center; gap: 12px;
    background: #f0fdf4; border: 2px solid #86efac;
    border-radius: 12px; padding: 16px 20px;
    margin-bottom: 16px;
}
.meet-link-icon { font-size: 2rem; flex-shrink: 0; }
.meet-link-text { flex: 1; min-width: 0; }
.meet-link-label { font-size: .72rem; font-weight: 700; color: #059669; text-transform: uppercase; letter-spacing: .06em; }
.meet-link-url {
    font-size: .88rem; color: #1f2937; font-weight: 600;
    word-break: break-all; margin-top: 2px;
}

.meet-no-link {
    background: #fff3cd; border: 2px dashed #F5A623;
    border-radius: 12px; padding: 24px;
    text-align: center; color: #92400e;
    margin-bottom: 16px;
}
.meet-no-link-icon { font-size: 2.5rem; margin-bottom: 8px; }
.meet-no-link h3 { font-size: 1rem; font-weight: 700; margin-bottom: 4px; }
.meet-no-link p  { font-size: .84rem; opacity: .8; }

.btn-join {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; padding: 14px;
    background: linear-gradient(135deg, #16a34a, #22c55e);
    color: #fff; border: none; border-radius: 10px;
    font-size: 1.05rem; font-weight: 800;
    cursor: pointer; text-decoration: none;
    transition: opacity .15s, box-shadow .15s;
    margin-bottom: 10px;
}
.btn-join:hover { opacity: .9; box-shadow: 0 4px 16px rgba(22,163,74,.35); }

.btn-copy {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    width: 100%; padding: 10px;
    background: #fff; border: 1.5px solid #d1d5db; border-radius: 8px;
    color: #374151; font-size: .88rem; font-weight: 600;
    cursor: pointer; transition: background .15s;
}
.btn-copy:hover { background: #f9fafb; }
.btn-copy.copied { border-color: #86efac; color: #16a34a; background: #f0fdf4; }

/* ── Info card ── */
.info-card {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,.07);
    overflow: hidden;
    margin-bottom: 20px;
}
.info-card-header {
    background: #FFF8E7; border-bottom: 2px solid #F5A623;
    padding: 12px 20px;
    font-weight: 700; font-size: .88rem; color: #1A2A5E;
    display: flex; align-items: center; gap: 8px;
}
.info-card-body { padding: 20px; }

/* Progress */
.progress-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
.progress-label { font-size: .8rem; color: #6b7280; }
.progress-val   { font-size: .82rem; font-weight: 700; color: #E8640A; }
.pbar { height: 8px; background: #e5e7eb; border-radius: 99px; overflow: hidden; margin-bottom: 12px; }
.pbar-fill { height: 100%; border-radius: 99px; background: linear-gradient(90deg, #E8640A, #F5A623); transition: width .8s ease; }

/* Info rows */
.info-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f3f4f6; }
.info-row:last-child { border-bottom: none; }
.info-row-icon { font-size: 1.1rem; width: 24px; text-align: center; flex-shrink: 0; }
.info-row-label { font-size: .78rem; color: #9ca3af; width: 80px; flex-shrink: 0; }
.info-row-value { font-size: .88rem; font-weight: 600; color: #1f2937; }

/* Student list (for teacher) */
.stu-row {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 0; border-bottom: 1px solid #f3f4f6;
}
.stu-row:last-child { border-bottom: none; }
.stu-avatar {
    width: 38px; height: 38px; border-radius: 50%;
    background: linear-gradient(135deg, #1A2A5E, #E8640A);
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: .9rem; flex-shrink: 0;
}
.stu-name { font-weight: 700; font-size: .9rem; color: #1f2937; }
.stu-code { font-size: .75rem; color: #9ca3af; }
.stu-link-badge {
    margin-left: auto; flex-shrink: 0;
    font-size: .72rem; font-weight: 700;
    padding: 3px 10px; border-radius: 99px;
}
.has-link    { background: #dcfce7; color: #166534; }
.no-link     { background: #fee2e2; color: #991b1b; }

/* Welcome */
.welcome-text { font-size: 1.1rem; font-weight: 700; color: #1A2A5E; margin-bottom: 4px; }
.welcome-sub  { font-size: .82rem; color: #6b7280; margin-bottom: 20px; }
</style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
    <div class="topbar-brand">
        <a href="javascript:history.back()" class="btn-logout" style="margin-right:8px;">← ย้อนกลับ</a>
        <span>🎓</span> 7J English Center
    </div>
    <div class="topbar-right">
        <div class="topbar-user">
            <?= $userType === 'student' ? '🎒' : '👨‍🏫' ?>
            <?= htmlspecialchars($userName) ?>
            <span style="opacity:.6;margin-left:4px;">(<?= htmlspecialchars($userCode) ?>)</span>
        </div>
        <a href="?action=logout" class="btn-logout">ออกจากระบบ</a>
    </div>
</div>

<div class="page">

    <!-- Date -->
    <div class="date-badge">📅 <?= $thDate ?></div>

    <!-- Welcome -->
    <div class="welcome-text">
        สวัสดี, <?= htmlspecialchars($userName) ?>!
        <?= $userType === 'student' ? '🎒' : '👨‍🏫' ?>
    </div>
    <div class="welcome-sub">
        <?= $userType === 'student' ? 'ห้องเรียนออนไลน์ของคุณพร้อมแล้ว' : 'จัดการห้องเรียนและนักเรียนของคุณ' ?>
    </div>

    <!-- Google Meet Link -->
    <div class="meet-card">
        <div class="meet-card-header">
            📹 ลิ้งก์ Google Meet ของคุณ
        </div>
        <div class="meet-card-body">
            <?php if ($meetLink): ?>
            <div class="meet-link-box">
                <div class="meet-link-icon">🔗</div>
                <div class="meet-link-text">
                    <div class="meet-link-label">ลิ้งก์ห้องเรียนออนไลน์</div>
                    <div class="meet-link-url" id="meet-url"><?= htmlspecialchars($meetLink) ?></div>
                </div>
            </div>
            <a href="<?= htmlspecialchars($meetLink) ?>" target="_blank" rel="noopener" class="btn-join">
                📹 เข้าร่วมห้องเรียน Google Meet
            </a>
            <button class="btn-copy" id="copy-btn" onclick="copyLink()">
                📋 คัดลอกลิ้งก์
            </button>
            <?php else: ?>
            <div class="meet-no-link">
                <div class="meet-no-link-icon">⏳</div>
                <h3>ยังไม่มีลิ้งก์ห้องเรียน</h3>
                <p>ลิ้งก์ Google Meet จะปรากฏที่นี่เมื่อผู้ดูแลระบบส่งมาให้</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($userType === 'student'): ?>
    <!-- ข้อมูลนักเรียน -->
    <div class="info-card">
        <div class="info-card-header">📊 ความคืบหน้าการเรียน</div>
        <div class="info-card-body">
            <?php if ($totalClasses !== null): ?>
            <div class="progress-row">
                <span class="progress-label">เรียนแล้ว / ทั้งหมด</span>
                <span class="progress-val"><?= $doneclasses ?> / <?= $totalClasses ?> ครั้ง (<?= $pct ?>%)</span>
            </div>
            <div class="pbar">
                <div class="pbar-fill" style="width:<?= $pct ?>%"></div>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span class="info-row-icon">🆔</span>
                <span class="info-row-label">รหัสนักเรียน</span>
                <span class="info-row-value"><?= htmlspecialchars($userCode) ?></span>
            </div>
            <?php if ($teacherName): ?>
            <div class="info-row">
                <span class="info-row-icon">👨‍🏫</span>
                <span class="info-row-label">ครูผู้สอน</span>
                <span class="info-row-value"><?= htmlspecialchars($teacherName) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($totalClasses !== null): ?>
            <div class="info-row">
                <span class="info-row-icon">📚</span>
                <span class="info-row-label">คงเหลือ</span>
                <span class="info-row-value" style="color:#E8640A;"><?= max(0, $totalClasses - $doneclasses) ?> ครั้ง</span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php else: ?>
    <!-- ครู: แสดงนักเรียนในความดูแล -->
    <div class="info-card">
        <div class="info-card-header">
            🎒 นักเรียนในความดูแล
            <span style="font-size:.75rem;font-weight:400;opacity:.7;"><?= count($myStudents) ?> คน</span>
        </div>
        <div class="info-card-body">
            <?php if (empty($myStudents)): ?>
            <div style="text-align:center;padding:20px;color:#9ca3af;font-size:.85rem;">
                ยังไม่มีนักเรียนในความดูแล
            </div>
            <?php else: ?>
            <?php foreach ($myStudents as $s): ?>
            <?php $initial = mb_substr($s['displayName'], 0, 1, 'UTF-8'); ?>
            <div class="stu-row">
                <div class="stu-avatar"><?= htmlspecialchars($initial) ?></div>
                <div style="flex:1;min-width:0;">
                    <div class="stu-name"><?= htmlspecialchars($s['displayName']) ?></div>
                    <div class="stu-code">
                        <?= htmlspecialchars($s['studentCode']) ?>
                        · เรียนแล้ว <?= (int)$s['completedClasses'] ?>/<?= (int)$s['totalClasses'] ?> ครั้ง
                    </div>
                </div>
                <span class="stu-link-badge <?= $s['googleMeetLink'] ? 'has-link' : 'no-link' ?>">
                    <?= $s['googleMeetLink'] ? '✓ มีลิ้งก์' : '— ไม่มีลิ้งก์' ?>
                </span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
function copyLink() {
    var url = document.getElementById('meet-url');
    if (!url) return;
    navigator.clipboard.writeText(url.textContent.trim()).then(function() {
        var btn = document.getElementById('copy-btn');
        btn.classList.add('copied');
        btn.innerHTML = '✅ คัดลอกแล้ว!';
        setTimeout(function() {
            btn.classList.remove('copied');
            btn.innerHTML = '📋 คัดลอกลิ้งก์';
        }, 2500);
    }).catch(function() {
        var range = document.createRange();
        range.selectNode(url);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        document.execCommand('copy');
        window.getSelection().removeAllRanges();
    });
}
</script>
</body>
</html>
