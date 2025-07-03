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
if (!isset($_SESSION['user_id']) && !isset($_COOKIE['remember_token'])) {
    header("Location: login.php");
    exit();
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';
require 'vendor/PHPMailer-master/src/PHPMailer.php';
require 'vendor/PHPMailer-master/src/SMTP.php';
require 'vendor/PHPMailer-master/src/Exception.php';

$bodyClass = 'default-class'; 

class NotificationSystem {
    private static $lastNotificationTime = 0;
    private const NOTIFICATION_COOLDOWN = 3600; // 1 hour cooldown

public static function sendEmailNotification($email, $message) {
    $currentTime = time();
    if (($currentTime - self::$lastNotificationTime) < self::NOTIFICATION_COOLDOWN) {
        error_log("Notification cooldown active. Skipping email to $email");
        return false;
    }

    $apiKey = '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'; 

    $data = [
        'sender' => [
            'name' => 'Monitoring System',
            'email' => 'noreply@monitoring-kenyamanan-termal.my.id'
        ],
        'to' => [
            ['email' => $email]
        ],
        'subject' => 'Peringatan Monitoring Kenyamanan Termal',
        'htmlContent' => '<p>' . nl2br(htmlspecialchars($message)) . '</p>',
        'textContent' => strip_tags($message)
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
        error_log("Email sending failed: " . $error);
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        error_log("Email successfully sent to $email");
        self::$lastNotificationTime = $currentTime;
        $_SESSION['last_notification_time'] = $currentTime;
        return true;
    } else {
        error_log("Email failed. HTTP code: $httpCode. Response: $response");
        return false;
    }
}



    public static function sendWhatsAppNotification($phone, $message) {
        global $conn; // Pastikan koneksi database tersedia

        if (empty($phone)) {
            error_log("Nomor WhatsApp kosong");
            return false;
        }

        // Ambil API key dari database
        $user_id = $_SESSION['user_id'];
        $query = $conn->prepare("SELECT apikey FROM users WHERE id = ?");
        $query->bind_param("i", $user_id);
        $query->execute();
        $result = $query->get_result();
        $user = $result->fetch_assoc();

        $apikey = $user['apikey'] ?? null; // Gunakan null jika apikey tidak ditemukan

        if (empty($apikey)) {
            error_log("API Key tidak ditemukan untuk user_id: $user_id");
            return false;
        }

        // Format nomor (pastikan 62...)
        $phone = ltrim($phone, '+');
        if (substr($phone, 0, 1) === '0') {
            $phone = '62' . substr($phone, 1); // Ganti 0 dengan 62
        } elseif (substr($phone, 0, 2) !== '62') {
            $phone = '62' . $phone; // Tambahkan 62 jika belum ada
        }

        $apiUrl = "https://api.callmebot.com/whatsapp.php?" . http_build_query([
            'phone' => $phone,
            'text'  => $message,
            'apikey' => $apikey // Gunakan API key dari database
        ]);

        try {
            $response = @file_get_contents($apiUrl);
            if ($response === FALSE) {
                throw new Exception("Gagal mengakses API");
            }
            error_log("Notifikasi WhatsApp terkirim ke $phone");
            return true;
        } catch (Exception $e) {
            error_log("Error mengirim WhatsApp: " . $e->getMessage());
            return false;
        }
    }
}

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get user data
$user_id = $_SESSION['user_id'];
$query = $conn->prepare("SELECT id, username, email, phone, profile_pic FROM users WHERE id = ?");
$query->bind_param("i", $user_id);
$query->execute();
$result = $query->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// Get system settings
$settings_query = $conn->prepare("SELECT interval_update FROM settings WHERE user_id = ?");
$settings_query->bind_param("i", $user_id);
$settings_query->execute();
$settings_result = $settings_query->get_result();
$settings = $settings_result->fetch_assoc();

$interval_update = $settings['interval_update'] ?? 5; // Default 5 detik

$settings_query = $conn->prepare("SELECT
    interval_update,
    suhu_min, suhu_max,
    kelembapan_min, kelembapan_max,
    wind_speed_min, wind_speed_max,
    air_quality_max,
    cooldown_time,
    delta_threshold,
    sensitivity_level
    FROM settings WHERE user_id = ?");
$settings_query->bind_param("i", $user_id);
$settings_query->execute();
$settings_result = $settings_query->get_result();
$settings = $settings_result->fetch_assoc();
if (!$settings) {
    $settings = []; // Atur ke array kosong jika tidak ada hasil
}

// Set default values
$defaults = [
    'interval_update' => 5,
    'suhu_min' => 25,
    'suhu_max' => 30,
    'kelembapan_min' => 50,
    'kelembapan_max' => 90,
    'wind_speed_min' => 1,
    'wind_speed_max' => 5,
    'air_quality_max' => 450,
    'cooldown_time' => 600, // 10 menit default
    'delta_threshold' => 10, // 10% perubahan
    'sensitivity_level' => 'normal'
];

foreach ($defaults as $key => $value) {
    $$key = $settings[$key] ?? $value;
}

// Profile picture handling
$profile_pic = 'default-profile.png';
if (!empty($user['profile_pic'])) {
    $profile_pic = 'data:image/jpeg;base64,' . base64_encode($user['profile_pic']);
}

class ConditionChecker {
    private $user, $settings, $conn, $activeAlerts = [];

    public function __construct($user, $settings, $conn) {
        $this->user = $user;
        $this->settings = $settings;
        $this->conn = $conn;
    }

    public function checkConditions($data) {
        $params = [
            'temperature'   => ['min' => $this->settings['suhu_min'], 'max' => $this->settings['suhu_max']],
            'humidity'      => ['min' => $this->settings['kelembapan_min'], 'max' => $this->settings['kelembapan_max']],
            'wind_speed'    => ['min' => $this->settings['wind_speed_min'], 'max' => $this->settings['wind_speed_max']],
            'air_quality'   => ['max' => $this->settings['air_quality_max']]
        ];

        $sensorLabels = [
            'temperature' => 'Suhu',
            'humidity' => 'Kelembapan',
            'wind_speed' => 'Angin',
            'air_quality' => 'Kualitas Udara'
        ];

        $messages = [];

        foreach ($params as $sensor => $limits) {
            $value = $data[$sensor];
            $status = $this->getStatus($value, $limits);
            $label = $sensorLabels[$sensor];

            $shouldNotify = $this->shouldNotify($sensor, $value, $status);

            if ($shouldNotify) {
                $msg = $this->generateMessage($sensor, $label, $value, $limits, $status);
                $messages[] = $msg;
                $this->activeAlerts[$sensor] = $msg;
                $this->updateLastAlert($sensor, $value, $status);
            }
        }

        if (!empty($messages)) {
            $this->handleNotifications($messages, $data);
        }
    }

    private function getStatus($value, $limits) {
        if (isset($limits['max']) && $value > $limits['max']) return 'abnormal';
        if (isset($limits['min']) && $value < $limits['min']) return 'abnormal';
        return 'normal';
    }

    private function shouldNotify($sensor, $value, $currentStatus) {
        $userId = $this->user['id'];
        $stmt = $this->conn->prepare("SELECT * FROM last_alerts WHERE user_id = ? AND sensor_type = ?");
        $stmt->execute([$userId, $sensor]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);

        $now = time();
        $cooldown = (int)$this->settings['cooldown_time'];
$delta = (float)$this->settings['delta_threshold'];  // Delta dalam persen, misalnya 2

if (!$last) return true;

$prevStatus = $last['last_status'];
$lastValue = (float)$last['last_value'];
$lastNotified = strtotime($last['last_notified_at']);

if ($currentStatus !== $prevStatus) return true;

if ($currentStatus === 'abnormal') {
    // Cek perubahan makin jauh dari ambang batas
    if ($value > $lastValue) {
        // Kenaikan nilai
        $percentageChange = (($value - $lastValue) / $lastValue) * 100;
    } else {
        // Penurunan nilai
        $percentageChange = (($lastValue - $value) / $lastValue) * 100;
    }

    if ($percentageChange >= $delta) {
        return true;
    }

    // Cek cooldown
    if ($now - $lastNotified > $cooldown) return true;
}


        return false;
    }

    private function updateLastAlert($sensor, $value, $status) {
        $stmt = $this->conn->prepare("
            INSERT INTO last_alerts (user_id, sensor_type, last_value, last_status, last_notified_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE last_value = ?, last_status = ?, last_notified_at = NOW()
        ");
        $stmt->execute([$this->user['id'], $sensor, $value, $status, $value, $status]);
    }

    private function generateMessage($sensor, $label, $value, $limits, $status) {
        if ($status === 'normal') return null;
        switch ($sensor) {
            case 'temperature':
                return $value > $limits['max']
                    ? "⚠️ $label Tinggi: $value°C (Maks: {$limits['max']}°C). Turunkan suhu."
                    : "⚠️ $label Rendah: $value°C (Min: {$limits['min']}°C). Naikkansuhu.";
            case 'humidity':
                return $value > $limits['max']
                    ? "⚠️ $label Tinggi: $value% (Maks: {$limits['max']}%). Turunkan kelembapan."
                    : "⚠️ $label Rendah: $value% (Min: {$limits['min']}%). Naikkan Kelembapan.";
            case 'wind_speed':
                return $value > $limits['max']
                    ? "⚠️ Angin Kencang: $value m/s (Maks: {$limits['max']})"
                    : "⚠️ Angin Lemah: $value m/s (Min: {$limits['min']})";
            case 'air_quality':
                return "⚠️ Kualitas Udara Buruk: $value ppm (Maks: {$limits['max']} ppm). Buka jendela untuk sirkulasi udara.";
        }
        return null;
    }

    private function handleNotifications($messages, $data) {
        if (!empty($messages)) {
            $emailBody = implode("\n\n", $messages);
            $whatsappBody = "[Sistem Monitoring]\n" . implode("\n", $messages);

            // Kirim email
            NotificationSystem::sendEmailNotification($this->user['email'], $emailBody);

            // Kirim WhatsApp jika nomor tersedia
            if (!empty($this->user['phone'])) {
                NotificationSystem::sendWhatsAppNotification(
                    $this->user['phone'],
                    "[Alert] " . implode("\n", $messages)
                );
            }

            $this->tryLogAlertsToDatabase($messages, $data['id']); // 'id' adalah sensor_id dari sensor_readings
        }
    }

    private function tryLogAlertsToDatabase($messages, $sensorId) {
        try {
            // Check if table exists first
            $tableCheck = $this->conn->query("SELECT 1 FROM alert_logs LIMIT 1");
            if ($tableCheck !== false) {
                $stmt = $this->conn->prepare("INSERT INTO alert_logs (user_id, sensor_id, message, created_at) VALUES (?, ?, ?, NOW())");
                foreach ($messages as $message) {
                    $stmt->bind_param("iis", $this->user['id'], $sensorId, $message);
                    $stmt->execute();
                }
            }
        } catch (Exception $e) {
            error_log("Failed to log alerts to database (table might not exist): " . $e->getMessage());
        }
    }

    public function getActiveAlerts() {
        return $this->activeAlerts;
    }
}

// Ambil data real-time dari database
$query = "SELECT * FROM sensor_readings ORDER BY reading_time DESC LIMIT 1";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    $sensor_data = $result->fetch_assoc(); // Perbarui $sensor_data dengan data dari database
    $sensor_data['id'] = $sensor_data['id']; // Tambahkan sensor_id
    error_log("Data sensor yang diperbarui: " . json_encode($sensor_data)); // Log data yang diperbarui
} else {
    error_log("Tidak ada data sensor yang ditemukan di database.");
}

// Initialize condition checker with database connection
$conditionChecker = new ConditionChecker($user, $settings, $conn);

// Check conditions if we have sensor data
if (isset($sensor_data)) {
    error_log("Data yang diteruskan ke checkConditions: " . json_encode($sensor_data));
    $conditionChecker->checkConditions($sensor_data);
    $activeAlerts = $conditionChecker->getActiveAlerts();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring Kenyamanan Termal</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background-color: #0d1117; color: white; }
        .chart-container { position: relative; width: 100%; max-width: 100%; height: auto; aspect-ratio: 16 / 9; min-height: 300px; }
        .timeframe-card {
            padding: 12px 20px; border-radius: 10px; background: #21262d;
            color: white; cursor: pointer; transition: 0.3s; text-align: center;
            font-weight: bold; box-shadow: 0px 3px 10px rgba(0, 0, 0, 0.2);
        }
        .timeframe-card:hover, .timeframe-card.active { background: #30363d; transform: scale(1.05); }
        .alert { background: red; color: white; padding: 10px; margin-top: 10px; border-radius: 5px; display: none; }
        .dark-mode { background-color: cbd5e1; color: #333; }
        .dark-mode .bg-gray-900 { background: #ffffff; color: black; }
        .dark-mode .bg-gray-800 { background: #e2e8f0; color: black; }
        .dark-mode .timeframe-card { background: #e2e8f0; color: black; }
        .dark-mode .timeframe-card:hover, .dark-mode .timeframe-card.active { background: #cbd5e1; }
        .navbar.scrolled { background: rgba(13, 17, 23, 0.48); }
        /* Sidebar Styles */
        #sidebar {
            position: fixed;
            top: 0;
            left: -250px;
            width: 250px;
            height: 100%;
            background: rgba(33, 38, 45, 0.9);
            transition: left 0.3s;
            z-index: 1000;
        }
        #sidebar.active {
            left: 0;
        }
        #sidebar .nav-links {
            padding: 20px;
        }
        #sidebar .nav-links a {
            display: block;
            padding: 10px 0;
            text-decoration: none;
            transition: color 0.3s, text-shadow 0.3s;
        }
        #sidebar .nav-links a:hover {
            color: #4a90e2;
        }
        /* Logout Style */
        #sidebar .nav-links a.logout {
            color: #e74c3c; /* Merah */
        }
        #sidebar .nav-links a.logout:hover {
            color: #ff6b6b; /* Lebih terang */
            text-shadow: 0 0 8px rgba(255, 107, 107, 0.8); /* Shadow merah menyala */
        }
        #overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            z-index: 999;
        }
        #overlay.active {
            display: block;
        }
        /* Pop-up Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.3s ease-in-out;
        }
        .modal-content {
            background: rgba(255, 0, 0, 0.85); /* Red with transparency */
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            width: 350px;
            font-size: 18px;
            font-weight: bold;
            box-shadow: 0px 4px 15px rgba(255, 0, 0, 0.7);
            animation: pulse 1.5s infinite alternate;
            backdrop-filter: blur(5px);
        }
        .modal-content button {
            margin-top: 10px;
            padding: 8px 15px;
            border: none;
            background: white;
            color: red;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            border-radius: 5px;
            transition: 0.3s;
        }
        .modal-content button:hover {
            background: black;
            color: white;
        }
        .sticky-alert {
            position: fixed;
            top: 60px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(255, 0, 0, 0.85); /* Same red with transparency */
            color: white;
            padding: 8px 20px;
            font-weight: bold;
            font-size: 16px;
            width: auto;
            max-width: 90%;
            text-align: center;
            border-radius: 5px;
            box-shadow: 0px 4px 10px rgba(255, 0, 0, 0.5);
            z-index: 800;
            display: none;
            backdrop-filter: blur(3px);
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.8); }
            to { opacity: 1; transform: scale(1); }
        }
        @keyframes pulse {
            from { transform: scale(1); }
            to { transform: scale(1.1); }
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideUp {
            from { opacity: 1; transform: translateY(0); }
            to { opacity: 0; transform: translateY(-20px); }
        }
        .dropdown-content {
            opacity: 0;
            transform: scale(0.95);
            transform-origin: top right;
            transition: opacity 0.3s ease-out, transform 0.3s ease-out;
        }
        .dropdown-content.active {
            opacity: 1;
            transform: scale(1);
        }
        #profileDropdown a {
            opacity: 0.5;
            pointer-events: none;
        }
        #profileDropdown.active a {
            opacity: 1;
            pointer-events: auto;
        }
        .alert-danger {
            background: rgba(255, 0, 0, 0.9);
            box-shadow: 0px 4px 10px rgba(255, 0, 0, 0.5);
        }
        .alert-warning {
            background: rgba(255, 165, 0, 0.9);
            box-shadow: 0px 4px 10px rgba(255, 165, 0, 0.5);
        }
        .alert-info {
            background: rgba(0, 0, 255, 0.9);
            box-shadow: 0px 4px 10px rgba(0, 0, 255, 0.5);
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .modal.show {
            display: flex;
            opacity: 1;
        }
    </style>
</head>
<body class="flex flex-col items-center justify-center min-h-screen">
    <!-- Navbar -->
    <nav id="navbar" class="navbar w-full bg-gray-900 text-white p-4 flex justify-between items-center shadow-lg fixed top-0 left-0 z-50">
        <h1 class="text-2xl font-bold">📡 Monitoring Kenyamanan Termal</h1>
        <button id="menuToggle" class="md:hidden text-2xl">☰</button>
        <div id="navLinks" class="hidden md:flex space-x-6 items-center">
            <a href="index.php" class="hover:text-blue-400">🏠 Beranda</a>
            <a href="history.php" class="hover:text-blue-400">📜 Data Historis</a>
            <a href="settings.php" class="hover:text-blue-400">⚙️ Pengaturan</a>
            <a href="about.php" class="hover:text-blue-400">ℹ️ Bantuan</a>

<!-- Profile Dropdown -->
<div class="relative">
    <button id="profileBtn" class="flex items-center space-x-2 px-3 py-2 rounded-md transition-all duration-300 transform hover:scale-105">
        <?php if (!empty($user['profile_pic'])): ?>
    <img src="<?= htmlspecialchars($user['profile_pic']) ?>" 
     class="w-12 h-12 rounded-full object-cover" 
     alt="Profile">
<?php else: ?>
    <div class="w-12 h-12 rounded-full bg-gray-600 flex items-center justify-center">
        <i class="fas fa-user text-xl"></i>
    </div>
<?php endif; ?>
        <span class="font-medium <?php echo $bodyClass; ?>"><?php echo $user['username']; ?></span>
        <svg class="w-4 h-4 text-gray-400 transform transition-transform duration-300" fill="currentColor" viewBox="0 0 20 20" id="dropdownArrow">
                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a 1 1 0 01-1.414 0l-4-4a 1 1 0 010-1.414z" clip-rule="evenodd"></path>
                    </svg>
    </button>
<!-- Di dalam bagian dropdown profil -->
<div id="profileDropdown" class="dropdown-content absolute right-0 mt-2 w-56 bg-gray-800 rounded-lg shadow-2xl overflow-hidden transform opacity-0 scale-95 origin-top-right transition-all duration-300 ease-out pointer-events-none">
    <!-- Profile Section -->
    <div class="p-4 text-center border-b border-gray-700">  <!-- Added border bottom -->
    <?php if (!empty($user['profile_pic'])): ?>
        <img src="<?= htmlspecialchars($user['profile_pic']) ?>" 
     class="w-12 h-12 rounded-full object-cover" 
     alt="Profile">
    <?php else: ?>
        <div class="w-16 h-16 rounded-full bg-gray-600 flex items-center justify-center mx-auto border-2 border-blue-400 hover:border-blue-500 transition-all duration-300">
            <i class="fas fa-user text-white text-2xl"></i> <!-- Ikon default -->
        </div>
    <?php endif; ?>
    <p class="font-bold mt-2"><?php echo $user['username']; ?></p>
    <p class="text-gray-400 text-sm"><?php echo $user['email']; ?></p>
    <p class="text-gray-400 text-sm"><?php echo $user['phone']; ?></p>
</div>

        <!-- Logout Section -->
    <div class="p-2 bg-gray-750">  <!-- Slightly darker background for contrast -->
        <a href="logout.php" class="flex items-center justify-center px-4 py-2 text-red-400 hover:bg-gray-700 hover:text-red-500 transition-all duration-300">
    <span>Logout</span>
    <svg class="w-5 h-5 ml-2 text-red-400 inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1m0-13V4m0 8H3m14 0H7" />
    </svg>
</a>

    </div>
</div>
</div>
<button id="toggleMode" class="px-3 py-1 rounded-md transition-all duration-300 transform hover:scale-105">🌙</button>
        </div>
    </nav>


    <div id="sidebar">
    <div class="nav-links">
        <a href="index.php" class="hover:text-blue-400">🏠 Beranda</a>
        <a href="history.php" class="hover:text-blue-400">📜 Data Historis</a>
        <a href="settings.php" class="hover:text-blue-400">⚙️ Pengaturan</a>
        <a href="about.php" class="hover:text-blue-400">ℹ️ Bantuan</a>
        <a href="logout.php" class="logout">
    <svg class="w-4 h-4 mr-1 inline text-red-400 transition-all duration-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7" />
    </svg>
    Logout
</a>


        <button id="toggleModeSidebar" class="px-3 py-1 rounded-md mt-4">🌙</button>
    </div>
</div>

    <!-- Overlay -->
    <div id="overlay"></div>
    <div class="w-full max-w-6xl bg-gray-900 p-6 rounded-lg shadow-xl mt-20">
        <h2 class="text-4xl font-bold text-center mb-6">📡 Sensor Monitoring Dashboard</h2>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-6 text-center">
            <div class="p-6 bg-gray-800 rounded-lg shadow-md">
                <p class="text-lg">🌡️ Temperature</p>
                <h3 class="text-3xl font-bold" id="temperature">- °C</h3>
            </div>
            <div class="p-6 bg-gray-800 rounded-lg shadow-md">
                <p class="text-lg">💧 Humidity</p>
                <h3 class="text-3xl font-bold" id="humidity">- %</h3>
            </div>
            <div class="p-6 bg-gray-800 rounded-lg shadow-md">
                <p class="text-lg">🌫️ Air Quality</p>
                <h3 class="text-3xl font-bold" id="CO2">- CO2</h3>
            </div>
            <div class="p-6 bg-gray-800 rounded-lg shadow-md">
                <p class="text-lg">🌬️ Wind Speed</p>
                <h3 class="text-3xl font-bold" id="wind_speed">- m/s</h3>
            </div>
        </div>

        <div class="chart-container mt-6">
            <canvas id="sensorChart"></canvas>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mt-6">
            <div class="timeframe-card active" onclick="setTimeframe(this, 'latest')">Last 1 Hours</div>
            <div class="timeframe-card" onclick="setTimeframe(this, 'daily')">Last 24 Hours</div>
            <div class="timeframe-card" onclick="setTimeframe(this, 'weekly')">Last Week</div>
            <div class="timeframe-card" onclick="setTimeframe(this, 'monthly')">Last Month</div>
            <div class="timeframe-card" onclick="setTimeframe(this, 'yearly')">Last Year</div>
        </div>

        <p id="loading" class="text-center text-gray-400 mt-4">🔄 Memuat data...</p>
    </div>
<div id="alertModal" class="modal">
    <div class="modal-content">
        <h2>⚠️ Peringatan!</h2>
        <p id="alertMessage"></p>
        <button onclick="moveAlertToBottom()">OK</button>
    </div>
</div>

<!-- Sticky Alert -->
<div id="stickyAlert" class="sticky-alert">
    <p id="stickyMessage"></p>
</div>

    <script>
        const toggleModeBtn = document.getElementById("toggleMode");
        const toggleModeSidebar = document.getElementById("toggleModeSidebar");
        const body = document.body;
        const navbar = document.getElementById("navbar");
        document.getElementById("menuToggle").addEventListener("click", function() {
            document.getElementById("sidebar").classList.toggle("active");
            document.getElementById("overlay").classList.toggle("active");
        });
        const sidebar = document.getElementById("sidebar");
        const overlay = document.getElementById("overlay");

        toggleModeBtn.addEventListener("click", () => {
            body.classList.toggle("dark-mode");
            toggleModeBtn.textContent = body.classList.contains("dark-mode") ? "☀️" : "🌙";
        });

        toggleModeSidebar.addEventListener("click", () => {
            body.classList.toggle("dark-mode");
            toggleModeSidebar.textContent = body.classList.contains("dark-mode") ? "☀️" : "🌙";
        });

        menuToggle.addEventListener("click", () => {
            sidebar.classList.toggle("active");
            overlay.classList.toggle("active");
        });

        overlay.addEventListener("click", () => {
            sidebar.classList.remove("active");
            overlay.classList.remove("active");
        });

        window.addEventListener("scroll", () => {
            navbar.classList.toggle("scrolled", window.scrollY > 50);
        });

        let sensorChart;
        let currentTimeframe = "latest";
        let sensorData = [];

        // Fungsi untuk mengambil data real-time (untuk card overlay)
        async function fetchRealtimeData() {
            try {
                let response = await fetch(`get_data.php?timeframe=realtime`);
                let result = await response.json();
                console.log("Data real-time dari API:", result.data); // Tambahkan log ini
                if (result.data.length > 0) {
                    let latestData = result.data[0]; // Ambil data terbaru
                    console.log("Data yang diteruskan ke checkConditions:", latestData); // Tambahkan log ini
                    document.getElementById("temperature").innerText = latestData.temperature + " °C";
                    document.getElementById("humidity").innerText = latestData.humidity + " %";
                    document.getElementById("CO2").innerText = latestData.air_quality + " CO2";
                    document.getElementById("wind_speed").innerText = latestData.wind_speed + " m/s";

                    // Kirim data terbaru ke fungsi checkConditions
                    checkConditions(latestData); updateStickyAlert();
                } else {
                    console.warn("No real-time data available.");
                }
            } catch (error) {
                console.error("Error fetching real-time data:", error);
            }
        }

        // Fungsi untuk mengambil data dari 1 jam terakhir (untuk grafik)
        async function fetchSensorData() {
            try {
                let response = await fetch(`get_data.php?timeframe=${currentTimeframe}`);
                let result = await response.json();
                sensorData = result.data;

                if (sensorData.length > 0) {
                    updateChart(sensorData);
                } else {
                    console.warn("No data found for the selected timeframe.");
                }
            } catch (error) {
                console.error("Error fetching sensor data:", error);
            }
        }

        // Fungsi untuk memperbarui grafik
        function updateChart(data) {
            data.reverse();
        
            let labels = data.map(entry => {
                const date = new Date(entry.reading_time);
        
                if (currentTimeframe === "latest" || currentTimeframe === "daily") {
                    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); // HH:MM
                } else if (currentTimeframe === "weekly" || currentTimeframe === "monthly") {
                    return date.toLocaleDateString([], { day: '2-digit', month: 'short' }); // 14 Jun
                } else if (currentTimeframe === "yearly") {
                    return date.toLocaleDateString([], { month: 'short', year: 'numeric' }); // Jun 2024
                } else {
                    return date.toLocaleString(); // fallback
                }
            });
        
            sensorChart.data.labels = labels;
            sensorChart.data.datasets[0].data = data.map(entry => entry.temperature);
            sensorChart.data.datasets[1].data = data.map(entry => entry.humidity);
            sensorChart.data.datasets[2].data = data.map(entry => entry.air_quality);
            sensorChart.data.datasets[3].data = data.map(entry => entry.wind_speed);
            sensorChart.update();
        }

        // Inisialisasi grafik
        function createChart() {
            let ctx = document.getElementById("sensorChart").getContext("2d");
            sensorChart = new Chart(ctx, {
                type: "line",
                data: {
                    labels: [],
                    datasets: [
                        { label: "Temp (°C)", data: [], borderColor: "red", borderWidth: 1, pointRadius: 0, fill: true, backgroundColor: "rgba(255, 99, 132, 0.1)", tension: 0.2 },
                        { label: "Humidity (%)", data: [], borderColor: "blue", borderWidth: 1, pointRadius: 0, fill: true, backgroundColor: "rgba(54, 162, 235, 0.1)", tension: 0.2 },
                        { label: "Air Quality (CO2)", data: [], borderColor: "green", borderWidth: 1, pointRadius: 0, fill: true, backgroundColor: "rgba(75, 192, 192, 0.1)", tension: 0.2 },
                        { label: "Wind Speed (m/s)", data: [], borderColor: "orange", borderWidth: 1, pointRadius: 0, fill: true, backgroundColor: "rgba(255, 159, 64, 0.1)", tension: 0.2 }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });
        }
        let intervalUpdate = <?= $interval_update * 1000 ?>; // Konversi detik ke milidetik
        // Jalankan fungsi secara berkala
        createChart();
        fetchRealtimeData(); // Ambil data real-time untuk card overlay
        fetchSensorData(); // Ambil data untuk grafik
        setInterval(fetchRealtimeData, intervalUpdate);
setInterval(fetchSensorData, intervalUpdate);


        function showModal(message) {
            document.getElementById("alertMessage").innerText = message;
            document.getElementById("alertModal").style.display = "flex";
        }

        let COOLDOWN_DURATION = <?= $cooldown_time * 1000 ?>; // Konversi detik ke milidetik
        let DELTA_THRESHOLD = <?= $delta_threshold ?>; // Ambil dari database
        let SENSITIVITY_LEVEL = '<?= $sensitivity_level ?>'; // Ambil dari database
        
        // Variabel untuk tracking status
        let lastSensorValues = {
            temperature: null,
            humidity: null,
            air_quality: null,
            wind_speed: null
        };
        
        let alertCooldown = false;
        let activeAlerts = {};

        function checkConditions(data) {
            console.log("Data sensor terbaru:", data);
            
            // Skip jika ini adalah pembacaan pertama
            if (lastSensorValues.temperature === null) {
                lastSensorValues = {...data};
                return;
            }
        
            const newAlerts = {};
            const settings = {
                suhu_max: <?= $suhu_max ?>,
                suhu_min: <?= $suhu_min ?>,
                kelembapan_max: <?= $kelembapan_max ?>,
                kelembapan_min: <?= $kelembapan_min ?>,
                wind_speed_max: <?= $wind_speed_max ?>,
                wind_speed_min: <?= $wind_speed_min ?>,
                air_quality_max: <?= $air_quality_max ?>,
                delta_threshold: DELTA_THRESHOLD,
                sensitivity: SENSITIVITY_LEVEL
            };
        
            // Fungsi helper untuk menghitung perubahan persentase
            function calculateChange(oldVal, newVal) {
                if (oldVal === 0) return Infinity; // Hindari pembagian dengan nol
                return Math.abs((newVal - oldVal) / oldVal) * 100;
            }
        
            // Pengecekan untuk setiap sensor
            // Temperature
            if (data.temperature > settings.suhu_max) {
                const change = calculateChange(lastSensorValues.temperature, data.temperature);
                if (settings.sensitivity === 'sensitif' || change >= settings.delta_threshold) {
                    newAlerts.tempHigh = `⚠️ Suhu tinggi. Melebihi batas maksimal (${settings.suhu_max}°C). Turunkan suhu.`;
                }
            } else if (data.temperature < settings.suhu_min) {
                const change = calculateChange(lastSensorValues.temperature, data.temperature);
                if (settings.sensitivity === 'sensitif' || change >= settings.delta_threshold) {
                    newAlerts.tempLow = `⚠️ Suhu rendah. Di bawah batas minimal (${settings.suhu_min}°C). Naikkan suhu.`;
                }
            }
        
            // Humidity
            if (data.humidity > settings.kelembapan_max) {
                const change = calculateChange(lastSensorValues.humidity, data.humidity);
                if (settings.sensitivity === 'sensitif' || change >= settings.delta_threshold) {
                    newAlerts.humidHigh = `⚠️ Kelembapan tinggi. Melebihi batas maksimal (${settings.kelembapan_max}%). Turunkan kelembapan.`;
                }
            } else if (data.humidity < settings.kelembapan_min) {
                const change = calculateChange(lastSensorValues.humidity, data.humidity);
                if (settings.sensitivity === 'sensitif' || change >= settings.delta_threshold) {
                    newAlerts.humidLow = `⚠️ Kelembapan rendah. Di bawah batas minimal (${settings.kelembapan_min}%). Naikkan kelembapan.`;
                }
            }
        
            // Wind Speed
            if (data.wind_speed > settings.wind_speed_max) {
                const change = calculateChange(lastSensorValues.wind_speed, data.wind_speed);
                if (settings.sensitivity === 'sensitif' || change >= settings.delta_threshold) {
                    newAlerts.windHigh = `⚠️ Angin kencang. Melebihi batas maksimal (${settings.wind_speed_max}m/s).`;
                }
            } else if (data.wind_speed < settings.wind_speed_min) {
                const change = calculateChange(lastSensorValues.wind_speed, data.wind_speed);
                if (settings.sensitivity === 'sensitif' || change >= settings.delta_threshold) {
                    newAlerts.windLow = `⚠️ Angin lemah. Di bawah batas minimal (${settings.wind_speed_min}m/s).`;
                }
            }
        
            // Air Quality
            if (data.air_quality > settings.air_quality_max) {
                const change = calculateChange(lastSensorValues.air_quality, data.air_quality);
                if (settings.sensitivity === 'sensitif' || change >= settings.delta_threshold) {
                    newAlerts.airPoor = `⚠️ Kualitas udara buruk. Melebihi batas maksimal (${settings.air_quality_max}ppm). Buka jendela untuk sikulasi udara.`;
                }
            }
                // Hapus alert yang sudah tidak relevan
                if (data.temperature <= settings.suhu_max) delete activeAlerts.tempHigh;
                if (data.temperature >= settings.suhu_min) delete activeAlerts.tempLow;
                
                if (data.humidity <= settings.kelembapan_max) delete activeAlerts.humidHigh;
                if (data.humidity >= settings.kelembapan_min) delete activeAlerts.humidLow;
                
                if (data.wind_speed <= settings.wind_speed_max) delete activeAlerts.windHigh;
                if (data.wind_speed >= settings.wind_speed_min) delete activeAlerts.windLow;
                
                if (data.air_quality <= settings.air_quality_max) delete activeAlerts.airPoor;
                
            // Update nilai terakhir
            lastSensorValues = {...data};
        
            // Skip jika dalam cooldown
            if (alertCooldown) {
                console.log("Alert dalam masa cooldown");
                return;
            }
        
            // Tampilkan alert jika ada
            if (Object.keys(newAlerts).length > 0) {
                updateAlerts(newAlerts);
                
                // Set cooldown
                alertCooldown = true;
                setTimeout(() => {
                    alertCooldown = false;
                    console.log("Cooldown selesai");
                }, COOLDOWN_DURATION);
            }
        }

        // Fungsi untuk menangani alert
        function updateAlerts(newAlerts) {
            // Gabungkan alert baru dengan yang sudah ada
            const allAlerts = {...activeAlerts, ...newAlerts};
            
            // Tampilkan semua alert
            if (Object.keys(allAlerts).length > 0) {
                const alertMessages = Object.values(allAlerts).join('<br>');
                document.getElementById("alertMessage").innerHTML = alertMessages;
                document.getElementById("stickyMessage").innerHTML = alertMessages;
                
                // Tampilkan modal dan sticky alert
                document.getElementById("alertModal").classList.add('show');
                document.getElementById("stickyAlert").style.display = "block";
            }
            
            // Simpan alert aktif
            activeAlerts = allAlerts;
        }

        function showAlert(type, message) {
            // Gabungkan semua pesan dari activeAlerts
            const alertMessages = Object.values(activeAlerts).join('<br>'); // Gabungkan pesan dengan <br> untuk format yang sama
            document.getElementById("alertMessage").innerHTML = alertMessages; // Tampilkan semua pesan di modal
            document.getElementById("alertModal").classList.add('show'); // Tampilkan modal
        }

        function moveAlertToBottom() {
            // Tutup modal
            document.getElementById("alertModal").classList.remove('show');

            // Tampilkan sticky alert setelah modal ditutup
            updateStickyAlert();
        }

        function updateStickyAlert() {
            const stickyAlert = document.getElementById("stickyAlert");
            const stickyMessage = document.getElementById("stickyMessage");

            if (Object.keys(activeAlerts).length > 0) {
                // Gabungkan semua pesan dari activeAlerts
                stickyMessage.innerHTML = Object.values(activeAlerts).join('<br>');
                stickyAlert.style.display = "block"; // Tampilkan sticky alert
                stickyAlert.style.animation = "slideDown 0.5s ease-in-out";
            } else {
                // Sembunyikan sticky alert jika tidak ada pesan
                stickyAlert.style.animation = "slideUp 0.5s ease-in-out";
                setTimeout(() => {
                    stickyAlert.style.display = "none";
                }, 500);
            }
        }

        function setTimeframe(element, timeframe) {
            currentTimeframe = timeframe;
        
            // Hapus kelas 'active' dari semua tombol
            document.querySelectorAll('.timeframe-card').forEach(card => {
                card.classList.remove('active');
            });
        
            // Tambahkan kelas 'active' ke tombol yang diklik
            element.classList.add('active');
        
            // Panggil fungsi untuk ambil data
            fetchSensorData();
        }

        document.getElementById("menuToggle").addEventListener("click", function() {
            document.getElementById("sidebar").classList.toggle("active");
            document.getElementById("overlay").classList.toggle("active");
        });

        document.getElementById("overlay").addEventListener("click", function() {
            document.getElementById("sidebar").classList.remove("active");
            document.getElementById("overlay").classList.remove("active");
        });

        // Single event listener for profile dropdown
        const profileBtn = document.getElementById("profileBtn");
        const profileDropdown = document.getElementById("profileDropdown");
        const dropdownArrow = document.getElementById("dropdownArrow");

        profileBtn.addEventListener("click", function(e) {
            e.stopPropagation();
            const isActive = profileDropdown.classList.toggle("active");
            dropdownArrow.classList.toggle("rotate-180");
        });

        // Close dropdown when clicking outside
        document.addEventListener("click", function() {
            profileDropdown.classList.remove("active");
            dropdownArrow.classList.remove("rotate-180");
        });

        // Prevent dropdown from closing when clicking inside it
        profileDropdown.addEventListener("click", function(e) {
            e.stopPropagation();
        });
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
            "Kalau ada bug, itu fitur tersembunyi.
            Kalau lancar, itu keajaiban mahasiswa tingkat akhir." 😄
        </p>
    </div>
</footer>

</body>
</html>
