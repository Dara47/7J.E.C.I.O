<?php
/* =====================================================================
 * 7J English Center — ปฏิทินตารางเรียน (class_calendar.php)
 * Month view: Sun–Sat | 24H | Admin only
 * ===================================================================== */

$CC_URL      = '/modules/7j/class_calendar.php';
$CC_DAYS_CAP = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$CC_DAYS_TH  = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
$CC_DAYS_EN  = ['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];
$CC_DAYS_SH  = ['อา','จ','อ','พ','พฤ','ศ','ส'];
$CC_MONTHS_TH= ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];

// ── Helpers ────────────────────────────────────────────────────────────
function ccF24(?string $t): string { return $t ? substr($t, 0, 5) : ''; }
function ccBgC(?string $n): string {
    $c = ['#ea580c','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2','#65a30d'];
    return $c[array_sum(array_map('ord', str_split($n ?? '?'))) % count($c)];
}
function ccIniC(?string $n): string {
    $i = '';
    foreach (preg_split('/\s+/', trim($n ?? '')) as $w) if ($w) $i .= mb_strtoupper(mb_substr($w, 0, 1));
    return mb_substr($i, 0, 2) ?: '?';
}
function ccIsPastDT(string $date, string $time, DateTime $now): bool {
    try { return (new DateTime($date . ' ' . $time, new DateTimeZone('Asia/Bangkok'))) <= $now; }
    catch (\Throwable $e) { return false; }
}
function ccEndMins(string $t): int {
    $p = explode(':', $t . ':00'); return (int)($p[0] ?? 0) * 60 + (int)($p[1] ?? 0);
}

// ── Current time ───────────────────────────────────────────────────────
$cc_today   = date('Y-m-d');
$cc_thNow   = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
$cc_nowMins = (int)$cc_thNow->format('H') * 60 + (int)$cc_thNow->format('i');

// ── Month navigation ───────────────────────────────────────────────────
$cc_year  = max(2026, min(2030, (int)($_GET['year']  ?? date('Y'))));
$cc_month = max(1,    min(12,   (int)($_GET['month'] ?? date('n'))));
$firstDay    = new DateTime("$cc_year-$cc_month-01", new DateTimeZone('Asia/Bangkok'));
$lastDay     = new DateTime($firstDay->format('Y-m-t'), new DateTimeZone('Asia/Bangkok'));
$cc_firstD   = $firstDay->format('Y-m-d');
$cc_lastD    = $lastDay->format('Y-m-d');
$daysInMonth = (int)$firstDay->format('t');

$prevMD   = (clone $firstDay)->modify('-1 month');
$nextMD   = (clone $firstDay)->modify('+1 month');
$cc_prevY = (int)$prevMD->format('Y'); $cc_prevM = (int)$prevMD->format('n');
$cc_nextY = (int)$nextMD->format('Y'); $cc_nextM = (int)$nextMD->format('n');
if ($cc_prevY < 2026) { $cc_prevY = 2026; $cc_prevM = 1; }
if ($cc_nextY > 2030) { $cc_nextY = 2030; $cc_nextM = 12; }
$cc_mLabel = $CC_MONTHS_TH[$cc_month] . ' ' . ($cc_year + 543);

// ── Calendar grid (array of weeks, each = 7 date strings or null) ──────
$firstDow  = (int)$firstDay->format('w');
$ccCalGrid = [];
$wk        = array_fill(0, $firstDow, null);
for ($d = 1; $d <= $daysInMonth; $d++) {
    $wk[] = sprintf('%04d-%02d-%02d', $cc_year, $cc_month, $d);
    if (count($wk) === 7) { $ccCalGrid[] = $wk; $wk = []; }
}
if (!empty($wk)) { while (count($wk) < 7) $wk[] = null; $ccCalGrid[] = $wk; }

// ── Teacher filter ─────────────────────────────────────────────────────
$cc_fTid = trim($_GET['tid'] ?? '');

// ── POST handlers ──────────────────────────────────────────────────────
$cc_msg = '';
$cc_act = trim($_POST['cc_act'] ?? '');

if ($cc_act === 'add_avail') {
    $vTid   = trim($_POST['cc_tid']  ?? '');
    $vType  = in_array($_POST['cc_type']??'',['weekly','specific_date']) ? $_POST['cc_type'] : 'specific_date';
    $vDay   = $vType === 'weekly'        ? strtolower(trim($_POST['cc_day']  ?? '')) : null;
    $vSdate = $vType === 'specific_date' ? trim($_POST['cc_date'] ?? '')             : null;
    $vTs    = trim($_POST['cc_ts'] ?? '');
    $vTe    = trim($_POST['cc_te'] ?? '');
    $vNote  = trim($_POST['cc_note'] ?? '') ?: null;

    if (!$vTid || !$vTs || !$vTe)
        $cc_msg = 'error|กรุณากรอกข้อมูลให้ครบถ้วน';
    elseif ($vTs >= $vTe)
        $cc_msg = 'error|เวลาเริ่มต้องน้อยกว่าเวลาจบ';
    elseif ($vType === 'specific_date' && !$vSdate)
        $cc_msg = 'error|กรุณาระบุวันที่';
    elseif ($vType === 'weekly' && !$vDay)
        $cc_msg = 'error|กรุณาเลือกวันในสัปดาห์';
    else {
        if ($vType === 'specific_date') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vSdate))
                $cc_msg = 'error|รูปแบบวันที่ไม่ถูกต้อง';
            elseif (ccIsPastDT($vSdate, $vTs, $cc_thNow))
                $cc_msg = 'error|วันเวลานี้ผ่านมาแล้ว ไม่สามารถบันทึกได้';
        }
        if (!$cc_msg) {
            $actualDay = $vType === 'specific_date' ? strtolower(date('l', strtotime($vSdate))) : $vDay;
            $connection2->prepare("INSERT INTO sevenj_teacher_availability (id,teacher_id,type,day,specific_date,start_time,end_time,note) VALUES (?,?,?,?,?,?,?,?)")
                ->execute(['cc_'.time().'_'.rand(1000,9999), $vTid, $vType, $actualDay, $vSdate, $vTs, $vTe, $vNote]);
            $cc_msg = 'success|เพิ่มช่วงเวลาว่างสำเร็จ';
            // Optional: immediately book a student if provided in the same form
            $avSid    = trim($_POST['cc_av_sid']    ?? '');
            $avSn     = trim($_POST['cc_av_sn']     ?? '');
            $avSc     = trim($_POST['cc_av_sc']     ?? '');
            $avCourse = trim($_POST['cc_av_course'] ?? '');
            if ($avSid && $avSn) {
                $tRow = $connection2->prepare("SELECT displayName FROM sevenj_teachers WHERE id=?");
                $tRow->execute([$vTid]); $avTn = $tRow->fetchColumn() ?: '';
                $stRow = $connection2->prepare("SELECT totalClasses FROM sevenj_students WHERE id=?");
                $stRow->execute([$avSid]); $avTotal = (int)($stRow->fetchColumn() ?? 0);
                $avBkDate = $vType === 'specific_date' ? $vSdate : null;
                $avBkType = $vType === 'specific_date' ? 'one_time' : 'weekly';
                $connection2->prepare("INSERT INTO sevenj_schedule (student_id,student_code,student_name,teacher_ref_id,teacher_name,schedule_type,day_of_week,time_start,time_end,specific_date,course,total_classes,completed_classes,status,note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,'active',?)")
                    ->execute([$avSid, $avSc, $avSn, $vTid, $avTn, $avBkType, $actualDay, $vTs, $vTe, $avBkDate, $avCourse, $avTotal, null]);
                $connection2->prepare("UPDATE sevenj_students SET totalClasses = GREATEST(0, totalClasses - 1) WHERE id=?")
                    ->execute([$avSid]);
                $cc_msg = 'success|เพิ่มช่วงเวลาว่างและจองนักเรียนสำเร็จ!';
            }
        }
    }

} elseif ($cc_act === 'edit_avail') {
    $vAvid  = trim($_POST['cc_avid'] ?? '');
    $vType  = in_array($_POST['cc_type']??'',['weekly','specific_date']) ? $_POST['cc_type'] : 'specific_date';
    $vDay   = $vType === 'weekly'        ? strtolower(trim($_POST['cc_day']  ?? '')) : null;
    $vSdate = $vType === 'specific_date' ? trim($_POST['cc_date'] ?? '')             : null;
    $vTs    = trim($_POST['cc_ts'] ?? '');
    $vTe    = trim($_POST['cc_te'] ?? '');
    $vNote  = trim($_POST['cc_note'] ?? '') ?: null;

    if (!$vAvid || !$vTs || !$vTe)
        $cc_msg = 'error|ข้อมูลไม่ครบ';
    elseif ($vTs >= $vTe)
        $cc_msg = 'error|เวลาเริ่มต้องน้อยกว่าเวลาจบ';
    else {
        if ($vType === 'specific_date' && $vSdate && ccIsPastDT($vSdate, $vTs, $cc_thNow))
            $cc_msg = 'error|วันเวลานี้ผ่านมาแล้ว ไม่สามารถบันทึกได้';
        if (!$cc_msg) {
            $actualDay = ($vType === 'specific_date' && $vSdate)
                       ? strtolower(date('l', strtotime($vSdate))) : $vDay;
            $connection2->prepare("UPDATE sevenj_teacher_availability SET type=?,day=?,specific_date=?,start_time=?,end_time=?,note=? WHERE id=?")
                ->execute([$vType, $actualDay, $vSdate, $vTs, $vTe, $vNote, $vAvid]);
            $cc_msg = 'success|แก้ไขช่วงเวลาว่างสำเร็จ';
        }
    }

} elseif ($cc_act === 'del_avail') {
    $vAvid = trim($_POST['cc_avid'] ?? '');
    if ($vAvid) { $connection2->prepare("DELETE FROM sevenj_teacher_availability WHERE id=?")->execute([$vAvid]); $cc_msg = 'success|ลบช่วงเวลาว่างสำเร็จ'; }

} elseif ($cc_act === 'book') {
    $vTid    = trim($_POST['cc_tid']    ?? '');
    $vTn     = trim($_POST['cc_tn']     ?? '');
    $vAvtype = trim($_POST['cc_avtype'] ?? 'specific_date');
    $vStype  = in_array($_POST['cc_stype']??'',['weekly','one_time']) ? $_POST['cc_stype'] : 'one_time';
    $vBday   = strtolower(trim($_POST['cc_bday']  ?? ''));
    $vBdate  = trim($_POST['cc_bdate'] ?? '');
    $vTs     = trim($_POST['cc_ts']    ?? '');
    $vTe     = trim($_POST['cc_te']    ?? '');
    $vSid    = trim($_POST['cc_sid']   ?? '');
    $vSn     = trim($_POST['cc_sn']    ?? '');
    $vSc     = trim($_POST['cc_sc']    ?? '');
    $vCourse = trim($_POST['cc_course']?? '');
    $vTotal  = (int)($_POST['cc_total'] ?? 0);
    $vBnote  = trim($_POST['cc_bnote'] ?? '') ?: null;

    if (!$vTn || !$vSn || !$vTs || !$vTe)
        $cc_msg = 'error|กรุณากรอกข้อมูลให้ครบถ้วน';
    elseif ($vStype === 'one_time' && !$vBdate)
        $cc_msg = 'error|กรุณาระบุวันที่';
    elseif ($vStype === 'weekly' && !$vBday)
        $cc_msg = 'error|ระบบผิดพลาด: ไม่พบวันในสัปดาห์';
    else {
        if ($vStype === 'one_time' && $vBdate && $vBday) {
            $actualDow = strtolower(date('l', strtotime($vBdate)));
            if ($actualDow !== $vBday) {
                $actIdx = array_search(ucfirst($actualDow), $CC_DAYS_CAP);
                $expIdx = array_search(ucfirst($vBday), $CC_DAYS_CAP);
                $actTH  = $actIdx !== false ? $CC_DAYS_TH[$actIdx] : $actualDow;
                $expTH  = $expIdx !== false ? $CC_DAYS_TH[$expIdx] : $vBday;
                $cc_msg = 'error|วันที่ '.$vBdate.' ตรงกับวัน'.$actTH.' ไม่ใช่วัน'.$expTH.' — บันทึกไม่ได้';
            }
        }
        if (!$cc_msg) {
            if ($vStype === 'one_time' && $vBdate) {
                if (ccIsPastDT($vBdate, $vTe, $cc_thNow))
                    $cc_msg = 'error|วันเวลานี้ผ่านมาแล้ว ไม่สามารถบันทึกได้';
            } elseif ($vStype === 'weekly' && $vBday) {
                $dayIdxMap  = array_flip($CC_DAYS_EN);
                $slotDI     = $dayIdxMap[$vBday]   ?? -1;
                $todayDI    = (int)date('w');
                if ($slotDI >= 0 && $slotDI < $todayDI)
                    $cc_msg = 'error|วัน'.($CC_DAYS_TH[$slotDI] ?? '').' ในสัปดาห์นี้ผ่านมาแล้ว';
                elseif ($slotDI === $todayDI && $cc_nowMins > ccEndMins($vTe))
                    $cc_msg = 'error|ช่วงเวลานี้ผ่านมาแล้วสำหรับวันนี้';
            }
        }
        if (!$cc_msg && $vSid) {
            $chkDup = $connection2->prepare(
                "SELECT COUNT(*) FROM sevenj_schedule WHERE student_id=? AND teacher_ref_id=? AND schedule_type=? AND time_start=? AND time_end=? AND status='active' AND "
                . ($vStype === 'weekly' ? "day_of_week=?" : "specific_date=?")
            );
            $chkDup->execute([$vSid, $vTid ?: '', $vStype, $vTs, $vTe, $vStype === 'weekly' ? $vBday : ($vBdate ?: null)]);
            if ($chkDup->fetchColumn() > 0)
                $cc_msg = 'error|นักเรียนนี้จองช่วงเวลานี้กับครูนี้ไปแล้ว';
        }
        if (!$cc_msg) {
            if ($vSid) {
                $stRow = $connection2->prepare("SELECT totalClasses FROM sevenj_students WHERE id=?");
                $stRow->execute([$vSid]);
                $vTotal = (int)($stRow->fetchColumn() ?? 0);
            }
            $connection2->prepare("INSERT INTO sevenj_schedule (student_id,student_code,student_name,teacher_ref_id,teacher_name,schedule_type,day_of_week,time_start,time_end,specific_date,course,total_classes,completed_classes,status,note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,'active',?)")
                ->execute([$vSid ?: null, $vSc, $vSn, $vTid ?: null, $vTn, $vStype, $vBday, $vTs, $vTe, $vBdate ?: null, $vCourse, $vTotal, $vBnote]);
            if ($vSid) {
                $connection2->prepare("UPDATE sevenj_students SET totalClasses = GREATEST(0, totalClasses - 1) WHERE id=?")
                    ->execute([$vSid]);
            }
            $cc_msg = 'success|เพิ่มนักเรียนสำเร็จ!';
        }
    }

} elseif ($cc_act === 'del_book') {
    $vBid = (int)($_POST['cc_bid'] ?? 0);
    if ($vBid) {
        $r = $connection2->prepare("SELECT student_id FROM sevenj_schedule WHERE id=?"); $r->execute([$vBid]);
        $delSid = $r->fetchColumn();
        $connection2->query("DELETE FROM sevenj_schedule WHERE id=$vBid");
        if ($delSid) {
            $connection2->prepare("UPDATE sevenj_students SET totalClasses = totalClasses + 1 WHERE id=?")
                ->execute([$delSid]);
        }
        $cc_msg = 'success|ลบการจองสำเร็จ';
    }
}

