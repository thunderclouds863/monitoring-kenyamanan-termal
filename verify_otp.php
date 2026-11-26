<?php
include 'db.php';
require_once 'includes/auth_bootstrap.php';
if (is_file(__DIR__ . '/vendor/composer/autoload_real.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error = '';
$success = '';

if (empty($_SESSION['email'])) {
    header("Location: register.php");
    exit;
}

$email = $_SESSION['email'];

if (!isset($_SESSION['otp_message'])) {
    $_SESSION['otp_message'] = 'Kode OTP telah dikirim ke email Anda. Berlaku selama 2 menit.';
}

function sendOtpEmail(string $email, string $body, string $subject): bool {
    $gmailPass = getenv('GMAIL_APP_PASSWORD');
    if (empty($gmailPass) || !class_exists(PHPMailer::class)) {
        return false;
    }
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'si.kenyamanantermal@gmail.com';
        $mail->Password = $gmailPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 10;

        $mail->setFrom('si.kenyamanantermal@gmail.com', 'Monitoring Kenyamanan Termal');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("OTP Mailer Error: " . $e->getMessage());
        return false;
    }
}

// AJAX Resend OTP
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['resend_otp'])) {
    header('Content-Type: application/json; charset=utf-8');
    $new_otp = strval(random_int(100000, 999999));
    $_SESSION['otp_expiry'] = time() + 120;
    $_SESSION['otp_attempts'] = 0;

    $stmt = $conn->prepare("UPDATE users SET otp = ? WHERE email = ? AND is_verified = 0");
    $stmt->bind_param("ss", $new_otp, $email);
    if ($stmt->execute()) {
        $stmt->close();
        $sent = sendOtpEmail($email, "Kode OTP baru Anda adalah: <b>$new_otp</b>", "Kode OTP Verifikasi Baru");
        if ($sent) {
            unset($_SESSION['demo_otp_preview']);
            echo json_encode(['success' => true, 'message' => 'Kode OTP baru telah dikirim ke email Anda.']);
        } else {
            $_SESSION['demo_otp_preview'] = $new_otp;
            echo json_encode(['success' => true, 'message' => 'Mode lokal/demo: kode OTP baru telah dibuat.', 'demo_otp' => $new_otp]);
        }
    } else {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Gagal memperbarui kode OTP.']);
    }
    exit;
}

// Verify OTP
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['verify_otp'])) {
    $otpArray = isset($_POST['otp']) && is_array($_POST['otp']) ? $_POST['otp'] : [];
    $entered_otp = preg_replace('/\D/', '', implode('', $otpArray));

    if (!isset($_SESSION['otp_attempts'])) {
        $_SESSION['otp_attempts'] = 0;
    }

    if (isset($_SESSION['otp_expiry']) && time() > $_SESSION['otp_expiry']) {
        $error = "Kode OTP telah kedaluwarsa. Silakan kirim ulang kode baru.";
    } else {
        $stmt = $conn->prepare("SELECT id, username, otp FROM users WHERE email = ? AND is_verified = 0 LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows === 1) {
            $user = $res->fetch_assoc();
            $stmt->close();

            if ($user['otp'] !== null && hash_equals((string)$user['otp'], $entered_otp)) {
                $upd = $conn->prepare("UPDATE users SET is_verified = 1, otp = NULL WHERE id = ?");
                $upd->bind_param("i", $user['id']);
                if ($upd->execute()) {
                    $upd->close();
                    unset($_SESSION['email'], $_SESSION['otp_attempts'], $_SESSION['otp_message'], $_SESSION['demo_otp_preview'], $_SESSION['otp_expiry']);
                    header("Location: login.php?verified=true");
                    exit;
                }
                $upd->close();
                $error = "Gagal memverifikasi akun. Silakan coba lagi.";
            } else {
                $_SESSION['otp_attempts']++;
                if ($_SESSION['otp_attempts'] >= 3) {
                    $del = $conn->prepare("DELETE FROM users WHERE email = ? AND is_verified = 0");
                    $del->bind_param("s", $email);
                    $del->execute();
                    $del->close();

                    unset($_SESSION['email'], $_SESSION['otp_attempts'], $_SESSION['otp_message'], $_SESSION['demo_otp_preview']);
                    $_SESSION['error'] = "Batas percobaan OTP terlampaui. Silakan daftar kembali.";
                    header("Location: register.php");
                    exit;
                } else {
                    $remaining = 3 - $_SESSION['otp_attempts'];
                    $error = "Kode OTP salah. Sisa percobaan: $remaining kali.";
                }
            }
        } else {
            $stmt->close();
            $error = "Akun tidak ditemukan atau sudah diverifikasi.";
        }
    }
}

$message = $_SESSION['otp_message'];
$demoOtp = $_SESSION['demo_otp_preview'] ?? null;
$remainingSeconds = isset($_SESSION['otp_expiry']) ? max(0, $_SESSION['otp_expiry'] - time()) : 120;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Akun — Monitoring Kenyamanan Termal</title>
    <link rel="icon" type="image/png" href="logo.png">
    <?php render_theme_init_script(); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
