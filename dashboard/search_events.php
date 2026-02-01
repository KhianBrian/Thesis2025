<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once __DIR__ . "/../db_connect.php";

$data = json_decode(file_get_contents("php://input"), true);

$types = $data['types'] ?? [];
$start = $data['start'] ?? null;
$end   = $data['end'] ?? null;

if (empty($types)) {
  echo json_encode([]);
  exit;
}

$conditions = [];
$params = [];

// Event types
$placeholders = implode(",", array_fill(0, count($types), "?"));
$conditions[] = "e.eventtype IN ($placeholders)";
$params = array_merge($params, $types);

// Time range (ONLY if both are present)
if ($start && $end) {
  $conditions[] = "e.eventtime BETWEEN ? AND ?";
  $params[] = $start;
  $params[] = $end;
}

$where = implode(" AND ", $conditions);

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
WHERE $where
ORDER BY e.eventtime DESC
";

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "error" => "Query failed",
    "details" => $e->getMessage()
  ]);
}
