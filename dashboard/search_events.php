<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db_connect.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'error'=>'Not logged in']);
    exit;
}

$uid  = $_SESSION['user_id'];
$role = strtolower($_SESSION['role'] ?? 'patient');

/* ── Resolve patient ID properly ───────────────────────────── */

if ($role === 'caregiver') {
    $stmt = $pdo->prepare("SELECT patientid FROM caregiver_table WHERE userid = ? LIMIT 1");
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row) {
        echo json_encode(['success'=>true,'data'=>[]]);
        exit;
    }
    $patientId = $row['patientid'];
} else {
    $stmt = $pdo->prepare("SELECT patientid FROM patient_table WHERE userid = ? LIMIT 1");
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row) {
        echo json_encode(['success'=>true,'data'=>[]]);
        exit;
    }
    $patientId = $row['patientid'];
}

/* ── Filters ───────────────────────────────────────────────── */

$types = $_GET['types'] ?? [];
if (!is_array($types)) $types = [$types];

$allowed = ['HeartRate','Fall','Height'];
$types   = array_values(array_intersect($types,$allowed));
if (!$types) $types = ['HeartRate','Fall'];

$inList = "'" . implode("','",$types) . "'";

$start = $_GET['start'] ?? null;
$end   = $_GET['end']   ?? null;
$limit = min((int)($_GET['limit'] ?? 100), 1000);

$where  = "e.patientid = ? AND e.eventtype::text IN ($inList)";
$params = [$patientId];

if ($start) {
    $where .= " AND e.eventtime >= ?";
    $params[] = date("Y-m-d H:i:s", strtotime($start));
}

if ($end) {
    $where .= " AND e.eventtime <= ?";
    $params[] = date("Y-m-d H:i:s", strtotime($end));
}

/* ── Query ─────────────────────────────────────────────────── */

$sql = "
    SELECT
        e.eventid,
        e.eventtype::text AS eventtype,
        e.eventtime,
        hr.heartrate,
        f.severity,
        (
            SELECT ht.heightcm
            FROM event_table e2
            JOIN height_event ht ON ht.eventid = e2.eventid
            WHERE e2.patientid = e.patientid
              AND e2.eventtype::text = 'Height'
              AND ABS(EXTRACT(EPOCH FROM (e2.eventtime - e.eventtime))) <= 2
            ORDER BY ABS(EXTRACT(EPOCH FROM (e2.eventtime - e.eventtime)))
            LIMIT 1
        ) AS fallheightcm
    FROM event_table e
    LEFT JOIN hr_event hr ON hr.eventid = e.eventid
    LEFT JOIN fall_event f ON f.eventid = e.eventid
    WHERE $where
    ORDER BY e.eventtime DESC
    LIMIT ?
";

$params[] = $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ── Format Output ─────────────────────────────────────────── */

$events = array_map(function($row){

    $heightcm = $row['fallheightcm'] ?? null;
    $height   = $heightcm ? round($heightcm/100,2).' m' : 'N/A';

    switch ($row['eventtype']) {
        case 'HeartRate':
            $value = $row['heartrate'] ? $row['heartrate'].' BPM' : '--';
            break;
        case 'Fall':
            $value = $row['severity'] ?? 'Detected';
            break;
        case 'Height':
            $value = $heightcm ? round($heightcm,1).' cm' : '--';
            break;
        default:
            $value = '--';
    }

    return [
        'eventid'   => $row['eventid'],
        'eventtype' => $row['eventtype'],
        'eventtime' => $row['eventtime'],
        'value'     => $value,
        'height'    => $height
    ];
}, $rows);

echo json_encode(['success'=>true,'data'=>$events]);