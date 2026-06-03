<?php
/*
 * 7J English Center — Classroom Link Manager
 * Admin ส่ง Google Meet link ให้นักเรียนและ/หรือครู
 */
require_once __DIR__.'/_theme.php';

$pdo    = $connection2;
$msg    = '';
$sentTo = [];

// ─── POST: ส่งลิ้งก์ ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_link') {
    $link    = trim($_POST['meet_link'] ?? '');
    // รับ IDs แบบ comma-separated string (หลีกเลี่ยง Gibbon sanitizer strip arrays)
    $stuRaw  = trim($_POST['student_ids_csv'] ?? '');
    $teaRaw  = trim($_POST['teacher_ids_csv'] ?? '');
    // IDs เป็น string เช่น "student_12345" — กรองเฉพาะ alphanumeric+underscore เพื่อความปลอดภัย
    $stuIds = array_values(array_filter(
        $stuRaw ? explode(',', $stuRaw) : [],
        fn($v) => preg_match('/^[a-zA-Z0-9_]+$/', trim($v))
    ));
    $teaIds = array_values(array_filter(
        $teaRaw ? explode(',', $teaRaw) : [],
        fn($v) => preg_match('/^[a-zA-Z0-9_]+$/', trim($v))
    ));

    if (!$link) {
        $msg = 'error|กรุณาใส่ลิ้งก์ Google Meet';
    } elseif (!$stuIds && !$teaIds) {
        $msg = 'error|กรุณาเลือกนักเรียนหรือครูอย่างน้อย 1 คน';
    } else {
        if ($stuIds) {
            $placeholders = implode(',', array_fill(0, count($stuIds), '?'));
            $pdo->prepare("UPDATE sevenj_students SET googleMeetLink=? WHERE id IN ($placeholders)")
                ->execute(array_merge([$link], $stuIds));
            $names = $pdo->prepare("SELECT displayName FROM sevenj_students WHERE id IN ($placeholders)");
            $names->execute($stuIds);
            foreach ($names->fetchAll(PDO::FETCH_COLUMN) as $n) $sentTo[] = '🎓 '.$n;
        }
        if ($teaIds) {
            $placeholders = implode(',', array_fill(0, count($teaIds), '?'));
            $pdo->prepare("UPDATE sevenj_teachers SET googleMeetLink=? WHERE id IN ($placeholders)")
                ->execute(array_merge([$link], $teaIds));
            $names = $pdo->prepare("SELECT displayName FROM sevenj_teachers WHERE id IN ($placeholders)");
            $names->execute($teaIds);
            foreach ($names->fetchAll(PDO::FETCH_COLUMN) as $n) $sentTo[] = '👨‍🏫 '.$n;
        }
        $msg = 'success|ส่งลิ้งก์สำเร็จ → '.implode(', ', $sentTo);
    }
}

// ─── ข้อมูลนักเรียนและครู ────────────────────────────────────────────────────
$students = $pdo->query(
    "SELECT id, displayName, studentCode, teacherId,
            (SELECT displayName FROM sevenj_teachers t WHERE t.id = sevenj_students.teacherId) AS teacherName,
            googleMeetLink AS currentLink
     FROM sevenj_students WHERE status='active' ORDER BY displayName"
)->fetchAll(PDO::FETCH_ASSOC);

$teachers = $pdo->query(
    "SELECT id, displayName, teacherCode, googleMeetLink AS currentLink
     FROM sevenj_teachers WHERE status='active' ORDER BY displayName"
)->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="g-page" style="max-width:900px;padding-bottom:3rem;">

<!-- Header -->
<div class="g-header" style="margin-bottom:1.25rem;">
    <h2 class="g-title">📹 ส่งลิ้งก์ห้องเรียน</h2>
</div>

<!-- Alert -->
<?php if ($msg): [$type,$text] = explode('|',$msg,2); ?>
<div id="cl-alert" style="
    display:flex;align-items:flex-start;gap:12px;
    padding:14px 18px;border-radius:10px;margin-bottom:1.25rem;
    <?= $type==='success'
        ? 'background:#dcfce7;border:1.5px solid #86efac;color:#166534;'
        : 'background:#fee2e2;border:1.5px solid #fca5a5;color:#991b1b;' ?>
    font-size:.92rem;font-weight:600;box-shadow:0 2px 8px rgba(0,0,0,.08);">
    <span style="font-size:1.3rem;flex-shrink:0;"><?= $type==='success' ? '✅' : '❌' ?></span>
    <div>
        <div style="font-weight:700;font-size:.95rem;margin-bottom:2px;">
            <?= $type==='success' ? 'ส่งลิ้งก์สำเร็จ!' : 'เกิดข้อผิดพลาด' ?>
        </div>
        <div style="font-weight:400;font-size:.85rem;opacity:.85;"><?= htmlspecialchars($text) ?></div>
    </div>
</div>
<script>document.getElementById('cl-alert')?.scrollIntoView({behavior:'smooth',block:'start'});</script>
<?php endif; ?>