// ── Shared data fetch ──────────────────────────────────────────────────
$ccTeachers = $connection2->query("SELECT id,displayName,teacherCode FROM sevenj_teachers WHERE status='active' ORDER BY displayName")->fetchAll(PDO::FETCH_ASSOC);
$ccStudents = $connection2->query("SELECT id,displayName,studentCode,totalClasses FROM sevenj_students WHERE status='active' ORDER BY displayName")->fetchAll(PDO::FETCH_ASSOC);

// Availabilities for this month (weekly always included; specific_date only if in this month)
$ccAvSql = "SELECT a.*,t.displayName AS tname,t.teacherCode AS tcode FROM sevenj_teacher_availability a LEFT JOIN sevenj_teachers t ON t.id=a.teacher_id WHERE (a.type='weekly' OR (a.type='specific_date' AND a.specific_date BETWEEN ? AND ?))";
$ccAvParams = [$cc_firstD, $cc_lastD];
if ($cc_fTid) { $ccAvSql .= ' AND a.teacher_id=?'; $ccAvParams[] = $cc_fTid; }
$stAv = $connection2->prepare($ccAvSql . ' ORDER BY a.teacher_id,a.start_time');
$stAv->execute($ccAvParams);
$ccAvRows = $stAv->fetchAll(PDO::FETCH_ASSOC);

// Active bookings for this month
$ccBkSql = "SELECT s.*,st.displayName AS sname,st.studentCode AS scode FROM sevenj_schedule s LEFT JOIN sevenj_students st ON st.id=s.student_id WHERE s.status='active' AND (s.total_classes=0 OR s.completed_classes<s.total_classes) AND (s.schedule_type='weekly' OR (s.schedule_type='one_time' AND s.specific_date BETWEEN ? AND ?))";
$ccBkParams = [$cc_firstD, $cc_lastD];
if ($cc_fTid) { $ccBkSql .= ' AND s.teacher_ref_id=?'; $ccBkParams[] = $cc_fTid; }
$stBk = $connection2->prepare($ccBkSql);
$stBk->execute($ccBkParams);
$ccBkRows = $stBk->fetchAll(PDO::FETCH_ASSOC);

// Build booking lookup map: key = teacher|day_or_date|ts|te
$ccBkMap = [];
foreach ($ccBkRows as $bk) {
    $dk  = $bk['schedule_type'] === 'one_time' ? '__d__'.($bk['specific_date']??'') : strtolower($bk['day_of_week']??'');
    $key = ($bk['teacher_ref_id']??'').'|'.$dk.'|'.ccF24($bk['time_start']).'|'.ccF24($bk['time_end']);
    $ccBkMap[$key][] = $bk;
}

// Build all non-expired slots as flat sorted array (ซ่อนช่วงเวลาที่ผ่านไปแล้ว)
$ccAllFlat = [];
foreach ($ccAvRows as $av) {
    $tid3 = $av['teacher_id'];
    if ($av['type'] === 'weekly') {
        $dayL   = strtolower($av['day'] ?? '');
        $dayDow = array_search(ucfirst($dayL), $CC_DAYS_CAP);
        if ($dayDow === false) continue;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dt    = sprintf('%04d-%02d-%02d', $cc_year, $cc_month, $d);
            if ((int)date('w', strtotime($dt)) !== $dayDow) continue;
            $endM  = ccEndMins($av['end_time'] ?? '00:00');
            $isExp = $dt < $cc_today || ($dt === $cc_today && $cc_nowMins > $endM);
            if ($isExp) continue; // ช่วงเวลาผ่านไปแล้ว — ไม่แสดง
            $bkKey = $tid3.'|'.$dayL.'|'.ccF24($av['start_time']).'|'.ccF24($av['end_time']);
            $ccAllFlat[] = [
                'avid'=>$av['id'], 'tid'=>$tid3, 'tname'=>$av['tname']??'', 'tcode'=>$av['tcode']??'',
                'ts'=>ccF24($av['start_time']), 'te'=>ccF24($av['end_time']),
                'note'=>$av['note']??'', 'avtype'=>$av['type'], 'day'=>$dayL, 'date'=>$dt,
                'exp'=>false, 'bookings'=>$ccBkMap[$bkKey] ?? [],
            ];
        }
    } else {
        $sdate3 = $av['specific_date'] ?? '';
        if (!$sdate3 || $sdate3 < $cc_firstD || $sdate3 > $cc_lastD) continue;
        $dayL  = strtolower(date('l', strtotime($sdate3)));
        $endM  = ccEndMins($av['end_time'] ?? '00:00');
        $isExp = $sdate3 < $cc_today || ($sdate3 === $cc_today && $cc_nowMins > $endM);
        if ($isExp) continue; // ช่วงเวลาผ่านไปแล้ว — ไม่แสดง
        $bkKey = $tid3.'|__d__'.$sdate3.'|'.ccF24($av['start_time']).'|'.ccF24($av['end_time']);
        $ccAllFlat[] = [
            'avid'=>$av['id'], 'tid'=>$tid3, 'tname'=>$av['tname']??'', 'tcode'=>$av['tcode']??'',
            'ts'=>ccF24($av['start_time']), 'te'=>ccF24($av['end_time']),
            'note'=>$av['note']??'', 'avtype'=>$av['type'], 'day'=>$dayL, 'date'=>$sdate3,
            'exp'=>false, 'bookings'=>$ccBkMap[$bkKey] ?? [],
        ];
    }
}
// Sort by date → teacher → time
usort($ccAllFlat, fn($a,$b) => strcmp($a['date'].$a['tid'].$a['ts'], $b['date'].$b['tid'].$b['ts']));

