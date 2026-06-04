<?php
/*
 * 7J English Center — Teacher-Student Relations
 * ปรับปรุง: ใช้ sevenj_students.teacherId (FK จริง) แทน sevenj_schedule.teacher_name (text)
 * แหล่งข้อมูลหลัก: sevenj_teachers + sevenj_students
 * แหล่งข้อมูลสำรอง: sevenj_schedule (สำหรับนักเรียนที่ยังไม่ได้อยู่ใน sevenj_students)
 */

// ─── POST: ลบ schedule ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_schedule') {
    $stuId = trim($_POST['stu_id'] ?? '');
    if ($stuId) {
        $q = $connection2->quote($stuId);
        $connection2->query("DELETE FROM sevenj_schedule WHERE student_id=$q"); // completions คงไว้ 30 วัน
    }
    $teacher = urlencode($_POST['teacher_back'] ?? '');
    $srch    = urlencode($_POST['search_back'] ?? '');
    header("Location: /MyNewShool/?q=/modules/7j/teacher_student_relations.php&teacher=$teacher&search=$srch");
    exit;
}

$search     = trim($_GET['search']  ?? '');
$selTeacher = trim($_GET['teacher'] ?? '');

// ─── ข้อมูลหลัก: ครูจาก sevenj_teachers + นักเรียนจาก sevenj_students ─────
$teacherWhere = '';
if ($selTeacher) $teacherWhere = "WHERE t.id='".$connection2->quote($selTeacher)."' OR t.displayName='".$connection2->quote($selTeacher)."'";

