<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? null;
$apikey = $_POST['apikey'] ?? null;

if ($action === 'activate' && !empty($apikey)) {
    // Perbarui API Key di database
    $query = $conn->prepare("UPDATE users SET apikey = ? WHERE id = ?");
    $query->bind_param("si", $apikey, $user_id);
    if ($query->execute()) {
        $_SESSION['success_message'] = "Notifikasi WhatsApp berhasil diaktifkan.";
    } else {
        $_SESSION['error_message'] = "Gagal mengaktifkan notifikasi WhatsApp.";
    }
} elseif ($action === 'deactivate') {
    // Simpan API Key ke sesi sebelum menghapusnya
    $query = $conn->prepare("SELECT apikey FROM users WHERE id = ?");
    $query->bind_param("i", $user_id);
    $query->execute();
    $result = $query->get_result();
    $row = $result->fetch_assoc();
    $_SESSION['last_apikey'] = $row['apikey'] ?? null;

    // Hapus API Key dari database
    $query = $conn->prepare("UPDATE users SET apikey = NULL WHERE id = ?");
    $query->bind_param("i", $user_id);
    if ($query->execute()) {
        $_SESSION['warning_message'] = "Notifikasi WhatsApp berhasil dinonaktifkan.";
    } else {
        $_SESSION['error_message'] = "Gagal menonaktifkan notifikasi WhatsApp.";
    }
} else {
    $_SESSION['error_message'] = "Permintaan tidak valid. Cek lagi API Key yang anda masukkan!";
}

header("Location: about.php");
exit();
?>