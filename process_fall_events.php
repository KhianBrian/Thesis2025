<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$DB_HOST = "sql100.infinityfree.com";   
$DB_USER = "if0_40579343";         
$DB_PASS = "Thesis2025";     
$DB_NAME = "if0_40579343_vitallink"; 

$BASE_DIR = __DIR__ . "/../fall_events/";
$PROCESSED_DIR = $BASE_DIR . "processed/";

echo "<h3>Processing fall event files...</h3>";

// ---- 3. Ensure directories exist ----
if (!is_dir($BASE_DIR)) die("FOLDER MISSING: fall_events/");
if (!is_dir($PROCESSED_DIR)) mkdir($PROCESSED_DIR, 0777, true);

// ---- 4. Connect to InfinityFree database ----
$conn = mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if (!$conn) {
    die("DB connection failed: " . mysqli_connect_error());
}

// ---- 5. Scan for files ----
$files = glob($BASE_DIR . "*.txt");

if (!$files || count($files) == 0) {
    echo "No event files found.<br>";
    exit;
}

// ---- 6. Process each file ----
foreach ($files as $filePath) {

    echo "Reading: " . basename($filePath) . "<br>";

    // read file safely
    $content = @file_get_contents($filePath);
    if ($content === false) {
        echo "Cannot read file.<br>";
        continue;
    }

    // parse key=value format
    $lines = explode("\n", $content);
    $data = [];

    foreach ($lines as $line) {
        $pair = explode("=", trim($line));
        if (count($pair) == 2) {
            $data[$pair[0]] = $pair[1];
        }
    }

    // assign defaults
    $device_id = $data["device_id"] ?? "unknown";
    $event = $data["event"] ?? "undefined";
    $timestamp = $data["timestamp"] ?? date("Y-m-d H:i:s");

    // ---- 7. Insert into database ----
    $sql = "INSERT INTO fall_events (device_id, event, timestamp)
            VALUES ('$device_id', '$event', '$timestamp')";

    if (mysqli_query($conn, $sql)) {
        echo "Inserted into database.<br>";
    } else {
        echo "DB Error: " . mysqli_error($conn) . "<br>";
    }

    // ---- 8. Move file to processed folder ----
    $newPath = $PROCESSED_DIR . basename($filePath);
    if (@rename($filePath, $newPath)) {
        echo "Moved to processed.<br><br>";
    } else {
        echo "Failed to move file.<br><br>";
    }
}

mysqli_close($conn);
echo "<h4>Done!</h4>";
?>