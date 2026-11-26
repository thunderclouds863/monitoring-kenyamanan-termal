<?php
header('Content-Type: application/json; charset=utf-8');
require 'db.php';
require_once 'includes/auth_bootstrap.php';

restore_session_from_cookie($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'User tidak terautentikasi']);
        exit;
    }

    $userId = (int)$_SESSION['user_id'];

    $stmt = $conn->prepare("SELECT profile_pic FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user && !empty($user['profile_pic'])) {
        $pathRelatif = $user['profile_pic'];
        $uploadsRoot = realpath(__DIR__ . '/uploads');
        $pathServer = realpath(__DIR__ . '/' . $pathRelatif);

        // Safely delete only if inside uploads/
        if ($uploadsRoot && $pathServer && str_starts_with($pathServer, $uploadsRoot . DIRECTORY_SEPARATOR) && is_file($pathServer)) {
            @unlink($pathServer);
        }

        $stmtUpdate = $conn->prepare("UPDATE users SET profile_pic = NULL WHERE id = ?");
        $stmtUpdate->bind_param("i", $userId);
        $stmtUpdate->execute();
        $stmtUpdate->close();

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Foto profil tidak ditemukan']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Permintaan tidak valid']);
