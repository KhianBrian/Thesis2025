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
$patientData  = [];
$threshold    = [];
$health       = [];

$ps = $pdo->prepare("SELECT * FROM patient_table WHERE userid = ? LIMIT 1");
$ps->execute([$uid]);
$patientData = $ps->fetch() ?: [];

if (!empty($patientData)) {
    $_SESSION['patient_id'] = $patientData['patientid'];

    $ts = $pdo->prepare("SELECT * FROM hr_threshold_table WHERE patientid = ? LIMIT 1");
    $ts->execute([$patientData['patientid']]);
    $threshold = $ts->fetch() ?: [];

    $hs = $pdo->prepare("SELECT * FROM healthhistory_table WHERE patientid = ? ORDER BY recorddate DESC LIMIT 1");
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

$userRole   = $_SESSION['role']     ?? 'patient';
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

@media(max-width:900px){.stat-cards{grid-template-columns:1fr 1fr}}
@media(max-width:640px){.sidebar{transform:translateX(-100%)}.main-wrap{margin-left:0}.stat-cards{grid-template-columns:1fr}}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar">
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
    <a class="nav-item active">
        <svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
        Dashboard
    </a>
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
    <div class="topbar-title">
        Good <?= $greeting ?>, <span><?= htmlspecialchars($firstName1) ?></span> 👋
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

<main class="page-content">

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
        </div>
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

// ══ SEARCH MODAL ═══════════════════════════
const searchModal = document.getElementById('searchModal');
document.getElementById('openSearch').addEventListener('click', () => searchModal.classList.remove('hidden'));
document.getElementById('closeSearch').addEventListener('click', () => searchModal.classList.add('hidden'));
searchModal.addEventListener('click', e => { if (e.target === searchModal) searchModal.classList.add('hidden'); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') searchModal.classList.add('hidden'); });

document.getElementById('applyFilter').addEventListener('click', async () => {
    const types = [...document.querySelectorAll('.evType:checked')].map(c => c.value);
    if (!types.length) { alert('Select at least one event type.'); return; }
    const params = new URLSearchParams();
    types.forEach(t => params.append('types[]', t));
    params.set('start', document.getElementById('startTime').value);
    params.set('end',   document.getElementById('endTime').value);
    params.set('limit', document.getElementById('limitSel').value);

    document.getElementById('searchLoading').style.display = 'block';
    document.getElementById('resultsTbody').innerHTML = '';

    try {
        const res  = await fetch('search_events.php?' + params);
        const json = await res.json();
        document.getElementById('searchLoading').style.display = 'none';
        if (!json.success || !json.data?.length) {
            document.getElementById('resultsTbody').innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--text-light);padding:22px">No events found.</td></tr>';
            return;
        }
        json.data.forEach(row => {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td>${new Date(row.time).toLocaleString()}</td><td>${row.type}</td><td>${row.value??'--'}</td><td>${row.height??'--'}</td>`;
            document.getElementById('resultsTbody').appendChild(tr);
        });
    } catch {
        document.getElementById('searchLoading').style.display = 'none';
        document.getElementById('resultsTbody').innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--danger)">Failed to load. Check server.</td></tr>';
    }
});
</script>
</body>
</html>
