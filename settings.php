<?php
include 'db.php';
require_once 'includes/auth_bootstrap.php';

$user = require_authenticated_user($conn);
$user_id = (int)$user['id'];

// Fetch password hash for verification on password tab
$pwdStmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
$pwdStmt->bind_param("i", $user_id);
$pwdStmt->execute();
$pwdRow = $pwdStmt->get_result()->fetch_assoc();
$pwdStmt->close();
$user['password'] = $pwdRow['password'] ?? '';

$allowedTabs = ['profile', 'password', 'preferences', 'system'];
$active_tab = isset($_GET['tab']) && in_array($_GET['tab'], $allowedTabs, true) ? $_GET['tab'] : 'profile';

$settings = get_user_settings($conn, $user_id);

$success_message = '';
$error_message = '';
$warning_message = '';

if (isset($_GET['verification_success']) && $_GET['verification_success'] === 'true') {
    $success_message = "Email Anda berhasil diverifikasi.";
}
if (isset($_GET['verification_invalid']) && $_GET['verification_invalid'] === 'true') {
    $error_message = "Link verifikasi tidak valid.";
}
if (isset($_GET['verification_expired']) && $_GET['verification_expired'] === 'true') {
    $error_message = "Link verifikasi telah kedaluwarsa.";
}
if (isset($_GET['verification_sent']) && $_GET['verification_sent'] === 'true') {
    $success_message = "Link verifikasi email telah dikirim ke email Anda.";
}
if (empty($error_message) && (int)$user['is_verified'] === 0) {
    $warning_message = "Email Anda belum diverifikasi. <a href='resend_change.php?email=" . urlencode($user['email']) . "'>Kirim ulang link verifikasi</a>.";
}

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'profile') {
    $active_tab = 'profile';
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));

    if (!preg_match('/^62\d{9,13}$/', $phone)) {
        $error_message = "Nomor telepon harus diawali 62 tanpa '+' atau '0' (contoh: 628123456789).";
    } elseif ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Username dan alamat email yang valid wajib diisi.";
    } else {
        $check_stmt = $conn->prepare("SELECT id, email, username, phone FROM users WHERE (email = ? OR username = ? OR phone = ?) AND id != ?");
        $check_stmt->bind_param("sssi", $email, $username, $phone, $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            while ($row = $check_result->fetch_assoc()) {
                if ($row['email'] === $email) $error_message = "Email sudah digunakan oleh akun lain.";
                elseif ($row['username'] === $username) $error_message = "Username sudah digunakan oleh akun lain.";
                elseif ($row['phone'] === $phone) $error_message = "Nomor telepon sudah digunakan oleh akun lain.";
            }
        } else {
            $profile_pic = $user['profile_pic'];

            // Secure image upload validation (MIME + extension + size <= 2MB)
            if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['profile_pic'];
                $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);

                if ($file['size'] > 2 * 1024 * 1024) {
                    $error_message = "Ukuran foto profil maksimal 2MB.";
                } elseif (!isset($allowedMimes[$mime])) {
                    $error_message = "Format foto harus JPG, PNG, atau WEBP.";
                } else {
                    $ext = $allowedMimes[$mime];
                    $newName = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
                    $uploadDir = __DIR__ . '/uploads/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    if (move_uploaded_file($file['tmp_name'], $uploadDir . $newName)) {
                        $profile_pic = 'uploads/' . $newName;
                    } else {
                        $error_message = "Gagal menyimpan foto profil.";
                    }
                }
            }

            if (empty($error_message)) {
                $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, phone = ?, profile_pic = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $username, $email, $phone, $profile_pic, $user_id);
                if ($stmt->execute()) {
                    $user['username'] = $username;
                    $user['email'] = $email;
                    $user['phone'] = $phone;
                    $user['profile_pic'] = $profile_pic;
                    $_SESSION['username'] = $username;
                    $success_message = "Profil berhasil diperbarui.";
                } else {
                    $error_message = "Gagal memperbarui profil.";
                }
                $stmt->close();
            }
        }
        $check_stmt->close();
    }
}

