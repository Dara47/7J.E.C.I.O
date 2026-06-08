<?php
/* =====================================================================
 * 7J English Center — จัดเวลาว่างครู (free_time_manage.php)
 * Standalone: ไม่เชื่อมกับระบบอื่น ใช้ตัวเองแยก
 * ===================================================================== */

$FT_URL = '/modules/7j/free_time_manage.php';

// ── Auto-create table ──────────────────────────────────────────────────
$connection2->exec("CREATE TABLE IF NOT EXISTS sevenj_free_time_slots (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id  VARCHAR(50)  NOT NULL DEFAULT '',
    teacher_name VARCHAR(120) NOT NULL DEFAULT '',
    teacher_code VARCHAR(50)  NOT NULL DEFAULT '',
    day_of_week  TINYINT     NOT NULL DEFAULT 0 COMMENT '0=Sun 1=Mon ... 6=Sat',
    slot_date    DATE        DEFAULT NULL,
    time_start   VARCHAR(5)  NOT NULL DEFAULT '09:00',
    time_end     VARCHAR(5)  NOT NULL DEFAULT '10:00',
    status       ENUM('free','booked') NOT NULL DEFAULT 'free',
    booked_name  VARCHAR(200) DEFAULT NULL,
    note         VARCHAR(500) DEFAULT NULL,
    created_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Allow NULL on slot_date for existing tables
try { $connection2->exec("ALTER TABLE sevenj_free_time_slots MODIFY slot_date DATE DEFAULT NULL"); } catch(\Throwable $e) {}

// ── Constants ──────────────────────────────────────────────────────────
$FT_DAYS_TH = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
$FT_DAYS_SH = ['อา','จ','อ','พ','พฤ','ศ','ส'];

// ── POST handlers ──────────────────────────────────────────────────────
$ft_msg = '';
$ft_act = trim($_POST['ft_act'] ?? '');

if ($ft_act === 'add') {
    $tid  = trim($_POST['ft_tid']  ?? '');
    $dow  = max(0, min(6, (int)($_POST['ft_day'] ?? 0)));
    $ts   = trim($_POST['ft_ts']   ?? '');
    $te   = trim($_POST['ft_te']   ?? '');
    $note = trim($_POST['ft_note'] ?? '') ?: null;

    if (!$tid || !$ts || !$te)
        $ft_msg = 'error|กรุณากรอกข้อมูลให้ครบถ้วน';
    elseif ($ts >= $te)
        $ft_msg = 'error|เวลาเริ่มต้องน้อยกว่าเวลาจบ';
    else {
        $tr = $connection2->prepare("SELECT id,displayName,teacherCode FROM sevenj_teachers WHERE id=?");
        $tr->execute([$tid]);
        $trow = $tr->fetch(PDO::FETCH_ASSOC);
        if (!$trow) { $ft_msg = 'error|ไม่พบครู'; }
        else {
            $connection2->prepare("INSERT INTO sevenj_free_time_slots (teacher_id,teacher_name,teacher_code,day_of_week,slot_date,time_start,time_end,note) VALUES (?,?,?,?,NULL,?,?,?)")
                ->execute([$trow['id'], $trow['displayName'], $trow['teacherCode']??'', $dow, $ts, $te, $note]);
            $ft_msg = 'success|เพิ่มช่วงเวลาว่างสำเร็จ';
        }
    }

} elseif ($ft_act === 'edit') {
    $fid  = (int)($_POST['ft_id']  ?? 0);
    $tid  = trim($_POST['ft_tid']  ?? '');
    $dow  = max(0, min(6, (int)($_POST['ft_day'] ?? 0)));
    $ts   = trim($_POST['ft_ts']   ?? '');
    $te   = trim($_POST['ft_te']   ?? '');
    $note = trim($_POST['ft_note'] ?? '') ?: null;

    if (!$fid || !$tid || !$ts || !$te)
        $ft_msg = 'error|ข้อมูลไม่ครบ';
    elseif ($ts >= $te)
        $ft_msg = 'error|เวลาเริ่มต้องน้อยกว่าเวลาจบ';
    else {
        $tr = $connection2->prepare("SELECT id,displayName,teacherCode FROM sevenj_teachers WHERE id=?");
        $tr->execute([$tid]);
        $trow = $tr->fetch(PDO::FETCH_ASSOC);
        if (!$trow) { $ft_msg = 'error|ไม่พบครู'; }
        else {
            $connection2->prepare("UPDATE sevenj_free_time_slots SET teacher_id=?,teacher_name=?,teacher_code=?,day_of_week=?,time_start=?,time_end=?,note=? WHERE id=?")
                ->execute([$trow['id'], $trow['displayName'], $trow['teacherCode']??'', $dow, $ts, $te, $note, $fid]);
            $ft_msg = 'success|แก้ไขสำเร็จ';
        }
    }

} elseif ($ft_act === 'book') {
    $fid   = (int)($_POST['ft_id']    ?? 0);
    $bname = trim($_POST['ft_bname']  ?? '') ?: null;
    if ($fid) {
        $connection2->prepare("UPDATE sevenj_free_time_slots SET status='booked', booked_name=? WHERE id=?")
            ->execute([$bname, $fid]);
        $ft_msg = 'success|จองสำเร็จ';
    }

} elseif ($ft_act === 'unbook') {
    $fid = (int)($_POST['ft_id'] ?? 0);
    if ($fid) {
        $connection2->prepare("UPDATE sevenj_free_time_slots SET status='free', booked_name=NULL WHERE id=?")
            ->execute([$fid]);
        $ft_msg = 'success|ยกเลิกการจองสำเร็จ';
    }

} elseif ($ft_act === 'delete') {
    $fid = (int)($_POST['ft_id'] ?? 0);
    if ($fid) {
        $connection2->prepare("DELETE FROM sevenj_free_time_slots WHERE id=?")->execute([$fid]);
        $ft_msg = 'success|ลบสำเร็จ';
    }
}

// ── Fetch teachers for dropdown ────────────────────────────────────────
$ftTeachers = $connection2->query(
    "SELECT id,displayName,teacherCode FROM sevenj_teachers WHERE status='active' ORDER BY displayName"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Search / filter params ─────────────────────────────────────────────
$ft_q      = trim($_GET['q2'] ?? '');   // search keyword (q2 เพื่อไม่ชนกับ q ของ Gibbon)
$ft_status = trim($_GET['st'] ?? '');   // 'free' | 'booked' | ''
$ft_tid_f  = trim($_GET['tf'] ?? '');   // teacher filter

// ── Data query ─────────────────────────────────────────────────────────
$whereParts = [];
$whereParams = [];

if ($ft_q) {
    $whereParts[]  = "(teacher_name LIKE ? OR teacher_code LIKE ? OR booked_name LIKE ? OR slot_date LIKE ?)";
    $lk = '%'.$ft_q.'%';
    $whereParams = array_merge($whereParams, [$lk,$lk,$lk,$lk]);
}
if ($ft_status === 'free' || $ft_status === 'booked') {
    $whereParts[]  = "status = ?";
    $whereParams[] = $ft_status;
}
if ($ft_tid_f) {
    $whereParts[]  = "teacher_id = ?";
    $whereParams[] = $ft_tid_f;
}

$whereSQL = $whereParts ? 'WHERE '.implode(' AND ', $whereParts) : '';
$stFt = $connection2->prepare("SELECT * FROM sevenj_free_time_slots $whereSQL ORDER BY slot_date DESC, time_start ASC");
$stFt->execute($whereParams);
$ftRows = $stFt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$ftTotal  = count($ftRows);
$ftFree   = count(array_filter($ftRows, fn($r) => $r['status'] === 'free'));
$ftBooked = $ftTotal - $ftFree;

[$ft_alertT, $ft_alertX] = $ft_msg ? explode('|', $ft_msg, 2) : ['',''];
$ft_baseUrl = '?q='.$FT_URL;
$ft_backUrl = $ft_baseUrl.($ft_q ? '&q2='.urlencode($ft_q) : '').($ft_status ? '&st='.urlencode($ft_status) : '').($ft_tid_f ? '&tf='.urlencode($ft_tid_f) : '');
?>
<?php require_once __DIR__.'/_theme.php'; ?>
<style>
.ft-wrap{font-family:var(--g-font,'Sarabun',sans-serif);}
.ft-tbl{width:100%;border-collapse:collapse;font-size:.84rem;}
.ft-tbl th{background:#1A2A5E;color:#fff;padding:9px 12px;text-align:left;font-size:.76rem;font-weight:700;white-space:nowrap;}
.ft-tbl td{padding:8px 12px;border-bottom:1px solid #e5e7eb;vertical-align:middle;}
.ft-tbl tbody tr:hover td{background:#fff8ef;}
.ft-tbl tbody tr:nth-child(even) td{background:#fafafa;}
.ft-tbl tbody tr:nth-child(even):hover td{background:#fff8ef;}
.ft-num{color:#9ca3af;font-size:.75rem;text-align:center;}
.ft-badge-free  {display:inline-block;padding:2px 10px;border-radius:99px;font-size:.72rem;font-weight:700;background:#dcfce7;color:#166534;}
.ft-badge-booked{display:inline-block;padding:2px 10px;border-radius:99px;font-size:.72rem;font-weight:700;background:#fee2e2;color:#991b1b;}
.ft-day{display:inline-block;width:32px;height:32px;line-height:32px;text-align:center;border-radius:50%;font-weight:700;font-size:.75rem;color:#fff;background:#1A2A5E;}
.ft-time{font-family:monospace;font-weight:700;color:#374151;font-size:.82rem;white-space:nowrap;}
.ft-tname{font-weight:700;color:#1e40af;font-size:.84rem;}
.ft-tcode{color:#6b7280;font-size:.72rem;}
.ft-stu{font-size:.78rem;color:#374151;margin-top:1px;}
.ft-acts{display:flex;gap:4px;flex-wrap:wrap;}
.ft-btn{padding:3px 9px;border-radius:5px;font-size:.72rem;font-weight:600;cursor:pointer;border:1px solid;white-space:nowrap;line-height:1.5;}
.ft-btn-book  {border-color:#16a34a;color:#16a34a;background:#fff;}.ft-btn-book:hover{background:#dcfce7;}
.ft-btn-unbook{border-color:#d97706;color:#d97706;background:#fff;}.ft-btn-unbook:hover{background:#fef3c7;}
.ft-btn-edit  {border-color:#2563eb;color:#2563eb;background:#fff;}.ft-btn-edit:hover{background:#dbeafe;}
.ft-btn-del   {border-color:#dc2626;color:#dc2626;background:#fff;}.ft-btn-del:hover{background:#fee2e2;}
/* search bar */
.ft-search{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;background:#fff8e7;border:1px solid #f5a623;border-radius:10px;padding:.85rem 1rem;margin-bottom:1rem;}
.ft-search input,.ft-search select{padding:7px 10px;border-radius:6px;border:1px solid #d1d5db;font-size:.84rem;background:#fff;outline:none;}
.ft-search input:focus,.ft-search select:focus{border-color:#E8640A;box-shadow:0 0 0 2px #fff8e7;}
/* stat pills */
.ft-stats{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:1rem;}
.ft-stat{padding:3px 13px;border-radius:20px;font-size:.76rem;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;}
.ft-s-all   {background:#dbeafe;color:#1e40af;}
.ft-s-free  {background:#dcfce7;color:#166534;}
.ft-s-booked{background:#fee2e2;color:#991b1b;}
.ft-s-active{outline:2px solid #374151;outline-offset:1px;}
/* modal */
.ft-mbg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;}
.ft-mbg.open{display:flex;}
.ft-mdl{background:#fff;border-radius:14px;width:100%;max-width:460px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);}
.ft-mhdr{padding:14px 18px 10px;background:linear-gradient(135deg,#1A2A5E,#E8640A);color:#fff;border-radius:14px 14px 0 0;display:flex;align-items:center;justify-content:space-between;}
.ft-mhdr h3{margin:0;font-size:.98rem;font-weight:800;}
.ft-mclose{background:none;border:none;color:#fff;font-size:1.2rem;cursor:pointer;opacity:.8;padding:0;line-height:1;}
.ft-mclose:hover{opacity:1;}
.ft-mbody{padding:14px 18px;}
.ft-mfoot{padding:10px 18px 14px;border-top:1px solid #f3f4f6;display:flex;justify-content:flex-end;gap:8px;}
.ft-frow{margin-bottom:11px;}
.ft-frow label{display:block;font-size:.78rem;font-weight:700;color:#374151;margin-bottom:3px;}
.ft-frow input,.ft-frow select,.ft-frow textarea{width:100%;padding:7px 9px;border-radius:7px;border:1px solid #d1d5db;font-size:.84rem;box-sizing:border-box;font-family:inherit;}
.ft-frow input:focus,.ft-frow select:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 2px #dbeafe;}
.ft-btn-cancel{padding:7px 16px;border-radius:7px;border:1.5px solid #d1d5db;background:#fff;font-size:.84rem;font-weight:600;cursor:pointer;color:#374151;}
.ft-btn-submit{padding:7px 18px;border-radius:7px;border:none;font-size:.84rem;font-weight:700;cursor:pointer;color:#fff;}
.ft-btn-primary{background:#2563eb;}.ft-btn-primary:hover{background:#1d4ed8;}
.ft-btn-green  {background:#16a34a;}.ft-btn-green:hover{background:#15803d;}
.ft-btn-danger {background:#dc2626;}.ft-btn-danger:hover{background:#b91c1c;}
.ft-dow-hint{font-size:.76rem;font-weight:600;margin-top:3px;display:none;}
.ft-err{display:none;padding:6px 10px;border-radius:6px;background:#fee2e2;color:#991b1b;font-size:.8rem;font-weight:600;margin-bottom:8px;}
</style>

<div class="ft-wrap">

<?php if ($ft_alertT): ?>
<div class="g-alert g-alert-<?= $ft_alertT === 'success' ? 'success' : 'error' ?>" style="margin-bottom:.9rem;">
  <?= $ft_alertT === 'success' ? '✅' : '❌' ?> <?= htmlspecialchars($ft_alertX) ?>
</div>
<?php endif; ?>

<!-- Header -->
<div class="g-header">
  <h1 class="g-title">📅 จัดเวลาว่างครู</h1>
  <button class="g-btn g-btn-primary" onclick="ftOpenAdd()">+ เพิ่มช่วงเวลาว่าง</button>
</div>

<!-- Search bar -->
<form method="get" class="ft-search" id="ft-search-form">
  <input type="hidden" name="q" value="<?= htmlspecialchars($FT_URL) ?>">
  <div>
    <label style="font-size:.74rem;font-weight:700;color:#92400e;display:block;margin-bottom:2px;">🔍 ค้นหา</label>
    <input name="q2" type="text" placeholder="ชื่อครู / รหัส / ชื่อนักเรียน / วันที่" value="<?= htmlspecialchars($ft_q) ?>" style="width:240px;">
  </div>
  <div>
    <label style="font-size:.74rem;font-weight:700;color:#92400e;display:block;margin-bottom:2px;">👨‍🏫 ครู</label>
    <select name="tf">
      <option value="">-- ครูทั้งหมด --</option>
      <?php foreach ($ftTeachers as $t): ?>
      <option value="<?= htmlspecialchars($t['id']) ?>" <?= $ft_tid_f===$t['id']?'selected':'' ?>>
        <?= htmlspecialchars($t['displayName']) ?> (<?= htmlspecialchars($t['teacherCode']??'') ?>)
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label style="font-size:.74rem;font-weight:700;color:#92400e;display:block;margin-bottom:2px;">สถานะ</label>
    <select name="st">
      <option value="" <?= $ft_status===''?'selected':'' ?>>ทั้งหมด</option>
      <option value="free"   <?= $ft_status==='free'  ?'selected':'' ?>>🟢 ว่าง</option>
      <option value="booked" <?= $ft_status==='booked'?'selected':'' ?>>🔴 จอง</option>
    </select>
  </div>
  <button type="submit" class="g-btn g-btn-primary g-btn-sm">ค้นหา</button>
  <a href="<?= $ft_baseUrl ?>" class="g-btn g-btn-outline g-btn-sm">รีเซ็ต</a>
</form>

<!-- Stats -->
<div class="ft-stats">
  <a href="<?= $ft_baseUrl.($ft_q?'&q2='.urlencode($ft_q):'').($ft_tid_f?'&tf='.urlencode($ft_tid_f):'') ?>"
     class="ft-stat ft-s-all<?= $ft_status===''?' ft-s-active':'' ?>">ทั้งหมด: <?= $ftTotal ?></a>
  <a href="<?= $ft_baseUrl.($ft_q?'&q2='.urlencode($ft_q):'').($ft_tid_f?'&tf='.urlencode($ft_tid_f):'').'&st=free' ?>"
     class="ft-stat ft-s-free<?= $ft_status==='free'?' ft-s-active':'' ?>">🟢 ว่าง: <?= $ftFree ?></a>
  <a href="<?= $ft_baseUrl.($ft_q?'&q2='.urlencode($ft_q):'').($ft_tid_f?'&tf='.urlencode($ft_tid_f):'').'&st=booked' ?>"
     class="ft-stat ft-s-booked<?= $ft_status==='booked'?' ft-s-active':'' ?>">🔴 จอง: <?= $ftBooked ?></a>
</div>

<!-- Table -->
<div style="overflow-x:auto;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.08);border:1px solid #e5e7eb;">
<table class="ft-tbl">
  <thead>
    <tr>
      <th style="width:44px;text-align:center;">#</th>
      <th>ชื่อครู (รหัส)</th>
      <th style="text-align:center;">วัน</th>
      <th>เวลา</th>
      <th style="text-align:center;">สถานะ</th>
      <th>นักเรียน</th>
      <th style="text-align:center;">จัดการ</th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$ftRows): ?>
  <tr><td colspan="8" style="text-align:center;padding:2rem;color:#9ca3af;font-size:.88rem;">ไม่พบข้อมูล</td></tr>
  <?php endif; ?>
  <?php foreach ($ftRows as $i => $r):
    $dow  = (int)$r['day_of_week'];
    $dayColors = ['#b91c1c','#1d4ed8','#0891b2','#059669','#d97706','#7c3aed','#0369a1'];
    $dayBg  = $dayColors[$dow] ?? '#374151';
    $isFree = $r['status'] === 'free';
  ?>
  <tr>
    <td class="ft-num"><?= $i+1 ?></td>
    <td>
      <span class="ft-tname"><?= htmlspecialchars($r['teacher_name']) ?></span>
      <?php if ($r['teacher_code']): ?>
        <span class="ft-tcode">(<?= htmlspecialchars($r['teacher_code']) ?>)</span>
      <?php endif; ?>
    </td>
    <td style="text-align:center;">
      <span class="ft-day" style="background:<?= $dayBg ?>;"><?= $FT_DAYS_SH[$dow] ?></span>
    </td>
    <td>
      <span class="ft-time"><?= htmlspecialchars($r['time_start']) ?> – <?= htmlspecialchars($r['time_end']) ?></span>
    </td>
    <td style="text-align:center;">
      <?php if ($isFree): ?>
        <span class="ft-badge-free">🟢 ว่าง</span>
      <?php else: ?>
        <span class="ft-badge-booked">🔴 จอง</span>
      <?php endif; ?>
    </td>
    <td>
      <?php if ($r['booked_name']): ?>
        <span class="ft-stu">🎓 <?= htmlspecialchars($r['booked_name']) ?></span>
      <?php else: ?>
        <span style="color:#d1d5db;font-size:.75rem;">—</span>
      <?php endif; ?>
      <?php if ($r['note']): ?>
        <div style="font-size:.7rem;color:#6b7280;font-style:italic;">📝 <?= htmlspecialchars($r['note']) ?></div>
      <?php endif; ?>
    </td>
    <td>
      <div class="ft-acts">
        <?php if ($isFree): ?>
        <button class="ft-btn ft-btn-book"
                onclick="ftOpenBook(<?= $r['id'] ?>,'<?= htmlspecialchars($r['teacher_name'],ENT_QUOTES) ?>','<?= $r['time_start'] ?>','<?= $r['time_end'] ?>','<?= $r['slot_date'] ?>')">
          📌 จอง
        </button>
        <?php else: ?>
        <form method="post" style="display:inline;" onsubmit="return confirm('ยกเลิกการจองนี้?')">
          <input type="hidden" name="q"      value="<?= htmlspecialchars($FT_URL) ?>">
          <input type="hidden" name="ft_act" value="unbook">
          <input type="hidden" name="ft_id"  value="<?= $r['id'] ?>">
          <?php if ($ft_q):    ?><input type="hidden" name="q2" value="<?= htmlspecialchars($ft_q) ?>"><?php endif; ?>
          <?php if ($ft_status): ?><input type="hidden" name="st" value="<?= htmlspecialchars($ft_status) ?>"><?php endif; ?>
          <?php if ($ft_tid_f): ?><input type="hidden" name="tf" value="<?= htmlspecialchars($ft_tid_f) ?>"><?php endif; ?>
          <button type="submit" class="ft-btn ft-btn-unbook">↩ ยกเลิกจอง</button>
        </form>
        <?php endif; ?>
        <button class="ft-btn ft-btn-edit"
                onclick="ftOpenEdit(<?= $r['id'] ?>,'<?= htmlspecialchars($r['teacher_id'],ENT_QUOTES) ?>',<?= (int)$r['day_of_week'] ?>,'<?= $r['time_start'] ?>','<?= $r['time_end'] ?>','<?= htmlspecialchars($r['note']??'',ENT_QUOTES) ?>')">
          ✏️ Edit
        </button>
        <form method="post" style="display:inline;" onsubmit="return confirm('ลบรายการนี้?')">
          <input type="hidden" name="q"      value="<?= htmlspecialchars($FT_URL) ?>">
          <input type="hidden" name="ft_act" value="delete">
          <input type="hidden" name="ft_id"  value="<?= $r['id'] ?>">
          <?php if ($ft_q):    ?><input type="hidden" name="q2" value="<?= htmlspecialchars($ft_q) ?>"><?php endif; ?>
          <?php if ($ft_status): ?><input type="hidden" name="st" value="<?= htmlspecialchars($ft_status) ?>"><?php endif; ?>
          <?php if ($ft_tid_f): ?><input type="hidden" name="tf" value="<?= htmlspecialchars($ft_tid_f) ?>"><?php endif; ?>
          <button type="submit" class="ft-btn ft-btn-del">🗑️ ลบ</button>
        </form>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<!-- ══ Modal: Add / Edit ══════════════════════════════════════════════ -->
<div id="ft-modal-form" class="ft-mbg">
<div class="ft-mdl">
  <div class="ft-mhdr">
    <h3 id="ft-form-title">เพิ่มช่วงเวลาว่าง</h3>
    <button class="ft-mclose" onclick="ftCloseModal('ft-modal-form')">✕</button>
  </div>
  <form method="post" id="ft-form" onsubmit="return ftValidate()">
    <div class="ft-mbody">
      <input type="hidden" name="q"      value="<?= htmlspecialchars($FT_URL) ?>">
      <?php if ($ft_q):    ?><input type="hidden" name="q2" value="<?= htmlspecialchars($ft_q) ?>"><?php endif; ?>
      <?php if ($ft_status): ?><input type="hidden" name="st" value="<?= htmlspecialchars($ft_status) ?>"><?php endif; ?>
      <?php if ($ft_tid_f): ?><input type="hidden" name="tf" value="<?= htmlspecialchars($ft_tid_f) ?>"><?php endif; ?>
      <input type="hidden" name="ft_act" id="ft-f-act" value="add">
      <input type="hidden" name="ft_id"  id="ft-f-id"  value="">

      <div class="ft-err" id="ft-f-err"></div>

      <!-- ค้นหา + ครู -->
      <div class="ft-frow">
        <label>🔍 ค้นหาครู</label>
        <input type="text" id="ft-f-tid-search" placeholder="พิมพ์ชื่อหรือรหัสครู..."
               oninput="ftFilterTeacher(this.value)" autocomplete="off"
               style="margin-bottom:5px;">
        <select name="ft_tid" id="ft-f-tid" required size="4"
                style="height:auto;border-radius:7px;border:1px solid #d1d5db;font-size:.84rem;width:100%;padding:3px 0;">
          <option value="">-- เลือกครู --</option>
          <?php foreach ($ftTeachers as $t): ?>
          <option value="<?= htmlspecialchars($t['id']) ?>"
                  data-name="<?= mb_strtolower(htmlspecialchars($t['displayName'])) ?>"
                  data-code="<?= mb_strtolower(htmlspecialchars($t['teacherCode']??'')) ?>">
            <?= htmlspecialchars($t['displayName']) ?> (<?= htmlspecialchars($t['teacherCode']??'') ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- วัน -->
      <div class="ft-frow">
        <label>วัน *</label>
        <select name="ft_day" id="ft-f-day" required>
          <?php foreach ($FT_DAYS_TH as $di => $dn): ?>
          <option value="<?= $di ?>"><?= $dn ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- เวลา -->
      <div class="ft-frow">
        <label>เวลา (24H) *</label>
        <div style="display:flex;gap:8px;align-items:center;">
          <input type="text" name="ft_ts" id="ft-f-ts" value="09:00" maxlength="5" placeholder="09:00"
                 oninput="ftTimeFmt(this)"
                 style="flex:1;padding:7px 6px;border-radius:7px;border:1px solid #d1d5db;font-family:monospace;font-size:.95rem;text-align:center;">
          <span style="font-weight:700;color:#374151;">—</span>
          <input type="text" name="ft_te" id="ft-f-te" value="10:00" maxlength="5" placeholder="10:00"
                 oninput="ftTimeFmt(this)"
                 style="flex:1;padding:7px 6px;border-radius:7px;border:1px solid #d1d5db;font-family:monospace;font-size:.95rem;text-align:center;">
        </div>
      </div>

      <!-- หมายเหตุ -->
      <div class="ft-frow">
        <label>หมายเหตุ</label>
        <input type="text" name="ft_note" id="ft-f-note" maxlength="300" placeholder="(ไม่บังคับ)">
      </div>
    </div>
    <div class="ft-mfoot">
      <button type="button" class="ft-btn-cancel" onclick="ftCloseModal('ft-modal-form')">ยกเลิก</button>
      <button type="submit" class="ft-btn-submit ft-btn-primary" id="ft-f-submit">เพิ่ม</button>
    </div>
  </form>
</div>
</div>

<!-- ══ Modal: Book ════════════════════════════════════════════════════ -->
<div id="ft-modal-book" class="ft-mbg">
<div class="ft-mdl" style="max-width:400px;">
  <div class="ft-mhdr">
    <h3>📌 จองช่วงเวลา</h3>
    <button class="ft-mclose" onclick="ftCloseModal('ft-modal-book')">✕</button>
  </div>
  <form method="post" id="ft-form-book">
    <div class="ft-mbody">
      <input type="hidden" name="q"      value="<?= htmlspecialchars($FT_URL) ?>">
      <?php if ($ft_q):    ?><input type="hidden" name="q2" value="<?= htmlspecialchars($ft_q) ?>"><?php endif; ?>
      <?php if ($ft_status): ?><input type="hidden" name="st" value="<?= htmlspecialchars($ft_status) ?>"><?php endif; ?>
      <?php if ($ft_tid_f): ?><input type="hidden" name="tf" value="<?= htmlspecialchars($ft_tid_f) ?>"><?php endif; ?>
      <input type="hidden" name="ft_act" value="book">
      <input type="hidden" name="ft_id"  id="ft-bk-id" value="">

      <div style="background:#f1f5f9;border-radius:8px;padding:10px 13px;margin-bottom:12px;">
        <div id="ft-bk-info" style="font-size:.87rem;color:#1e3a8a;font-weight:700;"></div>
      </div>

      <div class="ft-frow">
        <label>ชื่อนักเรียน <span style="color:#9ca3af;font-weight:400;">(ไม่บังคับ)</span></label>
        <input type="text" name="ft_bname" id="ft-bk-name" maxlength="200" placeholder="พิมพ์ชื่อนักเรียน หรือเว้นว่างไว้ก็ได้">
      </div>
    </div>
    <div class="ft-mfoot">
      <button type="button" class="ft-btn-cancel" onclick="ftCloseModal('ft-modal-book')">ยกเลิก</button>
      <button type="submit" class="ft-btn-submit ft-btn-green">✅ ยืนยันจอง</button>
    </div>
  </form>
</div>
</div>

</div><!-- .ft-wrap -->

<script>
var _ftDaysTH = <?= json_encode($FT_DAYS_TH) ?>;
var _ftDaysSH = <?= json_encode($FT_DAYS_SH) ?>;

function ftCloseModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('open');
}
function ftOpenModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('open');
}
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('ft-mbg')) e.target.classList.remove('open');
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.querySelectorAll('.ft-mbg.open').forEach(function(m){ m.classList.remove('open'); });
});

function ftFilterTeacher(q) {
    var sel = document.getElementById('ft-f-tid');
    var opts = sel.querySelectorAll('option');
    q = q.toLowerCase().trim();
    opts.forEach(function(o) {
        if (!o.value) { o.style.display = ''; return; }
        var match = !q || (o.dataset.name||'').includes(q) || (o.dataset.code||'').includes(q);
        o.style.display = match ? '' : 'none';
    });
    // ถ้า option ที่เลือกอยู่ถูกซ่อน ให้ reset
    if (sel.selectedOptions[0] && sel.selectedOptions[0].style.display === 'none') {
        sel.value = '';
    }
}

function ftTimeFmt(el) {
    var raw = el.value.replace(/[^0-9]/g, '');
    if (raw.length > 4) raw = raw.slice(0, 4);
    if (raw.length >= 3) raw = raw.slice(0, 2) + ':' + raw.slice(2);
    el.value = raw;
}
function ftTimeOk(v) {
    if (!/^\d{2}:\d{2}$/.test(v)) return false;
    var p = v.split(':'); return +p[0] <= 23 && +p[1] <= 59;
}

function ftValidate() {
    var err = document.getElementById('ft-f-err');
    err.style.display = 'none';
    var ts = document.getElementById('ft-f-ts').value;
    var te = document.getElementById('ft-f-te').value;
    if (!ftTimeOk(ts) || !ftTimeOk(te)) {
        err.textContent = '⚠️ กรุณาระบุเวลาในรูปแบบ HH:MM เช่น 09:30';
        err.style.display = 'block'; return false;
    }
    if (ts >= te) {
        err.textContent = '⚠️ เวลาเริ่มต้องน้อยกว่าเวลาจบ';
        err.style.display = 'block'; return false;
    }
    return true;
}

function ftOpenAdd() {
    document.getElementById('ft-f-act').value      = 'add';
    document.getElementById('ft-f-id').value       = '';
    document.getElementById('ft-form-title').textContent = 'เพิ่มช่วงเวลาว่าง';
    document.getElementById('ft-f-submit').textContent   = 'เพิ่ม';
    document.getElementById('ft-f-tid-search').value = '';
    ftFilterTeacher('');
    document.getElementById('ft-f-tid').value  = '';
    document.getElementById('ft-f-day').value  = '1';
    document.getElementById('ft-f-ts').value   = '09:00';
    document.getElementById('ft-f-te').value   = '10:00';
    document.getElementById('ft-f-note').value = '';
    document.getElementById('ft-f-err').style.display = 'none';
    ftOpenModal('ft-modal-form');
}

function ftOpenEdit(id, tid, dow, ts, te, note) {
    document.getElementById('ft-f-act').value      = 'edit';
    document.getElementById('ft-f-id').value       = id;
    document.getElementById('ft-form-title').textContent = 'แก้ไขช่วงเวลาว่าง';
    document.getElementById('ft-f-submit').textContent   = 'บันทึก';
    document.getElementById('ft-f-tid-search').value = '';
    ftFilterTeacher('');
    document.getElementById('ft-f-tid').value  = tid;
    document.getElementById('ft-f-day').value  = dow;
    document.getElementById('ft-f-ts').value   = ts;
    document.getElementById('ft-f-te').value   = te;
    document.getElementById('ft-f-note').value = note;
    document.getElementById('ft-f-err').style.display = 'none';
    ftOpenModal('ft-modal-form');
}

function ftOpenBook(id, tname, ts, te, date) {
    document.getElementById('ft-bk-id').value   = id;
    document.getElementById('ft-bk-name').value = '';
    var dow = new Date(date + 'T00:00:00').getDay();
    document.getElementById('ft-bk-info').innerHTML =
        '<div>👨‍🏫 ' + tname + '</div>' +
        '<div style="font-size:.8rem;color:#374151;margin-top:2px;">📅 วัน' + _ftDaysTH[dow] + ' ' + date + '&nbsp;&nbsp;⏰ ' + ts + ' – ' + te + '</div>';
    ftOpenModal('ft-modal-book');
}
</script>
