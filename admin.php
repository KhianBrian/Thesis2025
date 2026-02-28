<?php
/**
 * admin.php — Online Admin Panel (Render)
 * Read-only view of users and patients from PostgreSQL.
 * No password changes or deletes here — manage those on local RPi admin panel.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: /login.php");
    exit();
}

require_once __DIR__ . '/db_connect.php';

$adminName = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Admin';
$msg = '';
$msgType = '';

// ── Handle caregiver link updates (admin can reassign caregivers online) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_caregiver') {
        $cid      = (int)$_POST['caregiver_id'];
        $pid      = (int)$_POST['patient_id'];
        $fullname = trim($_POST['fullname'] ?? '');
        $cgtype   = $_POST['caregiver_type'] ?? 'Family';
        $relation = trim($_POST['relationship'] ?? '');
        $stmt = $pdo->prepare("
            INSERT INTO caregiver_table (userid, patientid, fullname, caregivertype, relationshiptopatient)
            VALUES ($1,$2,$3,$4,$5)
            ON CONFLICT (userid) DO UPDATE SET
                patientid=EXCLUDED.patientid,
                fullname=EXCLUDED.fullname,
                caregivertype=EXCLUDED.caregivertype,
                relationshiptopatient=EXCLUDED.relationshiptopatient
        ");
        $stmt->execute([$cid, $pid, $fullname, $cgtype, $relation]);
        $msg = 'Caregiver link updated.'; $msgType = 'success';
    }
}

// ── Load data ──────────────────────────────────────────────────────────
$users = $pdo->query("
    SELECT u.userid, u.username, u.email, u.role,
           COALESCE(u.isactive, 1) AS isactive,
           p.patientid, p.firstname, p.lastname
    FROM user_table u
    LEFT JOIN patient_table p ON p.userid = u.userid
    ORDER BY u.role, u.username
")->fetchAll(PDO::FETCH_ASSOC);

$allPatients = $pdo->query("
    SELECT p.patientid, p.firstname, p.lastname, p.age, p.gender,
           u.username, u.email,
           h.conditiontext,
           t.restingmin, t.restingmax, t.criticallevel,
           (SELECT COUNT(*) FROM event_table e WHERE e.patientid = p.patientid) AS totalevents,
           (SELECT MAX(e2.eventtime) FROM event_table e2 WHERE e2.patientid = p.patientid) AS lastevent
    FROM patient_table p
    LEFT JOIN user_table u ON u.userid = p.userid
    LEFT JOIN healthhistory_table h ON h.patientid = p.patientid
    LEFT JOIN hr_threshold_table t ON t.patientid = p.patientid
    ORDER BY p.firstname
")->fetchAll(PDO::FETCH_ASSOC);

$caregiverLinks = $pdo->query("
    SELECT c.userid AS caregiveruid, u.username AS caregivername,
           c.fullname, c.caregivertype, c.relationshiptopatient,
           c.patientid, p.firstname, p.lastname
    FROM caregiver_table c
    JOIN user_table u ON u.userid = c.userid
    JOIN patient_table p ON p.patientid = c.patientid
    ORDER BY u.username
")->fetchAll(PDO::FETCH_ASSOC);

$caregivers = $pdo->query("SELECT userid, username FROM user_table WHERE role::text ILIKE 'caregiver' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$patients   = $pdo->query("SELECT patientid, firstname, lastname FROM patient_table ORDER BY firstname")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>VitalLink — Online Admin</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',system-ui,sans-serif;background:#f0f6ff;color:#1a1a2e;min-height:100vh}
.topbar{background:linear-gradient(135deg,#1348a8,#0d3580);padding:0;box-shadow:0 2px 12px rgba(13,53,128,.25)}
.topbar-inner{display:flex;align-items:center;justify-content:space-between;padding:0 28px;flex-wrap:wrap;gap:8px}
.topbar-brand{display:flex;align-items:center;gap:12px;padding:16px 0}
.brand-icon{width:36px;height:36px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center}
.topbar-brand h1{font-size:1rem;font-weight:700;color:#fff}
.topbar-brand span{font-size:0.78rem;color:rgba(255,255,255,.6)}
.topbar-links{display:flex;gap:8px}
.topbar a{color:rgba(255,255,255,.85);text-decoration:none;font-size:0.8rem;background:rgba(255,255,255,.12);padding:7px 16px;border-radius:8px;font-weight:600}
.topbar a:hover{background:rgba(255,255,255,.22)}
.topbar a.logout{background:rgba(239,68,68,.25);color:#fca5a5}
.topbar a.logout:hover{background:rgba(239,68,68,.4)}
.tabs{display:flex;background:#fff;border-bottom:2px solid #e8f0fb;padding:0 28px;overflow-x:auto}
.tab{padding:14px 22px;font-size:0.84rem;font-weight:600;color:#94a3b8;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;white-space:nowrap;transition:color .2s,border-color .2s}
.tab.active{color:#1a6fd4;border-bottom-color:#1a6fd4;background:linear-gradient(to bottom,#f0f7ff,#fff)}
.tab:hover:not(.active){color:#1a6fd4;border-bottom-color:#bfdbfe}
.tab-content{display:none}.tab-content.active{display:block}
.container{max-width:1200px;margin:28px auto;padding:0 24px}
.notice{background:#fff8e1;border:1px solid #ffe082;border-radius:10px;padding:11px 16px;font-size:0.83rem;color:#b45309;margin-bottom:18px}
.section{background:#fff;border-radius:14px;box-shadow:0 2px 16px rgba(30,90,180,.09);overflow:hidden;margin-bottom:20px;border:1px solid #e8f0fb}
.section-header{padding:16px 22px;border-bottom:1px solid #e8f0fb;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;background:linear-gradient(to right,#fafcff,#fff)}
.section-header h2{font-size:0.9rem;font-weight:700}
.section-body{padding:20px 22px;overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:0.82rem;min-width:560px}
th{text-align:left;padding:8px 11px;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#64748b;border-bottom:1.5px solid #e8f0fb;white-space:nowrap}
td{padding:9px 11px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:#fafcff}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:0.7rem;font-weight:700;text-transform:uppercase}
.badge.admin{background:#fef3c7;color:#b45309}
.badge.caregiver{background:#f0fdf4;color:#15803d}
.badge.patient{background:#e0f2fe;color:#0369a1}
.badge.active{background:#f0fdf4;color:#15803d}
.badge.disabled{background:#fee2e2;color:#b91c1c}
.btn{border:none;border-radius:8px;padding:7px 14px;font-size:0.8rem;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;display:inline-flex;align-items:center;gap:5px;transition:all .15s}
.btn-blue{background:#1a6fd4;color:#fff;box-shadow:0 2px 6px rgba(26,111,212,.3)}
.btn-blue:hover{background:#1558b0;transform:translateY(-1px)}
.msg{padding:11px 16px;border-radius:9px;font-size:0.85rem;margin-bottom:16px}
.msg.success{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d}
.msg.error{background:#fff1f2;border:1px solid #fecdd3;color:#be123c}
.patient-card{border:1px solid #e8f0fb;border-radius:12px;padding:14px 18px;margin-bottom:12px}
.patient-card h3{font-size:0.9rem;font-weight:700;margin-bottom:10px}
.patient-meta{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px}
.meta-item label{display:block;font-size:0.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:2px}
.meta-item span{font-size:0.82rem;color:#1a1a2e;font-weight:500}
.form-inline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.form-inline input,.form-inline select{border:1.5px solid #e0e9f5;border-radius:8px;padding:7px 10px;font-size:0.81rem;outline:none;font-family:inherit;background:#fafcff}

/* ══ MOBILE ══ */
@media(max-width:768px){
    .topbar-inner{padding:0 16px;gap:6px}
    .topbar-brand h1{font-size:.9rem}
    .topbar-brand span{font-size:.72rem}
    .topbar-links{gap:6px}
    .topbar a{padding:6px 12px;font-size:.75rem}
    .tabs{padding:0 12px}
    .tab{padding:12px 14px;font-size:.78rem}
    .container{padding:0 14px;margin:16px auto}
    .section-body{padding:14px;overflow-x:auto}
    .section-header{padding:12px 16px}
    /* make tables scroll horizontally */
    table{min-width:480px;font-size:.78rem}
    th,td{padding:7px 9px}
    .patient-meta{grid-template-columns:1fr 1fr}
    .patient-card{padding:12px 14px}
    .notice{font-size:.78rem;padding:10px 13px}
    .form-inline{flex-wrap:wrap}
    .form-inline select{flex:1;min-width:120px}
}

