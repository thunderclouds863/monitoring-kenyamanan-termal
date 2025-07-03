<?php
session_start();
include 'db.php';
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $stmt = $conn->prepare("SELECT id, username FROM users WHERE remember_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
    }
}

// Jika masih belum login, redirect
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'profile';

// Get user settings
$sql = "SELECT * FROM settings WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$settings = $result->fetch_assoc();

// Default settings if none exist
if (!$settings) {
    $settings = [
        'suhu_min' => 25,
        'suhu_max' => 30,
        'kelembapan_min' => 50,
        'kelembapan_max' => 90,
        'wind_speed_min' => 1,
        'wind_speed_max' => 5,
        'air_quality_max' => 450,
        'interval_update' => 5
    ];
}

// Get user profile
$sql_user = "SELECT username, email, phone, password, profile_pic, is_verified FROM users WHERE id = ?";
$stmt_user = $conn->prepare($sql_user);
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$result_user = $stmt_user->get_result();
$user = $result_user->fetch_assoc();

// Inisialisasi variabel pesan
$success_message = '';
$error_message = '';
$warning_message = '';

// Tampilkan pesan sukses jika verifikasi berhasil
if (isset($_GET['verification_success']) && $_GET['verification_success'] === 'true') {
    $success_message = "Email Anda berhasil diverifikasi.";
}


// Tampilkan pesan error jika token tidak valid
if (isset($_GET['verification_invalid']) && $_GET['verification_invalid'] === 'true') {
    $error_message = "Link verifikasi tidak valid. <a href='resend_change.php?email=" . urlencode($user['email']) . "' class='text-blue-400 hover:text-blue-500 underline'>Klik di sini</a> untuk meminta link baru.";
}

// Tampilkan pesan error jika token kadaluarsa
if (isset($_GET['verification_expired']) && $_GET['verification_expired'] === 'true') {
    $error_message = "Link verifikasi telah kadaluarsa. <a href='resend_change.php?email=" . urlencode($user['email']) . "' class='text-blue-400 hover:text-blue-500 underline'>Klik di sini</a> untuk meminta link baru.";
}

// Tampilkan pesan sukses jika link verifikasi telah dikirim
if (isset($_GET['verification_sent']) && $_GET['verification_sent'] === 'true') {
    $success_message = "Link verifikasi email telah dikirim ke email Anda.";
}

// Tampilkan warning jika email belum diverifikasi dan tidak ada error
if (empty($error_message) && $user['is_verified'] == 0) {
    $warning_message = "Email Anda belum diverifikasi. <a href='resend_change.php?email=" . urlencode($user['email']) . "' class='text-blue-400 hover:text-blue-500 underline'>Klik di sini</a> untuk mengirim ulang link verifikasi.";
}

