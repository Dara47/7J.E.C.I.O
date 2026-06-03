<?php
require_once __DIR__ . '/_auth.php';

// Already logged in → go to dashboard
if (!empty($_SESSION['student_id'])) {
    header('Location: ' . ABSOLUTE_URL . '/portal/student_dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'กรุณากรอก Username และ Password';
    } else {
        $pdo = portal_db();
        $stmt = $pdo->prepare(
            'SELECT id, username, password_hash, displayName, status
             FROM sevenj_students
             WHERE username = ? OR studentCode = ?
             LIMIT 1'
        );
        $stmt->execute([$username, $username]);
        $student = $stmt->fetch();

        if (!$student || !password_verify($password, $student['password_hash'] ?? '')) {
            $error = 'Username หรือ Password ไม่ถูกต้อง';
        } elseif ($student['status'] !== 'active') {
            $error = 'บัญชีของคุณยังไม่ได้เปิดใช้งาน กรุณาติดต่อแอดมิน';
        } else {
            $_SESSION['student_id']       = $student['id'];
            $_SESSION['student_name']     = $student['displayName'];
            $_SESSION['student_username'] = $student['username'];
            // บันทึก login log
            $pdo2  = portal_db();
            $sCode = $pdo2->prepare('SELECT studentCode FROM sevenj_students WHERE id=? LIMIT 1');
            $sCode->execute([$student['id']]);
            $codeRow = $sCode->fetch();
            log_portal_login($student['id'], $student['displayName'], $codeRow['studentCode'] ?? '', 'student');
            header('Location: ' . ABSOLUTE_URL . '/portal/student_dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>7J English Center — Student Portal</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { font-family: 'Sarabun', 'Segoe UI', sans-serif; }
    .brand-gradient { background: linear-gradient(135deg, #92400e 0%, #c27016 50%, #d97706 100%); }
  </style>
</head>
<body class="min-h-screen brand-gradient flex items-center justify-center px-4">

  <!-- ปุ่มกลับหน้าหลัก -->
  <a href="<?= ABSOLUTE_URL ?>"
     class="fixed top-4 left-4 flex items-center gap-1.5 bg-white bg-opacity-20 hover:bg-opacity-30
            text-white text-sm font-medium px-3 py-2 rounded-lg transition">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
    </svg>
    หน้าหลัก
  </a>

  <div class="w-full max-w-md">
    <!-- Logo / Header -->
    <div class="text-center mb-8">
      <img src="<?= ABSOLUTE_URL ?>/uploads/logo_7j.png"
           alt="7J English Center"
           class="mx-auto h-20 w-20 object-contain mb-3"
           onerror="this.style.display='none'">
      <h1 class="text-white text-2xl font-bold tracking-wide">7J English Center</h1>
      <p class="text-amber-200 text-sm mt-1">Student Portal — เข้าสู่ระบบนักเรียน</p>
    </div>

    <!-- Card -->
    <div class="bg-white rounded-2xl shadow-2xl overflow-hidden">
      <!-- Card header bar -->
      <div class="brand-gradient py-4 px-6">
        <h2 class="text-white font-semibold text-lg text-center">
          <svg class="inline w-5 h-5 mr-1 -mt-0.5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/>
          </svg>
          เข้าสู่ระบบ
        </h2>
      </div>

      <form method="POST" class="p-8 space-y-5">
        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm flex items-start gap-2">
          <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
          </svg>
          <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
          <input
            type="text"
            name="username"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
            required
            autofocus
            class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                   focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent
                   transition"
            placeholder="กรอก username ของคุณ">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
          <input
            type="password"
            name="password"
            required
            class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm
                   focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent
                   transition"
            placeholder="กรอก password">
        </div>

        <button
          type="submit"
          class="w-full brand-gradient text-white font-semibold py-2.5 rounded-lg
                 hover:opacity-90 active:opacity-80 transition text-sm tracking-wide">
          เข้าสู่ระบบ
        </button>
      </form>

      <div class="border-t border-gray-100 px-8 py-4 text-center text-xs text-gray-400">
        ลืม password? ติดต่อครูหรือแอดมิน
      </div>
    </div>

    <p class="text-center text-amber-200 text-xs mt-6">
      © 7J English Center — Global Language Institute
    </p>
    <p class="text-center text-amber-300 text-xs mt-1 opacity-70">Version 3.0.0</p>
  </div>

</body>
</html>
