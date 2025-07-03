<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
include 'db.php';

if (isset($_GET['email'])) {
    $email = $_GET['email'];

    $sql = "SELECT * FROM users WHERE email='$email'";
    $result = $conn->query($sql);

    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();

        if ($user['is_verified'] == 0) {
            $verificationLink = "http://localhost/monitoring-kenyamanan-termal/verify_account.php?email=" . urlencode($email);

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
        'subject' => 'Verifikasi Akun Anda',
        'htmlContent' => "<p>Klik link berikut untuk memverifikasi akun Anda:</p><p><a href='$verificationLink'>$verificationLink</a></p>",
        'textContent' => "Klik link berikut untuk memverifikasi akun Anda: $verificationLink"
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
        // Redirect kembali ke login.php dengan pesan sukses
        header("Location: login.php?verification_sent=true&email=" . urlencode($email));
        exit;
    } else {
        throw new Exception("HTTP $httpCode: $response");
    }
} catch (Exception $e) {
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