// Handle profile update
if (isset($_POST['form_type']) && $_POST['form_type'] === 'profile') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);

    // Phone number validation
    $phone_pattern = '/^62\d{9,12}$/'; // Format: 62 followed by 9-12 digits
    if (!preg_match($phone_pattern, $phone)) {
        $error_message = "Nomor telepon harus menggunakan format internasional (contoh: 628123456789) tanpa '+' atau '0'";
    } elseif (empty($username) || empty($email)) {
        $error_message = "Semua kolom profil harus diisi.";
    } else {
        // Cek apakah email, username, atau nomor telepon sudah digunakan
        $check_sql = "SELECT id, email, username, phone FROM users WHERE (email = ? OR username = ? OR phone = ?) AND id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("sssi", $email, $username, $phone, $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            // Jika ada hasil, berarti ada duplikasi
            while ($row = $check_result->fetch_assoc()) {
                if ($row['email'] === $email) {
                    $error_message = "Email sudah digunakan oleh akun lain.";
                } elseif ($row['username'] === $username) {
                    $error_message = "Username sudah digunakan oleh akun lain.";
                } elseif ($row['phone'] === $phone) {
                    $error_message = "Nomor telepon sudah digunakan oleh akun lain.";
                }
            }
        } else {
            // Handle profile picture upload
            $profile_pic = $user['profile_pic'];
            if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES['profile_pic']['tmp_name'];
                $profile_pic_name = time() . '_' . basename($_FILES['profile_pic']['name']); // Kasih timestamp biar unik
                $upload_dir = 'uploads/'; // Folder di URL
                $upload_dir_server = __DIR__ . '/uploads/'; // Folder fisik di server
                $profile_pic_path = $upload_dir . $profile_pic_name;         // Buat simpan ke database
                $profile_pic_server_path = $upload_dir_server . $profile_pic_name; // Untuk move_uploaded_file

                // Pastikan folder ada
                if (!file_exists($upload_dir_server)) {
                    mkdir($upload_dir_server, 0755, true);
                }

                // Pindahkan file ke direktori upload
                if (move_uploaded_file($tmp_name, $profile_pic_server_path)) {
                    $profile_pic = $profile_pic_path; // Simpan path relatif ke database
                } else {
                    $error_message = "Gagal mengupload foto profil.";
                }
            }

            // Update user profile
            $sql = "UPDATE users SET username = ?, email = ?, phone = ?, profile_pic = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssi", $username, $email, $phone, $profile_pic, $user_id);

            if ($stmt->execute()) {
                $success_message = "Profil berhasil diperbarui.";
                if ($email !== $user['email']) {
                    // Set is_verified to 0 for the new email
                    $sql_update_verification = "UPDATE users SET is_verified = 0 WHERE id = ?";
                    $stmt_update_verification = $conn->prepare($sql_update_verification);
                    $stmt_update_verification->bind_param("i", $user_id);
                    $stmt_update_verification->execute();

                    // Add email verification message
                    $success_message = "Email berhasil diubah. Link verifikasi telah dikirim ke email Anda. Silakan verifikasi.";

                    // Kirim email verifikasi
                    $verification_url = "http://monitoring-kenyamanan-termal.my.id/resend_change.php?email=" . urlencode($email);
                    file_get_contents($verification_url); // Panggil script pengiriman email
                }
                $_SESSION['username'] = $username; // Update session username
            } else {
                if ($conn->errno == 1062) {
                    // Error kode 1062 = Duplicate entry
                    if (strpos($conn->error, 'email') !== false) {
                        $error_message = "Email sudah digunakan oleh akun lain.";
                    } elseif (strpos($conn->error, 'username') !== false) {
                        $error_message = "Username sudah digunakan oleh akun lain.";
                    } elseif (strpos($conn->error, 'phone') !== false) {
                        $error_message = "Nomor telepon sudah digunakan oleh akun lain.";
                    }
                } else {
                    $error_message = "Gagal memperbarui profil: " . $conn->error;
                }
            }
        }
    }
}

    // Password Update
    if (isset($_POST['form_type']) && $_POST['form_type'] === 'password') {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if (!password_verify($current_password, $user['password'])) {
            $error_message = "Password lama salah.";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "Password baru dan konfirmasi password tidak cocok.";
        } elseif (strlen($new_password) < 8) {
            $error_message = "Password baru harus minimal 8 karakter.";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $sql = "UPDATE users SET password = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $hashed_password, $user_id);

            if ($stmt->execute()) {
                $success_message = "Password berhasil diubah.";
            } else {
                $error_message = "Gagal mengubah password.";
            }
        }
    }

// Preferences Update
if (isset($_POST['form_type']) && $_POST['form_type'] === 'preferences') {
    $suhu_min = floatval($_POST['suhu_min']);
    $suhu_max = floatval($_POST['suhu_max']);
    $kelembapan_min = floatval($_POST['kelembapan_min']);
    $kelembapan_max = floatval($_POST['kelembapan_max']);
    $wind_speed_min = floatval($_POST['wind_speed_min']);
    $wind_speed_max = floatval($_POST['wind_speed_max']);
    $air_quality_max = floatval($_POST['air_quality_max']);
    
    $cooldown_time = isset($_POST['cooldown_time']) ? intval($_POST['cooldown_time']) : 600;
    $delta_threshold = isset($_POST['delta_threshold']) ? floatval($_POST['delta_threshold']) : 10.0;
    $sensitivity_level = isset($_POST['sensitivity_level']) ? $_POST['sensitivity_level'] : 'normal';

    // Validate ranges
    if ($suhu_min >= $suhu_max) {
        $error_message = "Suhu minimum harus lebih kecil dari suhu maksimum.";
    } elseif ($kelembapan_min >= $kelembapan_max) {
        $error_message = "Kelembapan minimum harus lebih kecil dari kelembapan maksimum.";
    } elseif ($wind_speed_min >= $wind_speed_max) {
        $error_message = "Kecepatan angin minimum harus lebih kecil dari maksimum.";
    } elseif ($cooldown_time < 60 || $cooldown_time > 3600) {
        $error_message = "Cooldown time harus antara 60 dan 3600 detik.";
    } elseif ($delta_threshold < 0.1 || $delta_threshold > 100) {
        $error_message = "Delta threshold harus antara 0.1% dan 100%.";
    } else {
        // Check if settings exist or need to be inserted
        $check_sql = "SELECT id FROM settings WHERE user_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            // Update existing settings
            $sql = "UPDATE settings SET
                    suhu_min = ?, suhu_max = ?,
                    kelembapan_min = ?, kelembapan_max = ?,
                    wind_speed_min = ?, wind_speed_max = ?,
                    air_quality_max = ?,
                    cooldown_time = ?, delta_threshold = ?, sensitivity_level = ?
                    WHERE user_id = ?";
        } else {
            // Insert new settings
            $sql = "INSERT INTO settings (
                    suhu_min, suhu_max,
                    kelembapan_min, kelembapan_max,
                    wind_speed_min, wind_speed_max,
                    air_quality_max,
                    cooldown_time, delta_threshold, sensitivity_level,
                    user_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        }

        $stmt = $conn->prepare($sql);
        if ($check_result->num_rows > 0) {
            $stmt->bind_param("dddddddddsi", $suhu_min, $suhu_max, $kelembapan_min, $kelembapan_max,
                            $wind_speed_min, $wind_speed_max, $air_quality_max,
                            $cooldown_time, $delta_threshold, $sensitivity_level,
                            $user_id);
        } else {
            $stmt->bind_param("dddddddddsi", $suhu_min, $suhu_max, $kelembapan_min, $kelembapan_max,
                            $wind_speed_min, $wind_speed_max, $air_quality_max,
                            $cooldown_time, $delta_threshold, $sensitivity_level,
                            $user_id);
        }

       if ($stmt->execute()) {
    $success_message = "Pengaturan preferensi berhasil disimpan.";
    
    // Ambil kembali pengaturan terbaru
    $sql = "SELECT * FROM settings WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $settings = $result->fetch_assoc();
} else {
    $error_message = "Gagal menyimpan pengaturan preferensi: " . $conn->error;
}
    }
}

        // Notification Settings Update