$allTeachersDB = $connection2->query("
    SELECT id, displayName, teacherCode, location FROM sevenj_teachers
    WHERE status='active' ORDER BY displayName
")->fetchAll(PDO::FETCH_ASSOC);

// Query นักเรียนที่มี teacherId FK
$stuWhere = []; $stuP = [];
if ($selTeacher) { $stuWhere[] = "t.id=?"; $stuP[] = (int)$selTeacher; }
if ($search)     { $stuWhere[] = "(st.displayName LIKE ? OR st.studentCode LIKE ? OR t.displayName LIKE ?)"; $l='%'.$search.'%'; $stuP[]=$l; $stuP[]=$l; $stuP[]=$l; }
$stuWhereSQL = $stuWhere ? 'WHERE '.implode(' AND ', $stuWhere) : '';

$stmtStu = $connection2->prepare("
    SELECT st.id AS stu_id, st.displayName AS stu_name, st.studentCode AS stu_code,
           st.teacherId AS teacher_fk_id,
           COALESCE(t.id, t2.id) AS tea_id,
           COALESCE(t.displayName, t2.displayName, sch.teacher_name) AS tea_name,
           COALESCE(t.teacherCode, t2.teacherCode) AS tea_code,
           COALESCE(sch.total_classes, st.totalClasses, 0) AS total_classes,
           (SELECT COUNT(*) FROM sevenj_class_completions cc
            WHERE cc.student_id = st.id
           ) AS actual_completed,
           sch.day_of_week, sch.time_start, sch.time_end, sch.course,
           sch.status AS sch_status
    FROM sevenj_students st
    LEFT JOIN sevenj_teachers t  ON t.id  = st.teacherId
    LEFT JOIN sevenj_schedule sch ON sch.student_id = st.id
    LEFT JOIN sevenj_teachers t2 ON t2.id = sch.teacher_ref_id
    $stuWhereSQL
    ORDER BY COALESCE(t.displayName, t2.displayName, sch.teacher_name), st.displayName
");
$stmtStu->execute($stuP);
$stuRows = $stmtStu->fetchAll(PDO::FETCH_ASSOC);

// ─── ข้อมูลสำรอง: sevenj_schedule ที่ยังไม่มี student_id FK ─────────────────
$shedWhere = ["s.student_id IS NULL"]; $shedP = [];
if ($selTeacher) { $shedWhere[] = "s.teacher_ref_id=?"; $shedP[] = (int)$selTeacher; }
if ($search)     { $shedWhere[] = "(s.student_name LIKE ? OR s.teacher_name LIKE ? OR s.student_code LIKE ?)"; $l='%'.$search.'%'; $shedP[]=$l; $shedP[]=$l; $shedP[]=$l; }
$shedWhereSQL = 'WHERE '.implode(' AND ', $shedWhere);

$stmtSched = $connection2->prepare("
    SELECT NULL AS stu_id, s.student_name AS stu_name, s.student_code AS stu_code,
           NULL AS teacher_fk_id,
           s.teacher_ref_id AS tea_id, s.teacher_name AS tea_name, NULL AS tea_code,
           s.total_classes, s.completed_classes,
           s.day_of_week, s.time_start, s.time_end, s.course,
           s.status AS sch_status
    FROM sevenj_schedule s
    $shedWhereSQL
    ORDER BY s.teacher_name, s.student_name
");
$stmtSched->execute($shedP);
$schedRows = $stmtSched->fetchAll(PDO::FETCH_ASSOC);

// ─── Group by teacher ─────────────────────────────────────────────────────────
$byTeacher = [];
$allTeacherNames = [];

foreach ($stuRows as $r) {
    $tName = $r['tea_name'] ?: 'ไม่มีครูประจำ';
    $tId   = $r['tea_id']   ?: '__no_teacher__';
    if (!isset($byTeacher[$tId])) {
        $byTeacher[$tId] = ['name'=>$tName,'code'=>$r['tea_code'],'students'=>[],'is_linked'=>true];
        $allTeacherNames[$tId] = $tName;
    }
    $byTeacher[$tId]['students'][] = $r;
}
foreach ($schedRows as $r) {
    $tName = $r['tea_name'] ?: 'ไม่มีครูประจำ';
    $tId   = $r['tea_id'] ?: 'sched_'.$tName;
    if (!isset($byTeacher[$tId])) {
        $byTeacher[$tId] = ['name'=>$tName,'code'=>null,'students'=>[],'is_linked'=>false];
        $allTeacherNames[$tId] = $tName;
    }
    $byTeacher[$tId]['students'][] = $r;
}

// ─── Merge: นักเรียนคนเดียวกันที่มีหลาย schedule กับครูคนเดียว ──────────────
// total/completed นับจาก active + completed (อ้างอิงบันทึกคาบเรียนจริง)
foreach ($byTeacher as &$tData) {
    $merged = [];
    foreach ($tData['students'] as $s) {
        $gKey    = !empty($s['stu_id']) ? ('id:'.$s['stu_id']) : ('nm:'.$s['stu_name'].'|'.($s['stu_code']??''));
        $isActive = in_array(($s['sch_status'] ?? 'active'), ['active', 'completed']);
        if (!isset($merged[$gKey])) {
            $merged[$gKey] = [
                'stu_id'            => $s['stu_id'],
                'stu_name'          => $s['stu_name'],
                'stu_code'          => $s['stu_code'],
                'teacher_fk_id'     => $s['teacher_fk_id'] ?? null,
                'total_classes'     => 0,
                'completed_classes' => 0,
                'schedules'         => [],
            ];
        }
        // รวม progress เฉพาะ active schedule
        // total_classes = MAX (ทุก row ของ package เดียวกันมีค่าเท่ากัน ไม่ sum)
        // completed_classes = COUNT จาก sevenj_class_completions ตรงๆ (อ้างอิงบันทึกคาบเรียน)
        if ($isActive) {
            $merged[$gKey]['total_classes']     = max($merged[$gKey]['total_classes'], (int)($s['total_classes'] ?? 0));
            $merged[$gKey]['completed_classes'] = (int)($s['actual_completed'] ?? 0);
        }
        // แสดงทุก schedule ในลิสต์ พร้อมระบุสถานะ
        if (($s['day_of_week'] ?? '') || ($s['time_start'] ?? '') || ($s['course'] ?? '')) {
            $merged[$gKey]['schedules'][] = [
                'day_of_week' => $s['day_of_week'] ?? '',
                'time_start'  => $s['time_start']  ?? '',
                'time_end'    => $s['time_end']     ?? '',
                'course'      => $s['course']       ?? '',
                'status'      => $s['sch_status']   ?? 'active',
            ];
        }
    }
    $tData['students'] = array_values($merged);
}
unset($tData);

// Sort by student count desc
uasort($byTeacher, fn($a,$b) => count($b['students'])-count($a['students']));
asort($allTeacherNames);

$dayThMap = ['Sunday'=>'อาทิตย์','Monday'=>'จันทร์','Tuesday'=>'อังคาร','Wednesday'=>'พุธ','Thursday'=>'พฤหัสบดี','Friday'=>'ศุกร์','Saturday'=>'เสาร์'];

// เวลาเก็บแบบ 24h จริง — แปลงเป็น 12h AM/PM
function fmtTimePM(string $t): string {
    if ($t === '') return '';
    [$h, $m] = array_pad(explode(':', $t), 2, '00');
    $h = (int)$h;
    $suffix = $h >= 12 ? 'PM' : 'AM';
    $h12    = $h % 12 ?: 12;
    return $h12 . ':' . $m . ' ' . $suffix;
}

// Stats — นับหลัง merge เพื่อได้จำนวน unique students
$totalTeachers = count(array_filter($byTeacher, fn($t) => !empty($t['students'])));
$uniqStuKeys   = [];
foreach ($byTeacher as $tData) {
    foreach ($tData['students'] as $s) {
        $k = !empty($s['stu_id']) ? $s['stu_id'] : ($s['stu_name'].'|'.($s['stu_code']??''));
        $uniqStuKeys[$k] = true;
    }
}
$totalStudents = count($uniqStuKeys);
$avgS = $totalTeachers > 0 ? round($totalStudents / $totalTeachers, 1) : 0;

function trAvatarColor($name) {
    $colors = ['#ea580c','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2','#65a30d'];
    $idx = array_sum(array_map('ord', str_split($name ?? '?'))) % count($colors);
    return $colors[$idx];
}
function trInitials($name) {
    $words = preg_split('/\s+/', trim($name ?? ''));
    $ini = '';
    foreach ($words as $w) { if ($w) $ini .= mb_strtoupper(mb_substr($w,0,1)); }
    return mb_substr($ini,0,2) ?: '?';
}
?>

<?php require_once __DIR__.'/_theme.php'; ?>
<style>
.tr-card{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:12px;overflow:hidden;}
.tr-head{padding:12px 16px;display:flex;justify-content:space-between;align-items:center;cursor:pointer;}
.tr-students{padding:10px 14px;display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:8px;}
.tr-stu-card{border:1px solid #fff7ed;border-radius:8px;padding:10px 12px;}
.tr-badge{display:inline-block;border-radius:99px;padding:1px 8px;font-size:.68rem;font-weight:700;}
.tr-linked{background:#d1fae5;color:#065f46;}
.tr-unlinked{background:#fef3c7;color:#92400e;}
.tr-search{padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:.88rem;outline:none;}
.tr-search:focus{border-color:#ea580c;box-shadow:0 0 0 2px #fff7ed;}
.tr-btn{padding:6px 14px;border-radius:7px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;}
.tr-btn-primary{background:#ea580c;color:#fff;} .tr-btn-primary:hover{background:#c2410c;}
.tr-btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db;} .tr-btn-outline:hover{background:#f3f4f6;}
.tr-prog{height:4px;border-radius:99px;background:#e5e7eb;overflow:hidden;margin-top:5px;}
.tr-prog-bar{height:100%;border-radius:99px;}
</style>

<div style="max-width:100%;padding-bottom:2rem;">

<h2 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0 0 1rem;">🔗 Teacher-Student Relations</h2>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:1.25rem;">
<?php foreach ([
    ['👨‍🏫','ครูที่มีนักเรียน', $totalTeachers, '#ea580c'],
    ['🎓','นักเรียนทั้งหมด',  $totalStudents, '#2563eb'],
    ['📊','เฉลี่ย/ครู',       $avgS,          '#059669'],
    ['🔗','ครูใน DB',         count($allTeachersDB), '#d97706'],
] as [$ic,$lb,$vl,$co]): ?>
<div style="background:#fff;border-radius:10px;padding:.75rem 1rem;box-shadow:0 1px 4px rgba(0,0,0,.07);border-left:4px solid <?= $co ?>;">
    <div style="font-size:1.1rem;"><?= $ic ?></div>
    <div style="font-size:1.5rem;font-weight:800;color:#1f2937;"><?= $vl ?></div>
    <div style="font-size:.72rem;color:#6b7280;"><?= $lb ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- Filter -->
<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1.25rem;align-items:center;">
    <input type="hidden" name="q" value="/modules/7j/teacher_student_relations.php">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="tr-search" placeholder="ค้นหาชื่อครู, นักเรียน, รหัส..." style="flex:1;min-width:160px;">
    <select name="teacher" class="tr-search">
        <option value="">ครูทั้งหมด</option>
        <?php foreach ($allTeachersDB as $t): ?>
        <option value="<?= htmlspecialchars($t['id']) ?>" <?= $selTeacher===$t['id']?'selected':'' ?>>
            <?= htmlspecialchars($t['displayName']) ?><?= $t['teacherCode']?' ('.$t['teacherCode'].')':'' ?>
        </option>
        <?php endforeach; ?>
        <?php foreach ($allTeacherNames as $tid => $tname):
            if (!in_array($tid, array_column($allTeachersDB,'id')) && !empty($tname) && $tname !== 'ไม่มีครูประจำ'): ?>
        <option value="<?= htmlspecialchars($tname) ?>" <?= $selTeacher===$tname?'selected':'' ?>>
            <?= htmlspecialchars($tname) ?> (schedule only)
        </option>
        <?php endif; endforeach; ?>
    </select>
    <button type="submit" class="tr-btn tr-btn-primary">ค้นหา</button>
    <?php if ($search || $selTeacher): ?>
    <a href="?q=/modules/7j/teacher_student_relations.php" class="tr-btn tr-btn-outline">✕ ล้าง</a>
    <?php endif; ?>
</form>

<?php if (empty($byTeacher)): ?>
<div style="text-align:center;padding:3rem 1rem;color:#9ca3af;">
    <div style="font-size:2.5rem;margin-bottom:.5rem;">🔗</div>
    ไม่พบข้อมูล<?= $search || $selTeacher ? '' : ' — เพิ่มนักเรียนและกำหนดครูใน <a href="?q=/modules/7j/user_management.php" style="color:#ea580c;">User Management</a>' ?>
</div>
<?php else: ?>

<?php foreach ($byTeacher as $tId => $tData):
    if (empty($tData['students'])) continue;
    $students    = $tData['students'];
    $tName       = $tData['name'];
    $isLinked    = $tData['is_linked'];
    $cardId      = 'tc_'.md5($tId);
    $totalDone   = array_sum(array_column($students,'completed_classes'));
    $totalAll    = array_sum(array_column($students,'total_classes'));
    $tPct        = $totalAll > 0 ? round($totalDone/$totalAll*100) : 0;
    $gradBg      = $isLinked
        ? 'background:linear-gradient(135deg,#ea580c,#ea580c)'
        : 'background:linear-gradient(135deg,#d97706,#f59e0b)';
?>
<div class="tr-card">
    <div class="tr-head" style="<?= $gradBg ?>;" onclick="toggleTR('<?= $cardId ?>')">
        <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;flex-shrink:0;">
                <?= htmlspecialchars(trInitials($tName)) ?>
            </div>
            <div>
                <div style="color:#fff;font-weight:700;font-size:.95rem;"><?= htmlspecialchars($tName) ?>
                    <?php if ($tData['code']): ?><span style="background:rgba(255,255,255,.2);border-radius:99px;padding:1px 8px;font-size:.7rem;margin-left:6px;"><?= htmlspecialchars($tData['code']) ?></span><?php endif; ?>
                </div>
                <div style="color:rgba(255,255,255,.7);font-size:.72rem;display:flex;align-items:center;gap:6px;">
                    <?= count($students) ?> นักเรียน
                    <span style="background:rgba(255,255,255,.15);border-radius:99px;padding:1px 7px;font-size:.65rem;">
                        <?= $isLinked ? '🔗 FK linked' : '⚠️ text only' ?>
                    </span>
                </div>
            </div>
        </div>
        <div style="text-align:right;">
            <span id="chev-<?= $cardId ?>" style="color:#fff;font-size:.9rem;display:inline-block;transition:transform .2s;">▼</span>
        </div>
    </div>

    <div id="<?= $cardId ?>">
        <div class="tr-students">
        <?php foreach ($students as $s):
            $sPct      = (int)$s['total_classes'] > 0 ? round((int)$s['completed_classes']/(int)$s['total_classes']*100) : 0;
            $remain    = max(0, (int)$s['total_classes'] - (int)$s['completed_classes']);
            $stuLinked = !empty($s['stu_id']);
        ?>
        <div class="tr-stu-card" style="<?= $stuLinked?'border-color:#fff7ed':'border-color:#fef3c7' ?>;position:relative;">
            <?php if ($stuLinked): ?>
            <button onclick="confirmDelSchedule('<?= htmlspecialchars($s['stu_id']) ?>','<?= htmlspecialchars($s['stu_name']) ?>')"
                title="ลบตารางเรียนของนักเรียนนี้"
                style="position:absolute;top:6px;right:6px;background:none;border:none;cursor:pointer;color:#dc2626;font-size:.9rem;padding:2px;line-height:1;opacity:.6;"
                onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.6">🗑</button>
            <?php endif; ?>

            <!-- ชื่อ + รหัส -->
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                <div style="width:30px;height:30px;border-radius:50%;background:<?= trAvatarColor($s['stu_name']) ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;flex-shrink:0;">
                    <?= htmlspecialchars(trInitials($s['stu_name'])) ?>
                </div>
                <div style="min-width:0;">
                    <div style="font-weight:600;font-size:.85rem;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($s['stu_name']) ?></div>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <?php if ($s['stu_code']): ?><span class="tr-badge" style="background:#fff7ed;color:#9a3412;"><?= htmlspecialchars($s['stu_code']) ?></span><?php endif; ?>
                        <span class="tr-badge <?= $stuLinked?'tr-linked':'tr-unlinked' ?>"><?= $stuLinked?'🔗':'⚠️' ?></span>
                    </div>
                </div>
            </div>

            <!-- รายการตาราง (แสดงทุก schedule พร้อมสถานะ) -->
            <?php foreach ($s['schedules'] as $sch):
                $schDay     = $dayThMap[ucfirst(strtolower($sch['day_of_week']))] ?? $sch['day_of_week'];
                $schDone    = ($sch['status'] ?? 'active') === 'completed';
                $schLineClr = $schDone ? '#9ca3af' : '#6b7280';
            ?>
            <div style="font-size:.73rem;color:<?= $schLineClr ?>;margin-top:3px;display:flex;align-items:center;gap:4px;flex-wrap:wrap;<?= $schDone?'text-decoration:line-through;opacity:.6;':'' ?>">
                <?php if ($sch['day_of_week'] || $sch['time_start']): ?>
                <span>🗓 <?= htmlspecialchars($schDay) ?><?= $sch['time_start']?' '.fmtTimePM($sch['time_start']).($sch['time_end']?' – '.fmtTimePM($sch['time_end']):''):'' ?></span>
                <?php endif; ?>
                <?php if ($sch['course']): ?><span>📚 <?= htmlspecialchars($sch['course']) ?></span><?php endif; ?>
                <?php if ($schDone): ?><span style="text-decoration:none;opacity:1;">✅</span><?php endif; ?>
            </div>
            <?php endforeach; ?>

            <!-- Progress รวมทุก schedule -->
            <div style="margin-top:6px;">
                <div style="display:flex;justify-content:space-between;font-size:.72rem;color:#6b7280;margin-bottom:2px;">
                    <span><?= (int)$s['completed_classes'] ?>/<?= (int)$s['total_classes'] ?> คาบ (เหลือ <?= $remain ?>)</span>
                    <span style="color:<?= $sPct>=100?'#059669':'#ea580c' ?>;font-weight:700;"><?= $sPct ?>%</span>
                </div>
                <div class="tr-prog">
                    <div class="tr-prog-bar" style="width:<?= $sPct ?>%;background:<?= $sPct>=100?'#059669':($sPct>=80?'#dc2626':'#ea580c') ?>;"></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endforeach; endif; ?>

</div>

<form id="form-del-schedule" method="post" action="/MyNewShool/?q=/modules/7j/teacher_student_relations.php" style="display:none;">
    <input type="hidden" name="action" value="delete_schedule">
    <input type="hidden" name="stu_id" id="del-stu-id">
    <input type="hidden" name="teacher_back" value="<?= htmlspecialchars($selTeacher) ?>">
    <input type="hidden" name="search_back" value="<?= htmlspecialchars($search) ?>">
</form>

<script>
function toggleTR(id) {
    var el   = document.getElementById(id);
    var chev = document.getElementById('chev-' + id);
    var open = el.style.display !== 'none' && el.style.display !== '';
    el.style.display = open ? 'none' : '';
    if (chev) chev.style.transform = open ? 'rotate(-90deg)' : 'rotate(0deg)';
}
function confirmDelSchedule(stuId, stuName) {
    if (!confirm('ลบตารางเรียนทั้งหมดของ "' + stuName + '" ใช่หรือไม่?\n(ประวัติการเรียนจะถูกลบด้วย)')) return;
    document.getElementById('del-stu-id').value = stuId;
    document.getElementById('form-del-schedule').submit();
}
</script>
