<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

namespace Gibbon\UI\Dashboard;

use Gibbon\Http\Url;
use Gibbon\View\View;
use Gibbon\Data\Validator;
use Gibbon\Services\Format;
use Gibbon\Domain\System\HookGateway;
use Gibbon\Forms\OutputableInterface;
use Gibbon\Contracts\Services\Session;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Tables\Prefab\BehaviourTable;
use Gibbon\Tables\Prefab\EnrolmentTable;
use Gibbon\Tables\Prefab\FormGroupTable;
use Gibbon\Contracts\Database\Connection;
use League\Container\ContainerAwareTrait;
use Gibbon\Tables\Prefab\TodaysLessonsTable;
use League\Container\ContainerAwareInterface;
use Gibbon\Support\Facades\Access;


/**
 * Staff Dashboard View Composer
 *
 * @version  v18
 * @since    v18
 */
class StaffDashboard implements OutputableInterface, ContainerAwareInterface
{
    use ContainerAwareTrait;

    /**
     * @var \Gibbon\Contracts\Database\Connection
     */
    protected $db;

    /**
     * @var \Gibbon\Contracts\Services\Session
     */
    protected $session;

    /**
     * @var \Gibbon\Tables\Prefab\FormGroupTable
     */
    protected $formGroupTable;

    /**
     * @var \Gibbon\Tables\Prefab\EnrolmentTable
     */
    protected $enrolmentTable;

    /**
     * @var SettingGateway
     */
    private $settingGateway;

    /**
     * @var View
     */
    private $view;

    public function __construct(
        Connection $db,
        Session $session,
        FormGroupTable $formGroupTable,
        EnrolmentTable $enrolmentTable,
        SettingGateway $settingGateway,
        View $view
    ) {
        $this->db = $db;
        $this->session = $session;
        $this->formGroupTable = $formGroupTable;
        $this->enrolmentTable = $enrolmentTable;
        $this->settingGateway = $settingGateway;
        $this->view = $view;
    }

    public function getOutput()
    {
        $output = '<h2>Admin Dashboard</h2>';
        $output .= $this->render7JStats();
        $output .= $this->render7JTodaySchedule();
        return $output;
    }