if (isset($_POST['form_type']) && $_POST['form_type'] === 'notification') {
    $cooldown_time = intval($_POST['cooldown_time']);
    $delta_threshold = floatval($_POST['delta_threshold']);
    $sensitivity_level = $_POST['sensitivity_level'];

    if ($cooldown_time < 60 || $cooldown_time > 3600) {
        $error_message = "Cooldown time harus antara 60 dan 3600 detik.";
    } elseif ($delta_threshold < 0.1) {
        $error_message = "Delta threshold terlalu kecil.";
    } elseif (!in_array($sensitivity_level, ['normal', 'sensitif'])) {
        $error_message = "Tingkat sensitivitas tidak valid.";
    } else {
        // Cek apakah record settings user sudah ada
        $check_sql = "SELECT id FROM settings WHERE user_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $sql = "UPDATE settings SET cooldown_time = ?, delta_threshold = ?, sensitivity_level = ? WHERE user_id = ?";
        } else {
            $sql = "INSERT INTO settings (cooldown_time, delta_threshold, sensitivity_level, user_id) VALUES (?, ?, ?, ?)";
        }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ddsi", $cooldown_time, $delta_threshold, $sensitivity_level, $user_id);

        if ($stmt->execute()) {
            $success_message = "Pengaturan notifikasi berhasil disimpan.";
                // Ambil kembali pengaturan terbaru
    $sql = "SELECT * FROM settings WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $settings = $result->fetch_assoc();
        } else {
            $error_message = "Gagal menyimpan pengaturan notifikasi.";
        }
    }
}

    // System Settings Update
    if (isset($_POST['form_type']) && $_POST['form_type'] === 'system') {
        $interval_update = intval($_POST['interval_update']);

        if ($interval_update < 1 || $interval_update > 60) {
            $error_message = "Interval harus antara 1 hingga 60 detik.";
        } else {
            // Check if settings exist or need to be inserted
            $check_sql = "SELECT id FROM settings WHERE user_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("i", $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            if ($check_result->num_rows > 0) {
                // Update existing settings
                $sql = "UPDATE settings SET interval_update = ? WHERE user_id = ?";
            } else {
                // Insert new settings with defaults except interval
                $sql = "INSERT INTO settings (interval_update, user_id) VALUES (?, ?)";
            }

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $interval_update, $user_id);

            if ($stmt->execute()) {
                $success_message = "Interval pembaruan berhasil disimpan.";
                    // Ambil kembali pengaturan terbaru
    $sql = "SELECT * FROM settings WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $settings = $result->fetch_assoc();
            } else {
                $error_message = "Gagal menyimpan interval pembaruan.";
            }
        }
    }
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="logo.png">
    <title>Pengaturan Sistem</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.js"></script>
    <style>
        .sidebar-item:hover {
            background-color: #374151;
        }
        .sidebar-item.active {
            background-color: #1e40af;
        }
        .profile-pic {
            width: 120px;
            height: 120px;
            object-fit: cover;
        }
        .profile-pic-container {
            position: relative;
            display: inline-block;
        }
        .profile-pic-edit {
            position: absolute;
            bottom: 0;
            right: 0;
            background: rgba(0,0,0,0.7);
            color: white;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        footer {
            background: none; /* Tanpa latar belakang */
            color: #6b7280; /* Warna teks abu-abu */
            font-size: 0.875rem; /* Ukuran teks kecil */
        }

        footer a {
            color: #60a5fa; /* Warna link biru */
            text-decoration: none;
            transition: color 0.3s ease-in-out;
        }

        footer a:hover {
            color: #3b82f6; /* Warna lebih terang saat hover */
        }
        #cropModal {
  position: fixed;
  z-index: 9999;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0, 0, 0, 0.7);
  display: none;
  align-items: center;
  justify-content: center;
  flex-direction: column;
}
    </style>
