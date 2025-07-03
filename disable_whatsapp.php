<?php
session_start();
include 'db.php';

// Cek apakah user sudah login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Ambil API Key sebelum dihapus
$query = $conn->prepare("SELECT apikey FROM users WHERE id = ?");
$query->bind_param("i", $_SESSION['user_id']);
$query->execute();
$result = $query->get_result();
$apikey = $result->fetch_assoc()['apikey'] ?? null;

// Simpan API Key di session (untuk info pengguna jika perlu)
if ($apikey) {
    $_SESSION['last_apikey'] = $apikey;
}

// Hapus API Key dari database
$query = $conn->prepare("UPDATE users SET apikey = NULL WHERE id = ?");
$query->bind_param("i", $_SESSION['user_id']);

if ($query->execute()) {
    $_SESSION['warning_message'] = "Notifikasi WhatsApp berhasil dinonaktifkan.";
} else {
    $_SESSION['error_message'] = "Gagal menonaktifkan notifikasi WhatsApp.";
}

// Buat log untuk mencatat status eksekusi
$log_file = 'log_apikey.txt';
$log_message = date("Y-m-d H:i:s") . " - User ID: " . $_SESSION['user_id'] . " - ";

if (isset($_SESSION['warning_message'])) {
    $log_message .= "SUCCESS: " . $_SESSION['warning_message'];
} elseif (isset($_SESSION['error_message'])) {
    $log_message .= "ERROR: " . $_SESSION['error_message'];
} else {
    $log_message .= "UNKNOWN STATUS";
}

file_put_contents($log_file, $log_message . PHP_EOL, FILE_APPEND);

// Redirect ke halaman about.php
header("Location: about.php");
exit();
?>