// Stats (from full non-expired set)
$ccTotAll = count($ccAllFlat);
$ccTotFr  = $ccTotBk = 0;
foreach ($ccAllFlat as $s) {
    if (!empty($s['bookings'])) $ccTotBk++;
    else $ccTotFr++;
}

// Pagination: 60 slots per page
$cc_perpage  = 60;
$cc_page     = max(1, (int)($_GET['page'] ?? 1));
$cc_pages    = $ccTotAll > 0 ? (int)ceil($ccTotAll / $cc_perpage) : 1;
$cc_page     = min($cc_page, $cc_pages);
$cc_pgStart  = ($cc_page - 1) * $cc_perpage;
$cc_pgEnd    = min($cc_pgStart + $cc_perpage, $ccTotAll);
$ccPageSlots = array_slice($ccAllFlat, $cc_pgStart, $cc_perpage);

// Build per-date view from this page's 60 slots only
$ccDaySlots = [];
foreach ($ccPageSlots as $s) {
    $ccDaySlots[$s['date']][] = $s;
}

// JS maps (only visible page's slots)
$ccAvJsMap = [];
foreach ($ccPageSlots as $s)
    if (!isset($ccAvJsMap[$s['avid']]))
        $ccAvJsMap[$s['avid']] = ['avid'=>$s['avid'],'tid'=>$s['tid'],'tname'=>$s['tname'],'tcode'=>$s['tcode'],'ts'=>$s['ts'],'te'=>$s['te'],'note'=>$s['note'],'avtype'=>$s['avtype'],'day'=>$s['day'],'date'=>$s['date']];
$ccBkJsMap = [];
foreach ($ccBkRows as $bk)
    $ccBkJsMap[$bk['id']] = ['bid'=>$bk['id'],'sname'=>$bk['sname']??$bk['student_name']??'','scode'=>$bk['scode']??$bk['student_code']??'','course'=>$bk['course']??'','total'=>(int)$bk['total_classes'],'note'=>$bk['note']??''];

