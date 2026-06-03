<?php
/*
 * 7J English Center — Import CSV
 * นำเข้าข้อมูลนักเรียน/ครู จากไฟล์ CSV
 */

// ─── ฟังก์ชัน helper ──────────────────────────────────────────────────────────
function nextCode($conn, $table, $col, $prefix, $start) {
    $row = $conn->query("SELECT $col FROM $table WHERE $col REGEXP '^{$prefix}[0-9]+$'
        ORDER BY CAST(SUBSTRING($col,".strlen($prefix)."+1) AS UNSIGNED) DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row && preg_match('/^'.preg_quote($prefix).'(\d+)$/', $row[$col], $m)) {
        return $prefix . str_pad((int)$m[1] + 1, 6, '0', STR_PAD_LEFT);
    }
    return $prefix . $start;
}

function parseCSV($filepath, $type) {
    $rows = [];
    $errors = [];
    if (($fh = fopen($filepath, 'r')) === false) return [[], ['ไม่สามารถอ่านไฟล์ได้']];

    $headers = null;
    $line = 0;
    while (($data = fgetcsv($fh, 2000, ',')) !== false) {
        $line++;
        // skip empty lines
        if (count(array_filter($data, fn($v) => trim($v) !== '')) === 0) continue;

        if ($headers === null) {
            $headers = array_map(fn($h) => strtolower(trim($h)), $data);
            continue;
        }

        $row = [];
        foreach ($headers as $i => $h) {
            $row[$h] = isset($data[$i]) ? trim($data[$i]) : '';
        }

        // validate displayName
        $name = $row['displayname'] ?? $row['ชื่อ-นามสกุล'] ?? $row['name'] ?? '';
        if ($name === '') {
            $errors[] = "แถวที่ $line: ไม่มีชื่อ — ข้ามแถวนี้";
            continue;
        }
        $rows[] = $row;
    }
    fclose($fh);
    return [$rows, $errors];
}

// ─── Sample CSV content ───────────────────────────────────────────────────────
$sampleStudent = "displayName,nickname,age,teacherCode,googleMeetLink,totalClasses,password\n"
    . "เด็กหญิงมานี มีบุญ,มานี,12,T260001,,20,\n"
    . "เด็กชายปิติ ใจดี,ปิติ,14,T260001,https://meet.google.com/xxx,30,\n"
    . "Miss Sarah Johnson,Sarah,22,T260002,,20,MyPass123\n";

$sampleTeacher = "displayName,nickname,age,location,googleMeetLink,password\n"
    . "นางสาวสมใจ รักเรียน,ใจ,30,Bangkok,,\n"
    . "นายวิชัย สอนดี,ชัย,35,Chiang Mai,https://meet.google.com/yyy,\n"
    . "Miss Emma Watson,Emma,28,Online,,MyPass456\n";

// ─── ดาวน์โหลด sample CSV ────────────────────────────────────────────────────
if (isset($_GET['download'])) {
    $type = $_GET['download'] === 'teachers' ? 'teachers' : 'students';
    $content = $type === 'teachers' ? $sampleTeacher : $sampleStudent;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sample_' . $type . '.csv"');
    header('Content-Length: ' . strlen("\xEF\xBB\xBF" . $content));
    echo "\xEF\xBB\xBF" . $content; // BOM สำหรับ Excel ไทย
    exit;
}

// ─── State machine ────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? 'upload';
$type   = $_POST['type'] ?? $_GET['type'] ?? 'students';
$result = [];
$errors = [];
$previewRows = [];

