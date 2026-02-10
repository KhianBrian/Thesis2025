<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

try {
    // ✅ SAFE absolute include
    $dbPath = dirname(__DIR__) . '/db_connect.php';
    if (!file_exists($dbPath)) {
        throw new Exception("db_connect.php not found at $dbPath");
    }
    require_once $dbPath;

    if (!isset($pdo)) {
        throw new Exception("Database connection not initialized");
    }

    $data = json_decode(file_get_contents("php://input"), true);

    $types = $data['types'] ?? [];
    $start = $data['start'] ?? null;
    $end   = $data['end'] ?? null;

    if (empty($types) || !$start || !$end) {
        echo json_encode([]);
        exit;
    }

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

    $params = array_merge($types, [$start, $end]);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;

} catch (Throwable $e) {
    // ✅ GUARANTEED JSON EVEN ON FATAL ERROR
    echo json_encode([
        "error" => "Backend failure",
        "message" => $e->getMessage()
    ]);
    exit;
}
