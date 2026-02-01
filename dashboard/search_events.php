<?php
require "../db_connect.php";

$data = json_decode(file_get_contents("php://input"), true);

$types = $data["types"];
$start = $data["start"];
$end   = $data["end"];

// Build placeholders for IN (...)
$placeholders = implode(",", array_fill(0, count($types), "?"));

$sql = "
SELECT
  e.eventid,
  e.eventtype,
  e.eventtime,
  hr.heartrate,
  sp.spo2,
  f.estimatedheight
FROM event_table e
LEFT JOIN hr_event hr ON e.eventid = hr.eventid
LEFT JOIN spo2_event sp ON e.eventid = sp.eventid
LEFT JOIN fall_event f ON e.eventid = f.eventid
WHERE e.eventtype IN ($placeholders)
AND e.eventtime BETWEEN ? AND ?
ORDER BY e.eventtime DESC
";

$stmt = $pdo->prepare($sql);

// Bind parameters in correct order
$params = array_merge($types, [$start, $end]);
$stmt->execute($params);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
