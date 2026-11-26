<?php
include 'db.php';
require_once 'includes/auth_bootstrap.php';
if (is_file(__DIR__ . '/vendor/composer/autoload_real.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Handle AJAX requests for email and phone uniqueness check (prepared statements)
if (isset($_POST['action']) && $_POST['action'] === 'check_email_phone') {
    header('Content-Type: application/json; charset=utf-8');
    $response = ['email_exists' => false, 'phone_exists' => false];

    if (!empty($_POST['email'])) {
        $email = trim((string)$_POST['email']);
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $response['email_exists'] = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    }

    if (!empty($_POST['phone'])) {
        $phone = trim((string)$_POST['phone']);
        $stmt = $conn->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $response['phone_exists'] = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    }

    echo json_encode($response);
    exit;
}

$errors = [];
$old = [
    'username' => '',
    'email' => '',
    'phone' => '',
];

if (!empty($_SESSION['error'])) {
    $errors['general'] = $_SESSION['error'];
    unset($_SESSION['error']);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    $old = ['username' => $username, 'email' => $email, 'phone' => $phone];

    if ($username === '' || strlen($username) < 3) {
        $errors['username'] = "Username minimal 3 karakter.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors['username'] = "Username sudah digunakan.";
        }
        $stmt->close();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "Format email tidak valid.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors['email'] = "Email sudah terdaftar.";
        }
        $stmt->close();
    }

    // Valid Indonesian international format: 62 followed by 9-13 digits (no +)
    if (!preg_match('/^62[0-9]{9,13}$/', $phone)) {
        $errors['phone'] = "Nomor telepon harus diawali 62 tanpa '+' (contoh: 628123456789).";
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors['phone'] = "Nomor telepon sudah terdaftar.";
        }
        $stmt->close();
    }

    if (strlen($password) < 8) {
        $errors['password'] = "Password minimal 8 karakter.";
    }
    if ($password !== $confirm_password) {
        $errors['confirm_password'] = "Konfirmasi password tidak cocok.";
    }

    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $otp = strval(random_int(100000, 999999));

        $stmt = $conn->prepare("INSERT INTO users (username, email, phone, password, otp, is_verified) VALUES (?, ?, ?, ?, ?, 0)");
        $stmt->bind_param("sssss", $username, $email, $phone, $hashed_password, $otp);

        if ($stmt->execute()) {
            $stmt->close();
            $_SESSION['email'] = $email;
            $_SESSION['otp_expiry'] = time() + 120;
            $_SESSION['otp_attempts'] = 0;

            $smtpPass = getenv('SMTP_PASSWORD');
            $brevoKey = getenv('BREVO_API_KEY');
            $sent = false;

            if (!empty($smtpPass)) {
                $sent = sendRegistrationEmail($email, "Kode OTP verifikasi akun Anda adalah: <b>$otp</b><br>Kode berlaku selama 2 menit.", "Verifikasi Akun — Monitoring Termal");
            } elseif (!empty($brevoKey)) {
                $sent = sendBrevoEmail($email, "Kode OTP verifikasi akun Anda adalah: <b>$otp</b><br>Kode berlaku selama 2 menit.", "Verifikasi Akun — Monitoring Termal", $brevoKey);
            }

            if ($sent) {
                $_SESSION['otp_message'] = "Kode OTP telah dikirim ke $email. Berlaku selama 2 menit.";
                unset($_SESSION['demo_otp_preview']);
            } else {
                // Local/demo environment without SMTP configured
                $_SESSION['otp_message'] = "Mode lokal/demo (layanan email tidak dikonfigurasi). Gunakan kode OTP berikut untuk menyelesaikan verifikasi.";
                $_SESSION['demo_otp_preview'] = $otp;
            }

            header("Location: verify_otp.php");
            exit;
        } else {
            $errors['general'] = "Gagal mendaftarkan akun. Silakan coba lagi.";
            $stmt->close();
        }
    }
}

function sendRegistrationEmail(string $email, string $body, string $subject): bool {
    if (!class_exists(PHPMailer::class)) {
        return false;
    }
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'mail.monitoring-kenyamanan-termal.my.id';
        $mail->SMTPAuth = true;
        $mail->Username = 'noreply@monitoring-kenyamanan-termal.my.id';
        $mail->Password = getenv('SMTP_PASSWORD');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 10;

        $mail->setFrom('noreply@monitoring-kenyamanan-termal.my.id', 'Monitoring Kenyamanan Termal');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $e->getMessage());
        return false;
    }
}

