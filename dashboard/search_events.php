<?php
/**
 * dashboard/search_events.php — Online (Render / PostgreSQL)
 * PostgreSQL stores columns lowercase. Uses positional $1,$2 params.
 */
header('Content-Type: application/json');
ini_set('display_errors', 0);
require_once dirname(__DIR__) . '/db_connect.php';
try {
    // ── Session: get patientid so we only return THIS patient's events ──
    if (session_status() === PHP_SESSION_NONE) session_start();
    $sessionPid = $_SESSION['patient_id'] ?? null;

    $types = $_GET['types'] ?? ['HeartRate','Fall'];
    if (!is_array($types)) $types = explode(',', $types);
    $allowed = ['HeartRate','Fall','Height'];
    $types   = array_values(array_intersect($types, $allowed));
    if (empty($types)) $types = $allowed;

    $start = $_GET['start'] ?? null;
    $end   = $_GET['end']   ?? null;
    $limit = min((int)($_GET['limit'] ?? 100), 500);

    // Fix datetime-local format: browser sends "2026-02-25T03:53:39"
    // PostgreSQL needs "2026-02-25 03:53:39" — replace T with space
    if ($start) $start = str_replace('T', ' ', $start);
    if ($end)   $end   = str_replace('T', ' ', $end);

    $phs = []; $params = []; $idx = 1;
    foreach ($types as $t) { $phs[] = '$'.$idx++; $params[] = $t; }

    // For Fall events, find the matching Height event that shares the same timestamp
    // (recorded within 1 second of the fall) to get the fall height.
    // Height events are stored as separate rows so we subquery for the closest one.
    $sql = "SELECT e.eventid, e.eventtype, e.eventtime,
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

    // Always filter by patient so users only see their own data
    if ($sessionPid) {
        $sql .= " AND e.patientid = $".$idx++;
        $params[] = (int)$sessionPid;
    }

    if ($start) { $sql .= " AND e.eventtime >= $".$idx++; $params[] = $start; }
    if ($end)   { $sql .= " AND e.eventtime <= $".$idx++; $params[] = $end; }
    $sql .= " ORDER BY e.eventtime DESC LIMIT $".$idx;
    $params[] = $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $events = array_map(function($row) {
        // For Fall rows: use the subqueried fall_heightcm (linked Height event)
        // For Height rows: use heightcm directly
        // For HeartRate rows: height is N/A
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
            'event_id' => $row['eventid'],
            'type'     => $row['eventtype'],
            'time'     => $row['eventtime'],
            'value'    => $v,
            'height'   => $heightCm ? round($heightCm/100,2).' m' : 'N/A',
        ];
    }, $rows);

    echo json_encode(['success'=>true,'data'=>$events]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
