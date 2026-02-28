<?php
/**
 * dashboard/index.php  — Online (Render / PHP Docker service)
 * Session-guarded. Patient data loaded from PostgreSQL (lowercase cols).
 * Design mirrors the local RPi dashboard exactly.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit();
}

require_once dirname(__DIR__) . '/db_connect.php';

// ── Pull patient data from PostgreSQL (all lowercase cols) ──────────
$uid          = $_SESSION['user_id'];
$userRole     = strtolower($_SESSION['role'] ?? 'patient');
$patientData  = [];
$threshold    = [];
$health       = [];

if ($userRole === 'caregiver') {
    // Caregiver → resolve their assigned patient via caregiver_table
    // patient_id may already be in session from QR token
    $cgPid = $_SESSION['patient_id'] ?? null;
    if (!$cgPid) {
        $cg = $pdo->prepare("SELECT patientid FROM caregiver_table WHERE userid = $1 LIMIT 1");
        $cg->execute([$uid]);
        $cgRow = $cg->fetch();
        $cgPid = $cgRow['patientid'] ?? null;
    }
    if ($cgPid) {
        $ps = $pdo->prepare("SELECT * FROM patient_table WHERE patientid = $1 LIMIT 1");
        $ps->execute([$cgPid]);
        $patientData = $ps->fetch() ?: [];
    }
} else {
    // Patient → direct lookup by userid
    $ps = $pdo->prepare("SELECT * FROM patient_table WHERE userid = $1 LIMIT 1");
    $ps->execute([$uid]);
    $patientData = $ps->fetch() ?: [];
}

if (!empty($patientData)) {
    $_SESSION['patient_id'] = $patientData['patientid'];

    $ts = $pdo->prepare("SELECT * FROM hr_threshold_table WHERE patientid = $1 LIMIT 1");
    $ts->execute([$patientData['patientid']]);
    $threshold = $ts->fetch() ?: [];

    $hs = $pdo->prepare("SELECT * FROM healthhistory_table WHERE patientid = $1 ORDER BY recorddate DESC LIMIT 1");
    $hs->execute([$patientData['patientid']]);
    $health = $hs->fetch() ?: [];
}

$firstName  = $patientData['firstname']  ?? '';
$lastName   = $patientData['lastname']   ?? '';
$fullName   = trim("$firstName $lastName") ?: ($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'User');
$age        = $patientData['age']        ?? '--';
$gender     = $patientData['gender']     ?? '--';
$ecName     = $patientData['emergencycontactname']   ?? '--';
$ecNumber   = $patientData['emergencycontactnumber'] ?? '--';
$conditions = $health['conditiontext']   ?? '';

$restMin    = (int)($threshold['restingmin']    ?? 50);
$restMax    = (int)($threshold['restingmax']    ?? 100);
$actMin     = (int)($threshold['activemin']     ?? 100);
$actMax     = (int)($threshold['activemax']     ?? 170);
$critical   = (int)($threshold['criticallevel'] ?? 150);

// $userRole already set above during patient data loading
$userRole   = $userRole ?? 'patient';
$username   = $_SESSION['username'] ?? 'user';

$hour = (int)date('H');
$greeting = $hour < 12 ? 'Morning' : ($hour < 17 ? 'Afternoon' : 'Evening');
$firstName1 = explode(' ', $fullName)[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>VitalLink – Online Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* ══ IDENTICAL to local dashboard design ══ */
:root {
    --blue-dark:  #0d3580; --blue-mid:   #1348a8;
    --blue-main:  #1a6fd4; --blue-light: #eaf2ff; --blue-pale: #f0f6ff;
    --accent:     #38bdf8; --danger: #ef4444; --warning: #f59e0b;
    --success:    #22c55e; --text-dark: #0f172a; --text-mid: #475569;
    --text-light: #94a3b8; --border: #e0e9f5;
    --sidebar-w:  260px;   --header-h:  68px;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--blue-pale);color:var(--text-dark);min-height:100vh;display:flex}

