<?php
/*
 * 7J English Center — Teacher Availability
 * Ported from TeacherAvailability.tsx
 * Admin จัดการช่วงเวลาว่างของครูแต่ละคน (weekly / specific_date)
 */

$DAYS = [
    'sunday'    => 'อาทิตย์',
    'monday'    => 'จันทร์',
    'tuesday'   => 'อังคาร',
    'wednesday' => 'พุธ',
    'thursday'  => 'พฤหัสบดี',
    'friday'    => 'ศุกร์',
    'saturday'  => 'เสาร์',
];

// ─── POST handlers ────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';
$msg = '';

if ($action === 'add_slot') {
    $tid   = trim($_POST['teacher_id'] ?? '');
    $type  = ($_POST['slot_type'] ?? '') === 'specific_date' ? 'specific_date' : 'weekly';
    $day   = $type === 'weekly'        ? (trim($_POST['day'] ?? 'monday') ?: null) : null;
    $sdate = $type === 'specific_date' ? (trim($_POST['specific_date'] ?? '') ?: null) : null;
    $stime = trim($_POST['start_time'] ?? '');
    $etime = trim($_POST['end_time']   ?? '');
    $note  = trim($_POST['note']       ?? '') ?: null;

    if ($tid && $stime && $etime) {
        $id   = 'slot_' . time() . rand(100, 999);
        $stmt = $connection2->prepare(
            'INSERT INTO sevenj_teacher_availability
             (id, teacher_id, type, day, specific_date, start_time, end_time, note)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([$id, $tid, $type, $day, $sdate, $stime, $etime, $note]);
        $msg = 'success|เพิ่มช่วงเวลาว่างสำเร็จ';
    } else {
        $msg = 'error|กรุณากรอกข้อมูลให้ครบ (ครู, เวลาเริ่ม, เวลาสิ้นสุด)';
    }

} elseif ($action === 'edit_slot') {
    $sid   = trim($_POST['slot_id']    ?? '');
    $type  = ($_POST['slot_type'] ?? '') === 'specific_date' ? 'specific_date' : 'weekly';
    $day   = $type === 'weekly'        ? (trim($_POST['day'] ?? 'monday') ?: null) : null;
    $sdate = $type === 'specific_date' ? (trim($_POST['specific_date'] ?? '') ?: null) : null;
    $stime = trim($_POST['start_time'] ?? '');
    $etime = trim($_POST['end_time']   ?? '');
    $note  = trim($_POST['note']       ?? '') ?: null;

    if ($sid && $stime && $etime) {
        $stmt = $connection2->prepare(
            'UPDATE sevenj_teacher_availability
             SET type=?, day=?, specific_date=?, start_time=?, end_time=?, note=?
             WHERE id=?'
        );
        $stmt->execute([$type, $day, $sdate, $stime, $etime, $note, $sid]);
        $msg = 'success|แก้ไขช่วงเวลาว่างสำเร็จ';
    } else {
        $msg = 'error|กรุณากรอกเวลาเริ่มและสิ้นสุด';
    }

} elseif ($action === 'delete_slot') {
    $sid = trim($_POST['slot_id'] ?? '');
    if ($sid) {
        $stmt = $connection2->prepare('DELETE FROM sevenj_teacher_availability WHERE id=?');
        $stmt->execute([$sid]);
        $msg = 'success|ลบช่วงเวลาว่างสำเร็จ';
    }
}

// ─── Fetch data ───────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$sWhere = $search ? "WHERE (t.displayName LIKE '%".addslashes($search)."%'
    OR t.teacherCode LIKE '%".addslashes($search)."%'
    OR t.nickname LIKE '%".addslashes($search)."%')" : '';

