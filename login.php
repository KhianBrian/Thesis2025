<?php
/**
 * login.php  — Online (Render / PHP Docker service)
 *
 * Two entry points:
 *   GET  login.php?t=TOKEN   → QR token login (auto-redirects to dashboard)
 *   POST login.php           → Manual username/password login
 *
 * QR token = base64url(JSON payload) + "." + base64url(HMAC-SHA256 signature)
 * The same QR_SECRET env var must be set on both the RPi (config.php) and Render.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Already logged in → go straight to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: /dashboard/");
    exit();
}

require_once __DIR__ . '/db_connect.php';

// ══ QR TOKEN LOGIN ════════════════════════════════════════════════════
if (isset($_GET['t'])) {
    $secret = getenv("QR_SECRET") ?: "vitallink_qr_2025";
    $token  = trim($_GET['t']);

    $dot = strrpos($token, '.');
    if ($dot !== false) {
        $payloadB64 = substr($token, 0, $dot);
        $sigB64     = substr($token, $dot + 1);

        $expected = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $payloadB64, $secret, true)
        ), '+/', '-_'), '=');

        if (hash_equals($expected, $sigB64)) {
            $payload = json_decode(
                base64_decode(strtr($payloadB64, '-_', '+/')),
                true
            );

            if ($payload && isset($payload['uid'], $payload['exp']) && time() <= (int)$payload['exp']) {
                // ✅ Valid QR — set session and redirect
                $_SESSION['user_id']      = $payload['uid'];
                $_SESSION['username']     = $payload['uname']  ?? '';
                $_SESSION['role']         = strtolower($payload['role'] ?? 'patient');
                $_SESSION['display_name'] = $payload['name']   ?? $payload['uname'] ?? '';
                $_SESSION['patient_id']   = $payload['pid']    ?? null;
                $_SESSION['qr_auth']      = true;

                $qrRole = strtolower($payload['role'] ?? 'patient');
                if ($qrRole === 'admin') {
                    header("Location: /admin.php");
                } else {
                    header("Location: /dashboard/");
                }
                exit();
            } elseif ($payload && time() > (int)$payload['exp']) {
                $qrError = "QR code expired (valid 24 hrs). Scan a fresh one from the RPi dashboard.";
            } else {
                $qrError = "Corrupted QR token. Please generate a new one.";
            }
        } else {
            $qrError = "Invalid QR code signature. Please scan again.";
        }
    } else {
        $qrError = "Malformed QR token.";
    }
}

// ══ MANUAL LOGIN ═════════════════════════════════════════════════════
$error = $qrError ?? "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? 'patient';

    // PostgreSQL uses lowercase column names
    $stmt = $pdo->prepare("SELECT * FROM user_table WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['passwordhash'])) {
        if (strtolower($user['role']) !== strtolower($role)) {
            $error = "This account is not registered as a " . ucfirst($role) . ".";
        } else {
            // Look up patient record
            $ps = $pdo->prepare("SELECT patientid, firstname, lastname FROM patient_table WHERE userid = $1 LIMIT 1");
            $ps->execute([$user['userid']]);
            $pat = $ps->fetch();

            $dispName = $pat ? trim(($pat['firstname']??'').' '.($pat['lastname']??'')) : $user['username'];

            $role = strtolower($user['role']);

            // For caregiver, resolve their assigned patient from caregiver_table
            $patientId = $pat['patientid'] ?? null;
            if ($role === 'caregiver' && !$patientId) {
                $cgq = $pdo->prepare("SELECT patientid FROM caregiver_table WHERE userid = $1 LIMIT 1");
                $cgq->execute([$user['userid']]);
                $cgRow = $cgq->fetch();
                $patientId = $cgRow['patientid'] ?? null;
            }

            $_SESSION['user_id']      = $user['userid'];
            $_SESSION['username']     = $user['username'];
            $_SESSION['role']         = $role;
            $_SESSION['display_name'] = $dispName ?: $user['username'];
            $_SESSION['patient_id']   = $patientId;

            if ($role === 'admin') {
                header("Location: /admin.php");
            } else {
                header("Location: /dashboard/");
            }
            exit();
        }
    } else {
        $error = "Invalid username or password.";
    }
}

$selRole = $_POST['role'] ?? 'patient';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>VitalLink – Online Login</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;background:linear-gradient(135deg,#c8e0f8 0%,#e8f4fd 50%,#d0e8f8 100%);display:flex;align-items:center;justify-content:center;font-family:'Segoe UI',system-ui,sans-serif;position:relative;overflow:hidden}
body::before{content:'';position:fixed;top:-80px;left:-60px;width:280px;height:280px;background:linear-gradient(135deg,#4a90e2,#1565c0);border-radius:50% 60% 70% 40%;opacity:.55;z-index:0}
body::after{content:'';position:fixed;bottom:-60px;right:-60px;width:200px;height:200px;background:linear-gradient(135deg,#a8d4f5,#5ba3e0);border-radius:50% 40% 60% 70%;opacity:.45;z-index:0}
.card{display:flex;width:820px;max-width:95vw;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 20px 60px rgba(30,90,180,.18);position:relative;z-index:1}
.left-panel{width:370px;min-width:280px;background:linear-gradient(145deg,#1a6fd4 0%,#1348a8 60%,#0d3580 100%);padding:50px 40px;display:flex;flex-direction:column;justify-content:center;position:relative;overflow:hidden}
.left-panel::before{content:'';position:absolute;top:-40px;right:-40px;width:200px;height:200px;background:rgba(255,255,255,.06);border-radius:50%}
.left-panel::after{content:'';position:absolute;bottom:-60px;left:-40px;width:240px;height:240px;background:rgba(255,255,255,.04);border-radius:50%}
.dot-grid{position:absolute;top:30px;left:30px;display:grid;grid-template-columns:repeat(8,10px);gap:6px;opacity:.25}
.dot-grid span{width:3px;height:3px;background:#fff;border-radius:50%;display:block}
.left-panel h1{color:#fff;font-size:1.9rem;font-weight:700;line-height:1.2;margin-bottom:28px;position:relative;z-index:1}
.qr-tip{background:rgba(255,255,255,.12);border-radius:12px;padding:16px 18px;position:relative;z-index:1}
.qr-tip h4{color:#fff;font-size:.9rem;font-weight:700;margin-bottom:6px}
.qr-tip p{color:rgba(255,255,255,.78);font-size:.8rem;line-height:1.55}
.right-panel{flex:1;padding:40px 44px;display:flex;flex-direction:column}
.top-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px}
.logo{display:flex;align-items:center;gap:8px;font-size:.85rem;font-weight:700;color:#1a6fd4;letter-spacing:.05em;text-transform:uppercase}
.logo-icon{width:28px;height:28px;background:linear-gradient(135deg,#1a6fd4,#0d3580);border-radius:50%;display:flex;align-items:center;justify-content:center}
.logo-icon svg{width:16px;height:16px;fill:#fff}
.badge{background:#eaf2ff;color:#1a6fd4;padding:5px 12px;border-radius:20px;font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
h2{font-size:1.55rem;font-weight:700;color:#1a1a2e;margin-bottom:20px}
.tab-group{display:flex;border:1.5px solid #e0e9f5;border-radius:10px;overflow:hidden;margin-bottom:22px}
.tab{flex:1;padding:9px 0;text-align:center;font-size:.82rem;font-weight:600;color:#888;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;background:transparent;border:none;transition:background .2s,color .2s;font-family:inherit}
.tab.active{background:#eaf2ff;color:#1a6fd4}
.tab svg{width:14px;height:14px;fill:currentColor}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:.75rem;color:#888;margin-bottom:5px}
.form-group input{width:100%;border:1.5px solid #e0e9f5;border-radius:8px;padding:10px 13px;font-size:.9rem;color:#1a1a2e;outline:none;background:#fafcff;transition:border-color .2s;font-family:inherit}
.form-group input:focus{border-color:#1a6fd4;background:#fff}
.submit-btn{width:100%;background:linear-gradient(135deg,#1a6fd4,#1348a8);color:#fff;border:none;border-radius:8px;padding:13px;font-size:.9rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;margin-top:6px;font-family:inherit;transition:opacity .2s,transform .1s}
.submit-btn:hover{opacity:.9;transform:translateY(-1px)}
.error-msg{background:#fff0f0;border:1px solid #ffcccc;color:#cc0000;padding:9px 13px;border-radius:8px;font-size:.83rem;margin-bottom:14px}
.qr-err{background:#fff8e1;border:1px solid #ffe082;color:#b45309;padding:9px 13px;border-radius:8px;font-size:.83rem;margin-bottom:14px}
@media(max-width:620px){
    .card{flex-direction:column;border-radius:0;min-height:100vh}
    .left-panel{width:100%;min-width:unset;padding:28px 24px}
    .left-panel h1{font-size:1.5rem;margin-bottom:16px}
    .right-panel{padding:28px 24px}
    h2{font-size:1.3rem}
}
@media(max-width:400px){
    body{align-items:flex-start}
    .card{max-width:100%;border-radius:0}
    .right-panel{padding:22px 18px}
    .left-panel{padding:22px 18px}
    .submit-btn{font-size:.85rem;padding:12px}
    .tab{font-size:.75rem;padding:8px 0}
}
</style>
</head>
<body>
<div class="card">
    <div class="left-panel">
        <div class="dot-grid"><?php for($i=0;$i<64;$i++) echo '<span></span>'; ?></div>
        <h1>VitalLink<br>Online</h1>
        <div class="qr-tip">
            <h4>📱 Easiest way to sign in</h4>
            <p>On the RPi dashboard, scan the QR code in the sidebar — you'll be logged in instantly as the same patient.</p>
        </div>
    </div>
    <div class="right-panel">
        <div class="top-bar">
            <div class="logo">
                <div class="logo-icon">
                    <svg viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                </div>
                VitalLink
            </div>
            <span class="badge">Online</span>
        </div>

        <h2>Login</h2>

        <div class="tab-group">
            <button type="button" class="tab <?= $selRole==='patient'?'active':'' ?>" onclick="selTab('patient',this)">
                <svg viewBox="0 0 24 24"><path d="M12 12c2.7 0 5-2.3 5-5s-2.3-5-5-5-5 2.3-5 5 2.3 5 5 5zm0 2c-3.3 0-10 1.7-10 5v2h20v-2c0-3.3-6.7-5-10-5z"/></svg>
                Patient
            </button>
            <button type="button" class="tab <?= $selRole==='caregiver'?'active':'' ?>" onclick="selTab('caregiver',this)">
                <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg>
                Caregiver
            </button>
            <button type="button" class="tab <?= $selRole==='admin'?'active':'' ?>" onclick="selTab('admin',this)">
                <svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 4l5 2.18V11c0 3.32-2.17 6.43-5 7.54-2.83-1.11-5-4.22-5-7.54V7.18L12 5z"/></svg>
                Admin
            </button>
        </div>

        <?php if (!empty($error)): ?>
            <div class="<?= isset($qrError) ? 'qr-err' : 'error-msg' ?>"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="role" id="roleField" value="<?= htmlspecialchars($selRole) ?>">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" placeholder="Enter your username" required
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            <button type="submit" class="submit-btn">Login</button>
        </form>
    </div>
</div>
<script>
function selTab(role, el) {
    document.getElementById('roleField').value = role;
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
}
</script>
</body>
</html>
