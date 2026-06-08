<?php
/*
 * 7J English Center — เมนูบัญชี / Payroll
 * ครู: จำนวนคาบ × ค่าจ้าง = ผลรวม
 * แอดมิน: หลายหน้าที่ × จำนวน × ค่าจ้าง/หน้าที่ = ผลรวม
 */

// ─── Payroll Login Guard ──────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

define('PAYROLL_PASS_HASH', hash('sha256', 'ATAL190314'));
$payrollLoginError = '';

// Build base URL dynamically (avoids hardcoded /MyNewShool/ path)
$_pyProto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_pyBase  = $_pyProto . '://' . $_SERVER['HTTP_HOST']
          . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$_pySelf  = $_pyBase . '/?q=/modules/7j/payroll.php';

if (($_POST['_payroll_action'] ?? '') === 'logout') {
    unset($_SESSION['7j_payroll_auth']);
    header('Location: ' . $_pySelf);
    exit;
}
if (($_POST['_payroll_action'] ?? '') === 'login') {
    if (hash_equals(PAYROLL_PASS_HASH, hash('sha256', trim($_POST['_payroll_pass'] ?? '')))) {
        $_SESSION['7j_payroll_auth'] = true;
        header('Location: ' . $_pySelf);
        exit;
    } else {
        $payrollLoginError = 'รหัสผ่านไม่ถูกต้อง';
    }
}
$payrollAuthed = !empty($_SESSION['7j_payroll_auth']);
// ─────────────────────────────────────────────────────────────────────────────

