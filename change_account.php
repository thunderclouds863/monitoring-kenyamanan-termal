<?php
require 'db.php';

if (isset($_GET['token'])) {
    $token = $_GET['token'];

    // Cari token di database
    $sql = "SELECT * FROM users WHERE verification_token = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // Cek apakah token belum kedaluwarsa
        if (strtotime($user['token_expiry']) > time()) {
            if ($user['is_verified'] == 1) {
                // Akun sudah diverifikasi
                header("Location: settings.php?already_verified=true");
                exit;
            }

            // Verifikasi akun
            $sql_update = "UPDATE users SET is_verified = 1, verification_token = NULL, token_expiry = NULL WHERE id = ?";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("i", $user['id']);
            $stmt_update->execute();

            header("Location: settings.php?verification_success=true");
            exit;
        } else {
            // Token kadaluarsa
            header("Location: settings.php?verification_expired=true");
            exit;
        }
    } else {
        // Cek apakah token sudah digunakan dan dihapus, tapi user sudah diverifikasi
        // Ini untuk kasus ketika user klik link ulang tapi tokennya sudah dihapus
        $sql_check_verified = "SELECT * FROM users WHERE is_verified = 1 AND verification_token IS NULL";
        $result_check = $conn->query($sql_check_verified);

        if ($result_check && $result_check->num_rows > 0) {
            header("Location: settings.php?already_verified=true");
            exit;
        }

        // Token benar-benar tidak valid
        header("Location: settings.php?verification_invalid=true");
        exit;
    }
} else {
    // Token tidak disertakan
    header("Location: settings.php?verification_invalid=true");
    exit;
}
?>
