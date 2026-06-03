<?php
/*
 * 7J English Center - System Settings
 * Ported from Settings.tsx (React/Firebase)
 * Stores: LINE ID, QR Code Image, Admin Note, Financial Sheet URL
 */

$msg = '';

// ── Handle POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {

    $lineId      = trim($_POST['line_id']      ?? '');
    $adminNote   = trim($_POST['admin_note']   ?? '');
    $sheetUrl    = trim($_POST['financial_url'] ?? '');

    // QR code: prefer new upload, else URL field, else keep existing
    $qrImage = '';
    if (!empty($_FILES['qr_file']['tmp_name'])) {
        $file = $_FILES['qr_file'];
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        if (!in_array($file['type'], $allowed)) {
            $msg = 'error|รูปแบบไฟล์ไม่ถูกต้อง (รองรับ JPG, PNG, GIF, WebP)';
        } elseif ($file['size'] > 600 * 1024) {
            $msg = 'error|ไฟล์ใหญ่เกินไป (สูงสุด 600 KB)';
        } else {
            $data = file_get_contents($file['tmp_name']);
            $qrImage = 'data:' . $file['type'] . ';base64,' . base64_encode($data);
        }
    } elseif (!empty($_POST['qr_url'])) {
        $qrImage = trim($_POST['qr_url']);
    } else {
        // Keep existing
        $row = $connection2->query("SELECT qr_code_image FROM sevenj_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        $qrImage = $row['qr_code_image'] ?? '';
    }

    if (!$msg) {
        // Remove QR if user clicked Remove
        if (isset($_POST['remove_qr'])) $qrImage = '';

        $stmt = $connection2->prepare(
            "INSERT INTO sevenj_config (id, line_id, admin_note, qr_code_image, financial_sheet_url)
             VALUES (1, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               line_id=VALUES(line_id),
               admin_note=VALUES(admin_note),
               qr_code_image=VALUES(qr_code_image),
               financial_sheet_url=VALUES(financial_sheet_url)"
        );
        $stmt->execute([$lineId, $adminNote, $qrImage, $sheetUrl]);
        $msg = 'success|บันทึกการตั้งค่าสำเร็จ ✓';
    }
}

// ── Load current config ───────────────────────────────────────────
$cfg = $connection2->query("SELECT * FROM sevenj_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
if (!$cfg) $cfg = ['line_id'=>'','admin_note'=>'','qr_code_image'=>'','financial_sheet_url'=>''];

[$alertType, $alertText] = $msg ? explode('|', $msg, 2) : ['',''];
?>
<style>
.cfg-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);padding:1.25rem 1.5rem;margin-bottom:1.25rem;}
.cfg-label{font-size:.8rem;font-weight:700;color:#374151;margin-bottom:5px;display:block;}
.cfg-input{width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 12px;font-size:.9rem;box-sizing:border-box;font-family:inherit;}
.cfg-input:focus{outline:none;border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.15);}
.cfg-btn{background:#7c3aed;color:#fff;border:none;border-radius:8px;padding:9px 22px;cursor:pointer;font-weight:700;font-size:.9rem;display:inline-flex;align-items:center;gap:6px;}
.cfg-btn:hover{background:#6d28d9;}
.cfg-btn-danger{background:#ef4444;}
.cfg-btn-danger:hover{background:#dc2626;}
.cfg-btn-gray{background:#e5e7eb;color:#374151;}
.cfg-btn-gray:hover{background:#d1d5db;}
</style>

<div style="max-width:760px;padding-bottom:2rem;">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:8px;">
    <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0;">⚙️ System Settings — 7J English Center</h2>
</div>

<?php if ($alertText): ?>
<div style="background:<?= $alertType==='success'?'#d1fae5':'#fee2e2' ?>;border-radius:8px;padding:10px 16px;margin-bottom:1.25rem;color:<?= $alertType==='success'?'#065f46':'#991b1b' ?>;font-weight:600;">
    <?= htmlspecialchars($alertText) ?>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save">

    <!-- LINE ID -->
    <div class="cfg-card">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:.5rem;">
            <span style="font-size:1.2rem;">💬</span>
            <span style="font-weight:700;font-size:1rem;color:#1f2937;">LINE ID</span>
        </div>
        <p style="font-size:.8rem;color:#6b7280;margin:0 0 .75rem;">LINE ID สำหรับติดต่อแอดมิน จะแสดงให้นักเรียนและครูเห็น</p>
        <label class="cfg-label">LINE ID</label>
        <input class="cfg-input" type="text" name="line_id"
               value="<?= htmlspecialchars($cfg['line_id']) ?>"
               placeholder="เช่น @116kxilb">
    </div>

    <!-- QR Code -->
    <div class="cfg-card">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:.5rem;">
            <span style="font-size:1.2rem;">📷</span>
            <span style="font-weight:700;font-size:1rem;color:#1f2937;">QR Code ชำระเงิน</span>
        </div>
        <p style="font-size:.8rem;color:#6b7280;margin:0 0 1rem;">อัปโหลดรูป QR Code สำหรับโอนเงิน (สูงสุด 600 KB)</p>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:1rem;">
            <label class="cfg-btn" style="cursor:pointer;">
                📤 อัปโหลดรูป
                <input type="file" name="qr_file" accept="image/*" style="display:none;"
                       onchange="previewQR(this)">
            </label>
            <?php if ($cfg['qr_code_image']): ?>
            <button type="submit" name="remove_qr" value="1" class="cfg-btn cfg-btn-danger"
                    onclick="return confirm('ลบรูป QR Code?')">✕ ลบรูป</button>
            <?php endif; ?>
        </div>

        <label class="cfg-label">หรือวาง URL ของรูป QR Code</label>
        <input class="cfg-input" type="text" name="qr_url" id="qrUrlInput"
               value="<?= $cfg['qr_code_image'] && !str_starts_with($cfg['qr_code_image'],'data:') ? htmlspecialchars($cfg['qr_code_image']) : '' ?>"
               placeholder="https://...">

        <?php if ($cfg['qr_code_image']): ?>
        <div id="qrPreview" style="margin-top:1rem;border:1px solid #e5e7eb;border-radius:10px;padding:1rem;background:#f9fafb;text-align:center;">
            <p style="font-size:.75rem;color:#9ca3af;margin:0 0 .5rem;">ตัวอย่าง:</p>
            <img id="qrImg" src="<?= htmlspecialchars($cfg['qr_code_image']) ?>" alt="QR Code"
                 style="max-width:220px;border-radius:8px;"
                 onerror="this.style.display='none'">
        </div>
        <?php else: ?>
        <div id="qrPreview" style="display:none;margin-top:1rem;border:1px solid #e5e7eb;border-radius:10px;padding:1rem;background:#f9fafb;text-align:center;">
            <p style="font-size:.75rem;color:#9ca3af;margin:0 0 .5rem;">ตัวอย่าง:</p>
            <img id="qrImg" src="" alt="QR Code" style="max-width:220px;border-radius:8px;">
        </div>
        <?php endif; ?>
    </div>

    <!-- Financial Google Sheet URL -->
    <div class="cfg-card">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:.5rem;">
            <span style="font-size:1.2rem;">📊</span>
            <span style="font-weight:700;font-size:1rem;color:#1f2937;">Financial Google Sheet</span>
        </div>
        <p style="font-size:.8rem;color:#6b7280;margin:0 0 .75rem;">URL ของ Google Sheet ที่ใช้บันทึกข้อมูลทางการเงิน</p>
        <label class="cfg-label">URL</label>
        <input class="cfg-input" type="text" name="financial_url"
               value="<?= htmlspecialchars($cfg['financial_sheet_url']) ?>"
               placeholder="https://docs.google.com/spreadsheets/d/...">
        <?php if ($cfg['financial_sheet_url']): ?>
        <a href="<?= htmlspecialchars($cfg['financial_sheet_url']) ?>" target="_blank"
           style="display:inline-block;margin-top:8px;font-size:.8rem;color:#7c3aed;">
            🔗 เปิด Google Sheet ↗
        </a>
        <?php endif; ?>
    </div>

    <!-- Admin Note -->
    <div class="cfg-card">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:.5rem;">
            <span style="font-size:1.2rem;">📝</span>
            <span style="font-weight:700;font-size:1rem;color:#1f2937;">กฎและข้อปฏิบัติ (Admin Note)</span>
        </div>
        <p style="font-size:.8rem;color:#6b7280;margin:0 0 .75rem;">ข้อความที่แสดงให้นักเรียนและครูอ่านบนหน้า Dashboard</p>
        <label class="cfg-label">เนื้อหา</label>
        <textarea class="cfg-input" name="admin_note" rows="14"
                  style="resize:vertical;font-size:.82rem;line-height:1.6;"><?= htmlspecialchars($cfg['admin_note']) ?></textarea>
    </div>

    <!-- Save button -->
    <div style="display:flex;justify-content:flex-end;margin-top:.5rem;">
        <button type="submit" class="cfg-btn">💾 บันทึกการตั้งค่า</button>
    </div>
</form>
</div>

<script>
function previewQR(input) {
    if (!input.files || !input.files[0]) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('qrImg').src = e.target.result;
        document.getElementById('qrPreview').style.display = 'block';
        document.getElementById('qrUrlInput').value = '';
    };
    reader.readAsDataURL(input.files[0]);
}
// Preview QR from URL input
document.getElementById('qrUrlInput').addEventListener('input', function() {
    var v = this.value.trim();
    if (v) {
        document.getElementById('qrImg').src = v;
        document.getElementById('qrPreview').style.display = 'block';
    }
});
</script>