@media(max-width:480px){
    .topbar-inner{flex-direction:column;align-items:flex-start;padding:10px 14px}
    .topbar-brand{padding:8px 0 4px}
    .topbar-links{padding-bottom:10px}
    .tabs{gap:0}
    .tab{padding:10px 12px;font-size:.75rem}
    .patient-meta{grid-template-columns:1fr}
    /* Caregiver table — allow horizontal scroll on the wrapper */
    .section-body{-webkit-overflow-scrolling:touch}
    table{min-width:420px}
    .btn{padding:6px 10px;font-size:.75rem}
}
</style>
</head>
<body>

<div class="topbar">
<div class="topbar-inner">
    <div class="topbar-brand">
        <div class="brand-icon">
            <svg viewBox="0 0 24 24" style="width:18px;height:18px;fill:#fff"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
        </div>
        <div>
            <h1>VitalLink Online Admin</h1>
            <span>Logged in as <?= htmlspecialchars($adminName) ?></span>
        </div>
    </div>
    <div class="topbar-links">
        <a href="/logout.php" class="logout">Logout</a>
    </div>
</div>
</div>

<div class="tabs">
    <div class="tab active" data-tab="users">Users</div>
    <div class="tab" data-tab="patients">Patient Data</div>
    <div class="tab" data-tab="caregivers">Caregiver Links</div>
</div>

<div class="container">

