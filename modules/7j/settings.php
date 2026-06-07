<?php
/*
 * 7J English Center — ตั้งค่าระบบ / System Settings
 * จัดการ Admin Login (เพิ่ม/ลบ/เปลี่ยน password) + เปลี่ยน password เมนูนี้
 */

// ─── Settings Login Guard ─────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

$settingsError = '';
$settingsMsg   = '';

// โหลด hash จาก DB
try {
    $cfgRow = $connection2->query("SELECT settings_pass_hash, settings_pass_salt FROM sevenj_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $cfgRow = []; }

$storedHash = $cfgRow['settings_pass_hash'] ?? '';
$storedSalt = $cfgRow['settings_pass_salt'] ?? '';

// ยังไม่มี password ใน DB → ตั้งค่าเริ่มต้น
if (empty($storedHash)) {
    $storedSalt = '7J_Settings_2026';
    $storedHash = hash('sha256', $storedSalt . 'ATAL190314');
    try {
        $connection2->prepare("UPDATE sevenj_config SET settings_pass_hash=?, settings_pass_salt=? WHERE id=1")
            ->execute([$storedHash, $storedSalt]);
    } catch (Exception $e) {}
}

function settingsVerify(string $input, string $storedHash, string $storedSalt): bool {
    return hash_equals($storedHash, hash('sha256', $storedSalt . $input));
}

// Build base URL dynamically (avoids hardcoded /MyNewShool/ path)
$_stProto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_stBase  = $_stProto . '://' . $_SERVER['HTTP_HOST']
          . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$_stSelf  = $_stBase . '/?q=/modules/7j/settings.php';

// Logout
if (($_POST['_settings_action'] ?? '') === 'logout') {
    unset($_SESSION['7j_settings_auth']);
    header('Location: ' . $_stSelf);
    exit;
}

// Login
if (($_POST['_settings_action'] ?? '') === 'login') {
    $inputPass = trim($_POST['_settings_pass'] ?? '');
    if (settingsVerify($inputPass, $storedHash, $storedSalt)) {
        $_SESSION['7j_settings_auth'] = true;
        header('Location: ' . $_stSelf);
        exit;
    } else {
        $settingsError = 'รหัสผ่านไม่ถูกต้อง';
    }
}

$settingsAuthed = !empty($_SESSION['7j_settings_auth']);
// ─────────────────────────────────────────────────────────────────────────────

if ($settingsAuthed) {

    // ─── เพิ่ม Admin ──────────────────────────────────────────────────────────
    if (($_POST['_settings_action'] ?? '') === 'add_admin') {
        $newEmail    = trim($_POST['new_email']    ?? '');
        $newName     = trim($_POST['new_name']     ?? '');
        $newPass     = trim($_POST['new_password'] ?? '');

        if (!$newEmail || !$newName || !$newPass) {
            $settingsError = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
        } elseif (!preg_match('/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/', $newEmail)) {
            $settingsError = 'รูปแบบ Email ไม่ถูกต้อง';
        } else {
            try {
                // ตรวจสอบว่า username/email ซ้ำไหม
                $chk = $connection2->prepare("SELECT COUNT(*) FROM gibbonPerson WHERE username=? OR email=?");
                $chk->execute([$newEmail, $newEmail]);
                if ($chk->fetchColumn() > 0) {
                    $settingsError = 'Email นี้มีอยู่ในระบบแล้ว';
                } else {
                    // หา ID ถัดไป
                    $maxID = (int)$connection2->query("SELECT MAX(gibbonPersonID) FROM gibbonPerson")->fetchColumn();
                    $nextID = str_pad($maxID + 1, 10, '0', STR_PAD_LEFT);

                    $salt = '7J_' . preg_replace('/[^a-zA-Z0-9]/', '', $newName) . '_' . time();
                    $pHash = hash('sha256', $salt . $newPass);

                    $stmt = $connection2->prepare(
                        "INSERT INTO gibbonPerson
                         (gibbonPersonID,title,surname,firstName,preferredName,officialName,
                          nameInCharacters,gender,username,passwordStrong,passwordStrongSalt,
                          passwordForceReset,status,canLogin,gibbonRoleIDPrimary,gibbonRoleIDAll,email)
                         VALUES (?,'',' ',?,?,?,'','Unspecified',?,?,?,'N','Full','Y','001','001',?)"
                    );
                    $stmt->execute([$nextID, $newName, $newName, $newName, $newEmail, $pHash, $salt, $newEmail]);
                    $settingsMsg = "✅ เพิ่ม Admin <strong>" . htmlspecialchars($newName) . "</strong> (<em>" . htmlspecialchars($newEmail) . "</em>) สำเร็จ";
                }
            } catch (Exception $e) {
                $settingsError = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            }
        }
    }

    // ─── ลบ Admin ─────────────────────────────────────────────────────────────
    if (($_POST['_settings_action'] ?? '') === 'delete_admin') {
        $delID = trim($_POST['del_person_id'] ?? '');
        if (!preg_match('/^\d+$/', $delID)) {
            $settingsError = 'ID ไม่ถูกต้อง';
        } else {
            try {
                // ตรวจว่าเป็น @7J.com admin เท่านั้น — ป้องกันลบ system admin
                $chk = $connection2->prepare(
                    "SELECT gibbonPersonID, preferredName, email FROM gibbonPerson
                     WHERE gibbonPersonID=? AND email LIKE '%@7J.com' AND gibbonRoleIDPrimary='001'"
                );
                $chk->execute([$delID]);
                $row = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $settingsError = 'ไม่พบ Admin หรือไม่มีสิทธิ์ลบ';
                } else {
                    $connection2->prepare("DELETE FROM gibbonPerson WHERE gibbonPersonID=?")->execute([$delID]);
                    $settingsMsg = "🗑️ ลบ Admin <strong>" . htmlspecialchars($row['preferredName']) . "</strong> สำเร็จ";
                }
            } catch (Exception $e) {
                $settingsError = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            }
        }
    }

    // ─── เปลี่ยน Password ของ Admin ──────────────────────────────────────────
    if (($_POST['_settings_action'] ?? '') === 'change_admin_pass') {
        $changeID   = trim($_POST['change_person_id'] ?? '');
        $newAdminPw = trim($_POST['change_new_pass']  ?? '');

        if (!preg_match('/^\d+$/', $changeID) || strlen($newAdminPw) < 3) {
            $settingsError = 'ID หรือ password ไม่ถูกต้อง (อย่างน้อย 3 ตัวอักษร)';
        } else {
            try {
                $chk = $connection2->prepare(
                    "SELECT preferredName, email FROM gibbonPerson
                     WHERE gibbonPersonID=? AND email LIKE '%@7J.com' AND gibbonRoleIDPrimary='001'"
                );
                $chk->execute([$changeID]);
                $row = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $settingsError = 'ไม่พบ Admin หรือไม่มีสิทธิ์แก้ไข';
                } else {
                    $salt  = '7J_' . preg_replace('/[^a-zA-Z0-9]/', '', $row['preferredName']) . '_' . time();
                    $pHash = hash('sha256', $salt . $newAdminPw);
                    $connection2->prepare(
                        "UPDATE gibbonPerson SET passwordStrong=?, passwordStrongSalt=? WHERE gibbonPersonID=?"
                    )->execute([$pHash, $salt, $changeID]);
                    $settingsMsg = "🔑 เปลี่ยน password ของ <strong>" . htmlspecialchars($row['preferredName']) . "</strong> สำเร็จ";
                }
            } catch (Exception $e) {
                $settingsError = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            }
        }
    }

    // ─── เปลี่ยน Password เมนูตั้งค่า ────────────────────────────────────────
    if (($_POST['_settings_action'] ?? '') === 'change_settings_pass') {
        $curPass = trim($_POST['cur_pass'] ?? '');
        $newPw1  = trim($_POST['new_pass1'] ?? '');
        $newPw2  = trim($_POST['new_pass2'] ?? '');

        if (!settingsVerify($curPass, $storedHash, $storedSalt)) {
            $settingsError = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
        } elseif (strlen($newPw1) < 4) {
            $settingsError = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 4 ตัวอักษร';
        } elseif ($newPw1 !== $newPw2) {
            $settingsError = 'รหัสผ่านใหม่ทั้ง 2 ช่องไม่ตรงกัน';
        } else {
            $newSalt = '7J_Settings_' . time();
            $newHash = hash('sha256', $newSalt . $newPw1);
            try {
                $connection2->prepare("UPDATE sevenj_config SET settings_pass_hash=?, settings_pass_salt=? WHERE id=1")
                    ->execute([$newHash, $newSalt]);
                $storedHash = $newHash;
                $storedSalt = $newSalt;
                $settingsMsg = '🔑 เปลี่ยนรหัสผ่านเมนูตั้งค่าสำเร็จ';
            } catch (Exception $e) {
                $settingsError = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            }
        }
    }

    // ─── ดึงรายชื่อ @7J.com admins ───────────────────────────────────────────
    try {
        $adminList = $connection2->query(
            "SELECT gibbonPersonID, preferredName, email
             FROM gibbonPerson
             WHERE email LIKE '%@7J.com' AND gibbonRoleIDPrimary='001'
             ORDER BY preferredName ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $adminList = []; }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ตั้งค่าระบบ — 7J English Center</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
body{font-family:'Noto Sans Thai','Sarabun',sans-serif;background:#f8f4ee;}
.card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.08);}
.btn-orange{background:#e07b28;color:#fff;border-radius:8px;padding:8px 22px;font-weight:700;cursor:pointer;border:none;}
.btn-orange:hover{background:#c96a1a;}
.btn-red{background:#dc2626;color:#fff;border-radius:8px;padding:5px 14px;font-size:.82rem;font-weight:700;cursor:pointer;border:none;}
.btn-red:hover{background:#b91c1c;}
.btn-blue{background:#2563eb;color:#fff;border-radius:8px;padding:5px 14px;font-size:.82rem;font-weight:700;cursor:pointer;border:none;}
.btn-blue:hover{background:#1d4ed8;}
input[type=text],input[type=email],input[type=password]{border:1px solid #d1d5db;border-radius:8px;padding:8px 12px;width:100%;font-size:.93rem;}
input:focus{outline:2px solid #e07b28;border-color:#e07b28;}
.table-admin th{background:#fef3e2;color:#92400e;font-weight:700;padding:10px 14px;text-align:left;font-size:.85rem;}
.table-admin td{padding:9px 14px;border-bottom:1px solid #f3f4f6;font-size:.9rem;vertical-align:middle;}
.table-admin tr:hover td{background:#fffbf5;}
</style>
</head>
<body>

<?php if (!$settingsAuthed): ?>
<!-- ══════════ LOGIN SCREEN ══════════ -->
<div class="min-h-screen flex items-center justify-center">
  <div class="card p-8 w-full max-w-sm">
    <div class="text-center mb-6">
      <div style="font-size:2.2rem;">⚙️</div>
      <h1 class="text-xl font-bold text-gray-800 mt-2">ตั้งค่าระบบ</h1>
      <p class="text-sm text-gray-500 mt-1">7J English Center — Admin Settings</p>
    </div>
    <?php if ($settingsError): ?>
      <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm mb-4">⚠️ <?= htmlspecialchars($settingsError) ?></div>
    <?php endif; ?>
    <form method="POST" action="<?= htmlspecialchars($_stSelf) ?>">
      <input type="hidden" name="_settings_action" value="login">
      <label class="block text-sm font-semibold text-gray-700 mb-1">รหัสผ่านเข้าเมนู</label>
      <input type="password" name="_settings_pass" placeholder="กรอกรหัสผ่าน" autofocus class="mb-4">
      <button type="submit" class="btn-orange w-full">🔓 เข้าสู่ระบบ</button>
    </form>
    <div class="text-center mt-4">
      <a href="/MyNewShool/" class="text-sm text-gray-400 hover:text-gray-600">← กลับหน้าหลัก</a>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ══════════ MAIN SETTINGS ══════════ -->
<div class="max-w-4xl mx-auto px-4 py-8">

  <!-- Header -->
  <div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-3">
      <span style="font-size:2rem;">⚙️</span>
      <div>
        <h1 class="text-2xl font-bold text-gray-800">ตั้งค่าระบบ</h1>
        <p class="text-sm text-gray-500">7J English Center — Admin Management</p>
      </div>
    </div>
    <form method="POST" action="<?= htmlspecialchars($_stSelf) ?>" class="inline">
      <input type="hidden" name="_settings_action" value="logout">
      <button type="submit" class="text-sm text-gray-500 hover:text-red-600 border border-gray-300 rounded-lg px-4 py-2">🚪 ออกจากระบบ</button>
    </form>
  </div>

  <!-- Alert -->
  <?php if ($settingsMsg): ?>
    <div class="bg-green-50 border border-green-300 text-green-800 rounded-lg px-4 py-3 text-sm mb-4"><?= $settingsMsg ?></div>
  <?php endif; ?>
  <?php if ($settingsError): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm mb-4">⚠️ <?= htmlspecialchars($settingsError) ?></div>
  <?php endif; ?>

  <!-- ══ รายชื่อ Admin ══ -->
  <div class="card p-6 mb-6">
    <h2 class="text-lg font-bold text-gray-800 mb-4">👤 รายชื่อ Admin Login (@7J.com)</h2>
    <div class="overflow-x-auto">
      <table class="table-admin w-full border-collapse">
        <thead>
          <tr>
            <th>#</th>
            <th>ชื่อ</th>
            <th>Email / Username</th>
            <th>เปลี่ยน Password</th>
            <th>ลบ</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($adminList)): ?>
          <tr><td colspan="5" class="text-center text-gray-400 py-6">ยังไม่มี Admin</td></tr>
        <?php else: ?>
          <?php foreach ($adminList as $idx => $adm): ?>
          <tr>
            <td class="text-gray-400 text-sm"><?= $idx+1 ?></td>
            <td class="font-semibold text-gray-800"><?= htmlspecialchars($adm['preferredName']) ?></td>
            <td class="text-blue-700"><?= htmlspecialchars($adm['email']) ?></td>
            <td>
              <!-- inline form เปลี่ยน password -->
              <form method="POST" class="flex gap-2 items-center" onsubmit="return confirm('เปลี่ยน password ของ <?= htmlspecialchars($adm['preferredName']) ?> ?')">
                <input type="hidden" name="_settings_action" value="change_admin_pass">
                <input type="hidden" name="change_person_id" value="<?= (int)$adm['gibbonPersonID'] ?>">
                <input type="password" name="change_new_pass" placeholder="password ใหม่" style="width:140px;padding:5px 10px;">
                <button type="submit" class="btn-blue">🔑 เปลี่ยน</button>
              </form>
            </td>
            <td>
              <form method="POST" onsubmit="return confirm('ลบ Admin <?= htmlspecialchars($adm['preferredName']) ?> ออกจากระบบ?')">
                <input type="hidden" name="_settings_action" value="delete_admin">
                <input type="hidden" name="del_person_id" value="<?= (int)$adm['gibbonPersonID'] ?>">
                <button type="submit" class="btn-red">🗑️ ลบ</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ══ เพิ่ม Admin ══ -->
  <div class="card p-6 mb-6">
    <h2 class="text-lg font-bold text-gray-800 mb-4">➕ เพิ่ม Admin ใหม่</h2>
    <form method="POST">
      <input type="hidden" name="_settings_action" value="add_admin">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">ชื่อ (แสดงในระบบ)</label>
          <input type="text" name="new_name" placeholder="เช่น Som" required>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Email (ใช้ Login)</label>
          <input type="email" name="new_email" placeholder="เช่น Som@7J.com" required>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Password</label>
          <input type="text" name="new_password" placeholder="เช่น Som999" required>
        </div>
      </div>
      <div class="mt-4">
        <button type="submit" class="btn-orange">➕ เพิ่ม Admin</button>
      </div>
    </form>
  </div>

  <!-- ══ เปลี่ยน Password เมนูตั้งค่า ══ -->
  <div class="card p-6">
    <h2 class="text-lg font-bold text-gray-800 mb-4">🔐 เปลี่ยน Password เมนูตั้งค่าระบบ</h2>
    <form method="POST">
      <input type="hidden" name="_settings_action" value="change_settings_pass">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">รหัสผ่านปัจจุบัน</label>
          <input type="password" name="cur_pass" placeholder="รหัสผ่านปัจจุบัน" required>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">รหัสผ่านใหม่</label>
          <input type="password" name="new_pass1" placeholder="รหัสผ่านใหม่" required>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">ยืนยันรหัสผ่านใหม่</label>
          <input type="password" name="new_pass2" placeholder="ยืนยันอีกครั้ง" required>
        </div>
      </div>
      <div class="mt-4">
        <button type="submit" class="btn-orange">🔐 เปลี่ยน Password</button>
      </div>
    </form>
  </div>

</div>
<?php endif; ?>

</body>
</html>
