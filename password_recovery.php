<?php
include 'db.php';
require_once 'includes/auth_bootstrap.php';

function sendRecoveryEmail(string $email, string $body, string $subject): bool {
    $apiKey = getenv('BREVO_API_KEY');
    if (empty($apiKey)) {
        return false;
    }

    $data = [
        'sender' => [
            'name' => 'Monitoring Kenyamanan Termal',
            'email' => 'noreply@monitoring-kenyamanan-termal.my.id'
        ],
        'to' => [['email' => $email]],
        'subject' => $subject,
        'htmlContent' => nl2br($body),
        'textContent' => strip_tags($body)
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'api-key: ' . $apiKey,
        'Content-Type: application/json',
        'accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode >= 200 && $httpCode < 300;
}

$error = '';
$message = '';
$email = $_SESSION['recovery_email'] ?? '';

if (isset($_GET['restart'])) {
    unset($_SESSION['recovery_otp'], $_SESSION['recovery_email'], $_SESSION['recovery_expiry'], $_SESSION['recovery_attempts'], $_SESSION['recovery_verified'], $_SESSION['recovery_demo_otp']);
    header("Location: password_recovery.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST['send_otp'])) {
        $email = trim((string)($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Format alamat email tidak valid.";
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 1) {
                $otp = strval(random_int(100000, 999999));
                $_SESSION['recovery_otp'] = $otp;
                $_SESSION['recovery_email'] = $email;
                $_SESSION['recovery_expiry'] = time() + 120;
                $_SESSION['recovery_attempts'] = 0;

                if (sendRecoveryEmail($email, "Kode OTP reset password Anda adalah: <b>$otp</b>", "Reset Password OTP")) {
                    $message = "Kode OTP telah dikirim ke $email. Berlaku selama 2 menit.";
                    unset($_SESSION['recovery_demo_otp']);
                } else {
                    $message = "Mode lokal/demo (layanan email tidak dikonfigurasi). Gunakan kode OTP di bawah untuk melanjutkan.";
                    $_SESSION['recovery_demo_otp'] = $otp;
                }
            } else {
                $error = "Email tidak ditemukan dalam sistem kami.";
            }
            $stmt->close();
        }
    } elseif (isset($_POST['verify_otp'])) {
        $otpArr = isset($_POST['otp']) && is_array($_POST['otp']) ? $_POST['otp'] : [];
        $entered_otp = preg_replace('/\D/', '', implode('', $otpArr));

        if (empty($_SESSION['recovery_otp'])) {
            $error = "Sesi OTP tidak ditemukan. Silakan minta kode baru.";
        } elseif (time() > ($_SESSION['recovery_expiry'] ?? 0)) {
            $error = "Kode OTP telah kedaluwarsa. Silakan minta kode baru.";
            unset($_SESSION['recovery_otp'], $_SESSION['recovery_demo_otp']);
        } elseif (hash_equals((string)$_SESSION['recovery_otp'], $entered_otp)) {
            $_SESSION['recovery_verified'] = true;
            unset($_SESSION['recovery_otp'], $_SESSION['recovery_demo_otp']);
            $message = "OTP terverifikasi. Silakan buat password baru Anda.";
        } else {
            $_SESSION['recovery_attempts'] = ($_SESSION['recovery_attempts'] ?? 0) + 1;
            if ($_SESSION['recovery_attempts'] >= 3) {
                $error = "Terlalu banyak percobaan salah. Silakan minta kode OTP baru.";
                unset($_SESSION['recovery_otp'], $_SESSION['recovery_demo_otp']);
            } else {
                $rem = 3 - $_SESSION['recovery_attempts'];
                $error = "Kode OTP tidak sesuai. Sisa percobaan: $rem kali.";
            }
        }
    } elseif (isset($_POST['resend_otp'])) {
        if (!empty($_SESSION['recovery_email'])) {
            $email = $_SESSION['recovery_email'];
            $otp = strval(random_int(100000, 999999));
            $_SESSION['recovery_otp'] = $otp;
            $_SESSION['recovery_expiry'] = time() + 120;
            $_SESSION['recovery_attempts'] = 0;

            if (sendRecoveryEmail($email, "Kode OTP reset password baru Anda adalah: <b>$otp</b>", "Reset Password OTP")) {
                $message = "Kode OTP baru telah dikirim ke email Anda.";
                unset($_SESSION['recovery_demo_otp']);
            } else {
                $message = "Mode lokal/demo: kode OTP baru telah dibuat.";
                $_SESSION['recovery_demo_otp'] = $otp;
            }
        } else {
            $error = "Sesi email tidak ditemukan. Silakan mulai ulang.";
        }
    } elseif (isset($_POST['reset_password'])) {
        if (!empty($_SESSION['recovery_verified']) && !empty($_SESSION['recovery_email'])) {
            $new_password = (string)($_POST['new_password'] ?? '');
            $confirm_password = (string)($_POST['confirm_password'] ?? '');

            if (strlen($new_password) < 8) {
                $error = "Password baru minimal 8 karakter.";
            } elseif ($new_password !== $confirm_password) {
                $error = "Konfirmasi password tidak cocok.";
            } else {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $targetEmail = $_SESSION['recovery_email'];

                $stmt = $conn->prepare("UPDATE users SET password = ?, remember_token = NULL WHERE email = ?");
                $stmt->bind_param("ss", $hashed, $targetEmail);
                if ($stmt->execute()) {
                    $stmt->close();
                    sendRecoveryEmail($targetEmail, "Password akun Monitoring Kenyamanan Termal Anda telah berhasil diperbarui.", "Password Berhasil Diubah");
                    unset($_SESSION['recovery_otp'], $_SESSION['recovery_email'], $_SESSION['recovery_expiry'], $_SESSION['recovery_attempts'], $_SESSION['recovery_verified'], $_SESSION['recovery_demo_otp']);
                    header("Location: login.php?reset=success");
                    exit;
                }
                $stmt->close();
                $error = "Gagal memperbarui password. Silakan coba lagi.";
            }
        } else {
            $error = "Verifikasi OTP diperlukan terlebih dahulu.";
        }
    }
}

$step = 1;
if (!empty($_SESSION['recovery_verified'])) {
    $step = 3;
} elseif (!empty($_SESSION['recovery_otp'])) {
    $step = 2;
}
$demoOtp = $_SESSION['recovery_demo_otp'] ?? null;
$remainingSeconds = isset($_SESSION['recovery_expiry']) ? max(0, $_SESSION['recovery_expiry'] - time()) : 120;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — Monitoring Kenyamanan Termal</title>
    <link rel="icon" type="image/png" href="logo.png">
    <?php render_theme_init_script(); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
    <style>
        .step-pills { display: flex; gap: 8px; margin-bottom: 24px; }
        .step-pill { flex: 1; height: 4px; border-radius: 999px; background: var(--bg-surface-3); }
        .step-pill.is-done { background: var(--brand-primary); }
        .strength-track { height: 5px; border-radius: 999px; background: var(--bg-surface-3); overflow: hidden; margin-top: 8px; }
        .strength-fill { height: 100%; width: 0%; transition: width 0.25s ease, background-color 0.25s ease; }
    </style>
</head>
<body>
<div class="auth-page">
    <section class="auth-visual-panel">
        <div class="auth-brand">
            <img src="logo.png" alt="Logo Monitoring Kenyamanan Termal" class="auth-brand-logo">
            <div class="auth-brand-text">
                <span class="auth-brand-name">ThermoMonitor</span>
                <span class="auth-brand-sub">Pemulihan Akses Akun</span>
            </div>
        </div>
        <div class="auth-artwork-wrap">
            <img src="assets/svg/auth-illustration.svg" alt="Ilustrasi sensor kenyamanan termal ruangan" class="auth-artwork-img">
        </div>
        <div class="auth-visual-footer">
            <p class="auth-visual-caption">Reset password secara aman menggunakan kode OTP sekali pakai berbatas waktu.</p>
            <span class="status-pill-live"><span class="status-dot"></span>Pemulihan Aman</span>
        </div>
    </section>

    <main class="auth-form-panel">
        <div class="auth-form-card">
            <div class="step-pills" aria-label="Langkah pemulihan password">
                <div class="step-pill is-done"></div>
                <div class="step-pill <?= $step >= 2 ? 'is-done' : '' ?>"></div>
                <div class="step-pill <?= $step >= 3 ? 'is-done' : '' ?>"></div>
            </div>

            <h1 class="auth-heading">
                <?= $step === 1 ? 'Lupa Password?' : ($step === 2 ? 'Verifikasi Kode OTP' : 'Buat Password Baru') ?>
            </h1>
            <p class="auth-subheading">
                <?= $step === 1 ? 'Masukkan email terdaftar untuk menerima kode verifikasi.' : ($step === 2 ? 'Masukkan 6 digit kode OTP yang dikirim ke ' . htmlspecialchars($email) : 'Gunakan kombinasi minimal 8 karakter yang kuat.') ?>
            </p>

            <?php if (!empty($message)): ?>
                <div class="alert-banner is-success"><i class="fas fa-circle-check" style="margin-top:2px;"></i><div><?= htmlspecialchars($message) ?></div></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert-banner is-error"><i class="fas fa-circle-exclamation" style="margin-top:2px;"></i><div><?= htmlspecialchars($error) ?></div></div>
            <?php endif; ?>
            <?php if ($step === 2 && $demoOtp): ?>
                <div class="alert-banner is-warning">
                    <i class="fas fa-flask" style="margin-top:2px;"></i>
                    <div>Kode OTP Demo: <strong class="mono" style="font-size:16px;letter-spacing:0.12em;"><?= htmlspecialchars($demoOtp) ?></strong></div>
                </div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
                <form method="POST">
                    <div class="form-group">
                        <label for="email" class="form-label">Alamat Email</label>
                        <div class="input-control-wrap">
                            <span class="input-icon-left"><i class="fas fa-envelope"></i></span>
                            <input type="email" id="email" name="email" class="form-control-ds has-left-icon" placeholder="nama@email.com" value="<?= htmlspecialchars($email) ?>" required>
                        </div>
                    </div>
                    <button type="submit" name="send_otp" class="btn-ds btn-ds-primary" style="width: 100%;">
                        <span>Kirim Kode OTP</span>
                        <i class="fas fa-paper-plane" style="font-size: 13px;"></i>
                    </button>
                </form>
            <?php elseif ($step === 2): ?>
                <form method="POST" id="otpForm">
                    <div class="otp-grid">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                            <input type="text" name="otp[]" maxlength="1" inputmode="numeric" pattern="[0-9]" class="otp-box" required>
                        <?php endfor; ?>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;font-size:13px;">
                        <span id="otpTimer" style="color:var(--text-secondary);">Berlaku: <strong class="mono"><?= $remainingSeconds ?>d</strong></span>
                        <a href="password_recovery.php?restart=1" style="font-size:13px;">Ganti email</a>
                    </div>
                    <button type="submit" name="verify_otp" class="btn-ds btn-ds-primary" style="width: 100%;">Verifikasi OTP</button>
                </form>
                <form method="POST" style="margin-top: 12px; text-align: center;">
                    <button type="submit" name="resend_otp" class="btn-ds btn-ds-ghost btn-ds-sm">Kirim ulang kode OTP</button>
                </form>
            <?php else: ?>
                <form method="POST">
                    <div class="form-group">
                        <label for="newPassword" class="form-label">Password Baru</label>
                        <div class="input-control-wrap">
                            <span class="input-icon-left"><i class="fas fa-lock"></i></span>
                            <input type="password" id="newPassword" name="new_password" class="form-control-ds has-left-icon" placeholder="Minimal 8 karakter" required>
                        </div>
                        <div class="strength-track"><div class="strength-fill" id="strengthMeter"></div></div>
                    </div>
                    <div class="form-group">
                        <label for="confirmPassword" class="form-label">Konfirmasi Password Baru</label>
                        <div class="input-control-wrap">
                            <span class="input-icon-left"><i class="fas fa-key"></i></span>
                            <input type="password" id="confirmPassword" name="confirm_password" class="form-control-ds has-left-icon" placeholder="Ulangi password baru" required>
                        </div>
                    </div>
                    <button type="submit" name="reset_password" class="btn-ds btn-ds-primary" style="width: 100%;">Simpan Password Baru</button>
                </form>
            <?php endif; ?>

            <p style="text-align: center; margin-top: 24px; font-size: 13.5px; color: var(--text-secondary);">
                <a href="login.php"><i class="fas fa-arrow-left" style="margin-right: 5px;"></i>Kembali ke halaman masuk</a>
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
            if (e.key === 'Backspace' && !box.value && boxes[idx - 1]) boxes[idx - 1].focus();
        });
    });

    const timerEl = document.getElementById('otpTimer');
    if (timerEl) {
        let left = <?= (int)$remainingSeconds ?>;
        const iv = setInterval(() => {
            if (left <= 0) {
                clearInterval(iv);
                timerEl.innerHTML = '<span style="color:var(--status-critical);">Kode OTP kedaluwarsa</span>';
                return;
            }
            left--;
            timerEl.innerHTML = 'Berlaku: <strong class="mono">' + left + 'd</strong>';
        }, 1000);
    }

    const newPwd = document.getElementById('newPassword');
    const meter = document.getElementById('strengthMeter');
    if (newPwd && meter) {
        newPwd.addEventListener('input', function() {
            const p = this.value;
            let s = 0;
            if (p.length >= 8) s++;
            if (p.length >= 12) s++;
            if (/[A-Z]/.test(p)) s++;
            if (/[0-9]/.test(p)) s++;
            if (/[^A-Za-z0-9]/.test(p)) s++;
            const widths = ['0%', '25%', '45%', '65%', '85%', '100%'];
            const colors = ['#f43f5e', '#f43f5e', '#f59e0b', '#f59e0b', '#14b8a6', '#10b981'];
            meter.style.width = widths[s];
            meter.style.backgroundColor = colors[s];
        });
    }
})();
</script>
</body>
</html>