<div class="notice">
    ⚠️ This is the <strong>online read-only admin view</strong>. To create/delete users or change passwords, use the <strong>local RPi admin panel</strong>.
</div>

<?php if ($msg): ?>
<div class="msg <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- ══ USERS ══ -->
<div id="tab-users" class="tab-content active">
<div class="section">
    <div class="section-header"><h2>All Users (<?= count($users) ?>)</h2></div>
    <div class="section-body">
        <table>
            <thead><tr><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Patient</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                <td><?= htmlspecialchars($u['email'] ?? '—') ?></td>
                <td><span class="badge <?= strtolower($u['role']) ?>"><?= htmlspecialchars($u['role']) ?></span></td>
                <td><span class="badge <?= $u['isactive'] ? 'active' : 'disabled' ?>"><?= $u['isactive'] ? 'Active' : 'Disabled' ?></span></td>
                <td><?= $u['firstname'] ? htmlspecialchars($u['firstname'].' '.$u['lastname']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<!-- ══ PATIENT DATA ══ -->
<div id="tab-patients" class="tab-content">
<?php if (empty($allPatients)): ?>
<div class="section"><div class="section-body"><p style="color:#64748b;">No patients found.</p></div></div>
<?php else: foreach ($allPatients as $p): ?>
<div class="patient-card">
    <h3>
        <?= htmlspecialchars(trim($p['firstname'].' '.$p['lastname'])) ?>
        <span style="font-size:0.76rem;font-weight:400;color:#64748b;margin-left:8px;">
            <?= htmlspecialchars($p['gender']??'') ?><?= $p['age'] ? ' · Age '.$p['age'] : '' ?>
        </span>
    </h3>
    <div class="patient-meta">
        <div class="meta-item"><label>Account</label><span><?= htmlspecialchars($p['username']??'—') ?></span></div>
        <div class="meta-item"><label>Email</label><span><?= htmlspecialchars($p['email']??'—') ?></span></div>
        <div class="meta-item"><label>Conditions</label><span><?= htmlspecialchars($p['conditiontext']??'—') ?></span></div>
        <div class="meta-item"><label>HR Thresholds</label><span><?= $p['restingmin'] ? "Resting {$p['restingmin']}–{$p['restingmax']} BPM · Critical {$p['criticallevel']} BPM" : '—' ?></span></div>
        <div class="meta-item"><label>Total Events</label><span><?= number_format($p['totalevents']) ?></span></div>
        <div class="meta-item"><label>Last Event</label><span><?= $p['lastevent'] ? date('M j, Y g:i A', strtotime($p['lastevent'])) : '—' ?></span></div>
    </div>
</div>
<?php endforeach; endif; ?>
</div>

<!-- ══ CAREGIVER LINKS ══ -->
<div id="tab-caregivers" class="tab-content">
<div class="section">
    <div class="section-header"><h2>Caregiver–Patient Relationships</h2></div>
    <div class="section-body">
        <?php if ($caregiverLinks): ?>
        <table style="margin-bottom:22px;">
            <thead><tr><th>Username</th><th>Full Name</th><th>Type</th><th>Relationship</th><th>Monitoring</th><th>Reassign</th></tr></thead>
            <tbody>
            <?php foreach ($caregiverLinks as $cl): ?>
            <tr>
                <td><?= htmlspecialchars($cl['caregivername']) ?></td>
                <td><?= htmlspecialchars($cl['fullname']) ?></td>
                <td><span class="badge caregiver"><?= htmlspecialchars($cl['caregivertype']) ?></span></td>
                <td><?= htmlspecialchars($cl['relationshiptopatient']??'—') ?></td>
                <td><strong><?= htmlspecialchars($cl['firstname'].' '.$cl['lastname']) ?></strong></td>
                <td>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_caregiver">
                        <input type="hidden" name="caregiver_id" value="<?= $cl['caregiveruid'] ?>">
                        <input type="hidden" name="fullname" value="<?= htmlspecialchars($cl['fullname']) ?>">
                        <input type="hidden" name="caregiver_type" value="<?= htmlspecialchars($cl['caregivertype']) ?>">
                        <input type="hidden" name="relationship" value="<?= htmlspecialchars($cl['relationshiptopatient']??'') ?>">
                        <div style="display:flex;gap:5px;align-items:center;">
                            <select name="patient_id" style="border:1.5px solid #e0e9f5;border-radius:7px;padding:4px 8px;font-size:0.79rem;font-family:inherit;">
                                <?php foreach ($patients as $p): ?>
                                <option value="<?= $p['patientid'] ?>" <?= $p['patientid']==$cl['patientid']?'selected':'' ?>>
                                    <?= htmlspecialchars($p['firstname'].' '.$p['lastname']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-blue" type="submit">Save</button>
                        </div>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p style="color:#64748b;font-size:0.85rem;margin-bottom:20px;">No caregiver links found. These sync from the local RPi.</p>
        <?php endif; ?>
    </div>
</div>
</div>

</div><!-- container -->

<script>
document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        document.getElementById('tab-' + this.dataset.tab).classList.add('active');
    });
});
</script>
</body>
</html>
