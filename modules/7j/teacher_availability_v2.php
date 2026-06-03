<?php
/*
 * 7J English Center — Teacher Availability V2
 * สร้างใหม่จาก TeacherAvailability.tsx (React/Firestore version)
 * UI แบบ shadcn/Tailwind — เปรียบเทียบกับ V1
 */

$DAYS_OF_WEEK = [
    ['value'=>'sunday',    'label'=>'อาทิตย์'],
    ['value'=>'monday',    'label'=>'จันทร์'],
    ['value'=>'tuesday',   'label'=>'อังคาร'],
    ['value'=>'wednesday', 'label'=>'พุธ'],
    ['value'=>'thursday',  'label'=>'พฤหัสบดี'],
    ['value'=>'friday',    'label'=>'ศุกร์'],
    ['value'=>'saturday',  'label'=>'เสาร์'],
];
$DAY_ORDER = array_column($DAYS_OF_WEEK, 'value');
$DAY_LABEL = array_column($DAYS_OF_WEEK, 'label', 'value');

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
        $id = 'slot_' . time() . rand(100,999);
        $connection2->prepare('INSERT INTO sevenj_teacher_availability
            (id,teacher_id,type,day,specific_date,start_time,end_time,note)
            VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$tid,$type,$day,$sdate,$stime,$etime,$note]);
        $msg = 'success|เพิ่มช่วงเวลาว่างสำเร็จ';
    } else {
        $msg = 'error|กรุณากรอกข้อมูลให้ครบ';
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
        $connection2->prepare('UPDATE sevenj_teacher_availability
            SET type=?,day=?,specific_date=?,start_time=?,end_time=?,note=? WHERE id=?')
            ->execute([$type,$day,$sdate,$stime,$etime,$note,$sid]);
        $msg = 'success|แก้ไขช่วงเวลาว่างสำเร็จ';
    } else {
        $msg = 'error|กรุณากรอกเวลาเริ่มและสิ้นสุด';
    }
} elseif ($action === 'delete_slot') {
    $sid = trim($_POST['slot_id'] ?? '');
    if ($sid) {
        $connection2->prepare('DELETE FROM sevenj_teacher_availability WHERE id=?')->execute([$sid]);
        $msg = 'success|ลบช่วงเวลาว่างสำเร็จ';
    }
}

// ─── Fetch ─────────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$sWhere = $search ? "WHERE (t.displayName LIKE ? OR t.teacherCode LIKE ? OR t.nickname LIKE ?)" : '';

if ($search) {
    $stmt = $connection2->prepare("SELECT t.id,t.displayName,t.teacherCode,t.nickname FROM sevenj_teachers t $sWhere ORDER BY t.displayName");
    $l = '%'.$search.'%';
    $stmt->execute([$l,$l,$l]);
} else {
    $stmt = $connection2->query("SELECT t.id,t.displayName,t.teacherCode,t.nickname FROM sevenj_teachers t ORDER BY t.displayName");
}
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$allSlots = $connection2->query("SELECT * FROM sevenj_teacher_availability ORDER BY type DESC, day, specific_date, start_time")->fetchAll(PDO::FETCH_ASSOC);
$slotsByTeacher = [];
foreach ($allSlots as $sl) { $slotsByTeacher[$sl['teacher_id']][] = $sl; }

// Sort weekly by DAY_ORDER
foreach ($slotsByTeacher as &$tSlots) {
    usort($tSlots, function($a, $b) use ($DAY_ORDER) {
        if ($a['type'] === 'weekly' && $b['type'] !== 'weekly') return -1;
        if ($a['type'] !== 'weekly' && $b['type'] === 'weekly') return 1;
        if ($a['type'] === 'weekly' && $b['type'] === 'weekly') {
            return array_search($a['day'],$DAY_ORDER) - array_search($b['day'],$DAY_ORDER);
        }
        return strcmp($a['specific_date']??'',$b['specific_date']??'');
    });
}
unset($tSlots);

[$alertType,$alertText] = $msg ? explode('|',$msg,2) : ['',''];