</head>
<body class="bg-gray-900 text-white min-h-screen pt-8">
    <?php include 'nav.php'; ?>

    <div class="flex pt-16">
    <!-- Sidebar -->
    <div class="w-64 bg-gray-800 min-h-screen p-4 hidden md:block fixed top-24 left-0">
            <div class="flex items-center space-x-4 p-4 border-b border-gray-700">
                <?php if (!empty($user['profile_pic'])): ?>
                    <img src="<?= htmlspecialchars($user['profile_pic']) ?>" 
     class="w-12 h-12 rounded-full object-cover" 
     alt="Profile">
                <?php else: ?>
                    <div class="w-12 h-12 rounded-full bg-gray-600 flex items-center justify-center">
                        <i class="fas fa-user text-xl"></i>
                    </div>
                <?php endif; ?>
                <div>
                    <h3 class="font-bold"><?= htmlspecialchars($user['username']) ?></h3>
                    <p class="text-sm text-gray-400">Pengaturan Akun</p>
                </div>
            </div>

            <nav class="mt-6">
                <a href="?tab=profile" class="block px-4 py-3 rounded-lg mb-2 sidebar-item <?= $active_tab === 'profile' ? 'active' : '' ?>">
                    <i class="fas fa-user-circle mr-3"></i> Profil
                </a>
                <a href="?tab=password" class="block px-4 py-3 rounded-lg mb-2 sidebar-item <?= $active_tab === 'password' ? 'active' : '' ?>">
                    <i class="fas fa-lock mr-3"></i> Password
                </a>
                <a href="?tab=preferences" class="block px-4 py-3 rounded-lg mb-2 sidebar-item <?= $active_tab === 'preferences' ? 'active' : '' ?>">
                    <i class="fas fa-sliders-h mr-3"></i> Preferensi Sensor
                </a>
                <a href="?tab=system" class="block px-4 py-3 rounded-lg mb-2 sidebar-item <?= $active_tab === 'system' ? 'active' : '' ?>">
                    <i class="fas fa-arrow-right-arrow-left mr-3"></i>  Sinkronisasi Data
                </a>
            </nav>
        </div>

        <!-- Mobile menu button -->
        <div class="md:hidden fixed bottom-4 right-4 z-50">
            <button id="mobile-menu-button" class="bg-blue-600 text-white p-4 rounded-full shadow-lg">
                <i class="fas fa-cog text-xl"></i>
            </button>
        </div>

<!-- Mobile menu -->
<div id="mobile-menu" class="fixed inset-0 bg-gray-900 bg-opacity-90 z-40 hidden pt-16">
            <div class="flex justify-end p-4">
<button id="close-mobile-menu" class="text-white dark:text-black text-2xl">
    <i class="fas fa-times"></i>
</button>

            </div>
            <div class="flex flex-col items-center justify-center h-full">
                <div class="flex items-center space-x-4 p-4 mb-8">
                <?php if (!empty($user['profile_pic'])): ?>
                    <img src="<?= htmlspecialchars($user['profile_pic']) ?>" class="profile-pic rounded-full" alt="Profile Picture">
                <?php else: ?>
                    <div class="profile-pic rounded-full bg-gray-600 flex items-center justify-center">
                        <i class="fas fa-user text-4xl"></i>
                    </div>
                <?php endif; ?>
                    <div>
                        <h3 class="font-bold"><?= htmlspecialchars($user['username']) ?></h3>
                        <p class="text-sm text-gray-400">Pengaturan Akun</p>
                    </div>
                </div>

                <nav class="w-full px-8">
                    <a href="?tab=profile" class="block px-4 py-3 rounded-lg mb-4 text-center sidebar-item <?= $active_tab === 'profile' ? 'active' : '' ?>">
                        <i class="fas fa-user-circle mr-3"></i> Profil
                    </a>
                    <a href="?tab=password" class="block px-4 py-3 rounded-lg mb-4 text-center sidebar-item <?= $active_tab === 'password' ? 'active' : '' ?>">
                        <i class="fas fa-lock mr-3"></i> Password
                    </a>
                    <a href="?tab=preferences" class="block px-4 py-3 rounded-lg mb-4 text-center sidebar-item <?= $active_tab === 'preferences' ? 'active' : '' ?>">
                        <i class="fas fa-sliders-h mr-3"></i> Preferensi Sensor
                    </a>
                    <a href="?tab=system" class="block px-4 py-3 rounded-lg mb-4 text-center sidebar-item <?= $active_tab === 'system' ? 'active' : '' ?>">
                        <i class="fas fa-arrow-right-arrow-left mr-3"></i> Sinkronisasi Data
                    </a>
                </nav>
            </div>
        </div>

