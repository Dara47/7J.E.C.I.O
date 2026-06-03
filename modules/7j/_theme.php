<?php
/**
 * 7J English Center — Gibbon Style Theme
 * Include ที่ต้นของทุก module page
 * เปลี่ยนสีทั้งระบบให้ตรงกับ Gibbon orange theme
 */

if (!function_exists('fmtDate')) {
    function fmtDate(?string $date): string {
        if (!$date) return (string)$date;
        $ts = strtotime($date);
        if ($ts === false) return $date;
        return date('D', $ts) . ' ' . $date;
    }
}
?>
<style>
/* ═══════════════════════════════════════════════════════════════════
   7J English Center — Brand Colors
   Primary:   #E8640A  ส้มหลัก
   Secondary: #1A2A5E  น้ำเงินเข้ม
   Accent:    #F5A623  ทอง/ส้มอ่อน
   BG:        #FFF8E7  ครีม
   ═══════════════════════════════════════════════════════════════════ */
:root {
    --g-primary:        #E8640A;
    --g-primary-dk:     #1A2A5E;
    --g-primary-lt:     #FFF8E7;
    --g-primary-bg:     #FFF8E7;
    --g-primary-border: #F5A623;
    --g-primary-text:   #1A2A5E;
    --g-secondary:      #1A2A5E;
    --g-accent:         #F5A623;
    --g-success:        #16a34a;
    --g-success-bg:     #dcfce7;
    --g-danger:         #dc2626;
    --g-danger-bg:      #fee2e2;
    --g-warn:           #d97706;
    --g-warn-bg:        #fef3c7;
    --g-gray:           #6b7280;
    --g-gray-bg:        #f9fafb;
    --g-border:         #e5e7eb;
    --g-text:           #1f2937;
    --g-text-sm:        #374151;
    --g-text-xs:        #6b7280;
}

/* ── Page wrapper ── */
.g-page { max-width:100%; padding-bottom:2rem; font-family:inherit; }