[$cc_alertT, $cc_alertX] = $cc_msg ? explode('|', $cc_msg, 2) : ['', ''];
$cc_pageBase = '?q='.$CC_URL;
$cc_pageUrl  = $cc_pageBase.($cc_fTid ? '&tid='.urlencode($cc_fTid) : '').'&year='.$cc_year.'&month='.$cc_month;
?>
<?php require_once __DIR__.'/_theme.php'; ?>
<style>
/* ══ Layout ══════════════════════════════════════════════════════════ */
.cc-wrap{font-family:var(--g-font,'Sarabun',sans-serif);}
.cc-head{display:flex;align-items:center;gap:10px;margin-bottom:.9rem;flex-wrap:wrap;}
.cc-title{font-size:1.2rem;font-weight:800;color:#1A2A5E;}
.cc-nav{display:flex;align-items:center;gap:5px;flex-wrap:wrap;}
.cc-nav-a{padding:5px 13px;border-radius:8px;border:1.5px solid #d1d5db;background:#fff;font-weight:700;font-size:.8rem;cursor:pointer;text-decoration:none;color:#374151;transition:all .12s;white-space:nowrap;}
.cc-nav-a:hover{border-color:#2563eb;color:#2563eb;}
.cc-nav-a.today-btn{background:#f1f5f9;}
.cc-wlabel{font-weight:800;font-size:.95rem;color:#1e40af;padding:0 4px;white-space:nowrap;}
.cc-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:.7rem;}
.cc-sel{padding:6px 10px;border-radius:8px;border:1px solid #d1d5db;font-size:.83rem;background:#fff;cursor:pointer;}
.cc-btn-add{padding:7px 16px;border-radius:8px;background:#2563eb;color:#fff;border:none;font-weight:700;font-size:.83rem;cursor:pointer;white-space:nowrap;}
.cc-btn-add:hover{background:#1d4ed8;}
/* ══ Stats ═══════════════════════════════════════════════════════════ */
.cc-stats{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:.75rem;}
.cc-stat{padding:3px 11px;border-radius:20px;font-size:.76rem;font-weight:700;}
.cc-s-tot{background:#dbeafe;color:#1e40af;}.cc-s-free{background:#dcfce7;color:#166534;}
.cc-s-busy{background:#fee2e2;color:#991b1b;}
/* ══ Pagination ══════════════════════════════════════════════════════ */
.cc-pgn{display:flex;align-items:center;gap:8px;padding:.85rem 0;justify-content:center;flex-wrap:wrap;}
.cc-pgn-btn{padding:5px 14px;border-radius:8px;border:1.5px solid #d1d5db;background:#fff;font-weight:700;font-size:.82rem;cursor:pointer;text-decoration:none;color:#374151;transition:all .12s;}
.cc-pgn-btn:hover{border-color:#2563eb;color:#2563eb;background:#eff6ff;}
.cc-pgn-info{font-weight:700;font-size:.83rem;color:#1e40af;padding:5px 10px;background:#dbeafe;border-radius:8px;}
/* ══ Month Calendar ══════════════════════════════════════════════════ */
.cc-gwrap{overflow-x:auto;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.09);border:1px solid #e5e7eb;}
table.cc-g{border-collapse:collapse;width:100%;min-width:700px;background:#fff;table-layout:fixed;}
table.cc-g th,table.cc-g td{border:1px solid #e5e7eb;}
thead.cc-th th{background:#1A2A5E;color:#fff;text-align:center;padding:8px 4px;font-size:.82rem;font-weight:700;width:14.28%;}
thead.cc-th th.cc-th-sun{background:#b91c1c;}
thead.cc-th th.cc-th-sat{background:#1d4ed8;}
/* ── Date cells ──────────────────────────────────────────────────── */
.cc-cal-td{vertical-align:top;padding:3px 3px 4px;min-height:90px;background:#fff;position:relative;}
.cc-cal-empty{background:#f9fafb;}
.cc-cal-today{background:#fffbeb !important;}
.cc-dnum-wrap{display:flex;justify-content:flex-end;margin-bottom:2px;}
.cc-cal-dnum{font-size:.72rem;font-weight:700;color:#374151;padding:1px 3px;line-height:1.3;}
.cc-cal-dnum-today{background:#ea580c;color:#fff;border-radius:50%;width:20px;height:20px;line-height:20px;text-align:center;font-size:.69rem;font-weight:800;display:inline-block;}
/* ── Slot cards ──────────────────────────────────────────────────── */
.cc-slot{border-radius:6px;padding:4px 5px;margin-bottom:3px;font-size:.69rem;line-height:1.35;border:1px solid transparent;overflow:hidden;}
.cc-sl-free{background:#dcfce7;border-color:#86efac;}
.cc-sl-busy{background:#fef2f2;border-color:#fca5a5;}
.cc-sl-exp{background:#f3f4f6;border-color:#e5e7eb;opacity:.6;}
.cc-sl-hdr{display:flex;align-items:center;gap:3px;margin-bottom:1px;flex-wrap:wrap;}
.cc-sltime{font-family:monospace;font-weight:700;color:#374151;font-size:.66rem;display:block;margin-bottom:1px;}
.cc-av{width:16px;height:16px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.47rem;font-weight:800;color:#fff;flex-shrink:0;}
.cc-tn{font-weight:700;color:#1e40af;font-size:.7rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:90px;}
.cc-tc{color:#64748b;font-size:.6rem;}
.cc-stu{color:#991b1b;font-weight:600;font-size:.67rem;margin-top:1px;}
.cc-stu-code{color:#6b7280;font-size:.6rem;}
.cc-nl{color:#6b7280;font-style:italic;font-size:.6rem;margin-top:1px;}
.cc-acts{display:flex;gap:2px;margin-top:3px;flex-wrap:wrap;}
.cc-ab{padding:2px 5px;border-radius:4px;border:1px solid;font-size:.62rem;font-weight:600;cursor:pointer;background:#fff;white-space:nowrap;line-height:1.4;}
.cc-ab-bk{border-color:#16a34a;color:#16a34a;}.cc-ab-bk:hover{background:#dcfce7;}
.cc-ab-ed{border-color:#2563eb;color:#2563eb;}.cc-ab-ed:hover{background:#dbeafe;}
.cc-ab-dl{border-color:#dc2626;color:#dc2626;}.cc-ab-dl:hover{background:#fee2e2;}
.cc-ab-cf{border-color:#9ca3af;color:#9ca3af;}.cc-ab-cf:hover{background:#f3f4f6;}
.cc-add-btn{width:100%;padding:2px;background:none;border:1.5px dashed #d1d5db;border-radius:5px;color:#9ca3af;font-size:.67rem;cursor:pointer;margin-top:2px;line-height:1.5;display:none;}
.cc-cal-td:hover .cc-add-btn{display:block;}
.cc-add-btn:hover{border-color:#2563eb;color:#2563eb;background:#eff6ff;}
/* ══ Modal ═══════════════════════════════════════════════════════════ */
.cc-mbg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;}
.cc-mbg.open{display:flex;}
.cc-mdl{background:#fff;border-radius:14px;width:100%;max-width:460px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);}
.cc-mhdr{display:flex;align-items:center;justify-content:space-between;padding:13px 17px 9px;border-bottom:1px solid #f3f4f6;}
.cc-mhdr h3{font-size:.97rem;font-weight:800;color:#1A2A5E;margin:0;}
.cc-mclose{background:none;border:none;font-size:1.2rem;cursor:pointer;color:#9ca3af;padding:0;line-height:1;}
.cc-mclose:hover{color:#374151;}
.cc-mbody{padding:13px 17px;}
.cc-mfoot{display:flex;gap:8px;justify-content:flex-end;padding:10px 17px 15px;border-top:1px solid #f3f4f6;}
.cc-frow{margin-bottom:11px;}
.cc-frow label{display:block;font-size:.78rem;font-weight:700;color:#374151;margin-bottom:3px;}
.cc-frow input,.cc-frow select,.cc-frow textarea{width:100%;padding:7px 9px;border-radius:8px;border:1px solid #d1d5db;font-size:.84rem;font-family:inherit;box-sizing:border-box;background:#fff;}
.cc-frow input:focus,.cc-frow select:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.cc-radrow{display:flex;gap:12px;padding:3px 0;}
.cc-radrow label{display:flex;align-items:center;gap:5px;cursor:pointer;font-size:.83rem;font-weight:600;color:#374151;}
.cc-radrow input[type=radio]{width:auto;accent-color:#2563eb;}
.cc-info-box{padding:7px 10px;background:#f1f5f9;border-radius:8px;font-weight:700;font-size:.84rem;color:#1e3a8a;}
.cc-warn-box{display:none;padding:7px 10px;border-radius:6px;background:#fef3c7;color:#92400e;font-size:.8rem;font-weight:600;margin-bottom:8px;}
.cc-err-box{display:none;padding:7px 10px;border-radius:6px;background:#fee2e2;color:#991b1b;font-size:.8rem;font-weight:600;margin-bottom:8px;}
.cc-dow-hint{font-size:.76rem;font-weight:600;margin-top:3px;}
.cc-btn-cancel{padding:7px 17px;border-radius:8px;border:1.5px solid #d1d5db;background:#fff;font-size:.84rem;font-weight:600;cursor:pointer;color:#374151;}
.cc-btn-cancel:hover{border-color:#9ca3af;}
.cc-btn-submit{padding:7px 19px;border-radius:8px;background:#2563eb;color:#fff;border:none;font-size:.84rem;font-weight:700;cursor:pointer;}
.cc-btn-submit:hover{background:#1d4ed8;}
.cc-btn-danger{background:#dc2626;}.cc-btn-danger:hover{background:#b91c1c;}
.cc-btn-green{background:#16a34a;}.cc-btn-green:hover{background:#15803d;}
.cc-alert{padding:9px 15px;border-radius:8px;margin-bottom:.9rem;font-size:.87rem;font-weight:600;display:flex;align-items:center;gap:7px;}
.cc-alert-ok{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.cc-alert-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.cc-badge-free{display:inline-block;font-size:.58rem;font-weight:700;padding:1px 5px;border-radius:4px;background:#dcfce7;color:#166534;vertical-align:middle;}
.cc-badge-busy{display:inline-block;font-size:.58rem;font-weight:700;padding:1px 5px;border-radius:4px;background:#fee2e2;color:#991b1b;vertical-align:middle;}
.cc-stu-rem{background:#fef3c7;color:#92400e;font-size:.62rem;padding:1px 5px;border-radius:4px;font-weight:700;margin-left:2px;}
.cc-jump{display:flex;gap:4px;align-items:center;}
.cc-jump select{padding:4px 6px;border-radius:7px;border:1px solid #d1d5db;font-size:12px;cursor:pointer;}
/* teacher autocomplete */
#cc-teacher-dd{display:none;position:absolute;top:calc(100% + 4px);left:0;min-width:240px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 6px 20px rgba(0,0,0,.13);z-index:300;max-height:220px;overflow-y:auto;}
#cc-teacher-dd .cc-td-item{padding:8px 14px;cursor:pointer;font-size:.83rem;border-bottom:1px solid #f3f4f6;transition:background .08s;}
#cc-teacher-dd .cc-td-item:hover{background:#eff6ff;}
#cc-teacher-dd .cc-td-item.cc-td-active{background:#dbeafe;font-weight:700;color:#1e40af;}
#cc-teacher-dd .cc-td-all{padding:7px 14px;cursor:pointer;font-size:.8rem;border-bottom:2px solid #e5e7eb;color:#6b7280;font-weight:700;}
#cc-teacher-dd .cc-td-all:hover{background:#f9fafb;}
.cc-teacher-chip{display:flex;align-items:center;gap:5px;background:#dbeafe;color:#1e40af;border-radius:20px;padding:4px 10px 4px 12px;font-size:.78rem;font-weight:700;white-space:nowrap;}
.cc-chip-x{color:#dc2626;text-decoration:none;font-weight:800;padding:0 3px;font-size:.85rem;}
.cc-chip-x:hover{color:#991b1b;}
#cc-bk-stu-list div:hover{background:#f0f9ff;}
</style>

<div class="cc-wrap">

<?php if ($cc_alertT): ?>
<div class="cc-alert <?= $cc_alertT === 'success' ? 'cc-alert-ok' : 'cc-alert-err' ?>">
  <?= $cc_alertT === 'success' ? '✅' : '❌' ?> <?= htmlspecialchars($cc_alertX) ?>
</div>
<?php endif; ?>

<!-- Header -->
<div class="cc-head">
  <div class="cc-title">📅 ปฏิทินตารางเรียน</div>
  <div class="cc-nav">
    <a href="<?= $cc_pageBase.($cc_fTid?'&tid='.urlencode($cc_fTid):'').'&year='.$cc_prevY.'&month='.$cc_prevM ?>" class="cc-nav-a">← ก่อน</a>
    <span class="cc-wlabel"><?= htmlspecialchars($cc_mLabel) ?></span>
    <a href="<?= $cc_pageBase.($cc_fTid?'&tid='.urlencode($cc_fTid):'').'&year='.$cc_nextY.'&month='.$cc_nextM ?>" class="cc-nav-a">ถัดไป →</a>
    <a href="<?= $cc_pageBase.($cc_fTid?'&tid='.urlencode($cc_fTid):'') ?>" class="cc-nav-a today-btn">เดือนนี้</a>
  </div>
  <div class="cc-jump">
    <select id="cc-jump-y" onchange="ccJumpDate()">
      <?php for($y=2026;$y<=2030;$y++): ?><option value="<?=$y?>" <?= $cc_year===$y?'selected':'' ?>><?=$y?></option><?php endfor; ?>
    </select>
    <select id="cc-jump-m" onchange="ccJumpDate()">
      <?php $months=['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.']; foreach($months as $mi=>$mn): ?><option value="<?=$mi+1?>" <?= $cc_month===$mi+1?'selected':'' ?>><?=$mn?></option><?php endforeach; ?>
    </select>
  </div>
</div>

<!-- Toolbar -->
<?php
$cc_fTname = '';
foreach ($ccTeachers as $t) { if ($t['id'] === $cc_fTid) { $cc_fTname = $t['displayName']; break; } }
?>
<div class="cc-toolbar">
  <div style="position:relative;">
    <input type="text" id="cc-tsearch" placeholder="🔍 ค้นหาครู..." autocomplete="off"
           oninput="ccSearchTeacher(this.value)"
           onfocus="ccSearchTeacher(this.value)"
           onkeydown="ccTeacherKeyNav(event)"
           value="<?= htmlspecialchars($cc_fTname) ?>"
           style="padding:6px 10px;border-radius:8px;border:1.5px solid <?= $cc_fTid ? '#2563eb' : '#d1d5db' ?>;font-size:13px;width:170px;box-sizing:border-box;">
    <div id="cc-teacher-dd"></div>
  </div>
  <?php if ($cc_fTid): ?>
  <div class="cc-teacher-chip">
    <span>📋 <?= htmlspecialchars($cc_fTname) ?></span>
    <a href="<?= $cc_pageBase.'&year='.$cc_year.'&month='.$cc_month ?>" class="cc-chip-x" title="ดูครูทั้งหมด">✕</a>
  </div>
  <?php endif; ?>
  <button class="cc-btn-add" onclick="ccOpenAdd(-1,'','')">+ เพิ่มช่วงว่างครู</button>
</div>

<!-- Stats -->
<div class="cc-stats">
  <span class="cc-stat cc-s-tot">ทั้งหมด: <?= $ccTotAll ?></span>
  <span class="cc-stat cc-s-free">ว่าง: <?= $ccTotFr ?></span>
  <span class="cc-stat cc-s-busy">จอง: <?= $ccTotBk ?></span>
  <?php if ($cc_pages > 1): ?>
  <span class="cc-stat" style="background:#fef3c7;color:#92400e;">หน้า <?= $cc_page ?>/<?= $cc_pages ?> &middot; แถวที่ <?= $cc_pgStart+1 ?>–<?= $cc_pgEnd ?></span>
  <?php endif; ?>
</div>

<!-- Monthly Calendar Grid -->
<div class="cc-gwrap">
<table class="cc-g">
<thead class="cc-th">
  <tr>
    <th class="cc-th-sun">อา</th>
    <th>จ</th><th>อ</th><th>พ</th><th>พฤ</th><th>ศ</th>
    <th class="cc-th-sat">ส</th>
  </tr>
</thead>
<tbody>
<?php foreach ($ccCalGrid as $wkRow): ?>
<tr>
  <?php foreach ($wkRow as $colDate): ?>
  <?php if (!$colDate): ?>
  <td class="cc-cal-td cc-cal-empty"></td>
  <?php else:
    $isToday  = ($colDate === $cc_today);
    $daySlots = $ccDaySlots[$colDate] ?? [];
    $dayNum   = (int)substr($colDate, 8);
    $esc_date = htmlspecialchars($colDate);
  ?>
  <td class="cc-cal-td<?= $isToday ? ' cc-cal-today' : '' ?>">
    <div class="cc-dnum-wrap">
      <?php if ($isToday): ?>
        <span class="cc-cal-dnum-today"><?= $dayNum ?></span>
      <?php else: ?>
        <span class="cc-cal-dnum"><?= $dayNum ?></span>
      <?php endif; ?>
    </div>
    <?php foreach ($daySlots as $s):
      $isBusy   = !empty($s['bookings']);
      $cls      = $s['exp'] ? 'cc-sl-exp' : ($isBusy ? 'cc-sl-busy' : 'cc-sl-free');
      $bg       = ccBgC($s['tname']);
      $ini      = htmlspecialchars(ccIniC($s['tname']));
      $esc_avid = htmlspecialchars($s['avid']);
    ?>
    <div class="cc-slot <?= $cls ?>">
      <!-- Teacher name header (shown on top of each calendar slot frame) -->
      <div class="cc-sl-hdr">
        <span class="cc-av" style="background:<?= $bg ?>"><?= $ini ?></span>
        <span class="cc-tn" title="<?= htmlspecialchars($s['tname']) ?>"><?= htmlspecialchars($s['tname']) ?></span>
        <?php if ($s['tcode']): ?><span class="cc-tc">(<?= htmlspecialchars($s['tcode']) ?>)</span><?php endif; ?>
      </div>
      <span class="cc-sltime"><?= $s['ts'] ?>–<?= $s['te'] ?></span>
      <!-- Status badge -->
      <?php if (!$s['exp']): ?>
        <?php if ($isBusy): ?><span class="cc-badge-busy">🔴 จอง</span><?php else: ?><span class="cc-badge-free">🟢 ว่าง</span><?php endif; ?>
      <?php endif; ?>
      <!-- Student bookings -->
      <?php foreach ($s['bookings'] as $bk):
        $sname = $bk['sname'] ?? $bk['student_name'] ?? '';
        $scode = $bk['scode'] ?? $bk['student_code'] ?? '';
      ?>
      <div class="cc-stu" style="display:flex;align-items:center;flex-wrap:wrap;gap:2px;margin-top:2px;">
        🎓 <?= htmlspecialchars($sname) ?>
        <?php if ($scode): ?><span class="cc-stu-code">(<?= htmlspecialchars($scode) ?>)</span><?php endif; ?>
        <span class="cc-stu-rem"><?= (int)$bk['total_classes'] ?> คาบ</span>
        <?php if (!$s['exp']): ?>
        <button class="cc-ab cc-ab-ed" onclick="ccOpenEditBk(<?= (int)$bk['id'] ?>,'<?= $esc_avid ?>','<?= $esc_date ?>')">✏️</button>
        <button class="cc-ab cc-ab-dl" onclick="ccDelBk(<?= (int)$bk['id'] ?>)">🗑️</button>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if ($s['note']): ?><div class="cc-nl">📝 <?= htmlspecialchars($s['note']) ?></div><?php endif; ?>
      <!-- Slot actions -->
      <?php if (!$s['exp']): ?>
      <div class="cc-acts">
        <button class="cc-ab cc-ab-bk" onclick="ccOpenBook('<?= $esc_avid ?>','<?= $esc_date ?>')">+ เพิ่มนักเรียน</button>
        <button class="cc-ab cc-ab-cf" onclick="ccOpenEditAv('<?= $esc_avid ?>')">⚙️</button>
        <button class="cc-ab cc-ab-dl" onclick="ccDelAv('<?= $esc_avid ?>')">✕</button>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <button class="cc-add-btn" onclick="ccOpenAdd(-1,'<?= $esc_date ?>','')">+ เพิ่มช่วงว่าง</button>
  </td>
  <?php endif; ?>
  <?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php if ($cc_pages > 1):
  $cc_pgBase = $cc_pageBase.($cc_fTid ? '&tid='.urlencode($cc_fTid) : '').'&year='.$cc_year.'&month='.$cc_month;
?>
<div class="cc-pgn">
  <?php if ($cc_page > 1): ?>
  <a href="<?= $cc_pgBase.'&page=1' ?>" class="cc-pgn-btn">«</a>
  <a href="<?= $cc_pgBase.'&page='.($cc_page-1) ?>" class="cc-pgn-btn">‹ ก่อนหน้า</a>
  <?php endif; ?>
  <span class="cc-pgn-info">หน้า <?= $cc_page ?> / <?= $cc_pages ?> <span style="color:#6b7280;font-weight:400;">(แถวที่ <?= $cc_pgStart+1 ?>–<?= $cc_pgEnd ?> จาก <?= $ccTotAll ?> ช่วงเวลา)</span></span>
  <?php if ($cc_page < $cc_pages): ?>
  <a href="<?= $cc_pgBase.'&page='.($cc_page+1) ?>" class="cc-pgn-btn">ถัดไป ›</a>
  <a href="<?= $cc_pgBase.'&page='.$cc_pages ?>" class="cc-pgn-btn">»</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══ Modal: Add/Edit Availability ══════════════════════════════════ -->
<div id="cc-modal-av" class="cc-mbg">
<div class="cc-mdl">
  <div class="cc-mhdr">
    <h3 id="cc-av-title">เพิ่มช่วงเวลาว่างครู</h3>
    <button class="cc-mclose" onclick="ccModal('cc-modal-av',0)">✕</button>
  </div>
  <form method="post" id="cc-form-av" onsubmit="return ccValidateAv()">
    <div class="cc-mbody">
      <input type="hidden" name="q"     value="<?= htmlspecialchars($CC_URL) ?>">
      <input type="hidden" name="year"  value="<?= $cc_year ?>">
      <input type="hidden" name="month" value="<?= $cc_month ?>">
      <?php if ($cc_fTid): ?><input type="hidden" name="tid" value="<?= htmlspecialchars($cc_fTid) ?>"><?php endif; ?>
      <input type="hidden" name="cc_act"  id="cc-av-act"  value="add_avail">
      <input type="hidden" name="cc_avid" id="cc-av-avid" value="">
      <!-- Teacher -->
      <div class="cc-frow">
        <label>ครู *</label>
        <select name="cc_tid" id="cc-av-tid" required>
          <option value="">-- เลือกครู --</option>
          <?php foreach ($ccTeachers as $t): ?>
          <option value="<?= htmlspecialchars($t['id']) ?>"><?= htmlspecialchars($t['displayName']) ?> (<?= htmlspecialchars($t['teacherCode']??'') ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <!-- Type -->
      <div class="cc-frow">
        <label>ประเภท</label>
        <div class="cc-radrow">
          <label><input type="radio" name="cc_type" value="specific_date" id="cc-av-type-sd" checked onchange="ccAvTypeChange()"> วันที่เฉพาะ</label>
          <label><input type="radio" name="cc_type" value="weekly"        id="cc-av-type-wk"       onchange="ccAvTypeChange()"> ประจำสัปดาห์</label>
        </div>
      </div>
      <!-- Date -->
      <div class="cc-frow" id="cc-av-date-row">
        <label>วันที่ *</label>
        <input type="date" name="cc_date" id="cc-av-date" min="<?= $cc_today ?>" oninput="ccAvDateChange()">
        <div class="cc-dow-hint" id="cc-av-dow-hint" style="display:none;color:#059669;"></div>
      </div>
      <!-- DOW -->
      <div class="cc-frow" id="cc-av-day-row" style="display:none">
        <label>วันในสัปดาห์ *</label>
        <select name="cc_day" id="cc-av-day">
          <?php foreach ($CC_DAYS_EN as $i => $d): ?>
          <option value="<?= $d ?>"><?= $CC_DAYS_TH[$i] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <!-- Time -->
      <div class="cc-frow">
        <label>เวลา (24H) *</label>
        <div style="display:flex;gap:8px;align-items:center;">
          <input type="text" name="cc_ts" id="cc-av-ts" value="09:00" placeholder="09:00" maxlength="5" oninput="ccTimeFmt(this)" style="flex:1;padding:7px 6px;border-radius:8px;border:1px solid #d1d5db;font-family:monospace;font-size:.95rem;text-align:center;width:70px;">
          <span style="font-weight:700;color:#374151;">—</span>
          <input type="text" name="cc_te" id="cc-av-te" value="10:00" placeholder="10:00" maxlength="5" oninput="ccTimeFmt(this)" style="flex:1;padding:7px 6px;border-radius:8px;border:1px solid #d1d5db;font-family:monospace;font-size:.95rem;text-align:center;width:70px;">
        </div>
      </div>
      <!-- Note -->
      <div class="cc-frow">
        <label>หมายเหตุ</label>
        <input type="text" name="cc_note" id="cc-av-note" maxlength="200" placeholder="(ไม่บังคับ)">
      </div>
      <!-- Optional: book student at the same time -->
      <div style="border-top:1.5px dashed #d1d5db;margin:10px 0 10px;"></div>
      <div style="font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:7px;">🎓 จองนักเรียนพร้อมกัน (ไม่บังคับ)</div>
      <input type="hidden" name="cc_av_sid" id="cc-av-sid" value="">
      <input type="hidden" name="cc_av_sn"  id="cc-av-sn"  value="">
      <input type="hidden" name="cc_av_sc"  id="cc-av-sc"  value="">
      <div class="cc-frow">
        <label>นักเรียน</label>
        <input type="text" id="cc-av-stu-q" placeholder="พิมพ์ชื่อหรือรหัสนักเรียน..." oninput="ccAvFilterStu()" autocomplete="off">
        <div id="cc-av-stu-list" style="max-height:130px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;margin-top:3px;display:none;background:#fff;box-shadow:0 4px 12px rgba(0,0,0,.1);"></div>
      </div>
      <div class="cc-frow">
        <label>จำนวนคาบ (คงเหลือ)</label>
        <input type="text" id="cc-av-cleft" readonly placeholder="— เลือกนักเรียนก่อน —"
               style="background:#f1f5f9;font-weight:700;color:#92400e;text-align:center;cursor:default;">
        <div id="cc-av-cleft-hint" style="font-size:.71rem;color:#6b7280;margin-top:2px;"></div>
      </div>
      <div class="cc-warn-box" id="cc-av-warn"></div>
    </div>
    <div class="cc-mfoot">
      <button type="button" class="cc-btn-cancel" onclick="ccModal('cc-modal-av',0)">ยกเลิก</button>
      <button type="submit" class="cc-btn-submit" id="cc-av-btn">เพิ่ม</button>
    </div>
  </form>
</div>
</div>

<!-- ══ Modal: Book Slot ═══════════════════════════════════════════════ -->
<div id="cc-modal-book" class="cc-mbg">
<div class="cc-mdl">
  <div class="cc-mhdr">
    <h3 id="cc-bk-title">จองเวลาเรียน</h3>
    <button class="cc-mclose" onclick="ccModal('cc-modal-book',0)">✕</button>
  </div>
  <form method="post" id="cc-form-book" onsubmit="return ccValidateBook()">
    <div class="cc-mbody">
      <input type="hidden" name="q"       value="<?= htmlspecialchars($CC_URL) ?>">
      <input type="hidden" name="year"    value="<?= $cc_year ?>">
      <input type="hidden" name="month"   value="<?= $cc_month ?>">
      <?php if ($cc_fTid): ?><input type="hidden" name="tid" value="<?= htmlspecialchars($cc_fTid) ?>"><?php endif; ?>
      <input type="hidden" name="cc_act"    id="cc-bk-act"    value="book">
      <input type="hidden" name="cc_avid"   id="cc-bk-avid"   value="">
      <input type="hidden" name="cc_tid"    id="cc-bk-tid"    value="">
      <input type="hidden" name="cc_tn"     id="cc-bk-tn"     value="">
      <input type="hidden" name="cc_avtype" id="cc-bk-avtype" value="">
      <input type="hidden" name="cc_bday"   id="cc-bk-bday"   value="">
      <input type="hidden" name="cc_ts"     id="cc-bk-ts"     value="">
      <input type="hidden" name="cc_te"     id="cc-bk-te"     value="">
      <input type="hidden" name="cc_sn"     id="cc-bk-sn"     value="">
      <input type="hidden" name="cc_sc"     id="cc-bk-sc"     value="">
      <input type="hidden" name="cc_sid"    id="cc-bk-sid"    value="">
      <!-- Teacher info (readonly) -->
      <div class="cc-frow">
        <label>ครู</label>
        <div class="cc-info-box" id="cc-bk-t-disp"></div>
      </div>
      <!-- Time info (readonly) -->
      <div class="cc-frow">
        <label>ช่วงเวลา</label>
        <div class="cc-info-box" id="cc-bk-time-disp" style="font-family:monospace;"></div>
      </div>
      <!-- Booking type -->
      <div class="cc-frow" id="cc-bk-stype-row">
        <label>รูปแบบการจอง</label>
        <div class="cc-radrow">
          <label><input type="radio" name="cc_stype" value="one_time" id="cc-bk-stype-ot" checked onchange="ccBkTypeChange()"> วันเดียว</label>
          <label><input type="radio" name="cc_stype" value="weekly"   id="cc-bk-stype-wk"       onchange="ccBkTypeChange()"> ประจำสัปดาห์</label>
        </div>
      </div>
      <!-- Date (for one_time) -->
      <div class="cc-frow" id="cc-bk-date-row">
        <label>วันที่ *</label>
        <input type="date" name="cc_bdate" id="cc-bk-bdate" min="<?= $cc_today ?>" oninput="ccBkCheckDow()">
        <div class="cc-dow-hint" id="cc-bk-dow-hint" style="display:none;"></div>
      </div>
      <!-- Day (for weekly - readonly display) -->
      <div class="cc-frow" id="cc-bk-day-row" style="display:none">
        <label>วัน (ประจำสัปดาห์)</label>
        <div class="cc-info-box" id="cc-bk-day-disp"></div>
      </div>
      <!-- Student search -->
      <div class="cc-frow">
        <label>นักเรียน *</label>
        <input type="text" id="cc-bk-stu-q" placeholder="พิมพ์ชื่อหรือรหัสนักเรียน..." oninput="ccBkFilterStu()" autocomplete="off">
        <div id="cc-bk-stu-list" style="max-height:140px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;margin-top:3px;display:none;background:#fff;box-shadow:0 4px 12px rgba(0,0,0,.1);"></div>
      </div>
      <!-- Course + Total -->
      <div style="display:flex;gap:8px;">
        <div class="cc-frow" style="flex:2;margin-bottom:11px;">
          <label>วิชา/คอร์ส</label>
          <input type="text" name="cc_course" id="cc-bk-course" placeholder="เช่น General English">
        </div>
        <div class="cc-frow" style="flex:1;margin-bottom:11px;">
          <label>คาบคงเหลือ</label>
          <input type="number" name="cc_total" id="cc-bk-total" value="0" min="0" max="9999" readonly style="background:#f1f5f9;">
          <div id="cc-bk-rem-disp" style="font-size:.72rem;color:#6b7280;margin-top:2px;"></div>
        </div>
      </div>
      <!-- Note -->
      <div class="cc-frow">
        <label>หมายเหตุ</label>
        <input type="text" name="cc_bnote" id="cc-bk-note" maxlength="200" placeholder="(ไม่บังคับ)">
      </div>
      <div class="cc-err-box" id="cc-bk-warn"></div>
    </div>
    <div class="cc-mfoot">
      <button type="button" class="cc-btn-cancel" onclick="ccModal('cc-modal-book',0)">ยกเลิก</button>
      <button type="submit" class="cc-btn-submit cc-btn-green" id="cc-bk-btn">จองเวลา</button>
    </div>
  </form>
</div>
</div>

<!-- ══ Modal: Delete Confirm ══════════════════════════════════════════ -->
<div id="cc-modal-del" class="cc-mbg">
<div class="cc-mdl" style="max-width:370px">
  <div class="cc-mhdr">
    <h3>ยืนยันการลบ</h3>
    <button class="cc-mclose" onclick="ccModal('cc-modal-del',0)">✕</button>
  </div>
  <form method="post" id="cc-form-del">
    <div class="cc-mbody">
      <input type="hidden" name="q"     value="<?= htmlspecialchars($CC_URL) ?>">
      <input type="hidden" name="year"  value="<?= $cc_year ?>">
      <input type="hidden" name="month" value="<?= $cc_month ?>">
      <?php if ($cc_fTid): ?><input type="hidden" name="tid" value="<?= htmlspecialchars($cc_fTid) ?>"><?php endif; ?>
      <input type="hidden" name="cc_act"  id="cc-del-act"  value="">
      <input type="hidden" name="cc_avid" id="cc-del-avid" value="">
      <input type="hidden" name="cc_bid"  id="cc-del-bid"  value="">
      <p id="cc-del-msg" style="font-size:.88rem;color:#374151;margin:4px 0 0;"></p>
    </div>
    <div class="cc-mfoot">
      <button type="button" class="cc-btn-cancel" onclick="ccModal('cc-modal-del',0)">ยกเลิก</button>
      <button type="submit" class="cc-btn-submit cc-btn-danger">ยืนยันลบ</button>
    </div>
  </form>
</div>
</div>

</div><!-- .cc-wrap -->

<script>
var _ccAv   = <?= json_encode($ccAvJsMap,   JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
var _ccBk   = <?= json_encode($ccBkJsMap,   JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
var _ccStus = <?= json_encode(array_values($ccStudents), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
var _ccDaysTH = <?= json_encode($CC_DAYS_TH, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
var _ccDaysEN = <?= json_encode($CC_DAYS_EN, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
var _ccToday  = '<?= $cc_today ?>';
var _ccNowMin = <?= $cc_nowMins ?>;
var _ccTeachers = <?= json_encode(array_map(fn($t)=>['v'=>$t['id'],'t'=>$t['displayName'].' ('.($t['teacherCode']??'').')'], $ccTeachers), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
_ccTeachers.unshift({v:'',t:'👥 ครูทั้งหมด'});
var _ccAvHits = [];   // hit list for availability modal student search
var _ccBkHits = [];   // hit list for booking modal student search
function escH(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// Navigate to selected year/month
function ccJumpDate() {
    var y = parseInt(document.getElementById('cc-jump-y').value);
    var m = parseInt(document.getElementById('cc-jump-m').value);
    var url = new URL(window.location.href);
    url.searchParams.set('year',  y);
    url.searchParams.set('month', m);
    url.searchParams.delete('wstart');
    window.location.href = url.toString();
}

var _ccTHits = [];
var _ccCurTid = '<?= htmlspecialchars($cc_fTid) ?>';

function ccSearchTeacher(q) {
    var dd = document.getElementById('cc-teacher-dd');
    q = (q || '').trim().toLowerCase();
    var all = _ccTeachers.filter(function(t){ return t.v !== ''; });
    _ccTHits = q ? all.filter(function(t){ return t.t.toLowerCase().includes(q); }).slice(0, 12) : all.slice(0, 15);
    var html = '<div class="cc-td-all" onmousedown="ccPickTeacher(-1)">👥 ดูครูทั้งหมด</div>';
    if (!_ccTHits.length) {
        html += '<div style="padding:8px 14px;color:#9ca3af;font-size:.82rem;">ไม่พบครู</div>';
    } else {
        html += _ccTHits.map(function(t, i){
            var active = t.v === _ccCurTid;
            return '<div class="cc-td-item'+(active?' cc-td-active':'')+'" onmousedown="ccPickTeacher('+i+')">'
                 + (active ? '✓ ' : '') + escH(t.t) + '</div>';
        }).join('');
    }
    dd.innerHTML = html;
    dd.style.display = 'block';
}

function ccPickTeacher(i) {
    var url = new URL(window.location.href);
    if (i < 0 || !_ccTHits[i] || !_ccTHits[i].v) {
        url.searchParams.delete('tid');
    } else {
        url.searchParams.set('tid', _ccTHits[i].v);
    }
    window.location.href = url.toString();
}

function ccTeacherKeyNav(e) {
    if (e.key === 'Escape') {
        document.getElementById('cc-teacher-dd').style.display = 'none';
    } else if (e.key === 'Enter') {
        if (_ccTHits.length === 1) ccPickTeacher(0);
    }
}

// ── Modal open/close ──────────────────────────────────────────────────
function ccModal(id, show) {
    var el = document.getElementById(id);
    if (el) show ? el.classList.add('open') : el.classList.remove('open');
}
document.addEventListener('click', function(e){
    if (e.target.classList.contains('cc-mbg')) e.target.classList.remove('open');
    var dd = document.getElementById('cc-teacher-dd');
    if (dd && e.target.id !== 'cc-tsearch' && !e.target.closest('#cc-teacher-dd')) dd.style.display = 'none';
});

// ── Helpers ───────────────────────────────────────────────────────────
function ccDowIdx(dateStr) { return new Date(dateStr + 'T00:00:00').getDay(); }
function ccIsPast(dateStr, timeStr) {
    if (!dateStr || !timeStr) return false;
    return new Date(dateStr + 'T' + timeStr + ':00') <= new Date();
}
function ccShowWarn(elId, msg) {
    var el = document.getElementById(elId);
    if (!el) return;
    el.textContent = '⚠️ ' + msg;
    el.style.display = 'block';
}
function ccHideWarn(elId) { var el = document.getElementById(elId); if(el) el.style.display='none'; }

// ── Add/Edit Availability Modal ───────────────────────────────────────
function ccOpenAdd(dayIdx, dateStr, tw) {
    document.getElementById('cc-av-act').value  = 'add_avail';
    document.getElementById('cc-av-avid').value = '';
    document.getElementById('cc-av-title').textContent = 'เพิ่มช่วงเวลาว่างครู';
    document.getElementById('cc-av-btn').textContent   = 'เพิ่ม';
    var tidSel = document.getElementById('cc-av-tid');
    if (tidSel && '<?= htmlspecialchars($cc_fTid) ?>') tidSel.value = '<?= htmlspecialchars($cc_fTid) ?>';
    document.getElementById('cc-av-type-sd').checked = true;
    ccAvTypeChange();
    var dateEl = document.getElementById('cc-av-date');
    dateEl.value = dateStr || '';
    ccAvDateChange();
    if (tw) {
        document.getElementById('cc-av-ts').value = tw;
        var parts = tw.split(':'); var h = parseInt(parts[0]) + 1;
        var endH  = (h < 22 ? h : 22).toString().padStart(2,'0');
        document.getElementById('cc-av-te').value = endH + ':' + parts[1];
    } else {
        document.getElementById('cc-av-ts').value = '09:00';
        document.getElementById('cc-av-te').value = '10:00';
    }
    document.getElementById('cc-av-note').value = '';
    // Reset optional student fields
    document.getElementById('cc-av-sid').value   = '';
    document.getElementById('cc-av-sn').value    = '';
    document.getElementById('cc-av-sc').value    = '';
    document.getElementById('cc-av-stu-q').value = '';
    document.getElementById('cc-av-stu-list').style.display = 'none';
    var cf = document.getElementById('cc-av-cleft');
    var ch = document.getElementById('cc-av-cleft-hint');
    if (cf) { cf.value = ''; cf.style.background = '#f1f5f9'; cf.style.color = '#92400e'; }
    if (ch) ch.textContent = '';
    ccHideWarn('cc-av-warn');
    ccModal('cc-modal-av', 1);
}

function ccOpenEditAv(avid) {
    var d = _ccAv[avid]; if (!d) return;
    document.getElementById('cc-av-act').value  = 'edit_avail';
    document.getElementById('cc-av-avid').value = avid;
    document.getElementById('cc-av-title').textContent = 'แก้ไขช่วงเวลาว่าง';
    document.getElementById('cc-av-btn').textContent   = 'บันทึก';
    document.getElementById('cc-av-tid').value  = d.tid;
    if (d.avtype === 'weekly') {
        document.getElementById('cc-av-type-wk').checked = true;
        document.getElementById('cc-av-day').value       = d.day;
    } else {
        document.getElementById('cc-av-type-sd').checked = true;
        document.getElementById('cc-av-date').value      = d.date;
    }
    ccAvTypeChange(); ccAvDateChange();
    document.getElementById('cc-av-ts').value   = d.ts;
    document.getElementById('cc-av-te').value   = d.te;
    document.getElementById('cc-av-note').value = d.note || '';
    // Reset student section when editing
    document.getElementById('cc-av-sid').value   = '';
    document.getElementById('cc-av-sn').value    = '';
    document.getElementById('cc-av-sc').value    = '';
    document.getElementById('cc-av-stu-q').value = '';
    document.getElementById('cc-av-stu-list').style.display = 'none';
    var cf2 = document.getElementById('cc-av-cleft');
    var ch2 = document.getElementById('cc-av-cleft-hint');
    if (cf2) { cf2.value = ''; cf2.style.background = '#f1f5f9'; }
    if (ch2) ch2.textContent = '';
    ccHideWarn('cc-av-warn');
    ccModal('cc-modal-av', 1);
}

function ccAvTypeChange() {
    var isWk = document.getElementById('cc-av-type-wk').checked;
    document.getElementById('cc-av-date-row').style.display = isWk ? 'none'  : 'block';
    document.getElementById('cc-av-day-row').style.display  = isWk ? 'block' : 'none';
}

function ccAvDateChange() {
    var d = document.getElementById('cc-av-date').value;
    var hint = document.getElementById('cc-av-dow-hint');
    if (!d) { hint.style.display = 'none'; return; }
    var dow = ccDowIdx(d);
    hint.textContent = '📅 วัน' + _ccDaysTH[dow];
    hint.style.display = 'block'; hint.style.color = '#059669';
}

function ccTimeFmt(el) {
    var raw = el.value.replace(/[^0-9]/g, '');
    if (raw.length > 4) raw = raw.slice(0, 4);
    if (raw.length >= 3) raw = raw.slice(0, 2) + ':' + raw.slice(2);
    el.value = raw;
}
function ccTimeOk(v) {
    if (!/^\d{2}:\d{2}$/.test(v)) return false;
    var p = v.split(':'); var h = +p[0], m = +p[1];
    return h >= 0 && h <= 23 && m >= 0 && m <= 59;
}
function ccValidateAv() {
    ccHideWarn('cc-av-warn');
    var ts = document.getElementById('cc-av-ts').value;
    var te = document.getElementById('cc-av-te').value;
    if (!ccTimeOk(ts)||!ccTimeOk(te)) { ccShowWarn('cc-av-warn','กรุณาระบุเวลาในรูปแบบ HH:MM (24 ชั่วโมง) เช่น 09:30'); return false; }
    if (ts >= te)   { ccShowWarn('cc-av-warn','เวลาเริ่มต้องน้อยกว่าเวลาจบ');    return false; }
    var isSd = document.getElementById('cc-av-type-sd').checked;
    if (isSd) {
        var dt = document.getElementById('cc-av-date').value;
        if (!dt) { ccShowWarn('cc-av-warn','กรุณาระบุวันที่'); return false; }
        if (ccIsPast(dt, ts)) { ccShowWarn('cc-av-warn','วันเวลานี้ผ่านมาแล้ว ไม่สามารถบันทึกได้'); return false; }
    }
    return true;
}

// ── Book Slot Modal (dateOverride = cell's specific date for weekly slots) ──
function ccOpenBook(avid, dateOverride) {
    var d = _ccAv[avid]; if (!d) return;
    document.getElementById('cc-bk-act').value    = 'book';
    document.getElementById('cc-bk-avid').value   = avid;
    document.getElementById('cc-bk-tid').value    = d.tid;
    document.getElementById('cc-bk-tn').value     = d.tname;
    document.getElementById('cc-bk-avtype').value = d.avtype;
    document.getElementById('cc-bk-bday').value   = d.day;
    document.getElementById('cc-bk-ts').value     = d.ts;
    document.getElementById('cc-bk-te').value     = d.te;
    document.getElementById('cc-bk-title').textContent   = 'จองเวลาเรียน';
    document.getElementById('cc-bk-btn').textContent     = 'จองเวลา';
    document.getElementById('cc-bk-t-disp').textContent  = d.tname + (d.tcode ? ' (' + d.tcode + ')' : '');
    document.getElementById('cc-bk-time-disp').textContent = d.ts + ' – ' + d.te;
    var wkRadio = document.getElementById('cc-bk-stype-wk');
    if (d.avtype === 'specific_date') {
        document.getElementById('cc-bk-stype-ot').checked = true;
        wkRadio.disabled = true; wkRadio.parentElement.style.opacity = '.4';
        document.getElementById('cc-bk-bdate').value = d.date;
    } else {
        wkRadio.disabled = false; wkRadio.parentElement.style.opacity = '1';
        document.getElementById('cc-bk-stype-ot').checked = true;
        // Use the clicked cell's date (dateOverride) for weekly slots in month view
        document.getElementById('cc-bk-bdate').value = dateOverride || '';
    }
    ccBkTypeChange();
    ccBkCheckDow();
    document.getElementById('cc-bk-stu-q').value  = '';
    document.getElementById('cc-bk-sn').value      = '';
    document.getElementById('cc-bk-sc').value      = '';
    document.getElementById('cc-bk-sid').value     = '';
    document.getElementById('cc-bk-stu-list').style.display = 'none';
    document.getElementById('cc-bk-course').value  = '';
    document.getElementById('cc-bk-total').value   = '0';
    document.getElementById('cc-bk-total').style.background = '#f1f5f9';
    var rd = document.getElementById('cc-bk-rem-disp'); if(rd) rd.textContent = '';
    document.getElementById('cc-bk-note').value    = '';
    ccHideWarn('cc-bk-warn');
    ccModal('cc-modal-book', 1);
}

function ccOpenEditBk(bid, avid, dateOverride) {
    var bk = _ccBk[bid]; if (!bk) return;
    var av = _ccAv[avid]; if (!av) return;
    ccOpenBook(avid, dateOverride);
    document.getElementById('cc-bk-title').textContent = 'แก้ไขการจอง (จองใหม่)';
    document.getElementById('cc-bk-sn').value    = bk.sname;
    document.getElementById('cc-bk-sc').value    = bk.scode;
    document.getElementById('cc-bk-stu-q').value = bk.sname + (bk.scode ? ' (' + bk.scode + ')' : '');
    document.getElementById('cc-bk-course').value= bk.course;
    document.getElementById('cc-bk-total').value = bk.total || 20;
    document.getElementById('cc-bk-note').value  = bk.note;
}

function ccBkTypeChange() {
    var isOt = document.getElementById('cc-bk-stype-ot').checked;
    document.getElementById('cc-bk-date-row').style.display = isOt ? 'block' : 'none';
    document.getElementById('cc-bk-day-row').style.display  = isOt ? 'none'  : 'block';
    if (!isOt) {
        var bday = document.getElementById('cc-bk-bday').value;
        var idx  = _ccDaysEN.indexOf(bday);
        document.getElementById('cc-bk-day-disp').textContent = (idx >= 0 ? 'วัน' + _ccDaysTH[idx] : bday) + ' (ประจำสัปดาห์)';
    }
}

function ccBkCheckDow() {
    var avid  = document.getElementById('cc-bk-avid').value;
    var av    = _ccAv[avid];
    var bdate = document.getElementById('cc-bk-bdate').value;
    var hint  = document.getElementById('cc-bk-dow-hint');
    if (!av || !bdate) { hint.style.display = 'none'; return; }
    var actualDowIdx   = ccDowIdx(bdate);
    var expectedDowIdx = _ccDaysEN.indexOf(av.day);
    if (av.avtype === 'weekly' && expectedDowIdx >= 0 && actualDowIdx !== expectedDowIdx) {
        hint.textContent = '⚠️ วันที่นี้ตรงกับวัน' + _ccDaysTH[actualDowIdx] + ' ไม่ตรงกับวัน' + _ccDaysTH[expectedDowIdx] + ' — จะบันทึกไม่ได้';
        hint.style.display = 'block'; hint.style.color = '#dc2626';
    } else {
        var dowTH = _ccDaysTH[actualDowIdx] || '';
        hint.textContent = '✓ วัน' + dowTH;
        hint.style.display = 'block'; hint.style.color = '#059669';
    }
}

// Student autocomplete for booking modal
function ccBkFilterStu() {
    var q    = document.getElementById('cc-bk-stu-q').value.toLowerCase().trim();
    var list = document.getElementById('cc-bk-stu-list');
    document.getElementById('cc-bk-sn').value  = '';
    document.getElementById('cc-bk-sid').value = '';
    if (!q) { list.style.display = 'none'; _ccBkHits = []; return; }
    _ccBkHits = _ccStus.filter(function(s){ return (s.displayName||'').toLowerCase().includes(q)||(s.studentCode||'').toLowerCase().includes(q); }).slice(0,8);
    if (!_ccBkHits.length) { list.style.display = 'none'; return; }
    list.innerHTML = _ccBkHits.map(function(s, i){
        var rem = s.totalClasses || 0;
        return '<div style="padding:6px 10px;cursor:pointer;font-size:.82rem;border-bottom:1px solid #f3f4f6;" '
             + 'onmousedown="ccBkSelectStu(' + i + ')">'
             + '<strong>' + escH(s.displayName) + '</strong>'
             + ' <span style="color:#9ca3af;font-size:.73rem;">(' + escH(s.studentCode||'') + ')</span>'
             + ' <span style="background:#fef3c7;color:#92400e;font-size:.7rem;padding:1px 5px;border-radius:4px;">' + rem + ' คาบ</span>'
             + '</div>';
    }).join('');
    list.style.display = 'block';
}
function ccBkSelectStu(i) {
    var s = _ccBkHits[i]; if (!s) return;
    var rem = s.totalClasses || 0;
    document.getElementById('cc-bk-sid').value    = s.id;
    document.getElementById('cc-bk-sn').value     = s.displayName;
    document.getElementById('cc-bk-sc').value     = s.studentCode || '';
    document.getElementById('cc-bk-stu-q').value  = s.displayName + (s.studentCode ? ' (' + s.studentCode + ')' : '');
    document.getElementById('cc-bk-stu-list').style.display = 'none';
    var t = document.getElementById('cc-bk-total');
    if (t) { t.value = rem || 0; t.style.background = (rem > 0) ? '#dcfce7' : '#fee2e2'; }
    var rd = document.getElementById('cc-bk-rem-disp');
    if (rd) rd.textContent = '📚 คงเหลือ ' + rem + ' คาบ';
}

function ccValidateBook() {
    ccHideWarn('cc-bk-warn');
    var sn = document.getElementById('cc-bk-sn').value;
    if (!sn) { ccShowWarn('cc-bk-warn','กรุณาเลือกนักเรียน'); return false; }
    var isOt = document.getElementById('cc-bk-stype-ot').checked;
    var ts   = document.getElementById('cc-bk-ts').value;
    var te   = document.getElementById('cc-bk-te').value;
    if (isOt) {
        var bdate = document.getElementById('cc-bk-bdate').value;
        if (!bdate) { ccShowWarn('cc-bk-warn','กรุณาระบุวันที่'); return false; }
        var avid = document.getElementById('cc-bk-avid').value;
        var av   = _ccAv[avid];
        if (av && av.avtype === 'weekly') {
            var actualIdx   = ccDowIdx(bdate);
            var expectedIdx = _ccDaysEN.indexOf(av.day);
            if (expectedIdx >= 0 && actualIdx !== expectedIdx) {
                ccShowWarn('cc-bk-warn','วันที่ '+bdate+' ตรงกับวัน'+_ccDaysTH[actualIdx]+' ไม่ใช่วัน'+_ccDaysTH[expectedIdx]+' — บันทึกไม่ได้');
                return false;
            }
        }
        if (ccIsPast(bdate, te)) { ccShowWarn('cc-bk-warn','วันเวลานี้ผ่านมาแล้ว ไม่สามารถบันทึกได้'); return false; }
    } else {
        var bday   = document.getElementById('cc-bk-bday').value;
        var dayIdx = _ccDaysEN.indexOf(bday);
        var todayD = new Date(_ccToday + 'T00:00:00').getDay();
        if (dayIdx >= 0 && dayIdx < todayD) {
            ccShowWarn('cc-bk-warn','วัน'+_ccDaysTH[dayIdx]+' ในสัปดาห์นี้ผ่านมาแล้ว'); return false;
        }
        if (dayIdx >= 0 && dayIdx === todayD) {
            var teParts = te.split(':');
            var teMin   = parseInt(teParts[0])*60 + parseInt(teParts[1]||'0');
            if (_ccNowMin > teMin) { ccShowWarn('cc-bk-warn','ช่วงเวลานี้ผ่านมาแล้วสำหรับวันนี้'); return false; }
        }
    }
    return true;
}

// ── Student search for availability modal ─────────────────────────────
function ccAvFilterStu() {
    var q    = document.getElementById('cc-av-stu-q').value.toLowerCase().trim();
    var list = document.getElementById('cc-av-stu-list');
    document.getElementById('cc-av-sid').value = '';
    document.getElementById('cc-av-sn').value  = '';
    if (!q) { list.style.display = 'none'; _ccAvHits = []; return; }
    _ccAvHits = _ccStus.filter(function(s){ return (s.displayName||'').toLowerCase().includes(q)||(s.studentCode||'').toLowerCase().includes(q); }).slice(0,8);
    if (!_ccAvHits.length) { list.style.display = 'none'; return; }
    list.innerHTML = _ccAvHits.map(function(s, i){
        var rem = s.totalClasses || 0;
        return '<div style="padding:6px 10px;cursor:pointer;font-size:.82rem;border-bottom:1px solid #f3f4f6;" '
             + 'onmousedown="ccAvSelectStu(' + i + ')">'
             + '<strong>' + escH(s.displayName) + '</strong>'
             + ' <span style="color:#9ca3af;font-size:.73rem;">(' + escH(s.studentCode||'') + ')</span>'
             + ' <span style="background:#fef3c7;color:#92400e;font-size:.7rem;padding:1px 5px;border-radius:4px;">' + rem + ' คาบ</span>'
             + '</div>';
    }).join('');
    list.style.display = 'block';
}
function ccAvSelectStu(i) {
    var s = _ccAvHits[i]; if (!s) return;
    var rem = s.totalClasses || 0;
    document.getElementById('cc-av-sid').value   = s.id;
    document.getElementById('cc-av-sn').value    = s.displayName;
    document.getElementById('cc-av-sc').value    = s.studentCode || '';
    document.getElementById('cc-av-stu-q').value = s.displayName + (s.studentCode ? ' (' + s.studentCode + ')' : '');
    document.getElementById('cc-av-stu-list').style.display = 'none';
    var cf = document.getElementById('cc-av-cleft');
    var ch = document.getElementById('cc-av-cleft-hint');
    if (cf) {
        cf.value = rem + ' คาบ';
        cf.style.background = rem > 0 ? '#fef9c3' : '#fee2e2';
        cf.style.color      = rem > 0 ? '#92400e' : '#991b1b';
    }
    if (ch) ch.textContent = rem > 0
        ? '📚 หลังจองจะเหลือ ' + (rem - 1) + ' คาบ'
        : '⚠️ คาบหมดแล้ว — ไม่แนะนำให้จอง';
}

// ── Delete ────────────────────────────────────────────────────────────
function ccDelAv(avid) {
    document.getElementById('cc-del-act').value  = 'del_avail';
    document.getElementById('cc-del-avid').value = avid;
    document.getElementById('cc-del-bid').value  = '';
    document.getElementById('cc-del-msg').textContent = 'ลบช่วงเวลาว่างนี้? (การจองที่เชื่อมโยงจะยังคงอยู่)';
    ccModal('cc-modal-del', 1);
}
function ccDelBk(bid) {
    document.getElementById('cc-del-act').value  = 'del_book';
    document.getElementById('cc-del-avid').value = '';
    document.getElementById('cc-del-bid').value  = bid;
    document.getElementById('cc-del-msg').textContent = 'ลบการจองนี้?';
    ccModal('cc-modal-del', 1);
}
</script>