<!-- Main Content -->
<div class="flex-1 md:ml-60 mt-8">
    <div class="max-w-4xl mx-auto">
                <h2 class="text-3xl font-bold mb-6 flex items-center">
                    <i class="fas fa-cog mr-3"></i> Pengaturan Sistem
                </h2>

                <?php if (!empty($success_message)): ?>
                    <div class="bg-green-500 text-white p-4 rounded mb-6 flex items-center">
                        <i class="fas fa-check-circle mr-2"></i> <?= $success_message ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                    <div class="bg-red-500 text-white p-4 rounded mb-6 flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i> <?= $error_message ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($warning_message)): ?>
                    <div class="bg-yellow-500 text-black p-4 rounded mb-6 flex items-center">
                        <i class="fas fa-exclamation-triangle mr-2"></i> <?= $warning_message ?>
                    </div>
                <?php endif; ?>

                <!-- Profile Tab -->
                <?php if ($active_tab === 'profile'): ?>
                    <div class="bg-gray-800 p-6 rounded-lg shadow-lg">
                        <h3 class="text-2xl font-bold mb-6 flex items-center">
                            <i class="fas fa-user-circle mr-2"></i> Profil Pengguna
                        </h3>

                        <form method="POST" enctype="multipart/form-data" class="space-y-6">
                            <input type="hidden" name="form_type" value="profile">

<!-- Profile Picture Display -->
<div class="flex flex-col items-center mb-6">
    <div class="profile-pic-container mb-4">
        <?php if (!empty($user['profile_pic'])): ?>
            <img src="<?= htmlspecialchars($user['profile_pic']) ?>" 
                 class="profile-pic rounded-full object-cover w-32 h-32" 
                 alt="Profile Picture" id="profilePicPreview">
        <?php else: ?>
            <div class="profile-pic rounded-full bg-gray-600 flex items-center justify-center w-32 h-32">
                <i class="fas fa-user text-gray-400 text-6xl"></i>
            </div>
        <?php endif; ?>
        <div class="profile-pic-edit" id="editProfilePic">
            <i class="fas fa-camera"></i>
        </div>
    </div>

    <?php if (!empty($user['profile_pic'])): ?>
        <button id="deletePhotoBtn" class="mt-2 text-red-500 hover:underline text-sm">
            <i class="fas fa-trash-alt mr-1"></i> Hapus Foto
        </button>
    <?php endif; ?>

    <input type="file" id="profile_pic" name="profile_pic" accept="image/*" class="hidden">
    <p class="text-gray-400 text-sm">Klik ikon kamera untuk mengubah foto profil</p>
</div>


                            <div>
                                <label class="block text-lg mb-2">Nama Pengguna</label>
                                <input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>"
                                       required class="w-full p-3 rounded bg-gray-700 text-white border border-gray-600 focus:border-blue-500 focus:outline-none">
                            </div>

                            <div>
                                <label class="block text-lg mb-2">Email</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>"
                                       required class="w-full p-3 rounded bg-gray-700 text-white border border-gray-600 focus:border-blue-500 focus:outline-none">
                            </div>

                            <div>
                                <label class="block text-lg mb-2">Nomor Telepon</label>
                                <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone']) ?>"
                                       required class="w-full p-3 rounded bg-gray-700 text-white border border-gray-600 focus:border-blue-500 focus:outline-none"
                                       placeholder="628123456789">
                                <p class="text-gray-400 text-sm mt-1">Gunakan format internasional (contoh: 628123456789) tanpa "+" atau "0"</p>
                            </div>

                            <div class="pt-4">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-lg transition duration-200">
                                    <i class="fas fa-save mr-2"></i> Simpan Perubahan
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Password Tab -->
                <?php if ($active_tab === 'password'): ?>
                    <div class="bg-gray-800 p-6 rounded-lg shadow-lg">
                        <h3 class="text-2xl font-bold mb-6 flex items-center">
                            <i class="fas fa-lock mr-2"></i> Ubah Password
                        </h3>

                        <form method="POST" class="space-y-6">
                            <input type="hidden" name="form_type" value="password">

                            <div>
                                <label class="block text-lg mb-2">Password Saat Ini</label>
                                <input type="password" name="current_password" required
                                       class="w-full p-3 rounded bg-gray-700 text-white border border-gray-600 focus:border-blue-500 focus:outline-none">
                            </div>

                            <div>
                                <label class="block text-lg mb-2">Password Baru</label>
                                <input type="password" name="new_password" required
                                       class="w-full p-3 rounded bg-gray-700 text-white border border-gray-600 focus:border-blue-500 focus:outline-none">
                                <p class="text-gray-400 text-sm mt-1">Minimal 8 karakter</p>
                            </div>

                            <div>
                                <label class="block text-lg mb-2">Konfirmasi Password Baru</label>
                                <input type="password" name="confirm_password" required
                                       class="w-full p-3 rounded bg-gray-700 text-white border border-gray-600 focus:border-blue-500 focus:outline-none">
                            </div>

                            <div class="pt-4 flex justify-between items-center">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-lg transition duration-200">
                                    <i class="fas fa-key mr-2"></i> Ubah Password
                                </button>

                                <a href="password_recovery.php" class="text-blue-400 hover:text-blue-300 text-sm">
                                    <i class="fas fa-question-circle mr-1"></i> Lupa Password?
                                </a>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

