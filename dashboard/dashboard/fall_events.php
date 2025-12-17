<?php
    
$DB_HOST = "sql100.infinityfree.com";
$DB_USER = "if0_40579343";
$DB_PASS = "Thesis2025";
$DB_NAME = "if0_40579343_vitallink";

$conn = mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

$query = "SELECT * FROM fall_events ORDER BY id DESC";
$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Fall Detection Events</title>
</head>
<body>
<h2>Fall Events</h2>

<table border="1" cellpadding="8">
    <tr>
        <th>ID</th>
        <th>Device ID</th>
        <th>Event</th>
        <th>Timestamp</th>
    </tr>
    <?php while($row = mysqli_fetch_assoc($result)): ?>
    <tr>
        <td><?= $row['id'] ?></td>
        <td><?= $row['device_id'] ?></td>
        <td><?= $row['event'] ?></td>
        <td><?= $row['timestamp'] ?></td>
    </tr>
    <?php endwhile; ?>
</table>

</body>
</html>
