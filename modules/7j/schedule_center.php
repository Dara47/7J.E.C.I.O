<?php
/* =====================================================================
 * 7J English Center — Schedule Center (รวม 3 เมนู)
 * Tab 1: ว่างครู (mini weekly grid per teacher)
 * Tab 2: จองคาบ + ปฏิทิน (overflow-safe calendar)
 * Tab 3: ตารางนักเรียน (grouped, paginated)
 * เวลาทั้งหมดแสดงแบบ 24H
 * ===================================================================== */

$SC_URL    = '/modules/7j/schedule_center.php';
$DAYS_CAP  = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$DAYS_TH   = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
$DAYS_EN   = ['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];
$DAYS_SHORT= ['อา','จ','อ','พ','พฤ','ศ','ส'];

// ── Helpers ───────────────────────────────────────────────────────────────────
function sc24(?string $t): string  { return $t ? substr($t, 0, 5) : ''; }
function scBg(?string $n): string  { $c=['#ea580c','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2','#65a30d']; return $c[array_sum(array_map('ord',str_split($n??'?')))%count($c)]; }
function scIni(?string $n): string { $i=''; foreach(preg_split('/\s+/',trim($n??''))as$w)if($w)$i.=mb_strtoupper(mb_substr($w,0,1)); return mb_substr($i,0,2)?:'?'; }
function scQ($c,$v)  { return $c->quote(trim($v??'')); }
function scQN($c,$v) { $s=trim($v??''); return $s===''?'NULL':$c->quote($s); }
function scSync($c,$sid) {
    if (!$sid) return;
    $st=$c->prepare("SELECT COALESCE(SUM(total_classes),0) FROM sevenj_schedule WHERE student_id=?"); $st->execute([$sid]);
    $c->prepare("UPDATE sevenj_students SET totalClasses=? WHERE id=?")->execute([(int)$st->fetchColumn(),$sid]);
}
function scExp(string $d,string $e,string $td,int $nm): bool {
    if ($d<$td) return true;
    if ($d===$td&&$e){[$h,$m]=array_pad(explode(':',$e),2,'00');return $nm>(int)$h*60+(int)$m;}
    return false;
}
function scBkd(string $s,string $e,array $ds): array {
    $b=[];
    foreach ($ds as $d){$ss=$d['time_start']??'';$se=$d['time_end']??'';if($ss<$e&&($se===''||$se>$s))$b[]=$d;}
    return $b;
}

function scTimeHtml(string $name, string $id='', string $def='09:00', bool $opt=false): string {
    $p=explode(':',$def.':00'); $hD=str_pad($p[0]??'09',2,'0',STR_PAD_LEFT);
    $mR=(int)substr($p[1]??'00',0,2); $mD=str_pad((string)(min(55,(int)round($mR/5)*5)),2,'0',STR_PAD_LEFT);
    if($opt&&!$def){$hD='';$mD='';}
    $idA=$id?" id=\"$id\"":''; $hv=($hD&&$mD)?"$hD:$mD":'';
    $sel='width:52px;padding:5px 2px;border-radius:6px;border:1px solid #d1d5db;font-family:monospace;font-size:.84rem;background:#fff;';
    $hh=($opt?'<option value="">--</option>':'');
    for($i=0;$i<24;$i++){$v=str_pad($i,2,'0',STR_PAD_LEFT);$s=($v===$hD)?' selected':'';$hh.="<option value=\"$v\"$s>$v</option>";}
    $mm=($opt?'<option value="">--</option>':'');
    foreach(['00','05','10','15','20','25','30','35','40','45','50','55']as$m){$s=($m===$mD)?' selected':'';$mm.="<option value=\"$m\"$s>$m</option>";}
    return "<span class=\"sc-time-wrap\"><select class=\"sc-hh\" onchange=\"scSync(this)\" style=\"$sel\">$hh</select><span style=\"padding:0 2px;font-weight:700;color:#374151;\">:</span><select class=\"sc-mm\" onchange=\"scSync(this)\" style=\"$sel\">$mm</select><input type=\"hidden\" name=\"$name\"$idA value=\"$hv\"></span>";
}

// ── Current time ───────────────────────────────────────────────────────────────
$today    = date('Y-m-d');
$todayDow = date('l');
$thNow    = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
$nowMins  = (int)$thNow->format('H') * 60 + (int)$thNow->format('i');

// ── Active tab ─────────────────────────────────────────────────────────────────
$activeTab = trim($_GET['tab'] ?? 'book');
if (!in_array($activeTab, ['avail','book','schedule'])) $activeTab = 'book';

// ── POST handlers ──────────────────────────────────────────────────────────────
$action  = $_POST['action']  ?? '';
$_action = $_POST['_action'] ?? '';
$msg     = '';

if ($action === 'add_slot') {
    $activeTab = 'avail';
    $tid=$_POST['teacher_id']??''; $type=($_POST['slot_type']??'')==='specific_date'?'specific_date':'weekly';
    $day=$type==='weekly'?(trim($_POST['day']??'monday')?:null):null;
    $sdate=$type==='specific_date'?(trim($_POST['specific_date']??'')?:null):null;
    $note=trim($_POST['note']??'')?:null; $starts=(array)($_POST['start_time']??[]); $ends=(array)($_POST['end_time']??[]); $ins=0;
    if (trim($tid)) {
        $st=$connection2->prepare('INSERT INTO sevenj_teacher_availability (id,teacher_id,type,day,specific_date,start_time,end_time,note) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($starts as $i=>$s){$s=trim($s);$e=trim($ends[$i]??'');if($s&&$e){$st->execute(['slot_'.time().'_'.$i.'_'.rand(10,99),trim($tid),$type,$day,$sdate,$s,$e,$note]);$ins++;}}
        $msg=$ins>0?"success|เพิ่มช่วงเวลาว่าง $ins ช่วงสำเร็จ":'error|กรุณากรอกเวลาเริ่มและสิ้นสุดอย่างน้อย 1 ช่วง';
    } else { $msg='error|กรุณากรอกข้อมูลให้ครบ'; }

} elseif ($action === 'edit_slot') {
    $activeTab = 'avail';
    $sid=trim($_POST['slot_id']??''); $type=($_POST['slot_type']??'')==='specific_date'?'specific_date':'weekly';
    $day=$type==='weekly'?(trim($_POST['day']??'monday')?:null):null;
    $sdate=$type==='specific_date'?(trim($_POST['specific_date']??'')?:null):null;
    $s=trim($_POST['start_time']??''); $e=trim($_POST['end_time']??''); $note=trim($_POST['note']??'')?:null;
    if ($sid&&$s&&$e){$connection2->prepare('UPDATE sevenj_teacher_availability SET type=?,day=?,specific_date=?,start_time=?,end_time=?,note=? WHERE id=?')->execute([$type,$day,$sdate,$s,$e,$note,$sid]);$msg='success|แก้ไขช่วงเวลาว่างสำเร็จ';}
    else $msg='error|กรุณากรอกเวลาเริ่มและสิ้นสุด';

} elseif ($action === 'delete_slot') {
    $activeTab = 'avail';
    $sid=trim($_POST['slot_id']??'');
    if ($sid){$connection2->prepare('DELETE FROM sevenj_teacher_availability WHERE id=?')->execute([$sid]);$msg='success|ลบช่วงเวลาว่างสำเร็จ';}

} elseif ($_action === 'book_slot') {
    $activeTab = 'book';
    $bTid=trim($_POST['b_teacher_id']??''); $bTn=trim($_POST['b_teacher_name']??'');
    $bStype=in_array($_POST['b_stype']??'',['weekly','one_time'])?$_POST['b_stype']:'one_time';
    $bDay=trim($_POST['b_day']??''); $bDate=trim($_POST['b_date']??'');
    $bTs=trim($_POST['b_time_start']??''); $bTe=trim($_POST['b_time_end']??'');
    $bSid=trim($_POST['b_student_id']??''); $bSc=trim($_POST['b_student_code']??''); $bSn=trim($_POST['b_student_name']??'');
    if (!$bTn||!$bSn||!$bTs||!$bTe) $msg='error|กรุณากรอกข้อมูลให้ครบ';
    elseif ($bStype==='one_time'&&!$bDate) $msg='error|กรุณาระบุวันที่';
    elseif ($bStype==='weekly'&&!$bDay) $msg='error|กรุณาระบุวันในสัปดาห์';
    else{
        // Expiry check
        $isExpBook=false;
        if($bStype==='one_time'){$isExpBook=scExp($bDate,$bTe,$today,$nowMins);}
        elseif(strtolower($todayDow)===strtolower($bDay)){[$_bTeh,$_bTem]=array_pad(explode(':',$bTe),2,'00');$isExpBook=$nowMins>(int)$_bTeh*60+(int)$_bTem;}
        if($isExpBook){$msg='error|ช่วงเวลานี้หมดเวลาแล้ว ไม่สามารถจองได้';}
        else{
            $chkQ=$connection2->prepare("SELECT COUNT(*) FROM sevenj_schedule WHERE teacher_ref_id=? AND schedule_type=? AND time_start=? AND time_end=? AND status='active' AND (total_classes=0 OR completed_classes<total_classes) AND ".($bStype==='weekly'?'day_of_week=?':'specific_date=?'));
            $chkQ->execute([$bTid?:'',$bStype,$bTs,$bTe,$bStype==='weekly'?$bDay:($bDate?:null)]);
            if($chkQ->fetchColumn()>0){$msg='error|ช่วงเวลานี้มีนักเรียนจองแล้ว ไม่สามารถจองซ้ำได้';}
            else{try{$connection2->prepare("INSERT INTO sevenj_schedule (student_id,student_code,student_name,teacher_ref_id,teacher_name,schedule_type,day_of_week,time_start,time_end,specific_date,course,total_classes,completed_classes,status,note) VALUES (?,?,?,?,?,?,?,?,?,?,'',1,0,'active','')")->execute([$bSid?:null,$bSc,$bSn,$bTid?:null,$bTn,$bStype,$bDay,$bTs,$bTe,$bDate?:null]);$msg='success|จองเวลาสำเร็จ!';}catch(Exception$ex){$msg='error|'.$ex->getMessage();}}
        }
    }

} elseif ($action==='add'||$action==='edit') {
    $activeTab = 'schedule';
    $sid=trim($_POST['student_id']??''); $tid=trim($_POST['teacher_ref_id']??'');
    $sname=trim($_POST['student_name']??''); $scode=trim($_POST['student_code']??''); $tname=trim($_POST['teacher_name']??'');
    if($sid){$r=$connection2->prepare("SELECT displayName,studentCode FROM sevenj_students WHERE id=?");$r->execute([$sid]);if($rw=$r->fetch(PDO::FETCH_ASSOC)){$sname=$rw['displayName'];$scode=$rw['studentCode']??'';}}
    if($tid){$r=$connection2->prepare("SELECT displayName FROM sevenj_teachers WHERE id=?");$r->execute([$tid]);if($rw=$r->fetch(PDO::FETCH_ASSOC))$tname=$rw['displayName'];}
    $stype=in_array($_POST['schedule_type']??'',['weekly','one_time'])?$_POST['schedule_type']:'weekly';
    $day=trim($_POST['day_of_week']??''); $ts=trim($_POST['time_start']??''); $te=trim($_POST['time_end']??'');
    $tsList=array_values(array_map('trim',(array)($_POST['time_starts']??[]))); $teList=array_values(array_map('trim',(array)($_POST['time_ends']??[])));
    if(!$ts&&!empty($tsList[0]))$ts=$tsList[0]; if(!$te&&!empty($teList[0]))$te=$teList[0];
    $sdates=array_filter(array_map('trim',(array)($_POST['specific_dates']??[]))); $sdate=$sdates?reset($sdates):'';
    $course=trim($_POST['course']??''); $total=max(1,(int)(trim($_POST['total_classes']??'20'))); $done=max(0,(int)(trim($_POST['completed_classes']??'0'))); $note3=trim($_POST['note']??'');
    if(!$sname||!$tname) $msg='error|กรุณาเลือกนักเรียนและครู';
    elseif(!$ts||!$te) $msg='error|กรุณาระบุเวลาเริ่มและเวลาจบ';
    elseif($ts>=$te) $msg='error|เวลาเริ่มต้องน้อยกว่าเวลาจบ';
    elseif($stype==='one_time'&&empty($sdates)) $msg='error|กรุณาระบุวันที่อย่างน้อย 1 วัน';
    elseif($stype==='weekly'&&!$day) $msg='error|กรุณาเลือกวันในสัปดาห์';
    else{
        if($stype==='one_time'&&$action==='add'){$nDT=new DateTime('now',new DateTimeZone('Asia/Bangkok'));$sdI=array_values($sdates);foreach($sdI as $i=>$d){if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)){$msg='error|รูปแบบวันที่ไม่ถูกต้อง: '.$d;break;}if($day){$ddow=date('l',strtotime($d));if(strcasecmp($ddow,$day)!==0){$msg='error|วันที่ '.$d.' ไม่ตรงกับวัน '.$day;break;}}$tsI=$tsList[$i]??$ts;$sDT=new DateTime($d.' '.($tsI?:'00:00'),new DateTimeZone('Asia/Bangkok'));if($sDT<=$nDT){$msg='error|วันที่ '.$d.' ผ่านมาแล้ว';break;}}}
        if(!$msg){
            $sidQ=scQN($connection2,$sid);$tidQ=scQN($connection2,$tid);$scQ2=scQ($connection2,$scode);$snQ=scQ($connection2,$sname);$tnQ=scQ($connection2,$tname);$dayQ=scQ($connection2,$day);$tsQ=scQ($connection2,$ts);$teQ=scQ($connection2,$te);$sdQ=$sdate?scQ($connection2,$sdate):'NULL';$crQ=scQ($connection2,$course);$noQ=scQ($connection2,$note3);
            if($action==='add'){
                if($stype==='one_time'&&count($sdates)>1){$ins3=0;$sdI2=array_values($sdates);foreach($sdI2 as $i=>$d){$dQ=scQ($connection2,$d);$tsI=!empty($tsList[$i])?$tsList[$i]:$ts;$teI=!empty($teList[$i])?$teList[$i]:$te;if(!$tsI||!$teI||$tsI>=$teI)continue;$tsIQ=scQ($connection2,$tsI);$teIQ=scQ($connection2,$teI);$connection2->query("INSERT INTO sevenj_schedule (student_id,student_code,student_name,teacher_ref_id,teacher_name,schedule_type,day_of_week,time_start,time_end,specific_date,course,total_classes,completed_classes,note) VALUES ($sidQ,$scQ2,$snQ,$tidQ,$tnQ,'one_time',$dayQ,$tsIQ,$teIQ,$dQ,$crQ,$total,$done,$noQ)");$ins3++;}$msg="success|เพิ่มตารางเรียนสำเร็จ ($ins3 วัน)";if($sid)scSync($connection2,$sid);}
                else{$connection2->query("INSERT INTO sevenj_schedule (student_id,student_code,student_name,teacher_ref_id,teacher_name,schedule_type,day_of_week,time_start,time_end,specific_date,course,total_classes,completed_classes,note) VALUES ($sidQ,$scQ2,$snQ,$tidQ,$tnQ,'$stype',$dayQ,$tsQ,$teQ,$sdQ,$crQ,$total,$done,$noQ)");$msg='success|เพิ่มตารางเรียนสำเร็จ';if($sid)scSync($connection2,$sid);}
            }else{
                $id=(int)($_POST['id']??0);
                if($id){$sg=$connection2->prepare("SELECT s.*,(SELECT COUNT(*) FROM sevenj_class_completions WHERE schedule_id=? AND completed_date=?) AS lt FROM sevenj_schedule s WHERE s.id=? LIMIT 1");$sg->execute([$id,$today,$id]);$sch=$sg->fetch(PDO::FETCH_ASSOC);$locked=false;if($sch){$nDT2=new DateTime('now',new DateTimeZone('Asia/Bangkok'));$nHM=(int)$nDT2->format('H')*60+(int)$nDT2->format('i');[$eh,$em]=array_map('intval',explode(':',($sch['time_start']??'00:00').':00'));$slHM=$eh*60+$em;if($sch['schedule_type']==='one_time'&&($sch['specific_date']??''))$locked=($sch['specific_date']<$today)||($sch['specific_date']===$today&&$nHM>=$slHM);else $locked=$nDT2->format('l')===($sch['day_of_week']??'')&&$nHM>=$slHM;if((int)($sch['lt']??0)>0)$locked=true;}if($locked)$msg='error|ไม่สามารถแก้ไขได้ — ถึงเวลาเรียนหรือบันทึกแล้ว';else{$oldS=$sch['student_id']??'';$connection2->query("UPDATE sevenj_schedule SET student_id=$sidQ,student_code=$scQ2,student_name=$snQ,teacher_ref_id=$tidQ,teacher_name=$tnQ,schedule_type='$stype',day_of_week=$dayQ,time_start=$tsQ,time_end=$teQ,specific_date=$sdQ,course=$crQ,total_classes=$total,completed_classes=$done,note=$noQ WHERE id=$id");$msg='success|อัปเดตตารางเรียนสำเร็จ';if($sid)scSync($connection2,$sid);if($oldS&&$oldS!==$sid)scSync($connection2,$oldS);}}
            }
        }
    }

} elseif($action==='delete'){
    $activeTab='schedule'; $id=(int)($_POST['id']??0);
    if($id){$r=$connection2->prepare("SELECT student_id FROM sevenj_schedule WHERE id=?");$r->execute([$id]);$ds=$r->fetchColumn();$connection2->query("DELETE FROM sevenj_schedule WHERE id=$id");$msg='success|ลบตารางเรียนสำเร็จ';if($ds)scSync($connection2,$ds);}

} elseif($action==='log_class'){
    $activeTab='schedule'; $id=(int)($_POST['id']??0); $ld=trim($_POST['log_date']??$today); $ln=trim($_POST['log_note']??'');
    if($id){$r=$connection2->prepare("SELECT * FROM sevenj_schedule WHERE id=?");$r->execute([$id]);$row=$r->fetch(PDO::FETCH_ASSOC);if($row){$tRef=$row['teacher_ref_id']?:null;if(!$tRef&&$row['teacher_name']){$rt=$connection2->prepare("SELECT id FROM sevenj_teachers WHERE displayName=? AND status='active' LIMIT 1");$rt->execute([$row['teacher_name']]);if($tr=$rt->fetch(PDO::FETCH_ASSOC))$tRef=$tr['id'];}$nd=(int)$row['completed_classes']+1;$ns=($nd>=(int)$row['total_classes'])?'completed':$row['status'];$connection2->prepare("UPDATE sevenj_schedule SET completed_classes=?,status=? WHERE id=?")->execute([$nd,$ns,$id]);if($row['student_id'])$connection2->prepare("UPDATE sevenj_students SET completedClasses=completedClasses+1 WHERE id=?")->execute([$row['student_id']]);$connection2->prepare("INSERT INTO sevenj_class_completions (schedule_id,student_id,student_code,student_name,teacher_name,teacher_ref_id,day_of_week,time_start,session_number,completed_date,note) VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute([$id,$row['student_id']?:null,$row['student_code'],$row['student_name'],$row['teacher_name'],$tRef,$row['day_of_week'],$row['time_start'],$nd,$ld,$ln]);$msg='success|บันทึกคาบที่ '.$nd.' สำเร็จ'.($ns==='completed'?' 🎉':'');}}

} elseif($action==='change_status'){
    $activeTab='schedule'; $id=(int)($_POST['id']??0); $ns=trim($_POST['new_status']??'');
    if($id&&in_array($ns,['active','completed','cancelled'])){$connection2->query("UPDATE sevenj_schedule SET status='$ns' WHERE id=$id");$msg='success|เปลี่ยนสถานะสำเร็จ';}
}

// ── Shared data ────────────────────────────────────────────────────────────────
$allT   = $connection2->query("SELECT id,displayName,teacherCode,nickname FROM sevenj_teachers WHERE status='active' ORDER BY displayName")->fetchAll(PDO::FETCH_ASSOC);
$allStu = $connection2->query("SELECT s.id,s.displayName,s.studentCode,s.nickname,s.teacherId,s.totalClasses,(SELECT COUNT(*) FROM sevenj_class_completions c WHERE c.student_id=s.id) AS actualCompleted FROM sevenj_students s WHERE s.status='active' ORDER BY s.displayName")->fetchAll(PDO::FETCH_ASSOC);

$avRows = $connection2->query("SELECT * FROM sevenj_teacher_availability ORDER BY teacher_id,type DESC,day,specific_date,start_time")->fetchAll(PDO::FETCH_ASSOC);
$avByT  = [];
foreach ($avRows as $av) $avByT[$av['teacher_id']][] = $av;

$schRows = $connection2->query("SELECT s.teacher_ref_id,s.day_of_week,s.schedule_type,s.specific_date,s.time_start,s.time_end,COALESCE(st.displayName,s.student_name) AS sdisplay FROM sevenj_schedule s LEFT JOIN sevenj_students st ON st.id=s.student_id WHERE s.status='active' AND (s.total_classes=0 OR s.completed_classes<s.total_classes)")->fetchAll(PDO::FETCH_ASSOC);
$schByTD = [];
foreach ($schRows as $sr){$tid2=$sr['teacher_ref_id']??'';if(!$tid2)continue;$dk2=$sr['schedule_type']==='one_time'&&($sr['specific_date']??'')?'__date__'.$sr['specific_date']:($sr['day_of_week']??'');$schByTD[$tid2][$dk2][]=$sr;}

// ── Tab 1 data ─────────────────────────────────────────────────────────────────
$s1 = trim($_GET['s1'] ?? '');
$w1 = $s1 ? "WHERE (t.displayName LIKE '%".addslashes($s1)."%' OR t.teacherCode LIKE '%".addslashes($s1)."%' OR t.nickname LIKE '%".addslashes($s1)."%')" : '';
$t1 = $connection2->query("SELECT id,displayName,teacherCode,nickname FROM sevenj_teachers $w1 ORDER BY displayName")->fetchAll(PDO::FETCH_ASSOC);
$sl1= $connection2->query("SELECT * FROM sevenj_teacher_availability ORDER BY start_time")->fetchAll(PDO::FETCH_ASSOC);
// group by teacher_id → day_en (lowercase) or '__sp' (specific_date)
$slByTD = [];
foreach ($sl1 as $sl){$dk3=$sl['type']==='weekly'?strtolower($sl['day']??'sunday'):'__sp';$slByTD[$sl['teacher_id']][$dk3][]=$sl;}

// per-teacher student count
$tSchCount = [];
foreach ($schRows as $sr2){$t4=$sr2['teacher_ref_id']??'';if($t4)$tSchCount[$t4]=($tSchCount[$t4]??0)+1;}

// ── Tab 2 data ─────────────────────────────────────────────────────────────────
$s2      = trim($_GET['s2'] ?? '');
$selDay  = trim($_GET['day'] ?? '');
$t2arr   = array_values(array_filter($allT, fn($t)=>!$s2||mb_stripos($t['displayName'],$s2)!==false||mb_stripos($t['teacherCode'],$s2)!==false));
$totAv=$totBk=$totEx=0;
foreach ($avRows as $av){$tid3=$av['teacher_id'];$ad=$av['type']==='specific_date'?'__date__'.$av['specific_date']:ucfirst(strtolower($av['day']??''));$ed=($av['type']==='specific_date'?($av['specific_date']??''):'')?:($ad===ucfirst(strtolower($todayDow))?$today:'9999-12-31');$totAv++;if(scExp($ed,$av['end_time'],$today,$nowMins)){$totEx++;continue;}if(!empty(scBkd($av['start_time'],$av['end_time'],$schByTD[$tid3][$ad]??[])))$totBk++;}
$totFr=$totAv-$totBk-$totEx;

// current week dates
$wStart=new DateTime('now',new DateTimeZone('Asia/Bangkok')); $wStart->modify('-'.(int)$wStart->format('w').' days');
$wDates=$wDatesISO=[];
foreach($DAYS_CAP as $i=>$d){$dt=clone $wStart;$dt->modify("+$i days");$wDatesISO[$d]=$dt->format('Y-m-d');$wDates[$d]=$dt->format('d/m');}
// calendar data
$calD=array_fill_keys($DAYS_CAP,[]);
foreach($allT as $t){foreach($avByT[$t['id']]??[]as$av){if($av['type']==='weekly'){$dc=ucfirst(strtolower($av['day']??''));if(!isset($calD[$dc]))continue;$bk=scBkd($av['start_time'],$av['end_time'],$schByTD[$t['id']][$dc]??[]);$ed2=($dc===ucfirst(strtolower($todayDow)))?$today:'9999-12-31';$calD[$dc][]=['tid'=>$t['id'],'tn'=>$t['displayName'],'tc'=>$t['teacherCode'],'s'=>$av['start_time'],'e'=>$av['end_time'],'busy'=>!empty($bk),'exp'=>scExp($ed2,$av['end_time'],$today,$nowMins),'bk'=>$bk,'av'=>$av];}else{$d2=$av['specific_date']??'';$dc2=array_search($d2,$wDatesISO);if($dc2!==false){$dk4='__date__'.$d2;$bk2=scBkd($av['start_time'],$av['end_time'],$schByTD[$t['id']][$dk4]??[]);$calD[$dc2][]=['tid'=>$t['id'],'tn'=>$t['displayName'],'tc'=>$t['teacherCode'],'s'=>$av['start_time'],'e'=>$av['end_time'],'busy'=>!empty($bk2),'exp'=>scExp($d2,$av['end_time'],$today,$nowMins),'bk'=>$bk2,'av'=>$av,'date'=>$d2,'stype'=>'one_time'];}}}}
foreach($calD as &$ds6)usort($ds6,fn($a,$b)=>strcmp($a['s'],$b['s']));unset($ds6);

// ── Tab 3 data ─────────────────────────────────────────────────────────────────
$s3=trim($_GET['s3']??''); $fTid=trim($_GET['teacher']??''); $fSt=trim($_GET['status']??'');
$pg=max(1,(int)($_GET['page']??1)); $lim=50; $off=($pg-1)*$lim;
$c3=[];$b3=[];
if($s3){$c3[]="(s.student_name LIKE ? OR s.student_code LIKE ? OR s.teacher_name LIKE ? OR s.course LIKE ?)";$l='%'.$s3.'%';$b3[]=$l;$b3[]=$l;$b3[]=$l;$b3[]=$l;}
if($fSt){if($fSt==='not_started')$c3[]="s.status='active' AND s.completed_classes=0";elseif($fSt==='in_progress')$c3[]="s.status='active' AND s.completed_classes>0 AND s.completed_classes<s.total_classes";else{$c3[]="s.status=?";$b3[]=$fSt;}}
if($fTid){$c3[]="s.teacher_ref_id=?";$b3[]=$fTid;}
$w3=$c3?'WHERE '.implode(' AND ',$c3):'';
$sc3c=$connection2->prepare("SELECT COUNT(*) FROM sevenj_schedule s $w3");$sc3c->execute($b3);$tot3=(int)$sc3c->fetchColumn();$pgs3=$tot3>0?ceil($tot3/$lim):1;
$sq3=$connection2->prepare("SELECT s.*,st.displayName AS st_name,st.studentCode AS st_code,t.displayName AS t_name,t.teacherCode AS t_code,(SELECT COUNT(*) FROM sevenj_class_completions c WHERE c.schedule_id=s.id AND c.completed_date=?) AS lt FROM sevenj_schedule s LEFT JOIN sevenj_students st ON st.id=s.student_id LEFT JOIN sevenj_teachers t ON t.id=s.teacher_ref_id $w3 ORDER BY s.created_at DESC LIMIT $lim OFFSET $off");
$sq3->execute(array_merge([$today],$b3));$scheds3=$sq3->fetchAll(PDO::FETCH_ASSOC);
$dayThMap3=array_combine($DAYS_CAP,$DAYS_TH);

[$alertT,$alertX]=$msg?explode('|',$msg,2):['',''];
?>
<?php require_once __DIR__.'/_theme.php'; ?>
<style>
/* ── Tabs ───────────────────────────────────────────────────────── */
.sc-tabs{display:flex;border-bottom:2px solid var(--g-border);margin-bottom:1.5rem;}
.sc-tab{padding:10px 22px;font-size:.88rem;font-weight:600;cursor:pointer;border:none;background:none;color:var(--g-text-xs);border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .15s;display:flex;align-items:center;gap:6px;}
.sc-tab:hover{color:var(--g-primary);background:var(--g-primary-bg);}
.sc-tab.active{color:var(--g-primary);border-bottom-color:var(--g-primary);}

/* ── Tab 1: Teacher weekly grid ─────────────────────────────────── */
.tv-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:10px;overflow:hidden;}
.tv-hdr{display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;user-select:none;transition:background .12s;}
.tv-hdr:hover{background:#fffbeb;}
.tv-avatar{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.95rem;color:#fff;flex-shrink:0;}
.tv-week{display:grid;grid-template-columns:repeat(7,1fr);border-top:2px solid #f3f4f6;}
.tv-col{border-right:1px solid #f3f4f6;}
.tv-col:last-child{border-right:none;}
.tv-col-hdr{text-align:center;padding:5px 2px;font-size:.68rem;font-weight:800;color:#fff;background:#1A2A5E;letter-spacing:.02em;}
.tv-col-hdr.today-col{background:#ea580c;}
.tv-col-body{padding:3px 3px 4px;min-height:28px;background:#fafafa;}
.tv-chip{display:block;padding:3px 4px;border-radius:5px;margin-bottom:2px;font-size:.67rem;font-family:monospace;font-weight:700;cursor:pointer;border:1px solid transparent;transition:all .1s;line-height:1.3;position:relative;}
.tv-chip-free{background:#dcfce7;color:#166534;border-color:#86efac;}
.tv-chip-busy{background:#fee2e2;color:#991b1b;border-color:#fca5a5;}
.tv-chip-exp{background:#f3f4f6;color:#b4b4b4;opacity:.6;}
.tv-chip-free:hover{background:#bbf7d0;box-shadow:0 2px 6px rgba(22,101,52,.2);}
.tv-chip-busy:hover{background:#fecaca;}
.tv-chip-add{display:block;width:100%;padding:3px;background:none;border:1.5px dashed #d1d5db;border-radius:4px;cursor:pointer;color:#d1d5db;font-size:.8rem;transition:all .12s;margin-top:1px;}
.tv-chip-add:hover{border-color:var(--g-primary);color:var(--g-primary);background:#fff7ed;}
.tv-sp-wrap{border-top:2px solid #fde68a;background:#fffbeb;padding:7px 10px;}

/* ── Day pills ──────────────────────────────────────────────────── */
.day-pills{display:flex;gap:5px;flex-wrap:wrap;}
.day-pill{padding:5px 12px;border-radius:99px;border:1.5px solid var(--g-border);background:#fff;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .12s;}
.day-pill:hover{border-color:var(--g-primary);color:var(--g-primary);}
.day-pill.selected{background:var(--g-primary);color:#fff;border-color:var(--g-primary);}

/* ── Tab 2: Calendar ─────────────────────────────────────────────── */
.sc-cal-wrap{overflow-x:auto;padding-bottom:4px;}
.sc-cal{display:grid;grid-template-columns:repeat(7,minmax(100px,1fr));gap:5px;min-width:720px;}
.sc-cal-hdr{text-align:center;padding:7px 4px 5px;font-size:.72rem;font-weight:700;border-radius:8px 8px 0 0;background:linear-gradient(135deg,#1A2A5E,#2563eb);color:#fff;line-height:1.35;}
.sc-cal-hdr.today{background:linear-gradient(135deg,#ea580c,#d97706);}
.sc-cal-body{min-height:38px;padding:3px 2px;max-height:320px;overflow-y:auto;}
.sc-chip{display:block;padding:4px 6px;border-radius:6px;margin-bottom:3px;font-size:.68rem;line-height:1.3;border:1px solid transparent;}
.sc-chip-free{background:#dcfce7;color:#166534;border-color:#86efac;cursor:pointer;}
.sc-chip-free:hover{background:#bbf7d0;}
.sc-chip-busy{background:#fee2e2;color:#991b1b;border-color:#fca5a5;}
.sc-chip-exp{background:#f3f4f6;color:#9ca3af;opacity:.55;}
.sc-cal-more{display:block;text-align:center;font-size:.68rem;color:var(--g-primary);cursor:pointer;padding:2px;font-weight:700;}

/* ── Tab 2: List view ───────────────────────────────────────────── */
.tar-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:.75rem;overflow:hidden;}
.tar-thdr{background:linear-gradient(135deg,#ea580c,#d97706);padding:9px 14px;display:flex;align-items:center;gap:10px;color:#fff;}
.tar-day-hdr{padding:5px 14px;background:#fffbeb;font-size:.74rem;font-weight:700;color:#92400e;border-bottom:1px solid #fde68a;display:flex;align-items:center;}
.tar-slot{display:flex;align-items:center;flex-wrap:wrap;gap:6px;padding:6px 14px;border-bottom:1px solid #f9fafb;font-size:.81rem;}

/* ── View toggle ─────────────────────────────────────────────────── */
.sc-vbtns{display:flex;gap:5px;margin-bottom:.75rem;}
.sc-vbtn{padding:6px 14px;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;border:1px solid var(--g-border);background:#fff;color:var(--g-text-sm);}
.sc-vbtn.active{background:var(--g-primary);color:#fff;border-color:var(--g-primary);}

/* ── Tab 3: Schedule cards ──────────────────────────────────────── */
.ms-card{background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:6px;overflow:hidden;}
.ms-avatar{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.88rem;color:#fff;flex-shrink:0;}
.ms-prog{height:5px;border-radius:99px;background:#e5e7eb;overflow:hidden;width:70px;display:inline-block;vertical-align:middle;}
.ms-prog-bar{height:100%;border-radius:99px;}
</style>

<div class="g-page">
<?php if($alertX):?><div class="g-alert g-alert-<?=$alertT==='success'?'success':'error'?>"><?=htmlspecialchars($alertX)?></div><?php endif;?>
<div class="g-header"><h2 class="g-title">📅 จัดการตาราง</h2></div>

<div class="sc-tabs">
    <button class="sc-tab <?=$activeTab==='avail'?'active':''?>" onclick="scTab('avail')">🕐 ว่างครู</button>
    <button class="sc-tab <?=$activeTab==='book'?'active':''?>"  onclick="scTab('book')">📅 จองคาบ</button>
    <button class="sc-tab <?=$activeTab==='schedule'?'active':''?>" onclick="scTab('schedule')">📋 ตารางนักเรียน</button>
</div>

<!-- ══ TAB 1 — ว่างครู (Mini Weekly Grid) ══════════════════════════ -->
<div id="tab-avail" style="<?=$activeTab!=='avail'?'display:none':''?>">
<form method="get" style="display:flex;gap:7px;margin-bottom:1rem;flex-wrap:wrap;">
    <input type="hidden" name="q" value="<?=$SC_URL?>"><input type="hidden" name="tab" value="avail">
    <input type="text" name="s1" value="<?=htmlspecialchars($s1)?>" class="g-search-input" style="flex:1;" placeholder="ค้นหาครู...">
    <button type="submit" class="g-btn g-btn-outline">🔍</button>
    <?php if($s1):?><a href="?q=<?=$SC_URL?>&tab=avail" class="g-btn g-btn-outline">✕</a><?php endif;?>
</form>

<?php if(empty($t1)):?>
<div style="text-align:center;padding:3rem;color:#9ca3af;"><?=$s1?'ไม่พบผลลัพธ์':'ยังไม่มีครูในระบบ'?></div>
<?php else: foreach($t1 as $t):
    $tSlots=$slByTD[$t['id']]??[];
    $totalSlots=array_sum(array_map('count',$tSlots));
    $tSCnt=$tSchCount[$t['id']]??0;
    $bg=scBg($t['displayName']); $ini=scIni($t['displayName']);
    $cid='tv_'.substr(md5($t['id']),0,8);
    $openDefault=$totalSlots>0;
?>
<div class="tv-card">
    <div class="tv-hdr" onclick="tvToggle('<?=$cid?>')">
        <div class="tv-avatar" style="background:<?=$bg?>;"><?=htmlspecialchars($ini)?></div>
        <div style="flex:1;min-width:0;">
            <div style="font-weight:700;font-size:.93rem;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <?=htmlspecialchars($t['displayName'])?>
                <?php if($t['teacherCode']??''):?><span class="g-badge g-badge-primary"><?=htmlspecialchars($t['teacherCode'])?></span><?php endif;?>
                <?php if($t['nickname']??''):?><span style="font-size:.72rem;color:#9ca3af;">(<?=htmlspecialchars($t['nickname'])?>)</span><?php endif;?>
            </div>
            <div style="font-size:.73rem;color:#9ca3af;margin-top:2px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <?=$totalSlots?> ช่วงว่าง
                <?php if($tSCnt>0):?><span style="color:#1e40af;font-weight:700;">👥 <?=$tSCnt?> นักเรียน</span><?php endif;?>
            </div>
        </div>
        <!-- Quick summary badges: show each day that has slots -->
        <div style="display:flex;gap:3px;flex-wrap:wrap;justify-content:flex-end;max-width:200px;">
            <?php foreach($DAYS_EN as $i=>$dv):$cnt=count($tSlots[$dv]??[]);if($cnt===0)continue;$isTd=strtolower($todayDow)===$dv;?>
            <span style="background:<?=$isTd?'#ea580c':'#1A2A5E'?>;color:#fff;border-radius:4px;padding:1px 7px;font-size:.65rem;font-weight:700;"><?=$DAYS_SHORT[$i]?><sup style="font-size:.6em;"><?=$cnt?></sup></span>
            <?php endforeach;$spCnt=count($tSlots['__sp']??[]);if($spCnt>0):?><span style="background:#d97706;color:#fff;border-radius:4px;padding:1px 7px;font-size:.65rem;font-weight:700;">📆<?=$spCnt?></span><?php endif;?>
        </div>
        <button class="g-btn g-btn-primary g-btn-sm" onclick="event.stopPropagation();avOpenAdd('<?=htmlspecialchars($t['id'])?>','<?=htmlspecialchars(addslashes($t['displayName']))?>')">+ เพิ่ม</button>
        <span id="chev_<?=$cid?>" style="color:#9ca3af;font-size:.8rem;transition:transform .2s;transform:<?=$openDefault?'rotate(0)':'rotate(-90deg)'?>;">▼</span>
    </div>

    <div id="<?=$cid?>" style="<?=!$openDefault?'display:none':''?>">
        <!-- 7-day grid -->
        <div class="tv-week">
        <?php foreach($DAYS_EN as $i=>$dv):
            $isToday=strtolower($todayDow)===$dv;
            $daySlots=array_values($tSlots[$dv]??[]);
            usort($daySlots,fn($a,$b)=>strcmp($a['start_time'],$b['start_time']));
            $dayCapKey=ucfirst($dv);
        ?>
        <div class="tv-col">
            <div class="tv-col-hdr <?=$isToday?'today-col':''?>"><?=$DAYS_SHORT[$i]?><?php if($isToday):?><div style="font-size:.52rem;font-weight:400;opacity:.85;">วันนี้</div><?php endif;?></div>
            <div class="tv-col-body">
                <?php foreach($daySlots as $slot):
                    $bkL=scBkd($slot['start_time'],$slot['end_time'],$schByTD[$t['id']][$dayCapKey]??[]);
                    $isBusy=!empty($bkL);
                    $isExp=scExp($isToday?$today:'9999-12-31',$slot['end_time'],$today,$nowMins);
                    $cls=$isExp?'tv-chip-exp':($isBusy?'tv-chip-busy':'tv-chip-free');
                    $jSlot=json_encode(['id'=>$slot['id'],'tid'=>$slot['teacher_id'],'type'=>'weekly','day'=>$dv,'specific_date'=>'','start_time'=>$slot['start_time'],'end_time'=>$slot['end_time'],'note'=>$slot['note']??''],JSON_HEX_APOS|JSON_HEX_QUOT);
                    $tipExtra='';
                    if($isBusy)$tipExtra=' ['.implode(',',array_column($bkL,'sdisplay')).']';
                    if($isExp)$tipExtra=' ⛔';
                ?>
                <div class="tv-chip <?=$cls?>" style="display:flex;align-items:center;gap:4px;padding:3px 5px;">
                    <span style="flex:1;font-family:monospace;font-size:.78rem;">
                        <?=sc24($slot['start_time'])?>–<?=sc24($slot['end_time'])?>
                        <?php if($isBusy&&!$isExp):?><span style="font-size:.6rem;opacity:.8;"> 👤<?=count($bkL)?></span><?php endif;?>
                        <?php if($isExp):?><span style="font-size:.6rem;"> ⛔</span><?php endif;?>
                    </span>
                    <?php if(!$isExp):?>
                    <button type="button" onclick="avOpenEditId('<?=htmlspecialchars($slot['id'])?>')"
                        style="flex-shrink:0;padding:1px 6px;font-size:.65rem;background:#1A2A5E;color:#fff;border:none;border-radius:4px;cursor:pointer;line-height:1.6;">แก้</button>
                    <button type="button" onclick="avConfirmDel('<?=htmlspecialchars($slot['id'])?>')"
                        style="flex-shrink:0;padding:1px 6px;font-size:.65rem;background:#dc2626;color:#fff;border:none;border-radius:4px;cursor:pointer;line-height:1.6;">ลบ</button>
                    <?php else:?>
                    <button type="button" onclick="avConfirmDel('<?=htmlspecialchars($slot['id'])?>')"
                        style="flex-shrink:0;padding:1px 6px;font-size:.65rem;background:#dc2626;color:#fff;border:none;border-radius:4px;cursor:pointer;line-height:1.6;">ลบ</button>
                    <?php endif;?>
                </div>
                <?php endforeach;?>
                <button class="tv-chip-add"
                        onclick="avOpenAdd('<?=htmlspecialchars($t['id'])?>','<?=htmlspecialchars(addslashes($t['displayName']))?>','<?=$dv?>')">+</button>
            </div>
        </div>
        <?php endforeach;?>
        </div>

        <!-- Specific-date slots -->
        <?php $spSlots=$tSlots['__sp']??[];
        if(!empty($spSlots)):
            usort($spSlots,fn($a,$b)=>strcmp($a['specific_date'],$b['specific_date']));?>
        <div class="tv-sp-wrap">
            <div style="font-size:.71rem;font-weight:700;color:#92400e;margin-bottom:5px;">📆 วันที่เฉพาะ (<?=count($spSlots)?> ช่วง)</div>
            <div style="display:flex;flex-wrap:wrap;gap:5px;">
            <?php foreach($spSlots as $slot):
                $dk5='__date__'.$slot['specific_date'];$bkL2=scBkd($slot['start_time'],$slot['end_time'],$schByTD[$t['id']][$dk5]??[]);
                $isBusy2=!empty($bkL2);$isExp2=scExp($slot['specific_date']??'',$slot['end_time'],$today,$nowMins);
                $cls2=$isExp2?'tv-chip-exp':($isBusy2?'tv-chip-busy':'tv-chip-free');
                $jSlot2=json_encode(['id'=>$slot['id'],'tid'=>$slot['teacher_id'],'type'=>'specific_date','day'=>'','specific_date'=>$slot['specific_date']??'','start_time'=>$slot['start_time'],'end_time'=>$slot['end_time'],'note'=>$slot['note']??''],JSON_HEX_APOS|JSON_HEX_QUOT);
            ?>
            <div class="tv-chip <?=$cls2?>" style="display:inline-flex;gap:4px;align-items:center;font-family:monospace;"
                 onclick="<?=$isExp2?'':'avOpenEdit('.$jSlot2.')' ?>">
                <span style="font-family:sans-serif;font-size:.63rem;opacity:.8;"><?=fmtDate($slot['specific_date'])?></span>
                <?=sc24($slot['start_time'])?>–<?=sc24($slot['end_time'])?>
                <?php if($isBusy2&&!$isExp2):?><span style="font-size:.55rem;"> 👤</span><?php endif;?>
                <?php if($isExp2):?><span style="font-size:.55rem;"> ⛔</span><?php endif;?>
                <button type="button" onclick="event.stopPropagation();avConfirmDel('<?=htmlspecialchars($slot['id'])?>')"
                        style="background:none;border:none;cursor:pointer;padding:0;margin-left:2px;color:inherit;opacity:.5;font-size:.75rem;" title="ลบ">✕</button>
            </div>
            <?php endforeach;?>
            </div>
        </div>
        <?php endif;?>
    </div>
</div>
<?php endforeach; endif;?>
</div><!-- /tab-avail -->

<!-- ══ TAB 2 — จองคาบ ══════════════════════════════════════════════ -->
<div id="tab-book" style="<?=$activeTab!=='book'?'display:none':''?>">
<!-- Stats row -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:.9rem;">
<?php foreach([['📋','ทั้งหมด',$totAv,'#6b7280','#f3f4f6'],['🟢','ว่าง',$totFr,'#166534','#dcfce7'],['🔴','จอง',$totBk,'#991b1b','#fee2e2'],['⛔','หมดเวลา',$totEx,'#6b7280','#f3f4f6']]as[$ic,$lb,$vl,$fg,$bg2]):?>
<div style="background:<?=$bg2?>;border-radius:8px;padding:.6rem .75rem;text-align:center;"><div style="font-size:1.5rem;font-weight:800;color:<?=$fg?>;"><?=$vl?></div><div style="font-size:.7rem;color:#6b7280;"><?=$ic?> <?=$lb?></div></div>
<?php endforeach;?>
</div>
<!-- Search + day filter -->
<form method="get" style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:.8rem;">
    <input type="hidden" name="q" value="<?=$SC_URL?>"><input type="hidden" name="tab" value="book">
    <input type="text" name="s2" value="<?=htmlspecialchars($s2)?>" class="g-search-input" style="flex:1;min-width:130px;" placeholder="ค้นหาครู...">
    <select name="day" class="g-select" style="width:auto;padding:8px 10px;">
        <option value="">ทุกวัน</option>
        <?php foreach($DAYS_CAP as $i=>$d):?><option value="<?=$d?>" <?=$selDay===$d?'selected':''?>><?=$DAYS_TH[$i]?></option><?php endforeach;?>
    </select>
    <button type="submit" class="g-btn g-btn-primary">🔍</button>
    <?php if($s2||$selDay):?><a href="?q=<?=$SC_URL?>&tab=book" class="g-btn g-btn-outline">✕</a><?php endif;?>
</form>
<!-- View toggle -->
<div class="sc-vbtns">
    <button class="sc-vbtn active" id="vbtn-list" onclick="scView('list')">☰ รายการ</button>
    <button class="sc-vbtn" id="vbtn-cal"          onclick="scView('cal')">🗓 ปฏิทิน</button>
</div>

<!-- LIST VIEW -->
<div id="book-view-list">
<?php $hasList=false; foreach($t2arr as $teacher):
    $tid5=$teacher['id']; $avSlots=$avByT[$tid5]??[]; if(empty($avSlots))continue; $hasList=true;
    $avByDay5=array_fill_keys($DAYS_CAP,[]); $avByDay5['__sp']=[];
    foreach($avSlots as $av5){if($av5['type']==='specific_date')$avByDay5['__sp'][]=$av5;else{$dc5=ucfirst(strtolower($av5['day']??''));if(isset($avByDay5[$dc5]))$avByDay5[$dc5][]=$av5;}}
    $tBk=$tFr=0;
    foreach($avSlots as$av5){$avD5=$av5['type']==='specific_date'?'__date__'.$av5['specific_date']:ucfirst(strtolower($av5['day']??''));$ed6=($av5['type']==='specific_date'?($av5['specific_date']??''):'')?:($avD5===ucfirst(strtolower($todayDow))?$today:'9999-12-31');if(scExp($ed6,$av5['end_time'],$today,$nowMins))continue;if(!empty(scBkd($av5['start_time'],$av5['end_time'],$schByTD[$tid5][$avD5]??[])))$tBk++;else$tFr++;}
?>
<div class="tar-card">
    <div class="tar-thdr">
        <div style="width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;"><?=mb_strtoupper(mb_substr($teacher['displayName'],0,1))?></div>
        <div style="flex:1;"><div style="font-weight:700;"><?=htmlspecialchars($teacher['displayName'])?> <span style="opacity:.7;font-size:.74rem;"><?=htmlspecialchars($teacher['teacherCode']??'')?></span></div><div style="font-size:.7rem;opacity:.75;">🟢 ว่าง <?=$tFr?> &nbsp;🔴 จอง <?=$tBk?></div></div>
    </div>
    <?php foreach($DAYS_CAP as $i5=>$dayEn5):
        if($selDay&&$selDay!==$dayEn5)continue;
        $slts5=$avByDay5[$dayEn5]??[];if(empty($slts5))continue;$isToday5=($dayEn5===$todayDow);
    ?>
    <div>
        <div class="tar-day-hdr"><?=$isToday5?'📍':'📅'?> <?=$DAYS_TH[$i5]?><?=$isToday5?' (วันนี้)':''?><span style="margin-left:auto;font-size:.68rem;color:#9ca3af;"><?=count($slts5)?> ช่วง</span></div>
        <?php foreach($slts5 as $av5b):
            $ds7=$schByTD[$tid5][$dayEn5]??[];$bks7=scBkd($av5b['start_time'],$av5b['end_time'],$ds7);
            $isBusy7=!empty($bks7);$ed7=$isToday5?$today:'9999-12-31';$isExp7=scExp($ed7,$av5b['end_time'],$today,$nowMins);
        ?>
        <div class="tar-slot" style="<?=$isExp7?'opacity:.5;background:#f9fafb;':''?>">
            <span style="font-family:monospace;font-weight:700;min-width:95px;<?=$isExp7?'text-decoration:line-through;color:#9ca3af;':''?>">⏰ <?=sc24($av5b['start_time'])?>–<?=sc24($av5b['end_time'])?></span>
            <?php if($isExp7):?><span class="g-badge g-badge-gray" style="font-size:.69rem;">⛔ หมดเวลา</span>
            <?php elseif($isBusy7):?><span class="g-badge g-badge-danger" style="font-size:.69rem;">🔴 จอง</span><?php foreach($bks7 as$bsc):?><span class="g-badge g-badge-session" style="font-size:.67rem;">👤 <?=htmlspecialchars($bsc['sdisplay'])?></span><?php endforeach;?>
            <?php else:?>
            <span class="g-badge g-badge-success" style="font-size:.69rem;">🟢 ว่าง</span>
            <?php $bD7=json_encode(['teacher_id'=>$tid5,'teacher_name'=>$teacher['displayName'],'stype'=>'weekly','day'=>$dayEn5,'date'=>'','time_start'=>$av5b['start_time'],'time_end'=>$av5b['end_time']],JSON_HEX_APOS|JSON_HEX_QUOT);?>
            <button onclick='openBookModal(<?=$bD7?>)' style="margin-left:auto;padding:3px 10px;background:#1A2A5E;color:#fff;border:none;border-radius:5px;font-size:.72rem;font-weight:700;cursor:pointer;">📅 จอง</button>
            <?php endif;?>
            <?php if($av5b['note']):?><span style="font-size:.68rem;color:#9ca3af;">📝 <?=htmlspecialchars($av5b['note'])?></span><?php endif;?>
        </div>
        <?php endforeach;?>
    </div>
    <?php endforeach;?>
    <?php if(!empty($avByDay5['__sp'])&&!$selDay):?>
    <div>
        <div class="tar-day-hdr">📆 วันที่เฉพาะ<span style="margin-left:auto;font-size:.68rem;color:#9ca3af;"><?=count($avByDay5['__sp'])?> ช่วง</span></div>
        <?php foreach($avByDay5['__sp']as$av5c):$dk5c='__date__'.$av5c['specific_date'];$bks7c=scBkd($av5c['start_time'],$av5c['end_time'],$schByTD[$tid5][$dk5c]??[]);$isBusy7c=!empty($bks7c);$isExp7c=scExp($av5c['specific_date']??'',$av5c['end_time'],$today,$nowMins);?>
        <div class="tar-slot" style="<?=$isExp7c?'opacity:.5;background:#f9fafb;':''?>">
            <span class="g-badge g-badge-warn"><?=fmtDate($av5c['specific_date'])?></span>
            <span style="font-family:monospace;font-weight:700;<?=$isExp7c?'text-decoration:line-through;color:#9ca3af;':''?>">⏰ <?=sc24($av5c['start_time'])?>–<?=sc24($av5c['end_time'])?></span>
            <?php if($isExp7c):?><span class="g-badge g-badge-gray" style="font-size:.69rem;">⛔</span>
            <?php elseif($isBusy7c):?><span class="g-badge g-badge-danger" style="font-size:.69rem;">🔴 จอง</span><?php foreach($bks7c as$bscc):?><span class="g-badge g-badge-session" style="font-size:.67rem;">👤 <?=htmlspecialchars($bscc['sdisplay'])?></span><?php endforeach;?>
            <?php else:?><span class="g-badge g-badge-success" style="font-size:.69rem;">🟢 ว่าง</span>
            <?php $bD8=json_encode(['teacher_id'=>$tid5,'teacher_name'=>$teacher['displayName'],'stype'=>'one_time','day'=>'','date'=>$av5c['specific_date'],'time_start'=>$av5c['start_time'],'time_end'=>$av5c['end_time']],JSON_HEX_APOS|JSON_HEX_QUOT);?>
            <button onclick='openBookModal(<?=$bD8?>)' style="margin-left:auto;padding:3px 10px;background:#1A2A5E;color:#fff;border:none;border-radius:5px;font-size:.72rem;font-weight:700;cursor:pointer;">📅 จอง</button>
            <?php endif;?>
        </div>
        <?php endforeach;?>
    </div>
    <?php endif;?>
</div>
<?php endforeach;if(!$hasList):?><div style="text-align:center;padding:2.5rem;color:#9ca3af;">ไม่พบข้อมูล</div><?php endif;?>
</div><!-- /book-view-list -->

<!-- CALENDAR VIEW -->
<div id="book-view-cal" style="display:none;">
<div style="font-size:.77rem;color:#9ca3af;margin-bottom:.6rem;">
    📅 สัปดาห์ <?=$wDates['Sunday']?> – <?=$wDates['Saturday']?>
    <span style="background:#fff7ed;color:#ea580c;border-radius:4px;padding:1px 8px;font-size:.68rem;margin-left:5px;">รายสัปดาห์ + วันที่ตรงสัปดาห์นี้</span>
</div>
<div class="sc-cal-wrap"><div class="sc-cal">
<?php $CAL_SHOW=5;
foreach($DAYS_CAP as $i8=>$dayEn8):
    if($selDay&&$selDay!==$dayEn8)continue;
    $isToday8=($dayEn8===$todayDow); $slots8=$calD[$dayEn8]??[];
    $freeCnt=count(array_filter($slots8,fn($s)=>!$s['busy']&&!$s['exp']));
    $busyCnt=count(array_filter($slots8,fn($s)=>$s['busy']&&!$s['exp']));
?>
<div>
    <div class="sc-cal-hdr <?=$isToday8?'today':''?>">
        <?=$DAYS_TH[$i8]?><br>
        <span style="font-size:.63rem;font-weight:400;opacity:.85;"><?=$wDates[$dayEn8]?></span>
        <?php if($freeCnt>0||$busyCnt>0):?><br><span style="font-size:.6rem;background:rgba(255,255,255,.2);border-radius:3px;padding:0 5px;">🟢<?=$freeCnt?> 🔴<?=$busyCnt?></span><?php endif;?>
    </div>
    <div class="sc-cal-body" id="cal-body-<?=$i8?>">
    <?php if(empty($slots8)):?><div style="padding:10px 4px;text-align:center;font-size:.68rem;color:#d1d5db;">—</div>
    <?php else:foreach($slots8 as$idx8=>$sl8):
        $cls8=$sl8['exp']?'sc-chip-exp':($sl8['busy']?'sc-chip-busy':'sc-chip-free');
        $stype8=$sl8['stype']??'weekly';
        $bD9=(!$sl8['busy']&&!$sl8['exp'])?json_encode(['teacher_id'=>$sl8['tid'],'teacher_name'=>$sl8['tn'],'stype'=>$stype8,'day'=>$stype8==='weekly'?$dayEn8:'','date'=>$sl8['date']??'','time_start'=>$sl8['s'],'time_end'=>$sl8['e']],JSON_HEX_APOS|JSON_HEX_QUOT):'null';
        $hidden8=$idx8>=$CAL_SHOW;
    ?>
    <div class="sc-chip <?=$cls8?>" data-cal-extra="<?=$i8?>" style="<?=$hidden8?'display:none;':''?>" <?=(!$sl8['busy']&&!$sl8['exp'])?"onclick='openBookModal($bD9)'":''?>>
        <div style="font-family:monospace;font-weight:700;font-size:.7rem;"><?=sc24($sl8['s'])?>–<?=sc24($sl8['e'])?></div>
        <div style="font-size:.65rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars($sl8['tn'])?></div>
        <?php if($sl8['busy']&&!empty($sl8['bk'])):?><div style="font-size:.6rem;opacity:.7;">👤<?=htmlspecialchars($sl8['bk'][0]['sdisplay']??'')?></div><?php endif;?>
        <?php if($sl8['exp']):?><div style="font-size:.6rem;">⛔</div><?php endif;?>
    </div>
    <?php endforeach;if(count($slots8)>$CAL_SHOW):?>
    <span class="sc-cal-more" id="cal-more-<?=$i8?>" onclick="calExpand(<?=$i8?>)">+ <?=count($slots8)-$CAL_SHOW?> เพิ่มเติม</span>
    <?php endif;endif;?>
    </div>
</div>
<?php endforeach;?>
</div></div>
</div><!-- /book-view-cal -->
</div><!-- /tab-book -->

<!-- ══ TAB 3 — ตารางนักเรียน ══════════════════════════════════════ -->
<div id="tab-schedule" style="<?=$activeTab!=='schedule'?'display:none':''?>">
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:.9rem;">
    <form method="get" style="display:flex;gap:7px;flex-wrap:wrap;flex:1;">
        <input type="hidden" name="q" value="<?=$SC_URL?>"><input type="hidden" name="tab" value="schedule">
        <?php if($fSt):?><input type="hidden" name="status" value="<?=htmlspecialchars($fSt)?>"><?php endif;?>
        <input type="text" name="s3" value="<?=htmlspecialchars($s3)?>" class="g-search-input" style="min-width:170px;flex:1;" placeholder="ค้นหาชื่อ, รหัส, ครู, คอร์ส...">
        <select name="teacher" class="g-select" style="width:auto;padding:8px 9px;" onchange="this.form.submit()">
            <option value="">ครูทั้งหมด</option>
            <?php foreach($allT as$t9):?><option value="<?=htmlspecialchars($t9['id'])?>" <?=$fTid===$t9['id']?'selected':''?>><?=htmlspecialchars($t9['displayName'])?></option><?php endforeach;?>
        </select>
        <button type="submit" class="g-btn g-btn-outline">🔍</button>
        <?php if($s3||$fSt||$fTid):?><a href="?q=<?=$SC_URL?>&tab=schedule" class="g-btn g-btn-outline">✕</a><?php endif;?>
    </form>
</div>
<div class="g-filter-tabs" style="margin-bottom:.75rem;">
<?php foreach([['','ทั้งหมด'],['not_started','ยังไม่เริ่ม'],['in_progress','กำลังเรียน'],['completed','ครบแล้ว'],['cancelled','ยกเลิก']]as[$v,$l]):?>
<a href="?q=<?=$SC_URL?>&tab=schedule<?=$s3?"&s3=".urlencode($s3):''?><?=$fTid?"&teacher=".urlencode($fTid):''?><?=$v?"&status=$v":''?>" class="g-filter-tab <?=$fSt===$v?'active':''?>"><?=$l?></a>
<?php endforeach;?>
</div>
<?php if($tot3>0):?><div style="font-size:.77rem;color:#9ca3af;margin-bottom:.6rem;"><?=$tot3?> รายการ</div><?php endif;?>

<?php if(empty($scheds3)):?>
<div style="text-align:center;padding:2.5rem;color:#9ca3af;"><div style="font-size:2rem;">📅</div><?=$s3?'ไม่พบผลลัพธ์':'ยังไม่มีตารางเรียน'?></div>
<?php else:
$grouped3=[];
foreach($scheds3 as$s9){$sk=!empty($s9['student_id'])?'id_'.(int)$s9['student_id']:'raw_'.($s9['student_code'].'|'.$s9['student_name']);$grouped3[$sk][]=$s9;}
foreach($grouped3 as$sk=>$sessions):
    $first=$sessions[0];$dName=$first['st_name']?:$first['student_name'];$dCode=$first['st_code']?:$first['student_code'];
    $cnt3=count($sessions);$gid='g'.substr(md5($sk),0,8);$bg3=scBg($dName);$ini3=scIni($dName);
?>
<div class="ms-card">
    <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;<?=$cnt3>1?'border-bottom:1px solid #f3f4f6;':''?>">
        <div class="ms-avatar" style="background:<?=$bg3?>;"><?=htmlspecialchars($ini3)?></div>
        <div style="flex:1;min-width:0;">
            <div style="font-weight:700;font-size:.92rem;display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
                <?=htmlspecialchars($dName)?>
                <?php if($dCode):?><span class="g-badge g-badge-primary"><?=htmlspecialchars($dCode)?></span><?php endif;?>
            </div>
            <?php if($cnt3>1):?><div style="font-size:.72rem;color:#9ca3af;"><?=$cnt3?> ตาราง</div><?php endif;?>
        </div>
        <?php if($cnt3>1):?>
        <button type="button" onclick="msToggleGrp('<?=$gid?>',this,<?=$cnt3-1?>)" style="background:#fff7ed;border:none;cursor:pointer;color:#ea580c;font-size:.74rem;font-weight:700;padding:3px 10px;border-radius:20px;display:flex;align-items:center;gap:3px;">
            <span class="grp-arrow" style="transition:transform .2s;transform:rotate(-90deg);">▼</span>
            <span class="grp-lbl">ดูเพิ่ม <?=$cnt3-1?></span>
        </button>
        <?php endif;?>
    </div>
    <?php foreach($sessions as$idx9=>$s9):
        $dT=$s9['t_name']?:$s9['teacher_name'];
        $done9=(int)$s9['completed_classes'];$tot9=(int)$s9['total_classes'];$pct9=$tot9>0?min(100,round($done9/$tot9*100)):0;
        $barC=$pct9>=80?'#dc2626':($pct9>=50?'#d97706':'#059669');
        $isOne=$s9['schedule_type']==='one_time';$dayLbl9=$isOne?'📅 '.fmtDate($s9['specific_date']):'🗓 '.($dayThMap3[$s9['day_of_week']]??$s9['day_of_week']);
        $nDT9=new DateTime('now',new DateTimeZone('Asia/Bangkok'));$ts9d=$nDT9->format('Y-m-d');$ts9day=$nDT9->format('l');$nm9=(int)$nDT9->format('H')*60+(int)$nDT9->format('i');
        $rtLabel='';$rtStyle='';
        if($s9['status']==='active'){$sD9=$isOne?$s9['specific_date']:'';if($sD9===$ts9d||(!$isOne&&$ts9day===($s9['day_of_week']??''))){$tsA9=$s9['time_start']?array_map('intval',explode(':',$s9['time_start'].':00')):null;$teA9=$s9['time_end']?array_map('intval',explode(':',$s9['time_end'].':00')):null;if($tsA9){$sM9=$tsA9[0]*60+$tsA9[1];$eM9=$teA9?$teA9[0]*60+$teA9[1]:$sM9+60;if($nm9<$sM9){$rtLabel='⏳ รอเรียน';$rtStyle='background:#fef3c7;color:#92400e;';}elseif($nm9<=$eM9){$rtLabel='🟢 กำลังเรียน';$rtStyle='background:#dcfce7;color:#166534;';}else{$rtLabel='✅ เรียนแล้ว';$rtStyle='background:#f0fdf4;color:#15803d;';}}}elseif($sD9<$ts9d&&$sD9!==''){$rtLabel='✅ เรียนแล้ว';$rtStyle='background:#f0fdf4;color:#15803d;';}else{$rtLabel='⏳ รอเรียน';$rtStyle='background:#fef3c7;color:#92400e;';}}
        $canEdit=true;$ltF=(int)($s9['lt']??0)>0;
        if($ltF){$canEdit=false;}else{$tsA9b=$s9['time_start']?array_map('intval',explode(':',$s9['time_start'].':00')):null;if($tsA9b){$sM9b=$tsA9b[0]*60+$tsA9b[1];if($isOne){if(($s9['specific_date']??'')<$ts9d)$canEdit=false;elseif(($s9['specific_date']??'')===$ts9d&&$nm9>=$sM9b)$canEdit=false;}else{if($ts9day===($s9['day_of_week']??'')&&$nm9>=$sM9b)$canEdit=false;}}}
    ?>
    <?php if($idx9===1):?><div id="<?=$gid?>" style="max-height:0;overflow:hidden;transition:max-height .3s ease;"><?php endif;?>
    <div style="display:flex;align-items:flex-start;gap:8px;padding:7px 14px 9px;<?=$idx9>0?'border-top:1px solid #f3f4f6;':''?>">
        <div style="flex:1;min-width:0;">
            <div style="font-size:.77rem;display:flex;flex-wrap:wrap;gap:4px;margin-bottom:3px;">
                <span style="background:#dbeafe;color:#1e40af;border-radius:5px;padding:1px 8px;">👨‍🏫 <?=htmlspecialchars($dT)?><?=$s9['t_code']?' ('.$s9['t_code'].')':''?></span>
                <?php if($s9['course']):?><span style="background:#f0fdf4;color:#166534;border-radius:5px;padding:1px 8px;">📚 <?=htmlspecialchars($s9['course'])?></span><?php endif;?>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:5px;align-items:center;font-size:.78rem;">
                <span style="background:<?=$isOne?'#fef3c7':'#d1fae5'?>;color:<?=$isOne?'#92400e':'#065f46'?>;border-radius:5px;padding:1px 8px;"><?=$dayLbl9?></span>
                <?php if($s9['time_start']):?><span style="font-family:monospace;font-weight:700;color:#374151;">⏰ <?=sc24($s9['time_start'])?><?=$s9['time_end']?' – '.sc24($s9['time_end']):''?></span><?php endif;?>
                <?php if($rtLabel):?><span style="<?=$rtStyle?>border-radius:99px;padding:1px 9px;font-size:.69rem;font-weight:700;"><?=$rtLabel?></span>
                <?php else:$stL=['active'=>'เปิดสอน','completed'=>'ครบ','cancelled'=>'ยกเลิก'];$stC=['active'=>'#dbeafe|#1e40af','completed'=>'#fef3c7|#92400e','cancelled'=>'#fee2e2|#991b1b'];[$stBg,$stFg]=explode('|',$stC[$s9['status']]??'#f3f4f6|#374151');?>
                <span style="background:<?=$stBg?>;color:<?=$stFg?>;border-radius:99px;padding:1px 9px;font-size:.69rem;font-weight:700;"><?=$stL[$s9['status']]??$s9['status']?></span>
                <?php endif;?>
            </div>
            <div style="font-size:.71rem;color:#9ca3af;margin-top:3px;display:flex;align-items:center;gap:5px;">
                คาบ <?=$done9?>/<?=$tot9?>
                <div class="ms-prog"><div class="ms-prog-bar" style="width:<?=$pct9?>%;background:<?=$barC?>;"></div></div>
                <?=$pct9?>%
                <?php if($s9['note']):?>&nbsp;·&nbsp;📝 <?=htmlspecialchars($s9['note'])?><?php endif;?>
            </div>
        </div>
        <div style="display:flex;gap:3px;flex-shrink:0;">
            <?php if($canEdit):?>
            <button class="g-btn-icon" title="แก้ไข" onclick="msOpenEdit(<?=htmlspecialchars(json_encode($s9))?>)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
            <?php else:?><span style="padding:5px;color:#d1d5db;font-size:.8rem;" title="ล็อค">🔒</span><?php endif;?>
            <button class="g-btn-icon g-btn-icon-danger" title="ลบ" onclick="msConfirmDel(<?=(int)$s9['id']?>,'<?=htmlspecialchars($dName)?>')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg></button>
        </div>
    </div>
    <?php endforeach;?>
    <?php if($cnt3>1):?></div><?php endif;?>
</div>
<?php endforeach;endif;?>
<?php if($pgs3>1):?>
<div class="g-page-nav">
    <?php if($pg>1):?><a href="?q=<?=$SC_URL?>&tab=schedule&page=<?=$pg-1?><?=$s3?"&s3=".urlencode($s3):''?>" class="g-page-btn">‹</a><?php endif;?>
    <?php for($p=max(1,$pg-2);$p<=min($pgs3,$pg+2);$p++):?><a href="?q=<?=$SC_URL?>&tab=schedule&page=<?=$p?><?=$s3?"&s3=".urlencode($s3):''?>" class="g-page-btn <?=$p===$pg?'active':''?>"><?=$p?></a><?php endfor;?>
    <?php if($pg<$pgs3):?><a href="?q=<?=$SC_URL?>&tab=schedule&page=<?=$pg+1?><?=$s3?"&s3=".urlencode($s3):''?>" class="g-page-btn">›</a><?php endif;?>
</div>
<?php endif;?>
</div><!-- /tab-schedule -->

<!-- ══ MODALS ══════════════════════════════════════════════════════ -->

<!-- ── Add Slot ─────────────────────────────────────────────────── -->
<div id="modal-av-add" class="g-modal-bg">
<div class="g-modal" style="max-width:460px;">
    <div class="g-modal-header"><h3>➕ เพิ่มช่วงเวลาว่าง — <span id="av-add-name" style="opacity:.85;"></span></h3></div>
    <form method="post" onsubmit="scSyncAll(this)">
    <div class="g-modal-body">
        <input type="hidden" name="action" value="add_slot"><input type="hidden" name="q" value="<?=$SC_URL?>"><input type="hidden" name="tab" value="avail"><input type="hidden" name="s1" value="<?=htmlspecialchars($s1)?>"><input type="hidden" name="teacher_id" id="av-add-tid">
        <div class="g-form-group"><label>ประเภท</label>
            <select name="slot_type" id="av-add-type" class="g-select" onchange="avToggleType('add')">
                <option value="specific_date">📆 วันที่เฉพาะ</option><option value="weekly">🗓 รายสัปดาห์</option>
            </select>
        </div>
        <div class="g-form-group" id="av-add-day-grp" style="display:none;"><label>เลือกวัน</label>
            <div class="day-pills"><?php foreach($DAYS_EN as$i=>$dv):?><button type="button" class="day-pill av-add-day-pill" data-day="<?=$dv?>" onclick="avSelectDay('add','<?=$dv?>')"><?=$DAYS_TH[$i]?></button><?php endforeach;?></div>
            <input type="hidden" name="day" id="av-add-day-val" value="monday">
        </div>
        <div class="g-form-group" id="av-add-date-grp"><label>วันที่</label>
            <input type="date" name="specific_date" id="av-add-date" class="g-input" onchange="avShowDay(this.value,'av-add-dayshow')">
            <span id="av-add-dayshow" style="font-size:.77rem;color:var(--g-primary);font-weight:700;margin-top:4px;display:block;"></span>
        </div>
        <div class="g-form-group">
            <label style="margin-bottom:5px;">⚡ เลือกช่วงเวลาด่วน</label>
            <div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:10px;">
                <?php foreach(range(7,21) as $h):?>
                <button type="button" onclick="avFillNext(<?=$h?>)"
                    style="padding:3px 9px;background:#fff7ed;color:#ea580c;border:1.5px solid #fed7aa;border-radius:20px;font-size:.75rem;font-family:monospace;cursor:pointer;font-weight:600;"
                    onmouseover="this.style.background='#ea580c';this.style.color='#fff';"
                    onmouseout="this.style.background='#fff7ed';this.style.color='#ea580c';"
                ><?=str_pad($h,2,'0',STR_PAD_LEFT)?>:00</button>
                <?php endforeach;?>
            </div>
            <div style="display:grid;grid-template-columns:28px 1fr 1fr 28px;gap:6px;align-items:center;margin-bottom:4px;">
                <span></span><span style="font-size:.73rem;font-weight:600;">เริ่ม (24H)</span><span style="font-size:.73rem;font-weight:600;">สิ้นสุด (24H)</span><span></span>
            </div>
            <div id="av-add-slots">
                <div class="av-slot-row" style="display:grid;grid-template-columns:28px 1fr 1fr 28px;gap:6px;align-items:center;margin-bottom:5px;">
                    <span style="font-size:.71rem;color:#9ca3af;">1</span>
                    <?=scTimeHtml('start_time[]','','09:00')?>
                    <?=scTimeHtml('end_time[]','','10:00')?>
                    <span></span>
                </div>
            </div>
            <button type="button" onclick="avAddSlotRow()"
                style="margin-top:4px;padding:4px 14px;background:#f0fdf4;color:#166534;border:1.5px dashed #86efac;border-radius:8px;font-size:.78rem;cursor:pointer;width:100%;">
                + เพิ่มช่วงเวลา
            </button>
        </div>
        <div class="g-form-group"><label>หมายเหตุ</label><textarea name="note" class="g-input" rows="2" placeholder="บันทึกเพิ่มเติม..."></textarea></div>
    </div>
    <div class="g-modal-footer"><button type="button" class="g-btn g-btn-outline" onclick="gCloseModal('av-add')">ยกเลิก</button><button type="submit" class="g-btn g-btn-primary">บันทึก</button></div>
    </form>
</div>
</div>

<!-- ── Edit Slot ─────────────────────────────────────────────────── -->
<div id="modal-av-edit" class="g-modal-bg">
<div class="g-modal" style="max-width:440px;">
    <div class="g-modal-header"><h3>✏️ แก้ไขช่วงเวลาว่าง</h3></div>
    <div class="g-modal-body">
    <form method="post">
        <input type="hidden" name="action" value="edit_slot"><input type="hidden" name="q" value="<?=$SC_URL?>"><input type="hidden" name="tab" value="avail"><input type="hidden" name="s1" value="<?=htmlspecialchars($s1)?>"><input type="hidden" name="slot_id" id="av-edit-id">
        <script>document.currentScript.closest('form').addEventListener('submit',function(){scSyncAll(this);});</script>
        <div class="g-form-group"><label>ประเภท</label>
            <select name="slot_type" id="av-edit-type" class="g-select" onchange="avToggleType('edit')">
                <option value="weekly">🗓 รายสัปดาห์</option><option value="specific_date">📆 วันที่เฉพาะ</option>
            </select>
        </div>
        <div class="g-form-group" id="av-edit-day-grp"><label>เลือกวัน</label>
            <div class="day-pills"><?php foreach($DAYS_EN as$i=>$dv):?><button type="button" class="day-pill av-edit-day-pill" data-day="<?=$dv?>" onclick="avSelectDay('edit','<?=$dv?>')"><?=$DAYS_TH[$i]?></button><?php endforeach;?></div>
            <input type="hidden" name="day" id="av-edit-day-val">
        </div>
        <div class="g-form-group" id="av-edit-date-grp" style="display:none;"><label>วันที่</label><input type="date" name="specific_date" id="av-edit-date" class="g-input"></div>
        <div class="g-grid2">
            <div class="g-form-group"><label>เวลาเริ่ม (24H)</label><?=scTimeHtml('start_time','av-edit-start','09:00')?></div>
            <div class="g-form-group"><label>เวลาสิ้นสุด (24H)</label><?=scTimeHtml('end_time','av-edit-end','10:00')?></div>
        </div>
        <div class="g-form-group"><label>หมายเหตุ</label><textarea name="note" id="av-edit-note" class="g-input" rows="2"></textarea></div>
    </div>
    <div class="g-modal-footer"><button type="button" class="g-btn g-btn-outline" onclick="gCloseModal('av-edit')">ยกเลิก</button><button type="submit" class="g-btn g-btn-primary">บันทึก</button></div>
    </form>
</div>
</div>

<!-- ── Delete Slot ───────────────────────────────────────────────── -->
<div id="modal-av-del" class="g-modal-bg">
<div class="g-modal" style="max-width:360px;">
    <div class="g-modal-header" style="background:linear-gradient(135deg,#991b1b,#dc2626);"><h3>ยืนยันการลบ</h3></div>
    <div class="g-modal-body"><p style="color:#374151;">ต้องการลบช่วงเวลาว่างนี้ใช่หรือไม่?</p></div>
    <form method="post"><input type="hidden" name="action" value="delete_slot"><input type="hidden" name="q" value="<?=$SC_URL?>"><input type="hidden" name="tab" value="avail"><input type="hidden" name="s1" value="<?=htmlspecialchars($s1)?>"><input type="hidden" name="slot_id" id="av-del-id">
        <div class="g-modal-footer"><button type="button" class="g-btn g-btn-outline" onclick="gCloseModal('av-del')">ยกเลิก</button><button type="submit" class="g-btn g-btn-danger">ลบ</button></div>
    </form>
</div>
</div>

<!-- ── Book Modal ───────────────────────────────────────────────── -->
<div id="book-modal-bg" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;overflow-y:auto;padding:20px;">
<div style="background:#fff;border-radius:14px;max-width:480px;margin:40px auto;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,.18);">
    <div style="background:linear-gradient(135deg,#1A2A5E,#2563eb);padding:13px 18px;display:flex;align-items:center;justify-content:space-between;">
        <div style="color:#fff;font-weight:700;">📅 จองช่วงเวลาเรียน</div>
        <button onclick="closeBookModal()" style="background:none;border:none;color:#fff;font-size:1.1rem;cursor:pointer;">✕</button>
    </div>
    <form method="POST" style="padding:16px 18px;">
        <input type="hidden" name="q" value="<?=$SC_URL?>"><input type="hidden" name="tab" value="book"><input type="hidden" name="_action" value="book_slot">
        <input type="hidden" name="b_teacher_id" id="b-tid"><input type="hidden" name="b_teacher_name" id="b-tname">
        <input type="hidden" name="b_stype" id="b-stype"><input type="hidden" name="b_day" id="b-day">
        <input type="hidden" name="b_date" id="b-date"><input type="hidden" name="b_time_start" id="b-tstart"><input type="hidden" name="b_time_end" id="b-tend">
        <input type="hidden" name="b_student_id" id="b-sid"><input type="hidden" name="b_student_code" id="b-scode"><input type="hidden" name="b_student_name" id="b-sname">
        <div id="b-info-box" style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:9px 13px;margin-bottom:13px;font-size:.8rem;color:#0369a1;"></div>
        <div style="margin-bottom:11px;position:relative;">
            <label style="display:block;font-size:.78rem;font-weight:700;color:#374151;margin-bottom:4px;">🔍 ค้นหานักเรียน *</label>
            <input type="text" id="b-stu-search" placeholder="พิมพ์ชื่อหรือรหัส..." autocomplete="off" oninput="bStuSearch(this.value)" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:.85rem;box-sizing:border-box;outline:none;">
            <div id="b-stu-dd" style="display:none;position:absolute;background:#fff;border:1px solid #e5e7eb;border-radius:8px;max-height:180px;overflow-y:auto;z-index:10;box-shadow:0 4px 12px rgba(0,0,0,.1);width:100%;top:100%;left:0;"></div>
            <div id="b-stu-sel" style="display:none;margin-top:5px;background:#f0fdf4;border:1px solid #86efac;border-radius:7px;padding:5px 10px;font-size:.8rem;color:#166534;"></div>
        </div>
        <div id="b-busy-warn" style="display:none;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:9px 13px;margin-bottom:12px;font-size:.82rem;color:#dc2626;font-weight:600;">⛔ ช่วงเวลานี้มีนักเรียนจองแล้ว ไม่สามารถจองซ้ำได้</div>
        <div style="display:flex;justify-content:flex-end;gap:8px;">
            <button type="button" onclick="closeBookModal()" style="padding:7px 16px;background:#e5e7eb;color:#374151;border:none;border-radius:7px;font-size:.85rem;font-weight:600;cursor:pointer;">ยกเลิก</button>
            <button type="submit" style="padding:7px 18px;background:linear-gradient(135deg,#ea580c,#d97706);color:#fff;border:none;border-radius:7px;font-size:.85rem;font-weight:700;cursor:pointer;">✅ จองเวลา</button>
        </div>
    </form>
</div>
</div>

<!-- ── Add/Edit Schedule Modal ──────────────────────────────────── -->
<div id="modal-ms-form" class="g-modal-bg">
<div class="g-modal" style="max-width:540px;">
    <div class="g-modal-header"><h3 id="ms-form-title">📅 เพิ่มตารางเรียน</h3></div>
    <div class="g-modal-body">
    <form method="post" id="ms-form">
        <input type="hidden" name="q" value="<?=$SC_URL?>"><input type="hidden" name="tab" value="schedule"><input type="hidden" name="action" id="ms-f-action" value="add"><input type="hidden" name="id" id="ms-f-id" value="0">
        <div class="g-section-label">นักเรียน</div>
        <input type="hidden" name="student_id" id="ms-f-sid">
        <div class="g-form-group" style="position:relative;">
            <label>ค้นหานักเรียน</label>
            <input type="text" id="ms-f-stu-search" class="g-input" autocomplete="off" placeholder="พิมพ์ชื่อหรือรหัส..." oninput="msStuSearch(this.value)" onfocus="msStuSearch(this.value)" style="padding-right:30px;">
            <span id="ms-f-stu-clear" onclick="msClearStu()" style="display:none;position:absolute;right:10px;top:32px;cursor:pointer;color:#9ca3af;">✕</span>
            <div id="ms-f-stu-dd" style="display:none;position:absolute;z-index:200;width:100%;background:#fff;border:1px solid #d1d5db;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:200px;overflow-y:auto;top:100%;left:0;margin-top:2px;"></div>
        </div>
        <div class="g-grid2">
            <div class="g-form-group"><label>ชื่อนักเรียน *</label><input type="text" name="student_name" id="ms-f-sname" class="g-input" required placeholder="กรอกอัตโนมัติ"></div>
            <div class="g-form-group"><label>รหัส</label><input type="text" name="student_code" id="ms-f-scode" class="g-input" placeholder="S260001"></div>
        </div>
        <div class="g-section-label">ครู</div>
        <div class="g-form-group" style="position:relative;">
            <label>ค้นหาครู</label>
            <input type="text" id="ms-f-tea-search" class="g-input" autocomplete="off" placeholder="พิมพ์ชื่อหรือรหัสครู..." oninput="msTeaSearch(this.value)" onfocus="msTeaSearch(this.value)" style="padding-right:30px;">
            <span id="ms-f-tea-clear" onclick="msClearTea()" style="display:none;position:absolute;right:10px;top:32px;cursor:pointer;color:#9ca3af;">✕</span>
            <div id="ms-f-tea-dd" style="display:none;position:absolute;z-index:200;width:100%;background:#fff;border:1px solid #d1d5db;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:200px;overflow-y:auto;top:100%;left:0;margin-top:2px;"></div>
        </div>
        <div class="g-form-group"><label>ชื่อครู *</label><input type="text" name="teacher_name" id="ms-f-tname" class="g-input" required placeholder="หรือกรอกเอง"></div>
        <input type="hidden" name="teacher_ref_id" id="ms-f-tid">
        <div id="ms-avail-hint" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:8px 11px;margin-bottom:8px;font-size:.78rem;color:#166534;"><strong>🕐 ช่วงเวลาว่าง (24H):</strong><div id="ms-avail-list" style="margin-top:4px;display:flex;flex-wrap:wrap;gap:4px;"></div></div>
        <div class="g-section-label">ตารางเรียน</div>
        <div class="g-grid2">
            <div class="g-form-group"><label>คอร์ส</label><input type="text" name="course" id="ms-f-course" class="g-input" placeholder="Basic, IELTS"></div>
            <div class="g-form-group"><label>ประเภท</label>
                <select name="schedule_type" id="ms-f-stype" class="g-select" onchange="msToggleType()">
                    <option value="weekly">🗓 รายสัปดาห์</option><option value="one_time">📆 วันเดียว/หลายวัน</option>
                </select>
            </div>
        </div>
        <div id="ms-f-week-grp" class="g-form-group"><label id="ms-f-day-lbl">วันในสัปดาห์</label>
            <select name="day_of_week" id="ms-f-day" class="g-select">
                <?php foreach($DAYS_CAP as$i=>$d):?><option value="<?=$d?>"><?=$DAYS_TH[$i]?> (<?=$d?>)</option><?php endforeach;?>
            </select>
        </div>
        <div id="ms-f-dates-grp" style="display:none;margin-bottom:10px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;">
                <label style="font-size:.78rem;font-weight:600;color:#374151;margin:0;">วันที่</label>
                <button type="button" onclick="msAddDateRow()" style="padding:3px 10px;background:#1A2A5E;color:#fff;border:none;border-radius:5px;font-size:.75rem;cursor:pointer;">+ เพิ่ม</button>
            </div>
            <div id="ms-f-dates-cont">
                <div class="ms-date-row" style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin-bottom:5px;">
                    <input type="date" name="specific_dates[]" class="g-input" style="flex:1;min-width:110px;padding:7px 8px;">
                    <?=scTimeHtml('time_starts[]')?>
                    <span style="color:#9ca3af;font-size:.78rem;">–</span>
                    <?=scTimeHtml('time_ends[]','','10:00')?>
                </div>
            </div>
        </div>
        <div id="ms-f-time-grp" class="g-grid2">
            <div class="g-form-group"><label>เวลาเริ่ม (24H)</label><?=scTimeHtml('time_start','ms-f-tstart','09:00')?></div>
            <div class="g-form-group"><label>เวลาจบ (24H)</label><?=scTimeHtml('time_end','ms-f-tend','10:00')?></div>
        </div>
        <div class="g-grid2">
            <div class="g-form-group"><label>คาบทั้งหมด</label><input type="number" name="total_classes" id="ms-f-total" class="g-input" value="20" min="1"></div>
            <div class="g-form-group"><label>คาบที่เรียนแล้ว</label><input type="number" name="completed_classes" id="ms-f-done" class="g-input" value="0" min="0"></div>
        </div>
        <div class="g-form-group"><label>หมายเหตุ</label><textarea name="note" id="ms-f-note" class="g-input" rows="2"></textarea></div>
    </div>
    <div class="g-modal-footer"><button type="button" class="g-btn g-btn-outline" onclick="msCloseModal()">ยกเลิก</button><button type="submit" class="g-btn g-btn-primary">บันทึก</button></div>
    </form>
</div>
</div>

<!-- ── Delete Schedule Modal ─────────────────────────────────────── -->
<div id="modal-ms-del" class="g-modal-bg">
<div class="g-modal" style="max-width:380px;">
    <div class="g-modal-header" style="background:linear-gradient(135deg,#991b1b,#dc2626);"><h3>ยืนยันการลบ</h3></div>
    <div class="g-modal-body"><p id="ms-del-text" style="color:#374151;"></p></div>
    <form method="post"><input type="hidden" name="action" value="delete"><input type="hidden" name="q" value="<?=$SC_URL?>"><input type="hidden" name="tab" value="schedule"><input type="hidden" name="id" id="ms-del-id">
        <div class="g-modal-footer"><button type="button" class="g-btn g-btn-outline" onclick="gCloseModal('ms-del')">ยกเลิก</button><button type="submit" class="g-btn g-btn-danger">ลบ</button></div>
    </form>
</div>
</div>

<!-- ══ JAVASCRIPT ══════════════════════════════════════════════════ -->
<script>
var _stuData=<?=json_encode(array_column($allStu,null,'id'))?>;
var _teaData=<?=json_encode(array_column($allT,null,'id'))?>;
var _slotMap=<?=json_encode(array_column($sl1,null,'id'))?>;
var _avData =<?=json_encode($avByT)?>;
var _bStus  =<?=json_encode(array_values($allStu))?>;
var _DAYSEN =<?=json_encode(array_combine($DAYS_CAP,$DAYS_TH))?>;

// ── Tab switch ────────────────────────────────────────────────────
function scTab(t){['avail','book','schedule'].forEach(function(id){var el=document.getElementById('tab-'+id);if(el)el.style.display=id===t?'':'none';var btn=document.querySelector('.sc-tab[onclick*="\''+id+'\'"]');if(btn)btn.classList.toggle('active',id===t);});try{var u=new URL(location.href);u.searchParams.set('tab',t);history.replaceState(null,'',u.toString());}catch(e){}}
function scView(v){document.getElementById('book-view-list').style.display=v==='list'?'':'none';document.getElementById('book-view-cal').style.display=v==='cal'?'':'none';document.getElementById('vbtn-list').classList.toggle('active',v==='list');document.getElementById('vbtn-cal').classList.toggle('active',v==='cal');}
function calExpand(i){document.querySelectorAll('[data-cal-extra="'+i+'"]').forEach(function(c){c.style.display='';});var m=document.getElementById('cal-more-'+i);if(m)m.style.display='none';}

// ── Tab 1: Availability ───────────────────────────────────────────
function tvToggle(id){var el=document.getElementById(id);var ch=document.getElementById('chev_'+id);if(!el)return;var open=el.style.display==='none';el.style.display=open?'block':'none';if(ch)ch.style.transform=open?'rotate(0)':'rotate(-90deg)';}
function avToggleType(pfx){var t=document.getElementById('av-'+pfx+'-type').value;document.getElementById('av-'+pfx+'-day-grp').style.display=t==='weekly'?'':'none';document.getElementById('av-'+pfx+'-date-grp').style.display=t==='specific_date'?'':'none';}
function avSelectDay(pfx,day){document.querySelectorAll('.av-'+pfx+'-day-pill').forEach(function(p){p.classList.toggle('selected',p.dataset.day===day);});document.getElementById('av-'+pfx+'-day-val').value=day;}
function avShowDay(dateStr,targetId){var el=document.getElementById(targetId);if(!el)return;if(!dateStr){el.textContent='';return;}var d=new Date(dateStr+'T00:00:00');var days=['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];el.textContent='วัน'+days[d.getDay()];}
function avOpenAdd(tid,tname,preDay){
    document.getElementById('av-add-tid').value=tid;document.getElementById('av-add-name').textContent=tname;
    if(preDay){document.getElementById('av-add-type').value='weekly';avToggleType('add');avSelectDay('add',preDay);}
    else{document.getElementById('av-add-type').value='specific_date';avToggleType('add');document.querySelectorAll('.av-add-day-pill').forEach(function(p){p.classList.remove('selected');});}
    document.getElementById('av-add-date').value='';document.getElementById('av-add-dayshow').textContent='';
    var c=document.getElementById('av-add-slots');if(c)c.innerHTML='<div class="av-slot-row" style="display:grid;grid-template-columns:28px 1fr 1fr 28px;gap:6px;align-items:center;margin-bottom:5px;"><span style="font-size:.71rem;color:#9ca3af;">1</span>'+_scTH('start_time[]','09:00')+_scTH('end_time[]','10:00')+'<span></span></div>';
    gOpenModal('av-add');
}
function scSync(sel){var w=sel.closest('.sc-time-wrap');var hh=w.querySelector('.sc-hh').value;var mm=w.querySelector('.sc-mm').value;w.querySelector('input[type=hidden]').value=(hh&&mm)?hh+':'+mm:'';}
function scSyncAll(form){form.querySelectorAll('.sc-time-wrap').forEach(function(w){var hh=w.querySelector('.sc-hh').value;var mm=w.querySelector('.sc-mm').value;var inp=w.querySelector('input[type=hidden]');if(inp)inp.value=(hh&&mm)?hh+':'+mm:'';});}
function scSetTime(id,val){var inp=document.getElementById(id);if(!inp)return;var w=inp.closest('.sc-time-wrap');if(!w){inp.value=val||'';return;}val=val||'';var p=val.split(':');var hh=p[0]?String(p[0]).padStart(2,'0'):'';var mm=p[1]?String(p[1]).substring(0,2):'';if(mm){var mn=Math.round(parseInt(mm)/5)*5;mm=String(mn>=60?55:mn).padStart(2,'0');}w.querySelector('.sc-hh').value=hh;w.querySelector('.sc-mm').value=mm;inp.value=(hh&&mm)?hh+':'+mm:'';}
function scSetTimeEl(inp,val){if(!inp)return;var w=inp.closest?inp.closest('.sc-time-wrap'):null;if(!w){inp.value=val||'';return;}val=val||'';var p=val.split(':');var hh=p[0]?String(p[0]).padStart(2,'0'):'';var mm=p[1]?String(p[1]).substring(0,2):'';if(mm){var mn=Math.round(parseInt(mm)/5)*5;mm=String(mn>=60?55:mn).padStart(2,'0');}w.querySelector('.sc-hh').value=hh;w.querySelector('.sc-mm').value=mm;inp.value=(hh&&mm)?hh+':'+mm:'';}
function _scTH(name,val,opt){val=val||'';var p=val.split(':');var hh=p[0]?String(p[0]).padStart(2,'0'):'';var mm=p[1]?String(p[1]).substring(0,2):'';if(mm){var mn=Math.round(parseInt(mm)/5)*5;mm=String(mn>=60?55:mn).padStart(2,'0');}var sel='width:52px;padding:5px 2px;border-radius:6px;border:1px solid #d1d5db;font-family:monospace;font-size:.84rem;background:#fff;';var h=opt?'<option value="">--</option>':'';for(var i=0;i<24;i++){var v=String(i).padStart(2,'0');h+='<option value="'+v+'"'+(v===hh?' selected':'')+'>'+v+'</option>';}var m=opt?'<option value="">--</option>':'';['00','05','10','15','20','25','30','35','40','45','50','55'].forEach(function(x){m+='<option value="'+x+'"'+(x===mm?' selected':'')+'>'+x+'</option>';});var hv=(hh&&mm)?hh+':'+mm:'';return '<span class="sc-time-wrap"><select class="sc-hh" onchange="scSync(this)" style="'+sel+'">'+h+'</select><span style="padding:0 2px;font-weight:700;color:#374151;">:</span><select class="sc-mm" onchange="scSync(this)" style="'+sel+'">'+m+'</select><input type="hidden" name="'+name+'" value="'+hv+'"></span>';}
function avOpenEdit(d){document.getElementById('av-edit-id').value=d.id;document.getElementById('av-edit-type').value=d.type;document.getElementById('av-edit-date').value=d.specific_date||'';scSetTime('av-edit-start',d.start_time);scSetTime('av-edit-end',d.end_time);document.getElementById('av-edit-note').value=d.note||'';avToggleType('edit');var selDay=d.day||'monday';document.getElementById('av-edit-day-val').value=selDay;document.querySelectorAll('.av-edit-day-pill').forEach(function(p){p.classList.toggle('selected',p.dataset.day===selDay);});gOpenModal('av-edit');}
function avConfirmDel(sid){document.getElementById('av-del-id').value=sid;gOpenModal('av-del');}
function avOpenEditId(id){var s=_slotMap[id];if(!s)return;avOpenEdit({id:s.id,tid:s.teacher_id,type:s.type||'weekly',day:s.day||'',specific_date:s.specific_date||'',start_time:s.start_time,end_time:s.end_time,note:s.note||'',name:''});}
function avAddSlotRow(){var c=document.getElementById('av-add-slots');var n=c.querySelectorAll('.av-slot-row').length+1;var div=document.createElement('div');div.className='av-slot-row';div.style.cssText='display:grid;grid-template-columns:28px 1fr 1fr 28px;gap:6px;align-items:center;margin-bottom:5px;';div.innerHTML='<span style="font-size:.71rem;color:#9ca3af;">'+n+'</span>'+_scTH('start_time[]','',true)+_scTH('end_time[]','',true)+'<button type="button" onclick="this.closest(\'.av-slot-row\').remove();avRenum()" style="width:24px;height:24px;background:#fee2e2;color:#dc2626;border:none;border-radius:5px;cursor:pointer;font-size:.8rem;line-height:1;">✕</button>';c.appendChild(div);}
function avRenum(){document.querySelectorAll('#av-add-slots .av-slot-row').forEach(function(r,i){var s=r.querySelector('span');if(s)s.textContent=i+1;});}
function avFillNext(h){var hh=String(h).padStart(2,'0');var hh1=String(Math.min(h+1,23)).padStart(2,'0');var rows=document.querySelectorAll('#av-add-slots .av-slot-row');for(var j=0;j<rows.length;j++){var wraps=rows[j].querySelectorAll('.sc-time-wrap');var sinp=wraps[0]&&wraps[0].querySelector('input[type=hidden]');if(sinp&&!sinp.value){wraps[0].querySelector('.sc-hh').value=hh;wraps[0].querySelector('.sc-mm').value='00';sinp.value=hh+':00';var einp=wraps[1]&&wraps[1].querySelector('input[type=hidden]');if(einp){wraps[1].querySelector('.sc-hh').value=hh1;wraps[1].querySelector('.sc-mm').value='00';einp.value=hh1+':00';}return;}}avAddSlotRow();setTimeout(function(){avFillNext(h);},10);}

// ── Tab 2: Book ───────────────────────────────────────────────────
function openBookModal(d){document.getElementById('b-tid').value=d.teacher_id||'';document.getElementById('b-tname').value=d.teacher_name||'';document.getElementById('b-stype').value=d.stype||'one_time';document.getElementById('b-day').value=d.day||'';document.getElementById('b-date').value=d.date||'';document.getElementById('b-tstart').value=d.time_start||'';document.getElementById('b-tend').value=d.time_end||'';var dLabel=d.stype==='weekly'?'🗓 ทุกวัน'+(_DAYSEN[d.day]||d.day):'📅 '+(d.date||'');document.getElementById('b-info-box').innerHTML='<strong>👨‍🏫 '+(d.teacher_name||'')+'</strong> &nbsp;|&nbsp; '+dLabel+' &nbsp;|&nbsp; ⏰ <strong>'+(d.time_start||'')+' – '+(d.time_end||'')+'</strong>';document.getElementById('b-stu-search').value='';document.getElementById('b-stu-dd').style.display='none';document.getElementById('b-stu-sel').style.display='none';document.getElementById('b-sid').value='';document.getElementById('b-scode').value='';document.getElementById('b-sname').value='';document.getElementById('book-modal-bg').style.display='block';}
function closeBookModal(){document.getElementById('book-modal-bg').style.display='none';}
document.getElementById('book-modal-bg').addEventListener('click',function(e){if(e.target===this)closeBookModal();});
var _bTmr=null;
function bStuSearch(val){var dd=document.getElementById('b-stu-dd');clearTimeout(_bTmr);val=val.trim();if(!val){dd.style.display='none';return;}_bTmr=setTimeout(function(){var q=val.toLowerCase();var res=_bStus.filter(function(s){return(s.displayName&&s.displayName.toLowerCase().indexOf(q)>=0)||(s.studentCode&&s.studentCode.toLowerCase().indexOf(q)>=0);}).slice(0,10);dd.innerHTML='';if(!res.length){dd.style.display='none';return;}res.forEach(function(s){var i=document.createElement('div');i.style.cssText='padding:8px 12px;cursor:pointer;font-size:.82rem;border-bottom:1px solid #f3f4f6;';i.innerHTML='<strong>'+_esc(s.displayName)+'</strong> <span style="color:#9ca3af;font-size:.74rem;">'+_esc(s.studentCode)+'</span>';i.onmouseover=function(){this.style.background='#f0fdf4';};i.onmouseout=function(){this.style.background='';};i.onclick=function(){document.getElementById('b-sid').value=s.id;document.getElementById('b-scode').value=s.studentCode;document.getElementById('b-sname').value=s.displayName;document.getElementById('b-stu-search').value=s.displayName+' ('+s.studentCode+')';document.getElementById('b-stu-sel').textContent='✅ '+s.displayName+' · '+s.studentCode;document.getElementById('b-stu-sel').style.display='block';dd.style.display='none';};dd.appendChild(i);});dd.style.display='block';},170);}

// ── Tab 3: Schedule ───────────────────────────────────────────────
function msOpenModal(mode){document.getElementById('ms-form-title').textContent=mode==='add'?'📅 เพิ่มตารางเรียน':'✏️ แก้ไขตารางเรียน';document.getElementById('ms-f-action').value=mode;gOpenModal('ms-form');}
function msCloseModal(){gCloseModal('ms-form');document.getElementById('ms-form').reset();document.getElementById('ms-f-action').value='add';document.getElementById('ms-f-id').value='0';msClearStu();msClearTea();document.getElementById('ms-avail-hint').style.display='none';var c=document.getElementById('ms-f-dates-cont');if(c)c.innerHTML=_msDR('','','');msToggleType();}
function _msDR(dv,ts,te){return'<div class="ms-date-row" style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin-bottom:5px;"><input type="date" name="specific_dates[]" value="'+dv+'" class="g-input" style="flex:1;min-width:110px;padding:7px 8px;">'+_scTH('time_starts[]',ts||'09:00')+'<span style="color:#9ca3af;font-size:.78rem;">–</span>'+_scTH('time_ends[]',te||'10:00')+'<button type="button" onclick="this.parentNode.remove()" style="padding:4px 8px;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:5px;cursor:pointer;font-size:.8rem;">✕</button></div>';}
function msAddDateRow(dv,ts,te){var c=document.getElementById('ms-f-dates-cont');if(!c)return;var last=c.querySelector('.ms-date-row:last-child');if(!ts&&last)ts=(last.querySelector('[name="time_starts[]"]')||{}).value||'';if(!te&&last)te=(last.querySelector('[name="time_ends[]"]')||{}).value||'';var div=document.createElement('div');div.innerHTML=_msDR(dv||'',ts||'',te||'');c.appendChild(div.firstChild);}
function msToggleType(){var t=document.getElementById('ms-f-stype').value;var lbl=document.getElementById('ms-f-day-lbl');if(lbl)lbl.textContent=t==='weekly'?'วันในสัปดาห์':'วันที่ (ตรวจสอบวัน)';document.getElementById('ms-f-week-grp').style.display='';document.getElementById('ms-f-dates-grp').style.display=t==='one_time'?'':'none';document.getElementById('ms-f-time-grp').style.display=t==='weekly'?'':'none';}
var _msStuTmr=null;
function msStuSearch(val){var dd=document.getElementById('ms-f-stu-dd');clearTimeout(_msStuTmr);val=val.trim();if(val.length<1){dd.style.display='none';return;}_msStuTmr=setTimeout(function(){var q=val.toLowerCase();var res=Object.values(_stuData).filter(function(s){return(s.displayName&&s.displayName.toLowerCase().indexOf(q)>=0)||(s.studentCode&&s.studentCode.toLowerCase().indexOf(q)>=0)||(s.nickname&&s.nickname.toLowerCase().indexOf(q)>=0);}).slice(0,20);if(!res.length){dd.innerHTML='<div style="padding:10px 14px;color:#9ca3af;font-size:.82rem;">ไม่พบนักเรียน</div>';}else{dd.innerHTML=res.map(function(s){return'<div data-sid="'+_esc(s.id)+'" style="padding:8px 14px;cursor:pointer;font-size:.84rem;border-bottom:1px solid #f3f4f6;" onmouseover="this.style.background=\'#fffbeb\'" onmouseout="this.style.background=\'\'"><span style="font-weight:600;">'+_esc(s.displayName)+'</span>'+(s.studentCode?'<span style="background:#fff7ed;color:#9a3412;border-radius:99px;padding:1px 7px;font-size:.72rem;margin-left:4px;">'+_esc(s.studentCode)+'</span>':'')+'</div>';}).join('');dd.querySelectorAll('[data-sid]').forEach(function(el){el.addEventListener('click',function(){var s=_stuData[this.dataset.sid];if(s)msSelectStu(s);});});}dd.style.display='';},140);}
function msSelectStu(s){document.getElementById('ms-f-sid').value=s.id||'';document.getElementById('ms-f-stu-search').value=s.displayName+(s.studentCode?' ('+s.studentCode+')':'');document.getElementById('ms-f-sname').value=s.displayName||'';document.getElementById('ms-f-scode').value=s.studentCode||'';document.getElementById('ms-f-stu-clear').style.display='inline';document.getElementById('ms-f-stu-dd').style.display='none';if(s.teacherId&&_teaData[s.teacherId]){document.getElementById('ms-f-tid').value=s.teacherId;document.getElementById('ms-f-tname').value=_teaData[s.teacherId].displayName||'';document.getElementById('ms-f-tea-search').value=_teaData[s.teacherId].displayName||'';document.getElementById('ms-f-tea-clear').style.display='inline';msShowAvail(s.teacherId);}if(s.totalClasses)document.getElementById('ms-f-total').value=s.totalClasses;document.getElementById('ms-f-done').value=s.actualCompleted||0;}
function msClearStu(){document.getElementById('ms-f-sid').value='';document.getElementById('ms-f-stu-search').value='';document.getElementById('ms-f-sname').value='';document.getElementById('ms-f-scode').value='';document.getElementById('ms-f-stu-clear').style.display='none';document.getElementById('ms-f-stu-dd').style.display='none';}
function msTeaSearch(q){var dd=document.getElementById('ms-f-tea-dd');if(!q.trim()){dd.style.display='none';return;}var lq=q.toLowerCase();var m=Object.values(_teaData).filter(function(t){return(t.displayName||'').toLowerCase().includes(lq)||(t.teacherCode||'').toLowerCase().includes(lq);});if(!m.length){dd.style.display='none';return;}dd.innerHTML='';m.forEach(function(t){var i=document.createElement('div');i.style.cssText='padding:9px 14px;cursor:pointer;border-bottom:1px solid #f3f4f6;font-size:.84rem;';i.innerHTML='<strong>'+t.displayName+'</strong>'+(t.teacherCode?' <span style="color:#9ca3af;font-size:.75rem;">('+t.teacherCode+')</span>':'');i.onmousedown=function(e){e.preventDefault();msSelTea(t.id);};i.onmouseover=function(){this.style.background='#f9fafb';};i.onmouseout=function(){this.style.background='';};dd.appendChild(i);});dd.style.display='block';}
function msSelTea(tid){var t=_teaData[tid];if(!t)return;document.getElementById('ms-f-tea-search').value=t.displayName+(t.teacherCode?' ('+t.teacherCode+')':'');document.getElementById('ms-f-tea-clear').style.display='inline';document.getElementById('ms-f-tea-dd').style.display='none';document.getElementById('ms-f-tid').value=tid;document.getElementById('ms-f-tname').value=t.displayName||'';msShowAvail(tid);}
function msClearTea(){document.getElementById('ms-f-tea-search').value='';document.getElementById('ms-f-tea-clear').style.display='none';document.getElementById('ms-f-tid').value='';document.getElementById('ms-f-tname').value='';document.getElementById('ms-f-tea-dd').style.display='none';document.getElementById('ms-avail-hint').style.display='none';}
function msShowAvail(tid){var hint=document.getElementById('ms-avail-hint');var list=document.getElementById('ms-avail-list');if(!tid||!_avData[tid]||!_avData[tid].length){hint.style.display='none';return;}var slots=_avData[tid].slice().sort(function(a,b){return(a.start_time||'').localeCompare(b.start_time||'');});list.innerHTML='';slots.forEach(function(s){var lbl=s.type==='weekly'?'🗓 '+(_DAYSEN[_capF(s.day)]||s.day):'📅 '+s.specific_date;var chip=document.createElement('span');chip.style.cssText='display:inline-block;background:#bbf7d0;color:#166534;border-radius:5px;padding:2px 9px;font-size:.74rem;cursor:pointer;border:1px solid #86efac;white-space:nowrap;';chip.innerHTML=lbl+' <strong>'+s.start_time+'–'+s.end_time+'</strong>';chip.onmouseover=function(){this.style.background='#4ade80';};chip.onmouseout=function(){this.style.background='#bbf7d0';};chip.onclick=function(){msApplySlot(s);};list.appendChild(chip);});hint.style.display='';}
function _capF(s){return s?s.charAt(0).toUpperCase()+s.slice(1).toLowerCase():'';}
function msApplySlot(s){var st=s.type==='weekly'?'weekly':'one_time';document.getElementById('ms-f-stype').value=st;msToggleType();if(st==='weekly'){document.getElementById('ms-f-day').value=_capF(s.day)||'Monday';scSetTime('ms-f-tstart',s.start_time||'');scSetTime('ms-f-tend',s.end_time||'');}else{var rows=document.querySelectorAll('#ms-f-dates-cont .ms-date-row');var filled=false;for(var i=0;i<rows.length;i++){var tsi=rows[i].querySelector('[name="time_starts[]"]');if(tsi&&!tsi.value){var di=rows[i].querySelector('[name="specific_dates[]"]');var tei=rows[i].querySelector('[name="time_ends[]"]');if(di&&!di.value)di.value=s.specific_date||'';scSetTimeEl(tsi,s.start_time||'');if(tei)scSetTimeEl(tei,s.end_time||'');filled=true;break;}}if(!filled)msAddDateRow(s.specific_date||'',s.start_time||'',s.end_time||'');}}
function msOpenEdit(d){document.getElementById('ms-f-action').value='edit';document.getElementById('ms-f-id').value=d.id;document.getElementById('ms-f-sid').value=d.student_id||'';var sL=(d.student_name||'')+(d.student_code?' ('+d.student_code+')':'');document.getElementById('ms-f-stu-search').value=sL;document.getElementById('ms-f-stu-clear').style.display=sL?'inline':'none';document.getElementById('ms-f-sname').value=d.student_name||'';document.getElementById('ms-f-scode').value=d.student_code||'';document.getElementById('ms-f-tid').value=d.teacher_ref_id||'';document.getElementById('ms-f-tname').value=d.teacher_name||'';document.getElementById('ms-f-course').value=d.course||'';document.getElementById('ms-f-stype').value=d.schedule_type||'weekly';document.getElementById('ms-f-day').value=d.day_of_week||'Monday';var c=document.getElementById('ms-f-dates-cont');if(c)c.innerHTML=_msDR(d.specific_date||'',d.time_start||'',d.time_end||'');scSetTime('ms-f-tstart',d.time_start||'');scSetTime('ms-f-tend',d.time_end||'');document.getElementById('ms-f-total').value=d.total_classes||20;document.getElementById('ms-f-done').value=d.completed_classes||0;document.getElementById('ms-f-note').value=d.note||'';msToggleType();msOpenModal('edit');}
function msConfirmDel(id,name){document.getElementById('ms-del-id').value=id;document.getElementById('ms-del-text').textContent='ต้องการลบตารางเรียนของ "'+name+'" ใช่หรือไม่?';gOpenModal('ms-del');}
function msToggleGrp(id,btn,extra){var el=document.getElementById(id);var arrow=btn.querySelector('.grp-arrow');var lbl=btn.querySelector('.grp-lbl');if(!el.style.maxHeight||el.style.maxHeight==='0px'){el.style.maxHeight=el.scrollHeight+'px';arrow.style.transform='';lbl.textContent='ซ่อน';}else{el.style.maxHeight='0px';arrow.style.transform='rotate(-90deg)';lbl.textContent='ดูเพิ่ม '+extra;}}

function _esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
document.addEventListener('click',function(e){
    if(!e.target.closest('#ms-f-stu-search')&&!e.target.closest('#ms-f-stu-dd')){var d=document.getElementById('ms-f-stu-dd');if(d)d.style.display='none';}
    if(!e.target.closest('#ms-f-tea-search')&&!e.target.closest('#ms-f-tea-dd')){var d=document.getElementById('ms-f-tea-dd');if(d)d.style.display='none';}
    if(!e.target.closest('#b-stu-search')&&!e.target.closest('#b-stu-dd')){var d=document.getElementById('b-stu-dd');if(d)d.style.display='none';}
});
document.addEventListener('DOMContentLoaded',function(){
    msToggleType();
    var dp=document.querySelector('.av-add-day-pill[data-day="monday"]');if(dp)dp.classList.add('selected');
});
</script>
</div><!-- /g-page -->