// Handle Password Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'password') {
    $active_tab = 'password';
    $current_password = (string)($_POST['current_password'] ?? '');
    $new_password = (string)($_POST['new_password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    if (!password_verify($current_password, $user['password'])) {
        $error_message = "Password saat ini tidak sesuai.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "Konfirmasi password baru tidak cocok.";
    } elseif (strlen($new_password) < 8) {
        $error_message = "Password baru minimal 8 karakter.";
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashed, $user_id);
        if ($stmt->execute()) {
            $success_message = "Password berhasil diperbarui.";
        } else {
            $error_message = "Gagal mengubah password.";
        }
        $stmt->close();
    }
}

// Handle Sensor Preferences Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'preferences') {
    $active_tab = 'preferences';
    $suhu_min = (float)$_POST['suhu_min'];
    $suhu_max = (float)$_POST['suhu_max'];
    $kelembapan_min = (float)$_POST['kelembapan_min'];
    $kelembapan_max = (float)$_POST['kelembapan_max'];
    $wind_speed_min = (float)$_POST['wind_speed_min'];
    $wind_speed_max = (float)$_POST['wind_speed_max'];
    $air_quality_max = (float)$_POST['air_quality_max'];
    $cooldown_time = (int)($_POST['cooldown_time'] ?? 600);
    $delta_threshold = (float)($_POST['delta_threshold'] ?? 10.0);
    $sensitivity_level = in_array($_POST['sensitivity_level'] ?? 'normal', ['normal', 'sensitif'], true) ? $_POST['sensitivity_level'] : 'normal';

    if ($suhu_min >= $suhu_max) {
        $error_message = "Suhu minimum harus lebih kecil dari suhu maksimum.";
    } elseif ($kelembapan_min >= $kelembapan_max) {
        $error_message = "Kelembapan minimum harus lebih kecil dari kelembapan maksimum.";
    } elseif ($wind_speed_min >= $wind_speed_max) {
        $error_message = "Kecepatan angin minimum harus lebih kecil dari maksimum.";
    } elseif ($cooldown_time < 60 || $cooldown_time > 3600) {
        $error_message = "Cooldown time harus antara 60 hingga 3600 detik.";
    } elseif ($delta_threshold < 0.1 || $delta_threshold > 100) {
        $error_message = "Delta threshold harus antara 0.1% hingga 100%.";
    } else {
        $check = $conn->prepare("SELECT id FROM settings WHERE user_id = ? LIMIT 1");
        $check->bind_param("i", $user_id);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if ($exists) {
            $sql = "UPDATE settings SET suhu_min=?, suhu_max=?, kelembapan_min=?, kelembapan_max=?, wind_speed_min=?, wind_speed_max=?, air_quality_max=?, cooldown_time=?, delta_threshold=?, sensitivity_level=? WHERE user_id=?";
        } else {
            $sql = "INSERT INTO settings (suhu_min, suhu_max, kelembapan_min, kelembapan_max, wind_speed_min, wind_speed_max, air_quality_max, cooldown_time, delta_threshold, sensitivity_level, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("dddddddidsi", $suhu_min, $suhu_max, $kelembapan_min, $kelembapan_max, $wind_speed_min, $wind_speed_max, $air_quality_max, $cooldown_time, $delta_threshold, $sensitivity_level, $user_id);
        if ($stmt->execute()) {
            $success_message = "Preferensi sensor dan aturan notifikasi berhasil disimpan.";
            $settings = get_user_settings($conn, $user_id);
        } else {
            $error_message = "Gagal menyimpan preferensi sensor.";
        }
        $stmt->close();
    }
}

// Handle System Sync Interval Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'system') {
    $active_tab = 'system';
    $interval_update = (int)($_POST['interval_update'] ?? 5);

    if ($interval_update < 1 || $interval_update > 60) {
        $error_message = "Interval pembaruan harus antara 1 hingga 60 detik.";
    } else {
        $check = $conn->prepare("SELECT id FROM settings WHERE user_id = ? LIMIT 1");
        $check->bind_param("i", $user_id);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        $sql = $exists
            ? "UPDATE settings SET interval_update = ? WHERE user_id = ?"
            : "INSERT INTO settings (interval_update, user_id) VALUES (?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $interval_update, $user_id);
        if ($stmt->execute()) {
            $success_message = "Interval pembaruan dashboard berhasil disimpan ({$interval_update} detik).";
            $settings = get_user_settings($conn, $user_id);
        } else {
            $error_message = "Gagal menyimpan interval pembaruan.";
        }
        $stmt->close();
    }
}

