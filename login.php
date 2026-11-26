<?php
include 'db.php';
require_once 'includes/auth_bootstrap.php';

// Redirect if already authenticated
restore_session_from_cookie($conn);
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = "";
$messageType = "error";
$emailValue = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);
    $emailValue = $email;

    if ($email === '' || $password === '') {
        $error = "Email dan password wajib diisi.";
    } else {
        $stmt = $conn->prepare("SELECT id, username, email, password, is_verified FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ((int)$user['is_verified'] === 0) {
                $verificationLink = "resend_verification.php?email=" . urlencode($email);
                $error = "Akun Anda belum diverifikasi. <a href='" . htmlspecialchars($verificationLink) . "'>Kirim ulang kode verifikasi</a>";
            } elseif (password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];

                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    setcookie('remember_token', $token, [
                        'expires' => time() + (30 * 24 * 60 * 60),
                        'path' => '/',
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);

                    $update = $conn->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                    $update->bind_param("si", $token, $user['id']);
                    $update->execute();
                    $update->close();
                }

                header("Location: index.php");
                exit;
            } else {
                $error = "Email atau password yang Anda masukkan tidak sesuai.";
            }
        } else {
            $error = "Email atau password yang Anda masukkan tidak sesuai.";
        }
        $stmt->close();
    }
}

if (isset($_GET['verified']) && $_GET['verified'] === 'true') {
    $error = "Akun Anda berhasil diverifikasi. Silakan masuk sekarang.";
    $messageType = "success";
}
if (isset($_GET['verification_sent']) && $_GET['verification_sent'] === 'true' && !empty($_GET['email'])) {
    $error = "Kode verifikasi telah dikirim ke " . htmlspecialchars((string)$_GET['email']) . ".";
    $messageType = "success";
}
if (isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $error = "Password berhasil diperbarui. Silakan masuk dengan password baru Anda.";
    $messageType = "success";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk — Monitoring Kenyamanan Termal</title>
    <link rel="icon" type="image/png" href="logo.png">
    <?php render_theme_init_script(); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
</head>
<body>
<div class="auth-page">
    <!-- Left Visual & Brand Identity Panel -->
    <section class="auth-visual-panel" aria-hidden="false">
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
                Pemantauan suhu, kelembapan, sirkulasi angin, dan kualitas udara ruangan secara real-time.
            </p>
            <span class="status-pill-live">
                <span class="status-dot"></span>
                Telemetri Aktif
            </span>
        </div>
    </section>

    <!-- Right Authentication Workspace -->
    <main class="auth-form-panel">
        <button type="button" class="btn-icon-only auth-theme-toggle" id="authThemeToggle" aria-label="Ganti tema">
            <i class="fas fa-sun" id="authThemeIcon"></i>
        </button>

        <div class="auth-form-card">
            <h1 class="auth-heading">Masuk ke Akun</h1>
            <p class="auth-subheading">Akses dashboard pemantauan sensor dan riwayat termal Anda.</p>

            <?php if (!empty($error)): ?>
                <div class="alert-banner <?= $messageType === 'success' ? 'is-success' : 'is-error' ?>" role="alert">
                    <i class="fas <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>" style="margin-top: 2px;"></i>
                    <div><?= $error ?></div>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" novalidate>
                <div class="form-group">
                    <label for="email" class="form-label">Alamat Email</label>
                    <div class="input-control-wrap">
                        <span class="input-icon-left"><i class="fas fa-envelope"></i></span>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control-ds has-left-icon"
                            placeholder="nama@email.com"
                            value="<?= htmlspecialchars($emailValue) ?>"
                            autocomplete="email"
                            required
                        >
                    </div>
                </div>

                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 7px;">
                        <label for="password" class="form-label" style="margin-bottom: 0;">Password</label>
                        <a href="password_recovery.php" style="font-size: 13px; font-weight: 600;">Lupa password?</a>
                    </div>
                    <div class="input-control-wrap">
                        <span class="input-icon-left"><i class="fas fa-lock"></i></span>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control-ds has-left-icon has-right-icon"
                            placeholder="Masukkan password"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="input-action-right" id="togglePassword" aria-label="Tampilkan atau sembunyikan password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div style="display: flex; align-items: center; justify-content: space-between; margin: 16px 0 24px;">
                    <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13.5px; color: var(--text-secondary);">
                        <input type="checkbox" name="remember" value="1" checked style="width: 16px; height: 16px; accent-color: var(--brand-primary);">
                        <span>Ingat saya selama 30 hari</span>
                    </label>
                </div>

                <button type="submit" class="btn-ds btn-ds-primary" style="width: 100%;">
                    <span>Masuk ke Dashboard</span>
                    <i class="fas fa-arrow-right" style="font-size: 13px;"></i>
                </button>
            </form>

            <p style="text-align: center; margin-top: 26px; font-size: 14px; color: var(--text-secondary);">
                Belum memiliki akun?
                <a href="register.php" style="font-weight: 700;">Daftar sekarang</a>
            </p>
        </div>
    </main>
</div>

<script>
(function() {
    const toggleBtn = document.getElementById('togglePassword');
    const pwdInput = document.getElementById('password');
    if (toggleBtn && pwdInput) {
        toggleBtn.addEventListener('click', function() {
            const isHidden = pwdInput.type === 'password';
            pwdInput.type = isHidden ? 'text' : 'password';
            const icon = toggleBtn.querySelector('i');
            if (icon) {
                icon.className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
            }
        });
    }

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
})();
</script>
</body>
</html>