</head>
<body>
<div class="auth-page">
    <section class="auth-visual-panel">
        <div class="auth-brand">
            <img src="logo.png" alt="Logo Monitoring Kenyamanan Termal" class="auth-brand-logo">
            <div class="auth-brand-text">
                <span class="auth-brand-name">ThermoMonitor</span>
                <span class="auth-brand-sub">Verifikasi Keamanan Akun</span>
            </div>
        </div>
        <div class="auth-artwork-wrap">
            <img src="assets/svg/auth-illustration.svg" alt="Ilustrasi sensor kenyamanan termal ruangan" class="auth-artwork-img">
        </div>
        <div class="auth-visual-footer">
            <p class="auth-visual-caption">Verifikasi email memastikan notifikasi peringatan sensor terkirim ke alamat yang tepat.</p>
            <span class="status-pill-live"><span class="status-dot"></span>Verifikasi OTP</span>
        </div>
    </section>

    <main class="auth-form-panel">
        <div class="auth-form-card">
            <h1 class="auth-heading">Verifikasi Kode OTP</h1>
            <p class="auth-subheading" id="otpSentMessage"><?= htmlspecialchars($message) ?></p>

            <?php if ($demoOtp): ?>
                <div class="alert-banner is-warning" id="demoOtpBox">
                    <i class="fas fa-flask" style="margin-top: 2px;"></i>
                    <div>Kode OTP Demo: <strong class="mono" id="demoOtpCode" style="font-size: 16px; letter-spacing: 0.12em;"><?= htmlspecialchars($demoOtp) ?></strong></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert-banner is-error" role="alert">
                    <i class="fas fa-circle-exclamation" style="margin-top: 2px;"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" id="otpForm">
                <label class="form-label">Masukkan 6 digit kode untuk <strong><?= htmlspecialchars($email) ?></strong></label>
                <div class="otp-grid">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <input type="text" name="otp[]" maxlength="1" inputmode="numeric" pattern="[0-9]" class="otp-box" autocomplete="one-time-code" required>
                    <?php endfor; ?>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; font-size: 13px;">
                    <span style="color: var(--text-secondary);" id="otpTimer">Berlaku: <strong class="mono"><?= $remainingSeconds ?>d</strong></span>
                    <button type="button" id="resendOtpButton" style="background: none; border: none; color: var(--brand-primary); font-weight: 600; cursor: pointer; font-size: 13px;">
                        Kirim ulang OTP
                    </button>
                </div>

                <button type="submit" name="verify_otp" class="btn-ds btn-ds-primary" style="width: 100%;">
                    <i class="fas fa-shield-check"></i>
                    <span>Verifikasi Akun</span>
                </button>
            </form>

            <p style="text-align: center; margin-top: 22px; font-size: 13.5px; color: var(--text-secondary);">
                <a href="register.php"><i class="fas fa-arrow-left" style="margin-right: 5px;"></i>Kembali ke pendaftaran</a>
            </p>
        </div>
    </main>
</div>

<script>
(function() {
    const boxes = Array.from(document.querySelectorAll('.otp-box'));
    boxes.forEach((box, idx) => {
        box.addEventListener('input', () => {
            box.value = box.value.replace(/\D/g, '').slice(0, 1);
            if (box.value && boxes[idx + 1]) boxes[idx + 1].focus();
        });
        box.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !box.value && boxes[idx - 1]) {
                boxes[idx - 1].focus();
            }
        });
        box.addEventListener('paste', (e) => {
            e.preventDefault();
            const digits = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
            digits.split('').forEach((d, i) => { if (boxes[i]) boxes[i].value = d; });
            if (boxes[Math.min(digits.length, 5)]) boxes[Math.min(digits.length, 5)].focus();
        });
    });

    let timeLeft = <?= (int)$remainingSeconds ?>;
    const timerEl = document.getElementById('otpTimer');
    const tick = setInterval(() => {
        if (timeLeft <= 0) {
            clearInterval(tick);
            timerEl.innerHTML = '<span style="color: var(--status-critical);">Kode OTP kedaluwarsa</span>';
            return;
        }
        timeLeft--;
        timerEl.innerHTML = 'Berlaku: <strong class="mono">' + timeLeft + 'd</strong>';
    }, 1000);

    const resendBtn = document.getElementById('resendOtpButton');
    if (resendBtn) {
        resendBtn.addEventListener('click', function() {
            resendBtn.disabled = true;
            resendBtn.textContent = 'Mengirim...';
            fetch('verify_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'resend_otp=true'
            })
            .then(r => r.json())
            .then(data => {
                resendBtn.disabled = false;
                resendBtn.textContent = 'Kirim ulang OTP';
                if (data.success) {
                    document.getElementById('otpSentMessage').textContent = data.message;
                    timeLeft = 120;
                    if (data.demo_otp) {
                        const codeEl = document.getElementById('demoOtpCode');
                        if (codeEl) codeEl.textContent = data.demo_otp;
                    }
                }
            })
            .catch(() => {
                resendBtn.disabled = false;
                resendBtn.textContent = 'Kirim ulang OTP';
            });
        });
    }
})();
</script>
</body>
</html>
