<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();

try {
    require_once __DIR__ . '/../db_connect.php';

    // Accept GET params
    $types = $_GET['types'] ?? [];
    $start = $_GET['start'] ?? null;
    $end   = $_GET['end']   ?? null;
    $limit = min((int)($_GET['limit'] ?? 100), 99999);

    if (!is_array($types)) $types = [$types];

    // Sanitise types
    $allowed = ['HeartRate', 'Fall', 'Height'];
    $types   = array_values(array_intersect($types, $allowed));
    if (empty($types)) $types = $allowed;

    $pgArray = '{' . implode(',', $types) . '}';

    // Build query — date filters are optional
    $params = [$pgArray];
    $where  = "e.eventtype = ANY($1)";
    $idx    = 2;

    if ($start) {
        $where   .= " AND e.eventtime >= \$$idx";
        $params[] = date("Y-m-d H:i:s", strtotime($start));
        $idx++;
    }
    if ($end) {
        $where   .= " AND e.eventtime <= \$$idx";
        $params[] = date("Y-m-d H:i:s", strtotime($end));
        $idx++;
    }

    $params[] = $limit;

    $sql = "
        SELECT
            e.eventid,
            e.eventtype,
            e.eventtime,
            hr.heartrate,
            h.heightcm,
            f.severity,
            (SELECT ht2.heightcm
             FROM event_table e2
             JOIN height_event ht2 ON ht2.eventid = e2.eventid
             WHERE e2.patientid = e.patientid
               AND e2.eventtype = 'Height'
               AND ABS(EXTRACT(EPOCH FROM (e2.eventtime - e.eventtime))) <= 2
             ORDER BY ABS(EXTRACT(EPOCH FROM (e2.eventtime - e.eventtime)))
             LIMIT 1
            ) AS fallheightcm
        FROM event_table e
        LEFT JOIN hr_event hr ON hr.eventid = e.eventid AND e.eventtype = 'HeartRate'
        LEFT JOIN fall_event f ON f.eventid = e.eventid AND e.eventtype = 'Fall'
        LEFT JOIN height_event h ON h.eventid = e.eventid AND e.eventtype = 'Height'
        WHERE $where
        ORDER BY e.eventtime DESC
        LIMIT \$$idx
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Shape rows to match frontend expectations
    $events = array_map(function($row) {
        if ($row['eventtype'] === 'Fall') {
            $heightcm = $row['fallheightcm'] ?? null;
        } elseif ($row['eventtype'] === 'Height') {
            $heightcm = $row['heightcm'] ?? null;
        } else {
            $heightcm = null;
        }

        $height = $heightcm ? round($heightcm / 100, 2) . ' m' : 'N/A';

        switch ($row['eventtype']) {
            case 'HeartRate': $value = $row['heartrate'] ? $row['heartrate'] . ' BPM' : '--'; break;
            case 'Fall':      $value = $row['severity'] ?? 'Detected'; break;
            case 'Height':    $value = $heightcm ? round($heightcm, 1) . ' cm' : '--'; break;
            default:          $value = '--';
        }

        return [
            'eventid'   => $row['eventid'],
            'eventtype' => $row['eventtype'],
            'eventtime' => $row['eventtime'],
            'heartrate' => $row['heartrate'],
            'heightcm'  => $heightcm,
            'value'     => $value,
            'height'    => $height,
        ];
    }, $rows);

    echo json_encode(['success' => true, 'data' => $events]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
