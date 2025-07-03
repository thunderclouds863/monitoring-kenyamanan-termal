<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
require 'db.php';

if (isset($_GET['email'])) {
    $email = $_GET['email'];

    $sql = "SELECT * FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if ($user['is_verified'] == 0) {
            // Generate token and expiry time
            $token = bin2hex(random_bytes(16)); // Generate a random token
            $expiry = date("Y-m-d H:i:s", strtotime("+2 minutes")); // Set expiry time to 2 minutes from now

            // Update token and expiry in the database
            $sql_update = "UPDATE users SET verification_token = ?, token_expiry = ? WHERE email = ?";
            $stmt_update = $conn->prepare($sql_update);
            $stmt = $conn->prepare($sql);
            $stmt_update->bind_param("sss", $token, $expiry, $email);
            $stmt_update->execute();

            // Generate verification link
            $verificationLink = "monitoring-kenyamanan-termal.my.id/change_account.php?token=" . urlencode($token);

            $mail = new PHPMailer(true);

            try {
    $apiKey = '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';

    $data = [
        'sender' => [
            'name' => 'Sistem Monitoring',
            'email' => 'noreply@monitoring-kenyamanan-termal.my.id'
        ],
        'to' => [
            ['email' => $email]
        ],
        'subject' => 'Verifikasi Email Baru Anda',
        'htmlContent' => "<p>Klik link berikut untuk memverifikasi email baru Anda (berlaku selama 2 menit):</p><p><a href='$verificationLink'>$verificationLink</a></p>",
        'textContent' => "Klik link berikut untuk verifikasi email baru Anda (berlaku selama 2 menit): $verificationLink"
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'api-key: ' . $apiKey,
        'Content-Type: application/json',
        'accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception("cURL Error: $error");
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        // Redirect ke settings.php dengan pesan sukses
        header("Location: settings.php?verification_sent=true");
        exit;
    } else {
        throw new Exception("HTTP $httpCode: $response");
    }
} catch (Exception $e) {
    error_log("Gagal kirim email verifikasi email baru: " . $e->getMessage());
    header("Location: settings.php?error=email_failed");
    exit;
}
 catch (Exception $e) {
                echo "Gagal mengirim email. Error: {$mail->ErrorInfo}";
            }
        } else {
            echo "Akun sudah diverifikasi.";
        }
    } else {
        echo "Email tidak ditemukan.";
    }
} else {
    echo "Email tidak valid.";
}
?>