<?php if ($active_tab === 'preferences'): ?>
    <div class="bg-gray-800 p-6 rounded-lg shadow-lg">
        <h3 class="text-2xl font-bold mb-6 flex items-center">
            <i class="fas fa-bell mr-2"></i> Preferensi Notifikasi
        </h3>

        <form method="POST" class="space-y-6">
            <input type="hidden" name="form_type" value="preferences">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Temperature Settings -->
                <div class="bg-gray-700 p-4 rounded-lg">
                    <h4 class="text-lg font-semibold mb-4 flex items-center">
                        <i class="fas fa-thermometer-half mr-2"></i> Suhu (°C)
                    </h4>
                    <div class="space-y-4">
                        <div>
                            <label class="block mb-1">Minimum</label>
                            <input type="number" step="0.1" name="suhu_min" value="<?= $settings['suhu_min'] ?>"
                                   class="w-full p-2 rounded bg-gray-600 text-white border border-gray-500">
                        </div>
                        <div>
                            <label class="block mb-1">Maksimum</label>
                            <input type="number" step="0.1" name="suhu_max" value="<?= $settings['suhu_max'] ?>"
                                   class="w-full p-2 rounded bg-gray-600 text-white border border-gray-500">
                        </div>
                    </div>
                </div>

                <!-- Humidity Settings -->
                <div class="bg-gray-700 p-4 rounded-lg">
                    <h4 class="text-lg font-semibold mb-4 flex items-center">
                        <i class="fas fa-tint mr-2"></i> Kelembapan (%)
                    </h4>
                    <div class="space-y-4">
                        <div>
                            <label class="block mb-1">Minimum</label>
                            <input type="number" step="0.1" name="kelembapan_min" value="<?= $settings['kelembapan_min'] ?>"
                                   class="w-full p-2 rounded bg-gray-600 text-white border border-gray-500">
                        </div>
                        <div>
                            <label class="block mb-1">Maksimum</label>
                            <input type="number" step="0.1" name="kelembapan_max" value="<?= $settings['kelembapan_max'] ?>"
                                   class="w-full p-2 rounded bg-gray-600 text-white border border-gray-500">
                        </div>
                    </div>
                </div>

                <!-- Wind Speed Settings -->
                <div class="bg-gray-700 p-4 rounded-lg">
                    <h4 class="text-lg font-semibold mb-4 flex items-center">
                        <i class="fas fa-wind mr-2"></i> Kecepatan Angin (m/s)
                    </h4>
                    <div class="space-y-4">
                        <div>
                            <label class="block mb-1">Minimum</label>
                            <input type="number" step="0.1" name="wind_speed_min" value="<?= $settings['wind_speed_min'] ?>"
                                   class="w-full p-2 rounded bg-gray-600 text-white border border-gray-500">
                        </div>
                        <div>
                            <label class="block mb-1">Maksimum</label>
                            <input type="number" step="0.1" name="wind_speed_max" value="<?= $settings['wind_speed_max'] ?>"
                                   class="w-full p-2 rounded bg-gray-600 text-white border border-gray-500">
                        </div>
                    </div>
                </div>

                <!-- Air Quality Settings -->
                <div class="bg-gray-700 p-4 rounded-lg">
                    <h4 class="text-lg font-semibold mb-4 flex items-center">
                        <i class="fas fa-smog mr-2"></i> Kualitas Udara (CO2)
                    </h4>
                    <div>
                        <label class="block mb-1">Batas Maksimum</label>
                        <input type="number" step="1" name="air_quality_max" value="<?= $settings['air_quality_max'] ?>"
                               class="w-full p-2 rounded bg-gray-600 text-white border border-gray-500">
                        <p class="text-gray-400 text-sm mt-1">Nilai di atas ini akan dianggap tidak sehat</p>
                    </div>
                </div>
            </div>

