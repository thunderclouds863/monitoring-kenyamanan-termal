<?php
include 'db.php';
require_once 'includes/auth_bootstrap.php';

if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
    }
}

session_unset();
session_destroy();

setcookie('remember_token', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);

header("Location: login.php");
exit();