/* ── SIDEBAR ── */
.sidebar{width:var(--sidebar-w);min-height:100vh;background:linear-gradient(160deg,#1a6fd4 0%,#1348a8 50%,#0d3580 100%);display:flex;flex-direction:column;position:fixed;top:0;left:0;z-index:100;overflow-y:auto}
.sidebar-bg{position:absolute;inset:0;pointer-events:none}
.sidebar-bg::before{content:'';position:absolute;top:-60px;right:-60px;width:220px;height:220px;background:rgba(255,255,255,.06);border-radius:50%}
.sidebar-bg::after{content:'';position:absolute;bottom:-80px;left:-40px;width:200px;height:200px;background:rgba(255,255,255,.04);border-radius:50%}
.sidebar-top{padding:24px 20px 16px;position:relative;z-index:1}
.sidebar-logo{display:flex;align-items:center;gap:10px;margin-bottom:4px}
.logo-icon{width:34px;height:34px;background:rgba(255,255,255,.18);border-radius:10px;display:flex;align-items:center;justify-content:center}
.logo-icon svg{width:18px;height:18px;fill:#fff}
.logo-text{font-size:.95rem;font-weight:700;color:#fff;letter-spacing:.05em;text-transform:uppercase}
.online-badge{display:inline-block;background:rgba(56,189,248,.25);color:#7dd3fc;font-size:.65rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:3px 10px;border-radius:20px;margin-top:6px}
.sidebar-user{margin:0 16px 14px;background:rgba(255,255,255,.1);border-radius:14px;padding:16px;text-align:center;position:relative;z-index:1}
.user-avatar{width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,rgba(255,255,255,.3),rgba(255,255,255,.1));color:#fff;font-size:1.3rem;font-weight:700;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;border:2px solid rgba(255,255,255,.25)}
.user-name{color:#fff;font-size:.95rem;font-weight:600}
.user-meta{color:rgba(255,255,255,.65);font-size:.75rem;margin-top:2px}
.user-role-badge{display:inline-block;margin-top:7px;background:rgba(255,255,255,.15);color:rgba(255,255,255,.9);font-size:.68rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:3px 10px;border-radius:20px}
.sidebar-section{margin:0 16px 12px;padding:14px 16px;background:rgba(255,255,255,.08);border-radius:12px;position:relative;z-index:1}
.sidebar-section h5{color:rgba(255,255,255,.5);font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px}
.info-row{display:flex;justify-content:space-between;font-size:.78rem;color:rgba(255,255,255,.8);margin-bottom:5px}
.info-row span:first-child{color:rgba(255,255,255,.45)}
.medical-tag{display:inline-block;background:rgba(255,255,255,.12);color:rgba(255,255,255,.8);font-size:.72rem;padding:3px 9px;border-radius:20px;margin:2px 2px 2px 0}
.sidebar-nav{padding:0 16px;flex:1;position:relative;z-index:1}
.nav-label{font-size:.62rem;font-weight:700;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.12em;padding:8px 10px 4px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:10px;margin-bottom:2px;color:rgba(255,255,255,.72);font-size:.88rem;font-weight:500;text-decoration:none;transition:background .2s,color .2s;cursor:pointer;border:none;width:100%;background:none;font-family:inherit}
.nav-item:hover{background:rgba(255,255,255,.1);color:#fff}
.nav-item.active{background:rgba(255,255,255,.18);color:#fff;font-weight:600}
.nav-item svg{width:17px;height:17px;fill:currentColor;flex-shrink:0}
.sidebar-qr{margin:0 16px 12px;padding:14px 16px;background:rgba(255,255,255,.08);border-radius:12px;text-align:center;position:relative;z-index:1}
.sidebar-qr h5{color:rgba(255,255,255,.5);font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px}
.sidebar-logout{padding:16px;border-top:1px solid rgba(255,255,255,.1);margin-top:auto;position:relative;z-index:1}
.logout-btn{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,.6);font-size:.83rem;text-decoration:none;padding:8px 12px;border-radius:8px;transition:color .2s,background .2s}
.logout-btn:hover{color:#fff;background:rgba(255,255,255,.08)}
.logout-btn svg{width:16px;height:16px;fill:currentColor}

/* ── MAIN ── */
.main-wrap{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh}
.topbar{height:var(--header-h);background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:50;box-shadow:0 1px 8px rgba(30,90,180,.06)}
.topbar-title{font-size:1rem;font-weight:600;color:var(--text-dark)}
.topbar-title span{color:var(--blue-main)}
.topbar-actions{display:flex;align-items:center;gap:10px}
.ws-indicator{display:flex;align-items:center;gap:6px}
.ws-dot{width:8px;height:8px;border-radius:50%;background:#94a3b8;transition:background .3s}
.ws-dot.live{background:var(--success);animation:pulse 2s infinite}
.ws-dot.err{background:var(--danger)}
.ws-label{font-size:.75rem;color:var(--text-light)}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
.search-btn{display:flex;align-items:center;gap:6px;background:var(--blue-main);color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:.82rem;font-weight:600;cursor:pointer;font-family:inherit;transition:opacity .2s}
.search-btn:hover{opacity:.88}
.search-btn svg{width:15px;height:15px;fill:currentColor}
.page-content{padding:24px 28px;flex:1}

/* ── STAT CARDS ── */
.stat-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:22px}
.stat-card{background:#fff;border-radius:16px;padding:20px 22px;box-shadow:0 2px 12px rgba(30,90,180,.07);border:1px solid var(--border);transition:box-shadow .2s}
.stat-card:hover{box-shadow:0 4px 20px rgba(30,90,180,.12)}
.stat-icon{width:40px;height:40px;border-radius:10px;margin-bottom:12px;display:flex;align-items:center;justify-content:center}
.stat-icon svg{width:20px;height:20px;fill:#fff}
.stat-icon.blue{background:linear-gradient(135deg,#1a6fd4,#0d3580)}
.stat-icon.green{background:linear-gradient(135deg,#22c55e,#15803d)}
.stat-icon.red{background:linear-gradient(135deg,#ef4444,#991b1b)}
.stat-icon.warning{background:linear-gradient(135deg,#f59e0b,#b45309)}
.stat-label{font-size:.75rem;color:var(--text-light);font-weight:600;text-transform:uppercase;letter-spacing:.06em}
.stat-value{font-size:2rem;font-weight:700;color:var(--text-dark);margin:4px 0 6px;line-height:1}
.stat-value.bpm{color:var(--blue-main)}
.stat-value.ok{color:var(--success)}
.stat-value.alert{color:var(--danger)}
.stat-sub{font-size:.75rem;color:var(--text-light);display:flex;align-items:center;gap:5px}
.stat-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.stat-dot.blue{background:var(--blue-main);animation:pulse 2s infinite}
.stat-dot.green{background:var(--success)}
.stat-dot.red{background:var(--danger);animation:pulse .8s infinite}
.stat-dot.warn{background:var(--warning)}
#fallStatusCard.fall-active{border-left:4px solid var(--danger);background:linear-gradient(135deg,#fff0f0,#fff)}

/* ── CHART ── */
.chart-card{background:#fff;border-radius:16px;padding:22px 24px;box-shadow:0 2px 12px rgba(30,90,180,.07);border:1px solid var(--border);margin-bottom:22px}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.card-header h3{font-size:.95rem;font-weight:700;color:var(--text-dark)}
.chart-wrap{height:220px}

/* ── ACTIVITY LOG ── */
.activity-card{background:#fff;border-radius:16px;padding:22px 24px;box-shadow:0 2px 12px rgba(30,90,180,.07);border:1px solid var(--border)}
.activity-card h3{font-size:.95rem;font-weight:700;margin-bottom:14px}
#activityLog{list-style:none}
#activityLog li{display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid #f0f6ff;font-size:.82rem;color:var(--text-mid)}
#activityLog li:last-child{border-bottom:none}
.log-dot{width:7px;height:7px;border-radius:50%;margin-top:4px;flex-shrink:0}
.log-time{color:var(--text-light);flex-shrink:0;font-size:.75rem;margin-top:1px;white-space:nowrap}

/* ── FALL MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(2,6,23,.8);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:1000}
.modal-overlay.hidden{display:none}
.fall-modal{background:#fff;border-radius:20px;padding:44px 48px;text-align:center;max-width:420px;width:90%;box-shadow:0 30px 80px rgba(0,0,0,.35);border-top:6px solid var(--danger)}
.fall-icon-big{font-size:3.5rem;display:block;margin-bottom:16px;animation:pulse .6s infinite}
.fall-modal h2{font-size:1.5rem;font-weight:800;color:var(--danger);margin-bottom:10px}
.fall-modal p{color:var(--text-mid);font-size:.9rem;margin-bottom:24px;line-height:1.6}
.fall-ack{background:var(--danger);color:#fff;border:none;border-radius:10px;padding:12px 36px;font-size:.95rem;font-weight:700;cursor:pointer;font-family:inherit}

/* ── SEARCH MODAL ── */
.search-overlay{position:fixed;inset:0;background:rgba(2,6,23,.75);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:1000}
.search-overlay.hidden{display:none}
.search-modal{width:90%;max-width:880px;max-height:85vh;background:#fff;border-radius:18px;padding:28px;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,.2)}
.smh{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.smh h3{font-size:1.1rem;font-weight:700}
.close-btn{background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--text-light);line-height:1}
.filter-row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end}
.filter-row label{font-size:.73rem;color:var(--text-light);display:block;margin-bottom:4px;font-weight:600}
.filter-row input[type=datetime-local],.filter-row select{border:1.5px solid var(--border);border-radius:8px;padding:8px 11px;font-size:.84rem;outline:none;font-family:inherit;background:#fafcff}
.checkbox-row{display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap}
.checkbox-row label{display:flex;align-items:center;gap:6px;font-size:.84rem;cursor:pointer}
.apply-btn{background:var(--blue-main);color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:.84rem;font-weight:600;cursor:pointer;font-family:inherit}
#resultsTable{width:100%;border-collapse:collapse;font-size:.83rem;margin-top:8px}
#resultsTable th{text-align:left;padding:10px;background:#f8faff;color:var(--text-light);font-size:.71rem;font-weight:700;text-transform:uppercase;border-bottom:2px solid var(--border)}
#resultsTable td{padding:10px;border-bottom:1px solid #f0f6ff}
#resultsTable tr:hover td{background:#f8faff}
.spinner{width:40px;height:40px;border:4px solid rgba(26,111,212,.15);border-top:4px solid var(--blue-main);border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 10px}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── SETTINGS PAGE ── */
.settings-card{background:#fff;border-radius:16px;padding:24px 26px;box-shadow:0 2px 12px rgba(30,90,180,.07);border:1px solid var(--border);margin-bottom:18px}
.settings-card h3{font-size:.95rem;font-weight:700;margin-bottom:18px;color:var(--text-dark)}
.stabs{display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:22px}
.stab{padding:10px 20px;font-size:.84rem;font-weight:600;color:var(--text-light);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:color .2s}
.stab.active{color:var(--blue-main);border-bottom-color:var(--blue-main);background:linear-gradient(to bottom,#f0f7ff,#fff)}
.spanel{display:none}.spanel.active{display:block}
.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 20px}
.settings-grid .full{grid-column:1/-1}
.sfield-label{display:block;font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.sfield-input{width:100%;border:1.5px solid var(--border);border-radius:9px;padding:10px 13px;font-size:.9rem;color:var(--text-dark);background:#fafcff;outline:none;font-family:inherit;transition:border-color .2s}
.sfield-input:focus{border-color:var(--blue-main);background:#fff}
.sfield-input:disabled{background:#f8fafc;color:#94a3b8;cursor:not-allowed}
.thresh-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px 16px}
.settings-banner{padding:10px 14px;border-radius:8px;font-size:.83rem;margin-bottom:16px;display:none}
.settings-banner.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d}
.settings-banner.err{background:#fff1f2;border:1px solid #fecdd3;color:#be123c}
.viewonly-notice{background:#fff8e1;border:1px solid #ffe082;border-radius:9px;padding:10px 14px;font-size:.83rem;color:#b45309;margin-bottom:16px}
@media(max-width:600px){.settings-grid{grid-template-columns:1fr}.thresh-grid{grid-template-columns:1fr 1fr}}

/* ══ HAMBURGER BUTTON (hidden on desktop) ══ */
.hamburger{display:none;flex-direction:column;gap:5px;background:none;border:none;cursor:pointer;padding:8px;border-radius:8px;transition:background .2s}
.hamburger:hover{background:#f0f6ff}
.hamburger span{display:block;width:22px;height:2px;background:var(--text-dark);border-radius:2px;transition:transform .3s,opacity .3s}
.hamburger.open span:nth-child(1){transform:translateY(7px) rotate(45deg)}
.hamburger.open span:nth-child(2){opacity:0}
.hamburger.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg)}

/* ══ SIDEBAR OVERLAY (mobile backdrop) ══ */
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(2,6,23,.55);backdrop-filter:blur(2px);z-index:99;opacity:0;transition:opacity .3s}
.sidebar-overlay.visible{opacity:1}

/* ══ SIDEBAR SLIDE TRANSITION ══ */
.sidebar{transition:transform .3s cubic-bezier(.4,0,.2,1)}

/* ══ TABLET (≤ 1024px) — sidebar collapses, hamburger appears ══ */
@media(max-width:1024px){
    .hamburger{display:flex}
    .sidebar-overlay{display:block}
    .sidebar{transform:translateX(-100%)}
    .sidebar.open{transform:translateX(0)}
    .main-wrap{margin-left:0}
    .stat-cards{grid-template-columns:1fr 1fr}
    .topbar{padding:0 18px}
    .page-content{padding:18px}
}

/* ══ PHONE (≤ 600px) — single column cards ══ */
@media(max-width:600px){
    .stat-cards{grid-template-columns:1fr}
    .sidebar{width:min(280px, 85vw)}
    .page-content{padding:14px}
    .topbar-title{font-size:.9rem}
    .chart-wrap{height:180px}
    .search-modal{padding:18px;max-height:92vh}
    .filter-row{flex-direction:column;gap:8px}
    .filter-row input[type=datetime-local],.filter-row select{width:100%}
}
</style>
</head>
<body>

<!-- ══ SIDEBAR OVERLAY ══ -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar" id="sidebar">
<div class="sidebar-bg"></div>

<div class="sidebar-top">
    <div class="sidebar-logo">
        <div class="logo-icon">
            <svg viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
        </div>
        <span class="logo-text">VitalLink</span>
    </div>
    <span class="online-badge">🌐 Online View</span>
</div>

<div class="sidebar-user">
    <div class="user-avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></div>
    <div class="user-name"><?= htmlspecialchars($fullName) ?></div>
    <div class="user-meta"><?= htmlspecialchars($gender) ?><?= $age !== '--' ? ' · Age '.$age : '' ?></div>
    <span class="user-role-badge"><?= htmlspecialchars($userRole) ?></span>
</div>

<?php if ($ecName !== '--'): ?>
<div class="sidebar-section">
    <h5>Emergency Contact</h5>
    <div class="info-row"><span>Name</span><span><?= htmlspecialchars($ecName) ?></span></div>
    <div class="info-row"><span>Phone</span><span><?= htmlspecialchars($ecNumber) ?></span></div>
</div>
<?php endif; ?>

<?php if ($conditions): ?>
<div class="sidebar-section">
    <h5>Medical Conditions</h5>
    <?php foreach(array_filter(array_map('trim', explode(',', $conditions))) as $c): ?>
        <span class="medical-tag"><?= htmlspecialchars($c) ?></span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="sidebar-section">
    <h5>HR Thresholds</h5>
    <div class="info-row"><span>Resting</span><span><?= $restMin ?>–<?= $restMax ?> BPM</span></div>
    <div class="info-row"><span>Active</span><span><?= $actMin ?>–<?= $actMax ?> BPM</span></div>
    <div class="info-row"><span>Critical</span><span>≥ <?= $critical ?> BPM</span></div>
</div>

<nav class="sidebar-nav">
    <div class="nav-label">Overview</div>
    <button class="nav-item active" id="navDashboard" onclick="showPage('dashboard')">
        <svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
        Dashboard
    </button>
    <button class="nav-item" id="navSettings" onclick="showPage('settings')">
        <svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.488.488 0 00-.59-.22l-2.39.96a7.06 7.06 0 00-1.62-.94l-.36-2.54a.484.484 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54a7.41 7.41 0 00-1.62.94l-2.39-.96a.476.476 0 00-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.63-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54a7.41 7.41 0 001.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
        Settings
    </button>
</nav>

<div class="sidebar-logout">
    <a href="/logout.php" class="logout-btn">
        <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
        Logout
    </a>
</div>
</aside>

<!-- ══ MAIN ══ -->
<div class="main-wrap">
<header class="topbar">
    <div style="display:flex;align-items:center;gap:12px">
        <button class="hamburger" id="hamburgerBtn" aria-label="Toggle menu">
            <span></span><span></span><span></span>
        </button>
        <div class="topbar-title">
            Good <?= $greeting ?>, <span><?= htmlspecialchars($firstName1) ?></span> 👋
        </div>
    </div>
    <div class="topbar-actions">
        <div class="ws-indicator">
            <div class="ws-dot" id="wsDot"></div>
            <span class="ws-label" id="wsLabel">Connecting…</span>
        </div>
        <button class="search-btn" id="openSearch">
            <svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0016 9.5 6.5 6.5 0 109.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
            Event Search
        </button>
    </div>
</header>

<main class="page-content" id="dashboardPage">

    <div class="stat-cards">
        <!-- Heart Rate -->
        <div class="stat-card">
            <div class="stat-icon blue">
                <svg viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
            </div>
            <div class="stat-label">Heart Rate</div>
            <div class="stat-value bpm" id="hrValue">-- BPM</div>
            <div class="stat-sub" id="hrSub"><span class="stat-dot blue"></span> Live reading</div>
        </div>

        <!-- Fall Status -->
        <div class="stat-card" id="fallStatusCard">
            <div class="stat-icon green" id="fallIconWrap">
                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
            </div>
            <div class="stat-label">Fall Status</div>
            <div class="stat-value ok" id="fallValue">Normal</div>
            <div class="stat-sub" id="fallSub"><span class="stat-dot green"></span> No fall detected</div>
        </div>

        <!-- Thresholds summary -->
        <div class="stat-card">
            <div class="stat-icon warning">
                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
            </div>
            <div class="stat-label">HR Range</div>
            <div class="stat-value" style="font-size:1.1rem;margin-top:8px;color:var(--text-dark)"><?= $restMin ?>–<?= $restMax ?> <span style="font-size:.7rem;color:var(--text-light);font-weight:400">resting BPM</span></div>
            <div class="stat-sub"><span class="stat-dot warn"></span> Critical ≥ <?= $critical ?> BPM</div>
        </div>
    </div>

    <!-- HR Chart -->
    <div class="chart-card">
        <div class="card-header">
            <h3>Heart Rate History</h3>
            <span style="font-size:.75rem;color:var(--text-light)" id="lastUpdated">Waiting for data…</span>
        </div>
        <div class="chart-wrap">
            <canvas id="hrChart"></canvas>
        </div>
    </div>

    <!-- Activity Log -->
    <div class="activity-card">
        <h3>Activity Log</h3>
        <ul id="activityLog">
            <li>
                <span class="log-dot" style="background:var(--text-light)"></span>
                <span class="log-time"><?= date('H:i:s') ?></span>
                <span>Dashboard loaded — waiting for sensor data…</span>
            </li>
        </ul>
    </div>
</main>

<!-- ══ SETTINGS PAGE ══ -->
<main class="page-content" id="settingsPage" style="display:none;">
<div style="max-width:820px;margin:0 auto;">
    <h2 style="font-size:1.2rem;font-weight:700;color:var(--text-dark);margin-bottom:4px;">Device Settings</h2>
    <p style="font-size:.85rem;color:var(--text-light);margin-bottom:22px;">Changes update both local and online databases.</p>

    <?php if (strtolower($userRole) === 'patient'): ?>
    <div class="viewonly-notice">⚠️ You have view-only access. Contact your caregiver to make changes.</div>
    <?php endif; ?>

    <div id="settingsBanner" class="settings-banner"></div>

    <div class="settings-card">
        <div class="stabs">
            <div class="stab active" id="stab-personal" onclick="showStab('personal')">Personal Info</div>
            <div class="stab" id="stab-thresholds" onclick="showStab('thresholds')">HR Thresholds</div>
        </div>

        <!-- Personal Info Panel -->
        <div class="spanel active" id="spanel-personal">
            <form id="personalForm">
            <div class="settings-grid">
                <div>
                    <label class="sfield-label">First Name</label>
                    <input class="sfield-input" type="text" name="first_name" value="<?= htmlspecialchars($firstName) ?>" <?= strtolower($userRole)==='patient'?'disabled':'' ?>>
                </div>
                <div>
                    <label class="sfield-label">Last Name</label>
                    <input class="sfield-input" type="text" name="last_name" value="<?= htmlspecialchars($lastName) ?>" <?= strtolower($userRole)==='patient'?'disabled':'' ?>>
                </div>
                <div>
                    <label class="sfield-label">Middle Name</label>
                    <input class="sfield-input" type="text" name="middle_name" value="<?= htmlspecialchars($patientData['middlename']??'') ?>" <?= strtolower($userRole)==='patient'?'disabled':'' ?>>
                </div>
                <div>
                    <label class="sfield-label">Birth Date</label>
                    <input class="sfield-input" type="date" name="birth_date" value="<?= htmlspecialchars($patientData['birthdate']??'') ?>" <?= strtolower($userRole)==='patient'?'disabled':'' ?>>
                </div>
                <div>
                    <label class="sfield-label">Gender</label>
                    <select class="sfield-input" name="gender" <?= strtolower($userRole)==='patient'?'disabled':'' ?>>
                        <option value="">Select</option>
                        <option value="Male" <?= ($patientData['gender']??'')==='Male'?'selected':'' ?>>Male</option>
                        <option value="Female" <?= ($patientData['gender']??'')==='Female'?'selected':'' ?>>Female</option>
                        <option value="Other" <?= ($patientData['gender']??'')==='Other'?'selected':'' ?>>Other</option>
                    </select>
                </div>
                <div>
                    <label class="sfield-label">Phone Number</label>
                    <input class="sfield-input" type="tel" name="phone" value="<?= htmlspecialchars($patientData['phonenumber']??'') ?>" <?= strtolower($userRole)==='patient'?'disabled':'' ?>>
                </div>
                <div class="full">
                    <label class="sfield-label">Address</label>
                    <input class="sfield-input" type="text" name="address" value="<?= htmlspecialchars($patientData['addressline']??'') ?>" <?= strtolower($userRole)==='patient'?'disabled':'' ?>>
                </div>
                <div>
                    <label class="sfield-label">City</label>
                    <input class="sfield-input" type="text" name="city" value="<?= htmlspecialchars($patientData['city']??'') ?>" <?= strtolower($userRole)==='patient'?'disabled':'' ?>>
                </div>
                <div>
                    <label class="sfield-label">Province</label>
                    <input class="sfield-input" type="text" name="province" value="<?= htmlspecialchars($patientData['province']??'') ?>" <?= strtolower($userRole)==='patient'?'disabled':'' ?>>
                </div>
                <div>
                    <label class="sfield-label">Emergency Contact</label>
                    <input class="sfield-input" type="text" name="emergency_name" value="<?= htmlspecialchars($patientData['emergencycontactname']??'') ?>" <?= strtolower($userRole)==='patient'?'disabled':'' ?>>
                </div>
                <div>
                    <label class="sfield-label">Emergency Phone</label>
                    <input class="sfield-input" type="tel" name="emergency_number" value="<?= htmlspecialchars($patientData['emergencycontactnumber']??'') ?>" <?= strtolower($userRole)==='patient'?'disabled':'' ?>>
                </div>
                <div>
                    <label class="sfield-label">Relationship</label>
                    <input class="sfield-input" type="text" name="emergency_relation" value="<?= htmlspecialchars($patientData['emergencyrelationship']??'') ?>" <?= strtolower($userRole)==='patient'?'disabled':'' ?>>
                </div>
            </div>
            <div style="margin-top:20px;display:flex;justify-content:flex-end;">
                <?php if (strtolower($userRole) !== 'patient'): ?>
                <button type="submit" style="background:linear-gradient(135deg,var(--blue-main),#0d3580);color:#fff;border:none;border-radius:9px;padding:11px 30px;font-size:.9rem;font-weight:700;cursor:pointer;font-family:inherit;">Save Personal Info</button>
                <?php else: ?>
                <button type="button" disabled style="background:#e2e8f0;color:#94a3b8;border:none;border-radius:9px;padding:11px 30px;font-size:.9rem;font-weight:700;cursor:not-allowed;font-family:inherit;">View Only</button>
                <?php endif; ?>
            </div>
            </form>
        </div>

        <!-- HR Thresholds Panel -->
        <div class="spanel" id="spanel-thresholds">
            <form id="thresholdsForm">
            <div class="thresh-grid">
                <?php
                function onfield($label, $name, $val, $disabled) {
                    $dis = $disabled ? 'disabled' : '';
                    echo "<div>
                        <label class='sfield-label'>$label <span style='font-weight:400;color:#aaa;'>(BPM)</span></label>
                        <input type='number' name='$name' value='$val' min='20' max='250' class='sfield-input' style='text-align:center;font-weight:700;' $dis>
                    </div>";
                }
                $isPatient = strtolower($userRole) === 'patient';
                onfield('Resting Min',  'resting_min', $restMin,   $isPatient);
                onfield('Resting Max',  'resting_max', $restMax,   $isPatient);
                onfield('Active Min',   'active_min',  $actMin,    $isPatient);
                onfield('Active Max',   'active_max',  $actMax,    $isPatient);
                onfield('Critical',     'critical',    $critical,  $isPatient);
                ?>
            </div>
            <div style="margin-top:20px;display:flex;justify-content:flex-end;">
                <?php if (!$isPatient): ?>
                <button type="submit" style="background:linear-gradient(135deg,var(--blue-main),#0d3580);color:#fff;border:none;border-radius:9px;padding:11px 30px;font-size:.9rem;font-weight:700;cursor:pointer;font-family:inherit;">Save HR Thresholds</button>
                <?php else: ?>
                <button type="button" disabled style="background:#e2e8f0;color:#94a3b8;border:none;border-radius:9px;padding:11px 30px;font-size:.9rem;font-weight:700;cursor:not-allowed;font-family:inherit;">View Only</button>
                <?php endif; ?>
            </div>
            </form>
        </div>

    </div>
</div>
</main>

</div>

<!-- ══ FALL MODAL ══ -->
<div id="fallModal" class="modal-overlay hidden">
    <div class="fall-modal">
        <span class="fall-icon-big">⚠️</span>
        <h2>FALL DETECTED</h2>
        <p>A fall event has been recorded for <strong><?= htmlspecialchars($fullName) ?></strong>.<br>Please check on the patient immediately.</p>
        <button class="fall-ack" onclick="acknowledgeFall()">Acknowledge</button>
    </div>
</div>

<!-- ══ SEARCH MODAL ══ -->
<div id="searchModal" class="search-overlay hidden">
    <div class="search-modal">
        <div class="smh">
            <h3>Event Search</h3>
            <button class="close-btn" id="closeSearch">✕</button>
        </div>
        <div class="checkbox-row">
            <label><input type="checkbox" class="evType" value="HeartRate" checked> Heart Rate</label>
            <label><input type="checkbox" class="evType" value="Fall" checked> Fall</label>
            <label><input type="checkbox" class="evType" value="Height"> Height</label>
        </div>
        <div class="filter-row">
            <div><label>From</label><input type="datetime-local" id="startTime"></div>
            <div><label>To</label><input type="datetime-local" id="endTime"></div>
            <div>
                <label>Max results</label>
                <select id="limitSel"><option value="50">50</option><option value="100" selected>100</option><option value="500">500</option></select>
            </div>
            <button class="apply-btn" id="applyFilter">Search</button>
            <?php if ($userRole !== 'patient'): ?>
            <button class="apply-btn" id="printResults" onclick="printEventResults()" style="background:#0f766e;">Print</button>
            <?php endif; ?>
        </div>
        <div id="resultCount" style="font-size:.8rem;color:var(--text-light);margin-bottom:8px;text-align:right;min-height:1.2em"></div>
        <div id="searchLoading" style="display:none;text-align:center;padding:28px">
            <div class="spinner"></div>
            <p style="color:var(--text-light);font-size:.84rem">Fetching events…</p>
        </div>
        <table id="resultsTable">
            <thead><tr><th>Time</th><th>Event</th><th>Value</th><th>Height</th></tr></thead>
            <tbody id="resultsTbody"></tbody>
        </table>
    </div>
</div>

<script>
// ══ CONFIG — thresholds injected from PHP ══
const LOW_HR   = <?= $restMin ?>;
const HIGH_HR  = <?= $restMax ?>;
const CRIT_HR  = <?= $critical ?>;
const MAX_PTS  = 30;
const MAX_LOGS = 15;
const WS_URL   = "wss://thesis2025-h4v3.onrender.com/ws";

// ══ WEBSOCKET ══════════════════════════════
let ws, retryDelay = 1000;
const dot   = document.getElementById('wsDot');
const label = document.getElementById('wsLabel');

function connectWS() {
    ws = new WebSocket(WS_URL);
    ws.onopen = () => {
        dot.className = 'ws-dot live';
        label.textContent = 'Live';
        retryDelay = 1000;
    };
    ws.onclose = () => {
        dot.className = 'ws-dot';
        label.textContent = 'Reconnecting…';
        setTimeout(connectWS, retryDelay);
        retryDelay = Math.min(retryDelay * 2, 30000);
    };
    ws.onerror = () => { dot.className = 'ws-dot err'; ws.close(); };
    ws.onmessage = handleMessage;
}
connectWS();
setInterval(() => { if (ws?.readyState === WebSocket.OPEN) ws.send(JSON.stringify({type:'ping'})); }, 30000);

// ══ CHART ══════════════════════════════════
const hrData = [], hrLabels = [];
const chart = new Chart(document.getElementById('hrChart'), {
    type: 'line',
    data: {
        labels: hrLabels,
        datasets: [{
            label: 'BPM',
            data: hrData,
            borderColor: '#1a6fd4',
            backgroundColor: 'rgba(26,111,212,.07)',
            fill: true,
            tension: 0.35,
            pointRadius: 3,
            pointBackgroundColor: '#1a6fd4',
        }]
    },
    options: {
        animation: false,
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: false, suggestedMin: 40, suggestedMax: 160,
                 grid: { color: '#f0f6ff' }, ticks: { font: { size: 11 } } },
            x: { grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 0 } }
        }
    }
});

// ══ MESSAGE HANDLER ════════════════════════
function handleMessage(e) {
    const d = JSON.parse(e.data);
    if (d.type === 'ping') return;
    const ts = d.timestamp ? new Date(d.timestamp).toLocaleTimeString() : new Date().toLocaleTimeString();

    if (d.type === 'pr') {
        const bpm = parseInt(d.value);
        const el  = document.getElementById('hrValue');
        el.textContent = bpm + ' BPM';
        document.getElementById('lastUpdated').textContent = 'Updated ' + ts;

        hrData.push(bpm); hrLabels.push(ts);
        if (hrData.length > MAX_PTS) { hrData.shift(); hrLabels.shift(); }
        chart.update();

        el.className = 'stat-value';
        if (bpm >= CRIT_HR) {
            el.style.color = 'var(--danger)';
            logEvent('⚠️ Critical HR: ' + bpm + ' BPM', 'red');
            document.getElementById('hrSub').innerHTML = '<span class="stat-dot red"></span> Critical — above ' + CRIT_HR + ' BPM';
        } else if (bpm > HIGH_HR) {
            el.style.color = 'var(--warning)';
            logEvent('↑ High HR: ' + bpm + ' BPM', 'warn');
            document.getElementById('hrSub').innerHTML = '<span class="stat-dot warn"></span> Above resting range';
        } else if (bpm < LOW_HR) {
            el.style.color = 'var(--warning)';
            logEvent('↓ Low HR: ' + bpm + ' BPM', 'warn');
            document.getElementById('hrSub').innerHTML = '<span class="stat-dot warn"></span> Below resting range';
        } else {
            el.classList.add('bpm');
            document.getElementById('hrSub').innerHTML = '<span class="stat-dot blue"></span> Normal range';
        }
    }

    if (d.type === 'fall') {
        triggerFall();
        logEvent('🚨 Fall detected! Height: ' + (d.height ?? '?') + ' m', 'red');
    }
}

// ══ FALL ═══════════════════════════════════
function triggerFall() {
    document.getElementById('fallValue').textContent   = 'FALL DETECTED';
    document.getElementById('fallValue').className     = 'stat-value alert';
    document.getElementById('fallSub').innerHTML       = '<span class="stat-dot red"></span> Emergency alert';
    document.getElementById('fallStatusCard').classList.add('fall-active');
    document.getElementById('fallIconWrap').className  = 'stat-icon red';
    document.getElementById('fallModal').classList.remove('hidden');
}
function acknowledgeFall() {
    document.getElementById('fallModal').classList.add('hidden');
    document.getElementById('fallValue').textContent   = 'Normal';
    document.getElementById('fallValue').className     = 'stat-value ok';
    document.getElementById('fallSub').innerHTML       = '<span class="stat-dot green"></span> No fall detected';
    document.getElementById('fallStatusCard').classList.remove('fall-active');
    document.getElementById('fallIconWrap').className  = 'stat-icon green';
}

// ══ ACTIVITY LOG ═══════════════════════════
function logEvent(text, color) {
    const colors = { red: 'var(--danger)', warn: 'var(--warning)', blue: 'var(--blue-main)', green: 'var(--success)' };
    const log = document.getElementById('activityLog');
    const li  = document.createElement('li');
    li.innerHTML = `<span class="log-dot" style="background:${colors[color]??'var(--blue-main)'}"></span>
        <span class="log-time">${new Date().toLocaleTimeString()}</span>
        <span>${text}</span>`;
    log.prepend(li);
    if (log.children.length > MAX_LOGS) log.removeChild(log.lastChild);
}

// ══ SIDEBAR TOGGLE (mobile/tablet) ════════════════════
const sidebar      = document.getElementById('sidebar');
const overlay      = document.getElementById('sidebarOverlay');
const hamburgerBtn = document.getElementById('hamburgerBtn');

function openSidebar() {
    sidebar.classList.add('open');
    overlay.classList.add('visible');
    hamburgerBtn.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('visible');
    hamburgerBtn.classList.remove('open');
    document.body.style.overflow = '';
}

hamburgerBtn.addEventListener('click', () => {
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
});
overlay.addEventListener('click', closeSidebar);
window.addEventListener('resize', () => { if (window.innerWidth > 1024) closeSidebar(); });

// ══ SEARCH MODAL ═══════════════════════════
const searchModal = document.getElementById('searchModal');
document.getElementById('openSearch').addEventListener('click', () => {
    // Pre-fill with current local time range (today 00:00 to now)
    // Uses local browser time so it matches PST timestamps in the database
    const startEl = document.getElementById('startTime');
    const endEl   = document.getElementById('endTime');
    if (!startEl.value && !endEl.value) {
        const now   = new Date();
        // Format as local time (not UTC) for datetime-local input
        const pad   = n => String(n).padStart(2, '0');
        const localStr = now.getFullYear() + '-' +
                         pad(now.getMonth()+1) + '-' +
                         pad(now.getDate()) + 'T' +
                         pad(now.getHours()) + ':' +
                         pad(now.getMinutes());
        const todayStr = now.getFullYear() + '-' +
                         pad(now.getMonth()+1) + '-' +
                         pad(now.getDate()) + 'T00:00';
        startEl.value = todayStr;
        endEl.value   = localStr;
    }
    searchModal.classList.remove('hidden');
});
document.getElementById('closeSearch').addEventListener('click', () => searchModal.classList.add('hidden'));
searchModal.addEventListener('click', e => { if (e.target === searchModal) searchModal.classList.add('hidden'); });
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        if (!searchModal.classList.contains('hidden')) searchModal.classList.add('hidden');
        else closeSidebar();
    }
});

// ── Print event results ──
function printEventResults() {
    const rows = document.querySelectorAll('#resultsTbody tr');
    if (!rows.length || (rows.length === 1 && rows[0].cells.length === 1)) {
        alert('No results to print. Please search first.');
        return;
    }
    const start = document.getElementById('startTime').value || 'All time';
    const end   = document.getElementById('endTime').value   || 'All time';
    let html = `<html><head><title>VitalLink Event Report</title>
        <style>
            body{font-family:Arial,sans-serif;padding:24px}
            h2{color:#1a6fd4;margin-bottom:4px}
            p{color:#64748b;font-size:.85rem;margin-bottom:16px}
            table{width:100%;border-collapse:collapse}
            th{background:#1a6fd4;color:#fff;padding:10px;text-align:left;font-size:.85rem}
            td{padding:9px 10px;border-bottom:1px solid #e2e8f0;font-size:.85rem}
            tr:nth-child(even) td{background:#f8fafc}
        </style></head><body>
        <h2>VitalLink Event Report</h2>
        <p>From: ${start} &nbsp;|&nbsp; To: ${end}</p>
        <table><thead><tr><th>Time</th><th>Event</th><th>Value</th><th>Height</th></tr></thead><tbody>`;
    rows.forEach(row => {
        html += '<tr>';
        row.querySelectorAll('td').forEach(td => { html += `<td>${td.innerText}</td>`; });
        html += '</tr>';
    });
    html += '</tbody></table></body></html>';
    const w = window.open('', '_blank');
    w.document.write(html);
    w.document.close();
    w.print();
}

// ── Page switching ──
function showPage(page) {
    const dash = document.getElementById('dashboardPage');
    const sett = document.getElementById('settingsPage');
    const navD = document.getElementById('navDashboard');
    const navS = document.getElementById('navSettings');
    if (page === 'settings') {
        dash.style.display = 'none';
        sett.style.display = 'block';
        navD.classList.remove('active');
        navS.classList.add('active');
    } else {
        dash.style.display = 'block';
        sett.style.display = 'none';
        navD.classList.add('active');
        navS.classList.remove('active');
    }
}

// ── Settings tab switching ──
function showStab(name) {
    document.querySelectorAll('.spanel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.stab').forEach(t => t.classList.remove('active'));
    document.getElementById('spanel-' + name).classList.add('active');
    document.getElementById('stab-' + name).classList.add('active');
}

// ── Settings form submit ──
async function submitSettings(section, formId) {
    const form = document.getElementById(formId);
    const data = Object.fromEntries(new FormData(form).entries());
    data.section = section;
    const banner = document.getElementById('settingsBanner');
    banner.style.display = 'none';
    try {
        const res  = await fetch('save_settings.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });
        const json = await res.json();
        banner.className = 'settings-banner ' + (json.success ? 'ok' : 'err');
        banner.textContent = json.success ? '✓ ' + (json.message || 'Saved successfully.') : '✗ ' + (json.error || 'Save failed.');
        banner.style.display = 'block';
        setTimeout(() => { banner.style.display = 'none'; }, 4000);
    } catch (e) {
        banner.className = 'settings-banner err';
        banner.textContent = '✗ Network error. Check connection.';
        banner.style.display = 'block';
    }
}

document.getElementById('personalForm')?.addEventListener('submit', e => {
    e.preventDefault(); submitSettings('personal', 'personalForm');
});
document.getElementById('thresholdsForm')?.addEventListener('submit', e => {
    e.preventDefault(); submitSettings('thresholds', 'thresholdsForm');
});

document.getElementById('applyFilter').addEventListener('click', async () => {
    const types = [...document.querySelectorAll('.evType:checked')].map(c => c.value);
    if (!types.length) { alert('Select at least one event type.'); return; }
    const params = new URLSearchParams();
    types.forEach(t => params.append('types[]', t));

    // Only send date params if actually filled — blank means search ALL dates
    const startVal = document.getElementById('startTime').value;
    const endVal   = document.getElementById('endTime').value;
    if (startVal) params.set('start', startVal);
    if (endVal)   params.set('end',   endVal);
    params.set('limit', document.getElementById('limitSel').value);

    document.getElementById('searchLoading').style.display = 'block';
    document.getElementById('resultsTbody').innerHTML = '';

    try {
        const res  = await fetch('search_events.php?' + params);
        const json = await res.json();
        document.getElementById('searchLoading').style.display = 'none';
        if (!json.success || !json.data?.length) {
            document.getElementById('resultsTbody').innerHTML =
                '<tr><td colspan="4" style="text-align:center;color:var(--text-light);padding:22px">' +
                ((!startVal && !endVal) ? 'No events found in database.' : 'No events found for the selected date range.') +
                '</td></tr>';
            return;
        }
        // Show result count above table
        const countEl = document.getElementById('resultCount');
        if (countEl) countEl.textContent = json.data.length + ' result' + (json.data.length !== 1 ? 's' : '');
        json.data.forEach(row => {

    let value = '--';
    let height = '--';

    if (row.eventtype === 'HeartRate') {
        value = row.heartrate !== null ? row.heartrate + ' BPM' : '--';
    }

    if (row.eventtype === 'Fall') {
        value = 'FALL';
    }

    if (row.heightcm !== null) {
        height = row.heightcm + ' cm';
    }

    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>${new Date(row.eventtime).toLocaleString()}</td>
        <td>${row.eventtype}</td>
        <td>${value}</td>
        <td>${height}</td>
    `;

    document.getElementById('resultsTbody').appendChild(tr);
});
    } catch(err) {
        document.getElementById('searchLoading').style.display = 'none';
        document.getElementById('resultsTbody').innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--danger)">Failed to load. Check server.</td></tr>';
        console.error('Search error:', err);
    }
});
</script>
</body>
</html>
