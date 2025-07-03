<?php
session_start();
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
    error_log("User dengan ID '$userId' tidak ditemukan.");
    exit;
}
include 'db.php';
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