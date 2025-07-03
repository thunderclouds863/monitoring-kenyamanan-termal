<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Konfigurasi Database
$host = "localhost";
$user = "figflzel_user";
$pass = "Y]N?GYJM~uT9";
$dbname = "figflzel_data";

// Create connection
$conn = new mysqli($host, $user, $pass, $dbname);
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
$conn->options(MYSQLI_OPT_READ_TIMEOUT, 5);

// Check connection
if ($conn->connect_error) {
    die(json_encode([
        "status" => "error",
        "message" => "Connection failed: " . $conn->connect_error
    ]));
}

// Validate input GET
$params = ['temperature', 'humidity', 'wind_speed', 'air_quality'];
foreach ($params as $param) {
    if (!isset($_GET[$param]) || !is_numeric($_GET[$param])) {
        die(json_encode([
            "status" => "error",
            "message" => "Missing or invalid parameter: $param"
        ]));
    }
}

$temperature = floatval($_GET['temperature']);
$humidity = floatval($_GET['humidity']);
$wind_speed = floatval($_GET['wind_speed']);
$air_quality = floatval($_GET['air_quality']);

// Validate ranges
if ($temperature < -50 || $temperature > 100) {
    die(json_encode(["error" => "Invalid temperature range"]));
}

// Use prepared statement
$sql = "INSERT INTO sensor_readings (temperature, humidity, wind_speed, air_quality) VALUES (?, ?, ?, ?)";
$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("dddd", $temperature, $humidity, $wind_speed, $air_quality);
    
    $response = [];
    if ($stmt->execute()) {
        $response = [
            "status" => "success",
            "message" => "Data recorded",
            "data" => [
                "id" => $stmt->insert_id,
                "temperature" => $temperature,
                "humidity" => $humidity,
                "wind_speed" => $wind_speed,
                "air_quality" => $air_quality
            ]
        ];
    } else {
        $response = [
            "status" => "error",
            "message" => $stmt->error
        ];
    }
    
    // Log the transaction
    file_put_contents('data_log.txt', 
        date('Y-m-d H:i:s') . " - " . 
        json_encode($response) . "\n", 
        FILE_APPEND
    );
    
    echo json_encode($response);
    $stmt->close();
} else {
    echo json_encode([
        "status" => "error",
        "message" => $conn->error
    ]);
}

$conn->close();
?>