    protected function render7JStats()
    {
        $pdo = $this->db->getConnection();

        // ── ดึงจาก sevenj_* tables ──────────────────────────────────────────────
        $todayDow       = date('l');
        $todayStr       = date('Y-m-d');
        $totalStudents  = (int)$pdo->query("SELECT COUNT(*) FROM sevenj_students WHERE status='active'")->fetchColumn();
        $totalTeachers  = (int)$pdo->query("SELECT COUNT(*) FROM sevenj_teachers WHERE status='active'")->fetchColumn();
        $pendingLeave   = (int)$pdo->query("SELECT COUNT(*) FROM sevenj_leave_requests WHERE status='pending'")->fetchColumn();
        $approvedLeave  = (int)$pdo->query("SELECT COUNT(*) FROM sevenj_leave_requests WHERE status='approved'")->fetchColumn();
        $todayLogins    = (int)$pdo->query("SELECT COUNT(*) FROM sevenj_login_logs WHERE log_date=CURDATE()")->fetchColumn();
        $activeSchedules= (int)$pdo->query("SELECT COUNT(*) FROM sevenj_schedule WHERE status='active'")->fetchColumn();
        $todayClasses   = (int)$pdo->query("SELECT COUNT(*) FROM sevenj_class_completions WHERE completed_date=CURDATE()")->fetchColumn();
        $monthClasses   = (int)$pdo->query("SELECT COUNT(*) FROM sevenj_class_completions WHERE YEAR(completed_date)=YEAR(CURDATE()) AND MONTH(completed_date)=MONTH(CURDATE())")->fetchColumn();
        // slots ว่างวันนี้
        $slotsToday = (int)$pdo->query("SELECT COUNT(*) FROM sevenj_teacher_availability WHERE (type='weekly' AND LOWER(day)=LOWER('$todayDow')) OR (type='specific_date' AND specific_date='$todayStr')")->fetchColumn();

        $cards = [
            [
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:2rem;height:2rem;"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5" /></svg>',
                'value' => number_format($totalStudents),
                'label' => 'นักเรียน',
                'sub'   => 'กำลังเรียนอยู่',
                'color' => '#d97706',
                'link'  => '/?q=/modules/7j/user_management.php&tab=students',
            ],
            [
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:2rem;height:2rem;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>',
                'value' => number_format($totalTeachers),
                'label' => 'ครู',
                'sub'   => 'ครูที่ active',
                'color' => '#7c3aed',
                'link'  => '/?q=/modules/7j/user_management.php&tab=teachers',
            ],
            [
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:2rem;height:2rem;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" /></svg>',
                'value' => number_format($pendingLeave),
                'label' => 'ใบลา รอพิจารณา',
                'sub'   => 'อนุมัติแล้ว '.$approvedLeave.' รายการ',
                'color' => '#dc2626',
                'link'  => '/?q=/modules/7j/leave_management.php&status=pending',
            ],
            [
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:2rem;height:2rem;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" /></svg>',
                'value' => number_format($todayLogins),
                'label' => 'เข้า Portal วันนี้',
                'sub'   => 'นักเรียน/ครู login',
                'color' => '#db2777',
                'link'  => '/?q=/modules/7j/attendance_logs.php',
            ],
        ];

        $absURL = $this->session->get('absoluteURL') ?? '';
        $html = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem;">';
        foreach ($cards as $card) {
            $href = $absURL . ($card['link'] ?? '#');
            $html .= '
            <a href="'.$href.'" style="text-decoration:none;display:block;">
            <div style="background:#fff;border-radius:12px;padding:1.1rem 1.2rem;box-shadow:0 2px 8px rgba(0,0,0,0.08);border:1px solid #f0f0f0;position:relative;overflow:hidden;height:100%;transition:box-shadow .15s;" onmouseover="this.style.boxShadow=\'0 4px 16px rgba(0,0,0,0.14)\'" onmouseout="this.style.boxShadow=\'0 2px 8px rgba(0,0,0,0.08)\'">
                <div style="position:absolute;top:1rem;right:1rem;color:'.$card['color'].';opacity:0.5;">'.$card['icon'].'</div>
                <p style="font-size:0.78rem;color:#6b7280;margin:0 0 4px;font-weight:600;">'.$card['label'].'</p>
                <p style="font-size:2rem;font-weight:800;color:'.$card['color'].';margin:0;line-height:1;">'.$card['value'].'</p>
                <p style="font-size:0.72rem;color:#9ca3af;margin:4px 0 0;">'.$card['sub'].'</p>
                <div style="position:absolute;bottom:0;left:0;right:0;height:3px;background:'.$card['color'].';"></div>
            </div>
            </a>';
        }
        $html .= '</div>';

        return $html;
    }

