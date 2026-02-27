<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

try {

    require_once __DIR__ . '/../db_connect.php';

    // =============================
    // 1️⃣ Accept JSON or GET
    // =============================

    $data = json_decode(file_get_contents("php://input"), true);

    if ($data) {
        $types = $data['types'] ?? [];
        $start = $data['start'] ?? null;
        $end   = $data['end'] ?? null;
    } else {
        // fallback to GET
        $types = $_GET['types'] ?? [];
        $start = $_GET['start'] ?? null;
        $end   = $_GET['end'] ?? null;

        if (!is_array($types)) {
            $types = [$types];
        }
    }

    if (empty($types) || !$start || !$end) {
        echo json_encode(["success" => true, "data" => []]);
        exit;
    }

    $start = date("Y-m-d H:i:s", strtotime($start));
    $end   = date("Y-m-d H:i:s", strtotime($end));

    // =============================
    // 2️⃣ PostgreSQL ANY array
    // =============================

    $sql = "
        SELECT
            e.eventid,
            e.eventtype,
            e.eventtime,
            hr.heartrate,
            h.heightcm
        FROM event_table e
        LEFT JOIN hr_event hr ON e.eventid = hr.eventid
        LEFT JOIN height_event h ON e.eventid = h.eventid
        WHERE e.eventtype = ANY(:types)
        AND e.eventtime BETWEEN :start AND :end
        ORDER BY e.eventtime DESC
    ";

    $stmt = $pdo->prepare($sql);

    $pgArray = '{' . implode(',', $types) . '}';

    $stmt->bindParam(':types', $pgArray);
    $stmt->bindParam(':start', $start);
    $stmt->bindParam(':end', $end);

    $stmt->execute();

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "data" => $results
    ]);

    exit;

} catch (Throwable $e) {

    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
    exit;
}