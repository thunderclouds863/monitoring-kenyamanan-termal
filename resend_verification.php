<?php
include 'db.php';
require_once 'includes/auth_bootstrap.php';

if (!empty($_GET['email'])) {
    $email = trim((string)$_GET['email']);

    $stmt = $conn->prepare("SELECT id, is_verified FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $stmt->close();

        if ((int)$user['is_verified'] === 0) {
            $otp = strval(random_int(100000, 999999));
            $upd = $conn->prepare("UPDATE users SET otp = ? WHERE id = ?");
            $upd->bind_param("si", $otp, $user['id']);
            $upd->execute();
            $upd->close();

            $_SESSION['email'] = $email;
            $_SESSION['otp_expiry'] = time() + 120;
            $_SESSION['otp_attempts'] = 0;

            $apiKey = getenv('BREVO_API_KEY');
            if (!empty($apiKey)) {
                $data = [
                    'sender' => [
                        'name' => 'Monitoring Kenyamanan Termal',
                        'email' => 'noreply@monitoring-kenyamanan-termal.my.id'
                    ],
                    'to' => [['email' => $email]],
                    'subject' => 'Kode OTP Verifikasi Akun',
                    'htmlContent' => "<p>Kode OTP verifikasi akun Anda adalah: <strong>$otp</strong> (berlaku 2 menit).</p>",
                    'textContent' => "Kode OTP verifikasi akun Anda adalah: $otp (berlaku 2 menit)."
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
                curl_exec($ch);
                curl_close($ch);
                $_SESSION['otp_message'] = 'Kode OTP verifikasi telah dikirim ulang ke email Anda.';
            } else {
                $_SESSION['otp_message'] = 'Mode lokal/demo (SMTP tidak dikonfigurasi). Gunakan kode OTP berikut untuk verifikasi.';
                $_SESSION['demo_otp_preview'] = $otp;
            }

            header("Location: verify_otp.php");
            exit;
        } else {
            header("Location: login.php?verified=true");
            exit;
        }
    }
    if ($stmt) {
        $stmt->close();
    }
}

header("Location: login.php");
exit;