<form method="POST" id="cl-form">
    <input type="hidden" name="action" value="send_link">
    <input type="hidden" name="q" value="/modules/7j/classroom_link.php">
    <input type="hidden" name="student_ids_csv" id="stu-ids-csv">
    <input type="hidden" name="teacher_ids_csv" id="tea-ids-csv">

    <!-- ลิ้งก์ -->
    <div class="g-card" style="margin-bottom:1rem;">
        <div class="g-card-header">🔗 Google Meet Link</div>
        <div class="g-card-body">
            <div class="g-form-group">
                <label>ลิ้งก์ Google Meet <span style="color:#dc2626;">*</span></label>
                <input type="url" name="meet_link" id="cl-link" class="g-input"
                       placeholder="https://meet.google.com/xxx-xxxx-xxx" required
                       value="<?= htmlspecialchars($_POST['meet_link'] ?? '') ?>">
            </div>
        </div>
    </div>

    <!-- Search + เลือกผู้รับ -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">

        <!-- นักเรียน -->
        <div class="g-card">
            <div class="g-card-header" style="justify-content:space-between;">
                <span>🎓 นักเรียน</span>
                <span id="stu-count" style="font-size:.75rem;font-weight:400;opacity:.8;">เลือก 0 / <?= count($students) ?></span>
            </div>
            <div class="g-card-body" style="padding-bottom:.5rem;">
                <div class="g-form-group">
                    <input type="text" id="stu-search" class="g-search-input" style="width:100%;margin-bottom:8px;"
                           placeholder="🔍 ค้นหาชื่อ/รหัสนักเรียน..." oninput="filterList('stu')">
                </div>
                <div style="display:flex;gap:8px;margin-bottom:6px;">
                    <button type="button" onclick="selectAll('stu',true)"
                            style="font-size:.75rem;padding:3px 10px;background:var(--g-primary);color:#fff;border:none;border-radius:6px;cursor:pointer;">
                        เลือกทั้งหมด
                    </button>
                    <button type="button" onclick="selectAll('stu',false)"
                            style="font-size:.75rem;padding:3px 10px;background:#e5e7eb;color:#374151;border:none;border-radius:6px;cursor:pointer;">
                        ยกเลิกทั้งหมด
                    </button>
                </div>
                <div id="stu-list" style="max-height:320px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;">
                    <?php foreach ($students as $s): ?>
                    <label class="cl-stu-item" data-name="<?= strtolower(htmlspecialchars($s['displayName'])) ?>" data-code="<?= strtolower(htmlspecialchars($s['studentCode'] ?? '')) ?>"
                           style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;cursor:pointer;border-bottom:1px solid #f3f4f6;font-size:.85rem;"
                           onmouseover="this.style.background='#fffbeb'" onmouseout="this.style.background=''">
                        <input type="checkbox" value="<?= htmlspecialchars($s['id']) ?>"
                               class="stu-cb" style="margin-top:2px;accent-color:var(--g-primary);"
                               onchange="updateCount('stu')">
                        <div style="min-width:0;">
                            <div style="font-weight:600;color:#1f2937;"><?= htmlspecialchars($s['displayName']) ?></div>
                            <div style="font-size:.75rem;color:#9ca3af;">
                                <?= htmlspecialchars($s['studentCode'] ?? '') ?>
                                <?php if ($s['teacherName']): ?>
                                 · 👨‍🏫 <?= htmlspecialchars($s['teacherName']) ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($s['currentLink']): ?>
                            <div style="font-size:.72rem;color:#059669;margin-top:2px;">✓ มีลิ้งก์แล้ว</div>
                            <?php endif; ?>
                        </div>
                    </label>
                    <?php endforeach; ?>
                    <?php if (empty($students)): ?>
                    <div style="padding:16px;text-align:center;color:#9ca3af;font-size:.85rem;">ไม่มีนักเรียน</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ครู -->
        <div class="g-card">
            <div class="g-card-header" style="justify-content:space-between;">
                <span>👨‍🏫 ครู</span>
                <span id="tea-count" style="font-size:.75rem;font-weight:400;opacity:.8;">เลือก 0 / <?= count($teachers) ?></span>
            </div>
            <div class="g-card-body" style="padding-bottom:.5rem;">
                <div class="g-form-group">
                    <input type="text" id="tea-search" class="g-search-input" style="width:100%;margin-bottom:8px;"
                           placeholder="🔍 ค้นหาชื่อ/รหัสครู..." oninput="filterList('tea')">
                </div>
                <div style="display:flex;gap:8px;margin-bottom:6px;">
                    <button type="button" onclick="selectAll('tea',true)"
                            style="font-size:.75rem;padding:3px 10px;background:var(--g-primary);color:#fff;border:none;border-radius:6px;cursor:pointer;">
                        เลือกทั้งหมด
                    </button>
                    <button type="button" onclick="selectAll('tea',false)"
                            style="font-size:.75rem;padding:3px 10px;background:#e5e7eb;color:#374151;border:none;border-radius:6px;cursor:pointer;">
                        ยกเลิกทั้งหมด
                    </button>
                </div>
                <div id="tea-list" style="max-height:320px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;">
                    <?php foreach ($teachers as $t): ?>
                    <label class="cl-tea-item" data-name="<?= strtolower(htmlspecialchars($t['displayName'])) ?>" data-code="<?= strtolower(htmlspecialchars($t['teacherCode'] ?? '')) ?>"
                           style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;cursor:pointer;border-bottom:1px solid #f3f4f6;font-size:.85rem;"
                           onmouseover="this.style.background='#fffbeb'" onmouseout="this.style.background=''">
                        <input type="checkbox" value="<?= htmlspecialchars($t['id']) ?>"
                               class="tea-cb" style="margin-top:2px;accent-color:var(--g-primary);"
                               onchange="updateCount('tea')">
                        <div style="min-width:0;">
                            <div style="font-weight:600;color:#1f2937;"><?= htmlspecialchars($t['displayName']) ?></div>
                            <div style="font-size:.75rem;color:#9ca3af;"><?= htmlspecialchars($t['teacherCode'] ?? '') ?></div>
                            <?php if ($t['currentLink']): ?>
                            <div style="font-size:.72rem;color:#059669;margin-top:2px;">✓ มีลิ้งก์แล้ว</div>
                            <?php endif; ?>
                        </div>
                    </label>
                    <?php endforeach; ?>
                    <?php if (empty($teachers)): ?>
                    <div style="padding:16px;text-align:center;color:#9ca3af;font-size:.85rem;">ไม่มีครู</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ลิ้งก์พอร์ทัล -->
    <div class="g-card" style="margin-bottom:1rem;background:#f0fdf4;border:1.5px solid #86efac;">
        <div class="g-card-body" style="padding:.85rem 1.25rem;display:flex;align-items:center;gap:12px;">
            <span style="font-size:1.4rem;">🔗</span>
            <div style="flex:1;">
                <div style="font-weight:700;font-size:.88rem;color:#166534;">พอร์ทัลสำหรับนักเรียนและครู</div>
                <div style="font-size:.78rem;color:#15803d;margin-top:2px;">
                    นักเรียน/ครูสามารถดูลิ้งก์ของตัวเองได้ที่:
                    <a href="/MyNewShool/7j_portal.php" target="_blank" style="color:#16a34a;font-weight:700;">
                        /MyNewShool/7j_portal.php
                    </a>
                </div>
            </div>
            <a href="/MyNewShool/7j_portal.php" target="_blank"
               style="font-size:.78rem;padding:5px 12px;background:#16a34a;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;white-space:nowrap;">
                เปิดพอร์ทัล ↗
            </a>
        </div>
    </div>

    <!-- ปุ่มส่ง -->
    <div style="display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" onclick="resetForm()"
                class="g-btn g-btn-outline">ล้างทั้งหมด</button>
        <button type="button" onclick="submitForm()" class="g-btn g-btn-primary" style="font-size:.95rem;padding:9px 28px;">
            📤 ส่งลิ้งก์ให้ที่เลือก
        </button>
    </div>