function sendBrevoEmail(string $email, string $body, string $subject, string $apiKey): bool {
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'api-key: ' . $apiKey,
        'Content-Type: application/json',
        'accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'sender' => ['name' => 'Monitoring Kenyamanan Termal', 'email' => 'noreply@monitoring-kenyamanan-termal.my.id'],
        'to' => [['email' => $email]],
        'subject' => $subject,
        'htmlContent' => $body,
        'textContent' => strip_tags($body),
    ]));
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Akun — Monitoring Kenyamanan Termal</title>
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
                <span class="auth-brand-sub">Monitoring Kenyamanan Termal</span>
            </div>
        </div>

        <div class="auth-artwork-wrap">
            <img src="assets/svg/auth-illustration.svg" alt="Ilustrasi sensor kenyamanan termal ruangan" class="auth-artwork-img">
        </div>

        <div class="auth-visual-footer">
            <p class="auth-visual-caption">
                Konfigurasi ambang batas personal dan terima peringatan saat kondisi ruangan tidak nyaman.
            </p>
            <span class="status-pill-live">
                <span class="status-dot"></span>
                Telemetri Aktif
            </span>
        </div>
    </section>

    <main class="auth-form-panel">
        <button type="button" class="btn-icon-only auth-theme-toggle" id="authThemeToggle" aria-label="Ganti tema">
            <i class="fas fa-sun" id="authThemeIcon"></i>
        </button>

        <div class="auth-form-card">
            <h1 class="auth-heading">Buat Akun Baru</h1>
            <p class="auth-subheading">Daftarkan akun untuk memantau dan mengatur preferensi sensor Anda.</p>

            <?php if (!empty($errors['general'])): ?>
                <div class="alert-banner is-error" role="alert">
                    <i class="fas fa-circle-exclamation" style="margin-top: 2px;"></i>
                    <div><?= htmlspecialchars($errors['general']) ?></div>
                </div>
            <?php endif; ?>

            <form action="register.php" method="POST" id="registerForm" novalidate>
                <div class="form-group">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-control-wrap">
                        <span class="input-icon-left"><i class="fas fa-user"></i></span>
                        <input type="text" id="username" name="username"
                               class="form-control-ds has-left-icon <?= !empty($errors['username']) ? 'is-invalid' : '' ?>"
                               placeholder="minimal 3 karakter"
                               value="<?= htmlspecialchars($old['username']) ?>" required>
                    </div>
                    <div class="form-error" id="err-username"><?= htmlspecialchars($errors['username'] ?? '') ?></div>
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">Alamat Email</label>
                    <div class="input-control-wrap">
                        <span class="input-icon-left"><i class="fas fa-envelope"></i></span>
                        <input type="email" id="email" name="email"
                               class="form-control-ds has-left-icon <?= !empty($errors['email']) ? 'is-invalid' : '' ?>"
                               placeholder="nama@email.com"
                               value="<?= htmlspecialchars($old['email']) ?>" required>
                    </div>
                    <div class="form-error" id="err-email"><?= htmlspecialchars($errors['email'] ?? '') ?></div>
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">Nomor WhatsApp / Telepon</label>
                    <div class="input-control-wrap">
                        <span class="input-icon-left"><i class="fas fa-phone"></i></span>
                        <input type="tel" id="phone" name="phone"
                               class="form-control-ds has-left-icon mono <?= !empty($errors['phone']) ? 'is-invalid' : '' ?>"
                               placeholder="628123456789"
                               value="<?= htmlspecialchars($old['phone']) ?>" required>
                    </div>
                    <p class="form-hint">Format internasional diawali 62 tanpa tanda '+' (contoh: 628123456789).</p>
                    <div class="form-error" id="err-phone"><?= htmlspecialchars($errors['phone'] ?? '') ?></div>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-control-wrap">
                        <span class="input-icon-left"><i class="fas fa-lock"></i></span>
                        <input type="password" id="password" name="password"
                               class="form-control-ds has-left-icon has-right-icon <?= !empty($errors['password']) ? 'is-invalid' : '' ?>"
                               placeholder="Minimal 8 karakter" required>
                        <button type="button" class="input-action-right toggle-pwd" data-target="password" aria-label="Tampilkan password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="form-error" id="err-password"><?= htmlspecialchars($errors['password'] ?? '') ?></div>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label">Konfirmasi Password</label>
                    <div class="input-control-wrap">
                        <span class="input-icon-left"><i class="fas fa-key"></i></span>
                        <input type="password" id="confirm_password" name="confirm_password"
                               class="form-control-ds has-left-icon has-right-icon <?= !empty($errors['confirm_password']) ? 'is-invalid' : '' ?>"
                               placeholder="Ulangi password" required>
                        <button type="button" class="input-action-right toggle-pwd" data-target="confirm_password" aria-label="Tampilkan konfirmasi password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="form-error" id="err-confirm_password"><?= htmlspecialchars($errors['confirm_password'] ?? '') ?></div>
                </div>

                <button type="submit" class="btn-ds btn-ds-primary" style="width: 100%; margin-top: 8px;">
                    <span>Daftar Akun</span>
                    <i class="fas fa-arrow-right" style="font-size: 13px;"></i>
                </button>
            </form>

            <p style="text-align: center; margin-top: 22px; font-size: 14px; color: var(--text-secondary);">
                Sudah memiliki akun?
                <a href="login.php" style="font-weight: 700;">Masuk di sini</a>
            </p>
        </div>
    </main>
