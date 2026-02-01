<?php

header("Content-Type: application/json");

if (!isset($_POST["device_id"]) || !isset($_POST["event"])) {
    echo json_encode(["status"=>"error", "message"=>"Missing parameters"]);
    exit();
}

$device_id = $_POST["device_id"];
$event = $_POST["event"];

// --- InfinityFree credentials (REPLACE THESE) ---
$servername = "thesis2025.kesug.com";   // ngalan sa website
$username = "	if0_40579343";        // MySQLusername
$password = "Thesis2025";
$dbname = "if0_40579343_vitallink";
// -------------------------------------------------

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(["status"=>"error", "message"=>"DB connection failed"]);
    exit();
}

$sql = "INSERT INTO fall_events (device_id, event) VALUES (?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $device_id, $event);

if ($stmt->execute()) {
    echo json_encode(["status"=>"success", "message"=>"Fall event recorded"]);
} else {
    echo json_encode(["status"=>"error", "message"=>"Insert failed"]);
}

$stmt->close();
$conn->close();
?>
