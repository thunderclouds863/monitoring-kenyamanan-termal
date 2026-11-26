<?php
header('Content-Type: application/json; charset=utf-8');

include 'db.php';
require_once 'includes/auth_bootstrap.php';

restore_session_from_cookie($conn);
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'data' => []]);
    exit;
}

$timeframe = $_GET['timeframe'] ?? 'latest';

switch ($timeframe) {
    case 'realtime':
        $query = "SELECT id, temperature, humidity, wind_speed, air_quality, reading_time FROM sensor_readings ORDER BY reading_time DESC LIMIT 1";
        break;
    case 'daily':
        $query = "SELECT id, temperature, humidity, wind_speed, air_quality, reading_time FROM sensor_readings WHERE reading_time >= NOW() - INTERVAL 1 DAY ORDER BY reading_time DESC";
        break;
    case 'weekly':
        $query = "SELECT id, temperature, humidity, wind_speed, air_quality, reading_time FROM sensor_readings WHERE reading_time >= NOW() - INTERVAL 1 WEEK ORDER BY reading_time DESC";
        break;
    case 'monthly':
        $query = "SELECT id, temperature, humidity, wind_speed, air_quality, reading_time FROM sensor_readings WHERE reading_time >= NOW() - INTERVAL 1 MONTH ORDER BY reading_time DESC";
        break;
    case 'yearly':
        $query = "SELECT id, temperature, humidity, wind_speed, air_quality, reading_time FROM sensor_readings WHERE reading_time >= NOW() - INTERVAL 1 YEAR ORDER BY reading_time DESC LIMIT 2000";
        break;
    case 'latest':
    default:
        $query = "SELECT id, temperature, humidity, wind_speed, air_quality, reading_time FROM sensor_readings WHERE reading_time >= NOW() - INTERVAL 1 HOUR ORDER BY reading_time DESC";
        break;
}

$result = $conn->query($query);
$data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'id' => (int)$row['id'],
            'temperature' => round((float)$row['temperature'], 1),
            'humidity' => round((float)$row['humidity'], 1),
            'wind_speed' => round((float)$row['wind_speed'], 1),
            'air_quality' => round((float)$row['air_quality'], 0),
            'reading_time' => $row['reading_time'],
        ];
    }
}

echo json_encode(['data' => $data]);