</div>

<script>
(function() {
    document.querySelectorAll('.toggle-pwd').forEach(btn => {
        btn.addEventListener('click', function() {
            const target = document.getElementById(this.dataset.target);
            if (!target) return;
            const show = target.type === 'password';
            target.type = show ? 'text' : 'password';
            const icon = this.querySelector('i');
            if (icon) icon.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
        });
    });

    const themeBtn = document.getElementById('authThemeToggle');
    const themeIcon = document.getElementById('authThemeIcon');
    function syncIcon() {
        const t = document.documentElement.getAttribute('data-theme') || 'dark';
        if (themeIcon) themeIcon.className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
    syncIcon();
    if (themeBtn) {
        themeBtn.addEventListener('click', function() {
            const next = (document.documentElement.getAttribute('data-theme') || 'dark') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('thermal_theme', next);
            syncIcon();
        });
    }

    const form = document.getElementById('registerForm');
    const emailInput = document.getElementById('email');
    const phoneInput = document.getElementById('phone');
    let emailAvailable = true;
    let phoneAvailable = true;

    function setErr(field, msg) {
        const el = document.getElementById('err-' + field);
        const inp = document.getElementById(field);
        if (el) el.textContent = msg;
        if (inp) inp.classList.toggle('is-invalid', Boolean(msg));
    }

    function checkUniqueness(payload, field) {
        fetch('register.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'check_email_phone', ...payload })
        })
        .then(r => r.json())
        .then(res => {
            if (field === 'email') {
                emailAvailable = !res.email_exists;
                setErr('email', res.email_exists ? 'Email sudah terdaftar.' : '');
            }
            if (field === 'phone') {
                phoneAvailable = !res.phone_exists;
                setErr('phone', res.phone_exists ? 'Nomor telepon sudah terdaftar.' : '');
            }
        })
        .catch(() => {});
    }

    emailInput.addEventListener('blur', () => {
        if (emailInput.value.trim()) checkUniqueness({ email: emailInput.value.trim() }, 'email');
    });
    phoneInput.addEventListener('blur', () => {
        if (phoneInput.value.trim()) checkUniqueness({ phone: phoneInput.value.trim() }, 'phone');
    });

    form.addEventListener('submit', function(e) {
        let valid = true;
        const username = document.getElementById('username').value.trim();
        const email = emailInput.value.trim();
        const phone = phoneInput.value.trim();
        const pwd = document.getElementById('password').value;
        const cpwd = document.getElementById('confirm_password').value;

        setErr('username', '');
        setErr('email', emailAvailable ? '' : 'Email sudah terdaftar.');
        setErr('phone', phoneAvailable ? '' : 'Nomor telepon sudah terdaftar.');
        setErr('password', '');
        setErr('confirm_password', '');

        if (username.length < 3) { setErr('username', 'Username minimal 3 karakter.'); valid = false; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setErr('email', 'Format email tidak valid.'); valid = false; }
        if (!/^62[0-9]{9,13}$/.test(phone)) { setErr('phone', "Nomor telepon harus diawali 62 tanpa '+' (9–13 digit setelah 62)."); valid = false; }
        if (pwd.length < 8) { setErr('password', 'Password minimal 8 karakter.'); valid = false; }
        if (pwd !== cpwd) { setErr('confirm_password', 'Konfirmasi password tidak cocok.'); valid = false; }
        if (!emailAvailable || !phoneAvailable) valid = false;

        if (!valid) e.preventDefault();
    });
})();
</script>
</body>
</html>
