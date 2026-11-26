<?php
header('Content-Type: application/json; charset=utf-8');

include 'db.php';
require_once 'includes/auth_bootstrap.php';

restore_session_from_cookie($conn);
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'draw' => intval($_POST['draw'] ?? 1),
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'Unauthorized',
    ]);
    exit;
}

$columns = ['reading_time', 'temperature', 'humidity', 'wind_speed', 'air_quality'];

$limit = isset($_POST['length']) ? (int)$_POST['length'] : 10;
$offset = isset($_POST['start']) ? max(0, (int)$_POST['start']) : 0;

$orderColumnIndex = isset($_POST['order'][0]['column']) ? (int)$_POST['order'][0]['column'] : 0;
if (!isset($columns[$orderColumnIndex])) {
    $orderColumnIndex = 0;
}
$orderColumn = $columns[$orderColumnIndex];

$rawDir = strtolower((string)($_POST['order'][0]['dir'] ?? 'desc'));
$orderDirection = ($rawDir === 'asc') ? 'ASC' : 'DESC';

$startDate = trim((string)($_POST['start_date'] ?? ''));
$endDate = trim((string)($_POST['end_date'] ?? ''));
$searchValue = trim((string)($_POST['search']['value'] ?? ''));

$conditions = [];
$params = [];
$types = "";

if ($startDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $conditions[] = "reading_time >= ?";
    $params[] = $startDate . " 00:00:00";
    $types .= "s";
}
if ($endDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $conditions[] = "reading_time <= ?";
    $params[] = $endDate . " 23:59:59";
    $types .= "s";
}
if ($searchValue !== '') {
    $conditions[] = "(CAST(reading_time AS CHAR) LIKE ? OR CAST(temperature AS CHAR) LIKE ? OR CAST(humidity AS CHAR) LIKE ? OR CAST(wind_speed AS CHAR) LIKE ? OR CAST(air_quality AS CHAR) LIKE ?)";
    $like = '%' . $searchValue . '%';
    for ($i = 0; $i < 5; $i++) {
        $params[] = $like;
        $types .= "s";
    }
}

$where = !empty($conditions) ? ("WHERE " . implode(" AND ", $conditions)) : "";

// Total records
$totalResult = $conn->query("SELECT COUNT(*) as total FROM sensor_readings");
$totalData = $totalResult ? (int)$totalResult->fetch_assoc()['total'] : 0;

// Filtered count + period summary stats
$filteredQuery = "SELECT COUNT(*) as total,
                         ROUND(AVG(temperature), 1) as avg_temp,
                         ROUND(AVG(humidity), 1) as avg_humidity,
                         ROUND(MAX(air_quality), 0) as max_co2,
                         ROUND(AVG(wind_speed), 1) as avg_wind
                  FROM sensor_readings $where";
$filteredStmt = $conn->prepare($filteredQuery);
if ($types !== '') {
    $filteredStmt->bind_param($types, ...$params);
}
$filteredStmt->execute();
$filteredRow = $filteredStmt->get_result()->fetch_assoc();
$filteredData = (int)($filteredRow['total'] ?? 0);
$stats = [
    'avg_temp' => $filteredRow['avg_temp'],
    'avg_humidity' => $filteredRow['avg_humidity'],
    'max_co2' => $filteredRow['max_co2'],
    'avg_wind' => $filteredRow['avg_wind'],
];
$filteredStmt->close();

// Fetch page data
$dataParams = $params;
$dataTypes = $types;
$limitClause = "";
if ($limit > 0) {
    $limitClause = "LIMIT ? OFFSET ?";
    $dataParams[] = $limit;
    $dataParams[] = $offset;
    $dataTypes .= "ii";
}

$dataQuery = "SELECT reading_time, temperature, humidity, wind_speed, air_quality
              FROM sensor_readings
              $where
              ORDER BY $orderColumn $orderDirection
              $limitClause";

$dataStmt = $conn->prepare($dataQuery);
if ($dataTypes !== '') {
    $dataStmt->bind_param($dataTypes, ...$dataParams);
}
$dataStmt->execute();
$result = $dataStmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        'reading_time' => $row['reading_time'],
        'temperature' => round((float)$row['temperature'], 1),
        'humidity' => round((float)$row['humidity'], 1),
        'wind_speed' => round((float)$row['wind_speed'], 1),
        'air_quality' => round((float)$row['air_quality'], 0),
    ];
}

$dataStmt->close();
$conn->close();

echo json_encode([
    "draw" => intval($_POST['draw'] ?? 1),
    "recordsTotal" => $totalData,
    "recordsFiltered" => $filteredData,
    "stats" => $stats,
    "data" => $data,
]);
