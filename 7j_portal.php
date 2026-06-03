<?php
/**
 * 7J English Center — Student / Teacher Portal Login
 * Username = รหัส (S260001 / T260001)  Password = รหัสเดียวกัน
 */
session_start();

// ถ้า login แล้วให้ไปที่ dashboard
if (!empty($_SESSION['7j_user_id'])) {
    header('Location: /MyNewShool/7j_portal_dashboard.php');
    exit;
}

require_once __DIR__ . '/config.php';
$pdo = new PDO(
    "mysql:host={$databaseServer};dbname={$databaseName};charset=utf8mb4",
    $databaseUsername, $databasePassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username && $password) {
        // ลอง login เป็นนักเรียนก่อน
        $stmt = $pdo->prepare("SELECT id, studentCode, displayName, password_hash, status FROM sevenj_students WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        if ($row && $row['status'] === 'active' && password_verify($password, $row['password_hash'])) {
            $_SESSION['7j_user_id']   = $row['id'];
            $_SESSION['7j_user_code'] = $row['studentCode'];
            $_SESSION['7j_user_name'] = $row['displayName'];
            $_SESSION['7j_user_type'] = 'student';
            header('Location: /MyNewShool/7j_portal_dashboard.php');
            exit;
        }

        // ลอง login เป็นครู
        $stmt2 = $pdo->prepare("SELECT id, teacherCode, displayName, password_hash, status FROM sevenj_teachers WHERE username = ? LIMIT 1");
        $stmt2->execute([$username]);
        $row2 = $stmt2->fetch();

        if ($row2 && $row2['status'] === 'active' && password_verify($password, $row2['password_hash'])) {
            $_SESSION['7j_user_id']   = $row2['id'];
            $_SESSION['7j_user_code'] = $row2['teacherCode'];
            $_SESSION['7j_user_name'] = $row2['displayName'];
            $_SESSION['7j_user_type'] = 'teacher';
            header('Location: /MyNewShool/7j_portal_dashboard.php');
            exit;
        }

        $error = 'รหัสผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
    } else {
        $error = 'กรุณากรอกรหัสผู้ใช้และรหัสผ่าน';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>7J English Center — เข้าสู่ระบบ</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, #1A2A5E 0%, #E8640A 100%);
    font-family: 'Segoe UI', Tahoma, sans-serif;
    padding: 20px;
}
.login-card {
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
    width: 100%; max-width: 400px;
    overflow: hidden;
}
.login-header {
    background: linear-gradient(135deg, #1A2A5E, #E8640A);
    padding: 36px 32px 28px;
    text-align: center; color: #fff;
}
.login-logo {
    width: 72px; height: 72px; border-radius: 50%;
    background: rgba(255,255,255,.18);
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem; margin: 0 auto 14px;
}
.login-header h1 { font-size: 1.35rem; font-weight: 800; letter-spacing: .02em; }
.login-header p  { font-size: .82rem; opacity: .8; margin-top: 4px; }
.login-body { padding: 28px 32px 32px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: .8rem; font-weight: 700; color: #374151; margin-bottom: 6px; }
.form-group input {
    width: 100%; padding: 10px 14px; font-size: .92rem;
    border: 1.5px solid #d1d5db; border-radius: 8px; outline: none;
    transition: border-color .15s, box-shadow .15s;
}
.form-group input:focus {
    border-color: #E8640A;
    box-shadow: 0 0 0 3px rgba(232,100,10,.12);
}
.btn-login {
    width: 100%; padding: 12px; border: none; border-radius: 8px;
    background: linear-gradient(135deg, #1A2A5E, #E8640A);
    color: #fff; font-size: 1rem; font-weight: 700;
    cursor: pointer; transition: opacity .15s, box-shadow .15s;
    margin-top: 8px;
}
.btn-login:hover { opacity: .9; box-shadow: 0 4px 16px rgba(232,100,10,.35); }
.error-box {
    background: #fee2e2; border: 1px solid #fca5a5;
    color: #991b1b; border-radius: 8px;
    padding: 10px 14px; font-size: .84rem; font-weight: 600;
    margin-bottom: 16px; display: flex; align-items: center; gap: 8px;
}
.hint {
    margin-top: 18px; text-align: center;
    font-size: .78rem; color: #9ca3af;
    border-top: 1px solid #f3f4f6; padding-top: 14px;
}
.hint span { color: #E8640A; font-weight: 700; }
</style>
</head>
<body>
<div class="login-card">
    <div class="login-header">
        <div style="text-align:left;margin-bottom:12px;">
            <a href="javascript:history.back()"
               style="color:rgba(255,255,255,.8);font-size:.8rem;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                ← ย้อนกลับ
            </a>
        </div>
        <div class="login-logo">🎓</div>
        <h1>7J English Center</h1>
        <p>พอร์ทัลนักเรียนและครู</p>
    </div>
    <div class="login-body">
        <?php if ($error): ?>
        <div class="error-box">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>รหัสผู้ใช้</label>
                <input type="text" name="username" placeholder="เช่น S260001 หรือ T260001"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       autocomplete="username" autofocus>
            </div>
            <div class="form-group">
                <label>รหัสผ่าน</label>
                <input type="password" name="password" placeholder="รหัสผ่าน"
                       autocomplete="current-password">
            </div>
            <button type="submit" class="btn-login">เข้าสู่ระบบ</button>
        </form>
        <div class="hint">
            รหัสผ่านเริ่มต้น = <span>รหัสนักเรียน/ครู</span><br>
            เช่น S260001 → รหัสผ่าน S260001
        </div>
    </div>
</div>
</body>
</html>