$avatarSrc = resolve_profile_pic($user['profile_pic'] ?? null);
$initials = user_initials($user['username']);
$activeNav = 'settings';
$pageTitle = 'Pengaturan Akun & Sistem';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan — Monitoring Kenyamanan Termal</title>
    <link rel="icon" type="image/png" href="logo.png">
    <?php render_theme_init_script(); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
    <style>
        .settings-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 22px;
            border-bottom: 1px solid var(--border-subtle);
            padding-bottom: 14px;
        }
        .settings-tab-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            transition: all 0.15s ease;
        }
        .settings-tab-link:hover {
            color: var(--text-primary);
            border-color: var(--border-default);
        }
        .settings-tab-link.is-active {
            background: var(--brand-primary-soft);
            color: var(--brand-primary);
            border-color: rgba(20, 184, 166, 0.4);
        }
        .settings-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }
        .settings-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        .param-card {
            padding: 18px;
            border-radius: var(--radius-md);
            background: var(--bg-surface-2);
            border: 1px solid var(--border-subtle);
        }
        .avatar-upload-box {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 18px;
            border-radius: var(--radius-md);
            background: var(--bg-surface-2);
            border: 1px solid var(--border-subtle);
            margin-bottom: 22px;
            flex-wrap: wrap;
        }
        @media (max-width: 820px) {
            .settings-grid-2, .settings-grid-3 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include 'nav.php'; ?>

    <main class="app-content">
        <div style="max-width: 960px;">
            <nav class="settings-tabs" aria-label="Kategori Pengaturan">
                <a href="settings.php?tab=profile" class="settings-tab-link <?= $active_tab === 'profile' ? 'is-active' : '' ?>">
                    <i class="fas fa-user"></i> Profil
                </a>
                <a href="settings.php?tab=password" class="settings-tab-link <?= $active_tab === 'password' ? 'is-active' : '' ?>">
                    <i class="fas fa-lock"></i> Keamanan Password
                </a>
                <a href="settings.php?tab=preferences" class="settings-tab-link <?= $active_tab === 'preferences' ? 'is-active' : '' ?>">
                    <i class="fas fa-sliders"></i> Ambang Batas &amp; Notifikasi
                </a>
                <a href="settings.php?tab=system" class="settings-tab-link <?= $active_tab === 'system' ? 'is-active' : '' ?>">
                    <i class="fas fa-arrows-rotate"></i> Sinkronisasi Data
                </a>
            </nav>

            <?php if (!empty($success_message)): ?>
                <div class="alert-banner is-success"><i class="fas fa-circle-check" style="margin-top:2px;"></i><div><?= $success_message ?></div></div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="alert-banner is-error"><i class="fas fa-circle-exclamation" style="margin-top:2px;"></i><div><?= $error_message ?></div></div>
            <?php endif; ?>
            <?php if (!empty($warning_message)): ?>
                <div class="alert-banner is-warning"><i class="fas fa-triangle-exclamation" style="margin-top:2px;"></i><div><?= $warning_message ?></div></div>
            <?php endif; ?>

            <!-- TAB 1: PROFILE -->
            <?php if ($active_tab === 'profile'): ?>
                <section class="surface-card">
                    <div class="surface-card-header">
                        <div>
                            <h2 class="surface-card-title">Profil Pengguna</h2>
                            <p class="surface-card-sub">Perbarui informasi akun dan nomor WhatsApp penerima notifikasi</p>
                        </div>
                    </div>
                    <div class="surface-card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="form_type" value="profile">

                            <div class="avatar-upload-box">
                                <?php if ($avatarSrc): ?>
                                    <img src="<?= htmlspecialchars($avatarSrc) ?>" id="profilePreviewImg" alt="Foto Profil" class="avatar-circle" style="width: 72px; height: 72px;">
                                <?php else: ?>
                                    <span id="profilePreviewFallback" class="avatar-circle" style="width: 72px; height: 72px; font-size: 24px;"><?= htmlspecialchars($initials) ?></span>
                                    <img src="" id="profilePreviewImg" alt="Foto Profil" class="avatar-circle" style="width: 72px; height: 72px; display: none;">
                                <?php endif; ?>
                                <div style="flex: 1; min-width: 200px;">
                                    <div style="font-weight: 700; font-size: 14.5px; color: var(--text-primary);">Foto Profil</div>
                                    <div style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 10px;">Format JPG, PNG, atau WEBP (maks. 2MB).</div>
                                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                        <label for="profile_pic" class="btn-ds btn-ds-secondary btn-ds-sm" style="cursor: pointer;">
                                            <i class="fas fa-camera"></i> Pilih Foto
                                        </label>
                                        <input type="file" id="profile_pic" name="profile_pic" accept="image/jpeg,image/png,image/webp" style="display: none;">
                                        <?php if ($avatarSrc): ?>
                                            <button type="button" id="deletePhotoBtn" class="btn-ds btn-ds-ghost btn-ds-sm" style="color: var(--status-critical);">
                                                <i class="fas fa-trash-can"></i> Hapus
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="settings-grid-2">
                                <div class="form-group">
                                    <label for="username" class="form-label">Nama Pengguna</label>
                                    <input type="text" id="username" name="username" class="form-control-ds" value="<?= htmlspecialchars($user['username']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="email" class="form-label">Alamat Email</label>
                                    <input type="email" id="email" name="email" class="form-control-ds" value="<?= htmlspecialchars($user['email']) ?>" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="phone" class="form-label">Nomor Telepon / WhatsApp</label>
                                <input type="tel" id="phone" name="phone" class="form-control-ds mono" value="<?= htmlspecialchars($user['phone']) ?>" placeholder="628123456789" required>
                                <p class="form-hint">Gunakan format internasional diawali 62 tanpa '+' atau '0' (contoh: 628123456789).</p>
                            </div>

                            <button type="submit" class="btn-ds btn-ds-primary">
                                <i class="fas fa-floppy-disk"></i> Simpan Perubahan Profil
                            </button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>

            <!-- TAB 2: PASSWORD -->
            <?php if ($active_tab === 'password'): ?>
                <section class="surface-card">
                    <div class="surface-card-header">
                        <div>
                            <h2 class="surface-card-title">Ubah Password</h2>
                            <p class="surface-card-sub">Pastikan akun Anda terlindungi dengan password minimal 8 karakter</p>
                        </div>
                    </div>
                    <div class="surface-card-body">
                        <form method="POST" style="max-width: 520px;">
                            <input type="hidden" name="form_type" value="password">
                            <div class="form-group">
                                <label for="current_password" class="form-label">Password Saat Ini</label>
                                <input type="password" id="current_password" name="current_password" class="form-control-ds" required>
                            </div>
                            <div class="form-group">
                                <label for="new_password" class="form-label">Password Baru</label>
                                <input type="password" id="new_password" name="new_password" class="form-control-ds" placeholder="Minimal 8 karakter" required>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password" class="form-label">Konfirmasi Password Baru</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="form-control-ds" required>
                            </div>
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-top: 20px;">
                                <button type="submit" class="btn-ds btn-ds-primary">
                                    <i class="fas fa-key"></i> Perbarui Password
                                </button>
                                <a href="password_recovery.php" style="font-size: 13.5px; font-weight: 600;">Lupa password saat ini?</a>
                            </div>
                        </form>
                    </div>
                </section>
            <?php endif; ?>

            <!-- TAB 3: SENSOR PREFERENCES -->
            <?php if ($active_tab === 'preferences'): ?>
                <section class="surface-card">
                    <div class="surface-card-header">
                        <div>
                            <h2 class="surface-card-title">Ambang Batas Kenyamanan &amp; Aturan Peringatan</h2>
                            <p class="surface-card-sub">Sesuaikan batas ideal setiap parameter sensor untuk memicu peringatan otomatis</p>
                        </div>
                    </div>
                    <div class="surface-card-body">
                        <form method="POST">
                            <input type="hidden" name="form_type" value="preferences">

                            <div class="settings-grid-2" style="margin-bottom: 20px;">
                                <div class="param-card">
                                    <div style="display: flex; align-items: center; gap: 9px; margin-bottom: 14px; font-weight: 700;">
                                        <i class="fas fa-temperature-half" style="color: var(--sensor-temp);"></i> Suhu Udara (°C)
                                    </div>
                                    <div class="settings-grid-2">
                                        <div>
                                            <label class="form-label">Minimum</label>
                                            <input type="number" step="0.1" name="suhu_min" class="form-control-ds mono" value="<?= htmlspecialchars((string)$settings['suhu_min']) ?>" required>
                                        </div>
                                        <div>
                                            <label class="form-label">Maksimum</label>
                                            <input type="number" step="0.1" name="suhu_max" class="form-control-ds mono" value="<?= htmlspecialchars((string)$settings['suhu_max']) ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="param-card">
                                    <div style="display: flex; align-items: center; gap: 9px; margin-bottom: 14px; font-weight: 700;">
                                        <i class="fas fa-droplet" style="color: var(--sensor-humid);"></i> Kelembapan (%)
                                    </div>
                                    <div class="settings-grid-2">
                                        <div>
                                            <label class="form-label">Minimum</label>
                                            <input type="number" step="0.1" name="kelembapan_min" class="form-control-ds mono" value="<?= htmlspecialchars((string)$settings['kelembapan_min']) ?>" required>
                                        </div>
                                        <div>
                                            <label class="form-label">Maksimum</label>
                                            <input type="number" step="0.1" name="kelembapan_max" class="form-control-ds mono" value="<?= htmlspecialchars((string)$settings['kelembapan_max']) ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="param-card">
                                    <div style="display: flex; align-items: center; gap: 9px; margin-bottom: 14px; font-weight: 700;">
                                        <i class="fas fa-wind" style="color: var(--sensor-wind);"></i> Kecepatan Angin (m/s)
                                    </div>
                                    <div class="settings-grid-2">
                                        <div>
                                            <label class="form-label">Minimum</label>
                                            <input type="number" step="0.1" name="wind_speed_min" class="form-control-ds mono" value="<?= htmlspecialchars((string)$settings['wind_speed_min']) ?>" required>
                                        </div>
                                        <div>
                                            <label class="form-label">Maksimum</label>
                                            <input type="number" step="0.1" name="wind_speed_max" class="form-control-ds mono" value="<?= htmlspecialchars((string)$settings['wind_speed_max']) ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="param-card">
                                    <div style="display: flex; align-items: center; gap: 9px; margin-bottom: 14px; font-weight: 700;">
                                        <i class="fas fa-lungs" style="color: var(--sensor-co2);"></i> Kualitas Udara CO₂ (ppm)
                                    </div>
                                    <div>
                                        <label class="form-label">Batas Maksimum Aman</label>
                                        <input type="number" step="1" name="air_quality_max" class="form-control-ds mono" value="<?= htmlspecialchars((string)$settings['air_quality_max']) ?>" required>
                                        <p class="form-hint">Nilai di atas batas ini akan memicu peringatan sirkulasi udara.</p>
                                    </div>
                                </div>
                            </div>

                            <h3 style="font-size: 14.5px; font-weight: 700; margin: 0 0 12px 0; color: var(--text-primary);">Logika Pengiriman Notifikasi</h3>
                            <div class="settings-grid-3" style="margin-bottom: 22px;">
                                <div class="param-card">
                                    <label for="cooldown_time" class="form-label">Cooldown Time (detik)</label>
                                    <input type="number" id="cooldown_time" name="cooldown_time" min="60" max="3600" class="form-control-ds mono" value="<?= htmlspecialchars((string)$settings['cooldown_time']) ?>" required>
                                    <p class="form-hint">Jeda minimum antar notifikasi (60–3600 detik).</p>
                                </div>
                                <div class="param-card">
                                    <label for="delta_threshold" class="form-label">Delta Threshold (%)</label>
                                    <input type="number" step="0.1" id="delta_threshold" name="delta_threshold" min="0.1" max="100" class="form-control-ds mono" value="<?= htmlspecialchars((string)$settings['delta_threshold']) ?>" required>
                                    <p class="form-hint">Perubahan ekstrem (%) untuk notifikasi ulang cepat.</p>
                                </div>
                                <div class="param-card">
                                    <label for="sensitivity_level" class="form-label">Tingkat Sensitivitas</label>
                                    <select id="sensitivity_level" name="sensitivity_level" class="form-control-ds">
                                        <option value="normal" <?= $settings['sensitivity_level'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                                        <option value="sensitif" <?= $settings['sensitivity_level'] === 'sensitif' ? 'selected' : '' ?>>Sensitif</option>
                                    </select>
                                    <p class="form-hint">Mode Sensitif merespons perubahan lebih cepat.</p>
                                </div>
                            </div>

                            <button type="submit" class="btn-ds btn-ds-primary">
                                <i class="fas fa-floppy-disk"></i> Simpan Semua Preferensi
                            </button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>

            <!-- TAB 4: SYSTEM SYNC -->
            <?php if ($active_tab === 'system'): ?>
                <section class="surface-card">
                    <div class="surface-card-header">
                        <div>
                            <h2 class="surface-card-title">Sinkronisasi Data Real-Time</h2>
                            <p class="surface-card-sub">Atur seberapa sering dashboard mengambil pembacaan terbaru dari server</p>
                        </div>
                    </div>
                    <div class="surface-card-body">
                        <form method="POST" style="max-width: 520px;">
                            <input type="hidden" name="form_type" value="system">
                            <div class="form-group">
                                <label for="interval_update" class="form-label">Interval Pembaruan (detik)</label>
                                <input type="number" id="interval_update" name="interval_update" min="1" max="60" class="form-control-ds mono" value="<?= htmlspecialchars((string)$settings['interval_update']) ?>" required>
                                <p class="form-hint">Rentang yang didukung: 1 hingga 60 detik. Nilai 5–10 detik disarankan untuk efisiensi baterai &amp; jaringan.</p>
                            </div>
                            <div style="display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap;">
                                <?php foreach ([5, 10, 30, 60] as $preset): ?>
                                    <button type="button" class="btn-ds btn-ds-secondary btn-ds-sm" onclick="document.getElementById('interval_update').value=<?= $preset ?>"><?= $preset ?> detik</button>
                                <?php endforeach; ?>
                            </div>
                            <button type="submit" class="btn-ds btn-ds-primary">
                                <i class="fas fa-floppy-disk"></i> Simpan Pengaturan
                            </button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <footer class="app-footer">
        <span>&copy; <?= date('Y') ?> Monitoring Kenyamanan Termal. All rights reserved.</span>
        <span>Pengaturan Sistem</span>
    </footer>
</div>
</div>

<script>
(function() {
    const fileInput = document.getElementById('profile_pic');
    const previewImg = document.getElementById('profilePreviewImg');
    const previewFallback = document.getElementById('profilePreviewFallback');
    if (fileInput && previewImg) {
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            if (file.size > 2 * 1024 * 1024) {
                alert('Ukuran maksimal foto adalah 2MB.');
                fileInput.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = function(ev) {
                previewImg.src = ev.target.result;
                previewImg.style.display = 'inline-block';
                if (previewFallback) previewFallback.style.display = 'none';
            };
            reader.readAsDataURL(file);
        });
    }

    const delBtn = document.getElementById('deletePhotoBtn');
    if (delBtn) {
        delBtn.addEventListener('click', function() {
            if (!confirm('Hapus foto profil saat ini?')) return;
            fetch('delete_profile_photo.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'delete=true'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) window.location.reload();
                else alert(data.message || 'Gagal menghapus foto profil.');
            })
            .catch(() => alert('Terjadi kesalahan jaringan.'));
        });
    }
})();
</script>
</body>
</html>