// ─── สร้างตารางถ้ายังไม่มี ────────────────────────────────────────────────────
try {
    $connection2->query("CREATE TABLE IF NOT EXISTS sevenj_payroll (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id      VARCHAR(120),
        teacher_name    VARCHAR(200) NOT NULL,
        sessions_count  INT DEFAULT 0,
        pay_rate        DECIMAL(10,2) DEFAULT 0,
        roles_json      TEXT,
        total_amount    DECIMAL(10,2) DEFAULT 0,
        date_from       DATE,
        date_to         DATE,
        week_label      VARCHAR(200),
        note            TEXT,
        status          ENUM('pending','paid') DEFAULT 'pending',
        paid_at         DATETIME,
        exported_at     DATETIME,
        created_at      DATETIME DEFAULT NOW()
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}
// เพิ่มคอลัมน์ใหม่ถ้ายังไม่มี (backward compat)
foreach (['roles_json TEXT', 'total_amount DECIMAL(10,2) DEFAULT 0'] as $col) {
    try { $connection2->query("ALTER TABLE sevenj_payroll ADD COLUMN $col"); } catch (Exception $e) {}
}

try {
    $connection2->query("CREATE TABLE IF NOT EXISTS sevenj_teacher_rate_templates (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id   VARCHAR(120),
        teacher_name VARCHAR(200) NOT NULL UNIQUE,
        rates_json   TEXT,
        updated_at   DATETIME DEFAULT NOW()
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

try {
    $connection2->query("CREATE TABLE IF NOT EXISTS sevenj_admin_rate_templates (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        admin_name   VARCHAR(200) NOT NULL UNIQUE,
        rates_json   TEXT,
        updated_at   DATETIME DEFAULT NOW()
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

try {
    $connection2->query("CREATE TABLE IF NOT EXISTS sevenj_admin_payroll (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        admin_name      VARCHAR(200) NOT NULL,
        roles_json      TEXT,
        total_amount    DECIMAL(10,2) DEFAULT 0,
        date_from       DATE,
        date_to         DATE,
        week_label      VARCHAR(200),
        note            TEXT,
        status          ENUM('pending','paid') DEFAULT 'pending',
        paid_at         DATETIME,
        created_at      DATETIME DEFAULT NOW()
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ─── POST handlers ────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';
$msg    = '';

if ($action === 'add_entry') {
    $tid    = trim($_POST['teacher_id']    ?? '');
    $tname  = trim($_POST['teacher_name']  ?? '');
    $rnames = (array)($_POST['role_name']  ?? []);
    $rrates = (array)($_POST['role_rate']  ?? []);
    $rcnts  = (array)($_POST['role_count'] ?? []);
    $dfrom  = trim($_POST['date_from']     ?? '') ?: null;
    $dto    = trim($_POST['date_to']       ?? '') ?: null;
    $week   = trim($_POST['week_label']    ?? '') ?: null;
    $note   = trim($_POST['note']          ?? '') ?: null;
    $roles  = []; $total = 0; $totalSess = 0;
    for ($i = 0; $i < count($rnames); $i++) {
        $rn = trim($rnames[$i] ?? '');
        $rr = max(0, (float)($rrates[$i]  ?? 0));
        $rc = max(0, (float)($rcnts[$i]     ?? 0));
        if ($rn) { $roles[] = ['role'=>$rn,'rate'=>$rr,'count'=>$rc]; $total += $rr*$rc; $totalSess += $rc; }
    }
    if ($tname) {
        $connection2->prepare("INSERT INTO sevenj_payroll
            (teacher_id,teacher_name,sessions_count,pay_rate,roles_json,total_amount,date_from,date_to,week_label,note)
            VALUES (?,?,?,0,?,?,?,?,?,?)")
            ->execute([$tid ?: null, $tname, $totalSess,
                       json_encode($roles, JSON_UNESCAPED_UNICODE), $total,
                       $dfrom, $dto, $week, $note]);
        $msg = 'success|เพิ่มรายการครูสำเร็จ';
    } else { $msg = 'error|กรุณาระบุชื่อครู'; }

} elseif ($action === 'edit_entry') {
    $pid    = (int)($_POST['payroll_id']   ?? 0);
    $tname  = trim($_POST['teacher_name']  ?? '');
    $rnames = (array)($_POST['role_name']  ?? []);
    $rrates = (array)($_POST['role_rate']  ?? []);
    $rcnts  = (array)($_POST['role_count'] ?? []);
    $dfrom  = trim($_POST['date_from']     ?? '') ?: null;
    $dto    = trim($_POST['date_to']       ?? '') ?: null;
    $week   = trim($_POST['week_label']    ?? '') ?: null;
    $note   = trim($_POST['note']          ?? '') ?: null;
    $roles  = []; $total = 0; $totalSess = 0;
    for ($i = 0; $i < count($rnames); $i++) {
        $rn = trim($rnames[$i] ?? '');
        $rr = max(0, (float)($rrates[$i]  ?? 0));
        $rc = max(0, (float)($rcnts[$i]     ?? 0));
        if ($rn) { $roles[] = ['role'=>$rn,'rate'=>$rr,'count'=>$rc]; $total += $rr*$rc; $totalSess += $rc; }
    }
    if ($pid && $tname) {
        $connection2->prepare("UPDATE sevenj_payroll SET
            teacher_name=?, sessions_count=?, pay_rate=0,
            roles_json=?, total_amount=?, date_from=?, date_to=?, week_label=?, note=?
            WHERE id=?")
            ->execute([$tname, $totalSess,
                       json_encode($roles, JSON_UNESCAPED_UNICODE), $total,
                       $dfrom, $dto, $week, $note, $pid]);
        $msg = 'success|แก้ไขรายการสำเร็จ';
    } else { $msg = 'error|ข้อมูลไม่ครบ'; }

} elseif ($action === 'save_rate_template') {
    // AJAX — บันทึก template อัตราค่าจ้างครู
    header('Content-Type: application/json; charset=utf-8');
    $tid    = trim($_POST['teacher_id']    ?? '');
    $tname  = trim($_POST['teacher_name']  ?? '');
    $rnames = (array)($_POST['role_name']  ?? []);
    $rrates = (array)($_POST['role_rate']  ?? []);
    $rates  = [];
    for ($i = 0; $i < count($rnames); $i++) {
        $rn = trim($rnames[$i] ?? '');
        $rr = max(0, (float)($rrates[$i] ?? 0));
        if ($rn) $rates[] = ['role' => $rn, 'rate' => $rr];
    }
    if ($tname) {
        $ex = $connection2->prepare("SELECT id FROM sevenj_teacher_rate_templates WHERE teacher_name=? LIMIT 1");
        $ex->execute([$tname]);
        if ($ex->fetchColumn()) {
            $connection2->prepare("UPDATE sevenj_teacher_rate_templates SET teacher_id=?, rates_json=?, updated_at=NOW() WHERE teacher_name=?")
                ->execute([$tid ?: null, json_encode($rates, JSON_UNESCAPED_UNICODE), $tname]);
        } else {
            $connection2->prepare("INSERT INTO sevenj_teacher_rate_templates (teacher_id,teacher_name,rates_json) VALUES (?,?,?)")
                ->execute([$tid ?: null, $tname, json_encode($rates, JSON_UNESCAPED_UNICODE)]);
        }
        echo json_encode(['ok' => true, 'msg' => 'บันทึกอัตราค่าจ้างสำหรับ '.$tname.' แล้ว']);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'กรุณาระบุชื่อครู']);
    }
    exit;

} elseif ($action === 'save_admin_rate_template') {
    // AJAX — บันทึก template อัตราค่าจ้างแอดมิน
    header('Content-Type: application/json; charset=utf-8');
    $aname  = trim($_POST['admin_name']   ?? '');
    $rnames = (array)($_POST['role_name'] ?? []);
    $rrates = (array)($_POST['role_rate'] ?? []);
    $rates  = [];
    for ($i = 0; $i < count($rnames); $i++) {
        $rn = trim($rnames[$i] ?? '');
        $rr = max(0, (float)($rrates[$i] ?? 0));
        if ($rn) $rates[] = ['role' => $rn, 'rate' => $rr];
    }
    if ($aname) {
        $ex = $connection2->prepare("SELECT id FROM sevenj_admin_rate_templates WHERE admin_name=? LIMIT 1");
        $ex->execute([$aname]);
        if ($ex->fetchColumn()) {
            $connection2->prepare("UPDATE sevenj_admin_rate_templates SET rates_json=?, updated_at=NOW() WHERE admin_name=?")
                ->execute([json_encode($rates, JSON_UNESCAPED_UNICODE), $aname]);
        } else {
            $connection2->prepare("INSERT INTO sevenj_admin_rate_templates (admin_name,rates_json) VALUES (?,?)")
                ->execute([$aname, json_encode($rates, JSON_UNESCAPED_UNICODE)]);
        }
        echo json_encode(['ok' => true, 'msg' => 'บันทึกอัตราค่าจ้างสำหรับ '.$aname.' แล้ว']);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'กรุณาระบุชื่อแอดมิน']);
    }
    exit;

} elseif ($action === 'mark_paid') {
    $pid = (int)($_POST['payroll_id'] ?? 0);
    if ($pid) {
        $connection2->prepare("UPDATE sevenj_payroll SET status='paid',paid_at=NOW() WHERE id=?")->execute([$pid]);
        $msg = 'success|บันทึกสถานะจ่ายแล้ว';
    }
} elseif ($action === 'mark_unpaid') {
    $pid = (int)($_POST['payroll_id'] ?? 0);
    if ($pid) {
        $connection2->prepare("UPDATE sevenj_payroll SET status='pending',paid_at=NULL WHERE id=?")->execute([$pid]);
        $msg = 'success|ยกเลิกสถานะ';
    }
} elseif ($action === 'delete_entry') {
    $pid = (int)($_POST['payroll_id'] ?? 0);
    if ($pid) {
        $connection2->prepare("DELETE FROM sevenj_payroll WHERE id=?")->execute([$pid]);
        $msg = 'success|ลบรายการสำเร็จ';
    }

// ─── Admin Payroll POST handlers ─────────────────────────────────────────────
} elseif ($action === 'add_admin') {
    $aname  = trim($_POST['admin_name']     ?? '');
    $rnames = (array)($_POST['role_name']   ?? []);
    $rrates = (array)($_POST['role_rate']   ?? []);
    $rcnts  = (array)($_POST['role_count']  ?? []);
    $dfrom  = trim($_POST['adm_date_from']  ?? '') ?: null;
    $dto    = trim($_POST['adm_date_to']    ?? '') ?: null;
    $week   = trim($_POST['adm_week_label'] ?? '') ?: null;
    $note   = trim($_POST['adm_note']       ?? '') ?: null;
    $roles  = []; $total = 0;
    for ($i = 0; $i < count($rnames); $i++) {
        $rn = trim($rnames[$i] ?? '');
        $rr = max(0, (float)($rrates[$i] ?? 0));
        $rc = max(0, (float)($rcnts[$i]    ?? 0));
        if ($rn) { $roles[] = ['role'=>$rn,'rate'=>$rr,'count'=>$rc]; $total += $rr * $rc; }
    }
    if ($aname) {
        $connection2->prepare("INSERT INTO sevenj_admin_payroll
            (admin_name,roles_json,total_amount,date_from,date_to,week_label,note)
            VALUES (?,?,?,?,?,?,?)")
            ->execute([$aname, json_encode($roles, JSON_UNESCAPED_UNICODE), $total, $dfrom, $dto, $week, $note]);
        $msg = 'success|เพิ่มรายการแอดมินสำเร็จ';
    } else { $msg = 'error|กรุณาระบุชื่อแอดมิน'; }

} elseif ($action === 'edit_admin') {
    $pid    = (int)($_POST['admin_payroll_id'] ?? 0);
    $aname  = trim($_POST['admin_name']    ?? '');
    $rnames = (array)($_POST['role_name']  ?? []);
    $rrates = (array)($_POST['role_rate']  ?? []);
    $rcnts  = (array)($_POST['role_count'] ?? []);
    $dfrom  = trim($_POST['adm_date_from'] ?? '') ?: null;
    $dto    = trim($_POST['adm_date_to']   ?? '') ?: null;
    $week   = trim($_POST['adm_week_label']?? '') ?: null;
    $note   = trim($_POST['adm_note']      ?? '') ?: null;
    $roles  = []; $total = 0;
    for ($i = 0; $i < count($rnames); $i++) {
        $rn = trim($rnames[$i] ?? '');
        $rr = max(0, (float)($rrates[$i] ?? 0));
        $rc = max(0, (float)($rcnts[$i]    ?? 0));
        if ($rn) { $roles[] = ['role'=>$rn,'rate'=>$rr,'count'=>$rc]; $total += $rr*$rc; }
    }
    if ($pid && $aname) {
        $connection2->prepare("UPDATE sevenj_admin_payroll SET
            admin_name=?, roles_json=?, total_amount=?, date_from=?, date_to=?, week_label=?, note=?
            WHERE id=?")
            ->execute([$aname, json_encode($roles, JSON_UNESCAPED_UNICODE), $total,
                       $dfrom, $dto, $week, $note, $pid]);
        $msg = 'success|แก้ไขรายการแอดมินสำเร็จ';
    } else { $msg = 'error|ข้อมูลไม่ครบ'; }

} elseif ($action === 'mark_admin_paid') {
    $pid = (int)($_POST['admin_payroll_id'] ?? 0);
    if ($pid) {
        $connection2->prepare("UPDATE sevenj_admin_payroll SET status='paid',paid_at=NOW() WHERE id=?")->execute([$pid]);
        $msg = 'success|บันทึกสถานะจ่ายแล้ว';
    }
} elseif ($action === 'mark_admin_unpaid') {
    $pid = (int)($_POST['admin_payroll_id'] ?? 0);
    if ($pid) {
        $connection2->prepare("UPDATE sevenj_admin_payroll SET status='pending',paid_at=NULL WHERE id=?")->execute([$pid]);
        $msg = 'success|ยกเลิกสถานะ';
    }
} elseif ($action === 'delete_admin') {
    $pid = (int)($_POST['admin_payroll_id'] ?? 0);
    if ($pid) {
        $connection2->prepare("DELETE FROM sevenj_admin_payroll WHERE id=?")->execute([$pid]);
        $msg = 'success|ลบรายการสำเร็จ';
    }

} elseif ($action === 'export') {
    // handled after fetch
}

// ─── Fetch admin rate template (AJAX) ────────────────────────────────────────
if (isset($_GET['fetch_admin_rate_template'])) {
    header('Content-Type: application/json; charset=utf-8');
    $aname = trim($_GET['aname'] ?? '');
    $stmt  = $connection2->prepare("SELECT rates_json FROM sevenj_admin_rate_templates WHERE admin_name=? LIMIT 1");
    $stmt->execute([$aname]);
    $row   = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['rates' => $row ? (json_decode($row['rates_json'], true) ?: []) : []]);
    exit;
}

// ─── Fetch rate template (AJAX) ──────────────────────────────────────────────
if (isset($_GET['fetch_rate_template'])) {
    header('Content-Type: application/json; charset=utf-8');
    $tid   = trim($_GET['tid']   ?? '');
    $tname = trim($_GET['tname'] ?? '');
    $stmt  = $connection2->prepare("SELECT rates_json FROM sevenj_teacher_rate_templates
        WHERE (? != '' AND teacher_id=?) OR teacher_name=? LIMIT 1");
    $stmt->execute([$tid, $tid, $tname]);
    $row   = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['rates' => $row ? (json_decode($row['rates_json'], true) ?: []) : []]);
    exit;
}

// ─── Fetch sessions สำหรับ auto-fill modal ───────────────────────────────────
$autoSessions = null;
$autoTeacher  = null;
if (isset($_GET['fetch_sessions'])) {
    $ftid   = trim($_GET['ftid']   ?? '');
    $fdfrom = trim($_GET['fdfrom'] ?? '');
    $fdto   = trim($_GET['fdto']   ?? '');
    if ($ftid && $fdfrom && $fdto) {
        $stF = $connection2->prepare("SELECT COUNT(*) AS cnt FROM sevenj_class_completions WHERE teacher_ref_id=? AND completed_date BETWEEN ? AND ?");
        $stF->execute([$ftid, $fdfrom, $fdto]);
        $autoSessions = (int)$stF->fetchColumn();
        $stT = $connection2->prepare("SELECT displayName FROM sevenj_teachers WHERE id=?");
        $stT->execute([$ftid]);
        $autoTeacher = $stT->fetchColumn();
    }
}

// ─── Active tab ───────────────────────────────────────────────────────────────
$activeTab = ($_GET['tab'] ?? 'teacher') === 'admin' ? 'admin' : 'teacher';

// ─── Teacher filters + fetch ─────────────────────────────────────────────────
$search   = trim($_GET['search']    ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to']   ?? '');
$where  = ["exported_at IS NULL"]; $params = [];
if ($search)   { $where[] = "teacher_name LIKE ?"; $params[] = "%$search%"; }
if ($dateFrom) { $where[] = "(date_from >= ? OR date_from IS NULL)"; $params[] = $dateFrom; }
if ($dateTo)   { $where[] = "(date_to <= ? OR date_to IS NULL)"; $params[] = $dateTo; }
$whereStr = implode(' AND ', $where);
$stRec = $connection2->prepare("SELECT * FROM sevenj_payroll WHERE $whereStr ORDER BY created_at DESC");
$stRec->execute($params);
$records = $stRec->fetchAll(PDO::FETCH_ASSOC);

// ─── Admin filters + fetch ────────────────────────────────────────────────────
$admSearch   = trim($_GET['adm_search']    ?? '');
$admDateFrom = trim($_GET['adm_date_from'] ?? '');
$admDateTo   = trim($_GET['adm_date_to']   ?? '');
$admWhere  = ["1=1"]; $admParams = [];
if ($admSearch)   { $admWhere[] = "admin_name LIKE ?"; $admParams[] = "%$admSearch%"; }
if ($admDateFrom) { $admWhere[] = "(date_from >= ? OR date_from IS NULL)"; $admParams[] = $admDateFrom; }
if ($admDateTo)   { $admWhere[] = "(date_to <= ? OR date_to IS NULL)"; $admParams[] = $admDateTo; }
$admWhereStr = implode(' AND ', $admWhere);
$stAdm = $connection2->prepare("SELECT * FROM sevenj_admin_payroll WHERE $admWhereStr ORDER BY created_at DESC");
$stAdm->execute($admParams);
$adminRecords = $stAdm->fetchAll(PDO::FETCH_ASSOC);

// ─── Export ────────────────────────────────────────────────────────────────────
if ($action === 'export' && !empty($records)) {
    $fmt = trim($_POST['format'] ?? 'excel');
    $ids = array_column($records, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $connection2->prepare("UPDATE sevenj_payroll SET exported_at=NOW() WHERE id IN ($ph)")->execute($ids);
    $grandTotal = array_sum(array_map(fn($r) => $r['sessions_count'] * $r['pay_rate'], $records));
    if ($fmt === 'excel') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="payroll_'.date('Y-m-d').'.csv"');
        echo "\xEF\xBB\xBF";
        echo "ชื่อครู,ช่วงวันที่,สัปดาห์/วัน,คาบสอน,ค่าจ้าง/คาบ (฿),รวม (฿),สถานะ\n";
        foreach ($records as $r) {
            $period = ($r['date_from'] && $r['date_to']) ? $r['date_from'].' ถึง '.$r['date_to'] : '-';
            $total  = $r['sessions_count'] * $r['pay_rate'];
            $status = $r['status'] === 'paid' ? 'จ่ายแล้ว' : 'รอจ่าย';
            echo '"'.addcslashes($r['teacher_name'],'"').'",'.'"'.addcslashes($period,'"').'",'.
                 '"'.addcslashes($r['week_label'] ?? '-','"').'",'.$r['sessions_count'].','.
                 number_format($r['pay_rate'],2).','.number_format($total,2).',"'.$status.'"'."\n";
        }
        echo '"รวมทั้งหมด","","","","",'.number_format($grandTotal,2).',"'."\"\n";
        exit;
    } elseif ($fmt === 'pdf') {
        $_SESSION['7j_payroll_pdf'] = ['records' => $records, 'total' => $grandTotal, 'date' => date('d/m/Y H:i')];
        header("Location: {$_pyBase}/?q=/modules/7j/payroll.php&print=1");
        exit;
    }
}

// ─── Print mode (PDF) ─────────────────────────────────────────────────────────
if (isset($_GET['print']) && !empty($_SESSION['7j_payroll_pdf'])) {
    $pdf = $_SESSION['7j_payroll_pdf'];
    unset($_SESSION['7j_payroll_pdf']);
    ?><!DOCTYPE html>
    <html lang="th"><head><meta charset="UTF-8">
    <title>บัญชีค่าจ้างครู — 7J English Center</title>
    <style>
    *{font-family:'Sarabun',sans-serif;font-size:13px;}
    body{margin:20px;color:#000;}
    h2{text-align:center;font-size:18px;margin-bottom:4px;}
    .sub{text-align:center;color:#555;margin-bottom:16px;}
    table{width:100%;border-collapse:collapse;}
    th{background:#1A2A5E;color:#fff;padding:6px 10px;text-align:left;}
    td{padding:6px 10px;border-bottom:1px solid #ddd;}
    .total-row{font-weight:700;background:#f9f9f9;}
    .paid{color:#059669;font-weight:700;}
    .pending{color:#d97706;font-weight:700;}
    @media print{.no-print{display:none;}}
    </style></head><body>
    <h2>🏦 บัญชีค่าจ้างครู — 7J English Center</h2>
    <p class="sub">วันที่พิมพ์: <?= $pdf['date'] ?></p>
    <p class="no-print" style="text-align:center;margin-bottom:12px;">
        <button onclick="window.print()" style="padding:8px 24px;background:#1A2A5E;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px;">🖨️ พิมพ์ / บันทึก PDF</button>
    </p>
    <table><thead><tr>
        <th>#</th><th>ชื่อครู</th><th>ช่วงวันที่</th><th>สัปดาห์/วัน</th>
        <th style="text-align:center;">คาบสอน</th>
        <th style="text-align:right;">ค่าจ้าง/คาบ</th>
        <th style="text-align:right;">รวม (฿)</th><th>สถานะ</th>
    </tr></thead><tbody>
    <?php $n=1; foreach ($pdf['records'] as $r):
        $total = $r['sessions_count'] * $r['pay_rate'];
        $period = ($r['date_from'] && $r['date_to']) ? $r['date_from'].' – '.$r['date_to'] : '—';
    ?>
    <tr>
        <td><?= $n++ ?></td>
        <td><?= htmlspecialchars($r['teacher_name']) ?></td>
        <td><?= $period ?></td>
        <td><?= htmlspecialchars($r['week_label'] ?? '—') ?></td>
        <td style="text-align:center;"><?= $r['sessions_count'] ?></td>
        <td style="text-align:right;"><?= number_format($r['pay_rate'],2) ?></td>
        <td style="text-align:right;"><?= number_format($total,2) ?></td>
        <td class="<?= $r['status']==='paid'?'paid':'pending' ?>"><?= $r['status']==='paid'?'จ่ายแล้ว':'รอจ่าย' ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row">
        <td colspan="6" style="text-align:right;">รวมทั้งหมด</td>
        <td style="text-align:right;"><?= number_format($pdf['total'],2) ?> ฿</td><td></td>
    </tr>
    </tbody></table>
    <script>setTimeout(function(){window.print();},500);</script>
    </body></html><?php exit;
}

// ─── Summary calculations ─────────────────────────────────────────────────────
$teaRowTotal     = fn($r) => !empty($r['roles_json']) ? (float)$r['total_amount'] : (float)$r['sessions_count'] * (float)$r['pay_rate'];
$grandTotal      = array_sum(array_map($teaRowTotal, $records));
$totalPaid       = array_sum(array_map(fn($r) => $r['status']==='paid' ? ($teaRowTotal)($r) : 0.0, $records));
$totalPending    = $grandTotal - $totalPaid;

$admGrandTotal   = array_sum(array_column($adminRecords, 'total_amount'));
$admTotalPaid    = array_sum(array_map(fn($r) => $r['status']==='paid' ? (float)$r['total_amount'] : 0.0, $adminRecords));
$admTotalPending = $admGrandTotal - $admTotalPaid;

$teachers = $connection2->query("SELECT id,displayName,teacherCode FROM sevenj_teachers WHERE status='active' ORDER BY displayName")->fetchAll(PDO::FETCH_ASSOC);

[$alertType, $alertText] = $msg ? explode('|', $msg, 2) : ['', ''];
require_once __DIR__.'/_theme.php';

// ─── แสดง login form ถ้ายังไม่ได้ authenticate ─────────────────────────────
if (!$payrollAuthed): ?>
<style>
.pl-wrap{display:flex;align-items:center;justify-content:center;min-height:60vh;}
.pl-box{background:#fff;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.12);padding:2.5rem 2rem;width:100%;max-width:360px;text-align:center;}
.pl-icon{font-size:2.8rem;margin-bottom:.5rem;}
.pl-title{font-size:1.2rem;font-weight:700;color:#1A2A5E;margin-bottom:.25rem;}
.pl-sub{font-size:.82rem;color:#9ca3af;margin-bottom:1.5rem;}
.pl-inp{width:100%;padding:10px 14px;border:2px solid #e5e7eb;border-radius:9px;font-size:1rem;box-sizing:border-box;outline:none;text-align:center;letter-spacing:.15em;}
.pl-inp:focus{border-color:#ea580c;box-shadow:0 0 0 3px rgba(234,88,12,.1);}
.pl-btn{width:100%;padding:11px;background:#ea580c;color:#fff;border:none;border-radius:9px;font-size:1rem;font-weight:700;cursor:pointer;margin-top:12px;}
.pl-btn:hover{background:#c2410c;}
.pl-err{background:#fee2e2;color:#991b1b;border-radius:8px;padding:8px 14px;font-size:.85rem;font-weight:600;margin-bottom:12px;}
</style>
<div class="pl-wrap">
    <div class="pl-box">
        <div class="pl-icon">🔐</div>
        <div class="pl-title">เมนูบัญชี</div>
        <div class="pl-sub">กรุณาใส่รหัสผ่านเพื่อเข้าใช้งาน</div>
        <?php if ($payrollLoginError): ?>
        <div class="pl-err">⚠️ <?= htmlspecialchars($payrollLoginError) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= htmlspecialchars($_pySelf) ?>">
            <input type="hidden" name="_payroll_action" value="login">
            <input type="password" name="_payroll_pass" class="pl-inp" placeholder="••••••••••" autofocus>
            <button type="submit" class="pl-btn">🔓 เข้าสู่ระบบ</button>
        </form>
    </div>
</div>
<?php return; // หยุดเฉพาะ module นี้ — Gibbon ยัง render ต่อได้ปกติ
endif;
// ─────────────────────────────────────────────────────────────────────────────
?>
<style>
.py-card{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:1rem 1.25rem;margin-bottom:1rem;}
.py-table{width:100%;border-collapse:collapse;font-size:.88rem;}
.py-table th{background:#1A2A5E;color:#fff;padding:9px 12px;text-align:left;font-size:.82rem;font-weight:600;}
.py-table td{padding:9px 12px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.py-table tr:hover td{background:#fafafa;}
.py-badge-paid{background:#d1fae5;color:#065f46;border-radius:99px;padding:2px 12px;font-size:.75rem;font-weight:700;}
.py-badge-pending{background:#fef3c7;color:#92400e;border-radius:99px;padding:2px 12px;font-size:.75rem;font-weight:700;}
.py-btn{padding:6px 16px;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;}
.py-btn-primary{background:#1A2A5E;color:#fff;}.py-btn-primary:hover{background:#2D5294;}
.py-btn-success{background:#059669;color:#fff;}.py-btn-success:hover{background:#047857;}
.py-btn-warning{background:#d97706;color:#fff;}.py-btn-warning:hover{background:#b45309;}
.py-btn-danger{background:#dc2626;color:#fff;}.py-btn-danger:hover{background:#b91c1c;}
.py-btn-outline{background:#fff;border:1px solid #d1d5db;color:#374151;}.py-btn-outline:hover{background:#f9fafb;}
.py-btn-green{background:#16a34a;color:#fff;}.py-btn-green:hover{background:#15803d;}
.py-btn-excel{background:#217346;color:#fff;}.py-btn-excel:hover{background:#1a5c38;}
.py-btn-pdf{background:#dc2626;color:#fff;}.py-btn-pdf:hover{background:#b91c1c;}
.py-btn-adm{background:#7c3aed;color:#fff;}.py-btn-adm:hover{background:#6d28d9;}
.py-stat{border-radius:10px;padding:.75rem 1rem;text-align:center;}
.py-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center;}
.py-modal-bg.open{display:flex;}
.py-modal{background:#fff;border-radius:14px;width:100%;max-width:520px;padding:1.5rem;box-shadow:0 20px 60px rgba(0,0,0,.25);max-height:90vh;overflow-y:auto;}
.py-fg{margin-bottom:12px;}
.py-fg label{display:block;font-size:.8rem;font-weight:600;color:#374151;margin-bottom:4px;}
.py-fg input,.py-fg select,.py-fg textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.88rem;box-sizing:border-box;outline:none;}
.py-fg input:focus,.py-fg select:focus{border-color:#1A2A5E;box-shadow:0 0 0 2px rgba(26,42,94,.1);}
.py-grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.py-total-calc{background:#f0f9ff;border-radius:8px;padding:.75rem 1rem;margin:8px 0;font-size:.9rem;font-weight:700;color:#1A2A5E;text-align:center;}
.py-summary-bar{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:1rem;}
.py-sum-card{flex:1;min-width:140px;border-radius:10px;padding:.75rem 1rem;text-align:center;}
/* Tabs */
.py-tabs{display:flex;gap:0;margin-bottom:1.25rem;border-bottom:2px solid #e5e7eb;}
.py-tab-btn{padding:9px 24px;font-size:.9rem;font-weight:600;cursor:pointer;border:none;background:transparent;color:#6b7280;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .15s;}
.py-tab-btn.active{color:#1A2A5E;border-bottom-color:#1A2A5E;}
.py-tab-btn:hover:not(.active){color:#374151;background:#f9fafb;}
/* Role rows in admin modal */
.adm-role-row{display:grid;grid-template-columns:1fr 100px 80px 70px auto;gap:6px;align-items:center;margin-bottom:6px;}
.adm-role-row input{padding:7px 8px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;outline:none;width:100%;}
.adm-role-row input:focus{border-color:#7c3aed;}
.adm-role-header{display:grid;grid-template-columns:1fr 100px 80px 70px auto;gap:6px;font-size:.75rem;font-weight:700;color:#6b7280;margin-bottom:4px;padding:0 2px;}
.adm-sub{font-size:.78rem;color:#059669;font-weight:700;text-align:right;line-height:2.2;}
/* roles display in table */
.role-chip{display:inline-block;background:#f3f0ff;color:#5b21b6;border-radius:6px;padding:1px 8px;font-size:.73rem;margin:1px 2px;}
</style>

<div style="max-width:100%;padding-bottom:3rem;">

<?php if ($alertText): ?>
<div style="background:<?= $alertType==='success'?'#d1fae5':'#fee2e2' ?>;border-radius:8px;padding:10px 16px;margin-bottom:1rem;
     color:<?= $alertType==='success'?'#065f46':'#991b1b' ?>;font-weight:600;"><?= htmlspecialchars($alertText) ?></div>
<?php endif; ?>

<!-- Header -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:1rem;">
    <div>
        <h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0;">🏦 เมนูบัญชี</h2>
        <p style="font-size:.83rem;color:#6b7280;margin:3px 0 0;">จัดการค่าจ้างครูและแอดมิน</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        <form method="post" action="<?= htmlspecialchars($_pySelf) ?>" style="display:inline;">
            <input type="hidden" name="_payroll_action" value="logout">
            <button type="submit" class="py-btn py-btn-outline" style="font-size:.8rem;padding:6px 14px;">🔒 ออกจากระบบบัญชี</button>
        </form>
        <button class="py-btn py-btn-primary" id="btn-add-teacher" onclick="teaOpenAdd()" style="font-size:.9rem;padding:8px 20px;">
            ＋ เพิ่มรายการครู
        </button>
        <button class="py-btn py-btn-adm" id="btn-add-admin" onclick="admOpenModal()" style="font-size:.9rem;padding:8px 20px;display:none;">
            ＋ เพิ่มรายการแอดมิน
        </button>
    </div>
</div>

<!-- Tabs -->
<div class="py-tabs">
    <button class="py-tab-btn <?= $activeTab==='teacher'?'active':'' ?>" onclick="pyShowTab('teacher')" id="tab-btn-teacher">
        👨‍🏫 ค่าจ้างครู <span style="background:#e0e7ff;color:#1A2A5E;border-radius:99px;padding:1px 8px;font-size:.72rem;margin-left:4px;"><?= count($records) ?></span>
    </button>
    <button class="py-tab-btn <?= $activeTab==='admin'?'active':'' ?>" onclick="pyShowTab('admin')" id="tab-btn-admin">
        👤 ค่าจ้างแอดมิน <span style="background:#ede9fe;color:#5b21b6;border-radius:99px;padding:1px 8px;font-size:.72rem;margin-left:4px;"><?= count($adminRecords) ?></span>
    </button>
</div>

<!-- ══════════════════ TAB: TEACHER ══════════════════ -->
<div id="py-tab-teacher" style="display:<?= $activeTab==='teacher'?'block':'none' ?>;">

<!-- Teacher Summary Bar -->
<div class="py-summary-bar">
    <div class="py-sum-card" style="background:#dbeafe;color:#1e40af;">
        <div style="font-size:1.4rem;font-weight:800;"><?= count($records) ?></div>
        <div style="font-size:.75rem;font-weight:600;">รายการทั้งหมด</div>
    </div>
    <div class="py-sum-card" style="background:#dcfce7;color:#065f46;">
        <div style="font-size:1.4rem;font-weight:800;"><?= number_format($totalPaid, 0) ?> ฿</div>
        <div style="font-size:.75rem;font-weight:600;">จ่ายแล้ว</div>
    </div>
    <div class="py-sum-card" style="background:#fef3c7;color:#92400e;">
        <div style="font-size:1.4rem;font-weight:800;"><?= number_format($totalPending, 0) ?> ฿</div>
        <div style="font-size:.75rem;font-weight:600;">รอจ่าย</div>
    </div>
    <div class="py-sum-card" style="background:#f1f5f9;color:#1A2A5E;">
        <div style="font-size:1.4rem;font-weight:800;"><?= number_format($grandTotal, 0) ?> ฿</div>
        <div style="font-size:.75rem;font-weight:600;">รวมทั้งหมด</div>
    </div>
</div>

<!-- Teacher Filter Bar -->
<div class="py-card" style="padding:.75rem 1rem;">
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="q"   value="/modules/7j/payroll.php">
        <input type="hidden" name="tab" value="teacher">
        <div style="flex:2;min-width:160px;">
            <label style="font-size:.75rem;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">ค้นหาครู</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ชื่อหรือรหัสครู..."
                style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;">
        </div>
        <div style="flex:1;min-width:130px;">
            <label style="font-size:.75rem;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">ตั้งแต่วันที่</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"
                style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;">
        </div>
        <div style="flex:1;min-width:130px;">
            <label style="font-size:.75rem;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">ถึงวันที่</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"
                style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;">
        </div>
        <button type="submit" class="py-btn py-btn-primary">🔍 ค้นหา</button>
        <?php if ($search || $dateFrom || $dateTo): ?>
        <a href="?q=/modules/7j/payroll.php&tab=teacher" class="py-btn py-btn-outline">✕ ล้าง</a>
        <?php endif; ?>
    </form>
</div>

<!-- Export Buttons (Teacher) -->
<?php if (!empty($records)): ?>
<div style="display:flex;gap:8px;margin-bottom:.75rem;flex-wrap:wrap;">
    <form method="post" style="display:inline;">
        <input type="hidden" name="action" value="export">
        <input type="hidden" name="format" value="excel">
        <input type="hidden" name="q"      value="/modules/7j/payroll.php">
        <?php foreach (['search'=>$search,'date_from'=>$dateFrom,'date_to'=>$dateTo] as $k=>$v): if($v): ?>
        <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>">
        <?php endif; endforeach; ?>
        <button type="submit" class="py-btn py-btn-excel">📊 Export Excel (CSV)</button>
    </form>
    <form method="post" style="display:inline;">
        <input type="hidden" name="action" value="export">
        <input type="hidden" name="format" value="pdf">
        <input type="hidden" name="q"      value="/modules/7j/payroll.php">
        <?php foreach (['search'=>$search,'date_from'=>$dateFrom,'date_to'=>$dateTo] as $k=>$v): if($v): ?>
        <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>">
        <?php endif; endforeach; ?>
        <button type="submit" class="py-btn py-btn-pdf">🖨️ Export PDF</button>
    </form>
    <span style="font-size:.75rem;color:#ef4444;align-self:center;">⚠️ หลัง Export ข้อมูลจะถูกนำออกจากเมนูบัญชีทันที</span>
</div>
<?php endif; ?>

<!-- Teacher Records Table -->
<div class="py-card" style="overflow-x:auto;padding:0;">
    <?php if (empty($records)): ?>
    <div style="text-align:center;padding:3rem 1rem;color:#9ca3af;">
        <div style="font-size:2rem;margin-bottom:8px;">👨‍🏫</div>
        ยังไม่มีรายการครู — กด "＋ เพิ่มรายการครู"
    </div>
    <?php else: ?>
    <table class="py-table">
        <thead><tr>
            <th>#</th><th>ชื่อครู</th><th>ช่วงวันที่ / สัปดาห์</th>
            <th>หน้าที่ (จำนวน × ค่าจ้าง)</th>
            <th style="text-align:right;">รวม (฿)</th>
            <th style="text-align:center;">สถานะ</th>
            <th style="text-align:center;">จัดการ</th>
        </tr></thead>
        <tbody>
        <?php $n = 1; foreach ($records as $r):
            $roles  = json_decode($r['roles_json'] ?? '', true) ?: [];
            $total  = !empty($roles) ? (float)$r['total_amount'] : (float)$r['sessions_count'] * (float)$r['pay_rate'];
            $isPaid = $r['status'] === 'paid';
            $period = ($r['date_from'] && $r['date_to']) ? fmtDate($r['date_from']).' – '.fmtDate($r['date_to']) : '—';
        ?>
        <tr>
            <td style="color:#9ca3af;font-size:.78rem;"><?= $n++ ?></td>
            <td style="font-weight:600;"><?= htmlspecialchars($r['teacher_name']) ?></td>
            <td style="font-size:.8rem;color:#6b7280;">
                <?= $period ?>
                <?php if ($r['week_label']): ?><div style="margin-top:2px;"><?= htmlspecialchars($r['week_label']) ?></div><?php endif; ?>
            </td>
            <td style="font-size:.8rem;">
                <?php if (!empty($roles)): ?>
                    <?php foreach ($roles as $role): ?>
                    <span class="role-chip"><?= htmlspecialchars($role['role']) ?> <?= $role['count'] ?>×<?= number_format($role['rate'],0) ?>฿</span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="role-chip"><?= $r['sessions_count'] ?> คาบ ×<?= number_format($r['pay_rate'],0) ?>฿</span>
                <?php endif; ?>
            </td>
            <td style="text-align:right;font-weight:700;color:#065f46;"><?= number_format($total, 2) ?></td>
            <td style="text-align:center;">
                <?php if ($isPaid): ?>
                <span class="py-badge-paid">✅ จ่ายแล้ว</span>
                <?php if ($r['paid_at']): ?><div style="font-size:.7rem;color:#9ca3af;margin-top:2px;"><?= date('d/m/Y H:i', strtotime($r['paid_at'])) ?></div><?php endif; ?>
                <?php else: ?><span class="py-badge-pending">⏳ รอจ่าย</span><?php endif; ?>
            </td>
            <td style="text-align:center;">
                <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap;">
                    <?php if (!$isPaid): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action"     value="mark_paid">
                        <input type="hidden" name="payroll_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="q"          value="/modules/7j/payroll.php">
                        <input type="hidden" name="tab"        value="teacher">
                        <button type="submit" class="py-btn py-btn-success" style="padding:4px 12px;font-size:.75rem;">✅ จ่ายแล้ว</button>
                    </form>
                    <?php else: ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action"     value="mark_unpaid">
                        <input type="hidden" name="payroll_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="q"          value="/modules/7j/payroll.php">
                        <input type="hidden" name="tab"        value="teacher">
                        <button type="submit" class="py-btn py-btn-outline" style="padding:4px 10px;font-size:.75rem;">↩ ยกเลิก</button>
                    </form>
                    <?php endif; ?>
                    <button class="py-btn py-btn-warning" style="padding:4px 10px;font-size:.75rem;"
                        onclick='pyOpenEdit(<?= htmlspecialchars(json_encode([
                            "id"             => $r["id"],
                            "teacher_name"   => $r["teacher_name"],
                            "sessions_count" => $r["sessions_count"],
                            "pay_rate"       => $r["pay_rate"],
                            "roles"          => json_decode($r["roles_json"] ?? "[]", true) ?: [],
                            "date_from"      => $r["date_from"]  ?? "",
                            "date_to"        => $r["date_to"]    ?? "",
                            "week_label"     => $r["week_label"] ?? "",
                            "note"           => $r["note"]       ?? "",
                        ])) ?>)'>✏️ แก้ไข</button>
                    <form method="post" style="display:inline;" onsubmit="return confirm('ลบรายการนี้?')">
                        <input type="hidden" name="action"     value="delete_entry">
                        <input type="hidden" name="payroll_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="q"          value="/modules/7j/payroll.php">
                        <input type="hidden" name="tab"        value="teacher">
                        <button type="submit" class="py-btn py-btn-danger" style="padding:4px 10px;font-size:.75rem;">🗑</button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background:#f8fafc;font-weight:700;">
                <td colspan="3" style="text-align:right;padding:10px 12px;color:#374151;">รวมทั้งหมด</td>
                <td></td>
                <td style="text-align:right;color:#065f46;font-size:1rem;"><?= number_format($grandTotal, 2) ?> ฿</td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</div>

</div><!-- end tab-teacher -->

<!-- ══════════════════ TAB: ADMIN ══════════════════ -->
<div id="py-tab-admin" style="display:<?= $activeTab==='admin'?'block':'none' ?>;">

<!-- Admin Summary Bar -->
<div class="py-summary-bar">
    <div class="py-sum-card" style="background:#ede9fe;color:#5b21b6;">
        <div style="font-size:1.4rem;font-weight:800;"><?= count($adminRecords) ?></div>
        <div style="font-size:.75rem;font-weight:600;">รายการทั้งหมด</div>
    </div>
    <div class="py-sum-card" style="background:#dcfce7;color:#065f46;">
        <div style="font-size:1.4rem;font-weight:800;"><?= number_format($admTotalPaid, 0) ?> ฿</div>
        <div style="font-size:.75rem;font-weight:600;">จ่ายแล้ว</div>
    </div>
    <div class="py-sum-card" style="background:#fef3c7;color:#92400e;">
        <div style="font-size:1.4rem;font-weight:800;"><?= number_format($admTotalPending, 0) ?> ฿</div>
        <div style="font-size:.75rem;font-weight:600;">รอจ่าย</div>
    </div>
    <div class="py-sum-card" style="background:#f1f5f9;color:#5b21b6;">
        <div style="font-size:1.4rem;font-weight:800;"><?= number_format($admGrandTotal, 0) ?> ฿</div>
        <div style="font-size:.75rem;font-weight:600;">รวมทั้งหมด</div>
    </div>
</div>

<!-- Admin Filter Bar -->
<div class="py-card" style="padding:.75rem 1rem;">
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="q"   value="/modules/7j/payroll.php">
        <input type="hidden" name="tab" value="admin">
        <div style="flex:2;min-width:160px;">
            <label style="font-size:.75rem;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">ค้นหาแอดมิน</label>
            <input type="text" name="adm_search" value="<?= htmlspecialchars($admSearch) ?>" placeholder="ชื่อแอดมิน..."
                style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;">
        </div>
        <div style="flex:1;min-width:130px;">
            <label style="font-size:.75rem;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">ตั้งแต่วันที่</label>
            <input type="date" name="adm_date_from" value="<?= htmlspecialchars($admDateFrom) ?>"
                style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;">
        </div>
        <div style="flex:1;min-width:130px;">
            <label style="font-size:.75rem;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">ถึงวันที่</label>
            <input type="date" name="adm_date_to" value="<?= htmlspecialchars($admDateTo) ?>"
                style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.85rem;box-sizing:border-box;">
        </div>
        <button type="submit" class="py-btn py-btn-adm">🔍 ค้นหา</button>
        <?php if ($admSearch || $admDateFrom || $admDateTo): ?>
        <a href="?q=/modules/7j/payroll.php&tab=admin" class="py-btn py-btn-outline">✕ ล้าง</a>
        <?php endif; ?>
    </form>
</div>

<!-- Admin Records Table -->
<div class="py-card" style="overflow-x:auto;padding:0;">
    <?php if (empty($adminRecords)): ?>
    <div style="text-align:center;padding:3rem 1rem;color:#9ca3af;">
        <div style="font-size:2rem;margin-bottom:8px;">👤</div>
        ยังไม่มีรายการแอดมิน — กด "＋ เพิ่มรายการแอดมิน"
    </div>
    <?php else: ?>
    <table class="py-table">
        <thead><tr>
            <th>#</th>
            <th>ชื่อแอดมิน</th>
            <th>ช่วงวันที่ / สัปดาห์</th>
            <th>หน้าที่ (จำนวน × ค่าจ้าง)</th>
            <th style="text-align:right;">รวม (฿)</th>
            <th style="text-align:center;">สถานะ</th>
            <th style="text-align:center;">จัดการ</th>
        </tr></thead>
        <tbody>
        <?php $n = 1; foreach ($adminRecords as $r):
            $isPaid  = $r['status'] === 'paid';
            $period  = ($r['date_from'] && $r['date_to']) ? fmtDate($r['date_from']).' – '.fmtDate($r['date_to']) : '—';
            $roles   = json_decode($r['roles_json'] ?? '[]', true) ?: [];
        ?>
        <tr>
            <td style="color:#9ca3af;font-size:.78rem;"><?= $n++ ?></td>
            <td style="font-weight:600;color:#5b21b6;"><?= htmlspecialchars($r['admin_name']) ?></td>
            <td style="font-size:.8rem;color:#6b7280;">
                <?= $period ?>
                <?php if ($r['week_label']): ?><div style="margin-top:2px;"><?= htmlspecialchars($r['week_label']) ?></div><?php endif; ?>
            </td>
            <td style="font-size:.8rem;">
                <?php foreach ($roles as $role): ?>
                <span class="role-chip"><?= htmlspecialchars($role['role']) ?> <?= $role['count'] ?>×<?= number_format($role['rate'],0) ?>฿</span>
                <?php endforeach; ?>
                <?php if (empty($roles)): ?><span style="color:#9ca3af;">—</span><?php endif; ?>
            </td>
            <td style="text-align:right;font-weight:700;color:#065f46;"><?= number_format((float)$r['total_amount'], 2) ?></td>
            <td style="text-align:center;">
                <?php if ($isPaid): ?>
                <span class="py-badge-paid">✅ จ่ายแล้ว</span>
                <?php if ($r['paid_at']): ?><div style="font-size:.7rem;color:#9ca3af;margin-top:2px;"><?= date('d/m/Y H:i', strtotime($r['paid_at'])) ?></div><?php endif; ?>
                <?php else: ?><span class="py-badge-pending">⏳ รอจ่าย</span><?php endif; ?>
            </td>
            <td style="text-align:center;">
                <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap;">
                    <?php if (!$isPaid): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action"          value="mark_admin_paid">
                        <input type="hidden" name="admin_payroll_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="q"               value="/modules/7j/payroll.php">
                        <input type="hidden" name="tab"             value="admin">
                        <button type="submit" class="py-btn py-btn-success" style="padding:4px 12px;font-size:.75rem;">✅ จ่ายแล้ว</button>
                    </form>
                    <?php else: ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action"          value="mark_admin_unpaid">
                        <input type="hidden" name="admin_payroll_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="q"               value="/modules/7j/payroll.php">
                        <input type="hidden" name="tab"             value="admin">
                        <button type="submit" class="py-btn py-btn-outline" style="padding:4px 10px;font-size:.75rem;">↩ ยกเลิก</button>
                    </form>
                    <?php endif; ?>
                    <button class="py-btn py-btn-warning" style="padding:4px 10px;font-size:.75rem;"
                        onclick='admOpenEdit(<?= htmlspecialchars(json_encode([
                            "id"         => $r["id"],
                            "admin_name" => $r["admin_name"],
                            "roles"      => json_decode($r["roles_json"] ?? "[]", true) ?: [],
                            "date_from"  => $r["date_from"]  ?? "",
                            "date_to"    => $r["date_to"]    ?? "",
                            "week_label" => $r["week_label"] ?? "",
                            "note"       => $r["note"]       ?? "",
                        ])) ?>)'>✏️ แก้ไข</button>
                    <form method="post" style="display:inline;" onsubmit="return confirm('ลบรายการนี้?')">
                        <input type="hidden" name="action"          value="delete_admin">
                        <input type="hidden" name="admin_payroll_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="q"               value="/modules/7j/payroll.php">
                        <input type="hidden" name="tab"             value="admin">
                        <button type="submit" class="py-btn py-btn-danger" style="padding:4px 10px;font-size:.75rem;">🗑</button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background:#f8fafc;font-weight:700;">
                <td colspan="4" style="text-align:right;padding:10px 12px;color:#374151;">รวมทั้งหมด</td>
                <td style="text-align:right;color:#065f46;font-size:1rem;"><?= number_format($admGrandTotal, 2) ?> ฿</td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</div>

</div><!-- end tab-admin -->

</div><!-- end main -->

<!-- ─── Teacher Edit Modal ────────────────────────────────────────────────── -->
<div id="py-modal-edit" class="py-modal-bg">
<div class="py-modal" style="max-width:560px;">
    <h3 style="margin:0 0 1rem;font-size:1.1rem;font-weight:700;">✏️ แก้ไขรายการค่าจ้างครู</h3>
    <form method="post">
        <input type="hidden" name="action"     value="edit_entry">
        <input type="hidden" name="q"          value="/modules/7j/payroll.php">
        <input type="hidden" name="tab"        value="teacher">
        <input type="hidden" name="payroll_id" id="py-edit-id">

        <div class="py-fg">
            <label>ชื่อครู *</label>
            <input type="text" name="teacher_name" id="py-edit-tname" required>
        </div>
        <div class="py-grid2">
            <div class="py-fg"><label>ตั้งแต่วันที่</label><input type="date" name="date_from" id="py-edit-dfrom"></div>
            <div class="py-fg"><label>ถึงวันที่</label><input type="date" name="date_to" id="py-edit-dto"></div>
        </div>
        <div class="py-fg">
            <label>สัปดาห์/วัน</label>
            <input type="text" name="week_label" id="py-edit-week">
        </div>
        <!-- Role rows -->
        <div class="py-fg">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                <label style="margin:0;">หน้าที่และค่าจ้าง *</label>
                <button type="button" onclick="teaEditAddRow()"
                    style="padding:3px 12px;background:#dbeafe;color:#1A2A5E;border:none;border-radius:6px;font-size:.78rem;font-weight:700;cursor:pointer;">
                    ＋ เพิ่มหน้าที่
                </button>
            </div>
            <div class="adm-role-header">
                <span>หน้าที่</span><span style="text-align:center;">ค่าจ้าง (฿)</span><span style="text-align:center;">จำนวน</span><span style="text-align:right;">รวม</span><span></span>
            </div>
            <div id="tea-edit-rows"></div>
        </div>
        <div class="py-total-calc" id="py-edit-total">รวมทั้งหมด: 0 ฿</div>
        <div class="py-fg"><label>หมายเหตุ</label><textarea name="note" id="py-edit-note" rows="2"></textarea></div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
            <button type="button" class="py-btn py-btn-outline" onclick="pyCloseModal('edit')">ยกเลิก</button>
            <button type="submit" class="py-btn py-btn-warning">💾 บันทึกการแก้ไข</button>
        </div>
    </form>
</div>
</div>

<!-- ─── Teacher Add Modal ─────────────────────────────────────────────────── -->
<div id="py-modal-add" class="py-modal-bg">
<div class="py-modal">
    <h3 style="margin:0 0 1rem;font-size:1.1rem;font-weight:700;">➕ เพิ่มรายการค่าจ้างครู</h3>
    <form method="post">
        <input type="hidden" name="action"     value="add_entry">
        <input type="hidden" name="q"          value="/modules/7j/payroll.php">
        <input type="hidden" name="tab"        value="teacher">
        <input type="hidden" name="teacher_id" id="py-add-tid">

        <div class="py-fg">
            <label>ชื่อครู *</label>
            <select id="py-add-teacher-sel" onchange="pySelectTeacher(this)">
                <option value="">— เลือกครู —</option>
                <?php foreach ($teachers as $t): ?>
                <option value="<?= htmlspecialchars($t['id']) ?>" data-name="<?= htmlspecialchars($t['displayName']) ?>">
                    <?= htmlspecialchars($t['displayName']) ?><?= $t['teacherCode'] ? ' ('.$t['teacherCode'].')' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="teacher_name" id="py-add-tname" placeholder="หรือกรอกชื่อเอง" style="margin-top:6px;">
        </div>
        <div class="py-grid2">
            <div class="py-fg"><label>ตั้งแต่วันที่</label><input type="date" name="date_from" id="py-add-dfrom" onchange="pyCalcTotal()"></div>
            <div class="py-fg"><label>ถึงวันที่</label><input type="date" name="date_to" id="py-add-dto" onchange="pyCalcTotal()"></div>
        </div>
        <div class="py-fg">
            <label>สัปดาห์/วัน <span style="font-weight:400;color:#9ca3af;">(เช่น สัปดาห์ที่ 23 วันจันทร์)</span></label>
            <input type="text" name="week_label" placeholder="เช่น สัปดาห์ที่ 23 (จันทร์ 10-06-2026)">
        </div>
        <!-- Role rows -->
        <div class="py-fg">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                <label style="margin:0;">หน้าที่และค่าจ้าง *</label>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <button type="button" onclick="pyFetchSessions()"
                        style="padding:3px 10px;background:#dbeafe;color:#1e40af;border:none;border-radius:6px;font-size:.75rem;font-weight:700;cursor:pointer;">
                        🔄 ดึงคาบจากระบบ
                    </button>
                    <button type="button" onclick="teaAddRow()"
                        style="padding:3px 10px;background:#dbeafe;color:#1A2A5E;border:none;border-radius:6px;font-size:.75rem;font-weight:700;cursor:pointer;">
                        ＋ เพิ่มหน้าที่
                    </button>
                    <button type="button" onclick="pySaveRateTemplate()"
                        style="padding:3px 10px;background:#dcfce7;color:#065f46;border:none;border-radius:6px;font-size:.75rem;font-weight:700;cursor:pointer;"
                        title="บันทึกอัตราค่าจ้างนี้ไว้ใช้ครั้งต่อไป">
                        💾 บันทึกค่าจ้าง
                    </button>
                </div>
            </div>
            <div class="adm-role-header">
                <span>หน้าที่</span><span style="text-align:center;">ค่าจ้าง (฿)</span><span style="text-align:center;">จำนวน</span><span style="text-align:right;">รวม</span><span></span>
            </div>
            <div id="tea-add-rows"></div>
        </div>
        <div class="py-total-calc" id="tea-add-total">รวมทั้งหมด: 0 ฿</div>
        <div class="py-fg"><label>หมายเหตุ</label><textarea name="note" rows="2" placeholder="ถ้ามี..."></textarea></div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
            <button type="button" class="py-btn py-btn-outline" onclick="pyCloseModal('add')">ยกเลิก</button>
            <button type="submit" class="py-btn py-btn-primary">บันทึก</button>
        </div>
    </form>
</div>
</div>

<!-- ─── Admin Edit Modal ──────────────────────────────────────────────────── -->
<div id="py-modal-admin-edit" class="py-modal-bg">
<div class="py-modal" style="max-width:600px;">
    <h3 style="margin:0 0 1rem;font-size:1.1rem;font-weight:700;color:#5b21b6;">✏️ แก้ไขรายการค่าจ้างแอดมิน</h3>
    <form method="post">
        <input type="hidden" name="action"          value="edit_admin">
        <input type="hidden" name="q"               value="/modules/7j/payroll.php">
        <input type="hidden" name="tab"             value="admin">
        <input type="hidden" name="admin_payroll_id" id="adm-edit-id">

        <div class="py-fg">
            <label>ชื่อแอดมิน *</label>
            <input type="text" name="admin_name" id="adm-edit-name" required>
        </div>
        <div class="py-grid2">
            <div class="py-fg"><label>ตั้งแต่วันที่</label><input type="date" name="adm_date_from" id="adm-edit-dfrom"></div>
            <div class="py-fg"><label>ถึงวันที่</label><input type="date" name="adm_date_to" id="adm-edit-dto"></div>
        </div>
        <div class="py-fg">
            <label>สัปดาห์/วัน</label>
            <input type="text" name="adm_week_label" id="adm-edit-week">
        </div>
        <!-- Role rows -->
        <div class="py-fg">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                <label style="margin:0;">หน้าที่และค่าจ้าง *</label>
                <button type="button" onclick="admEditAddRow()"
                    style="padding:3px 10px;background:#ede9fe;color:#5b21b6;border:none;border-radius:6px;font-size:.75rem;font-weight:700;cursor:pointer;">
                    ＋ เพิ่มหน้าที่
                </button>
            </div>
            <div class="adm-role-header">
                <span>หน้าที่</span><span style="text-align:center;">ค่าจ้าง (฿)</span><span style="text-align:center;">จำนวน</span><span style="text-align:right;">รวม</span><span></span>
            </div>
            <div id="adm-edit-rows"></div>
        </div>
        <div class="py-total-calc" id="adm-edit-total" style="background:#f5f3ff;color:#5b21b6;">รวมทั้งหมด: 0 ฿</div>
        <div class="py-fg"><label>หมายเหตุ</label><textarea name="adm_note" id="adm-edit-note" rows="2"></textarea></div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
            <button type="button" class="py-btn py-btn-outline" onclick="pyCloseModal('admin-edit')">ยกเลิก</button>
            <button type="submit" class="py-btn py-btn-warning">💾 บันทึกการแก้ไข</button>
        </div>
    </form>
</div>
</div>

<!-- ─── Admin Add Modal ───────────────────────────────────────────────────── -->
<div id="py-modal-admin" class="py-modal-bg">
<div class="py-modal" style="max-width:600px;">
    <h3 style="margin:0 0 1rem;font-size:1.1rem;font-weight:700;color:#5b21b6;">➕ เพิ่มรายการค่าจ้างแอดมิน</h3>
    <form method="post">
        <input type="hidden" name="action" value="add_admin">
        <input type="hidden" name="q"      value="/modules/7j/payroll.php">
        <input type="hidden" name="tab"    value="admin">

        <div class="py-fg">
            <label>ชื่อแอดมิน *</label>
            <input type="text" name="admin_name" id="adm-name-input"
                placeholder="กรอกชื่อแอดมิน..."
                oninput="admLoadRateDebounce(this.value)" required>
        </div>

        <div class="py-grid2">
            <div class="py-fg"><label>ตั้งแต่วันที่</label><input type="date" name="adm_date_from"></div>
            <div class="py-fg"><label>ถึงวันที่</label><input type="date" name="adm_date_to"></div>
        </div>
        <div class="py-fg">
            <label>สัปดาห์/วัน</label>
            <input type="text" name="adm_week_label" placeholder="เช่น สัปดาห์ที่ 23 (จันทร์ 10-06-2026)">
        </div>

        <!-- Role rows -->
        <div class="py-fg">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                <label style="margin:0;">หน้าที่และค่าจ้าง *</label>
                <div style="display:flex;gap:6px;">
                    <button type="button" onclick="admAddRow()"
                        style="padding:3px 10px;background:#ede9fe;color:#5b21b6;border:none;border-radius:6px;font-size:.75rem;font-weight:700;cursor:pointer;">
                        ＋ เพิ่มหน้าที่
                    </button>
                    <button type="button" onclick="admSaveRateTemplate()"
                        style="padding:3px 10px;background:#dcfce7;color:#065f46;border:none;border-radius:6px;font-size:.75rem;font-weight:700;cursor:pointer;"
                        title="บันทึกอัตราค่าจ้างนี้ไว้ใช้ครั้งต่อไป">
                        💾 บันทึกค่าจ้าง
                    </button>
                </div>
            </div>
            <div class="adm-role-header">
                <span>หน้าที่</span><span style="text-align:center;">ค่าจ้าง (฿)</span><span style="text-align:center;">จำนวน</span><span style="text-align:right;">รวม</span><span></span>
            </div>
            <div id="adm-rows-container"></div>
        </div>

        <div class="py-total-calc" id="adm-total-display" style="background:#f5f3ff;color:#5b21b6;">รวมทั้งหมด: 0 ฿</div>

        <div class="py-fg"><label>หมายเหตุ</label><textarea name="adm_note" rows="2" placeholder="ถ้ามี..."></textarea></div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
            <button type="button" class="py-btn py-btn-outline" onclick="admCloseModal()">ยกเลิก</button>
            <button type="submit" class="py-btn py-btn-adm">บันทึก</button>
        </div>
    </form>
</div>
</div>

<script>
// ─── Tab switching ─────────────────────────────────────────────────────────────
function pyShowTab(tab) {
    ['teacher','admin'].forEach(function(t) {
        var el = document.getElementById('py-tab-'+t);
        var btn = document.getElementById('tab-btn-'+t);
        if (el)  el.style.display  = t === tab ? 'block' : 'none';
        if (btn) btn.classList.toggle('active', t === tab);
    });
    document.getElementById('btn-add-teacher').style.display = tab === 'teacher' ? '' : 'none';
    document.getElementById('btn-add-admin').style.display   = tab === 'admin'   ? '' : 'none';
}

// ─── Modal helpers ─────────────────────────────────────────────────────────────
var pyTeacherData = <?= json_encode(array_column($teachers, null, 'id')) ?>;
function pyOpenModal(id)  { document.getElementById('py-modal-'+id).classList.add('open'); }
function pyCloseModal(id) { document.getElementById('py-modal-'+id).classList.remove('open'); }
document.querySelectorAll('.py-modal-bg').forEach(function(bg) {
    bg.addEventListener('click', function(e) { if (e.target === bg) bg.classList.remove('open'); });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.querySelectorAll('.py-modal-bg.open').forEach(function(m) { m.classList.remove('open'); });
});
function pySelectTeacher(sel) {
    var opt = sel.options[sel.selectedIndex];
    document.getElementById('py-add-tid').value   = opt.value || '';
    document.getElementById('py-add-tname').value = opt.value ? (opt.dataset.name || '') : '';
    if (opt.value) pyLoadRateTemplate(opt.value, opt.dataset.name || '');
}
function pyLoadRateTemplate(tid, tname) {
    var url = '?q=/modules/7j/payroll.php&fetch_rate_template=1&tid='+encodeURIComponent(tid)+'&tname='+encodeURIComponent(tname);
    fetch(url).then(function(r){ return r.json(); }).then(function(data) {
        if (data.rates && data.rates.length > 0) {
            teaAddCount = 0;
            document.getElementById('tea-add-rows').innerHTML = '';
            data.rates.forEach(function(r) { teaAddRow(r.role, r.rate, ''); });
            teaAddCalc();
        }
    }).catch(function(){});
}
function pySaveRateTemplate() {
    var tid   = document.getElementById('py-add-tid').value;
    var tname = document.getElementById('py-add-tname').value.trim();
    if (!tname) { alert('กรุณาระบุชื่อครูก่อนบันทึก'); return; }
    var fd = new FormData();
    fd.append('action', 'save_rate_template');
    fd.append('q', '/modules/7j/payroll.php');
    fd.append('teacher_id', tid);
    fd.append('teacher_name', tname);
    document.querySelectorAll('#tea-add-rows input[name="role_name[]"]').forEach(function(el){ fd.append('role_name[]', el.value); });
    document.querySelectorAll('#tea-add-rows input[name="role_rate[]"]').forEach(function(el){ fd.append('role_rate[]', el.value); });
    fetch('?q=/modules/7j/payroll.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(data){ alert(data.msg || (data.ok ? '✅ บันทึกสำเร็จ' : '❌ เกิดข้อผิดพลาด')); })
        .catch(function(){ alert('❌ เกิดข้อผิดพลาด'); });
}

// ─── Teacher Add: dynamic role rows ───────────────────────────────────────────
var teaAddCount = 0;
function teaOpenAdd() {
    teaAddCount = 0;
    document.getElementById('tea-add-rows').innerHTML = '';
    teaAddRow();
    document.getElementById('tea-add-total').textContent = 'รวมทั้งหมด: 0 ฿';
    pyOpenModal('add');
}
function teaAddRow(roleName, rate, count) {
    teaAddCount++;
    var idx = teaAddCount;
    var row = document.createElement('div');
    row.className = 'adm-role-row'; row.id = 'tea-add-row-'+idx;
    row.innerHTML =
        '<input type="text"   name="role_name[]"  placeholder="หน้าที่ เช่น คาบสอน" value="'+(roleName||'')+'" oninput="teaAddCalc()" required>'+
        '<input type="number" name="role_rate[]"  placeholder="ค่าจ้าง" min="0" step="0.01" value="'+(rate||'')+'" oninput="teaAddCalc()" style="text-align:right;">'+
        '<input type="number" name="role_count[]" placeholder="จำนวน"   min="0" step="0.01" value="'+(count||1)+'" oninput="teaAddCalc()" style="text-align:center;">'+
        '<span class="adm-sub" id="tea-add-sub-'+idx+'">0 ฿</span>'+
        (idx>1 ? '<button type="button" onclick="document.getElementById(\'tea-add-row-'+idx+'\').remove();teaAddCalc();" style="padding:4px 8px;background:#fee2e2;color:#dc2626;border:none;border-radius:6px;font-size:.8rem;cursor:pointer;font-weight:700;">✕</button>'
               : '<span></span>');
    document.getElementById('tea-add-rows').appendChild(row);
    teaAddCalc();
}
function teaAddCalc() {
    var grand = 0;
    document.querySelectorAll('#tea-add-rows .adm-role-row').forEach(function(row) {
        var r = parseFloat(row.querySelector('input[name="role_rate[]"]').value)||0;
        var c = parseFloat(row.querySelector('input[name="role_count[]"]').value)||0;
        var sub = r*c; grand += sub;
        var s = row.querySelector('[id^="tea-add-sub-"]');
        if(s) s.textContent = sub.toLocaleString('th-TH',{minimumFractionDigits:0})+' ฿';
    });
    document.getElementById('tea-add-total').textContent = 'รวมทั้งหมด: '+grand.toLocaleString('th-TH',{minimumFractionDigits:2})+' ฿';
}

// ─── Teacher Edit: dynamic role rows ──────────────────────────────────────────
var teaEditCount = 0;
function pyOpenEdit(d) {
    document.getElementById('py-edit-id').value    = d.id;
    document.getElementById('py-edit-tname').value = d.teacher_name  || '';
    document.getElementById('py-edit-dfrom').value = d.date_from     || '';
    document.getElementById('py-edit-dto').value   = d.date_to       || '';
    document.getElementById('py-edit-week').value  = d.week_label    || '';
    document.getElementById('py-edit-note').value  = d.note          || '';
    teaEditCount = 0;
    document.getElementById('tea-edit-rows').innerHTML = '';
    var roles = d.roles || [];
    if (roles.length === 0 && d.sessions_count > 0) {
        roles = [{role:'คาบสอน', rate: d.pay_rate, count: d.sessions_count}];
    }
    if (roles.length === 0) roles = [{}];
    roles.forEach(function(r) { teaEditAddRow(r.role, r.rate, r.count); });
    teaEditCalc();
    pyOpenModal('edit');
}
function teaEditAddRow(roleName, rate, count) {
    teaEditCount++;
    var idx = teaEditCount;
    var row = document.createElement('div');
    row.className = 'adm-role-row'; row.id = 'tea-edit-row-'+idx;
    row.innerHTML =
        '<input type="text"   name="role_name[]"  placeholder="หน้าที่ เช่น คาบสอน" value="'+(roleName||'')+'" oninput="teaEditCalc()" required>'+
        '<input type="number" name="role_rate[]"  placeholder="ค่าจ้าง" min="0" step="0.01" value="'+(rate||'')+'" oninput="teaEditCalc()" style="text-align:right;">'+
        '<input type="number" name="role_count[]" placeholder="จำนวน"   min="0" step="0.01" value="'+(count||1)+'" oninput="teaEditCalc()" style="text-align:center;">'+
        '<span class="adm-sub" id="tea-edit-sub-'+idx+'">0 ฿</span>'+
        (idx>1 ? '<button type="button" onclick="document.getElementById(\'tea-edit-row-'+idx+'\').remove();teaEditCalc();" style="padding:4px 8px;background:#fee2e2;color:#dc2626;border:none;border-radius:6px;font-size:.8rem;cursor:pointer;font-weight:700;">✕</button>'
               : '<span></span>');
    document.getElementById('tea-edit-rows').appendChild(row);
    teaEditCalc();
}
function teaEditCalc() {
    var grand = 0;
    document.querySelectorAll('#tea-edit-rows .adm-role-row').forEach(function(row) {
        var r = parseFloat(row.querySelector('input[name="role_rate[]"]').value)||0;
        var c = parseFloat(row.querySelector('input[name="role_count[]"]').value)||0;
        var sub = r*c; grand += sub;
        var s = row.querySelector('[id^="tea-edit-sub-"]');
        if(s) s.textContent = sub.toLocaleString('th-TH',{minimumFractionDigits:0})+' ฿';
    });
    document.getElementById('py-edit-total').textContent = 'รวมทั้งหมด: '+grand.toLocaleString('th-TH',{minimumFractionDigits:2})+' ฿';
}

// ─── Fetch sessions → fill first row count ────────────────────────────────────
function pyFetchSessions() {
    var tid   = document.getElementById('py-add-tid').value;
    var dfrom = document.getElementById('py-add-dfrom').value;
    var dto   = document.getElementById('py-add-dto').value;
    if (!tid)          { alert('กรุณาเลือกครูก่อนครับ'); return; }
    if (!dfrom || !dto){ alert('กรุณาเลือกช่วงวันที่ก่อนครับ'); return; }
    var url = '?q=/modules/7j/payroll.php&fetch_sessions=1&ftid='+encodeURIComponent(tid)
            + '&fdfrom='+encodeURIComponent(dfrom)+'&fdto='+encodeURIComponent(dto);
    fetch(url).then(function(r){ return r.text(); }).then(function(html) {
        var doc = (new DOMParser()).parseFromString(html, 'text/html');
        var val = doc.getElementById('py-fetched-sessions');
        if (val) {
            var cnt = val.dataset.count || 0;
            var firstCount = document.querySelector('#tea-add-rows input[name="role_count[]"]');
            var firstRole  = document.querySelector('#tea-add-rows input[name="role_name[]"]');
            if (firstCount) { firstCount.value = cnt; if (firstRole && !firstRole.value) firstRole.value = 'คาบสอน'; teaAddCalc(); }
        } else { alert('ไม่พบข้อมูลคาบสอนในช่วงวันที่นี้'); }
    }).catch(function(){ alert('เกิดข้อผิดพลาด กรุณาลองใหม่'); });
}

// ─── Admin modal ───────────────────────────────────────────────────────────────
var admRowCount = 0;
var _admLoadTimer = null;
function admOpenModal() {
    admRowCount = 0;
    document.getElementById('adm-rows-container').innerHTML = '';
    document.getElementById('adm-name-input').value = '';
    admAddRow();
    document.getElementById('adm-total-display').textContent = 'รวมทั้งหมด: 0 ฿';
    document.getElementById('py-modal-admin').classList.add('open');
}
function admLoadRateDebounce(aname) {
    clearTimeout(_admLoadTimer);
    if (!aname.trim()) return;
    _admLoadTimer = setTimeout(function() { admLoadRateTemplate(aname.trim()); }, 600);
}
function admLoadRateTemplate(aname) {
    var url = '?q=/modules/7j/payroll.php&fetch_admin_rate_template=1&aname='+encodeURIComponent(aname);
    fetch(url).then(function(r){ return r.json(); }).then(function(data) {
        if (data.rates && data.rates.length > 0) {
            admRowCount = 0;
            document.getElementById('adm-rows-container').innerHTML = '';
            data.rates.forEach(function(r) { admAddRow(r.role, r.rate, ''); });
            admCalcTotal();
        }
    }).catch(function(){});
}
// ─── Admin Edit modal ─────────────────────────────────────────────────────────
var admEditCount = 0;
function admOpenEdit(d) {
    document.getElementById('adm-edit-id').value    = d.id;
    document.getElementById('adm-edit-name').value  = d.admin_name  || '';
    document.getElementById('adm-edit-dfrom').value = d.date_from   || '';
    document.getElementById('adm-edit-dto').value   = d.date_to     || '';
    document.getElementById('adm-edit-week').value  = d.week_label  || '';
    document.getElementById('adm-edit-note').value  = d.note        || '';
    admEditCount = 0;
    document.getElementById('adm-edit-rows').innerHTML = '';
    var roles = d.roles || [];
    if (roles.length === 0) roles = [{}];
    roles.forEach(function(r) { admEditAddRow(r.role, r.rate, r.count); });
    admEditCalc();
    pyOpenModal('admin-edit');
}
function admEditAddRow(roleName, rate, count) {
    admEditCount++;
    var idx = admEditCount;
    var row = document.createElement('div');
    row.className = 'adm-role-row'; row.id = 'adm-edit-row-'+idx;
    row.innerHTML =
        '<input type="text"   name="role_name[]"  placeholder="หน้าที่ เช่น ผู้ดูแลระบบ" value="'+(roleName||'')+'" oninput="admEditCalc()" required>'+
        '<input type="number" name="role_rate[]"  placeholder="ค่าจ้าง" min="0" step="0.01" value="'+(rate||'')+'" oninput="admEditCalc()" style="text-align:right;">'+
        '<input type="number" name="role_count[]" placeholder="จำนวน"   min="0" step="0.01" value="'+(count||1)+'" oninput="admEditCalc()" style="text-align:center;">'+
        '<span class="adm-sub" id="adm-edit-sub-'+idx+'">0 ฿</span>'+
        (idx>1 ? '<button type="button" onclick="document.getElementById(\'adm-edit-row-'+idx+'\').remove();admEditCalc();" style="padding:4px 8px;background:#fee2e2;color:#dc2626;border:none;border-radius:6px;font-size:.8rem;cursor:pointer;font-weight:700;">✕</button>'
               : '<span></span>');
    document.getElementById('adm-edit-rows').appendChild(row);
    admEditCalc();
}
function admEditCalc() {
    var grand = 0;
    document.querySelectorAll('#adm-edit-rows .adm-role-row').forEach(function(row) {
        var r = parseFloat(row.querySelector('input[name="role_rate[]"]').value)||0;
        var c = parseFloat(row.querySelector('input[name="role_count[]"]').value)||0;
        var sub = r*c; grand += sub;
        var s = row.querySelector('[id^="adm-edit-sub-"]');
        if(s) s.textContent = sub.toLocaleString('th-TH',{minimumFractionDigits:0})+' ฿';
    });
    document.getElementById('adm-edit-total').textContent =
        'รวมทั้งหมด: '+grand.toLocaleString('th-TH',{minimumFractionDigits:2})+' ฿';
}

function admSaveRateTemplate() {
    var aname = (document.getElementById('adm-name-input').value || '').trim();
    if (!aname) { alert('กรุณาระบุชื่อแอดมินก่อนบันทึก'); return; }
    var fd = new FormData();
    fd.append('action', 'save_admin_rate_template');
    fd.append('q', '/modules/7j/payroll.php');
    fd.append('admin_name', aname);
    document.querySelectorAll('#adm-rows-container input[name="role_name[]"]').forEach(function(el){ fd.append('role_name[]', el.value); });
    document.querySelectorAll('#adm-rows-container input[name="role_rate[]"]').forEach(function(el){ fd.append('role_rate[]', el.value); });
    fetch('?q=/modules/7j/payroll.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(data){ alert(data.msg || (data.ok ? '✅ บันทึกสำเร็จ' : '❌ เกิดข้อผิดพลาด')); })
        .catch(function(){ alert('❌ เกิดข้อผิดพลาด'); });
}
function admCloseModal() {
    document.getElementById('py-modal-admin').classList.remove('open');
}
function admAddRow(roleName, rate, count) {
    admRowCount++;
    var idx = admRowCount;
    var row = document.createElement('div');
    row.className = 'adm-role-row';
    row.id = 'adm-row-' + idx;
    row.innerHTML =
        '<input type="text"   name="role_name[]"  placeholder="หน้าที่ เช่น ผู้ดูแลระบบ" value="'+(roleName||'')+'" oninput="admCalcTotal()" required>' +
        '<input type="number" name="role_rate[]"  placeholder="ค่าจ้าง" min="0" step="0.01" value="'+(rate||'')+'" oninput="admCalcTotal()" style="text-align:right;">' +
        '<input type="number" name="role_count[]" placeholder="จำนวน"   min="0" step="0.01" value="'+(count||1)+'" oninput="admCalcTotal()" style="text-align:center;">' +
        '<span class="adm-sub" id="adm-sub-'+idx+'">0 ฿</span>' +
        (idx > 1 ? '<button type="button" onclick="admRemoveRow('+idx+')" style="padding:4px 8px;background:#fee2e2;color:#dc2626;border:none;border-radius:6px;font-size:.8rem;cursor:pointer;font-weight:700;">✕</button>'
                 : '<span></span>');
    document.getElementById('adm-rows-container').appendChild(row);
    admCalcTotal();
}
function admRemoveRow(idx) {
    var row = document.getElementById('adm-row-' + idx);
    if (row) row.remove();
    admCalcTotal();
}
function admCalcTotal() {
    var rows = document.querySelectorAll('#adm-rows-container .adm-role-row');
    var grand = 0;
    rows.forEach(function(row) {
        var rateEl  = row.querySelector('input[name="role_rate[]"]');
        var countEl = row.querySelector('input[name="role_count[]"]');
        var subEl   = row.querySelector('[id^="adm-sub-"]');
        var rate  = parseFloat(rateEl  ? rateEl.value  : 0) || 0;
        var count = parseFloat(countEl ? countEl.value : 0) || 0;
        var sub = rate * count;
        grand += sub;
        if (subEl) subEl.textContent = sub.toLocaleString('th-TH', {minimumFractionDigits:0}) + ' ฿';
    });
    document.getElementById('adm-total-display').textContent =
        'รวมทั้งหมด: ' + grand.toLocaleString('th-TH', {minimumFractionDigits:2}) + ' ฿';
}

// init tab state
pyShowTab('<?= $activeTab ?>');
</script>

<?php if ($autoSessions !== null): ?>
<span id="py-fetched-sessions" data-count="<?= $autoSessions ?>" style="display:none;"></span>
<?php endif; ?>
