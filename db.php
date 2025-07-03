<?php
$host = "localhost";
$user = "figflzel_user";
$pass = getenv('DB_PASS');
$dbname = "figflzel_data";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