<!-- Notification Logic Settings -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-8">
    <!-- Cooldown Time -->
    <div class="bg-gray-700 p-4 rounded-lg">
        <label class="block mb-2 font-semibold flex items-center">
            Cooldown Time (detik)
            <span class="ml-2 relative group cursor-pointer">
                <i class="fas fa-info-circle text-blue-400"></i>
                <div class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 w-72 p-3 text-sm text-gray-100 bg-gray-800 border border-gray-600 rounded-lg shadow-lg opacity-0 group-hover:opacity-100 transition-opacity duration-300 z-10">
                    Waktu jeda minimum antar notifikasi. Selama periode ini, sistem tidak akan mengirim notifikasi meskipun parameter tetap abnormal.
                </div>
            </span>
        </label>
        <input type="number" name="cooldown_time" min="60" max="3600"
               value="<?= isset($settings['cooldown_time']) ? $settings['cooldown_time'] : 300 ?>"
               class="w-full px-4 py-2 rounded bg-gray-600 text-white border border-gray-500">
    </div>

    <!-- Delta Threshold -->
    <div class="bg-gray-700 p-4 rounded-lg">
        <label class="block mb-2 font-semibold flex items-center">
            Delta Threshold (%)
            <span class="ml-2 relative group cursor-pointer">
                <i class="fas fa-info-circle text-blue-400"></i>
                <div class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 w-80 p-3 text-sm text-gray-100 bg-gray-800 border border-gray-600 rounded-lg shadow-lg opacity-0 group-hover:opacity-100 transition-opacity duration-300 z-10">
                    Menentukan persentase perubahan minimal dari parameter terakhir untuk memicu notifikasi baru, walaupun masih dalam masa cooldown. Contoh: Jika batas atas suhu adalah <strong>30°C</strong>, dan sebelumnya sudah menyentuh <strong>32°C</strong>, lalu naik lagi menjadi <strong>32.7°C</strong> (kenaikan ≥ 2%), maka sistem akan mengirim notifikasi ulang.
                </div>
            </span>
        </label>
        <input type="number" step="0.1" name="delta_threshold"
               value="<?= isset($settings['delta_threshold']) ? $settings['delta_threshold'] : 2.0 ?>"
               class="w-full px-4 py-2 rounded bg-gray-600 text-white border border-gray-500">
    </div>

    <!-- Sensitivity Level -->
    <div class="bg-gray-700 p-4 rounded-lg">
        <label class="block mb-2 font-semibold flex items-center">
            Tingkat Sensitivitas
            <span class="ml-2 relative group cursor-pointer">
                <i class="fas fa-info-circle text-blue-400"></i>
                <div class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 w-72 p-3 text-sm text-gray-100 bg-gray-800 border border-gray-600 rounded-lg shadow-lg opacity-0 group-hover:opacity-100 transition-opacity duration-300 z-10">
                    Mengatur seberapa ketat sistem mendeteksi perubahan. Mode "Sensitif" akan lebih cepat merespons perubahan kecil dibandingkan mode "Normal".
                </div>
            </span>
        </label>
        <select name="sensitivity_level"
                class="w-full px-4 py-2 rounded bg-gray-600 text-white border border-gray-500">
            <option value="normal" <?= isset($settings['sensitivity_level']) && $settings['sensitivity_level'] === 'normal' ? 'selected' : '' ?>>Normal</option>
            <option value="sensitif" <?= isset($settings['sensitivity_level']) && $settings['sensitivity_level'] === 'sensitif' ? 'selected' : '' ?>>Sensitif</option>
        </select>
    </div>
</div>



            <div class="pt-6">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-lg transition duration-200">
                    <i class="fas fa-save mr-2"></i> Simpan Semua Preferensi
                </button>
            </div>
        </form>
    </div>
<?php endif; ?>


                <!-- System Tab -->
                <?php if ($active_tab === 'system'): ?>
                    <div class="bg-gray-800 p-6 rounded-lg shadow-lgs">
                        <h3 class="text-2xl font-bold mb-6 flex items-center">
                            <i class="fas fa-arrow-right-arrow-left mr-3"></i> Sinkronisasi Data
                        </h3>

                        <form method="POST" class="space-y-6">
                            <input type="hidden" name="form_type" value="system">

                            <div class="bg-gray-700 p-4 rounded-lg">
                                <h4 class="text-lg font-semibold mb-4 flex items-center">
                                    <i class="fas fa-sync-alt mr-2"></i> Pembaruan Data
                                </h4>

                                <div>
                                    <label class="block mb-1">Interval Pembaruan (detik)</label>
                                    <input type="number" min="1" max="60" name="interval_update" value="<?= $settings['interval_update'] ?>"
                                           class="w-full p-2 rounded bg-gray-600 text-white border border-gray-500">
                                    <p class="text-gray-400 text-sm mt-1">Interval pengambilan data dari sensor (1-60 detik)</p>
                                </div>
                            </div>

                            <div class="pt-4">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-lg transition duration-200">
                                    <i class="fas fa-save mr-2"></i> Simpan Pengaturan
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    

