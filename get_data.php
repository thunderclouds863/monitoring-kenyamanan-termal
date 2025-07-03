<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php';

$timeframe = $_GET['timeframe'] ?? 'latest';

if ($timeframe === 'realtime') {
    $query = "SELECT * FROM sensor_readings ORDER BY reading_time DESC LIMIT 1";
} elseif ($timeframe === 'latest') {
    $query = "SELECT * FROM sensor_readings WHERE reading_time >= NOW() - INTERVAL 1 HOUR ORDER BY reading_time DESC";
} elseif ($timeframe === 'daily') {
    $query = "SELECT * FROM sensor_readings WHERE reading_time >= NOW() - INTERVAL 1 DAY ORDER BY reading_time DESC";
} elseif ($timeframe === 'weekly') {
    $query = "SELECT * FROM sensor_readings WHERE reading_time >= NOW() - INTERVAL 1 WEEK ORDER BY reading_time DESC";
} elseif ($timeframe === 'monthly') {
    $query = "SELECT * FROM sensor_readings WHERE reading_time >= NOW() - INTERVAL 1 MONTH ORDER BY reading_time DESC";
}

$result = $conn->query($query);
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

error_log("Data yang dikirim ke frontend: " . json_encode($data)); // Tambahkan log ini
echo json_encode(['data' => $data]);
?>
