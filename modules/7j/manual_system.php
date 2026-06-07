<?php
/*
 * 7J English Center - Manual System (Session Management)
 * Ported from ManualSystem.tsx — tab: sessions
 * Features: add/deduct sessions, view history, manual check-in
 */

// Handle POST
$action = $_POST['action'] ?? '';
$msg = '';

if ($action === 'adjust') {
    $id     = (int)($_POST['schedule_id'] ?? 0);
    $type   = $_POST['adjust_type'] ?? 'add';
    $amount = max(1,(int)($_POST['amount'] ?? 1));
    $reason = trim($_POST['reason'] ?? '');
    $date   = $_POST['completed_date'] ?? date('Y-m-d');

    if ($id) {
        $stmtRow = $connection2->prepare("
            SELECT s.*,
                COALESCE(st.displayName, s.student_name) AS disp_student,
                COALESCE(st.studentCode, s.student_code) AS disp_code,
                COALESCE(t.displayName,  s.teacher_name) AS disp_teacher
            FROM sevenj_schedule s
            LEFT JOIN sevenj_students st ON st.id = s.student_id
            LEFT JOIN sevenj_teachers t  ON t.id  = s.teacher_ref_id
            WHERE s.id=?
        ");
        $stmtRow->execute([$id]);
        $row = $stmtRow->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $stuId = $row['student_id']    ?: null;
            $teaId = $row['teacher_ref_id'] ?: null;
            // ถ้า teacher_ref_id เป็น NULL ให้ lookup จากชื่อ
            if (!$teaId && $row['teacher_name']) {
                $stmtTL = $connection2->prepare("SELECT id FROM sevenj_teachers WHERE displayName=? AND status='active' LIMIT 1");
                $stmtTL->execute([$row['teacher_name']]);
                $tRow = $stmtTL->fetch(PDO::FETCH_ASSOC);
                if ($tRow) $teaId = $tRow['id'];
            }
            $sCode = $row['disp_code']    ?: $row['student_code'];
            $sName = $row['disp_student'] ?: $row['student_name'];
            $tName = $row['disp_teacher'] ?: $row['teacher_name'];
            $dow   = $row['day_of_week']  ?? '';
            $tstart= $row['time_start']   ?? '';

            if ($type === 'add') {
                $new = $row['completed_classes'] + $amount;
                $ins = $connection2->prepare("INSERT INTO sevenj_class_completions
                    (schedule_id, student_id, student_code, student_name,
                     teacher_ref_id, teacher_name, day_of_week, time_start,
                     session_number, completed_date, note)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                for ($i = 0; $i < $amount; $i++) {
                    $sess = $row['completed_classes'] + $i + 1;
                    $ins->execute([$id,$stuId,$sCode,$sName,$teaId,$tName,$dow,$tstart,$sess,$date,$reason]);
                }
                // อัปเดต completedClasses ใน sevenj_students
                if ($stuId) {
                    $connection2->prepare("UPDATE sevenj_students SET completedClasses=completedClasses+? WHERE id=?")
                        ->execute([$amount, $stuId]);
                }
            } else {
                $new = max(0, $row['completed_classes'] - $amount);
                // ลด completedClasses ใน sevenj_students
                if ($stuId) {
                    $connection2->prepare("UPDATE sevenj_students SET completedClasses=GREATEST(completedClasses-?,0) WHERE id=?")
                        ->execute([$amount, $stuId]);
                }
            }
            $new = min($new, $row['total_classes']);
            $upd = $connection2->prepare("UPDATE sevenj_schedule SET completed_classes=? WHERE id=?");
            $upd->execute([$new, $id]);
            $msg = 'success|'.($type==='add'?"เพิ่ม $amount คาบสำเร็จ":"หัก $amount คาบสำเร็จ");
        }
    }
} elseif ($action === 'delete_completion') {
    $cid = (int)($_POST['completion_id'] ?? 0);
    if ($cid) {
        $stmtC = $connection2->prepare("SELECT * FROM sevenj_class_completions WHERE id=?");
        $stmtC->execute([$cid]);
        $c = $stmtC->fetch(PDO::FETCH_ASSOC);
        if ($c) {
            $connection2->prepare("DELETE FROM sevenj_class_completions WHERE id=?")->execute([$cid]);
            $connection2->prepare("UPDATE sevenj_schedule SET completed_classes=GREATEST(completed_classes-1,0) WHERE id=?")->execute([$c['schedule_id']]);
        }
        $msg = 'success|ลบบันทึกคาบสำเร็จ';
    }
}

// Fetch — JOIN sevenj_students + sevenj_teachers เพื่อแสดงชื่อจาก DB จริง
$search  = trim($_GET['search'] ?? '');
$sParams = [];
if ($search) {
    $l = '%'.$search.'%';
    $sWhere = "WHERE (s.student_name LIKE ? OR s.student_code LIKE ? OR s.teacher_name LIKE ? OR st.displayName LIKE ? OR t.displayName LIKE ?)";
    $sParams = [$l, $l, $l, $l, $l];
} else {
    $sWhere = '';
}
$stmtStu = $connection2->prepare("
    SELECT s.*,
        COALESCE(st.displayName, s.student_name) AS disp_student,
        COALESCE(st.studentCode, s.student_code) AS disp_code,
        COALESCE(t.displayName,  s.teacher_name) AS disp_teacher
    FROM sevenj_schedule s
    LEFT JOIN sevenj_students st ON st.id = s.student_id
    LEFT JOIN sevenj_teachers t  ON t.id  = s.teacher_ref_id
    $sWhere
    ORDER BY disp_student
");
$stmtStu->execute($sParams);
$students = $stmtStu->fetchAll(PDO::FETCH_ASSOC);

// Recent completions — JOIN ด้วย
$recentDate = $_GET['date_filter'] ?? '';
if ($recentDate) {
    $rcWhere  = "WHERE DATE(c.completed_date)=?";
    $rcParams = [$recentDate];
} else {
    $rcWhere  = '';
    $rcParams = [];
}
$stmtRec = $connection2->prepare("
    SELECT c.*,
        COALESCE(st.displayName, c.student_name) AS disp_student,
        COALESCE(st.studentCode, c.student_code) AS disp_code,
        COALESCE(t.displayName,  c.teacher_name) AS disp_teacher
    FROM sevenj_class_completions c
    LEFT JOIN sevenj_students st ON st.id = c.student_id
    LEFT JOIN sevenj_teachers t  ON t.id  = c.teacher_ref_id
    $rcWhere
    ORDER BY c.created_at DESC LIMIT 50
");
$stmtRec->execute($rcParams);
$recents = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

[$alertType,$alertText] = $msg ? explode('|',$msg,2) : ['',''];
?>
<?php require_once __DIR__.'/_theme.php'; ?>
<style>
.ms-tab{padding:8px 18px;border:none;background:none;font-weight:600;font-size:.85rem;cursor:pointer;border-bottom:2px solid transparent;color:#6b7280;}
.ms-tab.active{color:#ea580c;border-bottom-color:#ea580c;}
.ms-card{background:#fff;border-radius:10px;padding:1rem 1.25rem;box-shadow:0 2px 6px rgba(0,0,0,.07);margin-bottom:10px;}
</style>

<div style="max-width:100%;padding-bottom:2rem;">
<h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0 0 1rem;">⚙️ Manual System — Session Management</h2>

<?php if ($alertText): ?>
<div style="background:<?= $alertType==='success'?'#d1fae5':'#fee2e2' ?>;border-radius:8px;padding:10px 16px;margin-bottom:1rem;color:<?= $alertType==='success'?'#065f46':'#991b1b' ?>;"><?= htmlspecialchars($alertText) ?></div>
<?php endif; ?>

<!-- Tabs -->
<div style="display:flex;gap:0;border-bottom:1px solid #e5e7eb;margin-bottom:1.5rem;">
    <button class="ms-tab active" onclick="showTab('sessions',this)">📋 จัดการ Session</button>
    <button class="ms-tab" onclick="showTab('history',this)">📜 ประวัติ</button>
</div>

<!-- Tab: Sessions -->
<div id="tab-sessions">
    <!-- Search -->
    <form method="get" style="display:flex;gap:8px;margin-bottom:1rem;">
        <input type="hidden" name="q" value="/modules/7j/manual_system.php">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
               placeholder="ค้นหานักเรียน, ครู, รหัส..."
               style="flex:1;border:1px solid #d1d5db;border-radius:8px;padding:7px 12px;font-size:.9rem;">
        <button type="submit" style="background:#ea580c;color:#fff;border:none;border-radius:8px;padding:7px 16px;cursor:pointer;font-weight:600;">ค้นหา</button>
        <?php if ($search): ?><a href="?q=/modules/7j/manual_system.php" style="background:#e5e7eb;color:#374151;border-radius:8px;padding:7px 14px;text-decoration:none;font-size:.9rem;">ล้าง</a><?php endif; ?>
    </form>

    <?php if (empty($students)): ?>
    <div style="text-align:center;padding:3rem;color:#9ca3af;">ไม่พบข้อมูล — <a href="/?q=/modules/7j/schedule_center.php&tab=schedule" style="color:#ea580c;">เพิ่มตารางเรียน</a></div>
    <?php else: foreach ($students as $s):
        $pct = $s['total_classes']>0?round($s['completed_classes']/$s['total_classes']*100):0;
        $sid = 'S'.$s['id'];
    ?>
    <div class="ms-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
            <div>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                    <span style="background:#fff7ed;color:#ea580c;padding:2px 8px;border-radius:20px;font-size:.72rem;font-weight:600;"><?= htmlspecialchars($s['disp_code'] ?? $s['student_code']) ?></span>
                    <span style="font-weight:700;font-size:.95rem;color:#1f2937;"><?= htmlspecialchars($s['disp_student'] ?? $s['student_name']) ?></span>
                </div>
                <div style="font-size:.8rem;color:#6b7280;">👨‍🏫 <?= htmlspecialchars($s['disp_teacher'] ?? $s['teacher_name']) ?> &nbsp;|&nbsp; <?= htmlspecialchars($s['course']) ?></div>
                <div style="font-size:.8rem;color:#6b7280;margin-top:2px;">🗓 <?= htmlspecialchars($s['day_of_week']) ?> <?= htmlspecialchars($s['time_start']) ?>-<?= htmlspecialchars($s['time_end']) ?></div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:1.4rem;font-weight:800;color:#ea580c;"><?= $s['completed_classes'] ?><span style="font-size:.8rem;color:#9ca3af;font-weight:400;">/ <?= $s['total_classes'] ?></span></div>
                <div style="font-size:.7rem;color:#6b7280;">คาบที่เรียน</div>
                <div style="background:#e5e7eb;border-radius:20px;height:5px;width:80px;margin:4px auto;overflow:hidden;">
                    <div style="background:<?= $pct>=100?'#059669':'#ea580c' ?>;width:<?= $pct ?>%;height:100%;border-radius:20px;"></div>
                </div>
                <div style="font-size:.7rem;color:#9ca3af;"><?= $pct ?>%</div>
            </div>
        </div>

        <!-- Action buttons -->
        <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
            <button onclick="openAdjust(<?= $s['id'] ?>,'<?= htmlspecialchars($s['student_name']) ?>','add')"
                    style="background:#ea580c;color:#fff;border:none;border-radius:6px;padding:5px 14px;cursor:pointer;font-size:.8rem;font-weight:600;">+ เพิ่มคาบ</button>
            <button onclick="openAdjust(<?= $s['id'] ?>,'<?= htmlspecialchars($s['student_name']) ?>','deduct')"
                    style="background:#ef4444;color:#fff;border:none;border-radius:6px;padding:5px 14px;cursor:pointer;font-size:.8rem;font-weight:600;">– หักคาบ</button>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<!-- Tab: History -->
<div id="tab-history" style="display:none;">
    <form method="get" style="display:flex;gap:8px;margin-bottom:1rem;">
        <input type="hidden" name="q" value="/modules/7j/manual_system.php">
        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
        <input type="date" name="date_filter" value="<?= htmlspecialchars($recentDate) ?>"
               style="border:1px solid #d1d5db;border-radius:8px;padding:7px 12px;font-size:.9rem;">
        <button type="submit" style="background:#ea580c;color:#fff;border:none;border-radius:8px;padding:7px 16px;cursor:pointer;font-weight:600;">กรอง</button>
        <?php if ($recentDate): ?><a href="?q=/modules/7j/manual_system.php" style="background:#e5e7eb;color:#374151;border-radius:8px;padding:7px 14px;text-decoration:none;font-size:.9rem;">ล้าง</a><?php endif; ?>
    </form>

    <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
            <thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb;">
                <th style="padding:10px 14px;text-align:left;color:#6b7280;font-weight:600;">คาบที่</th>
                <th style="padding:10px 14px;text-align:left;color:#6b7280;font-weight:600;">นักเรียน</th>
                <th style="padding:10px 14px;text-align:left;color:#6b7280;font-weight:600;">ครู</th>
                <th style="padding:10px 14px;text-align:left;color:#6b7280;font-weight:600;">วัน/เวลา</th>
                <th style="padding:10px 14px;text-align:left;color:#6b7280;font-weight:600;">วันที่บันทึก</th>
                <th style="padding:10px 14px;text-align:left;color:#6b7280;font-weight:600;">หมายเหตุ</th>
                <th style="padding:10px 14px;text-align:center;color:#6b7280;font-weight:600;">ลบ</th>
            </tr></thead>
            <tbody>
            <?php if (empty($recents)): ?>
                <tr><td colspan="7" style="text-align:center;padding:2rem;color:#9ca3af;">ไม่พบประวัติ</td></tr>
            <?php else: foreach ($recents as $i => $r): ?>
            <tr style="border-bottom:1px solid #f3f4f6;<?= $i%2?'background:#fafafa':'' ?>">
                <td style="padding:8px 14px;"><span style="background:#fff7ed;color:#ea580c;padding:2px 8px;border-radius:20px;font-size:.72rem;font-weight:700;">#<?= $r['session_number'] ?></span></td>
                <td style="padding:8px 14px;font-weight:500;color:#374151;"><?= htmlspecialchars($r['disp_student'] ?? $r['student_name']) ?><br><span style="font-size:.72rem;color:#9ca3af;"><?= htmlspecialchars($r['disp_code'] ?? $r['student_code']) ?></span></td>
                <td style="padding:8px 14px;color:#6b7280;"><?= htmlspecialchars($r['disp_teacher'] ?? $r['teacher_name']) ?></td>
                <td style="padding:8px 14px;color:#6b7280;"><?= htmlspecialchars($r['day_of_week']) ?> <?= htmlspecialchars($r['time_start']) ?></td>
                <td style="padding:8px 14px;color:#6b7280;"><?= htmlspecialchars($r['completed_date']) ?></td>
                <td style="padding:8px 14px;color:#6b7280;font-size:.78rem;"><?= htmlspecialchars($r['note']) ?></td>
                <td style="padding:8px 14px;text-align:center;">
                    <form method="post" onsubmit="return confirm('ยืนยันลบ?')">
                        <input type="hidden" name="action" value="delete_completion">
                        <input type="hidden" name="completion_id" value="<?= $r['id'] ?>">
                        <button type="submit" style="background:#ef4444;color:#fff;border:none;border-radius:6px;padding:3px 10px;cursor:pointer;font-size:.75rem;">ลบ</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <p style="color:#9ca3af;font-size:.78rem;margin-top:6px;">แสดง <?= count($recents) ?> รายการล่าสุด</p>
</div>
</div>

<!-- Adjust Modal -->
<div id="adjustModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:2rem;width:min(400px,95vw);box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 id="adjustTitle" style="margin:0 0 6px;font-size:1rem;font-weight:700;color:#1f2937;"></h3>
        <p id="adjustStudent" style="color:#6b7280;font-size:.85rem;margin:0 0 1.2rem;"></p>
        <form method="post">
            <input type="hidden" name="action" value="adjust">
            <input type="hidden" name="schedule_id" id="adjustId">
            <input type="hidden" name="adjust_type" id="adjustTypeField">
            <div style="display:grid;gap:10px;">
                <div>
                    <label style="font-size:.8rem;font-weight:600;color:#374151;">จำนวนคาบ</label>
                    <input type="number" name="amount" value="1" min="1" max="50"
                           style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:.9rem;margin-top:4px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="font-size:.8rem;font-weight:600;color:#374151;">วันที่</label>
                    <input type="date" name="completed_date" value="<?= date('Y-m-d') ?>"
                           style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:.9rem;margin-top:4px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="font-size:.8rem;font-weight:600;color:#374151;">หมายเหตุ</label>
                    <textarea name="reason" rows="2" style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:.9rem;margin-top:4px;box-sizing:border-box;resize:vertical;"></textarea>
                </div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:1.2rem;">
                <button type="button" onclick="document.getElementById('adjustModal').style.display='none'"
                        style="background:#e5e7eb;color:#374151;border:none;border-radius:8px;padding:8px 18px;cursor:pointer;font-weight:600;">ยกเลิก</button>
                <button type="submit" id="adjustSubmit" style="border:none;border-radius:8px;padding:8px 18px;cursor:pointer;font-weight:600;color:#fff;"></button>
            </div>
        </form>
    </div>
</div>

<script>
function showTab(name, btn) {
    document.querySelectorAll('[id^="tab-"]').forEach(function(el){ el.style.display='none'; });
    document.getElementById('tab-'+name).style.display='';
    document.querySelectorAll('.ms-tab').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');
}
function openAdjust(id, name, type) {
    document.getElementById('adjustId').value = id;
    document.getElementById('adjustTypeField').value = type;
    document.getElementById('adjustStudent').textContent = 'นักเรียน: ' + name;
    if (type === 'add') {
        document.getElementById('adjustTitle').textContent = '+ เพิ่มคาบเรียน';
        document.getElementById('adjustSubmit').style.background = '#ea580c';
        document.getElementById('adjustSubmit').textContent = 'เพิ่มคาบ';
    } else {
        document.getElementById('adjustTitle').textContent = '– หักคาบเรียน';
        document.getElementById('adjustSubmit').style.background = '#ef4444';
        document.getElementById('adjustSubmit').textContent = 'หักคาบ';
    }
    document.getElementById('adjustModal').style.display = 'flex';
}
</script>