    protected function render7JTodaySchedule()
    {
        $pdo    = $this->db->getConnection();
        $absURL = $this->session->get('absoluteURL') ?? '';
        $today  = date('Y-m-d');
        $dayName = date('l');
        $nowTime = date('H:i');

        // ─── ตารางสอนวันนี้ ──────────────────────────────────────────────
        $todaySchedules = $pdo->query("
            SELECT s.*,
                COALESCE(st.displayName, s.student_name) AS disp_student,
                COALESCE(st.studentCode, s.student_code) AS disp_code,
                COALESCE(t.displayName,  s.teacher_name) AS disp_teacher,
                (SELECT COUNT(*) FROM sevenj_class_completions c
                 WHERE c.schedule_id = s.id AND c.completed_date = '$today') AS logged_today
            FROM sevenj_schedule s
            LEFT JOIN sevenj_students st ON st.id = s.student_id
            LEFT JOIN sevenj_teachers t  ON t.id  = s.teacher_ref_id
            WHERE s.status = 'active'
              AND s.completed_classes < s.total_classes
              AND (
                  (s.schedule_type = 'weekly'   AND LOWER(s.day_of_week) = LOWER('$dayName'))
                  OR (s.schedule_type = 'one_time' AND s.specific_date = '$today')
              )
            ORDER BY s.time_start
        ")->fetchAll(\PDO::FETCH_ASSOC);

        // ─── นักเรียนเรียนครบ — ต้องต่อคอร์ส ────────────────────────────
        $needRenew = $pdo->query("
            SELECT s.id, s.displayName, s.studentCode, s.totalClasses, s.completedClasses,
                   t.displayName AS teacherName
            FROM sevenj_students s
            LEFT JOIN sevenj_teachers t ON t.id = s.teacherId
            WHERE s.status = 'active'
              AND s.totalClasses > 0
              AND s.completedClasses >= s.totalClasses
            ORDER BY s.displayName
            LIMIT 10
        ")->fetchAll(\PDO::FETCH_ASSOC);

        // ─── ใบลา รออนุมัติ ──────────────────────────────────────────────
        $pendingLeaves = $pdo->query("
            SELECT lr.*,
                COALESCE(st.displayName, t.displayName, lr.requester_name) AS disp_name,
                COALESCE(st.studentCode, t.teacherCode, '') AS disp_code,
                lr.requester_role
            FROM sevenj_leave_requests lr
            LEFT JOIN sevenj_students st ON st.id = lr.requester_id AND lr.requester_role = 'student'
            LEFT JOIN sevenj_teachers t  ON t.id  = lr.requester_id AND lr.requester_role = 'teacher'
            WHERE lr.status = 'pending'
            ORDER BY lr.created_at DESC
            LIMIT 8
        ")->fetchAll(\PDO::FETCH_ASSOC);

        // แปลงเวลาปัจจุบันเป็นนาที เพื่อใช้เปรียบเทียบแบบ numeric (หลีกเลี่ยง string comparison ผิดพลาด)
        $nowMins = (int)date('H') * 60 + (int)date('i');

        // นับสถานะการเรียนวันนี้
        $cntWait = 0; $cntActive = 0; $cntDone = 0; $cntOver = 0;
        foreach ($todaySchedules as $s) {
            $logged     = (int)$s['logged_today'] > 0;
            $eParts     = $s['time_end']   ? explode(':', $s['time_end'])   : ['99','59'];
            $sParts     = $s['time_start'] ? explode(':', $s['time_start']) : ['99','59'];
            $endMins    = (int)$eParts[0] * 60 + (int)($eParts[1] ?? 0);
            $startMins  = (int)$sParts[0] * 60 + (int)($sParts[1] ?? 0);
            $isOver   = $nowMins >= $endMins;
            $isActive = $nowMins >= $startMins && !$isOver;
            if ($logged)       $cntDone++;
            elseif ($isActive) $cntActive++;
            elseif ($isOver)   $cntOver++;
            else               $cntWait++;
        }

        $html = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;margin-bottom:1rem;">';

        // ── Card 1: ตารางสอนวันนี้ + สถานะ ─────────────────────────────
        $html .= '<div style="background:#fff;border-radius:12px;padding:1.2rem;box-shadow:0 2px 8px rgba(0,0,0,.08);border:1px solid #f0f0f0;">';
        $html .= '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;">';
        $html .= '<h3 style="margin:0;font-size:.95rem;font-weight:700;color:#1f2937;">📅 ตารางสอนวันนี้</h3>';
        $html .= '<span style="background:#fff7ed;color:#ea580c;border-radius:99px;padding:1px 10px;font-size:.75rem;font-weight:700;">'.count($todaySchedules).' คาบ</span>';
        $html .= '</div>';
        // สรุปสถานะ
        if (!empty($todaySchedules)) {
            $html .= '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:.75rem;">';
            if ($cntWait)   $html .= '<span style="background:#fef9c3;color:#713f12;border-radius:99px;padding:2px 9px;font-size:.72rem;font-weight:700;">⏳ รอเรียน '.$cntWait.'</span>';
            if ($cntActive) $html .= '<span style="background:#dcfce7;color:#166534;border-radius:99px;padding:2px 9px;font-size:.72rem;font-weight:700;">🟢 กำลังเรียน '.$cntActive.'</span>';
            if ($cntDone)   $html .= '<span style="background:#dbeafe;color:#1e40af;border-radius:99px;padding:2px 9px;font-size:.72rem;font-weight:700;">✅ เรียนแล้ว '.$cntDone.'</span>';
            if ($cntOver)   $html .= '<span style="background:#f3f4f6;color:#6b7280;border-radius:99px;padding:2px 9px;font-size:.72rem;font-weight:700;">⛔ หมดเวลา '.$cntOver.'</span>';
            $html .= '</div>';
        }

        if (empty($todaySchedules)) {
            $html .= '<p style="color:#9ca3af;font-size:.85rem;text-align:center;padding:1rem 0;">ไม่มีตารางสอนวันนี้</p>';
        } else {
            foreach ($todaySchedules as $s) {
                $logged     = (int)$s['logged_today'] > 0;
                $eParts     = $s['time_end']   ? explode(':', $s['time_end'])   : ['99','59'];
                $sParts     = $s['time_start'] ? explode(':', $s['time_start']) : ['99','59'];
                $endMins    = (int)$eParts[0] * 60 + (int)($eParts[1] ?? 0);
                $startMins  = (int)$sParts[0] * 60 + (int)($sParts[1] ?? 0);
                $isOver   = $nowMins >= $endMins;
                $isActive = $nowMins >= $startMins && !$isOver;
                $statusDot = $logged ? '✅' : ($isActive ? '🟢' : ($isOver ? '⛔' : '⏳'));
                $statusLbl = $logged ? 'เรียนแล้ว' : ($isActive ? 'กำลังเรียน' : ($isOver ? 'หมดเวลา' : 'รอเรียน'));
                $bdColor   = $logged ? '#059669' : ($isActive ? '#3b82f6' : ($isOver ? '#d1d5db' : '#fbbf24'));
                $bg        = $logged ? '#f0fdf4' : ($isActive ? '#eff6ff' : ($isOver ? '#f9fafb' : '#fff'));
                $html .= '<div style="background:'.$bg.';border-radius:8px;padding:7px 10px;margin-bottom:5px;border-left:3px solid '.$bdColor.';'.($isOver?'opacity:.6;':'').'">';
                $html .= '<div style="display:flex;justify-content:space-between;align-items:center;">';
                $html .= '<span style="font-weight:600;color:#1f2937;font-size:.83rem;">'.$statusDot.' '.htmlspecialchars($s['disp_student']).'</span>';
                $html .= '<span style="font-family:monospace;color:#6b7280;font-size:.75rem;">'.htmlspecialchars($s['time_start']).'–'.htmlspecialchars($s['time_end'] ?? '').'</span>';
                $html .= '</div>';
                $html .= '<div style="font-size:.73rem;color:#6b7280;margin-top:2px;">👨‍🏫 '.htmlspecialchars($s['disp_teacher']).' · '.htmlspecialchars($s['disp_code']).'</div>';
                $html .= '</div>';
            }
        }
        $html .= '<div style="text-align:right;margin-top:8px;"><a href="'.$absURL.'/?q=/modules/7j/teacher_schedule.php" style="font-size:.78rem;color:#ea580c;text-decoration:none;font-weight:600;">ดูทั้งหมด →</a></div>';
        $html .= '</div>';

        // ── Card 2: ใบลา รออนุมัติ ──────────────────────────────────────
        $html .= '<div style="background:#fff;border-radius:12px;padding:1.2rem;box-shadow:0 2px 8px rgba(0,0,0,.08);border:1px solid #fecaca;">';
        $html .= '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;">';
        $html .= '<h3 style="margin:0;font-size:.95rem;font-weight:700;color:#1f2937;">📋 ใบลา รออนุมัติ</h3>';
        $html .= '<span style="background:#fee2e2;color:#991b1b;border-radius:99px;padding:1px 10px;font-size:.75rem;font-weight:700;">'.count($pendingLeaves).' รายการ</span>';
        $html .= '</div>';
        if (empty($pendingLeaves)) {
            $html .= '<p style="color:#9ca3af;font-size:.85rem;text-align:center;padding:1rem 0;">✅ ไม่มีใบลารออนุมัติ</p>';
        } else {
            foreach ($pendingLeaves as $lr) {
                $role    = $lr['requester_role'] ?? 'student';
                $roleLbl = $role === 'teacher' ? '👨‍🏫 ครู' : '🎓 นักเรียน';
                $roleBg  = $role === 'teacher' ? '#ede9fe' : '#fef3c7';
                $roleClr = $role === 'teacher' ? '#6d28d9' : '#92400e';
                $html .= '<div style="background:#fff5f5;border-radius:8px;padding:7px 10px;margin-bottom:5px;border-left:3px solid #f87171;">';
                $html .= '<div style="display:flex;justify-content:space-between;align-items:center;">';
                $html .= '<span style="font-weight:600;color:#1f2937;font-size:.83rem;">'.htmlspecialchars($lr['disp_name'] ?? '—').'</span>';
                $html .= '<span style="background:'.$roleBg.';color:'.$roleClr.';border-radius:99px;padding:1px 7px;font-size:.7rem;font-weight:700;">'.$roleLbl.'</span>';
                $html .= '</div>';
                $html .= '<div style="font-size:.73rem;color:#6b7280;margin-top:2px;">';
                $html .= htmlspecialchars($lr['disp_code'] ?? '').' · '.htmlspecialchars($lr['leave_date'] ?? $lr['created_at'] ?? '');
                if (!empty($lr['reason'])) $html .= ' · '.mb_strimwidth(htmlspecialchars($lr['reason']), 0, 30, '…');
                $html .= '</div>';
                $html .= '</div>';
            }
        }
        $html .= '<div style="text-align:right;margin-top:8px;"><a href="'.$absURL.'/?q=/modules/7j/leave_management.php&status=pending" style="font-size:.78rem;color:#dc2626;text-decoration:none;font-weight:600;">จัดการใบลา →</a></div>';
        $html .= '</div>';

        $html .= '</div>'; // end grid
        return $html;
    }

    protected function render7JLoginHistory()
    {
        $pdo = $this->db->getConnection();
        $absURL = $this->session->get('absoluteURL') ?? '';

        // ดึงจาก sevenj_login_logs + sevenj_students/teachers
        $recentLogins = $pdo->query("
            SELECT l.log_timestamp, l.log_date, l.userName, l.userCode, l.role,
                COALESCE(st.displayName, t.displayName, l.userName) AS disp_name
            FROM sevenj_login_logs l
            LEFT JOIN sevenj_students st ON st.id = l.user_ref_id AND l.role='student'
            LEFT JOIN sevenj_teachers t  ON t.id  = l.user_ref_id AND l.role='teacher'
            ORDER BY l.log_timestamp DESC, l.created_at DESC
            LIMIT 15
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $roleColors = ['student'=>'#d97706','teacher'=>'#7c3aed','admin'=>'#059669'];
        $roleLabels = ['student'=>'นักเรียน','teacher'=>'ครู','admin'=>'Admin'];

        $html  = '<div style="background:#fff;border-radius:12px;padding:1.25rem 1.5rem;';
        $html .= 'box-shadow:0 2px 8px rgba(0,0,0,0.08);border:1px solid #f0f0f0;margin-bottom:1.5rem;">';
        $html .= '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">';
        $html .= '<h3 style="font-size:1rem;font-weight:700;color:#374151;margin:0;">🕐 ประวัติเข้าใช้งาน Portal ล่าสุด</h3>';
        $html .= '<a href="'.$absURL.'/?q=/modules/7j/attendance_logs.php" style="font-size:.8rem;color:#d97706;text-decoration:none;font-weight:600;">ดูทั้งหมด →</a>';
        $html .= '</div>';

        if (empty($recentLogins)) {
            $html .= '<p style="color:#9ca3af;font-size:0.85rem;text-align:center;padding:1rem 0;">ยังไม่มีประวัติการเข้าใช้งาน</p>';
        } else {
            $html .= '<div style="overflow-x:auto;">';
            $html .= '<table style="width:100%;border-collapse:collapse;font-size:0.85rem;">';
            $html .= '<thead><tr style="border-bottom:2px solid #f3f4f6;">
                <th style="text-align:left;padding:8px 12px;color:#6b7280;font-weight:600;">ชื่อ</th>
                <th style="text-align:left;padding:8px 12px;color:#6b7280;font-weight:600;">รหัส</th>
                <th style="text-align:left;padding:8px 12px;color:#6b7280;font-weight:600;">บทบาท</th>
                <th style="text-align:right;padding:8px 12px;color:#6b7280;font-weight:600;">เวลา</th>
            </tr></thead><tbody>';

            foreach ($recentLogins as $i => $row) {
                $bg    = $i % 2 === 0 ? '#fff' : '#fffbeb';
                $name  = htmlspecialchars($row['disp_name'] ?? $row['userName'] ?? '—');
                $code  = htmlspecialchars($row['userCode'] ?? '');
                $role  = $row['role'] ?? 'student';
                $color = $roleColors[$role] ?? '#6b7280';
                $rlbl  = $roleLabels[$role]  ?? $role;
                $ts    = $row['log_timestamp'] ? date('d/m H:i', strtotime($row['log_timestamp'])) : $row['log_date'];
                $html .= "<tr style='background:{$bg};border-bottom:1px solid #f3f4f6;'>
                    <td style='padding:8px 12px;color:#374151;font-weight:600;'>{$name}</td>
                    <td style='padding:8px 12px;color:#9ca3af;font-size:.78rem;font-family:monospace;'>{$code}</td>
                    <td style='padding:8px 12px;'>
                        <span style='background:".htmlspecialchars($color)."22;color:{$color};border-radius:99px;padding:1px 8px;font-size:.72rem;font-weight:700;'>{$rlbl}</span>
                    </td>
                    <td style='padding:8px 12px;color:#9ca3af;text-align:right;white-space:nowrap;'>{$ts}</td>
                </tr>";
            }
            $html .= '</tbody></table></div>';
        }

        $html .= '</div>';
        return $html;
    }

    protected function renderDashboard()
    {
        $guid = $this->session->get('guid');
        $connection2 = $this->db->getConnection();
        $gibbonPersonID = $this->session->get('gibbonPersonID');
        $session = $this->session;

        $return = false;

        $planner = false;

        // PLANNER
        if (isActionAccessible($guid, $connection2, '/modules/Planner/planner.php')) {
            $planner = $this
                ->getContainer()
                ->get(TodaysLessonsTable::class)
                ->create($session->get('gibbonSchoolYearID'), $this->session->get('gibbonPersonID'), 'Teacher')
                ->getOutput();
        }

        //GET TIMETABLE
        $timetable = false;
        if (
            isActionAccessible($guid, $connection2, '/modules/Timetable/tt.php') and $this->session->get('username') != ''
            && $this->session->get('gibbonRoleIDCurrentCategory') == 'Staff'
        ) {
            $_POST = (new Validator(''))->sanitize($_POST);
            $jsonQuery = [
                'gibbonTTID' => $_GET['gibbonTTID'] ?? '',
                'ttDate' => $_POST['ttDate'] ?? '',
            ];
            $apiEndpoint = (string)Url::fromHandlerRoute('index_tt_ajax.php')->withQueryParams($jsonQuery);

            $timetable .= '<h2>'.__('My Timetable').'</h2>';
            $timetable .= "<div hx-get='".$apiEndpoint."' hx-trigger='load' style='width: 100%; min-height: 40px; text-align: center'>";
            $timetable .= "<img style='margin: 10px 0 5px 0' src='".$this->session->get('absoluteURL')."/themes/Default/img/loading.gif' alt='".__('Loading')."' onclick='return false;' /><br/><p style='text-align: center'>".__('Loading').'</p>';
            $timetable .= '</div>';
        }

        //GET FORM GROUPS
        $formGroups = array();
        $formGroupCount = 0;
        $count = 0;

        $dataFormGroups = array('gibbonPersonIDTutor' => $this->session->get('gibbonPersonID'), 'gibbonPersonIDTutor2' => $this->session->get('gibbonPersonID'), 'gibbonPersonIDTutor3' => $this->session->get('gibbonPersonID'), 'gibbonSchoolYearID' => $this->session->get('gibbonSchoolYearID'));
        $sqlFormGroups = 'SELECT * FROM gibbonFormGroup WHERE (gibbonPersonIDTutor=:gibbonPersonIDTutor OR gibbonPersonIDTutor2=:gibbonPersonIDTutor2 OR gibbonPersonIDTutor3=:gibbonPersonIDTutor3) AND gibbonSchoolYearID=:gibbonSchoolYearID';
        $resultFormGroups = $this->db->select($sqlFormGroups, $dataFormGroups);

        $attendanceAccess = isActionAccessible($guid, $connection2, '/modules/Attendance/attendance_take_byFormGroup.php');

        while ($rowFormGroups = $resultFormGroups->fetch()) {
            $formGroups[$count][0] = $rowFormGroups['gibbonFormGroupID'];
            $formGroups[$count][1] = $rowFormGroups['nameShort'];

            //Form group table
            $formGroupTable = clone $this->formGroupTable;

            $formGroupTable->build($rowFormGroups['gibbonFormGroupID'], true, false, 'rollOrder, surname, preferredName');
            $formGroupTable->setTitle('');

            if ($rowFormGroups['attendance'] == 'Y' AND $attendanceAccess) {
                $formGroupTable->addHeaderAction('attendance', __('Take Attendance'))
                    ->setURL('/modules/Attendance/attendance_take_byFormGroup.php')
                    ->addParam('gibbonFormGroupID', $rowFormGroups['gibbonFormGroupID'])
                    ->setIcon('attendance')
                    ->displayLabel();
            }

            if (Access::allows('Student Alerts', 'report_alertsByFormGroup')) {
                $formGroupTable->addHeaderAction('alerts', __('Student Alerts'))
                    ->setURL('/modules/Student Alerts/report_alertsByFormGroup.php')
                    ->addParam('gibbonFormGroupID', $rowFormGroups['gibbonFormGroupID'])
                    ->setIcon('warning')
                    ->displayLabel();
            }

            $formGroupTable->addHeaderAction('export', __('Export to Excel'))
                ->setURL('/indexExport.php')
                ->addParam('gibbonFormGroupID', $rowFormGroups['gibbonFormGroupID'])
                ->directLink()
                ->displayLabel();

            $formGroups[$count][2] = $formGroupTable->getOutput();

            // BEHAVIOUR
            $behaviourView = isActionAccessible($guid, $connection2, '/modules/Behaviour/behaviour_view.php');
            if ($behaviourView) {
                $table = $this->getContainer()->get(BehaviourTable::class)->create($this->session->get('gibbonSchoolYearID'), $formGroups[$count][0]);
                $formGroups[$count][3] = $table->getOutput();
            }

            ++$count;
            ++$formGroupCount;
        }

        // TABS
        $tabs = [];

        if (!empty($planner) || !empty($timetable)) {
            $tabs['Planner'] = [
                'label'   => __('Planner'),
                'content' => $planner.$timetable,
                'icon'    => 'book-open',
            ];
        }

        if (count($formGroups) > 0) {
            foreach ($formGroups as $index => $formGroup) {
                $tabs['Form Group Info'.$index] = [
                    'label'   => $formGroup[1],
                    'content' => $formGroup[2],
                    'icon'    => 'user-group',
                ];
                $tabs['Form Group Behaviour'.$index] = [
                    'label'   => $formGroup[1].' '.__('Behaviour'),
                    'content' => $formGroup[3],
                    'icon'    => 'chat-bubble-text',
                ];
            }
        }

        if (isActionAccessible($guid, $connection2, '/modules/Admissions/report_students_left.php') || isActionAccessible($guid, $connection2, '/modules/Admissions/report_students_new.php')) {
            $tabs['Enrolment'] = [
                'label'   => __('Enrolment'),
                'content' => $this->enrolmentTable->getOutput(),
                'icon'    => 'academic-cap',
            ];
        }

        // Dashboard Hooks
        $hooks = $this->getContainer()->get(HookGateway::class)->getAccessibleHooksByType('Staff Dashboard', $this->session->get('gibbonRoleIDCurrent'));
        foreach ($hooks as $hookData) {

            // Set the module for this hook for translations
            $this->session->set('module', $hookData['sourceModuleName']);
            $include = $this->session->get('absolutePath').'/modules/'.$hookData['sourceModuleName'].'/'.$hookData['sourceModuleInclude'];

            if (!file_exists($include)) {
                $hookOutput = Format::alert(__('The selected page cannot be displayed due to a hook error.'), 'error');
            } else {
                try {
                    $hookOutput = include $include;
                } catch (\Throwable $e) {
                    error_log($e->getMessage());
                    $hookOutput = Format::alert(__('The selected page cannot be displayed due to a hook error.'), 'error');
                }
            }

            $tabs[$hookData['name']] = [
                'label'   => __($hookData['name'], [], $hookData['sourceModuleName']),
                'content' => $hookOutput,
                'icon'    => $hookData['name'],
            ];
        }
        
        // Set the default tab
        $staffDashboardDefaultTab = $this->settingGateway->getSettingByScope('School Admin', 'staffDashboardDefaultTab');
        $defaultTab = !isset($_GET['tab']) && !empty($staffDashboardDefaultTab)
            ? array_search($staffDashboardDefaultTab, array_keys($tabs))+1
            : preg_replace('/[^0-9]/', '', $_GET['tab'] ?? 1);

        $return .= $this->view->fetchFromTemplate('ui/tabs.twig.html', [
            'selected' => $defaultTab ?? 1,
            'tabs'     => $tabs,
            'outset'   => true,
            'icons'    => true,
        ]);

        return $return;
    }
}