function fmtTime12(string $t): string {
    if (!$t) return '';
    [$h,$m] = array_pad(explode(':',$t),2,'00');
    $h = (int)$h; $sfx = $h>=12?'PM':'AM'; $h12 = $h%12?:12;
    return $h12.':'.$m.' '.$sfx;
}
function fmtDateV2(string $d): string {
    if (!$d) return '';
    $ts = strtotime($d);
    $days = ['Sunday'=>'อาทิตย์','Monday'=>'จันทร์','Tuesday'=>'อังคาร','Wednesday'=>'พุธ',
             'Thursday'=>'พฤหัสบดี','Friday'=>'ศุกร์','Saturday'=>'เสาร์'];
    return ($days[date('l',$ts)]??'') . ' ' . date('j', $ts) . '/' . date('n', $ts) . '/' . (date('Y',$ts)+543);
}
function avColor2($n) {
    $c=['#ea580c','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2','#65a30d'];
    return $c[array_sum(array_map('ord',str_split($n??'?')))%count($c)];
}
function ini2($n) {
    $w=preg_split('/\s+/',trim($n??'')); $i='';
    foreach($w as $x){if($x)$i.=mb_strtoupper(mb_substr($x,0,1));}
    return mb_substr($i,0,2)?:'?';
}
?>
<?php require_once __DIR__.'/_theme.php'; ?>
<style>
/* ── V2: shadcn/Tailwind-inspired ── */
.v2-wrap{max-width:100%;padding-bottom:3rem;font-family:inherit;}
.v2-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;}
.v2-title{font-size:1.3rem;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;margin:0;}
.v2-desc{font-size:.83rem;color:#64748b;margin:0 0 1.25rem;}
.v2-search-wrap{position:relative;margin-bottom:1.25rem;}
.v2-search{width:100%;padding:9px 14px 9px 38px;border:1px solid #e2e8f0;border-radius:8px;font-size:.88rem;box-sizing:border-box;outline:none;background:#fff;}
.v2-search:focus{border-color:#E8640A;box-shadow:0 0 0 3px rgba(232,100,10,.1);}
.v2-search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.9rem;}
/* Card */
.v2-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:10px;overflow:hidden;transition:box-shadow .15s;}
.v2-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.08);}
.v2-card-header{padding:.85rem 1rem;display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none;}
.v2-avatar{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.82rem;color:#fff;flex-shrink:0;}
.v2-name{font-weight:600;font-size:.92rem;color:#0f172a;}
.v2-sub{font-size:.75rem;color:#64748b;}
.v2-badge-count{background:#f1f5f9;color:#475569;border-radius:99px;padding:2px 10px;font-size:.73rem;font-weight:600;border:1px solid #e2e8f0;}
.v2-btn-add{display:inline-flex;align-items:center;gap:4px;background:#0f172a;color:#fff;border:none;border-radius:7px;padding:5px 14px;font-size:.78rem;font-weight:600;cursor:pointer;margin-left:auto;}
.v2-btn-add:hover{background:#1e293b;}
.v2-chevron{color:#94a3b8;font-size:.8rem;transition:transform .2s;flex-shrink:0;}
.v2-chevron.open{transform:rotate(180deg);}
/* Slot items */
.v2-slots{padding:0 1rem 1rem;}
.v2-slot-item{display:flex;align-items:center;gap:10px;padding:10px 12px;background:#f8fafc;border-radius:8px;margin-bottom:6px;border:1px solid #f1f5f9;}
.v2-badge-weekly{background:#dbeafe;color:#1e40af;border-radius:6px;padding:2px 10px;font-size:.78rem;font-weight:600;white-space:nowrap;}
.v2-badge-date{background:#fef9c3;color:#854d0e;border-radius:6px;padding:2px 10px;font-size:.78rem;font-weight:600;white-space:nowrap;}
.v2-slot-time{font-weight:600;font-size:.88rem;color:#0f172a;}
.v2-slot-note{font-size:.75rem;color:#64748b;margin-top:2px;}
.v2-btn-icon{width:30px;height:30px;border-radius:6px;background:transparent;border:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:#94a3b8;transition:background .15s;}
.v2-btn-icon:hover{background:#f1f5f9;color:#475569;}
.v2-btn-icon.danger:hover{background:#fee2e2;color:#dc2626;}
/* Dialog (modal) */
.v2-dialog-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center;}
.v2-dialog-bg.open{display:flex;}
.v2-dialog{background:#fff;border-radius:14px;width:100%;max-width:420px;box-shadow:0 24px 64px rgba(0,0,0,.2);overflow:hidden;}
.v2-dialog-header{padding:1.25rem 1.5rem .75rem;border-bottom:1px solid #f1f5f9;}
.v2-dialog-title{font-size:1.05rem;font-weight:700;color:#0f172a;margin:0;}
.v2-dialog-desc{font-size:.82rem;color:#64748b;margin:4px 0 0;}
.v2-dialog-body{padding:1.25rem 1.5rem;}
.v2-dialog-footer{padding:.75rem 1.5rem 1.25rem;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid #f1f5f9;}
.v2-label{display:block;font-size:.8rem;font-weight:600;color:#374151;margin-bottom:5px;}
.v2-input,.v2-select,.v2-textarea{width:100%;padding:8px 12px;border:1px solid #e2e8f0;border-radius:7px;font-size:.88rem;box-sizing:border-box;outline:none;}
.v2-input:focus,.v2-select:focus,.v2-textarea:focus{border-color:#E8640A;box-shadow:0 0 0 2px rgba(232,100,10,.1);}
.v2-grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.v2-form-group{margin-bottom:14px;}
.v2-btn-outline{padding:8px 16px;border:1px solid #e2e8f0;border-radius:7px;background:#fff;font-size:.85rem;font-weight:600;cursor:pointer;color:#374151;}
.v2-btn-outline:hover{background:#f8fafc;}
.v2-btn-primary{padding:8px 16px;border:none;border-radius:7px;background:#0f172a;color:#fff;font-size:.85rem;font-weight:600;cursor:pointer;}
.v2-btn-primary:hover{background:#1e293b;}
.v2-btn-danger{padding:8px 16px;border:none;border-radius:7px;background:#dc2626;color:#fff;font-size:.85rem;font-weight:600;cursor:pointer;}
/* Alert */
.v2-alert{border-radius:8px;padding:10px 16px;margin-bottom:1rem;font-size:.85rem;font-weight:600;}
.v2-alert.success{background:#f0fdf4;color:#166534;border:1px solid #86efac;}
.v2-alert.error{background:#fef2f2;color:#991b1b;border:1px solid #fca5a5;}
/* Empty */
.v2-empty{text-align:center;padding:2rem;color:#94a3b8;font-size:.85rem;}
</style>

<div class="v2-wrap">

<!-- Header -->
<div class="v2-header" style="margin-bottom:.25rem;">
    <h2 class="v2-title">🕐 เวลาว่างครู <span style="font-size:.7rem;background:#fef3c7;color:#92400e;border-radius:99px;padding:2px 10px;font-weight:600;vertical-align:middle;">V2 — React Style</span></h2>
</div>
<p class="v2-desc">จัดการช่วงเวลาว่างของครูแต่ละคน — รายสัปดาห์หรือวันที่เฉพาะ</p>

<?php if ($alertText): ?>
<div class="v2-alert <?= $alertType ?>"><?= htmlspecialchars($alertText) ?></div>
<?php endif; ?>

<!-- Search -->
<div class="v2-search-wrap">
    <span class="v2-search-icon">🔍</span>
    <input type="text" id="v2-search-input" class="v2-search"
        placeholder="ค้นหาชื่อครู, รหัส, ชื่อเล่น..."
        value="<?= htmlspecialchars($search) ?>"
        oninput="v2FilterTeachers(this.value)">
</div>

<!-- Teacher list -->
<div id="v2-teacher-list">
<?php if (empty($teachers)): ?>
<div class="v2-empty"><?= $search ? 'ไม่พบผลลัพธ์' : 'ยังไม่มีครูในระบบ' ?></div>
<?php else: ?>
<?php foreach ($teachers as $t):
    $slots  = $slotsByTeacher[$t['id']] ?? [];
    $nSlots = count($slots);
    $bg     = avColor2($t['displayName']);
    $ini    = ini2($t['displayName']);
    $cid    = 'v2c_'.md5($t['id']);
?>
<div class="v2-card" data-search="<?= strtolower(htmlspecialchars($t['displayName'].' '.$t['teacherCode'].' '.($t['nickname']??''))) ?>">
    <div class="v2-card-header" onclick="v2Toggle('<?= $cid ?>')">
        <div class="v2-avatar" style="background:<?= $bg ?>;"><?= htmlspecialchars($ini) ?></div>
        <div style="flex:1;min-width:0;">
            <div class="v2-name"><?= htmlspecialchars($t['displayName']) ?></div>
            <div class="v2-sub"><?= htmlspecialchars($t['teacherCode']) ?><?= $t['nickname'] ? ' · '.$t['nickname'] : '' ?></div>
        </div>
        <span class="v2-badge-count"><?= $nSlots ?> ช่วง</span>
        <button class="v2-btn-add" onclick="event.stopPropagation();v2OpenAdd('<?= htmlspecialchars($t['id']) ?>','<?= htmlspecialchars(addslashes($t['displayName'])) ?>')">
            + เพิ่ม
        </button>
        <span class="v2-chevron" id="<?= $cid ?>_ch">▼</span>
    </div>

    <div id="<?= $cid ?>" style="display:none;">
        <div class="v2-slots">
            <?php if ($nSlots === 0): ?>
            <div class="v2-empty" style="padding:.75rem;">ยังไม่มีช่วงเวลาว่าง — กด "+ เพิ่ม" เพื่อเริ่มต้น</div>
            <?php else: ?>
            <?php foreach ($slots as $sl):
                $isWeekly = $sl['type'] === 'weekly';
            ?>
            <div class="v2-slot-item">
                <div style="flex:1;display:flex;align-items:flex-start;gap:10px;min-width:0;">
                    <?php if ($isWeekly): ?>
                    <span class="v2-badge-weekly">🗓 <?= $DAY_LABEL[$sl['day']] ?? $sl['day'] ?></span>
                    <?php else: ?>
                    <span class="v2-badge-date">📅 <?= fmtDateV2($sl['specific_date'] ?? '') ?></span>
                    <?php endif; ?>
                    <div>
                        <div class="v2-slot-time"><?= fmtTime12($sl['start_time']) ?> – <?= fmtTime12($sl['end_time']) ?></div>
                        <?php if ($sl['note']): ?>
                        <div class="v2-slot-note"><?= htmlspecialchars($sl['note']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display:flex;gap:2px;flex-shrink:0;">
                    <button class="v2-btn-icon" title="แก้ไข"
                        onclick="v2OpenEdit(<?= htmlspecialchars(json_encode([
                            'id'=>$sl['id'],'teacher_id'=>$sl['teacher_id'],
                            'type'=>$sl['type'],'day'=>$sl['day']??'monday',
                            'specific_date'=>$sl['specific_date']??'',
                            'start_time'=>$sl['start_time'],'end_time'=>$sl['end_time'],
                            'note'=>$sl['note']??''
                        ])) ?>)">✏️</button>
                    <button class="v2-btn-icon danger" title="ลบ"
                        onclick="v2OpenDelete('<?= htmlspecialchars($sl['id']) ?>')">🗑</button>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>

</div><!-- v2-wrap -->

<!-- ─── Add Dialog ─────────────────────────────────────────────────────── -->
<div id="v2-dialog-add" class="v2-dialog-bg">
<div class="v2-dialog">
    <div class="v2-dialog-header">
        <div class="v2-dialog-title">เพิ่มช่วงเวลาว่าง</div>
        <div class="v2-dialog-desc" id="v2-add-teacher-name" style="color:#E8640A;font-weight:600;"></div>
    </div>
    <form method="POST">
        <div class="v2-dialog-body">
            <input type="hidden" name="action" value="add_slot">
            <input type="hidden" name="q" value="/modules/7j/teacher_availability_v2.php">
            <input type="hidden" name="teacher_id" id="v2-add-tid">

            <div class="v2-form-group">
                <label class="v2-label">ประเภทช่วงเวลา</label>
                <select name="slot_type" id="v2-add-type" class="v2-select" onchange="v2ToggleType('add')">
                    <option value="weekly">รายสัปดาห์ (Weekly)</option>
                    <option value="specific_date">วันที่เฉพาะ (Specific Date)</option>
                </select>
            </div>

            <div class="v2-form-group" id="v2-add-day-g">
                <label class="v2-label">วันในสัปดาห์</label>
                <select name="day" class="v2-select">
                    <?php foreach ($DAYS_OF_WEEK as $d): ?>
                    <option value="<?= $d['value'] ?>"><?= $d['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="v2-form-group" id="v2-add-date-g" style="display:none;">
                <label class="v2-label">วันที่</label>
                <input type="date" name="specific_date" id="v2-add-sdate" class="v2-input">
            </div>

            <div class="v2-grid2">
                <div class="v2-form-group">
                    <label class="v2-label">เวลาเริ่ม</label>
                    <input type="time" name="start_time" value="09:00" class="v2-input" required>
                </div>
                <div class="v2-form-group">
                    <label class="v2-label">เวลาสิ้นสุด</label>
                    <input type="time" name="end_time" value="10:00" class="v2-input" required>
                </div>
            </div>
            <div class="v2-form-group" style="margin-bottom:0;">
                <label class="v2-label">หมายเหตุ (ถ้ามี)</label>
                <textarea name="note" rows="2" class="v2-textarea" placeholder="เช่น ชื่อนักเรียน / หมายเหตุ..."></textarea>
            </div>
        </div>
        <div class="v2-dialog-footer">
            <button type="button" class="v2-btn-outline" onclick="v2CloseDialog('add')">ยกเลิก</button>
            <button type="submit" class="v2-btn-primary">บันทึก</button>
        </div>
    </form>
</div>
</div>

<!-- ─── Edit Dialog ────────────────────────────────────────────────────── -->
<div id="v2-dialog-edit" class="v2-dialog-bg">
<div class="v2-dialog">
    <div class="v2-dialog-header">
        <div class="v2-dialog-title">แก้ไขช่วงเวลาว่าง</div>
        <div class="v2-dialog-desc">แก้ไขช่วงเวลาว่างของครู</div>
    </div>
    <form method="POST">
        <div class="v2-dialog-body">
            <input type="hidden" name="action" value="edit_slot">
            <input type="hidden" name="q" value="/modules/7j/teacher_availability_v2.php">
            <input type="hidden" name="slot_id" id="v2-edit-sid">

            <div class="v2-form-group">
                <label class="v2-label">ประเภทช่วงเวลา</label>
                <select name="slot_type" id="v2-edit-type" class="v2-select" onchange="v2ToggleType('edit')">
                    <option value="weekly">รายสัปดาห์ (Weekly)</option>
                    <option value="specific_date">วันที่เฉพาะ (Specific Date)</option>
                </select>
            </div>

            <div class="v2-form-group" id="v2-edit-day-g">
                <label class="v2-label">วันในสัปดาห์</label>
                <select name="day" id="v2-edit-day" class="v2-select">
                    <?php foreach ($DAYS_OF_WEEK as $d): ?>
                    <option value="<?= $d['value'] ?>"><?= $d['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="v2-form-group" id="v2-edit-date-g" style="display:none;">
                <label class="v2-label">วันที่</label>
                <input type="date" name="specific_date" id="v2-edit-sdate" class="v2-input">
            </div>

            <div class="v2-grid2">
                <div class="v2-form-group">
                    <label class="v2-label">เวลาเริ่ม</label>
                    <input type="time" name="start_time" id="v2-edit-start" class="v2-input" required>
                </div>
                <div class="v2-form-group">
                    <label class="v2-label">เวลาสิ้นสุด</label>
                    <input type="time" name="end_time" id="v2-edit-end" class="v2-input" required>
                </div>
            </div>
            <div class="v2-form-group" style="margin-bottom:0;">
                <label class="v2-label">หมายเหตุ</label>
                <textarea name="note" id="v2-edit-note" rows="2" class="v2-textarea"></textarea>
            </div>
        </div>
        <div class="v2-dialog-footer">
            <button type="button" class="v2-btn-outline" onclick="v2CloseDialog('edit')">ยกเลิก</button>
            <button type="submit" class="v2-btn-primary">บันทึก</button>
        </div>
    </form>
</div>
</div>

<!-- ─── Delete Alert Dialog ───────────────────────────────────────────── -->
<div id="v2-dialog-delete" class="v2-dialog-bg">
<div class="v2-dialog" style="max-width:360px;">
    <div class="v2-dialog-header">
        <div class="v2-dialog-title" style="color:#dc2626;">ลบช่วงเวลาว่าง</div>
        <div class="v2-dialog-desc">ต้องการลบช่วงเวลาว่างนี้ใช่หรือไม่? การกระทำนี้ไม่สามารถยกเลิกได้</div>
    </div>
    <form method="POST">
        <div class="v2-dialog-footer" style="border-top:none;padding-top:.5rem;">
            <input type="hidden" name="action" value="delete_slot">
            <input type="hidden" name="q" value="/modules/7j/teacher_availability_v2.php">
            <input type="hidden" name="slot_id" id="v2-del-sid">
            <button type="button" class="v2-btn-outline" onclick="v2CloseDialog('delete')">ยกเลิก</button>
            <button type="submit" class="v2-btn-danger">ลบ</button>
        </div>
    </form>
</div>
</div>

<script>
function v2Toggle(id) {
    var el = document.getElementById(id);
    var ch = document.getElementById(id + '_ch');
    var open = el.style.display !== 'none';
    el.style.display = open ? 'none' : 'block';
    ch.classList.toggle('open', !open);
}

function v2OpenDialog(type) { document.getElementById('v2-dialog-' + type).classList.add('open'); }
function v2CloseDialog(type) { document.getElementById('v2-dialog-' + type).classList.remove('open'); }

document.querySelectorAll('.v2-dialog-bg').forEach(function(bg) {
    bg.addEventListener('click', function(e) { if (e.target === bg) bg.classList.remove('open'); });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.querySelectorAll('.v2-dialog-bg.open').forEach(function(d) { d.classList.remove('open'); });
});

function v2ToggleType(prefix) {
    var type = document.getElementById('v2-' + prefix + '-type').value;
    document.getElementById('v2-' + prefix + '-day-g').style.display  = type === 'weekly' ? '' : 'none';
    document.getElementById('v2-' + prefix + '-date-g').style.display = type === 'specific_date' ? '' : 'none';
}

function v2OpenAdd(tid, name) {
    document.getElementById('v2-add-tid').value = tid;
    document.getElementById('v2-add-teacher-name').textContent = name;
    document.getElementById('v2-add-type').value = 'weekly';
    v2ToggleType('add');
    v2OpenDialog('add');
}

function v2OpenEdit(d) {
    document.getElementById('v2-edit-sid').value   = d.id;
    document.getElementById('v2-edit-type').value  = d.type;
    document.getElementById('v2-edit-day').value   = d.day || 'monday';
    document.getElementById('v2-edit-sdate').value = d.specific_date || '';
    document.getElementById('v2-edit-start').value = d.start_time;
    document.getElementById('v2-edit-end').value   = d.end_time;
    document.getElementById('v2-edit-note').value  = d.note || '';
    v2ToggleType('edit');
    v2OpenDialog('edit');
}

function v2OpenDelete(sid) {
    document.getElementById('v2-del-sid').value = sid;
    v2OpenDialog('delete');
}

function v2FilterTeachers(q) {
    var lq = q.toLowerCase();
    document.querySelectorAll('#v2-teacher-list .v2-card').forEach(function(card) {
        card.style.display = (!lq || card.dataset.search.includes(lq)) ? '' : 'none';
    });
}
</script>