</form>

</div>

<script>
function submitForm() {
    var link = document.getElementById('cl-link').value.trim();
    if (!link) { alert('กรุณาใส่ลิ้งก์ Google Meet'); return; }
    var stuIds = [];
    document.querySelectorAll('.stu-cb:checked').forEach(function(cb){ stuIds.push(cb.value); });
    var teaIds = [];
    document.querySelectorAll('.tea-cb:checked').forEach(function(cb){ teaIds.push(cb.value); });
    if (!stuIds.length && !teaIds.length) { alert('กรุณาเลือกนักเรียนหรือครูอย่างน้อย 1 คน'); return; }
    document.getElementById('stu-ids-csv').value = stuIds.join(',');
    document.getElementById('tea-ids-csv').value = teaIds.join(',');
    document.getElementById('cl-form').submit();
}
function resetForm() {
    document.querySelectorAll('.stu-cb, .tea-cb').forEach(function(cb){ cb.checked = false; });
    document.getElementById('cl-link').value = '';
    document.getElementById('stu-ids-csv').value = '';
    document.getElementById('tea-ids-csv').value = '';
    updateCount('stu'); updateCount('tea');
}
function filterList(type) {
    var q = document.getElementById(type+'-search').value.toLowerCase();
    document.querySelectorAll('.cl-'+type+'-item').forEach(function(el) {
        var name = el.dataset.name || '';
        var code = el.dataset.code || '';
        el.style.display = (name.includes(q) || code.includes(q)) ? '' : 'none';
    });
}
function selectAll(type, checked) {
    document.querySelectorAll('.'+type+'-cb').forEach(function(cb) {
        var row = cb.closest('label');
        if (row && row.style.display !== 'none') cb.checked = checked;
    });
    updateCount(type);
}
function updateCount(type) {
    var total = document.querySelectorAll('.'+type+'-cb').length;
    var sel   = document.querySelectorAll('.'+type+'-cb:checked').length;
    document.getElementById(type+'-count').textContent = 'เลือก '+sel+' / '+total;
}
updateCount('stu'); updateCount('tea');
</script>
