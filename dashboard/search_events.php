<?php
// DO NOT echo PHP errors in JSON responses
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once __DIR__ . '/../db_connect.php';

$data = json_decode(file_get_contents("php://input"), true);

$types = $data['types'] ?? [];
$start = $data['start'] ?? null;
$end   = $data['end'] ?? null;

if (empty($types) || !$start || !$end) {
    echo json_encode([]);
    exit;
}

/*
 Allowed event types after SpO2 removal:
 - HeartRate
 - Fall
 - Height
*/

$placeholders = implode(',', array_fill(0, count($types), '?'));

$sql = "
SELECT
    e.eventid,
    e.eventtype,
    e.eventtime,
    hr.heartrate,
    h.heightcm AS estimatedheight
FROM event_table e
LEFT JOIN hr_event hr ON e.eventid = hr.eventid
LEFT JOIN height_event h ON e.eventid = h.eventid
WHERE e.eventtype IN ($placeholders)
AND e.eventtime BETWEEN ? AND ?
ORDER BY e.eventtime DESC
";

$stmt = $pdo->prepare($sql);
$params = array_merge($types, [$start, $end]);
$stmt->execute($params);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
exit;
