<?php
session_start();
require 'db.php'; 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'User tidak terautentikasi']);
        exit;
    }

    $userId = $_SESSION['user_id'];

    // Ambil data foto profil lama dari DB
    $stmt = $conn->prepare("SELECT profile_pic FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && !empty($user['profile_pic'])) {
        $pathRelatif = $user['profile_pic']; // Contoh: uploads/namafile.jpg
        $pathServer = __DIR__ . '/' . $pathRelatif;

        // Hapus file dari server (jika ada dan bukan default)
        if (file_exists($pathServer)) {
            unlink($pathServer);
        }

        // Kosongkan field profile_pic di database
        $stmtUpdate = $conn->prepare("UPDATE users SET profile_pic = NULL WHERE id = ?");
        $stmtUpdate->bind_param("i", $userId);
        $stmtUpdate->execute();

        echo json_encode(['success' => true]);
        exit;
    } else {
        // Kalau nggak ada foto profil atau sudah kosong
        echo json_encode(['success' => false, 'message' => 'Foto profil tidak ditemukan']);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Permintaan tidak valid']);