// ─── IMPORT: ประมวลผลจริง ────────────────────────────────────────────────────
if ($action === 'import' && !empty($_POST['rows_json'])) {
    $rows    = json_decode($_POST['rows_json'], true) ?? [];
    $type    = $_POST['type'] ?? 'students';
    $ok = $fail = 0;

    // สร้าง teacher map (code → id) สำหรับ students
    $teacherMap = [];
    if ($type === 'students') {
        foreach ($connection2->query("SELECT id, teacherCode FROM sevenj_teachers")->fetchAll(PDO::FETCH_ASSOC) as $t) {
            if ($t['teacherCode']) $teacherMap[$t['teacherCode']] = $t['id'];
        }
    }

    foreach ($rows as $i => $row) {
        try {
            if ($type === 'students') {
                $code   = nextCode($connection2, 'sevenj_students', 'studentCode', 'S', '260001');
                $id     = 'student_' . time() . rand(100,999) . $i;
                $uname  = $code . '@7J.com';
                $pass   = $row['password'] !== '' ? $row['password'] : $code;
                $hash   = password_hash($pass, PASSWORD_DEFAULT);
                $name   = $row['displayname'] ?? $row['ชื่อ-นามสกุล'] ?? '';
                $nick   = $row['nickname'] ?? $row['ชื่อเล่น'] ?? '';
                $age    = (int)($row['age'] ?? $row['อายุ'] ?? 0) ?: null;
                $tcode  = $row['teachercode'] ?? $row['รหัสครู'] ?? '';
                $tid    = $teacherMap[$tcode] ?? null;
                $meet   = $row['googlemeetlink'] ?? $row['google meet link'] ?? '';
                $total  = (int)($row['totalclasses'] ?? $row['คาบทั้งหมด'] ?? 20);

                $connection2->query("INSERT INTO sevenj_students
                    (id,studentCode,username,password_hash,displayName,nickname,age,teacherId,googleMeetLink,totalClasses,completedClasses,status)
                    VALUES (
                    '".$connection2->quote($id)."',
                    '".$connection2->quote($code)."',
                    '".$connection2->quote($uname)."',
                    '".$connection2->quote($hash)."',
                    '".$connection2->quote($name)."',
                    ".($nick !== '' ? "'".$connection2->quote($nick)."'" : "NULL").",
                    ".($age !== null ? $age : "NULL").",
                    ".($tid !== null ? "'".$connection2->quote($tid)."'" : "NULL").",
                    ".($meet !== '' ? "'".$connection2->quote($meet)."'" : "NULL").",
                    $total, 0, 'active')");

                $result[] = ['ok'=>true,'name'=>$name,'code'=>$code,'user'=>$uname,'pass'=>$pass];
                $ok++;
            } else {
                $code   = nextCode($connection2, 'sevenj_teachers', 'teacherCode', 'T', '260001');
                $id     = 'teacher_' . time() . rand(100,999) . $i;
                $uname  = $code . '@7J.com';
                $pass   = $row['password'] !== '' ? $row['password'] : $code;
                $hash   = password_hash($pass, PASSWORD_DEFAULT);
                $name   = $row['displayname'] ?? $row['ชื่อ-นามสกุล'] ?? '';
                $nick   = $row['nickname'] ?? $row['ชื่อเล่น'] ?? '';
                $age    = (int)($row['age'] ?? $row['อายุ'] ?? 0) ?: null;
                $loc    = $row['location'] ?? $row['สถานที่'] ?? '';
                $meet   = $row['googlemeetlink'] ?? $row['google meet link'] ?? '';

                $connection2->query("INSERT INTO sevenj_teachers
                    (id,teacherCode,username,password_hash,displayName,nickname,age,location,googleMeetLink,status)
                    VALUES (
                    '".$connection2->quote($id)."',
                    '".$connection2->quote($code)."',
                    '".$connection2->quote($uname)."',
                    '".$connection2->quote($hash)."',
                    '".$connection2->quote($name)."',
                    ".($nick !== '' ? "'".$connection2->quote($nick)."'" : "NULL").",
                    ".($age !== null ? $age : "NULL").",
                    ".($loc !== '' ? "'".$connection2->quote($loc)."'" : "NULL").",
                    ".($meet !== '' ? "'".$connection2->quote($meet)."'" : "NULL").",
                    'active')");

                $result[] = ['ok'=>true,'name'=>$name,'code'=>$code,'user'=>$uname,'pass'=>$pass];
                $ok++;
            }
        } catch (Exception $e) {
            $result[] = ['ok'=>false,'name'=>($row['displayname']??'?'),'error'=>$e->getMessage()];
            $fail++;
        }
    }
    $action = 'result';
}

// ─── PREVIEW: อ่าน CSV แล้ว preview ─────────────────────────────────────────
if ($action === 'preview' && isset($_FILES['csvfile']) && $_FILES['csvfile']['error'] === 0) {
    [$previewRows, $errors] = parseCSV($_FILES['csvfile']['tmp_name'], $type);
    if (empty($previewRows)) {
        $action = 'upload';
        $errors[] = 'ไม่พบข้อมูลในไฟล์ CSV';
    }
}
?>

<?php require_once __DIR__.'/_theme.php'; ?>
<style>
.ic-tab{padding:8px 18px;border:none;background:none;font-weight:600;font-size:.85rem;cursor:pointer;border-bottom:3px solid transparent;color:#6b7280;}
.ic-tab.active{color:#d97706;border-bottom-color:#d97706;}
.ic-card{background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:1.5rem;}
.ic-btn{padding:8px 20px;border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;border:none;transition:opacity .15s;}
.ic-btn:hover{opacity:.85;}
.ic-btn-primary{background:linear-gradient(135deg,#92400e,#d97706);color:#fff;}
.ic-btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db;}
.ic-btn-green{background:#059669;color:#fff;}
.ic-table{width:100%;border-collapse:collapse;font-size:.83rem;}
.ic-table th{background:#fffbeb;color:#92400e;text-align:left;padding:8px 10px;border-bottom:2px solid #fde68a;font-weight:700;white-space:nowrap;}
.ic-table td{padding:7px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.ic-table tr:hover td{background:#fffbeb;}
.ic-badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:.72rem;font-weight:700;}
.ic-badge-ok{background:#d1fae5;color:#065f46;}
.ic-badge-err{background:#fee2e2;color:#991b1b;}
.ic-hint{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:.82rem;color:#92400e;margin-bottom:1rem;}
.ic-drop{border:2px dashed #fde68a;border-radius:12px;padding:2.5rem;text-align:center;background:#fffbeb;cursor:pointer;transition:border-color .2s;}
.ic-drop:hover,.ic-drop.drag{border-color:#d97706;background:#fef3c7;}
</style>

<div style="max-width:860px;padding-bottom:2rem;">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:8px;">
    <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0;">📥 Import CSV</h2>
    <div style="display:flex;gap:8px;">
        <a href="?q=/modules/7j/import_csv.php&download=students&type=students"
           class="ic-btn ic-btn-outline" style="font-size:.8rem;text-decoration:none;display:inline-flex;align-items:center;gap:5px;">
            ⬇ CSV ตัวอย่าง (นักเรียน)
        </a>
        <a href="?q=/modules/7j/import_csv.php&download=teachers&type=teachers"
           class="ic-btn ic-btn-outline" style="font-size:.8rem;text-decoration:none;display:inline-flex;align-items:center;gap:5px;">
            ⬇ CSV ตัวอย่าง (ครู)
        </a>
    </div>
</div>

<?php if ($action === 'upload' || $action === 'preview' && !empty($errors)): ?>

<!-- ─── UPLOAD FORM ─────────────────────────────────────────────────────────── -->
<?php if (!empty($errors)): ?>
<div style="background:#fee2e2;border-radius:8px;padding:10px 16px;margin-bottom:1rem;color:#991b1b;font-size:.88rem;">
    <?php foreach ($errors as $e): ?><div>⚠ <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Type tabs -->
<div style="border-bottom:1px solid #e5e7eb;margin-bottom:1.25rem;">
    <button class="ic-tab <?= $type==='students'?'active':'' ?>" onclick="setType('students')">🎓 นักเรียน</button>
    <button class="ic-tab <?= $type==='teachers'?'active':'' ?>" onclick="setType('teachers')">👨‍🏫 ครู</button>
</div>

<div class="ic-card">
    <div class="ic-hint">
        <strong>คอลัมน์ที่ต้องการ <?= $type==='students'?'(นักเรียน)':'(ครู)' ?>:</strong><br>
        <?php if ($type==='students'): ?>
        <code>displayName, nickname, age, teacherCode, googleMeetLink, totalClasses, password</code><br>
        <span style="color:#78350f;">• teacherCode เช่น T260001 (ถ้าไม่มีครูให้ว่างไว้)</span><br>
        <span style="color:#78350f;">• password ถ้าว่างจะใช้ studentCode เป็น password อัตโนมัติ</span>
        <?php else: ?>
        <code>displayName, nickname, age, location, googleMeetLink, password</code><br>
        <span style="color:#78350f;">• password ถ้าว่างจะใช้ teacherCode เป็น password อัตโนมัติ</span>
        <?php endif; ?>
    </div>

    <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <input type="hidden" name="action" value="preview">
        <input type="hidden" name="type" id="typeInput" value="<?= htmlspecialchars($type) ?>">

        <!-- Drop zone -->
        <div class="ic-drop" id="dropZone" onclick="document.getElementById('csvfile').click()">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="1.5" style="margin:0 auto 12px;display:block;">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <div style="font-size:1rem;font-weight:600;color:#92400e;margin-bottom:4px;">
                คลิกเพื่อเลือกไฟล์ หรือลากมาวาง
            </div>
            <div style="font-size:.8rem;color:#9ca3af;">รองรับ .csv (UTF-8 หรือ UTF-8 BOM) ขนาดไม่เกิน 5 MB</div>
            <div id="fileName" style="margin-top:10px;font-size:.85rem;color:#d97706;font-weight:600;"></div>
        </div>
        <input type="file" name="csvfile" id="csvfile" accept=".csv,text/csv" style="display:none;"
               onchange="document.getElementById('fileName').textContent=this.files[0]?.name||''; document.getElementById('uploadForm').submit();">
    </form>
</div>

<script>
function setType(t) {
    document.getElementById('typeInput').value = t;
    window.location.href = '?q=/modules/7j/import_csv.php&type=' + t;
}
// Drag-drop
const dz = document.getElementById('dropZone');
if (dz) {
    dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('drag'));
    dz.addEventListener('drop', e => {
        e.preventDefault(); dz.classList.remove('drag');
        const f = e.dataTransfer.files[0];
        if (!f) return;
        document.getElementById('csvfile').files = e.dataTransfer.files;
        document.getElementById('fileName').textContent = f.name;
        document.getElementById('uploadForm').submit();
    });
}
</script>

<?php elseif ($action === 'preview' && !empty($previewRows)): ?>

<!-- ─── PREVIEW ───────────────────────────────────────────────────────────────── -->
<div class="ic-card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:8px;">
        <div>
            <span style="font-size:1.05rem;font-weight:700;color:#1f2937;">
                พบข้อมูล <?= count($previewRows) ?> แถว — <?= $type==='students'?'นักเรียน':'ครู' ?>
            </span>
            <?php if (!empty($errors)): ?>
            <span style="margin-left:8px;font-size:.8rem;color:#dc2626;">(ข้ามไป <?= count($errors) ?> แถว)</span>
            <?php endif; ?>
        </div>
        <a href="?q=/modules/7j/import_csv.php&type=<?= $type ?>"
           class="ic-btn ic-btn-outline" style="font-size:.8rem;text-decoration:none;">← เลือกไฟล์ใหม่</a>
    </div>

    <div style="overflow-x:auto;margin-bottom:1rem;">
    <table class="ic-table">
        <thead>
            <tr>
                <th>#</th>
                <th>ชื่อ-นามสกุล</th>
                <th>ชื่อเล่น</th>
                <th>อายุ</th>
                <?php if ($type==='students'): ?>
                <th>รหัสครู</th>
                <th>คาบทั้งหมด</th>
                <?php else: ?>
                <th>สถานที่</th>
                <?php endif; ?>
                <th>Password</th>
                <th>Meet Link</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($previewRows as $i => $row):
            $name  = $row['displayname'] ?? $row['ชื่อ-นามสกุล'] ?? '';
            $nick  = $row['nickname'] ?? $row['ชื่อเล่น'] ?? '';
            $age   = $row['age'] ?? $row['อายุ'] ?? '';
            $pass  = ($row['password']??'') !== '' ? $row['password'] : '(auto = รหัส)';
            $meet  = $row['googlemeetlink'] ?? $row['google meet link'] ?? '';
            if ($type==='students') {
                $tcode = $row['teachercode'] ?? $row['รหัสครู'] ?? '';
                $total = $row['totalclasses'] ?? $row['คาบทั้งหมด'] ?? '20';
            } else {
                $loc = $row['location'] ?? $row['สถานที่'] ?? '';
            }
        ?>
        <tr>
            <td style="color:#9ca3af;"><?= $i+1 ?></td>
            <td style="font-weight:600;"><?= htmlspecialchars($name) ?></td>
            <td><?= htmlspecialchars($nick) ?></td>
            <td><?= htmlspecialchars($age) ?></td>
            <?php if ($type==='students'): ?>
            <td>
                <?php if ($tcode): ?>
                <span style="background:#fff7ed;color:#9a3412;padding:1px 7px;border-radius:99px;font-size:.75rem;font-weight:700;"><?= htmlspecialchars($tcode) ?></span>
                <?php else: ?>
                <span style="color:#9ca3af;">—</span>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($total) ?></td>
            <?php else: ?>
            <td><?= htmlspecialchars($loc) ?></td>
            <?php endif; ?>
            <td style="color:#059669;font-size:.8rem;"><?= htmlspecialchars($pass) ?></td>
            <td style="font-size:.78rem;color:#2563eb;"><?= $meet ? '✓ '.mb_substr(htmlspecialchars($meet),0,25).'…' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if (!empty($errors)): ?>
    <details style="margin-bottom:1rem;">
        <summary style="cursor:pointer;font-size:.82rem;color:#dc2626;">⚠ แถวที่มีปัญหา (<?= count($errors) ?>)</summary>
        <div style="margin-top:6px;font-size:.8rem;color:#374151;">
            <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>

    <!-- Confirm form -->
    <form method="POST">
        <input type="hidden" name="action" value="import">
        <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
        <input type="hidden" name="rows_json" value="<?= htmlspecialchars(json_encode($previewRows)) ?>">
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <a href="?q=/modules/7j/import_csv.php&type=<?= $type ?>"
               class="ic-btn ic-btn-outline" style="text-decoration:none;">ยกเลิก</a>
            <button type="submit" class="ic-btn ic-btn-green">
                ✓ นำเข้า <?= count($previewRows) ?> รายการ
            </button>
        </div>
    </form>
</div>

<?php elseif ($action === 'result'): ?>

<!-- ─── RESULT ────────────────────────────────────────────────────────────────── -->
<?php
$ok   = count(array_filter($result, fn($r) => $r['ok']));
$fail = count(array_filter($result, fn($r) => !$r['ok']));
?>
<div style="background:<?= $fail===0?'#d1fae5':'#fef3c7' ?>;border-radius:10px;padding:14px 18px;margin-bottom:1.25rem;font-weight:600;font-size:.95rem;color:<?= $fail===0?'#065f46':'#92400e' ?>;">
    <?= $fail===0 ? '✅' : '⚠' ?>
    นำเข้าสำเร็จ <?= $ok ?> รายการ<?= $fail>0 ? " / ผิดพลาด {$fail} รายการ" : '' ?>
</div>

<div class="ic-card">
    <div style="overflow-x:auto;">
    <table class="ic-table">
        <thead>
            <tr>
                <th>#</th>
                <th>ชื่อ</th>
                <th>รหัส</th>
                <th>Username (Portal)</th>
                <th>Password</th>
                <th>สถานะ</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($result as $i => $r): ?>
        <tr>
            <td style="color:#9ca3af;"><?= $i+1 ?></td>
            <td style="font-weight:600;"><?= htmlspecialchars($r['name']) ?></td>
            <td>
                <?php if ($r['ok']): ?>
                <span style="background:#fff7ed;color:#9a3412;padding:1px 7px;border-radius:99px;font-size:.75rem;font-weight:700;"><?= htmlspecialchars($r['code']) ?></span>
                <?php endif; ?>
            </td>
            <td style="font-size:.83rem;color:#2563eb;"><?= htmlspecialchars($r['user']??'') ?></td>
            <td style="font-size:.83rem;font-family:monospace;color:#059669;"><?= htmlspecialchars($r['pass']??'') ?></td>
            <td>
                <?php if ($r['ok']): ?>
                <span class="ic-badge ic-badge-ok">✓ สำเร็จ</span>
                <?php else: ?>
                <span class="ic-badge ic-badge-err" title="<?= htmlspecialchars($r['error']??'') ?>">✗ ผิดพลาด</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:1rem;">
        <a href="?q=/modules/7j/import_csv.php&type=<?= htmlspecialchars($type) ?>"
           class="ic-btn ic-btn-outline" style="text-decoration:none;">📥 Import เพิ่มเติม</a>
        <a href="?q=/modules/7j/user_management.php&tab=<?= $type ?>"
           class="ic-btn ic-btn-primary" style="text-decoration:none;">👥 ดูข้อมูล <?= $type==='students'?'นักเรียน':'ครู' ?></a>
    </div>
</div>

<?php endif; ?>

</div>