<!-- Modal Crop -->
<div id="cropModal" style="display:none;">
  <div id="cropContainer"></div>
  <button id="cropBtn" class="mt-1 mb-8 bg-blue-600 text-white px-4 py-2 rounded">Crop & Preview</button>
</div>


    <script>
        // Mobile menu toggle
        document.getElementById('mobile-menu-button').addEventListener('click', function() {
            document.getElementById('mobile-menu').classList.remove('hidden');
        });
        
        document.getElementById('close-mobile-menu').addEventListener('click', function() {
            document.getElementById('mobile-menu').classList.add('hidden');
        });
        
document.addEventListener('DOMContentLoaded', function () {
    let cropInstance;

    // Klik ikon edit = buka input file
    document.getElementById('editProfilePic').addEventListener('click', function () {
        document.getElementById('profile_pic').click();
    });

    // Saat file dipilih
    document.getElementById('profile_pic').addEventListener('change', function (e) {
        const file = e.target.files[0];
        if (!file) return;

        // Validasi file
        if (file.size > 2 * 1024 * 1024) {
            alert("Ukuran maksimal 2MB");
            return;
        }
        if (!file.type.match('image/jpeg') && !file.type.match('image/png')) {
            alert("Hanya JPG dan PNG yang diperbolehkan");
            return;
        }

        const reader = new FileReader();
        reader.onload = function (event) {
            // Tampilkan modal crop
            document.getElementById('cropModal').style.display = 'flex';

            // Destroy instance lama (jika ada)
            if (cropInstance) cropInstance.destroy();

            // Inisialisasi Croppie
            cropInstance = new Croppie(document.getElementById('cropContainer'), {
                viewport: { width: 200, height: 200, type: 'circle' },
                boundary: { width: 300, height: 300 },
                showZoomer: true,
                url: event.target.result
            });
        };
        reader.readAsDataURL(file);
    });

    // Ketika tombol Crop diklik
document.getElementById('cropBtn').addEventListener('click', function () {
    console.log("Crop button clicked.");
    cropInstance.result({
        type: 'base64',
        size: 'viewport'
    }).then(function (base64) {
        console.log("Cropping result:", base64);
        document.getElementById('cropModal').style.display = 'none';
        document.getElementById('profilePicPreview').src = base64;
    });
    });
});
document.getElementById('deletePhotoBtn').addEventListener('click', function () {
    if (confirm("Yakin ingin menghapus foto profil?")) {
        fetch('delete_profile_photo.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'delete=true'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
const container = document.querySelector('.profile-pic-container');
container.innerHTML = `
    <i class="fas fa-user text-gray-400 text-6xl"></i>
    <div class="profile-pic-edit">
        <i class="fas fa-camera"></i>
    </div>
`;

                alert("Foto profil berhasil dihapus.");
            } else {
                alert("Gagal menghapus foto profil: " + data.message);
            }
        })
        .catch(err => {
            alert("Terjadi kesalahan jaringan.");
        });
    }
});




        // Sembunyikan pesan error setelah 5 detik
        const errorMessage = document.getElementById('error-message');
        if (errorMessage) {
            setTimeout(() => {
                errorMessage.style.display = 'none';
            }, 5000); // 5000 ms = 5 detik
        }
    </script>
    <!-- Footer -->
<footer class="text-gray-500 text-center py-4 mt-10">
    <div class="container mx-auto px-4">
        <p class="text-sm">
            &copy; <?php echo date('Y'); ?> Monitoring Kenyamanan Termal. All rights reserved.
        </p>
        <p class="text-xs mt-2">
            Dibuat dengan ❤️ oleh
            <a href="https://www.linkedin.com/in/desi-armanda-sari" target="_blank"
               class="text-blue-400 hover:text-blue-500 underline transition-all duration-300">
                Desi Armanda Sari
            </a>.
        </p>
        <p class="text-xs mt-2 italic text-gray-400">
            "Kalau ada bug, itu fitur tersembunyi. Kalau lancar, itu keajaiban mahasiswa tingkat akhir." 😄
        </p>
    </div>
</footer>
</body>
</html>