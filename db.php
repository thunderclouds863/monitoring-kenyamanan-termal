<?php
$host = "localhost";
$user = "figflzel_user";
$pass = "Y]N?GYJM~uT9";
$dbname = "figflzel_data";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