$teachers = $connection2->query("
    SELECT t.id, t.displayName, t.teacherCode, t.nickname, t.status
    FROM sevenj_teachers t
    $sWhere
    ORDER BY t.displayName
")->fetchAll(PDO::FETCH_ASSOC);

// availability เก็บเวลาแบบ 24h จริง (จาก <input type="time">) → แปลงเป็น 12h AM/PM
function fmtTimePM(string $t): string {
    if ($t === '') return '';
    [$h, $m] = array_pad(explode(':', $t), 2, '00');
    $h = (int)$h;
    $suffix = $h >= 12 ? 'PM' : 'AM';
    $h12    = $h % 12 ?: 12;
    return $h12 . ':' . $m . ' ' . $suffix;
}

// Fetch all slots grouped by teacher
$allSlots = $connection2->query("
    SELECT * FROM sevenj_teacher_availability
    ORDER BY type DESC, day, specific_date, start_time
")->fetchAll(PDO::FETCH_ASSOC);

$slotsByTeacher = [];
foreach ($allSlots as $slot) {
    $slotsByTeacher[$slot['teacher_id']][] = $slot;
}

[$alertType, $alertText] = $msg ? explode('|', $msg, 2) : ['', ''];

function avatarColor($name) {
    $colors = ['#ea580c','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2','#65a30d'];
    $idx = array_sum(array_map('ord', str_split($name ?? '?'))) % count($colors);
    return $colors[$idx];
}
function initials($name) {
    $words = preg_split('/\s+/', trim($name ?? ''));
    $ini = '';
    foreach ($words as $w) { if ($w) $ini .= mb_strtoupper(mb_substr($w, 0, 1)); }
    return mb_substr($ini, 0, 2) ?: '?';
}
?>

<?php require_once __DIR__.'/_theme.php'; ?>
<style>
.ta-card{background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:10px;overflow:hidden;}
.ta-card-header{padding:.75rem 1rem;display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none;}
.ta-card-header:hover{background:#faf9ff;}
.ta-avatar{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;color:#fff;flex-shrink:0;}
.ta-badge{display:inline-block;background:#fff7ed;color:#9a3412;border-radius:99px;padding:1px 9px;font-size:.72rem;font-weight:700;}
.ta-badge-day{background:#dbeafe;color:#1e40af;border-radius:6px;padding:2px 10px;font-size:.8rem;font-weight:600;}
.ta-badge-date{background:#fef3c7;color:#92400e;border-radius:6px;padding:2px 10px;font-size:.8rem;font-weight:600;}
.ta-slot{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 12px;background:#f8f7ff;border-radius:8px;margin-bottom:6px;}
.ta-btn{padding:6px 14px;border-radius:7px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;}
.ta-btn-primary{background:#ea580c;color:#fff;}
.ta-btn-primary:hover{background:#c2410c;}
.ta-btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db;}
.ta-btn-outline:hover{background:#f3f4f6;}
.ta-btn-sm{padding:4px 10px;font-size:.78rem;border-radius:6px;cursor:pointer;border:none;font-weight:600;}
.ta-btn-icon{padding:5px;border-radius:6px;background:none;border:none;cursor:pointer;color:#6b7280;}
.ta-btn-icon:hover{background:#f3f4f6;color:#111827;}
.ta-search{width:100%;padding:9px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:.9rem;outline:none;box-sizing:border-box;}
.ta-search:focus{border-color:#ea580c;box-shadow:0 0 0 2px #fff7ed;}
.ta-slots-body{padding:0 1rem 1rem;}
/* Modal */
.ta-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center;}
.ta-modal-bg.open{display:flex;}
.ta-modal{background:#fff;border-radius:14px;width:100%;max-width:440px;padding:1.5rem;box-shadow:0 20px 60px rgba(0,0,0,.25);max-height:90vh;overflow-y:auto;}
.ta-modal h3{margin:0 0 1rem;font-size:1.1rem;font-weight:700;}
.ta-form-group{margin-bottom:12px;}
.ta-form-group label{display:block;font-size:.8rem;font-weight:600;color:#374151;margin-bottom:4px;}
.ta-form-group input,.ta-form-group select,.ta-form-group textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.88rem;box-sizing:border-box;outline:none;}
.ta-form-group input:focus,.ta-form-group select:focus,.ta-form-group textarea:focus{border-color:#ea580c;box-shadow:0 0 0 2px #fff7ed;}
.ta-grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.ta-chevron{transition:transform .2s;display:inline-block;}
.ta-chevron.open{transform:rotate(180deg);}
</style>

<div style="max-width:100%;padding-bottom:2rem;">

<?php if ($alertText): ?>
<div style="background:<?= $alertType==='success'?'#d1fae5':'#fee2e2' ?>;border-radius:8px;padding:10px 16px;margin-bottom:1rem;color:<?= $alertType==='success'?'#065f46':'#991b1b' ?>;"><?= htmlspecialchars($alertText) ?></div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:.5rem;">
    <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0;">🕐 เวลาว่างครู</h2>
</div>
<p style="color:#6b7280;font-size:.88rem;margin:0 0 1rem;">จัดการช่วงเวลาว่างของครูแต่ละคน — รายสัปดาห์หรือวันที่เฉพาะ</p>

<!-- Search -->
<form method="get" style="display:flex;gap:8px;margin-bottom:1.25rem;">
    <input type="hidden" name="q" value="/modules/7j/teacher_availability.php">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="ta-search" placeholder="ค้นหาชื่อครู, รหัส, ชื่อเล่น...">
    <button type="submit" class="ta-btn ta-btn-outline">ค้นหา</button>
    <?php if ($search): ?><a href="?q=/modules/7j/teacher_availability.php" class="ta-btn ta-btn-outline">✕</a><?php endif; ?>
</form>

<!-- Teacher list -->
<?php if (empty($teachers)): ?>
<div style="text-align:center;padding:3rem 1rem;color:#9ca3af;"><?= $search ? 'ไม่พบผลลัพธ์' : 'ยังไม่มีครูในระบบ' ?></div>
<?php else: ?>
<?php foreach ($teachers as $t):
    $slots  = $slotsByTeacher[$t['id']] ?? [];
    $nSlots = count($slots);
    $bg     = avatarColor($t['displayName']);
    $ini    = initials($t['displayName']);
    $cardId = 'tc_'.md5($t['id']);
?>
<div class="ta-card">
    <div class="ta-card-header" onclick="toggleCard('<?= $cardId ?>')">
        <div class="ta-avatar" style="background:<?= $bg ?>;"><?= htmlspecialchars($ini) ?></div>
        <div style="flex:1;min-width:0;">
            <div style="font-weight:600;font-size:.95rem;"><?= htmlspecialchars($t['displayName']) ?>
                <span class="ta-badge" style="margin-left:6px;"><?= htmlspecialchars($t['teacherCode'] ?? '') ?></span>
            </div>
            <?php if ($t['nickname']): ?>
            <div style="font-size:.78rem;color:#6b7280;"><?= htmlspecialchars($t['nickname']) ?></div>
            <?php endif; ?>
        </div>
        <span style="background:#fff7ed;color:#9a3412;border-radius:99px;padding:2px 10px;font-size:.78rem;font-weight:700;white-space:nowrap;">
            <?= $nSlots ?> ช่วง
        </span>
        <button class="ta-btn ta-btn-primary ta-btn-sm" style="white-space:nowrap;"
            onclick="event.stopPropagation();openAddSlot('<?= htmlspecialchars($t['id']) ?>','<?= htmlspecialchars(addslashes($t['displayName'])) ?>')">
            + เพิ่ม
        </button>
        <span class="ta-chevron" id="chev_<?= $cardId ?>">▼</span>
    </div>

    <div id="<?= $cardId ?>" style="display:none;">
        <div class="ta-slots-body">
            <?php if ($nSlots === 0): ?>
            <div style="text-align:center;padding:1rem;color:#9ca3af;font-size:.85rem;">ยังไม่มีช่วงเวลาว่าง</div>
            <?php else: ?>
            <?php foreach ($slots as $slot):
                $isWeekly = $slot['type'] === 'weekly';
                $dayLabel = $DAYS[$slot['day']] ?? $slot['day'];
            ?>
            <div class="ta-slot">
                <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;">
                    <?php if ($isWeekly): ?>
                    <span class="ta-badge-day">📅 <?= htmlspecialchars($dayLabel) ?></span>
                    <?php else: ?>
                    <span class="ta-badge-date">📆 <?= fmtDate($slot['specific_date'] ?? '') ?></span>
                    <?php endif; ?>
                    <div>
                        <span style="font-weight:600;font-size:.88rem;"><?= fmtTimePM($slot['start_time']) ?> – <?= fmtTimePM($slot['end_time']) ?></span>
                        <?php if ($slot['note']): ?>
                        <div style="font-size:.75rem;color:#6b7280;margin-top:1px;"><?= htmlspecialchars($slot['note']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display:flex;gap:2px;flex-shrink:0;">
                    <button class="ta-btn-icon" title="แก้ไข"
                        onclick="openEditSlot(<?= htmlspecialchars(json_encode([
                            'id'            => $slot['id'],
                            'teacher_id'    => $slot['teacher_id'],
                            'type'          => $slot['type'],
                            'day'           => $slot['day'] ?? 'monday',
                            'specific_date' => $slot['specific_date'] ?? '',
                            'start_time'    => $slot['start_time'],
                            'end_time'      => $slot['end_time'],
                            'note'          => $slot['note'] ?? '',
                        ])) ?>)">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button class="ta-btn-icon" title="ลบ" style="color:#dc2626;"
                        onclick="confirmDeleteSlot('<?= htmlspecialchars($slot['id']) ?>')">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

</div><!-- end main -->

<!-- ─── Add Slot Modal ────────────────────────────────────────────────────── -->
<div id="modal-add-slot" class="ta-modal-bg">
<div class="ta-modal">
    <h3>➕ เพิ่มช่วงเวลาว่าง — <span id="add-teacher-name" style="color:#ea580c;"></span></h3>
    <form method="post">
        <input type="hidden" name="action" value="add_slot">
        <input type="hidden" name="q" value="/modules/7j/teacher_availability.php">
        <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
        <input type="hidden" name="teacher_id" id="add-teacher-id">

        <div class="ta-form-group">
            <label>ประเภทช่วงเวลา</label>
            <select name="slot_type" id="add-slot-type" onchange="toggleSlotType('add')">
                <option value="weekly">รายสัปดาห์</option>
                <option value="specific_date">วันที่เฉพาะ</option>
            </select>
        </div>

        <div class="ta-form-group" id="add-day-group">
            <label>วันในสัปดาห์</label>
            <select name="day" id="add-day">
                <?php foreach ($DAYS as $val => $label): ?>
                <option value="<?= $val ?>"><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ta-form-group" id="add-date-group" style="display:none;">
            <label>วันที่</label>
            <input type="date" name="specific_date" id="add-specific-date">
        </div>

        <div class="ta-grid2">
            <div class="ta-form-group">
                <label>เวลาเริ่ม</label>
                <input type="time" name="start_time" id="add-start" value="09:00" required>
            </div>
            <div class="ta-form-group">
                <label>เวลาสิ้นสุด</label>
                <input type="time" name="end_time" id="add-end" value="10:00" required>
            </div>
        </div>
        <div class="ta-form-group">
            <label>หมายเหตุ (ถ้ามี)</label>
            <textarea name="note" rows="2" placeholder="เช่น ชื่อนักเรียน / หมายเหตุ..."></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
            <button type="button" class="ta-btn ta-btn-outline" onclick="closeModal('add-slot')">ยกเลิก</button>
            <button type="submit" class="ta-btn ta-btn-primary">บันทึก</button>
        </div>
    </form>
</div>
</div>

<!-- ─── Edit Slot Modal ───────────────────────────────────────────────────── -->
<div id="modal-edit-slot" class="ta-modal-bg">
<div class="ta-modal">
    <h3>✏️ แก้ไขช่วงเวลาว่าง</h3>
    <form method="post">
        <input type="hidden" name="action" value="edit_slot">
        <input type="hidden" name="q" value="/modules/7j/teacher_availability.php">
        <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
        <input type="hidden" name="slot_id" id="edit-slot-id">

        <div class="ta-form-group">
            <label>ประเภทช่วงเวลา</label>
            <select name="slot_type" id="edit-slot-type" onchange="toggleSlotType('edit')">
                <option value="weekly">รายสัปดาห์</option>
                <option value="specific_date">วันที่เฉพาะ</option>
            </select>
        </div>

        <div class="ta-form-group" id="edit-day-group">
            <label>วันในสัปดาห์</label>
            <select name="day" id="edit-day">
                <?php foreach ($DAYS as $val => $label): ?>
                <option value="<?= $val ?>"><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ta-form-group" id="edit-date-group" style="display:none;">
            <label>วันที่</label>
            <input type="date" name="specific_date" id="edit-specific-date">
        </div>

        <div class="ta-grid2">
            <div class="ta-form-group">
                <label>เวลาเริ่ม</label>
                <input type="time" name="start_time" id="edit-start" required>
            </div>
            <div class="ta-form-group">
                <label>เวลาสิ้นสุด</label>
                <input type="time" name="end_time" id="edit-end" required>
            </div>
        </div>
        <div class="ta-form-group">
            <label>หมายเหตุ (ถ้ามี)</label>
            <textarea name="note" id="edit-note" rows="2"></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
            <button type="button" class="ta-btn ta-btn-outline" onclick="closeModal('edit-slot')">ยกเลิก</button>
            <button type="submit" class="ta-btn ta-btn-primary">บันทึก</button>
        </div>
    </form>
</div>
</div>

<!-- ─── Delete Confirm Modal ─────────────────────────────────────────────── -->
<div id="modal-del-slot" class="ta-modal-bg">
<div class="ta-modal" style="max-width:360px;">
    <h3 style="color:#dc2626;">ยืนยันการลบ</h3>
    <p style="color:#374151;margin:.5rem 0 1.25rem;">ต้องการลบช่วงเวลาว่างนี้ใช่หรือไม่?</p>
    <form method="post">
        <input type="hidden" name="action" value="delete_slot">
        <input type="hidden" name="q" value="/modules/7j/teacher_availability.php">
        <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
        <input type="hidden" name="slot_id" id="del-slot-id">
        <div style="display:flex;justify-content:flex-end;gap:8px;">
            <button type="button" class="ta-btn ta-btn-outline" onclick="closeModal('del-slot')">ยกเลิก</button>
            <button type="submit" class="ta-btn" style="background:#dc2626;color:#fff;">ลบ</button>
        </div>
    </form>
</div>
</div>

<script>
// Card toggle
function toggleCard(id) {
    var el = document.getElementById(id);
    var ch = document.getElementById('chev_' + id);
    if (el.style.display === 'none') {
        el.style.display = 'block';
        ch.classList.add('open');
    } else {
        el.style.display = 'none';
        ch.classList.remove('open');
    }
}

// Modal
function openModal(id) { document.getElementById('modal-' + id).classList.add('open'); }
function closeModal(id) { document.getElementById('modal-' + id).classList.remove('open'); }
document.querySelectorAll('.ta-modal-bg').forEach(function(bg) {
    bg.addEventListener('click', function(e) { if (e.target === bg) bg.classList.remove('open'); });
});

// Slot type toggle
function toggleSlotType(prefix) {
    var type = document.getElementById(prefix + '-slot-type').value;
    document.getElementById(prefix + '-day-group').style.display  = type === 'weekly' ? '' : 'none';
    document.getElementById(prefix + '-date-group').style.display = type === 'specific_date' ? '' : 'none';
}

// Add slot
function openAddSlot(teacherId, teacherName) {
    document.getElementById('add-teacher-id').value = teacherId;
    document.getElementById('add-teacher-name').textContent = teacherName;
    document.getElementById('add-slot-type').value = 'weekly';
    toggleSlotType('add');
    openModal('add-slot');
}

// Edit slot
function openEditSlot(data) {
    document.getElementById('edit-slot-id').value     = data.id;
    document.getElementById('edit-slot-type').value   = data.type;
    document.getElementById('edit-day').value         = data.day || 'monday';
    document.getElementById('edit-specific-date').value = data.specific_date || '';
    document.getElementById('edit-start').value       = data.start_time;
    document.getElementById('edit-end').value         = data.end_time;
    document.getElementById('edit-note').value        = data.note || '';
    toggleSlotType('edit');
    openModal('edit-slot');
}

// Delete slot
function confirmDeleteSlot(slotId) {
    document.getElementById('del-slot-id').value = slotId;
    openModal('del-slot');
}
</script>