/* ── Alert bars ── */
.g-alert { border-radius:8px; padding:10px 16px; margin-bottom:1rem; font-size:.88rem; }
.g-alert-success { background:var(--g-success-bg); color:#166534; border-left:4px solid var(--g-success); }
.g-alert-error   { background:var(--g-danger-bg);  color:#991b1b;  border-left:4px solid var(--g-danger); }
.g-alert-warn    { background:var(--g-warn-bg);    color:#92400e;  border-left:4px solid var(--g-warn); }
.g-alert-info    { background:var(--g-primary-bg); color:var(--g-primary-text); border-left:4px solid var(--g-primary); }

/* ── Page header ── */
.g-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:1.25rem; }
.g-title  { font-size:1.35rem; font-weight:700; color:var(--g-text); margin:0; display:flex; align-items:center; gap:8px; }

/* ── Buttons ── */
.g-btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:7px 16px; border-radius:6px; font-size:.85rem; font-weight:600;
    cursor:pointer; border:1px solid transparent; text-decoration:none;
    transition:opacity .15s, box-shadow .15s; line-height:1.4;
}
.g-btn:hover { opacity:.88; box-shadow:0 1px 4px rgba(0,0,0,.12); }
.g-btn-primary { background:var(--g-primary); color:#fff; border-color:var(--g-primary-dk); }
.g-btn-outline  { background:#fff; color:var(--g-text-sm); border-color:var(--g-border); }
.g-btn-outline:hover { background:var(--g-gray-bg); }
.g-btn-success  { background:var(--g-success); color:#fff; }
.g-btn-danger   { background:var(--g-danger);  color:#fff; }
.g-btn-sm { padding:4px 10px; font-size:.78rem; }
.g-btn-icon {
    display:inline-flex; align-items:center; justify-content:center;
    padding:5px; border-radius:5px; background:none; border:none;
    cursor:pointer; color:var(--g-gray); transition:background .15s;
}
.g-btn-icon:hover { background:var(--g-gray-bg); color:var(--g-text); }
.g-btn-icon-danger:hover { background:var(--g-danger-bg); color:var(--g-danger); }

/* ── Cards / Panels ── */
.g-card {
    background:#fff; border-radius:10px;
    box-shadow:0 1px 4px rgba(0,0,0,.07), 0 0 0 1px rgba(0,0,0,.04);
    margin-bottom:1rem; overflow:hidden;
}
.g-card-body  { padding:1.1rem 1.25rem; }
.g-card-header {
    padding:.75rem 1.25rem;
    background:linear-gradient(135deg, var(--g-primary-dk), var(--g-primary));
    color:#fff; font-weight:700; font-size:.95rem;
    display:flex; align-items:center; justify-content:space-between; gap:8px;
}
.g-card-header-light {
    padding:.65rem 1.25rem;
    background:var(--g-primary-bg);
    color:var(--g-primary-text);
    border-bottom:1px solid var(--g-primary-border);
    font-weight:700; font-size:.88rem;
    display:flex; align-items:center; justify-content:space-between; gap:8px;
}
.g-section-label {
    font-size:.7rem; font-weight:700; color:var(--g-primary);
    text-transform:uppercase; letter-spacing:.06em;
    margin:10px 0 6px; padding-bottom:4px;
    border-bottom:1px solid var(--g-primary-bg);
}

/* ── Stats bar ── */
.g-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:10px; margin-bottom:1.25rem; }
.g-stat {
    background:#fff; border-radius:10px; padding:.75rem 1rem; text-align:center;
    box-shadow:0 1px 3px rgba(0,0,0,.08); border-top:3px solid var(--g-primary);
    cursor:pointer; text-decoration:none; display:block; transition:box-shadow .15s;
}
.g-stat:hover { box-shadow:0 3px 10px rgba(0,0,0,.12); }
.g-stat-num   { font-size:1.6rem; font-weight:800; color:var(--g-primary); line-height:1; }
.g-stat-label { font-size:.72rem; color:var(--g-text-xs); margin-top:3px; }

/* ── Tables — Gibbon dataTable style ── */
.g-table { width:100%; border-collapse:collapse; font-size:.85rem; }
.g-table th {
    padding:9px 14px; text-align:left; font-weight:700; font-size:.78rem;
    text-transform:uppercase; letter-spacing:.04em; color:var(--g-primary-text);
    background:var(--g-primary-bg); border-bottom:2px solid var(--g-primary-border);
    white-space:nowrap;
}
.g-table td { padding:8px 14px; border-bottom:1px solid var(--g-border); vertical-align:middle; }
.g-table tbody tr:hover td { background:var(--g-primary-bg); }
.g-table tbody tr:nth-child(even) td { background:#fafafa; }
.g-table tbody tr:nth-child(even):hover td { background:var(--g-primary-bg); }

/* ── List cards (avatar-style rows) ── */
.g-row {
    background:#fff; border-radius:8px;
    box-shadow:0 1px 3px rgba(0,0,0,.07);
    margin-bottom:6px; padding:.8rem 1rem;
    display:flex; align-items:flex-start; gap:12px;
    border-left:3px solid transparent; transition:border-color .15s;
}
.g-row:hover { border-left-color:var(--g-primary); }
.g-avatar {
    width:40px; height:40px; border-radius:50%; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-weight:700; font-size:.9rem; color:#fff;
}
.g-avatar-sm { width:30px; height:30px; font-size:.75rem; }

/* ── Badges ── */
.g-badge {
    display:inline-block; border-radius:99px; padding:2px 9px;
    font-size:.72rem; font-weight:700;
}
.g-badge-primary { background:var(--g-primary-bg); color:var(--g-primary-text); border:1px solid var(--g-primary-border); }
.g-badge-success { background:var(--g-success-bg); color:#166534; }
.g-badge-danger  { background:var(--g-danger-bg);  color:#991b1b; }
.g-badge-warn    { background:var(--g-warn-bg);    color:#92400e; }
.g-badge-gray    { background:#f3f4f6; color:#374151; }
.g-badge-session { background:#dbeafe; color:#1e40af; }

/* ── Progress bar ── */
.g-pbar { height:6px; background:#e5e7eb; border-radius:99px; overflow:hidden; }
.g-pbar-fill { height:100%; border-radius:99px; background:var(--g-primary); transition:width .6s ease; }
.g-pbar-success { background:var(--g-success); }
.g-pbar-warn    { background:var(--g-warn); }
.g-pbar-danger  { background:var(--g-danger); }

/* ── Modals ── */
.g-modal-bg {
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,.45); z-index:9000;
    align-items:center; justify-content:center;
}
.g-modal-bg.open { display:flex; }
.g-modal {
    background:#fff; border-radius:14px; width:100%; max-width:480px;
    box-shadow:0 24px 64px rgba(0,0,0,.2); overflow:hidden;
    max-height:90vh; overflow-y:auto;
}
.g-modal-header {
    padding:16px 20px;
    background:linear-gradient(135deg, var(--g-primary-dk), var(--g-primary));
    color:#fff;
}
.g-modal-header h3 { margin:0; font-size:1.05rem; font-weight:700; }
.g-modal-body  { padding:1.25rem 1.5rem; }
.g-modal-footer { padding:.75rem 1.5rem; border-top:1px solid var(--g-border); display:flex; justify-content:flex-end; gap:8px; }

/* ── Forms — Gibbon input style ── */
.g-form-group { margin-bottom:12px; }
.g-form-group label { display:block; font-size:.8rem; font-weight:600; color:var(--g-text-sm); margin-bottom:4px; }
.g-input, .g-select, .g-textarea {
    width:100%; padding:8px 10px; font-size:.88rem; box-sizing:border-box; outline:none;
    border:1px solid #d1d5db; border-radius:6px; background:#fff;
    transition:border-color .15s, box-shadow .15s;
}
.g-input:focus, .g-select:focus, .g-textarea:focus {
    border-color:var(--g-primary); box-shadow:0 0 0 2px var(--g-primary-bg);
}
.g-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.g-grid3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }

/* ── Search bar ── */
.g-search {
    display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;
    background:var(--g-primary-bg); border:1px solid var(--g-primary-border);
    border-radius:10px; padding:.9rem 1rem; margin-bottom:1.25rem;
}
.g-search-input {
    flex:1; min-width:150px; padding:8px 12px; font-size:.88rem;
    border:1px solid #d1d5db; border-radius:6px; outline:none;
}
.g-search-input:focus { border-color:var(--g-primary); box-shadow:0 0 0 2px var(--g-primary-bg); }

/* ── Filter tabs (clickable status filters) ── */
.g-filter-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:1rem; }
.g-filter-tab {
    padding:5px 14px; border-radius:99px; font-size:.8rem; font-weight:600;
    cursor:pointer; border:1px solid var(--g-border); text-decoration:none;
    display:inline-block; background:#fff; color:var(--g-text-sm); transition:all .15s;
}
.g-filter-tab:hover, .g-filter-tab.active {
    background:var(--g-primary); color:#fff; border-color:var(--g-primary);
}

/* ── Pagination ── */
.g-page-nav { display:flex; gap:6px; justify-content:center; margin-top:1rem; flex-wrap:wrap; }
.g-page-btn {
    padding:5px 12px; border-radius:6px; border:1px solid var(--g-border);
    background:#fff; font-size:.82rem; cursor:pointer; color:var(--g-text-sm);
    text-decoration:none; transition:background .15s;
}
.g-page-btn:hover    { background:var(--g-gray-bg); }
.g-page-btn.active   { background:var(--g-primary); color:#fff; border-color:var(--g-primary); }

/* ── Delete / review box ── */
.g-review-box {
    margin-top:8px; padding:7px 12px;
    background:var(--g-primary-bg); border-left:3px solid var(--g-primary);
    border-radius:0 6px 6px 0; font-size:.82rem; color:var(--g-text-sm);
}

/* ── Overlay: backdrop click closes ── */
.g-modal-bg { cursor:default; }
</style>

<script>
// Global modal helpers — ใช้ได้ทุกหน้า
function gOpenModal(id)  { var el=document.getElementById('modal-'+id); if(el) el.classList.add('open'); }
function gCloseModal(id) { var el=document.getElementById('modal-'+id); if(el) el.classList.remove('open'); }
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.g-modal-bg').forEach(function(bg){
        bg.addEventListener('click', function(e){ if(e.target===bg) bg.classList.remove('open'); });
    });
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') document.querySelectorAll('.g-modal-bg.open').forEach(function(m){ m.classList.remove('open'); }); });
});
</script>
