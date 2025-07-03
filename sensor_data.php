<?php
session_start();
include 'db.php';

$columns = ['reading_time', 'temperature', 'humidity', 'wind_speed', 'air_quality'];

$limit = $_POST['length'] ?? 10;
$offset = $_POST['start'] ?? 0;
$orderColumnIndex = $_POST['order'][0]['column'] ?? 0;
$orderDirection = $_POST['order'][0]['dir'] ?? 'desc';

$orderColumn = $columns[$orderColumnIndex];

$startDate = $_POST['start_date'] ?? '';
$endDate = $_POST['end_date'] ?? '';

$where = "";
$params = [];
$types = "";

if (!empty($startDate) && !empty($endDate)) {
    $where = "WHERE reading_time BETWEEN ? AND ?";
    $params[] = $startDate . " 00:00:00";
    $params[] = $endDate . " 23:59:59";
    $types .= "ss";
} elseif (!empty($startDate)) {
    $where = "WHERE reading_time >= ?";
    $params[] = $startDate . " 00:00:00";
    $types .= "s";
} elseif (!empty($endDate)) {
    $where = "WHERE reading_time <= ?";
    $params[] = $endDate . " 23:59:59";
    $types .= "s";
}

// Total data (tanpa filter)
$totalQuery = "SELECT COUNT(*) as total FROM sensor_readings";
$totalResult = $conn->query($totalQuery);
$totalData = $totalResult->fetch_assoc()['total'];

// Total data (dengan filter)
$filteredQuery = "SELECT COUNT(*) as total FROM sensor_readings $where";
$filteredStmt = $conn->prepare($filteredQuery);
if (!empty($types)) {
    $filteredStmt->bind_param($types, ...$params);
}
$filteredStmt->execute();
$filteredResult = $filteredStmt->get_result();
$filteredData = $filteredResult->fetch_assoc()['total'];
$filteredStmt->close();

// Ambil data
$dataQuery = "SELECT reading_time, temperature, humidity, wind_speed, air_quality
              FROM sensor_readings
              $where
              ORDER BY $orderColumn $orderDirection
              LIMIT ? OFFSET ?";

$params[] = (int)$limit;
$params[] = (int)$offset;
$types .= "ii";

$dataStmt = $conn->prepare($dataQuery);
$dataStmt->bind_param($types, ...$params);
$dataStmt->execute();
$result = $dataStmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

$dataStmt->close();
$conn->close();

// Balikan JSON
echo json_encode([
    "draw" => intval($_POST['draw'] ?? 1),
    "recordsTotal" => $totalData,
    "recordsFiltered" => $filteredData,
    "data" => $data
]);