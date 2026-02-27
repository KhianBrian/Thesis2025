<?php
/**
 * dashboard/search_events.php — Online (Render / PostgreSQL)
 */
header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once dirname(__DIR__) . '/db_connect.php';
try {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $sessionPid = $_SESSION['patient_id'] ?? null;

    // ── FULL DIAGNOSTIC ─────────────────────────────────────────
    // Count ALL rows in event_table regardless of anything
    $total = $pdo->query("SELECT COUNT(*) FROM event_table")->fetchColumn();
    $pids  = $pdo->query("SELECT DISTINCT patientid FROM event_table ORDER BY patientid")->fetchAll(PDO::FETCH_COLUMN);
    $latest = $pdo->query("SELECT eventid, patientid, eventtype, eventtime FROM event_table ORDER BY eventtime DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);

    // If zero rows — DB is empty or wrong DB
    if ($total == 0) {
        echo json_encode([
            'success' => false,
            'error'   => 'event_table is completely empty in this database',
            'db_host' => getenv('DB_HOST'),
            'db_name' => getenv('DB_NAME'),
            'session_pid' => $sessionPid,
        ]);
        exit;
    }

    $types = $_GET['types'] ?? ['HeartRate','Fall'];
    if (!is_array($types)) $types = explode(',', $types);
    $allowed = ['HeartRate','Fall','Height'];
    $types   = array_values(array_intersect($types, $allowed));
    if (empty($types)) $types = $allowed;

    $start = $_GET['start'] ?? null;
    $end   = $_GET['end']   ?? null;
    $limit = min((int)($_GET['limit'] ?? 100), 500);

    if ($start) $start = str_replace('T', ' ', $start);
    if ($end)   $end   = str_replace('T', ' ', $end);

    $phs = []; $params = []; $idx = 1;
    foreach ($types as $t) { $phs[] = '$'.$idx++; $params[] = $t; }

    $sql = "SELECT e.eventid, e.eventtype, e.eventtime, e.patientid,
                   hr.heartrate, f.severity,
                   ht.heightcm,
                   (SELECT ht2.heightcm
                    FROM event_table e2
                    JOIN height_event ht2 ON ht2.eventid = e2.eventid
                    WHERE e2.patientid = e.patientid
                      AND e2.eventtype = 'Height'
                      AND ABS(EXTRACT(EPOCH FROM (e2.eventtime - e.eventtime))) <= 2
                    ORDER BY ABS(EXTRACT(EPOCH FROM (e2.eventtime - e.eventtime)))
                    LIMIT 1
                   ) AS fall_heightcm
        FROM event_table e
        LEFT JOIN hr_event     hr ON hr.eventid=e.eventid AND e.eventtype='HeartRate'
        LEFT JOIN fall_event   f  ON f.eventid =e.eventid AND e.eventtype='Fall'
        LEFT JOIN height_event ht ON ht.eventid=e.eventid AND e.eventtype='Height'
        WHERE e.eventtype IN (".implode(',',$phs).")";

    if ($start) { $sql .= " AND e.eventtime >= $".$idx++; $params[] = $start; }
    if ($end)   { $sql .= " AND e.eventtime <= $".$idx++; $params[] = $end; }
    $sql .= " ORDER BY e.eventtime DESC LIMIT $".$idx;
    $params[] = $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $events = array_map(function($row) {
        $heightCm = null;
        if ($row['eventtype'] === 'Fall') {
            $heightCm = $row['fall_heightcm'] ?? null;
        } elseif ($row['eventtype'] === 'Height') {
            $heightCm = $row['heightcm'] ?? null;
        }
        switch ($row['eventtype']) {
            case 'HeartRate': $v = ($row['heartrate']??null) ? $row['heartrate'].' BPM' : '--'; break;
            case 'Fall':      $v = $row['severity'] ?? 'Detected'; break;
            case 'Height':    $v = $heightCm ? round($heightCm,1).' cm' : '--'; break;
            default:          $v = '--';
        }
        return [
            'event_id'   => $row['eventid'],
            'type'       => $row['eventtype'],
            'time'       => $row['eventtime'],
            'value'      => $v,
            'height'     => $heightCm ? round($heightCm/100,2).' m' : 'N/A',
        ];
    }, $rows);

    echo json_encode([
        'success'           => true,
        'data'              => $events,
        'debug_total_rows'  => $total,
        'debug_pids_in_db'  => $pids,
        'debug_session_pid' => $sessionPid,
        'debug_latest_3'    => $latest,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'db_host' => getenv('DB_HOST'),
        'db_name' => getenv('DB_NAME'),
    ]);
}
