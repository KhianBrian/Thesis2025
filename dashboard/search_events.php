<?php
header('Content-Type: application/json');

require_once __DIR__ . '/db_connect.php';

try {
    $dbName = $pdo->query("SELECT current_database()")->fetchColumn();
    $count  = $pdo->query("SELECT COUNT(*) FROM event_table")->fetchColumn();

    echo json_encode([
        "database" => $dbName,
        "event_count" => $count
    ]);
    exit;

} catch (Throwable $e) {
    echo json_encode([
        "error" => $e->getMessage()
    ]);
    exit;
}